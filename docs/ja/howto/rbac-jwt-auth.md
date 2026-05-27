# ハウツー: RBAC + JWT 認証

> **FT リファレンス**: FT279 (`NENE2-FT/rbaclog`) — JWT を使ったロールベースアクセス制御: タイミング攻撃防止付き Argon2id パスワードハッシュ、JWT の role クレーム、401 と 403 の区別、手動フォールバック付き BearerTokenMiddleware、14 テスト / 48 アサーション PASS。
>
> **VULN アセスメント**: V-01〜V-10 はこのドキュメントの末尾に含まれています。

このガイドでは、NENE2 で JWT トークンを使ったロールベースアクセス制御（RBAC）システムの構築方法を解説します。

## 機能

- メール + パスワードログイン（Argon2id ハッシュ）
- JWT に埋め込まれた role クレーム（`user` / `admin`）
- 公開、認証済み、管理者専用エンドポイント
- ハンドラーごとのフォールバック付き `BearerTokenMiddleware`
- 正確な `401 Unauthorized` と `403 Forbidden` のセマンティクス

## スキーマ

```sql
CREATE TABLE IF NOT EXISTS users (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    email         TEXT NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    role          TEXT NOT NULL DEFAULT 'user',
    created_at    TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS posts (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    title      TEXT NOT NULL,
    body       TEXT NOT NULL,
    author_id  INTEGER NOT NULL,
    created_at TEXT NOT NULL
);
```

## エンドポイント

| メソッド | パス | 認証 | 説明 |
|--------|------|------|-------------|
| `POST` | `/auth/login` | なし | ログイン、JWT を受け取る |
| `GET` | `/posts` | なし | すべての投稿を一覧表示する（公開） |
| `POST` | `/posts` | ユーザーまたは管理者 | 投稿を作成する |
| `DELETE` | `/posts/{id}` | 管理者のみ | 投稿を削除する |

## タイミング攻撃防止付きログイン

ダミーハッシュのトリックにより、メールが存在するかどうかにかかわらず、ログインが常に同じ時間かかることを保証します:

```php
$user = $this->users->findByEmail(trim($body['email']));

$dummyHash   = '$argon2id$v=19$m=65536,t=4,p=1$dummysaltdummysaltdummysalt$dummyhashvaluedummyhashvaluedummyh';
$hashToCheck = $user !== null ? $user->passwordHash : $dummyHash;

if (!password_verify($body['password'], $hashToCheck) || $user === null) {
    return $this->problems->create($request, 'invalid-credentials', 'Invalid Credentials', 401, '...');
}
```

ダミーハッシュなしでは、タイミング攻撃がレスポンス時間を測定することで有効なメールアドレスを検出できます — 不明なメールではハッシュ計算がスキップされます。

## JWT への role クレーム埋め込み

役割は各リクエストでの DB ラウンドトリップを避けるために JWT ペイロードに保存されます:

```php
$token = $this->issuer->issue([
    'sub'   => $user->id,
    'email' => $user->email,
    'role'  => $user->role->value,   // Role::User → 'user', Role::Admin → 'admin'
    'iat'   => $now,
    'exp'   => $now + self::TOKEN_TTL_SECONDS,
]);
```

## Enum を使ったロールチェック

```php
private function requireRole(ServerRequestInterface $request, Role $required): array|ResponseInterface
{
    $claims = $this->requireAuth($request);
    if ($claims instanceof ResponseInterface) {
        return $claims;
    }

    $actualRole = Role::tryFrom((string) ($claims['role'] ?? ''));

    if ($actualRole !== $required) {
        return $this->problems->create(
            $request, 'forbidden', 'Forbidden', 403,
            "This action requires the '{$required->value}' role."
        );
    }

    return $claims;
}
```

`Role::tryFrom()` は文字列クレームを安全に enum にマップします — 無効なロール文字列は `null` になり、チェックに失敗します。

## 401 と 403 の区別

| ステータス | 意味 | 時期 |
|--------|---------|------|
| `401 Unauthorized` | 未認証 | トークンなし、無効なトークン、期限切れトークン |
| `403 Forbidden` | 認証済みだが不十分なロール | 有効なトークン、間違ったロール |

この区別はクライアントにとって重要です: `401` は再ログインを促すべきで、`403` は「アクセス拒否」メッセージを表示すべきです。

## フォールバック付き BearerTokenMiddleware

