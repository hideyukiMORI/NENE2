---
title: "OAuth2 Social Login の実装ガイド"
category: auth
tags: [oauth2, social-login, authorization-code-flow, jwt]
difficulty: advanced
related: [add-jwt-authentication, delegated-access-grants]
---

# OAuth2 Social Login の実装ガイド

## 概要

このガイドでは NENE2 を使って OAuth2 Authorization Code Flow による Social Login を実装する方法を説明します。
CSRF 防止（state パラメータ）・コードリプレイ防止・セッション無効化・クラッカー攻撃試験（ATK-01〜12）を含みます。

---

## DB スキーマ

```sql
CREATE TABLE users (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    provider   TEXT    NOT NULL,
    subject    TEXT    NOT NULL,  -- OAuth プロバイダが発行するユーザー識別子
    name       TEXT    NOT NULL,
    email      TEXT,
    created_at TEXT    NOT NULL,
    UNIQUE (provider, subject)
);

CREATE TABLE oauth_states (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    state      TEXT    NOT NULL UNIQUE,
    created_at TEXT    NOT NULL,
    expires_at TEXT    NOT NULL,
    used_at    TEXT    -- NULL = 未使用, NOT NULL = 使用済み（再利用不可）
);

CREATE TABLE sessions (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    token      TEXT    NOT NULL UNIQUE,
    created_at TEXT    NOT NULL,
    expires_at TEXT    NOT NULL,
    revoked_at TEXT,   -- NULL = 有効, NOT NULL = ログアウト済み
    FOREIGN KEY (user_id) REFERENCES users(id)
);

CREATE TABLE used_oauth_codes (
    id       INTEGER PRIMARY KEY AUTOINCREMENT,
    code     TEXT    NOT NULL UNIQUE,
    used_at  TEXT    NOT NULL
);
```

`oauth_states.used_at` と `used_oauth_codes` が**CSRF・コードリプレイ攻撃防止**の核心。

---

## エンドポイント設計

| メソッド | パス | 説明 |
|---|---|---|
| POST | `/auth/oauth/start` | state 生成・認可 URL 返却 |
| POST | `/auth/oauth/callback` | state/code 検証・ユーザー作成・セッション発行 |
| POST | `/auth/logout` | セッション無効化 |
| GET | `/me` | 認証済みユーザー情報取得 |

---

## Authorization Code Flow

```
Client                 Server                   OAuth Provider
  |                      |                            |
  |-- POST /start -----→ |                            |
  |← {state, auth_url} --|                            |
  |                      |                            |
  |-- ユーザーが auth_url にアクセス →→→→→→→→→→→→→→→|
  |←←←←←←←←←←←←←←←←←←← redirect with ?code=XXX&state=YYY |
  |                      |                            |
  |-- POST /callback ──→ |                            |
  |   {state, code}      |-- code exchange →→→→→→→→ |
  |                      |← {subject, name, email} ---|
  |← {token, user} -----.|                            |
  |                      |                            |
  |-- GET /me ─────────→ |                            |
  |   Authorization: Bearer <token>                   |
  |← {id, name, email} - |                            |
```

---

## 設計のポイント

### CSRF 防止（state パラメータ）

OAuth2 コールバックは URL パラメータで届くため、攻撃者が被害者を悪意あるコールバック URL に誘導できる（CSRF）。
`state` で防ぐ:

1. `/auth/oauth/start` でランダムな state を DB に保存
2. コールバックで state を照合
3. **使用済み state を再利用不可にする**（`used_at` を記録）

```php
if (!$this->repo->isStateValid($state, $now)) {
    return $this->json->create(['error' => 'Invalid, expired, or already used state'], 400);
}
```

### コードリプレイ防止

Authorization Code は 1 回のみ使用可能（RFC 6749 §4.1.2）。
`used_oauth_codes` テーブルで使用済みコードを記録し再利用を拒否:

```php
if ($this->repo->isCodeUsed($code)) {
    return $this->json->create(['error' => 'Authorization code already used'], 400);
}
// ... プロバイダー検証 ...
$this->repo->markCodeUsed($code, $now);
```

### state と code の消費順序

state 検証 → code 検証 → **プロバイダーに問い合わせ → state と code を同時に使用済みマーク**。
プロバイダーが失敗した場合、state も code も消費しない（やり直しができる）。

### Bearer トークン認証

```php
private function bearerToken(ServerRequestInterface $request): ?string
{
    $header = $request->getHeaderLine('Authorization');
    if (!str_starts_with($header, 'Bearer ')) {
        return null;
    }
    return substr($header, 7) ?: null;
}
```

### ユーザーの upsert

同一プロバイダーの同一 subject が再ログインした場合、既存ユーザーを更新する:

```php
public function upsertUser(array $info, string $now): int
{
    $row = $this->db->fetchOne(
        'SELECT id FROM users WHERE provider = ? AND subject = ?',
        [$info['provider'], $info['subject']],
    );
    if ($row !== null) {
        // 名前・メールは最新に更新
        $this->db->insert('UPDATE users SET name = ?, email = ? WHERE id = ?', [...]);
        return (int) $row['id'];
    }
    return $this->db->insert('INSERT INTO users ...', [...]);
}
```

### state の有効期限

state は 5 分間有効。期限切れ state は `expires_at > $now` チェックで拒否:

```php
public function isStateValid(string $state, string $now): bool
{
    $row = $this->findState($state);
    if ($row === null || $row['used_at'] !== null) return false;
    return (string) $row['expires_at'] > $now;
}
```

---

## クラッカー攻撃試験 ATK-01〜12（全 Pass）

| # | 攻撃シナリオ | 対策 | 期待ステータス |
|---|---|---|---|
| ATK-01 | CSRF: state パラメータ欠落 | required バリデーション | 422 |
| ATK-02 | CSRF: 偽造 state 値 | DB 照合 → 不明 state は拒否 | 400 |
| ATK-03 | 使用済み state の再利用 | `used_at` 記録後は再使用不可 | 400 |
| ATK-04 | 正規 state の横取り再利用 | 1 回使用後に即座に失効 | 400 |
| ATK-05 | 認可コードのリプレイ | `used_oauth_codes` で記録 | 400 |
| ATK-06 | 不正な認可コード | モックプロバイダーが null 返却 | 401 |
| ATK-07 | オープンリダイレクト注入 | start は redirect_uri を受け付けない | auth_url に evil ドメイン不含 |
| ATK-08 | ログアウト後のセッション再利用 | `revoked_at` セット → findSession 失敗 | 401 |
| ATK-09 | 不正なセッショントークン | DB 照合 → 未登録 token は拒否 | 401 |
| ATK-10 | 認証なしで /me アクセス | Bearer 未設定 → 401 | 401 |
| ATK-11 | state パラメータに SQL インジェクション | prepared statement で無効化 | 400/422 |
| ATK-12 | 異なるユーザーのセッションで /me | token は user_id に紐付き | 異なる user.id |

---

## テスト構成

```
tests/
  OAuth/
    OAuthTest.php   — 機能テスト 10 件
    AttackTest.php  — クラッカー攻撃試験 12 件 (ATK-01〜12)
```

合計 22 テスト / 36 アサーション。

---

## 参照実装

`../NENE2-FT/oauthlog/` — FT160 フィールドトライアル（22 テスト + クラッカー攻撃試験 12 件）
