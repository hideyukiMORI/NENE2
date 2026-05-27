# ハウツー: デッドレターキュー（DLQ）

> **FT リファレンス**: FT72 (`NENE2-FT/deadletterlog`) — デッドレターキュー API

指数バックオフリトライとデッドレターキューを持つ信頼性の高いメッセージキューを実演します。失敗したメッセージは遅延を増加させながら自動的に再スケジュールされ、すべてのリトライを使い切った後は検査と再実行が可能な `dead` 状態に移行します。パスパラメーター経由で複数の名前付きキューをサポートします。

---

## メッセージライフサイクル

```
enqueue ──▶ pending ──claim──▶ processing
                                    │
                        ┌──succeed──┤──fail（リトライ残あり）──▶ pending (retry_after)
                        │           │
                        ▼           └──fail（使い切り）──▶ dead ──replay──▶ pending
                    succeeded
```

| ステータス | 説明 |
|--------|-------------|
| `pending` | クレーム可能な状態（または `retry_after` まで待機中） |
| `processing` | ワーカーによってクレーム済み、処理中 |
| `succeeded` | 正常に完了 |
| `dead` | すべてのリトライを使い切り — デッドレターキュー内 |

---

## ルート

| メソッド | パス | 説明 |
|--------|-----------------------------------------------|--------------------------------------|
| `POST` | `/queues/{queue}/messages`                    | メッセージをエンキューする |
| `GET`  | `/queues/{queue}/messages`                    | キュー内のメッセージを一覧表示する |
| `GET`  | `/queues/{queue}/messages/{id}`               | 単一メッセージを取得する |
| `POST` | `/queues/{queue}/claim`                       | 次の pending メッセージをクレームする |
| `POST` | `/queues/{queue}/messages/{id}/succeed`       | succeeded としてマークする |
| `POST` | `/queues/{queue}/messages/{id}/fail`          | failed としてマークする（リトライまたは DLQ） |
| `POST` | `/queues/{queue}/messages/{id}/replay`        | dead メッセージを再実行する |

---

## メッセージのエンキュー

```php
// POST /queues/emails/messages
$body = [
    'payload'     => '{"to":"alice@example.com","subject":"Welcome"}',  // 必須文字列
    'max_retries' => 5,  // オプション、デフォルト 3、範囲 1〜10
];
```

`max_retries` は 1 から 10 の間でバリデーションされます:

```php
$maxRetries = isset($body['max_retries']) && is_int($body['max_retries']) ? $body['max_retries'] : 3;

if ($maxRetries < 1 || $maxRetries > 10) {
    return $this->problems->create($request, 'validation-failed', 'Validation Failed', 422, null, [
        'errors' => [['field' => 'max_retries', 'code' => 'invalid', 'message' => 'max_retries must be between 1 and 10.']],
    ]);
}
```

---

## 次の pending メッセージのクレーム

ワーカーは `POST /queues/{queue}/claim` を呼び出してメッセージをアトミックにデキューします:

```php
public function claim(string $queue, string $now): ?Message
{
    $rows = $this->executor->fetchAll(
        "SELECT * FROM messages
         WHERE queue = ? AND status = 'pending'
           AND (retry_after IS NULL OR retry_after <= ?)
         ORDER BY created_at ASC LIMIT 1",
        [$queue, $now],
    );

    if ($rows === []) {
        return null;  // 利用可能なメッセージなし
    }

    $id = (int) $rows[0]['id'];
    $this->executor->execute(
        "UPDATE messages SET status = 'processing', updated_at = ? WHERE id = ?",
        [$now, $id],
    );

    return $this->findById($id);
}
```

`retry_after <= now` はリトライ待機中のメッセージをフィルタリングします。メッセージは FIFO 順（`ORDER BY created_at ASC`）でクレームされます。

> **アトミック性の注意**: トランザクションなしでは、2 つの並行ワーカーが UPDATE が実行される前に同じ行を読み取ると、同じメッセージをクレームできます。真のアトミッククレームのために、`SELECT ... FOR UPDATE`（MySQL/PostgreSQL）を使ったトランザクションでラップするか、`UPDATE ... WHERE status = 'pending' RETURNING id` を使用してください。

---

## 指数バックオフによる失敗処理

ワーカーが失敗を報告（`POST .../fail`）すると、リポジトリはリトライをスケジュールするかメッセージをデッドレターキューに昇格させます:

