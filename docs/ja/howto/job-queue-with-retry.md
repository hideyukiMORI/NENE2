# ハウツー: リトライと冪等性を備えたバックグラウンドジョブキュー

> **FT リファレンス**: FT255 (`NENE2-FT/queuelog`) — リトライと冪等性を備えたバックグラウンドジョブキュー
> **VULN**: FT255 — 脆弱性評価（V-01 〜 V-10）

SQLite をバックエンドとした永続ジョブキューを実証します。ジョブには優先度レベルがあり、`pending → running → completed|failed` というステートマシンを経て遷移し、設定可能なリトライ上限付きの自動リトライをサポートします。冪等性キーにより重複ジョブの作成を防止します。完全な脆弱性評価も含みます。

---

## ルート

| メソッド | パス | 説明 |
|--------|------|-------------|
| `POST` | `/jobs` | ジョブをエンキューする（冪等性キーはオプション） |
| `GET` | `/jobs` | ジョブを一覧表示する（ステータスでフィルタ可能） |
| `GET` | `/jobs/{id}` | 単一ジョブを取得する |
| `POST` | `/jobs/claim` | ワーカーが次の pending ジョブをクレームする |
| `POST` | `/jobs/{id}/complete` | ワーカーがジョブを完了としてマークする |
| `POST` | `/jobs/{id}/fail` | ワーカーがジョブを失敗としてマークする（リトライ付き） |

> **ルート順序**: `/jobs/claim` はリテラルセグメント `claim` がパスパラメーターとしてキャプチャされないよう、`/jobs/{id}` より先に登録する必要があります。

---

## スキーマ

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

`idempotency_key TEXT UNIQUE` は DB レベルで一意性を強制します。`claimed_at`、`worker_id`、`error` は Nullable で、ジョブが `running` または `failed` に入ったときのみ設定されます。

---

## 優先度: SQL 順序付けのための数値 enum

```php
enum JobPriority: int
{
    case Low      = 0;
    case Medium   = 10;
    case High     = 20;
    case Critical = 30;

    public static function fromLabel(string $label): self
    {
        return match (strtolower($label)) {
            'low' => self::Low, 'medium' => self::Medium,
            'high' => self::High, 'critical' => self::Critical,
            default => throw new \InvalidArgumentException("Unknown priority: {$label}"),
        };
    }
}
```

数値は `ORDER BY priority DESC` による直接ソートを可能にします。文字列 enum では `CASE` 式または優先度ルックアップテーブルが必要になります。値の間隔（0, 10, 20, 30）により、将来の優先度レベルを番号を振り直さずに挿入できます。

---

## クレーム: 最高優先度 FIFO

```php
public function claim(string $workerId, string $now): ?Job
{
    $rows = $this->executor->fetchAll(
        "SELECT * FROM jobs WHERE status = 'pending' ORDER BY priority DESC, created_at ASC LIMIT 1",
        [],
    );
    if ($rows === []) {
        return null;
    }

    $id = (int) $rows[0]['id'];
    $this->executor->execute(
        "UPDATE jobs SET status = 'running', claimed_at = ?, worker_id = ?, updated_at = ? WHERE id = ?",
        [$now, $workerId, $now, $id],
    );

    return $this->findById($id);
}
```

`ORDER BY priority DESC, created_at ASC` は最高優先度のジョブを選択し、同一優先度のジョブ間では最も古いもの（FIFO）を選択します。`LIMIT 1` で 1 つのジョブのみが選択されることを保証します。

このクレームは**非アトミック**です（V-06 を参照）。単一ワーカー構成では許容されます。並行ワーカーには、SQLite の `BEGIN IMMEDIATE` + `SELECT … LIMIT 1 FOR UPDATE`（MySQL）、または `status = 'pending' AND id = ?` の条件付き UPDATE と `changes()` チェックを使用してください。

---

## リトライロジック: 再エンキュー vs 失敗

