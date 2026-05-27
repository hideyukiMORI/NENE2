# ハウツー: JSON マージパッチと ETag 競合検出

**FT178 — patchlog**

ETag による楽観的ロック、不変フィールド保護、V.php 統合を使って PATCH（RFC 7396 JSON マージパッチ）と PUT セマンティクスを実装します。

---

## PUT の問題点

`PUT` はリソース全体を置き換えます。クライアントは変更していないフィールドも含めてすべてのフィールドを送信する必要があります。これにより以下が発生します:

- **競合状態**: 並行リーダーが両方ともバージョン 1 を参照し、両方が PUT し、最後のものが勝ってもう一方の変更をサイレントに破棄する。
- **帯域幅の無駄**: 1 フィールドの変更のためにもフルペイロードが必要。
- **権限の混乱**: クライアントが所有しないフィールドを書き込む。

**JSON マージパッチ（RFC 7396）** を使った `PATCH` が最初の 2 つを解決し、`ETag` / `If-Match` が PATCH と PUT の両方の競合状態を解決します。

---

## JSON マージパッチのセマンティクス（RFC 7396）

パッチドキュメントはシンプルなルールで変更を記述します:

| パッチ値 | 意味 |
|---------|------|
| `"new value"` | フィールドをこの値に設定する |
| `null` | フィールドをリセットする（削除またはデフォルトに戻す） |
| *（キーなし）* | フィールドを変更しない |

```json
// PATCH 前のドキュメント:
{ "title": "Hello", "body": "World", "status": "draft" }

// PATCH ボディ:
{ "title": "Goodbye", "status": null }

// 結果:
{ "title": "Goodbye", "body": "World", "status": "draft" }
//                              ^^^^^     ^^^^^^^^^^^^^^
//                              変更なし    null → デフォルトにリセット
```

### 不変フィールド

一部のフィールドは PATCH または PUT で変更されてはなりません:

```php
private const array IMMUTABLE_FIELDS = ['id', 'owner_id', 'version', 'created_at', 'updated_at'];

$violations = array_intersect(array_keys($body), self::IMMUTABLE_FIELDS);

if ($violations !== []) {
    return $this->responseFactory->create(
        ['error' => 'Fields are immutable: ' . implode(', ', $violations)],
        422,
    );
}
```

### 空の PATCH は有効（ノーオペレーション）

RFC 7396 §3 は空のパッチ `{}` を明示的に許可しています:

```php
// $patch にキーなし → UPDATE をスキップ、現在のドキュメントをそのまま返す
if ($patch === []) {
    return $doc;  // ノーオペレーション; バージョンはインクリメントされない
}
```

---

## 楽観的ロックのための ETag と If-Match

### ETag フォーマット

```php
public function etag(): string
{
    return sprintf('"doc-%d-%d"', $this->id, $this->version);
    // 例: "doc-42-7"
}
```

すべての GET/PATCH/PUT レスポンスで `ETag` を返してください:

```php
return $this->responseFactory->create($doc->toArray())
    ->withHeader('ETag', $doc->etag());
```

### 競合検出

```php
$ifMatch = $request->getHeaderLine('If-Match');

if ($ifMatch !== '' && $ifMatch !== $doc->etag()) {
    return $this->responseFactory->create(
        ['error' => 'Version conflict. Fetch the document and retry.'],
        412,  // Precondition Failed
    );
}
```

**If-Match なし**: 競合チェックなしの楽観的更新（最後の書き込みが勝つ）。
**If-Match あり、一致**: 安全な並行更新。
**If-Match あり、古い**: 412 — クライアントは再フェッチしてリトライする必要があります。

### SQL でのバージョンインクリメント

データベースを使って原子的にバージョンをインクリメントしてください:

```sql
UPDATE documents
SET title = ?, version = version + 1, updated_at = ?
WHERE id = ? AND version = ?
```

`WHERE version = ?` 句は DB レベルで楽観的ロックをダブルチェックし、読み取りと書き込みの間に並行書き込みが入り込むことを防ぎます。

---

## V.php 統合

FT178 は `Nene2\Validation\V` を共有ユーティリティとして使用する最初の FT です:

```php
// クエリパラメーター
$page  = V::queryInt($params, 'page', 1, PHP_INT_MAX, 1);
$limit = V::queryInt($params, 'limit', 1, 50, 20);

// Auth ヘッダー
$ownerId = V::userId($request->getHeaderLine('X-User-Id'));

// 文字列フィールド（明示的な長さ制限付き）
$title = V::str($body['title'] ?? null, 200);

// Enum バリデーション
$status = V::enum($body['status'] ?? null, DocumentStatus::class);
```

### オプションボディフィールドの `?? ''` トラップ

```php
// ❌ 間違い — 長すぎる入力での V::str null 返却をバイパスする
$text = V::str($body['body'] ?? null, 10000) ?? '';

// ✅ 正しい — 存在する場合はバリデーション、不在の場合はデフォルト
$rawText = $body['body'] ?? null;
if ($rawText !== null) {
    $text = V::str($rawText, 10000);
    if ($text === null) {
        return $this->responseFactory->create(['error' => 'body too long'], 422);
    }
} else {
    $text = '';
}
```

`V::str(null, ...)` は `null` が文字列でないため `null` を返します。
`V::str(too_long_string, 10000)` も `null` を返します。
`?? ''` を使うと両方のケースが空文字列に折りたたまれ、長すぎる入力をサイレントに受け入れます。

---

## ルートパラメーター抽出

NENE2 Router はパスパラメーターを個別のリクエスト属性としてではなく、`nene2.route.parameters` 属性に保存します:

```php
// ❌ 間違い
$id = $request->getAttribute('id');  // パスパラメーターは常に null

// ✅ 正しい
$id = Router::param($request, 'id');  // nene2.route.parameters から読み取る
```

---

## アタックチェックリスト（ATK-01 〜 ATK-12）

| # | テスト | 期待値 |
|---|------|--------|
| ATK-01 | PATCH `{"id": 999}` | 422 — 不変フィールド |
| ATK-02 | PATCH `{"owner_id": 99}` | 422 — 不変フィールド |
| ATK-03 | PATCH `{"version": 999}` | 422 — 不変フィールド |
| ATK-04 | PATCH `{"title": 42}`（型混乱） | 422 — V::str が非文字列を拒否 |
| ATK-05 | 非オーナーによる PATCH | 404 — IDOR 保護 |
| ATK-06 | 古い ETag の If-Match | 412 — 楽観的ロック競合 |
| ATK-07 | 必須 title が欠けた PUT | 422 |
| ATK-08 | 空の PATCH `{}` | 200 — 有効なノーオペレーション（RFC 7396 §3） |
| ATK-09 | PATCH `{"status": null}` | 200 — デフォルト `draft` にリセット |
| ATK-10 | PATCH `{"status": 2}`（型混乱） | 422 — V::enum が非文字列を拒否 |
| ATK-11 | PATCH `{"__proto__": {...}}` | 200 — 未知のキーは無視、クラッシュなし |
| ATK-12 | `?limit=999999`、`?page=-1`、20 桁のオーバーフロー | 422 — V::queryInt ガード |
