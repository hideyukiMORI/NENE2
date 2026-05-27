# HOWTO: 監査証跡 — 誰が何を変更したかを記録する

> **FT リファレンス**: FT268 (`NENE2-FT/auditlog`) — 追記専用の監査証跡: JWT アクター抽出、変更前後のペイロードスナップショット、不変の監査テーブル、未認証の監査読み取りギャップ
>
> **ATK アセスメント**: ATK-01 から ATK-12 がこのドキュメントの末尾に含まれています。

このガイドでは、NENE2 アプリケーションで追記専用の監査証跡を実装する方法を説明します。監査証跡はすべての作成、更新、削除操作を、アクター（JWT クレームから）、リソース、ペイロードスナップショットとともに記録します。これらのレコードは不変です: API は監査テーブルの UPDATE または DELETE エンドポイントを公開しません。

---

## データベーススキーマ

```sql
-- actor_id または resource_id への FK なし:
-- 監査レコードは記述する対象の削除後も生き続ける必要がある。
CREATE TABLE IF NOT EXISTS audit_log (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    actor_id      INTEGER NOT NULL,
    action        TEXT    NOT NULL,   -- 'created' | 'updated' | 'deleted'
    resource_type TEXT    NOT NULL,   -- 例: 'task', 'order', 'user'
    resource_id   INTEGER NOT NULL,
    occurred_at   TEXT    NOT NULL,
    payload       TEXT    NOT NULL DEFAULT '{}'
);

-- 最も一般的なクエリパターン用のインデックスを追加する
CREATE INDEX idx_audit_log_actor_id ON audit_log(actor_id);
CREATE INDEX idx_audit_log_resource ON audit_log(resource_type, resource_id);
```

主要な設計上の選択:
- **FK 制約なし** — 監査レコードはその対象より長く生きます。タスクが削除されても、その監査履歴は残る必要があります。
- **設計上の不変性** — このテーブルに UPDATE または DELETE の SQL パスを追加しないでください。
- **`action` を型付き動詞として** — ログエントリを自己記述的にするために過去形の動詞（`created`、`updated`、`deleted`）を使用します。

---

## AuditEntry DTO と AuditRepository

```php
final readonly class AuditEntry
{
    public function __construct(
        public int    $id,
        public int    $actorId,
        public string $action,
        public string $resourceType,
        public int    $resourceId,
        public string $occurredAt,
        public string $payload,
    ) {}
}
```

```php
final readonly class AuditRepository
{
    public function __construct(
        private DatabaseQueryExecutorInterface $executor,
    ) {}

    /** @param array<string, mixed> $payload */
    public function record(
        int    $actorId,
        string $action,
        string $resourceType,
        int    $resourceId,
        array  $payload,
    ): AuditEntry {
        $now         = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $payloadJson = json_encode($payload, JSON_THROW_ON_ERROR);

        $this->executor->execute(
            'INSERT INTO audit_log (actor_id, action, resource_type, resource_id, occurred_at, payload)
             VALUES (?, ?, ?, ?, ?, ?)',
            [$actorId, $action, $resourceType, $resourceId, $now, $payloadJson],
        );

        return $this->findById((int) $this->executor->lastInsertId())
            ?? throw new \RuntimeException('Failed to record audit entry.');
    }

    /** @return list<AuditEntry> */
    public function findByResource(string $resourceType, int $resourceId, int $limit = 50): array
    {
        $rows = $this->executor->fetchAll(
            // ORDER BY id DESC であって occurred_at DESC ではない: 秒精度のタイムスタンプは
            // 同じ秒に 2 つの操作が行われると衝突する。
            'SELECT * FROM audit_log
             WHERE resource_type = ? AND resource_id = ?
             ORDER BY id DESC LIMIT ?',
            [$resourceType, $resourceId, $limit],
        );
        return array_map(fn (array $row) => $this->hydrate($row), $rows);
    }
}
```

