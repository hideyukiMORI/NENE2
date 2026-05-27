# ハウツー: 予算追跡 API

> **FT リファレンス**: FT244 (`NENE2-FT/budgetlog`) — 予算追跡 API
> **ATK**: FT244 — クラッカー視点の攻撃テスト（ATK-01 から ATK-12）

`income`/`expense`/`transfer` トランザクション種別、DB トランザクション内での残高チェックを持つ `TransferFundsUseCase`、`QueryStringParser` による複数フィルターのトランザクション一覧表示、カテゴリ集計を備えた多アカウント予算追跡 API を示します。

---

## ルート

| メソッド | パス | 説明 |
|--------|-----------------------------------|------------------------------------------------------|
| `GET` | `/accounts` | すべてのアカウントを一覧表示する |
| `POST` | `/accounts` | アカウントを作成する（オプションの初期残高） |
| `GET` | `/accounts/{id}` | 単一アカウントを取得する |
| `POST` | `/accounts/{id}/transactions` | 収入または支出トランザクションを記録する |
| `GET` | `/accounts/{id}/transactions` | トランザクションを一覧表示する（フィルタリング可、ページネーション） |
| `GET` | `/accounts/{id}/summary` | 残高 + カテゴリごとの収支 |
| `POST` | `/transfers` | 2 つのアカウント間で資金を送金する |

---

## スキーマ

```sql
CREATE TABLE IF NOT EXISTS accounts (
    id      INTEGER PRIMARY KEY AUTOINCREMENT,
    name    TEXT    NOT NULL,
    balance INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE IF NOT EXISTS transactions (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    account_id  INTEGER NOT NULL REFERENCES accounts(id) ON DELETE CASCADE,
    amount      INTEGER NOT NULL,
    type        TEXT    NOT NULL CHECK(type IN ('income','expense','transfer')),
    category    TEXT    NOT NULL,
    description TEXT    NOT NULL DEFAULT '',
    recurring   INTEGER NOT NULL DEFAULT 0,
    created_at  TEXT    NOT NULL
);
```

`balance` と `amount` は整数として保存されます（最小通貨単位、例: セント）。`type` は DB レベルで `CHECK(type IN ('income','expense','transfer'))` で制約されています。`recurring` は `INTEGER`（`0`/`1`）として保存され、PHP の `bool` にマップされます。

---

## トランザクション種別許可リスト

コントローラーは `type` を明示的な許可リストに対してバリデーションします:

```php
if (!in_array($type, ['income', 'expense'], true)) {
    $errors[] = new ValidationError('type', 'Type must be income or expense.', 'invalid_value');
}
```

API からは `income` と `expense` のみが受け入れられます。`transfer` 種別は `TransferFundsUseCase` によって内部的に設定されます — 呼び出し元は `POST /accounts/{id}/transactions` 経由で直接注入することはできません。

---

## 残高更新: 読み取り後更新パターン

`POST /accounts/{id}/transactions` はトランザクション記録後にアカウント残高を更新します:

```php
$delta = $type === 'income' ? $amount : -$amount;
$this->accounts->updateBalance($id, $account->balance + $delta);
```

残高は最初に読み取られ（`findById`）、PHP でデルタが適用されてから書き戻されます（`updateBalance`）。これは**アトミックではありません** — 同時リクエストがレース条件を引き起こす可能性があります（ATK-09 参照）。

---

## TransferFundsUseCase: 残高チェック + DB トランザクション

送金は一貫性を保証するために DB トランザクションでラップされます:

```php
public function execute(int $fromId, int $toId, int $amount, string $description): void
{
    if ($amount <= 0) {
        throw new ValidationException([
            new ValidationError('amount', 'Amount must be greater than zero.', 'out_of_range'),
        ]);
    }

    $this->txManager->transactional(function (DatabaseQueryExecutorInterface $tx) use ($fromId, $toId, $amount, $description): void {
        // トランザクションエグゼキュータでコールバック内にリポジトリをインスタンス化する
        $accounts     = new SqliteAccountRepository($tx);
        $transactions = new SqliteTransactionRepository($tx);

        $from = $accounts->findById($fromId);
        $to   = $accounts->findById($toId);

        if ($from === null) {
            throw new ValidationException([new ValidationError('from_account_id', 'Source account not found.', 'not_found')]);
        }
        if ($to === null) {
            throw new ValidationException([new ValidationError('to_account_id', 'Destination account not found.', 'not_found')]);
        }
        if ($from->balance < $amount) {
            throw new ValidationException([new ValidationError('amount', 'Insufficient balance.', 'insufficient_balance')]);
        }

        $accounts->updateBalance($fromId, $from->balance - $amount);
        $accounts->updateBalance($toId, $to->balance + $amount);

        $transactions->create($fromId, $amount, 'transfer', 'transfer', $description, false, $now);
        $transactions->create($toId, $amount, 'transfer', 'transfer', $description, false, $now);
    });
}
```

