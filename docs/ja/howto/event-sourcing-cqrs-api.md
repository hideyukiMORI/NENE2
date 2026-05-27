# ハウツー: イベントソーシング & CQRS API

> **FT リファレンス**: `NENE2-FT/eventstore` — 集約ごとのシーケンス番号を持つ追記専用イベントログ、許可リストイベントタイプ、イベントから再構築された読み取りモデルプロジェクション、残高追跡、17 テスト PASS。

このガイドでは、イベントソーシングの実装方法を示します: すべての状態変更をイミュータブルなイベントとして保存し、イベントログから現在の状態を計算し、読み取りモデルプロジェクションを公開します。

## スキーマ

```sql
-- 追記専用のイベントログ — 行を UPDATE または DELETE しない
CREATE TABLE domain_events (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    aggregate_id   TEXT    NOT NULL,  -- 例: "acc-001"
    aggregate_type TEXT    NOT NULL,  -- 例: "account"
    event_type     TEXT    NOT NULL,  -- 例: "MoneyDeposited"
    payload        TEXT    NOT NULL DEFAULT '{}',  -- JSON
    sequence       INTEGER NOT NULL,  -- 集約ごとのカウンター、1 から開始
    occurred_at    TEXT    NOT NULL,
    UNIQUE(aggregate_id, sequence)
);

-- 読み取りモデル: イベントから再構築された現在のアカウント状態
CREATE TABLE account_projections (
    account_id    TEXT    PRIMARY KEY,
    owner         TEXT    NOT NULL,
    balance_cents INTEGER NOT NULL DEFAULT 0,
    is_open       INTEGER NOT NULL DEFAULT 1,
    last_sequence INTEGER NOT NULL DEFAULT 0,
    updated_at    TEXT    NOT NULL
);
```

`UNIQUE(aggregate_id, sequence)` は重複イベント挿入を防止します。プロジェクションは常にイベントログから導出されます — いつでも再生して再構築できます。

## イベントタイプ（許可リスト）

```php
const ALLOWED_EVENTS = [
    'AccountOpened',
    'MoneyDeposited',
    'MoneyWithdrawn',
    'AccountClosed',
];
```

未知のイベントタイプは 422 を返します。プロジェクションロジックに明示的なハンドラーを持つイベントのみを追加してください。

## エンドポイント

| メソッド | パス | 説明 |
|--------|-------------------------------|---------------------------------|
| `POST` | `/accounts`                   | アカウントを開設する（AccountOpened を発行） |
| `GET`  | `/accounts`                   | すべてのアカウントプロジェクションを一覧表示する |
| `GET`  | `/accounts/{id}`              | アカウントプロジェクションを取得する（見つからない場合 404） |
| `POST` | `/accounts/{id}/events`       | アカウントにイベントを追加する |
| `GET`  | `/accounts/{id}/events`       | アカウントのイベントログを一覧表示する |

## アカウント開設

```php
POST /accounts
{"account_id": "acc-001", "owner": "Alice"}

→ 201
{
  "event_type": "AccountOpened",
  "aggregate_id": "acc-001",
  "sequence": 1,
  "payload": {"owner": "Alice"},
  "occurred_at": "..."
}
```

アカウント開設は `AccountOpened` イベント（sequence=1）を作成し、プロジェクションを初期化します。

## イベントの追加

```php
POST /accounts/acc-001/events
{"event_type": "MoneyDeposited", "payload": {"amount_cents": 50000}}

→ 201
{
  "event_type": "MoneyDeposited",
  "aggregate_id": "acc-001",
  "sequence": 2,         // ← 集約ごとにインクリメント
  "payload": {"amount_cents": 50000},
  "occurred_at": "..."
}
```

各アカウントは**独立したシーケンスカウンター**を持ちます。`acc-001` と `acc-002` は両方とも 1 から開始します。

```php
// 無効なイベントタイプ → 422
POST /accounts/acc-001/events  {"event_type": "UnknownEvent"}
→ 422

// 存在しないアカウント → 404
POST /accounts/nonexistent/events  {"event_type": "MoneyDeposited", "payload": {"amount_cents": 1000}}
→ 404
```

## 読み取りモデルプロジェクション

```php
GET /accounts/acc-001

→ 200
{
  "account_id": "acc-001",
  "owner": "Alice",
  "balance_cents": 60000,   // 50000 入金 + 10000 入金
  "is_open": true,
  "last_sequence": 3
}

// AccountClosed イベント適用後
GET /accounts/acc-001  // AccountClosed 追加後
→ 200  {"is_open": false, "last_sequence": 4}
```

