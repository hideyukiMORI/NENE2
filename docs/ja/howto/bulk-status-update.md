# ハウツー: バルクステータス更新 API

> **FT リファレンス**: FT85 (`NENE2-FT/bulkupdatelog`) — バルクステータス更新 API
> **VULN**: FT231 — セキュリティ / 脆弱性アセスメント（V-01 から V-10）

バルクステータス変更の 2 つのパターンを示します: アイテムごとの更新（各アイテムが独自のターゲットステータスを持つ）と同質バルク更新（すべてのアイテムが同じステータスになる）。両方とも部分成功をサポートします — レスポンスはどの ID が成功してどれが失敗したかを報告します。

---

## ルート

| メソッド | パス | 説明 |
|---------|------------------|-----------------------------------------------------|
| `POST` | `/tasks` | タスクを作成する |
| `GET` | `/tasks` | すべてのタスクを一覧表示する |
| `PATCH` | `/tasks/status` | アイテムごとのバルクステータス更新（異なるターゲットステータス） |
| `PATCH` | `/tasks/done` | ID のセットを完了にマークする（単一ターゲットステータス） |

---

## アイテムごとのバルク更新（`PATCH /tasks/status`）

各更新アイテムが独自のターゲットステータスを指定します:

```json
{
  "updates": [
    {"id": 1, "status": "done"},
    {"id": 2, "status": "cancelled"},
    {"id": 3, "status": "in_progress"}
  ]
}
```

リポジトリは各アイテムを個別に処理し、成功と失敗を蓄積します:

```php
public function bulkUpdateStatus(array $items, string $now): BulkUpdateResult
{
    $updatedIds = [];
    $failed     = [];

    foreach ($items as $item) {
        $itemArr = is_array($item) ? $item : [];
        $id      = isset($itemArr['id']) && is_int($itemArr['id']) ? $itemArr['id'] : null;
        $status  = isset($itemArr['status']) && is_string($itemArr['status'])
            ? TaskStatus::tryFrom($itemArr['status'])
            : null;

        if ($id === null) {
            $failed[] = ['id' => 0, 'error' => 'id must be an integer'];
            continue;
        }

        if ($status === null) {
            $failed[] = ['id' => $id, 'error' => 'invalid status value'];
            continue;
        }

        $affected = $this->executor->execute(
            'UPDATE tasks SET status = ?, updated_at = ? WHERE id = ?',
            [$status->value, $now, $id],
        );

        if ($affected === 0) {
            $failed[] = ['id' => $id, 'error' => 'task not found'];
        } else {
            $updatedIds[] = $id;
        }
    }

    return new BulkUpdateResult($updatedIds, $failed);
}
```

### レスポンス構造

```json
{
  "updated": [1, 3],
  "failed": [
    {"id": 2, "error": "task not found"}
  ]
}
```

HTTP ステータスは常に `200 OK` です — すべてのアイテムが失敗しても同様です。呼び出し元はアイテムごとのエラーを検出するために `failed` を調べる必要があります。

---

## 同質バルク更新（`PATCH /tasks/done`）

すべての ID が単一の `UPDATE ... WHERE id IN (?)` で同じターゲットステータスに移行します:

```php
// ボディ: {"ids": [1, 2, 3]}
$ids = isset($body['ids']) && is_array($body['ids'])
    ? array_values(array_filter($body['ids'], static fn (mixed $v): bool => is_int($v)))
    : [];

if ($ids === []) {
    return $this->json->create(['error' => 'ids array is required and must not be empty'], 422);
}
```

非整数値は `array_filter(..., is_int(...))` でサイレントにフィルタリングされます。フィルタリング後に結果が空の場合、422 が返されます。

```php
public function bulkSetStatus(array $ids, TaskStatus $status, string $now): array
{
    $placeholders = implode(',', array_fill(0, count($ids), '?'));

    $this->executor->execute(
        "UPDATE tasks SET status = ?, updated_at = ? WHERE id IN ({$placeholders})",
        [$status->value, $now, ...$ids],
    );

    // ターゲットステータスを持つ存在する ID を返す
    $rows = $this->executor->fetchAll(
        "SELECT id FROM tasks WHERE id IN ({$placeholders}) AND status = ?",
        [...$ids, $status->value],
    );

    return array_map(static fn (array $r): int => (int) $r['id'], $rows);
}
```