一部のパスは公開と保護されたメソッドの両方を提供します（例: `GET /posts` は公開、`POST /posts` は認証済み）。ミドルウェアはパスを完全に除外し、認証を必要とするハンドラーが `requireAuth()` を手動で呼び出します:

```php
$authMiddleware = new BearerTokenMiddleware(
    problemDetails: $problems,
    verifier: $this->verifier,
    excludedPaths: ['/auth/login', '/posts'],  // /posts にはメソッドごとの処理が必要
);
```

```php
private function requireAuth(ServerRequestInterface $request): array|ResponseInterface
{
    // 高速パス: ミドルウェアが既に検証済み
    $claims = $request->getAttribute('nene2.auth.claims');
    if (is_array($claims)) {
        return $claims;
    }

    // 低速パス: 除外されたパスの手動抽出
    $authorization = $request->getHeaderLine('Authorization');
    if ($authorization === '' || !str_starts_with($authorization, 'Bearer ')) {
        return $this->problems->create($request, 'unauthorized', 'Unauthorized', 401, '...');
    }

    try {
        return $this->verifier->verify(substr($authorization, 7));
    } catch (TokenVerificationException) {
        return $this->problems->create($request, 'unauthorized', 'Unauthorized', 401, '...');
    }
}
```

---

## VULN アセスメント — 脆弱性診断

### V-01 — 偽造 JWT クレームによるロール昇格 🛡️ SAFE

**Threat**: 攻撃者が `"role": "admin"` を持つ JWT を作成してランダムな秘密で署名する。
**Defense**: `LocalBearerTokenVerifier` がサーバーの秘密に対して HMAC-HS256 署名を検証します。秘密が一致しない場合 `TokenVerificationException` → 401。
**Result**: SAFE — 署名検証がクレーム偽造を防ぎます。

---

### V-02 — ログイン時のメール列挙によるタイミング攻撃 🛡️ SAFE

**Threat**: 攻撃者が不明なメールと既知のメールでログインリクエストを送信し、レスポンス時間を測定して有効なアカウントを列挙する。
**Defense**: 不明なメールの場合、`password_verify()` がダミーの Argon2id ハッシュ（同じコストパラメーター）に対して呼び出されます。両方のパスが約 200ms かかります。ログイン失敗メッセージは間違ったメールと間違ったパスワードで同一です。
**Result**: SAFE — タイミングが均等化され、エラーメッセージが汎用的です。

---

### V-03 — 期限切れトークンが有効として受け入れられる 🛡️ SAFE

**Threat**: 攻撃者が期限切れ後にキャプチャした JWT を再利用する。
**Defense**: `LocalBearerTokenVerifier` が `exp` クレームを `time()` と照合します。期限切れトークンは `TokenVerificationException` → 401 をスローします。
**Result**: SAFE — `exp` チェックが強制されます。

---

### V-04 — JWT ペイロードの変更（再署名なし）によるロールダウングレード 🛡️ SAFE

**Threat**: 攻撃者が JWT ペイロードを base64 デコードし、`"role": "user"` を `"role": "admin"` に変更し、再エンコードして元の署名で送信する。
**Defense**: JWT 署名はヘッダー + ペイロードをカバーします。ペイロードを変更すると署名が無効になる → `TokenVerificationException` → 401。
**Result**: SAFE — ペイロード改ざんが HMAC によって検出されます。

---

### V-05 — ユーザーロールで管理者エンドポイントにアクセス 🛡️ SAFE

**Threat**: 攻撃者が `user` としてログインして `DELETE /posts/{id}` を試みる。
**Defense**: `requireRole($request, Role::Admin)` が JWT の `role` クレームを確認します。`user` トークンは `role: 'user'` を持つ → `Role::tryFrom('user') !== Role::Admin` → 403。
**Result**: SAFE — 403 Forbidden が返されます; ユーザートークンは管理者に昇格できません。

---

### V-06 — 保護されたエンドポイントへの未認証アクセス 🛡️ SAFE

**Threat**: 攻撃者が Authorization ヘッダーなしで `POST /posts` または `DELETE /posts/{id}` を送信する。
**Defense**: `requireAuth()` が `Bearer ` プレフィックスを確認します; ヘッダーがない → 401 `unauthorized`。
**Result**: SAFE — 401 Unauthorized が返されます。

---

### V-07 — 401 と 403 の混乱（情報漏洩）🛡️ SAFE

