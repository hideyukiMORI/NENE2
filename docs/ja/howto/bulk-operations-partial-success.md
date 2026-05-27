# ハウツー: 部分成功セマンティクスを持つバルク操作

> **FT リファレンス**: FT258 (`NENE2-FT/bulklog`) — HTTP 207 Multi-Status を使った部分成功セマンティクスを持つバルク作成 / バルク削除

一部のアイテムが成功し他が失敗する可能性があるバルク API 操作の処理方法を示します。各アイテムは独立して処理されます — アイテム N のバリデーション失敗はアイテム N+1 以降を中断しません。レスポンスには 2 つの配列が含まれます: `created`（成功）と `errors`（理由付きの失敗）。混合がある場合は HTTP 207 Multi-Status、すべて成功した場合は 201 Created が返されます。

---

## ルート

| メソッド | パス | 説明 |
|----------|---------------|-----------------------------------------------|
| `POST` | `/items` | 単一アイテムを作成する |
| `GET` | `/items/{id}` | 単一アイテムを取得する |
| `POST` | `/items/bulk` | アイテムをバルク作成する（部分成功） |
| `DELETE` | `/items/bulk` | ID でアイテムをバルク削除する（部分成功） |

> **ルート順序**: `/items/bulk` はリテラルセグメント `bulk` がパスパラメーターとしてキャプチャされないよう `/items/{id}` の**前に**登録する必要があります。

---

## スキーマ

```sql
CREATE TABLE IF NOT EXISTS items (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    sku        TEXT NOT NULL UNIQUE,
    name       TEXT NOT NULL,
    price      INTEGER NOT NULL,
    created_at TEXT NOT NULL
);
```

`sku TEXT NOT NULL UNIQUE` は DB レベルで重複 SKU を防止します。`price INTEGER` は浮動小数点の丸め誤差を避けるために価格を最小通貨単位（セント/円）で保存します。

---

## BulkResult DTO

```php
final readonly class BulkResult
{
    /**
     * @param list<array<string, mixed>> $created
     * @param list<array<string, mixed>> $errors
     */
    public function __construct(
        public array $created,
        public array $errors,
    ) {}

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }
}
```

`created` は正常に作成されたレコードを保持します。`errors` はアイテムごとのエラー記述子を保持します。`hasErrors()` はコントローラーが HTTP ステータスコードを選択するために使用するシンプルな述語です。

---

## バルク作成: アイテムごとのバリデーション

```php
public function bulkCreate(array $inputs, string $now): BulkResult
{
    $created = [];
    $errors  = [];

    foreach ($inputs as $index => $input) {
        $sku   = isset($input['sku'])   && is_string($input['sku'])   ? trim($input['sku'])   : '';
        $name  = isset($input['name'])  && is_string($input['name'])  ? trim($input['name'])  : '';
        $price = isset($input['price']) && is_int($input['price'])    ? $input['price']       : -1;

        $itemErrors = [];
        if ($sku === '') {
            $itemErrors[] = 'sku is required';
        } elseif ($this->skuExists($sku)) {
            $itemErrors[] = "sku \"{$sku}\" already exists";
        }
        if ($name === '') {
            $itemErrors[] = 'name is required';
        }
        if ($price < 0) {
            $itemErrors[] = 'price must be a non-negative integer';
        }

        if ($itemErrors !== []) {
            $errors[] = ['index' => $index, 'sku' => $sku, 'errors' => $itemErrors];
            continue;   // 挿入をスキップし、次のアイテムに続く
        }

        $item      = $this->create($sku, $name, $price, $now);
        $created[] = $item->toArray();
    }

    return new BulkResult($created, $errors);
}
```

**主要な決定**:
- バリデーション失敗時の `continue`: 失敗したアイテムはループを中断しません。
- エラーエントリに `$index` が含まれる: クライアントは入力配列のどの位置が失敗したかを知れます。
- SKU の一意性は DB 例外からキャッチするのではなく INSERT 前に PHP で確認（`skuExists()`）します。これにより生の制約違反よりもクリーンなアプリケーションレベルのエラーメッセージが得られます。
- 成功したすべての INSERT は同じ `$now` タイムスタンプを共有します: バッチは単一の時点として扱われます。

---

## バルク削除: 見つからない追跡

```php
public function bulkDelete(array $ids): array
{
    $deleted  = [];
    $notFound = [];

    foreach ($ids as $id) {
        $item = $this->findById($id);
        if ($item === null) {
            $notFound[] = $id;
            continue;
        }
        $this->executor->execute('DELETE FROM items WHERE id = ?', [$id]);
        $deleted[] = $id;
    }

    return ['deleted' => $deleted, 'not_found' => $notFound];
}
```

見つからない ID は追跡されますが、操作を中断しません。レスポンスにより呼び出し元は実際に削除された ID とすでになかった ID を監査できます。すべてのリクエストされた削除が成功したかすでになかったかのどちらかなので、ここで 200（207 ではない）を返すのは合理的です — 「エラー」状態がありません。

