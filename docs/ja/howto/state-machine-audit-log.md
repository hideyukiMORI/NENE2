# ハウツー: 監査ログ付きステートマシン

> **FT リファレンス**: FT237 (`NENE2-FT/statemachinelog`) — 監査ログ付きステートマシン
> **VULN**: FT237 — セキュリティ/脆弱性アセスメント（V-01〜V-10）

すべてのトランジションをイミュータブルな監査ログテーブルに記録するステートマシン API を実演します。現在のステータスはオーダーに保持し、完全な履歴は別の `order_transitions` テーブルに保持します。`InvalidTransitionException` は `from` と `to` のコンテキストを含む構造化された 409 レスポンスを提供します。

---

## ルート

| メソッド | パス | 説明 |
|--------|------|------|
| `POST` | `/orders`                  | オーダーを作成する（`draft` として開始） |
| `GET`  | `/orders/{id}`             | 現在のオーダー状態を取得する            |
| `POST` | `/orders/{id}/transitions` | ステートトランジションを適用する        |
| `GET`  | `/orders/{id}/transitions` | 完全なトランジション履歴を一覧表示する  |

---

## ステートマシン: 許可されたトランジション

```php
enum OrderStatus: string
{
    case Draft     = 'draft';
    case Submitted = 'submitted';
    case Approved  = 'approved';
    case Rejected  = 'rejected';
    case Cancelled = 'cancelled';

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft     => [self::Submitted, self::Cancelled],
            self::Submitted => [self::Approved, self::Rejected, self::Cancelled],
            self::Approved  => [],
            self::Rejected  => [],
            self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }
}
```

終端状態（`approved`、`rejected`、`cancelled`）は空のリストを返します — それ以上トランジションできません。

---

## InvalidTransitionException → コンテキスト付き 409

呼び出し元が不正なトランジションを要求した場合、例外は from と to の状態を構造化データとしてエラーレスポンスに含めます:

```php
final class InvalidTransitionException extends \RuntimeException
{
    public function __construct(OrderStatus $from, OrderStatus $to)
    {
        parent::__construct(
            sprintf('Transition from "%s" to "%s" is not allowed.', $from->value, $to->value)
        );
    }
}
```

コントローラーは Problem Details エクステンションに `from` と `to` を含めます:

```php
try {
    $updated = $this->repo->transition($id, $targetEnum, $now);
} catch (InvalidTransitionException $e) {
    return $this->problems->create(
        $request,
        'invalid-transition',
        'Invalid State Transition',
        409,
        $e->getMessage(),
        ['from' => $order->status->value, 'to' => $targetEnum->value],
    );
}
```

レスポンス:
```json
{
  "type": "https://nene2.dev/problems/invalid-transition",
  "title": "Invalid State Transition",
  "status": 409,
  "detail": "Transition from \"approved\" to \"submitted\" is not allowed.",
  "from": "approved",
  "to": "submitted"
}
```

`from` と `to` により、呼び出し元は `detail` 文字列を解析せずに、どのトランジションが拒否されたかを正確に理解できます。

---

## トランジション監査ログ: 2 回書き込みパターン

成功したトランジションはオーダーのステータスを更新し、ログレコードをアトミックに挿入します:

```php
public function transition(int $orderId, OrderStatus $targetStatus, string $now): Order
{
    $order = $this->findById($orderId);

    if (!$order->status->canTransitionTo($targetStatus)) {
        throw new InvalidTransitionException($order->status, $targetStatus);
    }

    // 現在のステータスを更新
    $this->executor->execute(
        'UPDATE orders SET status = ?, updated_at = ? WHERE id = ?',
        [$targetStatus->value, $now, $orderId],
    );

    // 監査ログに追記
    $this->executor->execute(
        'INSERT INTO order_transitions (order_id, from_status, to_status, transitioned_at) VALUES (?, ?, ?, ?)',
        [$orderId, $order->status->value, $targetStatus->value, $now],
    );

    return new Order($order->id, $order->title, $targetStatus, $order->createdAt, $now);
}
```

> **アトミック性の注意**: トランザクションで囲まない場合、UPDATE と INSERT の間で失敗するとオーダーは新しい状態のままログレコードが残りません。真のアトミック性のために両方のステートメントをトランザクションで囲んでください。SQLite の WAL モードにより、同時アクセス下でもこれが安全に動作します。

