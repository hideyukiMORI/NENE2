# イベントソーシング（基本）

状態をイミュータブルなドメインイベントのシーケンスとして永続化します。イベントストリームを再生することで現在の状態を導出します。

## 概要

イベントソーシングは**現在の状態**（what is）ではなく**何が起きたか**（what happened）、つまりイベントを保存します。アカウントの残高は保存されず、すべての入出金イベントを再生することで計算されます。イベントはイミュータブルです — 更新も削除もされません。

## データベーススキーマ

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
    payload      TEXT    NOT NULL,  -- JSON
    occurred_at  TEXT    NOT NULL,
    FOREIGN KEY (aggregate_id) REFERENCES accounts(id)
);
```

`accounts` は集約ルートです。`events` は追記専用のイベントログです。`balance` カラムはありません — 常にイベントから計算されます。

## イベントタイプ

タイポを防ぎ静的解析を有効にするために、イベントタイプを定数として定義します:

```php
public const string TYPE_ACCOUNT_CREATED = 'account_created';
public const string TYPE_DEPOSITED       = 'deposited';
public const string TYPE_WITHDRAWN       = 'withdrawn';
```

## イベントの追加

イベントは常に挿入され、更新されません。API にはイベントを変更または削除するエンドポイントはありません:

```php
public function appendEvent(int $aggregateId, string $eventType, array $payload, string $now): DomainEvent
{
    $payloadJson = json_encode($payload, JSON_THROW_ON_ERROR);

    $this->executor->execute(
        'INSERT INTO events (aggregate_id, event_type, payload, occurred_at) VALUES (?, ?, ?, ?)',
        [$aggregateId, $eventType, $payloadJson, $now],
    );
    ...
}
```

## 状態の再生

挿入順序でイベントをロードし、現在の状態に畳み込みます:

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

`ORDER BY id ASC` は再生順序を保証します。`ORDER BY occurred_at ASC` は壊れやすい — 同じタイムスタンプを持つ 2 つのイベントは未定義の順序になります。

## 金額バリデーション

イベントを追加する前に金額を厳格にバリデーションします:

```php
$amount = isset($body['amount']) && is_int($body['amount']) ? $body['amount'] : 0;

if ($amount <= 0 || $amount > 1_000_000_000) {
    return 422;
}
```

- `is_int()` で浮動小数点値（例: `1.9`）を拒否。PHP は黙って `1` に切り捨てる
- 上限は複数の大きな入金を合計する際の整数オーバーフローを防ぐ
- API レイヤーで拒否する — 無効な金額をイベントログに到達させない

## 残高不足

出金イベントを追加する前に残高をチェックします:

```php
$balance = $this->repo->replayBalance($id);

if ($amount > $balance) {
    return $this->problems->create($request, 'insufficient-funds', 'Insufficient funds.', 422, '');
}

$event = $this->repo->appendEvent($id, DomainEvent::TYPE_WITHDRAWN, ['amount' => $amount], $now);
```

残高チェックはハンドラーで行われます（リポジトリではなく）。これはデータ整合性制約ではなくビジネスルールだからです。

## イベント分離

イベントは `aggregate_id` によって集約にスコープされます。アカウント A のイベントを再生してもアカウント B に影響しません:

```sql
SELECT * FROM events WHERE aggregate_id = ? ORDER BY id ASC
```

## セキュリティプロパティ

| プロパティ | 実装 |
|---|---|
| イベントのイミュータビリティ | イベントに DELETE/UPDATE エンドポイントなし |
| 金額範囲 | 1〜1,000,000,000（int）— 浮動小数点とオーバーフロー値を拒否 |
| 残高不足 | 出金前に残高を再生。不足の場合は 422 |
| クロスアカウント分離 | すべてのクエリが aggregate_id でフィルタリング |
| ペイロードインジェクション | ペイロードは常に `['amount' => int]`。ユーザー制御のキーなし |
| イベントタイプインジェクション | イベントタイプは常に定数から。ユーザー制御の event_type なし |

## ルートまとめ

| メソッド | パス | 説明 |
|---|---|---|
| `POST` | `/accounts` | アカウントを作成する |
| `POST` | `/accounts/{id}/deposit` | 入金イベントを追加する |
| `POST` | `/accounts/{id}/withdraw` | 出金イベントを追加する（残高チェック） |
| `GET` | `/accounts/{id}/balance` | イベントから残高を再生する |
| `GET` | `/accounts/{id}/events` | アカウントのすべてのイベントを一覧表示する |
