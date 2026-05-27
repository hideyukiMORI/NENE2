# ハウツー: ユーザープロフィール API

> **FT リファレンス**: FT275 (`NENE2-FT/profilelog`) — ユーザープロフィール: ユーザーごとに 1 プロフィール（UNIQUE user_id）、FILTER_VALIDATE_EMAIL でメールを検証、フィールド長制限（display_name 100 / bio 500 / avatar_url 2048）、https のみのアバター URL、DatabaseConstraintException → 409、X-User-Id による所有権ガード、32 テスト PASS。

1:1 のユーザーとプロフィールシステムを実演します: ユーザーを作成し（メール一意）、プロフィールを作成/取得/更新します。プロフィールフィールドには長さ制限と URL セキュリティ制約があります。

---

## スキーマ

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

`user_id UNIQUE` は DB レベルでユーザーごとに 1 プロフィールという不変条件を強制します。

---

## ルート

| メソッド | パス | 説明 |
|--------|------|------|
| `POST` | `/users`                   | ユーザーを作成する（メール必須、一意）      |
| `POST` | `/users/{userId}/profile`  | ユーザーのプロフィールを作成する            |
| `GET`  | `/users/{userId}/profile`  | プロフィールを取得する                      |
| `PUT`  | `/users/{userId}/profile`  | プロフィールを更新する（オーナーのみ）      |

---

## メールバリデーション

```php
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    return $this->responseFactory->create(['error' => 'valid email is required'], 422);
}
```

メールが重複した場合、`DatabaseConstraintException` をキャッチして 409 にマッピングします:

```php
try {
    $userId = $this->repo->createUser($email, $now);
} catch (DatabaseConstraintException) {
    return $this->responseFactory->create(['error' => 'email already registered'], 409);
}
```

---

## フィールド制限（UserProfile バリューオブジェクト）

```php
final readonly class UserProfile
{
    public const int MAX_BIO_LENGTH          = 500;
    public const int MAX_DISPLAY_NAME_LENGTH = 100;
    public const int MAX_AVATAR_URL_LENGTH   = 2048;
}
```

長さは `mb_strlen()` でチェックします（マルチバイト対応）:

```php
if (mb_strlen($displayName) > UserProfile::MAX_DISPLAY_NAME_LENGTH) {
    return [$displayName, $bio, $avatarUrl, 'display_name must not exceed 100 characters'];
}
```

---

## https のみのアバター URL

```php
private function isValidAvatarUrl(string $url): bool
{
    if (mb_strlen($url) > UserProfile::MAX_AVATAR_URL_LENGTH) {
        return false;
    }
    // https のみを許可して javascript: と data: URI スキームを防ぐ
    if (!str_starts_with($url, 'https://')) {
        return false;
    }
    return filter_var($url, FILTER_VALIDATE_URL) !== false;
}
```

`str_starts_with('https://')` は `filter_var` が実行される前に `javascript:`、`data:`、`http://` をブロックします。

---

## 所有権ガード

プロフィールの更新には、`X-User-Id` がプロフィールオーナーと一致することが必要です:

```php
$actorId = $this->resolveActorId($request); // X-User-Id ヘッダーから

if ($actorId !== $userId) {
    return $this->responseFactory->create(['error' => 'forbidden'], 403);
}
```

---

## やってはいけないこと

| アンチパターン | リスク |
|---|---|
| メールフォーマットのバリデーションなし | 無効なメールが保存される; ダウンストリームの送信がサイレントに失敗する |
| プロフィールの `user_id` に UNIQUE がない | 重複プロフィールが可能; GET が予測不可能な行を返す |
| `display_name` の制限に `strlen()` を使用する | マルチバイト文字（絵文字、CJK）が誤ってカウントされる |
| `http://` のアバター URL を許可する | パッシブ混在コンテンツと潜在的なクリックジャッキング面 |
| `javascript:` や `data:` URI を許可する | アバター URL が `<a href>` や `<img src>` としてレンダリングされる場合 XSS |
| `DatabaseConstraintException` のキャッチをスキップする | UNIQUE 違反が 409 ではなく 500 になる |
| 任意のユーザーが任意のプロフィールを更新できるようにする | IDOR — 書き込み前に常にアクター = オーナーを確認すること |