リポジトリはトランザクションクロージャの**内側**で `$tx` エグゼキュータを使ってインスタンス化されます — これによりすべての読み取りと書き込みが同じ接続とトランザクション境界を共有することが保証されます。いずれかのステップがスローすると、トランザクション全体がロールバックされます。

同一アカウントガードはコントローラーにあります:
```php
if ($fromId === $toId && $fromId > 0) {
    $errors[] = new ValidationError('to_account_id', 'Cannot transfer to the same account.', 'invalid_value');
}
```

---

## 複数フィルターのトランザクション一覧表示

`GET /accounts/{id}/transactions` は複数の同時フィルターをサポートします:

```php
$category  = QueryStringParser::string($req, 'category');
$minAmount = QueryStringParser::int($req, 'min_amount');
$maxAmount = QueryStringParser::int($req, 'max_amount');
$recurring = QueryStringParser::bool($req, 'recurring');
```

`QueryStringParser::int()` はパラメーターが存在しない場合 `null` を返します — フィルターなし。`QueryStringParser::bool()` は存在しない場合は `null`、`"true"/"1"` では `true`、`"false"/"0"` では `false` を返します。

リポジトリは `WHERE` 句を動的に構築します:

```php
if ($category !== null)  { $where[] = 'category = ?'; $params[] = $category; }
if ($minAmount !== null) { $where[] = 'amount >= ?';  $params[] = $minAmount; }
if ($maxAmount !== null) { $where[] = 'amount <= ?';  $params[] = $maxAmount; }
if ($recurring !== null) { $where[] = 'recurring = ?'; $params[] = (int) $recurring; }
```

---

## カテゴリサマリー集計

`GET /accounts/{id}/summary` は残高とカテゴリごとの合計を返します:

```php
return $this->json->create([
    'balance'             => $account->balance,
    'income_by_category'  => $incomeByCategory,
    'expense_by_category' => $expenseByCategory,
]);
```

リポジトリは `SUM(amount)` を伴う `GROUP BY category` を使用します:

```sql
SELECT category, SUM(amount) AS total
FROM transactions
WHERE account_id = ? AND type = ?
GROUP BY category
ORDER BY total DESC
```

---

## ATK — クラッカー視点の攻撃テスト（FT244）

### ATK-01 — 認証なし: アカウントとトランザクションが公開

**攻撃**: 資格情報なしですべてのアカウントを一覧表示する。

```bash
curl -s http://localhost:8080/accounts
curl -s http://localhost:8080/accounts/1/transactions
```

**観測結果**: 両エンドポイントとも認証なしでデータを返します。任意の呼び出し元がすべてのアカウントとその残高を列挙できます。

**判定**: **EXPOSED** — すべてのエンドポイントに認証（API キー、JWT、またはセッション）を追加してください。アカウントはユーザースコープにする必要があります。

---

### ATK-02 — 負の初期残高でのアカウント作成

**攻撃**: 負残高チェックをバイパスする。

```json
{"name": "Attack", "initial_balance": -99999}
```

**観測結果**: `$initialBalance < 0` チェックが発動 → `out_of_range` エラー付きの `422 Unprocessable Entity`。

**判定**: **BLOCKED** — 明示的なガードが負の初期残高を拒否します。

---

### ATK-03 — 支出でアカウント残高が負になる

**攻撃**: 直接トランザクションでアカウント残高より大きな支出を記録する。

```bash
# アカウントの残高は 100
curl -X POST /accounts/1/transactions \
  -d '{"amount": 99999, "type": "expense", "category": "food"}'
```