> **`ORDER BY id DESC` であって `occurred_at DESC` ではない:** `occurred_at` は秒精度です。同じ秒に 2 つの操作があると同一のタイムスタンプになり、ソート順が予測不能になります。オートインクリメントの `id` は挿入順を確実に保持します。

---

## ハンドラーで監査を記録する

監査イベントはリポジトリではなく、ハンドラー（UseCase 相当）で記録してください。リポジトリで記録すると、ビジネスコンテキスト（「どの操作がこれをトリガーしたか？」）が失われます。

### 作成 — 初期スナップショットを記録する

```php
$task = $this->tasks->create($title, $body, $actorId);

// 監査: actor_id はペイロードに含めない — 監査レコード自体にすでに含まれている。
$this->audit->record($actorId, 'created', 'task', $task->id, [
    'title'  => $task->title,
    'body'   => $task->body,
    'status' => $task->status,
]);
```

### 更新 — 差分可視性のために変更前後を記録する

```php
$before = $this->tasks->findById($id);
// ... 所有権チェック、バリデーション ...
$after  = $this->tasks->update($id, $title, $body, $status);

$this->audit->record($actorId, 'updated', 'task', $id, [
    'before' => ['title' => $before->title, 'body' => $before->body, 'status' => $before->status],
    'after'  => ['title' => $after->title,  'body' => $after->body,  'status' => $after->status],
]);
```

### 削除 — 削除前にスナップショットを撮る

```php
$task = $this->tasks->findById($id);
// ... 所有権チェック ...
$this->tasks->delete($id);

// 削除後に記録 — タスク行は消えているが、監査は生き続ける。
$this->audit->record($actorId, 'deleted', 'task', $id, [
    'title'  => $task->title,
    'status' => $task->status,
]);
```

---

## JWT クレームからのアクター

アクターは常に検証済みの JWT から導出し、リクエストボディからは導出しないでください。

```php
private function actorId(ServerRequestInterface $request): ?int
{
    /** @var array<string, mixed>|null $claims */
    $claims = $request->getAttribute('nene2.auth.claims');

    if (!is_array($claims) || !isset($claims['sub']) || !is_int($claims['sub'])) {
        return null;
    }

    return $claims['sub'];
}
```

`nene2.auth.claims` はトークン検証後に `BearerTokenMiddleware` によって設定されます。クライアントはリクエストボディに偽の `actor_id` を提供して記録させることはできません。

---

## センシティブフィールドの除外

**ペイロードにパスワード、トークン、内部 ID を絶対に入れないでください。**

```php
// ❌ センシティブデータが漏洩し冗長
$this->audit->record($actorId, 'created', 'user', $user->id, [
    'email'         => $user->email,
    'password_hash' => $user->passwordHash,  // 絶対に含めない
    'actor_id'      => $actorId,              // 冗長
]);

// ✅ ビジネス上見える属性のみ
$this->audit->record($actorId, 'created', 'user', $user->id, [
    'email' => $user->email,
    'role'  => $user->role,
]);
```

---

## 不変の監査 API — 書き込みエンドポイントなし

```php
public function register(Router $router): void
{
    $router->get('/audit', $this->list(...));
    $router->get('/audit/{resource_type}/{resource_id}', $this->byResource(...));
    // POST、PUT、DELETE は意図的に省略
}
```

---

## すべての書き込み前（および監査前）の所有権チェック

```php
$task = $this->tasks->findById($id);
if ($task === null) {
    return $this->problems->create($request, 'not-found', 'Task not found.', 404);
}

// 未認可のアクターにリソースの存在を確認しないよう 403 の代わりに 404 を返す。
if ($task->actorId !== $actorId) {
    return $this->problems->create($request, 'not-found', 'Task not found.', 404);
}

// ここで初めて: 変更 + 監査
```

---

## 監査ログのクエリ

