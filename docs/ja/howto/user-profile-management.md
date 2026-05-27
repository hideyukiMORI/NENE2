# ユーザープロフィール管理

ユーザー向けのプロフィールデータを保存・更新します: 表示名、自己紹介、アバター URL。プロフィール作成はユーザー作成とは別です — ユーザーが先に存在し、次にプロフィールが 1 回作成されてその場で更新されます。

## 概要

プロフィール管理 API には以下が含まれます:
- **ユーザー作成** — メールベースのユーザー登録（ユーザーごとに 1 プロフィール）
- **プロフィール作成** — 初期プロフィールのセットアップ（冪等性なし: すでに存在する場合は 409）
- **プロフィール取得** — 現在のプロフィールデータを取得する
- **プロフィール更新** — プロフィールフィールドを置き換える（所有権の強制）

## データベーススキーマ

```sql
CREATE TABLE users (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    email      TEXT    NOT NULL UNIQUE,
    created_at TEXT    NOT NULL
);

CREATE TABLE profiles (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id      INTEGER NOT NULL UNIQUE,
    display_name TEXT    NOT NULL DEFAULT '',
    bio          TEXT    NOT NULL DEFAULT '',
    avatar_url   TEXT    NOT NULL DEFAULT '',
    updated_at   TEXT    NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id)
);
```

`user_id` の `UNIQUE` は DB レベルでユーザーごとに 1 プロフィールを強制します。

## 重複メールの処理

`DatabaseConstraintException` をキャッチして 500 を漏洩させずに 409 を返してください:

```php
try {
    $userId = $this->repo->createUser($email, $now);
} catch (DatabaseConstraintException) {
    return $this->responseFactory->create(['error' => 'email already registered'], 409);
}
```

このキャッチがないと、重複メールは内部エラーの詳細をクライアントに露出する未処理の例外を引き起こします。

## アバター URL のバリデーション

`javascript:`、`data:`、`file://`、`http://` スキームを防ぐために、`https://` URL のみを許可してください:

```php
private function isValidAvatarUrl(string $url): bool
{
    if (mb_strlen($url) > UserProfile::MAX_AVATAR_URL_LENGTH) {
        return false;
    }

    // https のみ — javascript:, data:, file://, ftp://, http:// をブロック
    if (!str_starts_with($url, 'https://')) {
        return false;
    }

    return filter_var($url, FILTER_VALIDATE_URL) !== false;
}
```

空文字列は許可されます（アバターなし）。`MAX_AVATAR_URL_LENGTH = 2048` の制限はストレージの乱用を防ぎます。

## フィールド長の制限

唯一の情報源のために、バリューオブジェクトの定数として制限を定義してください:

```php
final readonly class UserProfile
{
    public const int MAX_BIO_LENGTH          = 500;
    public const int MAX_DISPLAY_NAME_LENGTH = 100;
    public const int MAX_AVATAR_URL_LENGTH   = 2048;
    ...
}
```

マルチバイト（UTF-8）の正確性のために `strlen()` ではなく `mb_strlen()` を使用してください。

## 所有権チェック

`PUT /users/{userId}/profile` エンドポイントは `X-User-Id` ヘッダーを使ってリクエストアクターを識別します。本番環境では、これを JWT クレームに置き換えてください:

```php
private function resolveActorId(ServerRequestInterface $request): int
{
    $header = $request->getHeaderLine('X-User-Id');
    return is_numeric($header) ? (int) $header : 0;
}

// ハンドラー内:
if ($actorId !== $userId) {
    return $this->responseFactory->create(['error' => 'forbidden'], 403);
}
```

数値以外または欠落したヘッダーは `0` に解決され、実際のユーザー ID とは一致しません → 403。

## 重複プロフィールの防止

挿入前に既存のプロフィールを確認し、409 を返してください:

```php
if ($this->repo->findByUserId($userId) !== null) {
    return $this->responseFactory->create(['error' => 'profile already exists'], 409);
}
```

これにより、2 番目の `POST /users/{userId}/profile` が既存のプロフィールをサイレントに上書きするのを防ぎます。

## セキュリティ特性

| 特性 | 実装 |
|---|---|
| 重複メール | `DatabaseConstraintException` をキャッチ → 409（スタックトレース漏洩なし） |
| avatar_url スキーム | `str_starts_with('https://')` がすべての非 https スキームをブロック |
| avatar_url の長さ | `MAX_AVATAR_URL_LENGTH = 2048` |
| bio の長さ | `MAX_BIO_LENGTH = 500` と `mb_strlen()` |
| 所有権 | `X-User-Id` ヘッダー（本番環境では JWT クレームに置き換える） |
| ユーザーごとに 1 プロフィール | `UNIQUE (user_id)` DB 制約 + ハンドラーでの 409 チェック |

## ルートサマリー

| メソッド | パス | 説明 |
|---|---|---|
| `POST` | `/users`                     | ユーザーを登録する（メール、重複時 409）   |
| `POST` | `/users/{userId}/profile`    | プロフィールを作成する（すでに存在する場合 409） |
| `GET`  | `/users/{userId}/profile`    | プロフィールを取得する                     |
| `PUT`  | `/users/{userId}/profile`    | プロフィールを更新する（`X-User-Id` ヘッダー必要） |
