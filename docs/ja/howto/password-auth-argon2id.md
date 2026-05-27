# ハウツー: Argon2id によるパスワード認証

> **FT リファレンス**: FT331 (`NENE2-FT/pwdlog`) — Argon2id パスワードハッシュによるユーザー登録とログイン、レスポンスでパスワード/ハッシュを絶対に公開しない、ユーザー列挙防止（パスワード不正と不明なメールで同じ 401）、アルゴリズム移行時の再ハッシュ、14 テスト / 40 アサーション PASS。

このガイドでは安全なパスワードベース認証の構築方法を解説します: Argon2id でパスワードを安全に保存し、レスポンスで認証情報を漏洩させず、攻撃者が登録済みメールアドレスを列挙するのを防ぎます。

## スキーマ

```sql
CREATE TABLE users (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    email         TEXT    NOT NULL UNIQUE,
    password_hash TEXT    NOT NULL,
    created_at    TEXT    NOT NULL
);
```

`password_hash` は完全な Argon2id 出力文字列を保存します（例: `$argon2id$v=19$m=65536,...`）。**平文や MD5/SHA-1 は絶対に保存しないでください。**

## エンドポイント

| メソッド | パス | 説明 |
|--------|------|-------------|
| `POST` | `/register` | 新しいユーザーを登録する |
| `POST` | `/login` | 認証してユーザーデータを返す |

## 登録

```php
POST /register
{"email": "alice@example.com", "password": "correct-horse"}

→ 201
{"id": 1, "email": "alice@example.com", "created_at": "2026-05-27T09:00:00Z"}
```

**`password` と `password_hash` はレスポンスで絶対に返しません** — マスクや切り詰めも含めて。

### バリデーション

```php
POST /register  {"email": "alice@example.com", "password": "short"}
→ 422  // パスワードが短すぎる（最低 8 文字）

POST /register  {"email": "not-an-email", "password": "correct-horse"}
→ 422  // 無効なメール形式

POST /register  {"email": "alice@example.com"}
→ 400  // password フィールドなし

POST /register  {"email": "alice@example.com", "password": "battery-staple"}
// (alice が既に登録済みの場合)
→ 409  {"type": ".../email-taken", "detail": "Email already registered"}
```

## ログイン

```php
POST /login
{"email": "alice@example.com", "password": "correct-horse"}

→ 200
{"id": 1, "email": "alice@example.com", "created_at": "..."}
// password_hash は返されない
```

### ユーザー列挙防止

```php
// 既知のメールで間違ったパスワード
POST /login  {"email": "alice@example.com", "password": "wrong"}
→ 401  {"type": ".../invalid-credentials", "detail": "Invalid email or password"}

// 不明なメール
POST /login  {"email": "ghost@example.com", "password": "any"}
→ 401  {"type": ".../invalid-credentials", "detail": "Invalid email or password"}
```

**どちらのケースも同じ 401 と同一の `detail` メッセージを返します。** 不明なメールに 404 を返すと、攻撃者がユーザーデータベースを調査できるようになります。

```php
// テスト: 同じ detail 文字列
$this->assertSame($wrongPasswordBody['detail'], $unknownEmailBody['detail']);
```

## 実装

### パスワード保存 — Argon2id

```php
// 登録
$hash = password_hash($plaintext, PASSWORD_ARGON2ID);
// 保存: $argon2id$v=19$m=65536,t=4,p=1$...

// 保存しない:
// md5($plaintext)          — 数秒で逆算可能
// sha1($plaintext)         — レインボーテーブル攻撃
// $plaintext               — 平文保存
```

PHP の `password_hash(PASSWORD_ARGON2ID)` は自動的に:
- ハッシュごとにランダムなソルトを生成します
- アルゴリズム、パラメーター、ソルト、ダイジェストを 1 つの文字列に保存します
- GPU ブルートフォースに耐性があります（メモリハード）

### 検証 — 定数時間

```php
$row = $this->repo->findByEmail($email);

if ($row === null || !password_verify($plaintext, $row['password_hash'])) {
    // メールが不明でもパスワードが間違っていても同じレスポンス
    return $this->problems->create('invalid-credentials', 'Invalid email or password', 401);
}
```

`password_verify()` は定数時間で、アルゴリズムファミリー（bcrypt、Argon2id 等）をまたいで動作します。

### アルゴリズム移行時の再ハッシュ

bcrypt から Argon2id にアップグレードする場合、ログイン成功時に再ハッシュします:

```php
if (password_needs_rehash($row['password_hash'], PASSWORD_ARGON2ID)) {
    $newHash = password_hash($plaintext, PASSWORD_ARGON2ID);
    $this->repo->updateHash($row['id'], $newHash);
}
```

ユーザーは次回ログイン時にサイレントに強力なアルゴリズムに移行されます — 強制的なパスワードリセットは不要です。

### 認証情報を絶対に返さない

```php
private function toPublic(array $user): array
{
    // 機密フィールドを明示的に削除する
    unset($user['password_hash']);
    return $user;
}
```

すべてのレスポンスに `toPublic()` を適用します: 登録 201、ログイン 200、すべてのプロファイルエンドポイント。

---

## してはいけないこと

| アンチパターン | リスク |
|---|---|
| ログインで不明なメールに 404 を返す | ユーザー列挙: 攻撃者がどのメールが登録済みかを発見する |
| パスワード不正と不明なメールで異なる `detail` メッセージを返す | どちらの条件が失敗したかを漏洩する |
| パスワードを MD5 または SHA-1 として保存する | レインボーテーブル攻撃で数時間以内にすべてのパスワードが破られる |
| 移行パスなしで bcrypt としてパスワードを保存する | 強制リセットなしに強力なアルゴリズムにアップグレードできない |
| いかなるレスポンスでも `password_hash` を返す | ハッシュがオフラインブルートフォースに使われる |
| ログイン時に `password_needs_rehash()` をスキップする | アルゴリズムアップグレード後もレガシーな弱いハッシュが永続する |
| ハッシュの比較に `===` を使う | タイミング攻撃がハッシュバイトを明かす; 常に `password_verify()` を使う |
