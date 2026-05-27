# ハウツー: クレジット台帳 API

> **FT リファレンス**: FT234 (`NENE2-FT/creditslog`) — クレジット台帳 API

残高を直接保存せず、クエリ時に `SUM(amount * direction)` として計算する追記専用クレジット台帳を実演します。クレジットの獲得、オーバードラフトガード付きの消費、ユニークキーによる冪等な獲得、フィルタリング可能なトランザクション履歴をサポートします。

---

## ルート

| メソッド | パス | 説明 |
|--------|----------------------------------------|-------------------------------------------------|
| `POST` | `/users/{userId}/credits/earn`         | クレジットを獲得する（残高に追加） |
| `POST` | `/users/{userId}/credits/spend`        | クレジットを消費する（残高から差し引き、オーバードラフト時は 409） |
| `GET`  | `/users/{userId}/credits/balance`      | 現在の残高を取得する |
| `GET`  | `/users/{userId}/credits/transactions` | トランザクション履歴を一覧表示する（オプション `?type=`） |

---

## 台帳モデル: 符号付き金額の代わりに `direction`

正と負の金額を保存する代わりに、各トランザクションは正の `amount` と符号付きの `direction`（獲得の場合は `+1`、消費の場合は `-1`）を保存します:

```sql
CREATE TABLE IF NOT EXISTS credit_transactions (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id         TEXT    NOT NULL,
    type            TEXT    NOT NULL CHECK(type IN ('earn', 'spend', 'adjust')),
    amount          INTEGER NOT NULL CHECK(amount > 0),
    direction       INTEGER NOT NULL CHECK(direction IN (1, -1)),
    description     TEXT    NOT NULL DEFAULT '',
    idempotency_key TEXT    UNIQUE,
    created_at      TEXT    NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_credit_transactions_user ON credit_transactions (user_id);
```

`direction` カラムパターンの利点:
- `CHECK(amount > 0)` は生の金額が常に正であることを強制します — 挿入時の誤った二重否定バグなし。
- `CHECK(direction IN (1, -1))` は乗数を 2 つの有効な値に制限します。
- 残高の計算式は一様です: `SUM(amount * direction)` — 集計に条件分岐なし。
- `adjust` 型は手動修正（例: 払い戻し、管理者付与）のためにどちらの direction でも使用できます。

---

## 残高計算

残高は読み取り時に計算されます — `balance` カラムは更新されません:

```php
public function balance(string $userId): int
{
    $row = $this->db->fetchOne(
        'SELECT COALESCE(SUM(amount * direction), 0) AS bal FROM credit_transactions WHERE user_id = ?',
        [$userId],
    );

    return (int) ($row['bal'] ?? 0);
}
```

`COALESCE(..., 0)` はユーザーにトランザクションがない場合を処理します — SQL の空集合の `SUM` は `NULL` を返すため `0` にキャストされますが、`COALESCE` によって意図が明示されます。

`user_id` のインデックスにより、`SUM` 集計はそのユーザーの行のみをスキャンします。
大規模な台帳では、楽観的ロックを使ったキャッシュ残高カラムやイベントソースのスナップショットが検討に値します（`add-optimistic-locking.md` を参照）。

---

## オプションの冪等キーを使った獲得

`idempotency_key` を指定すると、獲得操作が安全に再試行できます — 重複キーは新しいレコードを挿入する代わりに元のトランザクションを返します:

```php
public function earn(string $userId, int $amount, string $description, ?string $idempotencyKey): CreditTransaction
{
    $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');

    if ($idempotencyKey !== null) {
        try {
            $id = $this->db->insert(
                'INSERT INTO credit_transactions (user_id, type, amount, direction, description, idempotency_key, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$userId, 'earn', $amount, 1, $description, $idempotencyKey, $now],
            );
        } catch (DatabaseConstraintException) {
            // キーは既に使用済み — 元のトランザクションを返す
            $row = $this->db->fetchOne(
                'SELECT * FROM credit_transactions WHERE idempotency_key = ?',
                [$idempotencyKey],
            );
            assert($row !== null);

            return $this->hydrate($row);
        }
    } else {
        $id = $this->db->insert(
            'INSERT INTO credit_transactions (user_id, type, amount, direction, description, idempotency_key, created_at) VALUES (?, ?, ?, ?, ?, NULL, ?)',
            [$userId, 'earn', $amount, 1, $description, $now],
        );
    }

    $row = $this->db->fetchOne('SELECT * FROM credit_transactions WHERE id = ?', [$id]);
    assert($row !== null);

    return $this->hydrate($row);
}
```

