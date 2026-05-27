# ハウツー: クォータ管理 API

> **FT リファレンス**: FT236 (`NENE2-FT/quotalog`) — クォータ管理 API
> **ATK**: FT236 — クラッカー視点の攻撃テスト（ATK-01〜ATK-12）

各ユーザー/リソースペアに設定可能なレートポリシー（時間単位または日単位）があり、使用量がウィンドウ開始時刻をキーとする別テーブルで追跡され、超過時に `consume` エンドポイントが `429 Too Many Requests` で制限を強制するクォータ管理 API を実演します。`check`（読み取り専用）と `consume`（変更）は独立した操作です。

---

## ルート

| メソッド | パス | 説明 |
|--------|------------------------------------------|----------------------------------------------------|
| `PUT` | `/quotas/{userId}/{resource}` | クォータポリシーを作成または更新する |
| `GET` | `/quotas/{userId}` | ユーザーのすべてのクォータポリシーを一覧表示する |
| `GET` | `/quotas/{userId}/{resource}` | 現在のクォータ状態を確認する（読み取り専用） |
| `POST` | `/quotas/{userId}/{resource}/consume` | 1 ユニットを消費する（超過時は 429 を返す） |
| `POST` | `/quotas/{userId}/{resource}/reset` | 現在のウィンドウの使用量をゼロにリセットする |

---

## QuotaWindow: ウィンドウ開始時刻の計算

`QuotaWindow` は現在のタイムスタンプをウィンドウ境界に切り下げる `windowStart()` メソッドを持つバックドエナムです:

```php
enum QuotaWindow: string
{
    case Hourly = 'hourly';
    case Daily  = 'daily';

    public function windowStart(string $now): string
    {
        $dt = new \DateTimeImmutable($now, new \DateTimeZone('UTC'));

        return match ($this) {
            self::Hourly => $dt->setTime((int) $dt->format('H'), 0, 0)->format('Y-m-d H:i:s'),
            self::Daily  => $dt->setTime(0, 0, 0)->format('Y-m-d H:i:s'),
        };
    }
}
```

`setTime(H, 0, 0)` は現在の時間に切り下げ; `setTime(0, 0, 0)` は UTC 深夜に切り下げます。結果は使用量テーブルの `window_start` キーとして保存されます — 同じウィンドウ内のすべてのリクエストが同じ `window_start` 値を共有します。

---

## 2 テーブル設計: ポリシーと使用量

```sql
-- クォータポリシー: ウィンドウあたりの最大許可数
CREATE TABLE IF NOT EXISTS quota_policies (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id     TEXT    NOT NULL,
    resource    TEXT    NOT NULL,
    window      TEXT    NOT NULL DEFAULT 'hourly',
    limit_count INTEGER NOT NULL,
    created_at  TEXT    NOT NULL,
    updated_at  TEXT    NOT NULL,
    UNIQUE(user_id, resource)
);

-- 使用量追跡: ウィンドウあたりの実際のカウント
CREATE TABLE IF NOT EXISTS quota_usage (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id      TEXT    NOT NULL,
    resource     TEXT    NOT NULL,
    window_start TEXT    NOT NULL,
    usage        INTEGER NOT NULL DEFAULT 0,
    created_at   TEXT    NOT NULL,
    updated_at   TEXT    NOT NULL,
    UNIQUE(user_id, resource, window_start)
);
```

ポリシーと使用量を分離することで:
- ポリシーはウィンドウをまたいで持続します — 各期間に再作成する必要はありません。
- 使用量行は `window_start` で自動的にパーティション分割されます。古いウィンドウはテーブルに蓄積されます; バックグラウンドジョブで刈り込めます。
- ポリシーの `UNIQUE(user_id, resource)` は重複した設定を防ぎます。
- 使用量の `UNIQUE(user_id, resource, window_start)` はウィンドウごとに 1 つのカウンターを保証します。

---

## check と consume

`check` は読み取り専用です — 変更なしで残量を計算します:

```php
public function check(string $userId, string $resource, string $now): ?QuotaStatus
{
    $policy      = $this->findPolicy($userId, $resource);
    $windowStart = $policy->window->windowStart($now);
    $usage       = $this->getUsage($userId, $resource, $windowStart);
    $remaining   = max(0, $policy->limitCount - $usage);

    return new QuotaStatus(..., remaining: $remaining, allowed: $remaining > 0);
}
```