**観測結果**: `createTransaction` ハンドラーは残高を読み取ってから充足チェックなしに減算します。`100 - 99999 = -99899` — 残高は負の整数として書き込まれます。

**判定**: **EXPOSED** — `POST /accounts/{id}/transactions` は非負の残高制約を強制しません。`POST /transfers`（`TransferFundsUseCase` 経由）のみが `if ($from->balance < $amount)` をチェックします。支出トランザクションの `createTransaction` に残高充足チェックを追加してください。

---

### ATK-04 — カテゴリまたは説明への SQL インジェクション

**攻撃**: `category` または `description` に SQL メタ文字を埋め込む。

```json
{"amount": 1, "type": "income", "category": "'; DROP TABLE transactions; --"}
```

**観測結果**: すべての値はパラメータ化された `?` 値としてバインドされます。SQL との文字列連結は発生しません。インジェクションペイロードはリテラルテキストとして保存されます。

**判定**: **BLOCKED** — パラメータ化クエリが SQL インジェクションを防止します。

---

### ATK-05 — Float 金額: `(int)` キャストの切り捨て

**攻撃**: 浮動小数点の金額を送信する。

```json
{"amount": 1.9, "type": "income", "category": "x"}
```

**観測結果**: `(int) $body['amount']` が `1.9` を `1` に切り捨てます。金額 `1.9` はサイレントに受け入れられ `1` として保存されます。`1.9` が拒否（または `2` に丸め）されることを期待している呼び出し元は驚くでしょう。

**判定**: **PARTIALLY BLOCKED** — 非整数の float が受け入れられてサイレントに切り捨てられます。非整数型を明示的に拒否するには `is_int($body['amount'])` を使用し、`1.9` に対して `422` を返してください。

---

### ATK-06 — ゼロまたは負の金額

**攻撃**: `amount: 0` または `amount: -100` を送信する。

```json
{"amount": 0, "type": "income", "category": "x"}
{"amount": -100, "type": "income", "category": "x"}
```

**観測結果**: 両方で `$amount <= 0` チェックが発動 → `422 Unprocessable Entity`。

**判定**: **BLOCKED** — 明示的なガードがゼロと負の金額を拒否します。

---

### ATK-07 — 同一アカウントへの送金

**攻撃**: アカウントからそれ自身に資金を送金する。

```json
{"from_account_id": 1, "to_account_id": 1, "amount": 100}
```

**観測結果**: `$fromId === $toId && $fromId > 0` が発動 → `to_account_id` の `invalid_value` エラー付きの `422 Unprocessable Entity`。

**判定**: **BLOCKED** — 同一アカウント送金は明示的に拒否されます。

---

### ATK-08 — 残高不足での送金

**攻撃**: 送金元アカウントの残高より多く送金する。

```json
{"from_account_id": 1, "to_account_id": 2, "amount": 99999}
```

**観測結果**: トランザクション内で `$from->balance < $amount` が発動 → `insufficient_balance` を持つ `ValidationException` → トランザクションロールバック → `422`。どちらの残高も変わりません。

**判定**: **BLOCKED** — `TransferFundsUseCase` は DB トランザクション内で残高をチェックします。ロールバックがアトミック性を保証します。

---

### ATK-09 — 直接支出トランザクションでのレース条件

**攻撃**: 残高チェックをどちらも通過する（チェックがない）が、合計で残高を超える 2 つの同時支出リクエストを送信する。

**観測結果**: `createTransaction` はトランザクションなしに読み取り後更新パターンを使用します:
1. スレッド A が `balance = 100` を読み取る
2. スレッド B が `balance = 100` を読み取る
3. スレッド A が 80 の支出を記録 → `balance = 20` を書き込む
4. スレッド B が 80 の支出を記録 → `balance = 20` を書き込む（本来は -60 であるべき）

`balance` カラムは正しい `-60` ではなく `20` で終わります — しかしより重要なのは、ビジネス制約（非負の残高）が直接トランザクションに対して全く強制されず、このパスが読み取り後更新をバイパスすることさえできる点です。

**判定**: **EXPOSED** — `createTransaction` パスには残高ガードもトランザクションラッピングもありません。修正方法: (1) `if ($type === 'expense' && $account->balance < $amount) → 422` を追加、(2) 読み取り後更新を DB トランザクションでラップする。