```php
GET /accounts/nonexistent
→ 404
```

## イベントログ

```php
GET /accounts/acc-001/events

→ 200
{
  "total": 3,
  "items": [
    {"event_type": "AccountOpened",  "sequence": 1, ...},
    {"event_type": "MoneyDeposited", "sequence": 2, "payload": {"amount_cents": 50000}, ...},
    {"event_type": "MoneyWithdrawn", "sequence": 3, "payload": {"amount_cents": 30000}, ...}
  ]
}
```

`sequence ASC` で順序付け — 時系列順。

```php
// 未知のアカウント → 空リスト（404 ではなく）
GET /accounts/nonexistent/events
→ 200  {"total": 0, "items": []}
```

## 実装

### シーケンス生成

```php
public function nextSequence(string $aggregateId): int
{
    $row = $this->db->fetchOne(
        'SELECT MAX(sequence) AS seq FROM domain_events WHERE aggregate_id = ?',
        [$aggregateId],
    );
    return (int) ($row['seq'] ?? 0) + 1;
}
```

### イベント追加 + プロジェクション更新（トランザクション）

```php
public function appendEvent(string $aggregateId, string $eventType, array $payload): array
{
    $sequence = $this->nextSequence($aggregateId);
    $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

    $this->tx->begin();
    try {
        // イミュータブルログに追記
        $id = $this->db->insert(
            'INSERT INTO domain_events (aggregate_id, aggregate_type, event_type, payload, sequence, occurred_at)
             VALUES (?, ?, ?, ?, ?, ?)',
            [$aggregateId, 'account', $eventType, json_encode($payload), $sequence, $now->format('Y-m-d H:i:s')],
        );

        // プロジェクションを更新
        $this->applyProjection($aggregateId, $eventType, $payload, $sequence, $now);

        $this->tx->commit();
    } catch (\Throwable $e) {
        $this->tx->rollback();
        throw $e;
    }

    return $this->db->fetchOne('SELECT * FROM domain_events WHERE id = ?', [$id]);
}
```

### プロジェクションロジック

```php
private function applyProjection(
    string $aggregateId,
    string $eventType,
    array $payload,
    int $sequence,
    \DateTimeImmutable $now,
): void {
    $ts = $now->format('Y-m-d H:i:s');
    match ($eventType) {
        'AccountOpened' => $this->db->execute(
            'INSERT INTO account_projections (account_id, owner, balance_cents, is_open, last_sequence, updated_at)
             VALUES (?, ?, 0, 1, ?, ?)',
            [$aggregateId, $payload['owner'] ?? '', $sequence, $ts],
        ),
        'MoneyDeposited' => $this->db->execute(
            'UPDATE account_projections SET balance_cents = balance_cents + ?, last_sequence = ?, updated_at = ?
             WHERE account_id = ?',
            [$payload['amount_cents'], $sequence, $ts, $aggregateId],
        ),
        'MoneyWithdrawn' => $this->db->execute(
            'UPDATE account_projections SET balance_cents = balance_cents - ?, last_sequence = ?, updated_at = ?
             WHERE account_id = ?',
            [$payload['amount_cents'], $sequence, $ts, $aggregateId],
        ),
        'AccountClosed' => $this->db->execute(
            'UPDATE account_projections SET is_open = 0, last_sequence = ?, updated_at = ? WHERE account_id = ?',
            [$sequence, $ts, $aggregateId],
        ),
        default => null,
    };
}
```

---

## やってはいけないこと

| アンチパターン | リスク |
|---|---|
| `domain_events` の行を UPDATE または DELETE する | 監査証跡を破壊し、プロジェクションが履歴と不整合になる |
| `UNIQUE(aggregate_id, sequence)` なし | 重複イベントが再生時のプロジェクションを破損する |
| 計算された残高を `domain_events` に保存する | 導出された状態はプロジェクションに属し、イベントログには属さない |
| 任意のイベントタイプを許可する | プロジェクションロジックにハンドラーがない → サイレントな no-op またはクラッシュ |
| イベント追加と同じトランザクションでプロジェクション更新なし | イベントログと読み取りモデル間の不整合ウィンドウ |
| 集約ごとのシーケンスカウンターなし | 再生ギャップや並行書き込み競合を検知できない |