`consume` はまず制限を確認し、許可された場合のみインクリメントします:

```php
public function consume(string $userId, string $resource, string $now): ?QuotaStatus
{
    $policy      = $this->findPolicy($userId, $resource);
    $windowStart = $policy->window->windowStart($now);
    $usage       = $this->getUsage($userId, $resource, $windowStart);

    if ($usage >= $policy->limitCount) {
        // クォータ超過 — allowed=false でステータスを返し、インクリメントしない
        return new QuotaStatus(..., remaining: 0, allowed: false);
    }

    $this->incrementUsage($userId, $resource, $windowStart, $now);
    $newUsage  = $usage + 1;
    $remaining = max(0, $policy->limitCount - $newUsage);

    return new QuotaStatus(..., remaining: $remaining, allowed: true);
}
```

コントローラーは `allowed=false` を `429 Too Many Requests` にマップします:

```php
$httpStatus = $status->allowed ? 200 : 429;
return $this->json->create($status->toArray(), $httpStatus);
```

`429` はクォータ超過に意味的に正しいです。本番では `Retry-After` ヘッダーをウィンドウのリセット時刻に設定してください。

---

## 使用量インクリメント: SELECT-then-INSERT/UPDATE

使用量のインクリメントはアプリケーションレベルの upsert です:

```php
private function incrementUsage(string $userId, string $resource, string $windowStart, string $now): void
{
    $existing = $this->executor->fetchAll(
        'SELECT id FROM quota_usage WHERE user_id = ? AND resource = ? AND window_start = ?',
        [$userId, $resource, $windowStart],
    );

    if ($existing !== []) {
        $this->executor->execute(
            'UPDATE quota_usage SET usage = usage + 1, updated_at = ? WHERE user_id = ? AND resource = ? AND window_start = ?',
            [$now, $userId, $resource, $windowStart],
        );
    } else {
        $this->executor->execute(
            'INSERT INTO quota_usage (user_id, resource, window_start, usage, created_at, updated_at) VALUES (?, ?, ?, 1, ?, ?)',
            [$userId, $resource, $windowStart, $now, $now],
        );
    }
}
```

`usage = usage + 1` は原子的な DB レベルのインクリメントです — アプリケーションコードでの read-modify-write はありません。`(user_id, resource, window_start)` の `UNIQUE` 制約が 2 つの並行した初回使用インサートのレースコンディションを防ぎます。

---

## `PUT` によるポリシー upsert

`PUT /quotas/{userId}/{resource}` は冪等です — 作成または更新を行います:

```php
$window     = QuotaWindow::tryFrom($windowRaw);
$limitCount = isset($body['limit_count']) && is_int($body['limit_count']) ? $body['limit_count'] : -1;

$errors = [];
if ($window === null) {
    $errors[] = ['field' => 'window', 'code' => 'invalid', 'message' => 'window must be one of: hourly, daily.'];
}
if ($limitCount < 1) {
    $errors[] = ['field' => 'limit_count', 'code' => 'invalid', 'message' => 'limit_count must be a positive integer.'];
}
```

`is_int()` の厳密チェックは JSON の浮動小数点数と文字列を拒否します。`limitCount < 1` は少なくとも 1 を要求します — ゼロと負の値は拒否されます。

---

## ATK — クラッカー視点の攻撃テスト（FT236）

### ATK-01 — 認証なし

**Attack**: 認証情報なしで任意のユーザーのクォータポリシーを作成または代理消費する。

```bash
curl -s -X PUT http://localhost:8080/quotas/user-123/api-calls \
  -H 'Content-Type: application/json' \
  -d '{"window":"daily","limit_count":10}'
```

**Observed**: `200 OK` — トークン不要。誰でも任意のユーザーのクォータを設定または枯渇させられます。

**Verdict**: **EXPOSED**（FT236 デモでは設計上）。認証を追加し、ポリシー管理を管理者ロールに、消費をオーナーユーザーのトークンに制限してください。

---

### ATK-02 — `{resource}` パスパラメーターへの SQL インジェクション

**Attack**: リソース名に SQL メタ文字を埋め込む。

```
PUT /quotas/user-1/api'; DROP TABLE quota_policies; --
POST /quotas/user-1/" OR "1"="1/consume
```

