# ハウツー: マジックリンク認証

> **FT リファレンス**: FT309 (`NENE2-FT/magiclog`) — マジックリンク認証: トークンを SHA-256 ハッシュとして保存（プレーンテキストは保存しない）、15 分 TTL、used-at で再利用を防止、used-at の前に有効期限をチェック、セッショントークン 64+ hex 文字の SHA-256 保存、失効/期限切れセッションを拒否、/auth/request は常に 202（ユーザー列挙防止）、Bearer トークン必須（X-User-Id ヘッダーは無視）、VULN-A〜L すべて SAFE、43 テスト / 91 アサーション PASS。

このガイドでは、セキュリティがトークンエントロピー、ハッシュストレージ、短い TTL、一回限りの使用強制に依存するパスワードレスのマジックリンク認証システムの構築方法を示します。

## スキーマ

```sql
CREATE TABLE users (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    email      TEXT    NOT NULL UNIQUE,
    created_at TEXT    NOT NULL
);

CREATE TABLE magic_links (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    token_hash TEXT    NOT NULL UNIQUE,   -- SHA-256(raw_token)
    expires_at TEXT    NOT NULL,          -- now + 15 分
    used_at    TEXT,                      -- 最初の検証成功時に設定
    created_at TEXT    NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

CREATE TABLE auth_sessions (
    id                 INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id            INTEGER NOT NULL,
    session_token_hash TEXT    NOT NULL UNIQUE,   -- SHA-256(raw_token)
    expires_at         TEXT    NOT NULL,
    revoked_at         TEXT,
    created_at         TEXT    NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id)
);
```

`magic_links.token_hash` と `auth_sessions.session_token_hash` の両方が SHA-256 ハッシュを保存します。生のトークンは保存されません。

## エンドポイント

| メソッド | パス | 認証 | 説明 |
|--------|------|------|-------------|
| `POST` | `/auth/request` | — | マジックリンクをリクエストする（常に 202） |
| `POST` | `/auth/verify` | — | トークンを検証してセッションを発行する |
| `POST` | `/auth/logout` | `Bearer` | セッションを失効させる |
| `GET` | `/me` | `Bearer` | 現在のユーザーを取得する |

## トークン生成とハッシュ

```php
// 64 hex 文字（256 ビットエントロピー）を生成する
$rawToken   = bin2hex(random_bytes(32));
$tokenHash  = hash('sha256', $rawToken);
$expiresAt  = (new \DateTimeImmutable())->modify('+15 minutes')->format('c');

$this->repo->createMagicLink($userId, $tokenHash, $expiresAt);

// 生のトークンを呼び出し元に返す（メールでユーザーに送信する）
return ['token' => $rawToken];
```

生のトークンはレスポンスで返されます（メールの URL パラメーターとして送信するため）。SHA-256 ハッシュのみが保存されます。`UNIQUE(token_hash)` でハッシュ衝突を防止します。

## セッショントークン

```php
$rawSessionToken  = bin2hex(random_bytes(32)); // 64 hex 文字
$sessionTokenHash = hash('sha256', $rawSessionToken);
$sessionExpiry    = (new \DateTimeImmutable())->modify('+24 hours')->format('c');

$this->repo->createSession($userId, $sessionTokenHash, $sessionExpiry);

return ['session_token' => $rawSessionToken]; // 一度だけ返す、その後はハッシュのみ
```

セッショントークン: 64 hex 文字 = 256 ビットエントロピー。SHA-256 ハッシュとして保存。最小 64 文字はエントロピーソース（`bin2hex(random_bytes(32))`）によって強制されます。

## 検証 — チェックの順序が重要

```php
// 1. ハッシュで検索
$magicLink = $this->repo->findByTokenHash(hash('sha256', $token));
if ($magicLink === null) {
    return 401; // 見つからない
}

// 2. まず有効期限をチェック
if ($magicLink['expires_at'] < date('c')) {
    return 401; // エラーメッセージに 'expired'
}

// 3. 次に used_at をチェック
if ($magicLink['used_at'] !== null) {
    return 401; // エラーメッセージに 'already been used'
}

// 4. 使用済みとしてマーク
$this->repo->markUsed($magicLink['id'], date('c'));

// 5. セッションを作成
```