---

## スキーマ: オーダー状態 + トランジション履歴

```sql
CREATE TABLE IF NOT EXISTS orders (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    title      TEXT NOT NULL,
    status     TEXT NOT NULL DEFAULT 'draft',
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS order_transitions (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    order_id        INTEGER NOT NULL,
    from_status     TEXT    NOT NULL,
    to_status       TEXT    NOT NULL,
    transitioned_at TEXT    NOT NULL,
    FOREIGN KEY (order_id) REFERENCES orders (id)
);
```

`order_transitions` は設計上追記専用です — UPDATE や DELETE エンドポイントは存在しません。監査のために完全なトランジション履歴が保持されます。

---

## トランジション履歴レスポンス

```json
{
  "order_id": 1,
  "transitions": [
    {"id": 1, "order_id": 1, "from_status": "draft", "to_status": "submitted", "transitioned_at": "2026-05-27 10:00:00"},
    {"id": 2, "order_id": 1, "from_status": "submitted", "to_status": "approved", "transitioned_at": "2026-05-27 11:00:00"}
  ]
}
```

リストは `id ASC` でソートされるため、履歴は時系列順になります。

---

## VULN — セキュリティアセスメント（FT237）

### V-01 — すべてのエンドポイントに認証なし

**攻撃**: 認証情報なしでオーダーを作成し、トランジションを適用する。

```bash
curl -s -X POST http://localhost:8200/orders/1/transitions \
  -H 'Content-Type: application/json' \
  -d '{"status":"approved"}'
```

**観察**: `200 OK` — トークンは不要。誰でも任意のオーダーを承認またはキャンセルできる。

**判定**: **EXPOSED**（FT237 デモのため設計上）。認証と認可を追加してください: ロール（submitter vs reviewer）でトランジションをゲートし、各オーダーをその所有者に制限してください。

---

### V-02 — 無効なステータス値

**攻撃**: 不明なステータス文字列を送信する。

```json
{"status": "hacked"}
{"status": ""}
```

**観察**: `OrderStatus::tryFrom('hacked')` = `null` → すべての有効なステータスをリストした `422`。

**判定**: **BLOCKED** — バックド enum の `tryFrom()` が未知の値を拒否する。

---

### V-03 — 不正なトランジション（終端状態 → アクティブ）

**攻撃**: `approved` または `cancelled` から別のステータスへのトランジションを試みる。

```json
{"status": "submitted"}   // approved から
{"status": "draft"}       // cancelled から
```

**観察**: `canTransitionTo()` が `false` を返す → `InvalidTransitionException` → `409 Conflict`、レスポンスボディに `from`/`to` コンテキストあり。

**判定**: **BLOCKED** — ステートマシンがドメインレベルですべてのトランジションルールを強制する。

---

### V-04 — 非数値のオーダー ID

**攻撃**: `{id}` として文字列または浮動小数点数を渡す。

```
GET /orders/abc
GET /orders/1.5
```

**観察**: `(int) 'abc'` = 0、`(int) '1.5'` = 1。`abc` の場合、`findById(0)` は `null` を返す → `404 Not Found`。`1.5` の場合、オーダー 1 が存在すれば返される — サイレントな切り捨て。

**判定**: **PARTIALLY BLOCKED** — 非数値文字列は 404 になる。浮動小数点数はサイレントに切り捨てられる。厳密なバリデーションのために `ctype_digit()` ガードを追加してください。

---

### V-05 — トランジション履歴が呼び出し元にスコープされていない

**攻撃**: 別のユーザーのトランジション履歴を読み取る。

```
GET /orders/1/transitions
```

**観察**: `200 OK` — 所有権や認証チェックなしで完全な履歴が返される。履歴は誰が提出、承認、またはキャンセルしたかを明かす（タイムスタンプを通じて、アクターは記録されていないが）。

**判定**: **EXPOSED** — 所有権モデルがない。オーダーに `created_by` フィールドを追加し、履歴の読み取りを所有者または認可されたレビュアーに制限してください。

---

### V-06 — `status` ボディフィールド経由の SQL インジェクション

**攻撃**: `status` 値に SQL メタ文字を埋め込む。

```json
{"status": "'; DROP TABLE orders; --"}
{"status": "approved' OR '1'='1"}
```