```php
// 特定リソースの履歴
GET /audit/task/42

// アクターによるすべてのイベント
GET /audit?actor_id=7

// リソース種別横断のすべての削除
GET /audit?action=deleted

// 安全にページネーション
GET /audit?limit=20&offset=40
```

---

## セキュリティ考慮事項

| リスク | 緩和策 |
|---|---|
| 監査ログの削除 | DELETE エンドポイントなし。テーブルレベル: 可能であればアプリ DB ユーザーの DELETE 権限を拒否する |
| アクターのなりすまし | アクターは常に `nene2.auth.claims` から、リクエストボディからは絶対に取得しない |
| センシティブなペイロード | パスワード、トークン、内部キーをペイロードから明示的に除外する |
| IDOR（クロスユーザー監査読み取り） | `GET /audit` を管理者ロールに制限する（RBAC と組み合わせる）；またはリクエスト者の actor_id でフィルタリングする |
| タイミング攻撃 / ユーザー列挙 | 不正な文字列ではなく、事前計算された実際の Argon2id ハッシュをダミーとして使用する |
| `LIMIT -1` DoS | クランプ: `max(1, min((int) $limit, 100))` |

---

## ダミーハッシュは実際の Argon2id ハッシュでなければならない

不正なダミーハッシュは `password_verify()` を即座に（KDF を実行せずに）`false` を返させ、約 20,000 倍のタイミング差を生み出し、攻撃者が有効なメールアドレスを列挙できるようになります。

```php
// ❌ 不正 — KDF がスキップされ、~0.001ms で false を返す
$dummyHash = '$argon2id$v=19$m=65536,t=4,p=1$dummysaltdummysaltdummysalt$dummyhashvaluedummyhashvaluedummyh';

// ✅ 実際の事前計算済みハッシュ — KDF がフルコスト（~180ms）で実行される
// 一度だけ生成する: password_hash('dummy-constant-value', PASSWORD_ARGON2ID)
$dummyHash = '$argon2id$v=19$m=65536,t=4,p=1$VkZVLkx3L3FPaVA5NndVSA$vwBHHeAqq1DpGTf7G55ZPAUad+CGLvEJle2m5NA8ulA';
```

> このダミーハッシュパターンは [password-hashing.md](password-hashing.md) で最初に文書化されました。
> **同じ原則が `password_verify()` が存在しない可能性のあるユーザーに対して呼び出されるすべての場所に適用されます。**

---

## ATK アセスメント（FT268）

`NENE2-FT/auditlog` に対するクラッカー視点の攻撃テスト。攻撃面: JWT 認証によるタスク CRUD + 未認証の監査ログ読み取り。

### ATK-01 — JWT None アルゴリズム攻撃 🚫 BLOCKED

**攻撃**: `"alg":"none"` と署名なし、任意の `sub` クレームで JWT を偽造する。
```
Header: {"alg":"none","typ":"JWT"}
Payload: {"sub":1,"email":"admin@x.com","iat":9999999999,"exp":9999999999}
Signature: （空）
```
**結果**: `LocalBearerTokenVerifier` は設定されたシークレットに対して HMAC-HS256 を使用して検証します。有効な署名のないトークンは拒否されます — `alg:none` は受け入れられません。→ **401 Unauthorized**

---

### ATK-02 — JWT 署名の改ざん 🚫 BLOCKED

**攻撃**: 有効な JWT を取得し、`sub` フィールドを別のユーザーの ID に変更（例: `1` → `2`）し、再署名なしに再エンコードする。
**結果**: HMAC-HS256 署名が変更されたペイロードと一致しなくなります。`LocalBearerTokenVerifier` がトークンを拒否します。→ **401 Unauthorized**

---

### ATK-03 — JWT 期限切れトークンのリプレイ 🚫 BLOCKED