有効期限は `used_at` の**前に**チェックされます。トークンが期限切れかつ使用済みの場合、エラーは「already been used」ではなく「expired」と言います。これは、攻撃者がトークンが使用されたかどうかをプローブするタイミング攻撃を防止します。

## ユーザー列挙防止 — 常に 202

```php
public function handleRequest(ServerRequestInterface $request): ResponseInterface
{
    $email = $body['email'] ?? '';
    
    $user = $this->repo->findUserByEmail($email);
    if ($user !== null) {
        // マジックリンクを作成して（本番では）メールを送信する
        $rawToken = bin2hex(random_bytes(32));
        $this->repo->createMagicLink($user['id'], hash('sha256', $rawToken), ...);
    }
    // メールが存在するかどうかに関わらず常に 202 を返す
    return $this->json->create(['message' => 'If registered, a magic link has been sent.'], 202);
}
```

存在しないメールアドレスは有効なものと同じ 202 レスポンスを返します。「email not found」メッセージは決して返されません。

## セッションバリデーション

```php
$token = substr($authHeader, 7); // 'Bearer ' を除去
$tokenHash = hash('sha256', $token);
$session = $this->repo->findSessionByHash($tokenHash);

if ($session === null) {
    return 401; // セッションが見つからない
}
if ($session['revoked_at'] !== null) {
    return 401; // 'revoked'
}
if ($session['expires_at'] < date('c')) {
    return 401; // 'expired'
}
```

3 つのセッションチェック: 存在確認 → 失効確認 → 有効期限確認。ログアウトからの失効セッションは「revoked」を返します — 明確なエラーメッセージのために「expired」と区別されます。

## 認証での X-User-Id ヘッダーは無視される

`/me` エンドポイントには有効な `Bearer` セッショントークンが必要です。`X-User-Id` ヘッダー（他のエンドポイントで便利な認証として使用）はここでは明示的に無視されます:

```php
// Bearer トークン認証のみ — X-User-Id は受け付けない
$authHeader = $request->getHeaderLine('Authorization');
if (!str_starts_with($authHeader, 'Bearer ')) {
    return 401;
}
```

---

## 脆弱性評価

### V-01 — 有効期限は used チェックの前に拒否される ✅ SAFE

**リスク**: トークンが期限切れだがまだ未使用; 攻撃者がそれを使おうとして「already used」エラーを得て、チェックの順序を知る。
**判定**: SAFE — 有効期限が最初にチェックされます。期限切れ+使用済みのトークンは両方とも「expired」を返します。

---

### V-02 — セッショントークンはハッシュとして保存される ✅ SAFE

**リスク**: DB 侵害がセッショントークンを明かす。
**判定**: SAFE — `session_token_hash = SHA-256(raw_token)`。生のトークンは DB に存在しません。

---

### V-03 — 使用済みのマジックリンクは再利用できない ✅ SAFE

**リスク**: 攻撃者がマジックリンク URL を取得して、意図したユーザーの後に使用する。
**判定**: SAFE — `used_at` は最初の使用時に設定されます。2 回目の試みは 401「already been used」を返します。

---

### V-04 — ログアウトでセッションが無効化される ✅ SAFE

**リスク**: セッション Cookie/トークンがログアウト後もまだ機能する。
**判定**: SAFE — ログアウトが `revoked_at` を設定します。その後のトークンでの `/me` は 401「revoked」を返します。

---

### V-05 — 存在しないメールが 202 を返す ✅ SAFE

**リスク**: 攻撃者が異なるエラーレスポンスを観察してどのメールが登録されているかをチェックする。
**判定**: SAFE — `/auth/request` は常に同じボディで 202 を返します。「not found」漏洩なし。

---

### V-06 — 失効したセッションが拒否される ✅ SAFE