**観察**:
1. `OrderStatus::tryFrom("'; DROP TABLE orders; --")` = `null` → SQL 実行前に `422`。
2. チェックがバイパスされても、ステータスはパラメーター化された `?` 値として渡される。

**判定**: **BLOCKED** — 二重レイヤー: enum allowlist + パラメーター化クエリ。

---

### V-07 — 同じステータスへのトランジション（冪等性）

**攻撃**: 現在のステータスへのトランジションを送信する。

```json
// オーダーはすでに 'submitted'
{"status": "submitted"}
```

**観察**: `submitted` の `allowedTransitions()` は `[approved, rejected, cancelled]` — `submitted` はリストにない。`canTransitionTo(submitted)` が `false` を返す → `409 Conflict`。

**判定**: **BLOCKED** — 自己トランジションはステートマシンによって暗黙的に拒否される。

---

### V-08 — 同じオーダーへの同時トランジション

**攻撃**: 同じオーダーに対して 2 つの同時トランジションリクエストを送信する。

```
POST /orders/1/transitions {"status":"approved"}  // 同時リクエスト A
POST /orders/1/transitions {"status":"rejected"}  // 同時リクエスト B
```

**観察**: 両方のリクエストが UPDATE が実行される前にオーダー（ステータス = `submitted`）をフェッチする。両方とも `canTransitionTo()` = true を確認する。両方が UPDATE する — 2 番目の UPDATE が最初のものを上書きする。リクエストごとに 1 件のトランジションログレコードが挿入されるが、オーダーは最後に実行されたステータスで終わる。履歴には両方のトランジションが表示され、矛盾する（例: `submitted → approved`、次に `submitted → rejected`）。

**判定**: **EXPOSED** — 競合状態を防ぐために `findById` + `canTransitionTo` + `UPDATE` + `INSERT` シーケンスを単一のトランザクションで囲んでください。

---

### V-09 — 空白のみのタイトル

**攻撃**: 空のタイトルでオーダーを作成する。

```json
{"title": "   "}
```

**観察**: `trim($body['title'])` が `""` に削減される → `title === ''` チェックが実行される → `422 Unprocessable Entity`。

**判定**: **BLOCKED** — 空文字列チェックの前に `trim()` することで空白のみの入力を処理する。

---

### V-10 — 無制限のタイトル長

**攻撃**: 非常に長いタイトルでオーダーを作成する。

```json
{"title": "A".repeat(100_000)}
```

**観察**: 長さ制限が強制されていない — 非常に長いタイトルが制限なく `TEXT` カラムに保存される。

**判定**: **EXPOSED** — 長さガードを追加してください:
```php
if (mb_strlen($title) > 500) {
    $errors[] = ['field' => 'title', 'code' => 'too_long', 'message' => 'title must not exceed 500 characters.'];
}
```

---

## VULN サマリー

| # | 攻撃ベクター | 判定 |
|---|------------|------|
| V-01 | 認証なし | EXPOSED |
| V-02 | 無効なステータス値 | BLOCKED |
| V-03 | 終端状態からの不正なトランジション | BLOCKED |
| V-04 | 非数値のオーダー ID | PARTIALLY BLOCKED |
| V-05 | トランジション履歴が呼び出し元にスコープされていない | EXPOSED |
| V-06 | status ボディ経由の SQL インジェクション | BLOCKED |
| V-07 | 自己トランジション（同じステータス） | BLOCKED |
| V-08 | 同時トランジションの競合状態 | EXPOSED |
| V-09 | 空白のみのタイトル | BLOCKED |
| V-10 | 無制限のタイトル長 | EXPOSED |

**本番前に修正すべき実際の脆弱性**:
1. **V-01 / V-05** — 認証と認可を追加する（所有権スコープ）
2. **V-08** — トランジションをトランザクションで囲む
3. **V-10** — タイトル長制限を追加する
4. **V-04** — ID パラメーターに `ctype_digit()` ガードを追加する

---

## 関連 howto

- [`approval-workflow.md`](approval-workflow.md) — 別々のアクションエンドポイントを持つ enum ベースのステートマシン
- [`audit-trail.md`](audit-trail.md) — 追記専用監査ログパターン
- [`transactions.md`](transactions.md) — 複数書き込みシーケンスのトランザクションラッピング
- [`enforce-resource-ownership.md`](enforce-resource-ownership.md) — IDOR 防止