```php
public function fail(int $id, string $error, string $now): ?Message
{
    $msg = $this->findById($id);
    if ($msg === null || $msg->status !== MessageStatus::Processing) {
        return null;
    }

    $newRetryCount = $msg->retryCount + 1;

    if ($newRetryCount >= $msg->maxRetries) {
        // 使い切り — DLQ に移動
        $this->executor->execute(
            "UPDATE messages SET status = 'dead', retry_count = ?, last_error = ?, updated_at = ? WHERE id = ?",
            [$newRetryCount, $error, $now, $id],
        );
    } else {
        // 指数バックオフでリトライをスケジュール
        $backoffSeconds = min(2 ** $newRetryCount, 3600);
        $retryAfter     = (new \DateTimeImmutable($now))
            ->modify("+{$backoffSeconds} seconds")
            ->format('Y-m-d H:i:s');

        $this->executor->execute(
            "UPDATE messages SET status = 'pending', retry_count = ?, last_error = ?,
             retry_after = ?, updated_at = ? WHERE id = ?",
            [$newRetryCount, $error, $retryAfter, $now, $id],
        );
    }

    return $this->findById($id);
}
```

### バックオフスケジュール（max_retries = 5）

| 試行 | バックオフ秒数 | 計算式 |
|---------|-----------------|---------|
| 1 回目の失敗 | 2 秒 | 2^1 |
| 2 回目の失敗 | 4 秒 | 2^2 |
| 3 回目の失敗 | 8 秒 | 2^3 |
| 4 回目の失敗 | 16 秒 | 2^4 |
| 5 回目の失敗 | → dead | リトライ使い切り |

`min(2 ** $newRetryCount, 3600)` は最大バックオフを 1 時間にキャップします。大きなリトライ数でも複数日の遅延を防ぎながら、サービスが回復する時間を確保します。

---

## dead メッセージの再実行

dead メッセージはリトライ状態をクリアして `pending` にリセットすることで再実行できます:

```php
public function replay(int $id, string $now): ?Message
{
    $msg = $this->findById($id);
    if ($msg === null || $msg->status !== MessageStatus::Dead) {
        return null;  // 409 Conflict
    }

    $this->executor->execute(
        "UPDATE messages SET status = 'pending', retry_count = 0,
         last_error = NULL, retry_after = NULL, updated_at = ? WHERE id = ?",
        [$now, $id],
    );

    return $this->findById($id);
}
```

`retry_count` は 0 にリセットされるため、メッセージは再び `max_retries` の全バジェットを取得します。元の `max_retries` 値は保持されます。

> **ベストプラクティス**: 再実行する前に失敗の根本原因を修正してください。壊れたシステムへの再実行は DLQ を再び埋めるだけです。

---

## 複数の名前付きキュー

`{queue}` パスパラメーターは名前でメッセージをルーティングします。空でない任意の文字列が有効です:

```
POST /queues/emails/messages
POST /queues/notifications/messages
POST /queues/webhooks/messages
```

すべてのクエリが `queue = ?` でフィルタリングされるため、各キューは分離されています。キューの登録ステップは不要です — キューは最初のエンキュー時に暗黙的に作成されます。

---

## スキーマ

```sql
CREATE TABLE messages (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    queue       TEXT    NOT NULL DEFAULT 'default',
    payload     TEXT    NOT NULL,
    status      TEXT    NOT NULL DEFAULT 'pending',
    retry_count INTEGER NOT NULL DEFAULT 0,
    max_retries INTEGER NOT NULL DEFAULT 3,
    retry_after TEXT,           -- リトライがスケジュールされていない場合は NULL
    last_error  TEXT,           -- 最初の失敗まで NULL
    created_at  TEXT    NOT NULL,
    updated_at  TEXT    NOT NULL
);
```

主要な設計上の選択:
- `payload` は不透明な文字列です — キューはメッセージ内容を検査またはバリデーションしません。
- `last_error` はデバッグ用に最新の失敗メッセージを保存します。
- `retry_after` は新しいメッセージでは `NULL` で、再実行時にクリアされるため、`retry_after <= now` が特殊ケースなしに機能します。

---

## ワーカーパターン

ワーカーは 1 度に 1 つのメッセージをポーリングして処理します:

```php
// ワーカーループ（擬似コード）
while (true) {
    $msg = claim('/queues/emails/messages');
    if ($msg === null) {
        sleep(5);  // メッセージなし、バックオフ
        continue;
    }

    try {
        sendEmail(json_decode($msg->payload));
        succeed($msg->id);
    } catch (Exception $e) {
        fail($msg->id, $e->getMessage());
    }
}
```

クレームから succeed/fail のサイクルを短く保ってください。タイムアウトなしの長時間処理はワーカーがクラッシュした場合にメッセージを永遠に `processing` 状態に残します。タイムアウトしたメッセージを回収するための `processing_timeout` カラムとリーパージョブを追加してください。

---

## 関連ハウツー

- [`job-queue.md`](job-queue.md) — DLQ なしの基本的なジョブキュー
- [`notification-queue.md`](notification-queue.md) — 通知キューパターン
- [`idempotency.md`](idempotency.md) — at-least-once 配信の冪等処理
- [`webhook-delivery.md`](webhook-delivery.md) — webhook リトライパターン