**Observed**: リソース文字列はすべてのクエリでパラメーター化された `?` 値として直接渡されます — 文字列補間はありません。インジェクションされた SQL はリテラル文字列として保存/比較され、実行されません。

**Verdict**: **BLOCKED** — パラメーター化クエリがパスパラメーター経由のインジェクションを防ぎます。

---

### ATK-03 — 負またはゼロの `limit_count`

**Attack**: 別のユーザーのアクセスを無効にするために 0 または -1 の制限を設定する。

```json
{"window": "daily", "limit_count": 0}
{"window": "daily", "limit_count": -999}
```

**Observed**: `$limitCount < 1` チェックが発動 → `limit_count` の構造化エラーで `422 Unprocessable Entity`。

**Verdict**: **BLOCKED** — アプリケーション層で最小 `limit_count` 1 が強制されます。

---

### ATK-04 — 無効な `window` 値

**Attack**: サポートされていないウィンドウ文字列を送信する。

```json
{"window": "weekly", "limit_count": 100}
{"window": "minutely", "limit_count": 100}
```

**Observed**: `QuotaWindow::tryFrom('weekly')` が `null` を返す → `window` の構造化エラーで `422`。

**Verdict**: **BLOCKED** — バックドエナムの `tryFrom()` が不明なウィンドウ値を拒否します。

---

### ATK-05 — ポリシーなしでの消費

**Attack**: ポリシーが設定されていないユーザー/リソースに `POST .../consume` を呼び出す。

```bash
curl -s -X POST http://localhost:8080/quotas/user-ghost/api-calls/consume
```

**Observed**: `findPolicy()` が `null` を返す → Problem Details レスポンスで `404 Not Found`。

**Verdict**: **BLOCKED** — ポリシーなし → 消費不可。呼び出し元は消費前にポリシーを設定する必要があります。

---

### ATK-06 — 浮動小数点の `limit_count`

**Attack**: 整数の代わりに float を送信する。

```json
{"window": "daily", "limit_count": 9.9}
```

**Observed**: PHP では `is_int(9.9)` = `false` — JSON からデコードされた float 値（`float` 型）がチェックに失敗します。`$limitCount` がデフォルトの `-1` になる → `< 1` ガードが発動 → `422`。

**Verdict**: **BLOCKED** — `is_int()` の厳密型チェックが JSON の浮動小数点数を拒否します。

---

### ATK-07 — 極端に大きな `limit_count`

**Attack**: `PHP_INT_MAX` または `9999999999` の limit_count を設定する。

```json
{"window": "daily", "limit_count": 9223372036854775807}
```

**Observed**: `is_int()` は通過します（PHP はこれを `int` として表現）; `< 1` チェックも通過します。値は問題なく保存されて比較に使用されます。上限は存在しません。

**Verdict**: **EXPOSED** — 最大 `limit_count` が強制されていません。非常に大きな制限は実質的に「制限なし」と同じです。追加してください:
```php
if ($limitCount > 1_000_000) {
    $errors[] = ['field' => 'limit_count', 'code' => 'too_large', 'message' => 'limit_count must not exceed 1 000 000.'];
}
```

---

### ATK-08 — 制限到達時の並行消費のレースコンディション

**Attack**: `usage == limit - 1` の時に 2 つの同時 `POST .../consume` リクエストを送信する。

**Observed**: 両方のリクエストがインクリメント実行前に `usage = limit - 1` を読み取ります。両方が `usage < limitCount` を確認 → 両方が `incrementUsage()` を呼び出します。両方が成功 — 使用量は `limit + 1` で終わり、両方のレスポンスが `allowed: true` を返します。

**Verdict**: **EXPOSED** — チェック→インクリメントパターンは原子的ではありません。トランザクションで修正してください:
```sql
BEGIN;
SELECT usage FROM quota_usage WHERE ... FOR UPDATE;
-- < limit を確認
UPDATE quota_usage SET usage = usage + 1 WHERE ...;
COMMIT;
```
または PostgreSQL で `UPDATE ... SET usage = CASE WHEN usage < ? THEN usage + 1 ELSE usage END RETURNING usage` を使ってください。

---

### ATK-09 — 不明または任意の `{resource}` 名

**Attack**: 意図されていなかったリソース名を使用する。