**Threat**: 誤った 401/403 の使用がリソースの存在やユーザーが認証されているかを明かす。
**Defense**: システムは未認証アクセス（トークンなし/無効）に 401 を、不十分なロールの認証済みアクセスに 403 を返します。区別は意味的に正確であり、ロール要件を超えてリソースの存在を明かしません。
**Result**: SAFE — 401/403 セマンティクスが正確; `test401MeansNotAuthenticated` と `test403MeansAuthenticatedButForbidden` の両テストが通過します。

---

### V-08 — JWT の無効なロール文字列によるバイパス 🛡️ SAFE

**Threat**: 攻撃者が（有効な秘密で、例: 侵害された秘密のシナリオ）JWT を作成して `role` を `"superadmin"` のような不明な値に設定する。
**Defense**: `Role::tryFrom((string) ($claims['role'] ?? ''))` は不明な文字列に `null` を返す → `null !== Role::Admin` → 403。
**Result**: SAFE — `tryFrom()` が null セーフ; 不明なロールは不十分として扱われます。

---

### V-09 — ログイン時のメールフィールドへの SQL インジェクション 🛡️ SAFE

**Threat**: 攻撃者が `{"email": "' OR '1'='1", "password": "anything"}` を送信する。
**Defense**: `findByEmail()` はパラメーター化クエリを使用します（`WHERE email = ?`）。インジェクションされた文字列はリテラル値として扱われ、SQL としては扱われません。
**Result**: SAFE — パラメーター化クエリが SQL インジェクションを防ぎます。

---

### V-10 — パスワードが平文で保存される 🛡️ SAFE

**Threat**: DB が侵害された場合、パスワードが読み取り可能。
**Defense**: コストパラメーター `m=65536,t=4,p=1` で `password_hash($password, PASSWORD_ARGON2ID)`。Argon2id ハッシュのみが保存され、平文パスワードは永続化されません。
**Result**: SAFE — Argon2id は現在推奨されるアルゴリズム（RFC 9106）; PBKDF2/bcrypt/scrypt も通過します。

---

### VULN サマリー

| ID | 脅威 | 結果 |
|----|--------|--------|
| V-01 | 偽造 JWT によるロール昇格 | 🛡️ SAFE |
| V-02 | メール列挙によるタイミング攻撃 | 🛡️ SAFE |
| V-03 | 期限切れトークンが受け入れられる | 🛡️ SAFE |
| V-04 | 再署名なしの JWT ペイロード改ざん | 🛡️ SAFE |
| V-05 | ユーザーロールトークンで管理者エンドポイント | 🛡️ SAFE |
| V-06 | 保護されたエンドポイントへの未認証アクセス | 🛡️ SAFE |
| V-07 | 401 と 403 の混乱 | 🛡️ SAFE |
| V-08 | 不明なロール文字列によるバイパス | 🛡️ SAFE |
| V-09 | メールフィールドへの SQL インジェクション | 🛡️ SAFE |
| V-10 | パスワードが平文で保存される | 🛡️ SAFE |

**10 SAFE, 0 EXPOSED**
Argon2id ハッシュ、HMAC 署名 JWT、`Role::tryFrom()` ガード、パラメーター化クエリがすべてのテスト済み脆弱性ベクターを防ぎます。

---

## してはいけないこと

| アンチパターン | リスク |
|---|---|
| DB にロールを保存してリクエストごとに参照する | リクエストごとに追加の DB クエリ; ロール変更にトークン失効ロジックが必要 |
| `Role::tryFrom()` の代わりに `Role::from()` を使用する | 不明なロール文字列は `ValueError` をスロー — 403 の代わりに 500 |
| 未認証リクエストに 403 を返す | クライアントを誤解させる — 403 は「認証済みだが禁止」を意味するべきで「ログインしていない」ではない |
| 間違ったロールアクセスに 401 を返す | クライアントが「アクセス拒否」を表示する代わりに再ログインを試みる |
| ログインでダミーハッシュをスキップする | タイミング攻撃が有効なメールアドレスを明かす |
| MD5/SHA1/平文でパスワードを保存する | DB 侵害でブルートフォースまたはレインボーテーブル攻撃によりすべてのパスワードが公開される |
| JWT にロール（ではなく権限）を埋め込む | 権限セットの変更にトークンの再発行が必要; ロールは安定しているが権限は変わる |
| `alg: none` JWT を許可する | 攻撃者が署名を完全に削除してトークンを偽造できる |
| enum チェックの代わりに `str_contains($role, 'admin')` を使用する | `"not-admin"` または `"superadmin"` が予期せずマッチする可能性がある |
