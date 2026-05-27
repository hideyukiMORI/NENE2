# バックグラウンドジョブキュー（リトライと冪等性）

このガイドでは NENE2 アプリケーションで永続バックグラウンドジョブキューを実装する方法を解説します。パターンは優先度キュー、バックオフカウンタによる自動リトライ、冪等なジョブ作成をサポートします。

## コアコンセプト

ジョブキューは作業を HTTP リクエストサイクルから切り離します。HTTP ハンドラーはジョブをエンキューして即座に返します。別のワーカープロセスがジョブをクレームして実行します。

主要なステータス: `pending` → `running` → `completed` または `failed`（リトライが残っている場合は自動的に再エンキュー）。

## スキーマ設計

```sql
CREATE TABLE IF NOT EXISTS jobs (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    type            TEXT    NOT NULL,
    payload         TEXT    NOT NULL DEFAULT '{}',
    priority        INTEGER NOT NULL DEFAULT 0,
    status          TEXT    NOT NULL DEFAULT 'pending',
    retry_count     INTEGER NOT NULL DEFAULT 0,
    max_retries     INTEGER NOT NULL DEFAULT 3,
    idempotency_key TEXT    UNIQUE,
    claimed_at      TEXT,
    worker_id       TEXT,
    error           TEXT,
    created_at      TEXT    NOT NULL,
    updated_at      TEXT    NOT NULL
);
```

`idempotency_key UNIQUE` はアプリケーションレベルだけでなく、データベースレベルで強制されます。2 つの並行した HTTP リクエストがどちらもアプリケーション層のチェックを通過して両方 INSERT を試みる競合を防止します。

## ジョブライフサイクル

```
POST /jobs                  → pending (retry_count=0)
POST /jobs/claim            → running (worker_id, claimed_at が設定される)
POST /jobs/{id}/complete    → completed
POST /jobs/{id}/fail        → pending (retry_count+1) リトライが残っている場合
                            → failed retry_count >= max_retries の場合
```

## リトライロジック

ワーカーが `fail` を呼ぶと、リポジトリが再エンキューするか永続的に失敗にするかを決定します:

```php
public function fail(int $id, string $error, string $now): ?Job
{
    $job = $this->findById($id);
    if ($job === null || $job->status !== JobStatus::Running) {
        return null;
    }

    if ($job->retryCount < $job->maxRetries) {
        $this->executor->execute(
            "UPDATE jobs SET status = 'pending', retry_count = retry_count + 1,
             error = ?, claimed_at = NULL, worker_id = NULL, updated_at = ? WHERE id = ?",
            [$error, $now, $id],
        );
    } else {
        $this->executor->execute(
            "UPDATE jobs SET status = 'failed', error = ?, updated_at = ? WHERE id = ?",
            [$error, $now, $id],
        );
    }

    return $this->findById($id);
}
```

`error` フィールドは再エンキュー時でも**最新の**失敗理由を保存し、オペレーターにジョブレコードの診断証跡を提供します。

## 冪等性

HTTP クライアントから安全にリトライできるよう、ジョブ作成時に `idempotency_key` を渡してください:

```http
POST /jobs
Content-Type: application/json

{
  "type": "send-invoice",
  "payload": {"invoice_id": 42},
  "idempotency_key": "invoice-42-send-2026-05"
}
```

- 初回呼び出し: `201 Created` — ジョブが作成される。
- 同じキーでの後続呼び出し: `200 OK` — 既存のジョブが返され、重複は作成されない。

データベースの `idempotency_key` の `UNIQUE` 制約がセーフティネットです。例外処理を主要なコードパスとして使うことを避けるため、先にアプリケーション層でチェックしてください:

```php
if ($idempotencyKey !== null) {
    $existing = $this->repo->findByIdempotencyKey($idempotencyKey);
    if ($existing !== null) {
        return $this->json->create($existing->toArray(), 200);
    }
}
$job = $this->repo->create(..., $idempotencyKey, $maxRetries);
return $this->json->create($job->toArray(), 201);
```

## 優先度キュー

ジョブは優先度 DESC、次に created_at ASC（同一ティア内で FIFO）でクレームされます:

```sql
SELECT * FROM jobs
WHERE status = 'pending'
ORDER BY priority DESC, created_at ASC
LIMIT 1
```

優先度レベル（整数値を保存し、人が読めるラベルを公開）:

| ラベル | 値 |
|--------|-----|
| low | 0 |
| medium | 10 |
| high | 20 |
| critical | 30 |

## ワーカーパターン

ワーカーはループするステートレスプロセスです: クレーム → 実行 → 完了または失敗。

```
loop:
  job = POST /jobs/claim { worker_id: "worker-1" }
  if job is null → sleep, continue

  try:
    execute(job.type, job.payload)
    POST /jobs/{job.id}/complete {}
  catch error:
    POST /jobs/{job.id}/fail { error: error.message }
```

ワーカーは `worker_id` で自身を識別するので、オペレーターはどのワーカーがジョブを保持しているかを確認し、停止したワーカーを診断できます。

## 停止ジョブの検出

`running` ステータスで `claimed_at` タイムスタンプが閾値より古いジョブは停止しています（ワーカーがクラッシュ）。メンテナンスプロセスがそれらを検出して再エンキューすべきです:

```sql
UPDATE jobs
SET status = 'pending', retry_count = retry_count + 1,
    claimed_at = NULL, worker_id = NULL, updated_at = ?
WHERE status = 'running'
  AND claimed_at < ?             -- タイムアウト閾値より古い
  AND retry_count < max_retries
```

## リトライ不可ジョブには max_retries=0 を使う

一部のジョブはリトライしてはなりません（例: 決済、リプレイが害を引き起こす外部 Webhook）。作成時に `max_retries: 0` を設定してください:

```json
{ "type": "charge-card", "max_retries": 0, "idempotency_key": "charge-order-99" }
```

最初の `fail` 呼び出しで即座にジョブが `failed` に遷移します。

## 設計上の判断

**なぜリトライロジックをワーカーではなくリポジトリに置くのか？** 再エンキューの判断はデータ層の不変条件（retry_count < max_retries）であり、ビジネスロジックではありません。リポジトリに置くことでワーカーをシンプルに保ち、異なるチェックを実装するワーカーからの不整合を防ぎます。

**なぜ DB レベルで idempotency_key に UNIQUE 制約を置くのか？** アプリケーション層のチェックは並行リクエスト下で競合状態があります。DB 制約が権威あるガードです。アプリケーション層のチェックは例外処理への依存を避けるための最適化です。

**なぜ優先度を整数として保存するのか？** スキーマ変更なしで後から中間の優先度レベルを追加できます。人が読めるラベルは導出されるもので、保存されません。