**攻撃**: `exp` タイムスタンプが過ぎた後にキャプチャした JWT をリプレイする。
**結果**: `BearerTokenMiddleware` / `LocalBearerTokenVerifier` が `exp` をチェックします。期限切れのトークンは拒否されます。→ **401 Unauthorized**

---

### ATK-04 — IDOR: ID 経由で別ユーザーのタスクにアクセス ✅ BLOCKED

**攻撃**: ユーザー A（sub=1）として認証し、タスク 3 がユーザー B（sub=2）に属する `PUT /tasks/3` を呼び出す。
**結果**: タスクルートハンドラーが `task->actorId` を読み取り、JWT クレームの `actorId` と比較します。不一致は → **404 Not Found** を返します（リソースの存在が攻撃者に確認されない）。

---

### ATK-05 — IDOR: 別ユーザーのタスクを削除 ✅ BLOCKED

**攻撃**: ユーザー A として認証し、タスク 7 がユーザー B に属する `DELETE /tasks/7` を呼び出す。
**結果**: ATK-04 と同じ所有権ガード。`task->actorId !== $actorId` → **404 Not Found**。

---

### ATK-06 — リクエストボディ経由のアクター ID インジェクション ✅ BLOCKED

**攻撃**: `POST /tasks` にボディ `{"title":"Injected","actor_id":999}` を付けて送信する。
**結果**: コントローラーは `body['actor_id']` を完全に無視します。監査レコードは `nene2.auth.claims['sub']`（JWT）からの `actorId` を使用します。タスクは認証済みアクターの下で作成されます — `actor_id:999` は効果がありません。

---

### ATK-07 — 未認証の監査ログ読み取り ⚠️ EXPOSED

**攻撃**: Authorization ヘッダーなしで `GET /audit` を送信する。
**結果**: 監査ログ読み取りエンドポイント（`GET /audit`、`GET /audit/{type}/{id}`）は `BearerTokenMiddleware` で**保護されていません**。ミドルウェアは `/auth/login` のみを除外しますが、監査ルートレジストラーは認証を要求せずにルートをアタッチします。任意の未認証の呼び出し元がすべてのアクターとリソースの完全な監査履歴を読み取れます。

**影響**: 誰が、いつ、どのリソースに何をしたかの完全な開示。変更前後のペイロードスナップショットを含みます。マルチテナントアプリでは重大な情報漏洩です。

**推奨**: 監査エンドポイントを管理者スコープの JWT に制限する（例: `claims['role'] === 'admin'`）か、最低限でも任意の有効な JWT を要求する。監査プレフィックスを `BearerTokenMiddleware` の保護ルートに追加する。

---

### ATK-08 — ?actor_id によるクロスアクター監査列挙 ⚠️ EXPOSED

**攻撃**: `GET /audit?actor_id=2`（または 1..N を列挙する）— 任意の actor_id のすべての監査エントリを読み取る。
**結果**: `actor_id` フィルターに認可チェックがありません。攻撃者がすべてのユーザー ID を列挙して完全な監査履歴を取得できます。ATK-07（未認証アクセス）から連鎖します。
**推奨**: 認証済みユーザーのみ（管理者でない）に監査を制限する場合、認証済みユーザーの `sub` でフィルタリングする — 呼び出し元は他のアクターのログをクエリできません。管理者はすべてを見られます。

---

### ATK-09 — 監査検索パラメーターへの SQL インジェクション 🚫 BLOCKED

**攻撃**: `GET /audit?action=deleted';DROP TABLE audit_log;--&resource_type=task`
**結果**: `$action` と `$resourceType` は SQL クエリで `?` パラメーターとしてバインドされます。文字列補間なし。SQLite は `WHERE action = ?` をインジェクトされた文字列をリテラル値として受け取ります — 単純に 0 行を返します。テーブルは安全です。→ **200 OK（空）**

---

### ATK-10 — Limit -1 / 大きなリミット DoS ✅ BLOCKED

