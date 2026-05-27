# ハウツー: イベントソーシング台帳

> **FT リファレンス**: FT310 (`NENE2-FT/eventsourcelog`) — イベントソーシングアカウント台帳: イミュータブルイベントログ（追記専用）、`replayBalance()` がすべてのイベントを再生して現在の残高を計算、入出金イベントは削除されない、`is_int()` 厳格な金額バリデーション、最大金額 1,000,000,000、別アカウントは残高を共有しない、17 テスト / 24 アサーション PASS。

このガイドでは、イベントソーシングを使ったアカウント台帳の実装方法を示します: 現在の残高は直接保存されず、過去のすべてのイベントを再生することで導出されます。

## スキーマ

```sql
CREATE TABLE accounts (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    owner      TEXT    NOT NULL,
    created_at TEXT    NOT NULL
);

CREATE TABLE events (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    aggregate_id INTEGER NOT NULL,
    event_type   TEXT    NOT NULL,
    payload      TEXT    NOT NULL,  -- JSON: { "amount": 100 }
    occurred_at  TEXT    NOT NULL,
    FOREIGN KEY (aggregate_id) REFERENCES accounts(id)
);
```

`events` は追記専用です。イベントに対して `UPDATE` や `DELETE` はありません。各入金または出金が新しい行を追記します。

## エンドポイント

| メソッド | パス | 説明 |
|--------|------|-------------|
| `POST` | `/accounts` | アカウントを作成する |
| `GET` | `/accounts/{id}/balance` | 現在の残高を取得する（再生） |
| `POST` | `/accounts/{id}/deposit` | 入金イベントを追加する |
| `POST` | `/accounts/{id}/withdraw` | 出金イベントを追加する |
| `GET` | `/accounts/{id}/events` | すべてのイベントを一覧表示する |

## 残高 — イベントからの再生

```php
public function replayBalance(int $aggregateId): int
{
    $events  = $this->findEventsByAggregateId($aggregateId);
    $balance = 0;

    foreach ($events as $event) {
        $amount = isset($event->payload['amount']) ? (int) $event->payload['amount'] : 0;

        if ($event->eventType === DomainEvent::TYPE_DEPOSITED) {
            $balance += $amount;
        } elseif ($event->eventType === DomainEvent::TYPE_WITHDRAWN) {
            $balance -= $amount;
        }
    }

    return $balance;
}
```

残高はどこにも保存されません — すべてのイベントを再生することで毎回新鮮に計算されます。新しいアカウントは 0 から開始します（イベントなし）。イベントログが信頼できる情報源です。

## 入金バリデーション

```php
$amount = isset($body['amount']) && is_int($body['amount']) ? $body['amount'] : 0;
if ($amount <= 0 || $amount > 1_000_000_000) {
    return $this->problems->create($request, 'validation-failed',
        'amount must be a positive integer not exceeding 1000000000.', 422, '');
}
// イベントを追加
$this->repo->appendEvent($id, 'AccountDeposited', ['amount' => $amount], date('c'));
```

- `is_int()` で浮動小数点、文字列、null を拒否
- `> 0` でゼロと負の値を拒否
- `<= 1_000_000_000` で単一トランザクション金額に上限を設ける

## 出金 — 先に再生残高をチェック

```php
$balance = $this->repo->replayBalance($id);
if ($amount > $balance) {
    return $this->problems->create($request, 'validation-failed',
        'insufficient funds.', 422, '');
}
$this->repo->appendEvent($id, 'AccountWithdrawn', ['amount' => $amount], date('c'));
```

出金を受け付ける前に残高を再生します。オーバードラフトチェックはアプリケーションレイヤーで行われます — DB 制約ではありません（イベント行には「後の残高」という概念がありません）。

## アカウント分離

各アカウントは独自の `aggregate_id` を持ちます。`replayBalance()` は `aggregate_id` でフィルタリングするため:
- アカウント 1 の入金はアカウント 2 の残高に影響しない
- イベントリストはアカウントごと（クロス汚染なし）

---

## やってはいけないこと

| アンチパターン | リスク |
|---|---|
| 再生の代わりに `balance` カラムを保存する | 変更可能な状態が同期を失う可能性がある。イベントが真実 |
| イベント削除を許可する | イベントを削除すると残高計算が遡及的に間違いになる |
| 浮動小数点の `amount` を受け付ける | イベントペイロードの小数金額が整数再生を破損する |
| アプリケーションレベルのオーバードラフトチェックなし | イベントに残高制約がないため負の残高が可能 |
| `aggregate_id` フィルターなしの共有イベントテーブル | すべてのアカウントが同じイベントストリームを共有する |
| 1 つの残高のためにすべてのアカウントのイベントを再生する | アカウントでフィルタリングする代わりにフルテーブルスキャン |
