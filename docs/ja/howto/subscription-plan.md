# ハウツー: サブスクリプション / プラン管理 API（VULN-A〜L）

このガイドでは、ユーザーがプランにサブスクライブできるサブスクリプション管理 API を実演します。重複防止、キャンセル、IDOR 保護が含まれます。

## パターン概要

- シードプランはスキーマ時に挿入されます（`free`、`starter`、`pro`、`annual`）。
- ユーザーは `plan_id` を持つ `POST /subscriptions` でサブスクライブします。
- 各（ユーザー、プラン）ペアは最大 1 つのアクティブなサブスクリプションを持てます。
- キャンセルするとステータスが `'cancelled'` に変わります; キャンセル済みのサブスクリプションは再度キャンセルできません。

## スキーマ

```sql
CREATE TABLE IF NOT EXISTS plans (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    name        TEXT    NOT NULL UNIQUE,
    price_cents INTEGER NOT NULL,
    interval    TEXT    NOT NULL DEFAULT 'monthly'
);

CREATE TABLE IF NOT EXISTS subscriptions (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id      INTEGER NOT NULL,
    plan_id      INTEGER NOT NULL,
    status       TEXT    NOT NULL DEFAULT 'active',
    started_at   TEXT    NOT NULL,
    cancelled_at TEXT,
    FOREIGN KEY (plan_id) REFERENCES plans(id),
    UNIQUE (user_id, plan_id, status)
);
```

## VULN-A: SQL インジェクション

すべてのクエリは PDO プリペアドステートメントを使用します。プラン名とユーザー ID は補間されません。

## VULN-C: IDOR

非管理者ユーザーは自分自身のサブスクリプションのみにアクセスできます。別のユーザーのサブスクリプションへのアクセスは 404 を返します（403 ではなく）:

```php
if (!$isAdmin && (int) $sub['user_id'] !== $uid) {
    return $this->problem(404, 'not-found', 'Subscription not found.');
}
```

## VULN-D: 管理者フェイルクローズド

```php
private function isAdmin(ServerRequestInterface $req): bool
{
    if ($this->adminKey === '') {
        return false;
    }
    return hash_equals($this->adminKey, $req->getHeaderLine('X-Admin-Key'));
}
```

## VULN-G: ReDoS

パス ID は `ctype_digit()` + 長さ制限を使用します。非数値パス（`/subscriptions/abc`）はすぐに 404 を返します。

## VULN-J: 型の混乱

```php
$planId = $body['plan_id'] ?? null;
if (!is_int($planId) || $planId < 1) {
    return $this->problem(422, 'validation-failed', 'plan_id must be a positive integer.');
}
```

文字列 `"2"`、浮動小数点 `2.5`、ゼロはすべて 422 を返します。

## 重複防止

```php
$stmt = $this->pdo->prepare(
    "SELECT id FROM subscriptions WHERE user_id = :uid AND plan_id = :pid AND status = 'active'"
);
```

すでにアクティブなプランにサブスクライブしようとすると 409 が返されます。

## キャンセルの冪等性

`cancel()` メソッドは更新前にステータスを確認します。`'cancelled'` サブスクリプションへの 2 回目のキャンセル試行は `'already_cancelled'` を返し → 409（204 ではなく）。

## リッチなレスポンスのための JOIN

サブスクリプションの詳細は JOIN 経由でプラン情報を含みます:

```sql
SELECT s.*, p.name AS plan_name, p.price_cents, p.interval AS plan_interval
FROM subscriptions s JOIN plans p ON p.id = s.plan_id
WHERE s.id = :id
```

## ルート

```
GET    /plans                           利用可能なプランを一覧表示する（パブリック）
POST   /subscriptions                   プランにサブスクライブする（X-User-Id 必須）
GET    /subscriptions/{id}              サブスクリプションを取得する（所有者または管理者）
POST   /subscriptions/{id}/cancel       サブスクリプションをキャンセルする（所有者または管理者）
GET    /users/{userId}/subscriptions    ユーザーのサブスクリプションを一覧表示する（所有者または管理者）
```

## 関連

- FT213 ソース: `../NENE2-FT/subscriptionlog/`
- 関連: `docs/howto/coupon-redemption.md`（FT204、ステートフルなユーザーごとの制限）
- 関連: `docs/howto/wish-list-api.md`（FT207、VULN パターン）