```php
public function fail(int $id, string $error, string $now): ?Job
{
    $job = $this->findById($id);
    if ($job === null || $job->status !== JobStatus::Running) {
        return null;
    }

    if ($job->retryCount < $job->maxRetries) {
        // 再エンキュー: retry_count をインクリメントして pending にリセット
        $this->executor->execute(
            "UPDATE jobs SET status = 'pending', retry_count = retry_count + 1,
             error = ?, claimed_at = NULL, worker_id = NULL, updated_at = ? WHERE id = ?",
            [$error, $now, $id],
        );
    } else {
        // 使い果たした: 永続的な失敗
        $this->executor->execute(
            "UPDATE jobs SET status = 'failed', error = ?, updated_at = ? WHERE id = ?",
            [$error, $now, $id],
        );
    }

    return $this->findById($id);
}
```

`retry_count < max_retries` でジョブにリトライが残っているかチェックします。残っている場合、ジョブは `pending` に戻り（`claimed_at`/`worker_id` をクリア）、次のワーカーが再クレームできます。使い果たした場合、終端状態の `failed` に遷移します。

再エンキュー時に `claimed_at = NULL` と `worker_id = NULL` がクリアされ、次にクレームするワーカーにとって新しい pending ジョブとして見えるようになります。

---

## 冪等性キー: 作成時の重複排除

```php
if ($idempotencyKey !== null) {
    $existing = $this->repo->findByIdempotencyKey($idempotencyKey);
    if ($existing !== null) {
        return $this->json->create($existing->toArray(), 200);
    }
}

$job = $this->repo->create($type, ..., $idempotencyKey, $maxRetries);
return $this->json->create($job->toArray(), 201);
```

同じ `idempotency_key` を持つジョブが既に存在する場合、重複を作成せずに既存のジョブが `200 OK` で返されます。新しいジョブは `201 Created` を返します。`idempotency_key` の `UNIQUE` 制約が競合状態に対する第 2 レベルのガードを提供します。

---

## ステートマシン

```
pending ──(claim)──→ running ──(complete)──→ completed (終端)
                        │
                        └──(fail, リトライあり)──→ pending
                        │
                        └──(fail, リトライ使い果たし)──→ failed (終端)
```

`complete()` と `fail()` はどちらも遷移を適用する前に `status = Running` をチェックします。どちらからの `null` 返却も、ジョブが見つからないか正しい状態でなかったことを示し、コントローラーにより `409 Conflict` にマップされます。

---

## VULN — 脆弱性評価（FT255）

### V-01 — 認証なし: 任意の呼び出し元がジョブのエンキュー、クレーム、完了を実行できる

**リスク**: すべてのエンドポイントが認証なし。

**影響**: 攻撃者は任意のタイプとペイロードで任意のジョブをエンキューし、正規のジョブをクレームして実際のワーカーの処理を妨害し、実際の作業を実行せずにジョブを完了または失敗としてマークできます。

**判定**: ⚠️ EXPOSED — 認証を追加してください。ワーカーエンドポイント（`/jobs/claim`、`/jobs/{id}/complete`、`/jobs/{id}/fail`）はワーカー API キーまたは JWT を要求すべきです。エンキューは認証済みプロデューサーのみに制限すべきです。

---

### V-02 — ジョブタイプは任意の文字列: 許可リストなし

**リスク**: `type` は空でない任意の文字列を受け入れます。攻撃者はシステムが処理しないタイプのジョブをエンキューできます（例: `"DROP TABLE"`、`"shutdown"`、`"admin_task"`）。

**影響**: ワーカーが `type` に基づいてディスパッチする場合（例: `match($job->type) { ... }`）、不明なタイプはサイレントにスキップされるか、予期しないデフォルトハンドラーをトリガーします。

**判定**: ⚠️ EXPOSED — `type` を既知のジョブタイプの許可リストに対してバリデーションしてください。不明なタイプには `422` を返してください。例:

```php
if (!in_array($type, ['email', 'pdf', 'sync'], true)) {
    return $this->problems->create($request, 'validation-failed', '...', 422, ...);
}
```

---

### V-03 — 優先度操作: 攻撃者が `critical` 優先度を設定する

**攻撃**: `"priority": "critical"` でジョブをエンキューして、既存のジョブすべてを追い越す。

```json
{"type": "spam", "payload": {}, "priority": "critical"}
```

**観察結果**: リクエストは `201` で成功します。スパムジョブがキューの先頭に立ち、正規の高優先度ジョブより先にクレームされます。

**判定**: ⚠️ EXPOSED — 高優先度レベルを設定できる権限を制限してください。昇格した信頼のないプロデューサーは `low` または `medium` に制限すべきです。未認証の呼び出し元からの `critical` を拒否してください。

---

### V-04 — ワーカー ID スプーフィング: 任意のワーカー ID でクレームできる

**攻撃**: `"worker_id": "legitimate-worker-1"` でクレームを送信する。

**観察結果**: クレームは成功します — ジョブはスプーフィングされたワーカー ID に割り当てられます。正規のワーカーはこれを自分のクレームと区別できません。

**判定**: ⚠️ EXPOSED — `worker_id` は呼び出し元が提供するのではなく、認証済みアイデンティティ（API キー → ワーカー名）から導出すべきです。呼び出し元提供のワーカー ID を信頼しないでください。

---

### V-05 — ジョブ状態の乗っ取り: 任意の呼び出し元が実行中の任意のジョブを完了/失敗にできる

**攻撃**: 別のワーカーがクレームしたジョブを完了または失敗にする。

```bash
# ワーカー A がジョブ 1 をクレーム; 攻撃者がワーカー A の完了前に完了させる:
POST /jobs/1/complete
```

**観察結果**: `complete()` は `status = Running` のみチェックします。呼び出し元がジョブをクレームしたワーカーであるかを検証する所有権チェックがありません。

**判定**: ⚠️ EXPOSED — `complete()` と `fail()` に `WHERE worker_id = $requestWorkerId` 条件を追加してください。ワーカーがジョブを所有しない場合は `409` を返してください。

---

### V-06 — クレームの競合状態: 非アトミックな SELECT + UPDATE

**リスク**: `claim()` は `SELECT … LIMIT 1` の後に `UPDATE … WHERE id = ?` を実行します。2 つの並行ワーカーが同じジョブをどちらか更新する前に選択できます。

**攻撃**: 2 つのワーカーがジョブ 1 を `pending` として参照し、両方が `running` に更新し、両方がジョブを実行します。2 番目の更新が `worker_id` カラムを上書きしますが、ジョブは 2 回実行されます。

**判定**: ⚠️ EXPOSED — アトミッククレームパターンを使用してください:
```sql
UPDATE jobs SET status='running', worker_id=?, claimed_at=?
WHERE id = (SELECT id FROM jobs WHERE status='pending' ORDER BY priority DESC, created_at ASC LIMIT 1)
  AND status = 'pending'
```
次に `changes() = 1` を確認してください。SQLite では `BEGIN IMMEDIATE` でラップすることで、並行読み取りが同じ pending 行を参照することを防ぎます。

---

### V-07 — ペイロードサイズ: ジョブペイロードの制限なし

**リスク**: `payload` はサイズバリデーションなしで任意の JSON オブジェクトを受け入れます。

**影響**: 数メガバイトのペイロードは、ジョブがワーカーによってフェッチされるかキューで一覧表示されるときにストレージとメモリを消費します。

**判定**: ⚠️ EXPOSED — ペイロードサイズチェックを追加してください（例: `strlen($json) > 65536 → 422`）。外部の制限としてリクエストサイズミドルウェアに依存してください。

---

### V-08 — type または payload 経由の SQL インジェクション

**攻撃**: `type` または `payload` フィールドに SQL メタ文字を埋め込む。