`idempotency_key` の `UNIQUE` 制約により DB が権威となります — アプリケーションは `DatabaseConstraintException` をキャッチして既存の行を再フェッチします。これにより SELECT-before-INSERT の競合状態を回避します: 同じキーでの 2 つの並行リトライのうち、正確に 1 つの INSERT のみが成功します。

---

## オーバードラフトガード付きの消費

```php
public function spend(string $userId, int $amount, string $description): CreditTransaction
{
    $balance = $this->balance($userId);
    if ($balance < $amount) {
        throw new InsufficientCreditsException("Insufficient credits: balance={$balance}, requested={$amount}");
    }

    $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    $id  = $this->db->insert(
        'INSERT INTO credit_transactions (user_id, type, amount, direction, description, idempotency_key, created_at) VALUES (?, ?, ?, ?, ?, NULL, ?)',
        [$userId, 'spend', $amount, -1, $description, $now],
    );
    // ...
}
```

コントローラーは `InsufficientCreditsException` を `409 Conflict` にマップします:

```php
try {
    $tx = $this->repo->spend($userId, $amount, $description);
} catch (InsufficientCreditsException $e) {
    return $this->problems->create($request, 'insufficient-credits', 'Insufficient Credits', 409, $e->getMessage());
}
```

リクエストは有効で、残高の状態が妨げているため、`422 Unprocessable Entity` より `409 Conflict` が適切です。より多くのクレジットを獲得した後に再試行した呼び出し元は成功します。

> **並行性の注意**: 残高チェックと挿入はトランザクションでラップされていません。2 つの並行する消費リクエストが両方とも十分な残高を読み取り、両方とも挿入して残高を負にする可能性があります。並行性下での正確さのために、`SELECT ... FOR UPDATE`（MySQL/PostgreSQL）を使ったトランザクションでラップするか、SQLite のシリアル化書き込みを使用してください。

---

## 金額バリデーション

```php
$amount = isset($body['amount']) && is_int($body['amount']) ? $body['amount'] : 0;

$errors = [];
if ($amount <= 0) {
    $errors[] = ['field' => 'amount', 'code' => 'invalid', 'message' => 'amount must be a positive integer.'];
}
```

`is_int()` 厳格チェックは JSON の浮動小数点（`1.5`）と文字列（`"10"`）を拒否します。DB レベルの `CHECK(amount > 0)` がバックストップとして機能しますが、アプリケーション層での拒否により DB エラーの代わりに構造化された Problem Details レスポンスが返されます。

---

## タイプフィルター付きトランザクション履歴

```php
private function transactions(ServerRequestInterface $request): ResponseInterface
{
    $params = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
    $userId = (string) ($params['userId'] ?? '');
    $q      = $request->getQueryParams();
    $type   = isset($q['type']) && is_string($q['type']) ? $q['type'] : null;

    $txs = $this->repo->listTransactions($userId, $type);

    return $this->json->create([
        'user_id'      => $userId,
        'transactions' => array_map(fn (CreditTransaction $t) => $t->toArray(), $txs),
    ]);
}
```

`?type=earn` または `?type=spend` でリストを絞り込みます。型の値にはバリデーションを行いません — 未知の型（例: `?type=refund`）はエラーではなく空のリストを返します。これはフィルターパラメーターとして許容されます。

---

## スキーマ設計メモ

| カラム | 目的 |
|--------|---------|
| `amount` | 常に正; `CHECK(amount > 0)` で強制 |
| `direction` | `+1`（獲得）または `-1`（消費）; `CHECK(direction IN (1, -1))` |
| `type` | 人間向けラベル: `earn`、`spend`、`adjust`; `CHECK` 許可リスト |
| `idempotency_key` | リトライセーフな獲得操作のオプション `UNIQUE` キー |
| `description` | トランザクションの自由形式メモ |

`balance` カラムなし — 現在の残高は常に台帳から導出されます。

---

## 関連ハウツー

- [`idempotency.md`](idempotency.md) — 一般的な冪等キーパターン
- [`multi-currency-wallet.md`](multi-currency-wallet.md) — 多通貨残高管理
- [`point-loyalty-system.md`](point-loyalty-system.md) — ティアレベル付きのポイント獲得/利用
- [`add-optimistic-locking.md`](add-optimistic-locking.md) — バージョンガード付きキャッシュ残高
- [`transactions.md`](transactions.md) — トランザクションでの残高チェックと挿入のラップ