---

### ATK-10 — 別アカウントのトランザクションにアクセス（所有権なし）

**攻撃**: 別のユーザーのアカウントに属するトランザクションを読み取る。

```bash
curl -s http://localhost:8080/accounts/2/transactions
```

**観測結果**: エンドポイントは所有権チェックなしにアカウント 2 のすべてのトランザクションを返します。認証がないため、任意の呼び出し元が任意のアカウントを読み取れます。

**判定**: **EXPOSED**（ATK-01 と同じ根本原因）。アカウントは認証済みユーザーにスコープする必要があります — `WHERE account_id = ? AND owner_id = ?`。

---

### ATK-11 — `recurring` フィールド: 真値強制

**攻撃**: `recurring` に非ブール値を送信する。

```json
{"amount": 1, "type": "income", "category": "x", "recurring": "yes"}
{"amount": 1, "type": "income", "category": "x", "recurring": 1}
{"amount": 1, "type": "income", "category": "x", "recurring": 0}
```

**観測結果**: `(bool) $body['recurring']` が `"yes"` → `true`、`1` → `true`、`0` → `false` に強制します。任意の真値文字列が `recurring = true` に設定されます。厳密な `is_bool()` チェックがありません。

**判定**: **PARTIALLY BLOCKED** — 非ブール型がサイレントに強制されます。厳密な型強制のために `is_bool($body['recurring'])` を使用し、非ブール入力に `422` を返してください。

---

### ATK-12 — パスの非数値アカウント ID

**攻撃**: パスパラメーターに文字列 ID を渡す。

```
GET /accounts/abc/transactions
GET /accounts/1.5/transactions
```

**観測結果**: `(int) 'abc'` = `0`、`(int) '1.5'` = `1`。
- `abc` → `findById(0)` → `null` を返す → `404 Not Found`。
- `1.5` → `findById(1)` → アカウント 1 が存在する場合、サイレントに返す。

**判定**: **PARTIALLY BLOCKED** — 非数値文字列は 404 にマップされます。Float 文字列はサイレントに切り捨てられます。厳密なパスパラメーターチェックのために `ctype_digit()` バリデーションを追加してください。

---

## ATK まとめ

| # | 攻撃ベクター | 判定 |
|---|---------------|---------|
| ATK-01 | 認証なし（すべてのエンドポイントが公開） | EXPOSED |
| ATK-02 | 負の初期残高 | BLOCKED |
| ATK-03 | 支出で残高が負になる | EXPOSED |
| ATK-04 | カテゴリ/説明への SQL インジェクション | BLOCKED |
| ATK-05 | Float 金額がサイレントに切り捨てられる | PARTIALLY BLOCKED |
| ATK-06 | ゼロまたは負の金額 | BLOCKED |
| ATK-07 | 同一アカウントへの送金 | BLOCKED |
| ATK-08 | 残高不足での送金 | BLOCKED |
| ATK-09 | 直接支出でのレース条件 | EXPOSED |
| ATK-10 | クロスアカウントデータアクセス（所有権なし） | EXPOSED |
| ATK-11 | `recurring` 非ブール強制 | PARTIALLY BLOCKED |
| ATK-12 | 非数値アカウント ID | PARTIALLY BLOCKED |

**本番前に修正すべき実際の脆弱性**:
1. **ATK-01 / ATK-10** — 認証とユーザーごとのアカウント所有権を追加する
2. **ATK-03 / ATK-09** — `createTransaction` に残高充足チェック + DB トランザクションを追加する
3. **ATK-05** — 厳密な型強制のために `(int)` キャストを `is_int()` チェックに置き換える
4. **ATK-11** — `(bool)` キャストを `is_bool()` チェックに置き換える
5. **ATK-12** — ID パスパラメーターに `ctype_digit()` ガードを追加する

---

## 関連ハウツー

- [`credit-ledger.md`](credit-ledger.md) — 方向 ±1 と InsufficientCreditsException を持つ追記専用台帳
- [`multi-currency-wallet.md`](multi-currency-wallet.md) — 多通貨残高管理
- [`transactions.md`](transactions.md) — DatabaseTransactionManagerInterface パターン
- [`note-management-ownership.md`](note-management-ownership.md) — IDOR 防止付きユーザーごとのリソース所有権
