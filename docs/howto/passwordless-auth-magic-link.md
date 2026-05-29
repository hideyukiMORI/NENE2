---
title: "Passwordless Auth (Magic Link)"
category: auth
tags: [passwordless, magic-link, one-time-token, email-auth]
difficulty: intermediate
related: [magic-link-authentication, otp-authentication, one-time-secrets]
---

# Passwordless Auth (Magic Link)

パスワードレス認証（Magic Link）の実装ガイド。メールアドレスだけで認証できる
ワンタイムリンクシステムのセキュアな設計パターンを解説する。

## 概要

Magic Link 認証は以下のフローで動作する:

1. ユーザーがメールアドレスを送信する
2. サーバーがワンタイムトークン（Magic Link）を生成し、メールで送る
3. ユーザーがトークンを送信してセッショントークンを取得する
4. セッショントークンで API にアクセスする

## エンドポイント

| Method | Path | 説明 |
|---|---|---|
| `POST` | `/auth/request` | メールアドレス提示 → Magic Link 生成（常に 202） |
| `POST` | `/auth/verify` | Magic Link トークン検証 → セッショントークン発行 |
| `POST` | `/auth/logout` | セッション無効化（常に 204） |
| `GET` | `/me` | 認証済みユーザー情報取得 |

## データベース設計

```sql
CREATE TABLE users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    email TEXT NOT NULL UNIQUE,
    created_at TEXT NOT NULL
);

CREATE TABLE magic_links (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    token_hash TEXT NOT NULL UNIQUE,  -- SHA-256 ハッシュ保存（生値は保存しない）
    expires_at TEXT NOT NULL,          -- 15 分の有効期限
    used_at TEXT,                      -- 一回限り使用（NULL = 未使用）
    created_at TEXT NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

CREATE TABLE auth_sessions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    session_token_hash TEXT NOT NULL UNIQUE,  -- SHA-256 ハッシュ保存
    expires_at TEXT NOT NULL,                  -- 24 時間の有効期限
    revoked_at TEXT,                           -- logout で設定
    created_at TEXT NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id)
);
```

## セキュリティ設計

### トークンは SHA-256 ハッシュで保存

```php
// 生成: 256-bit ランダム → hex 文字列（64文字）
$rawToken = bin2hex(random_bytes(32));
$tokenHash = hash('sha256', $rawToken);

// DB には tokenHash だけ保存
// rawToken だけがメールで送られる（DB 漏洩時の安全性）
```

DB が漏洩してもハッシュからトークンを復元できない。パスワードハッシュと同じ原理。

### ユーザー列挙防止

```php
// POST /auth/request は常に 202 を返す
// 登録済み / 未登録メールで応答を変えない
return $this->responseFactory->create([
    'message' => 'if this email is registered, a magic link has been sent',
], 202);
```

攻撃者がメールアドレスの有効性を確認できない。

### expiry チェックは used_at チェックより先

```php
// expiry を先にチェックする
if ($now > (string) $link['expires_at']) {
    return $this->responseFactory->create(['error' => 'token has expired'], 401);
}

// その後で used_at を確認
if ($link['used_at'] !== null) {
    return $this->responseFactory->create(['error' => 'token has already been used'], 401);
}
```

期限切れトークンが「使用済み」かどうかを知られない（タイミング情報の漏洩防止）。

### 一回限り使用（リプレイ攻撃防止）

```php
// verify 成功時に immediately used_at をセット
$this->repository->markMagicLinkUsed($linkId, $now);
```

同じ Magic Link を二度使えない。傍受されたリンクの再利用を防ぐ。

### セッション無効化（logout）

```php
// logout は常に 204 — セッション存在を漏らさない
if ($session !== null && $session['revoked_at'] === null) {
    $this->repository->revokeSession((int) $session['id'], date('c'));
}
return $this->responseFactory->createEmpty(204);
```

`/me` では `revoked_at !== null` なら 401 を返す。

## セッション検証フロー

```php
private function handleMe(ServerRequestInterface $request): ResponseInterface
{
    $rawToken = $this->extractBearerToken($request);
    if ($rawToken === '') {
        return $this->responseFactory->create(['error' => 'authentication required'], 401);
    }

    $tokenHash = hash('sha256', $rawToken);
    $session = $this->repository->findSessionByTokenHash($tokenHash);

    if ($session === null) { return 401; }
    if ($session['revoked_at'] !== null) { return 401 revoked; }
    if ($now > $session['expires_at']) { return 401 expired; }

    // ...
}
```

## Bearer トークン抽出

```php
private function extractBearerToken(ServerRequestInterface $request): string
{
    $header = $request->getHeaderLine('Authorization');
    if (!str_starts_with($header, 'Bearer ')) {
        return '';
    }
    return trim(substr($header, 7));
}
```

`X-User-Id` ヘッダーは認証に使用しない。`Authorization: Bearer <token>` のみ。

## 新規ユーザー自動作成

```php
public function findOrCreateUser(string $email, string $now): int
{
    $user = $this->findUserByEmail($email);
    if ($user !== null) {
        return (int) $user['id'];
    }
    $this->executor->execute('INSERT INTO users (email, created_at) VALUES (?, ?)', [$email, $now]);
    return (int) $this->executor->lastInsertId();
}
```

初回ログインでユーザーを自動作成。パスワードレス認証の特性。

## Magic Link の有効期限

- **Magic Link**: 15 分（900 秒）— メールの開封・クリックまでの余裕
- **Session Token**: 24 時間（86400 秒）— 通常の API セッション

```php
$expiresAt = date('c', time() + 900);    // magic link: 15 min
$sessionExpiresAt = date('c', time() + 86400);  // session: 24h
```

## 本番環境での考慮事項

- **メール送信**: 本 FT では `token` をレスポンスに含めている（テスト用）。
  本番では SMTP でユーザーのメールアドレスに送信し、レスポンスから削除する。
- **レートリミット**: `/auth/request` へのリクエストを IP / email でレート制限する。
- **古い未使用リンクの無効化**: 同じメールで `/auth/request` を複数回呼んだとき、
  古い未使用リンクを明示的に無効化することを検討する。
- **HTTPS 必須**: Magic Link トークンは URL パラメーターに含まれるため
  HTTPS が必須（中間者攻撃防止）。