`implode(',', array_fill(0, count($ids), '?'))` は正しい数の `?` プレースホルダーを生成します — 安全でパラメータ化されています。

---

## ステータス許可リスト（backed enum）

`TaskStatus` は 4 つのケースを持つ backed 文字列 enum です:

```php
enum TaskStatus: string
{
    case Pending    = 'pending';
    case InProgress = 'in_progress';
    case Done       = 'done';
    case Cancelled  = 'cancelled';
}
```

`TaskStatus::tryFrom($string)` は未知のステータス値に `null` を返し、バルクハンドラーはこれをアイテムごとの失敗にマップします。スキーマは DB レベルのバックストップとして `CHECK(status IN (...))` を追加します。

---

## スキーマ

```sql
CREATE TABLE tasks (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    title      TEXT NOT NULL,
    status     TEXT NOT NULL DEFAULT 'pending'
                             CHECK(status IN ('pending', 'in_progress', 'done', 'cancelled')),
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
```

---

## VULN — セキュリティアセスメント（FT231）

### V-01 — すべてのエンドポイントに認証なし

**攻撃**: 資格情報なしですべてのタスクをバルクキャンセルする。

```json
{"updates": [{"id": 1, "status": "cancelled"}, {"id": 2, "status": "cancelled"}]}
```

**観測結果**: `200 OK` — トークン不要。

**判定**: **EXPOSED**（FT85 デモでは設計上）。本番では認証と認可を追加してください。バルク変更をタスクの所有者または管理者ロールに制限してください。

---

### V-02 — 大量更新 DoS（巨大な配列）

**攻撃**: CPU またはメモリを枯渇させるために何千ものアイテムを持つ `updates` 配列を送信する。

```python
{"updates": [{"id": i, "status": "done"} for i in range(100_000)]}
```

**観測結果**: ループで処理されます — 各アイテムが 1 つの `UPDATE` クエリを実行します。100,000 アイテムの場合、バッチサイズ制限なしにタイトなループで 100,000 個の個別 SQL ステートメントが実行されます。

**判定**: **EXPOSED** — 最大バッチサイズ制限を追加してください:
```php
$maxBatchSize = 500;
if (count($updates) > $maxBatchSize) {
    return $this->json->create(['error' => "Batch size must not exceed {$maxBatchSize} items."], 422);
}
```

---

### V-03 — `IN` 句経由の SQL インジェクション

**攻撃**: `IN (?)` で使用される `ids` 配列経由で SQL をインジェクトしようとする。

```json
{"ids": ["1; DROP TABLE tasks; --", 1, 2]}
```

**観測結果**: 文字列 `"1; DROP TABLE tasks; --"` は `array_filter()` の `is_int()` フィルターで拒否されます。整数のみが `IN` 句に到達します。`implode` + `array_fill` パターンは正しい数の `?` プレースホルダーを生成します — ユーザーデータの文字列連結はありません。

**判定**: **BLOCKED** — `is_int()` フィルター + パラメータ化 `IN` 句でインジェクションを防止します。

---

### V-04 — アイテムごとの更新で非整数 ID

**攻撃**: `updates` 配列に非整数の `id` 値を送信する。

```json
{"updates": [{"id": "1", "status": "done"}, {"id": null, "status": "done"}]}
```

**観測結果**: 両アイテムとも `'error' => 'id must be an integer'` で `$failed` に追加されます。`is_int()` は文字列と `null` を拒否します。

**判定**: **BLOCKED** — アイテムごとの厳密な `is_int()` 型チェック。

---

### V-05 — 無効なステータス値

**攻撃**: `updates` 配列に未知のステータス文字列を送信する。

```json
{"updates": [{"id": 1, "status": "hacked"}]}
```

**観測結果**: アイテムが `'error' => 'invalid status value'` で `$failed` に追加されます。`TaskStatus::tryFrom("hacked")` は `null` を返します。

**判定**: **BLOCKED** — backed enum の `tryFrom()` が未知の値を拒否します。

---

### V-06 — 空の配列

**攻撃**: 空の `updates` または `ids` 配列を送信する。