**攻撃**: `GET /audit?limit=-1` または `GET /audit?limit=99999`。
**結果**: `max(1, min((int) ($q['limit'] ?? 50), 100))` で `[1, 100]` にクランプされます。負のリミットと過大なリミットはサイレントにクランプされます。→ **200 OK（最大 100 エントリ）**

---

### ATK-11 — ログインブルートフォース（レート制限なし） ⚠️ EXPOSED

**攻撃**: 同じメールアドレスと異なるパスワードで `POST /auth/login` を高速に連続送信する。
**結果**: レート制限なし、ロックアウトなし、CAPTCHA なし。攻撃者が無限にパスワードを繰り返せます。Argon2id KDF は各試行を約 180ms に遅らせ、強いパスワードに対してはブルートフォースを非現実的にしますが、弱いパスワードには依然として実行可能です。
**推奨**: `/auth/login` に `ThrottleMiddleware` を追加する（例: IP あたり 5 回 / 15 分）。モニタリングのために request_id とともに失敗した試行をログに記録する。

---

### ATK-12 — 任意のステータス値インジェクション ⚠️ EXPOSED

**攻撃**: `PUT /tasks/1` にボディ `{"status":"<script>alert(1)</script>"}` または `{"status":"admin_override"}` を付けて送信する。
**結果**: ハンドラーは任意の空でない文字列を `status` として受け入れます。リポジトリはそのまま書き込みます。タスクは `status="<script>alert(1)</script>"` で更新されます。列挙型バリデーションも許可リストもありません。
**影響**: エスケープなしでブラウザにステータスがレンダリングされた場合の保存型 XSS。ビジネスロジックがステータスを `{open, closed, in_progress}` と仮定している場合のドメインモデルの破損。
**推奨**: 許可リストまたは PHP BackedEnum に対してステータスをバリデーションする:
```php
$validStatuses = ['open', 'in_progress', 'closed'];
if (!in_array($status, $validStatuses, true)) {
    return $this->problems->create($request, 'validation-failed', 'Validation Failed', 422, null, [
        'errors' => [['field' => 'status', 'code' => 'invalid', 'message' => 'status must be one of: open, in_progress, closed']],
    ]);
}
```

---

### ATK まとめ

| ID | 攻撃 | 結果 |
|----|--------|--------|
| ATK-01 | JWT `alg:none` | 🚫 BLOCKED |
| ATK-02 | JWT 署名の改ざん | 🚫 BLOCKED |
| ATK-03 | 期限切れ JWT のリプレイ | 🚫 BLOCKED |
| ATK-04 | IDOR: 別ユーザーのタスクにアクセス | ✅ BLOCKED |
| ATK-05 | IDOR: 別ユーザーのタスクを削除 | ✅ BLOCKED |
| ATK-06 | ボディ経由のアクター ID インジェクション | ✅ BLOCKED |
| ATK-07 | 未認証の監査ログ読み取り | ⚠️ EXPOSED |
| ATK-08 | クロスアクター監査列挙 | ⚠️ EXPOSED |
| ATK-09 | 監査検索への SQL インジェクション | 🚫 BLOCKED |
| ATK-10 | Limit -1 / 過大なリミット DoS | ✅ BLOCKED |
| ATK-11 | ログインブルートフォース（レート制限なし） | ⚠️ EXPOSED |
| ATK-12 | 任意のステータス値インジェクション | ⚠️ EXPOSED |

**9 BLOCKED / SAFE, 4 EXPOSED**（ATK-07、08 は同じ未認証監査読み取りギャップから連鎖）。

重大な所見は **ATK-07**: 監査ログエンドポイントに認証ガードがなく、未認証の呼び出し元に完全なアクター活動履歴が公開されます。ATK-12（ステータス許可リスト）と ATK-11（レート制限）は標準的な強化ギャップです。SQL インジェクションや JWT 偽造のベクタは見つかりませんでした。