```json
{"type": "'; DROP TABLE jobs; --", "payload": {}}
```

**観察結果**: 値はパラメーター化された `?` プレースホルダーとしてバインドされます。インジェクションはデータベースにリテラルテキストとして保存されます。SQL は実行されません。

**判定**: 🚫 BLOCKED — パラメーター化クエリが SQL インジェクションを防止します。

---

### V-09 — 冪等性キーの衝突: 攻撃者が正規のキーを推測する

**攻撃**: 正規の呼び出し元の冪等性キーを推測または列挙し、異なるペイロードで同じジョブを送信する。

**観察結果**: 既存のジョブは変更されずに返されます。攻撃者のリクエストは新しいジョブを作成しません — `UNIQUE` 制約とアプリケーションレベルのチェックの両方が防止します。攻撃者は返された `200` を通じてジョブが存在することを知りますが、変更はできません。

**判定**: PARTIALLY BLOCKED — 重複作成はブロックされます。ただし、攻撃者は冪等性キーをプローブしてジョブの存在を列挙できます。列挙を実行不可能にするために長いランダムキー（例: UUID v4）を使用してください。一致したキーへのレスポンスはジョブが存在することとそのステータスを漏洩します。

---

### V-10 — 失敗したジョブでのエラーメッセージ漏洩

**リスク**: `POST /jobs/{id}/fail` からのワーカーエラーメッセージは `error` カラムに保存され、すべての一覧/取得レスポンスで返されます。

**影響**: ワーカーから送信された内部エラーメッセージ（スタックトレース、DB 接続文字列、内部ファイルパス）が `GET /jobs` の任意の呼び出し元に見えます。

**判定**: ⚠️ EXPOSED — 保存前にエラーメッセージをサニタイズしてください（機密情報を除去する）。一覧/取得レスポンスでの `error` フィールドの可視性を管理者ロールに制限してください。

---

## VULN サマリー

| # | 脆弱性 | 判定 |
|---|--------|------|
| V-01 | すべてのエンドポイントで認証なし | ⚠️ EXPOSED |
| V-02 | ジョブタイプ: 許可リストなし | ⚠️ EXPOSED |
| V-03 | 優先度操作（critical ジョブ） | ⚠️ EXPOSED |
| V-04 | ワーカー ID スプーフィング | ⚠️ EXPOSED |
| V-05 | ジョブ状態の乗っ取り（所有権チェックなし） | ⚠️ EXPOSED |
| V-06 | クレームの競合状態（非アトミック） | ⚠️ EXPOSED |
| V-07 | ペイロードサイズ: 制限なし | ⚠️ EXPOSED |
| V-08 | type/payload 経由の SQL インジェクション | 🚫 BLOCKED |
| V-09 | 冪等性キーの衝突/列挙 | PARTIALLY BLOCKED |
| V-10 | 一覧でのエラーメッセージ漏洩 | ⚠️ EXPOSED |

**本番前の重要な修正**:
1. **V-01** — プロデューサーとワーカーに認証を追加する（別々の認証レベル）
2. **V-02** — 既知の許可リストに対して `type` をバリデーションする
3. **V-03 / V-04 / V-05** — 認証済みセッションからワーカーアイデンティティを導出する; `worker_id` 所有権チェックを追加する
4. **V-06** — アトミッククレームを使用する（`UPDATE … WHERE … AND status='pending'` + `changes() = 1`）
5. **V-10** — 保存前にワーカーエラーメッセージをサニタイズする; 可視性を制限する

---

## 関連 howto

- [`notification-queue.md`](notification-queue.md) — 通知キュー API（notiflog FT214）
- [`idempotency.md`](idempotency.md) — POST リクエストの冪等性キーパターン
- [`dead-letter-queue.md`](dead-letter-queue.md) — リトライ付きデッドレターキュー（deadletterlog FT72）
- [`transactions.md`](transactions.md) — トランザクションでのキュー操作のラッピング