```json
{"updates": []}
{"ids": []}
```

**観測結果**: 両方とも `422 Unprocessable Entity` とエラーメッセージを返します。

**判定**: **BLOCKED** — 処理前の空配列チェック。

---

### V-07 — 同じバッチで重複 ID

**攻撃**: 1 つのリクエストに同じ `id` を複数回含める。

```json
{"updates": [{"id": 1, "status": "done"}, {"id": 1, "status": "cancelled"}]}
```

**観測結果**: 両方の更新が成功します。2 番目の UPDATE が最初のものを上書きします — タスクは `cancelled` で終わります。重複排除は行われません。

**判定**: **設計上 ACCEPTED** — 最後の書き込みが勝つセマンティクスはシンプルなタスク管理では一貫しています。競合を拒否すべき場合は、処理前に `ids` を重複排除し、重複時にエラーを返してください。

---

### V-08 — 負またはゼロの ID

**攻撃**: ID `0` または `-1` を送信する。

```json
{"ids": [0, -1]}
```

**観測結果**: `is_int(0)` = true、`is_int(-1)` = true — 両方ともフィルターを通過します。UPDATE は `WHERE id IN (0, -1)` で実行され、行に一致しません。レスポンス: `{"requested": 2, "updated": 0, "ids": []}`。

**判定**: **BLOCKED**（事実上）（影響を受けた行なし）。存在しない ID にはエラーは返されません — これは部分成功パターンと一貫しています。負の ID を 422 で拒否すべき場合は正の整数ガードを追加してください。

---

### V-09 — バルク更新が存在しないタスクをサイレントにスキップ

**攻撃**: データベースに存在しない ID を含める。

```json
{"ids": [99999, 100000]}
```

**観測結果**: `{"requested": 2, "updated": 0, "ids": []}` — エラーなし、タスクが存在しないという表示なし。

**判定**: **設計上 ACCEPTED** — 部分成功モデル。この動作を API 仕様に文書化してください。呼び出し元が「タスクがない」と「タスクがすでにターゲット状態にある」を区別する必要がある場合、レスポンスに `not_found` リストを含めてください。

---

### V-10 — 同じ ID に対する同時バルク更新

**攻撃**: 同じ ID セットに対して 2 つの同時 `PATCH /tasks/done` リクエストを送信する。

**観測結果**: 両方の UPDATE ステートメントが DB で実行されます。SQLite の行レベルロックにより、一方の UPDATE が最初に完了してから、2 番目の UPDATE がすでに `done` 状態の行で実行されます。両方のレスポンスが `updated` ID を返します（行はまだ `status = done` で存在するため）。

**判定**: **BLOCKED** — 冪等な書き込み。両方のリクエストが同じ結果を生成します（すべての ID が `done` に設定）。呼び出し元ごとにターゲットステータスが異なるステータス更新では、同時書き込みは最後の書き込みが勝つを使用します。

---

## VULN まとめ

| # | 攻撃ベクター | 判定 |
|---|---------------|---------|
| V-01 | 認証なし | EXPOSED（設計上） |
| V-02 | 大量更新 DoS（巨大な配列） | EXPOSED |
| V-03 | `IN` 句経由の SQL インジェクション | BLOCKED |
| V-04 | 非整数 ID | BLOCKED |
| V-05 | 無効なステータス値 | BLOCKED |
| V-06 | 空の配列 | BLOCKED |
| V-07 | バッチ内の重複 ID | 設計上 ACCEPTED |
| V-08 | 負/ゼロの ID | BLOCKED |
| V-09 | 存在しないタスクがサイレントにスキップされる | 設計上 ACCEPTED |
| V-10 | 同時バルク更新 | BLOCKED |

**本番前に修正すべき実際の脆弱性**:
1. **V-01** — 認証と認可を追加する
2. **V-02** — 最大バッチサイズ制限を追加する（例: 500 アイテム）

---

## 関連ハウツー

- [`implement-bulk-endpoint.md`](implement-bulk-endpoint.md) — アイテムごとのエラーを持つバルク作成
- [`batch-api-partial-success.md`](batch-api-partial-success.md) — 部分成功パターン
- [`approval-workflow.md`](approval-workflow.md) — enum ガードを持つステータス遷移
