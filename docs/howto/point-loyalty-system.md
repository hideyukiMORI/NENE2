---
title: "ポイント・ロイヤルティシステム"
category: product
tags: [points, loyalty, idempotency, rbac, balance]
difficulty: intermediate
related: [point-ledger-api, credit-ledger]
---

# ポイント・ロイヤルティシステム

ポイント付与・消費・残高管理・admin 調整を備えたロイヤルティシステムの実装ガイド。
冪等トランザクション（reference_id）・残高下限保護・admin RBAC を解説する。

## 概要

- ユーザーがポイントを獲得（earn）・消費（spend）できる
- admin のみポイントを直接調整（adjust: add/subtract）できる
- 残高はトランザクションの `balance_after` を積み上げて管理
- `reference_id` による冪等トランザクション（二重付与・二重消費防止）
- `max_uses` ではなく上限金額（MAX_EARN_PER_TRANSACTION）でバルク不正付与を防ぐ

## エンドポイント

| Method | Path | 説明 | 権限 |
|---|---|---|---|
| `GET` | `/users/{userId}/points` | 残高取得 | 本人 or admin |
| `GET` | `/users/{userId}/points/history` | 履歴取得 | 本人 or admin |
| `POST` | `/users/{userId}/points/earn` | ポイント付与 | 本人 or admin |
| `POST` | `/users/{userId}/points/spend` | ポイント消費 | 本人 or admin |
| `POST` | `/users/{userId}/points/adjust` | ポイント調整 | admin のみ |

## データベース設計

```sql
CREATE TABLE point_transactions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    type TEXT NOT NULL CHECK (type IN ('earn', 'spend', 'adjust', 'expire')),
    amount INTEGER NOT NULL CHECK (amount > 0),
    balance_after INTEGER NOT NULL CHECK (balance_after >= 0),
    description TEXT NOT NULL,
    reference_id TEXT,
    created_at TEXT NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id)
);
```

`balance_after >= 0` の CHECK 制約が残高マイナスを DB レベルで防ぐ。
`amount > 0` の CHECK 制約が 0 以下のトランザクションを DB レベルで拒否する。

## 残高計算

```php
public function getBalance(int $userId): int
{
    $row = $this->executor->fetchOne(
        'SELECT balance_after FROM point_transactions WHERE user_id = ? ORDER BY id DESC LIMIT 1',
        [$userId]
    );
    return $row !== null ? (int) $row['balance_after'] : 0;
}
```

最新トランザクションの `balance_after` が現在残高。
別テーブルに残高を保持しないため、トランザクション履歴が唯一の真実源（SSOT）。

## 冪等トランザクション（reference_id）

```php
if ($referenceId !== null) {
    $existing = $this->repository->findByReferenceId($targetUserId, $referenceId);
    if ($existing !== null) {
        return $this->responseFactory->create($this->formatTransaction($existing), 200);
    }
}
// ... 新規トランザクション処理
```

`reference_id` が既に存在する場合は既存トランザクションを返す（200）。
二重クレジット・二重デビットを防ぐ。

## ポイント消費の残高チェック

```php
$balance = $this->repository->getBalance($targetUserId);
if ($balance < $amount) {
    return $this->responseFactory->create([
        'error' => 'insufficient points',
        'balance' => $balance,
        'required' => $amount,
    ], 422);
}
$balanceAfter = $balance - $amount;  // 必ず >= 0
```

アプリ層で残高チェックを行い、DB の CHECK 制約と二重防御。

## admin 調整（adjust）

```php
// adjust_type: 'add' (default) or 'subtract'
if ($adjustType === 'subtract') {
    if ($balance < $amount) { return 422 'insufficient points for adjustment'; }
    $balanceAfter = $balance - $amount;
} else {
    $balanceAfter = $balance + $amount;
}
$this->repository->addTransaction($userId, 'adjust', $amount, $balanceAfter, $description, null, $now);
```

## 上限制御（MAX_EARN_PER_TRANSACTION）

```php
private const int MAX_EARN_PER_TRANSACTION = 10000;

if ($amount > self::MAX_EARN_PER_TRANSACTION) {
    return $this->responseFactory->create([
        'error' => 'amount exceeds maximum per transaction',
        'max' => self::MAX_EARN_PER_TRANSACTION,
    ], 422);
}
```

1 回のトランザクションで大量ポイントを不正付与する攻撃を防ぐ。

## アクセス制御

自分の残高・履歴は本人のみ参照可能。admin は全ユーザーを参照・操作可能。

```php
if ($targetUserId !== $actorId && !$this->isAdmin($request)) {
    return $this->responseFactory->create(['error' => 'access denied'], 403);
}
```

earn/spend は自分のポイントのみ操作可能（他人のポイントを自分で増やせない）。

## レスポンス例

### POST /users/2/points/earn
```json
{
  "id": 1,
  "user_id": 2,
  "type": "earn",
  "amount": 100,
  "balance_after": 100,
  "description": "Purchase reward",
  "reference_id": "order-123",
  "created_at": "2026-05-21T..."
}
```

### GET /users/2/points/history
```json
{
  "user_id": 2,
  "balance": 70,
  "transactions": [
    {"id": 2, "type": "spend", "amount": 30, "balance_after": 70, ...},
    {"id": 1, "type": "earn", "amount": 100, "balance_after": 100, ...}
  ]
}
```