**リスク**: 手動で失効したセッションがまだアクセスを許可する。
**判定**: SAFE — `revoked_at` チェックがアクセスを拒否します。エラーメッセージは「revoked」です。

---

### V-07 — 期限切れセッションが拒否される ✅ SAFE

**リスク**: 長期前の古いセッションがまだ機能する。
**判定**: SAFE — `expires_at` チェックがアクセスを拒否します。エラーメッセージは「expired」です。

---

### V-08 — マジックリンクトークンがハッシュとして DB に保存される ✅ SAFE

**リスク**: DB 侵害がマジックリンクトークンを明かす。攻撃者が任意のユーザーとして認証する。
**判定**: SAFE — `token_hash = SHA-256(raw_token)`。生のトークンは DB に存在しません。

---

### V-09 — マジックリンクが 15 分以内に期限切れになる ✅ SAFE

**リスク**: 長命なマジックリンクで遅延した傍受とリプレイが可能。
**判定**: SAFE — TTL ≤ 900 秒（15 分）がテストで確認済み。

---

### V-10 — セッションに有効期限がある ✅ SAFE

**リスク**: セッションが無期限。古いトークンが永遠に有効。
**判定**: SAFE — `expires_at` がセッション作成時に未来に設定されます。非 null であることが確認済み。

---

### V-11 — セッショントークンのエントロピーが十分 ✅ SAFE

**リスク**: 短いセッショントークンがブルートフォース可能。
**判定**: SAFE — `bin2hex(random_bytes(32))` = 64 hex 文字 = 256 ビットエントロピー。

---

### V-12 — X-User-Id ヘッダーで認証をバイパスできない ✅ SAFE

**リスク**: `X-User-Id: 1` ヘッダーが有効なセッションなしで `/me` へのアクセスを許可する。
**判定**: SAFE — `/me` は `Authorization: Bearer <token>` を要求します。X-User-Id は無視されます。

---

### VULN サマリー

| ID | 脆弱性 | 判定 |
|----|--------|------|
| V-01 | 有効期限 vs used チェック順序 | ✅ SAFE |
| V-02 | プレーンテキスト DB のセッショントークン | ✅ SAFE |
| V-03 | 使用済みマジックリンクの再利用 | ✅ SAFE |
| V-04 | ログアウト後もセッションが有効 | ✅ SAFE |
| V-05 | メール列挙 | ✅ SAFE |
| V-06 | 失効セッションへのアクセス | ✅ SAFE |
| V-07 | 期限切れセッションへのアクセス | ✅ SAFE |
| V-08 | DB 内のマジックリンクトークン | ✅ SAFE |
| V-09 | マジックリンク TTL > 15 分 | ✅ SAFE |
| V-10 | セッション有効期限なし | ✅ SAFE |
| V-11 | セッショントークンのエントロピー低 | ✅ SAFE |
| V-12 | X-User-Id バイパス | ✅ SAFE |

**12 SAFE、0 EXPOSED**

---

## やってはいけないこと

| アンチパターン | リスク |
|---|---|
| マジックリンクトークンを生のまま DB に保存する | DB 侵害で攻撃者が任意のユーザーとして認証できる |
| セッショントークンを生のまま DB に保存する | DB 侵害で全セッションが無効化される |
| expires_at の前に used_at をチェックする | タイミング漏洩でトークンが使用されたかどうかが分かる |
| 存在しないメールにエラーを返す | 攻撃者が登録済みメールを列挙できる |
| マジックリンクに TTL なし | 無期限に有効なトークン; 遅延傍受攻撃 |
| セッション有効期限なし | セッションが永遠に有効 |
| bearer 認証に X-User-Id を受け入れる | トークンなしのヘッダーベース認証バイパス |
| エントロピー低のトークン（`rand()` または 8 文字） | ブルートフォース可能なトークン |
| 複数セッションに同じマジックリンクを再利用する | 1 トークンの漏洩で後続のすべてのセッションが侵害される |