```
PUT /quotas/user-1/../../../../etc/passwd
PUT /quotas/user-1/system::admin
POST /quotas/user-1/; DROP TABLE quota_usage;--/consume
```

**Observed**: パストラバーサル（`../`）はルーティング前に URL デコードされ、ルーターは複数セグメントのパスとして見なして `{resource}` ルートにマッチしません。特殊文字はパラメーター化クエリ経由でリテラル文字列として保存されます（ATK-02 参照）。

**Verdict**: 事実上 **BLOCKED** — ルーターがパストラバーサルを拒否し、SQL は安全です。リソース名が既知の値に制限すべき場合はリソース名の許可リストまたはフォーマットチェックの追加を検討してください。

---

### ATK-10 — 別ユーザーのクォータリセット

**Attack**: 別のユーザーのクォータカウンターをリセットしてスロットリングを回避する。

```bash
curl -s -X POST http://localhost:8080/quotas/target-user/api-calls/reset
```

**Observed**: `200 OK` — 所有権チェックなし。任意の呼び出し元が任意のユーザーのクォータ使用量をリセットし、即座にアクセスを再有効化できます。

**Verdict**: **EXPOSED** — ATK-01 と同じ根本原因。`reset` を管理者ロールに制限してください。

---

### ATK-11 — `{userId}` と `{resource}` の長さ制限なし

**Attack**: 非常に長いパスセグメント値を送信する。

```
PUT /quotas/<10000 文字>/<5000 文字>
```

**Observed**: 長い文字列は制限なしに `TEXT` カラムに受け入れられて保存されます。非常に長いキーでのインデックスパフォーマンスが低下します。

**Verdict**: **EXPOSED** — 長さガードを追加してください:
```php
if (strlen($userId) > 255 || strlen($resource) > 255) {
    return $this->problems->create($request, 'validation-failed', 'Validation Failed', 422, ...);
}
```

---

### ATK-12 — クロックドリフトによる `window_start` 操作

**Attack**: 呼び出し元が `$now` に影響を与えられる場合、ウィンドウ開始時刻をずらして人工的にウィンドウを延長または再開できます。

**Observed**: `$now` はコントローラー内で `new \DateTimeImmutable()` を通じて計算されます — ユーザーが指定したものではありません。呼び出し元はウィンドウ計算に影響を与えられません。

**Verdict**: **BLOCKED** — サーバークロックが唯一の時刻ソースです。複数ノードの分散システムでは、すべてのノードが UTC を使用して NTP 同期されていることを確認してください。

---

## ATK サマリー

| # | 攻撃ベクター | 判定 |
|---|---------------|---------|
| ATK-01 | 認証なし | EXPOSED |
| ATK-02 | リソースパスパラメーターへの SQL インジェクション | BLOCKED |
| ATK-03 | 負/ゼロの limit_count | BLOCKED |
| ATK-04 | 無効な window 値 | BLOCKED |
| ATK-05 | ポリシーなしでの消費 | BLOCKED |
| ATK-06 | 浮動小数点の limit_count | BLOCKED |
| ATK-07 | 極端に大きな limit_count | EXPOSED |
| ATK-08 | 並行消費のレースコンディション | EXPOSED |
| ATK-09 | 任意のリソース名 | BLOCKED |
| ATK-10 | 別ユーザーのクォータリセット | EXPOSED |
| ATK-11 | userId/resource の長さ制限なし | EXPOSED |
| ATK-12 | ウィンドウ開始時刻の操作 | BLOCKED |

**本番前に修正すべき実際の脆弱性**:
1. **ATK-01 / ATK-10** — 認証と認可を追加する
2. **ATK-08** — 消費をトランザクションでラップする（原子的なチェック→インクリメント）
3. **ATK-07** — `limit_count` に上限を追加する
4. **ATK-11** — パスパラメーター値に長さ制限を追加する

---

## 関連ハウツー

- [`rate-limiting.md`](rate-limiting.md) — ミドルウェアレベルのレート制限
- [`sliding-window-rate-limiter.md`](sliding-window-rate-limiter.md) — スライディングウィンドウカウンター
- [`api-usage-metering.md`](api-usage-metering.md) — API キーごとの使用量追跡
- [`credit-ledger.md`](credit-ledger.md) — クォータ類似システム向けのクレジット/デビットモデル