---

## コントローラー: HTTP 207 Multi-Status

```php
private function bulkCreate(ServerRequestInterface $request): ResponseInterface
{
    $body = JsonRequestBodyParser::parse($request);

    if (!isset($body['items']) || !is_array($body['items'])) {
        return $this->problems->create($request, 'validation-failed', 'Validation Failed', 422, null, [
            'errors' => [['field' => 'items', 'code' => 'required', 'message' => 'items array is required.']],
        ]);
    }

    $inputs = array_values($body['items']);
    $now    = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
    $result = $this->repo->bulkCreate($inputs, $now);

    $status = $result->hasErrors() ? 207 : 201;   // ← 成功+エラーの混合時は 207

    return $this->json->create($result->toArray(), $status);
}
```

**HTTP ステータスの選択**:

| 結果 | ステータス | 意味 |
|---|---|---|
| すべて作成済み | `201 Created` | 完全成功 |
| 一部作成済み、一部失敗 | `207 Multi-Status` | 部分成功 — クライアントはボディを調べる必要がある |
| すべて失敗 | `207 Multi-Status` | 完全失敗 — `created` 配列が空 |
| `items` 配列なし | `422 Unprocessable Entity` | 不正なリクエスト |

`207` はクライアントに伝えます: _成功を仮定しないでください — ボディを調べてください_。`201` を見たクライアントはすべてのアイテムが処理されたと仮定できます；`207` を見たクライアントは `errors` を確認する必要があります。

**なぜ部分的な失敗に 422 を使わないのか？** `422` はリクエスト全体が拒否されたことを意味します。部分成功バルクエンドポイントは一部の入力を正常に処理するため、`422` は誤解を招きます。

---

## バルク削除コントローラー

```php
private function bulkDelete(ServerRequestInterface $request): ResponseInterface
{
    $body = JsonRequestBodyParser::parse($request);

    if (!isset($body['ids']) || !is_array($body['ids'])) {
        return $this->problems->create($request, 'validation-failed', 'Validation Failed', 422, null, [
            'errors' => [['field' => 'ids', 'code' => 'required', 'message' => 'ids array is required.']],
        ]);
    }

    $ids    = array_values(array_filter($body['ids'], 'is_int'));
    $result = $this->repo->bulkDelete($ids);

    return $this->json->create($result);   // 常に 200
}
```

`array_filter($body['ids'], 'is_int')` は ID 配列から非整数値をサイレントに除外します。これは設計上の選択です: 不正な ID は 422 を引き起こすのではなく無視されます。別のアプローチとして、いずれかの ID が非整数の場合にリクエスト全体を拒否することもできます。

---

## リクエストとレスポンスの例

### バルク作成 — 部分成功

**リクエスト** `POST /items/bulk`:
```json
{
  "items": [
    {"sku": "A001", "name": "Widget A", "price": 1000},
    {"sku": "",     "name": "Bad Item",  "price": 500},
    {"sku": "A001", "name": "Duplicate", "price": 200}
  ]
}
```

**レスポンス** `207 Multi-Status`:
```json
{
  "created": [
    {"id": 1, "sku": "A001", "name": "Widget A", "price": 1000, "created_at": "2026-01-01 00:00:00"}
  ],
  "errors": [
    {"index": 1, "sku": "", "errors": ["sku is required"]},
    {"index": 2, "sku": "A001", "errors": ["sku \"A001\" already exists"]}
  ]
}
```

`index` は入力の `items` 配列の位置（0 始まり）を指します。クライアントはペイロードをスキャンせずに各エラーを元の入力と関連付けられます。

### バルク削除 — 部分成功

**リクエスト** `DELETE /items/bulk`:
```json
{"ids": [1, 999, 2]}
```

**レスポンス** `200 OK`:
```json
{
  "deleted": [1, 2],
  "not_found": [999]
}
```

---

## 設計上のトレードオフ

| アプローチ | 動作 | 使用タイミング |
|---|---|---|
| 全か無か | いずれかが失敗した場合はすべてをロールバック | 金融、在庫 — 一貫性が必要 |
| 部分成功（このパターン） | 各アイテムを独立して処理 | インポート/エクスポート、データ取り込み |
| ファイアアンドフォーゲットキュー | 非同期処理、遅延結果 | 大きなバッチ、バックグラウンドジョブ |

部分成功はアイテムが互いに独立している場合に適しています。アイテム A の成功がアイテム B の成功に依存している場合（例: アイテム間での在庫転送）、代わりに全か無かのトランザクションを使用してください。

---

## 関連ハウツー

- [`transaction-scope-pattern.md`](transaction-scope-pattern.md) — アトミックな全か無かの複数書き込み
- [`job-queue-with-retry.md`](job-queue-with-retry.md) — ジョブキュー経由の非同期バルク処理
- [`mass-assignment-defence.md`](mass-assignment-defence.md) — 各アイテムへの明示的な DTO ホワイトリスト
