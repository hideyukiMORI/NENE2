# ハウツー: パスワードハッシュ

NENE2 で PHP のネイティブ `password_hash()` / `password_verify()` を使ってパスワードを安全に保存・検証します。

---

## クイックスタート

```php
// 登録 — 保存前にハッシュ化
$hash = password_hash($password, PASSWORD_ARGON2ID);
$user = $this->repo->create($email, $hash);

// ログイン — 定数時間での検証
if (!password_verify($inputPassword, $user->passwordHash)) {
    // 401 を返す
}
```

---

## アルゴリズム: 常に `PASSWORD_ARGON2ID` を使う

PHP 8.4 の時点でも `PASSWORD_DEFAULT` は `bcrypt` です。Argon2id はメモリハードであり、GPU/ASIC 攻撃に耐性があります。

```php
// ❌ PASSWORD_DEFAULT = bcrypt — GPU ブルートフォースに対してより脆弱
$hash = password_hash($password, PASSWORD_DEFAULT);

// ✅ Argon2id — メモリハード、新規プロジェクトに推奨
$hash = password_hash($password, PASSWORD_ARGON2ID);
```

Argon2id は PHP 7.3+ が必要です。NENE2 は PHP 8.4 を要求するため、常に利用可能です。

---

## UNIQUE 違反の検出: `DatabaseConstraintException`

NENE2 の `PdoDatabaseQueryExecutor` はすべての制約違反（UNIQUE、FK、NOT NULL）を `DatabaseConstraintException` にラップして再スローします。`\PDOException` を直接キャッチしても**動作しません**。

```php
use Nene2\Database\DatabaseConstraintException;

// ❌ ここには到達しない — PDOException は既にラップされている
catch (\PDOException $e) {
    if (str_contains($e->getMessage(), 'UNIQUE constraint failed')) { ... }
}

// ✅ NENE2 ラッパーをキャッチする
catch (DatabaseConstraintException) {
    throw new DuplicateEmailException($email);
}
```

`DatabaseConstraintException` は安定した公開 API の一部です（ADR 0009）。

完全なリポジトリパターン:

```php
use Nene2\Database\DatabaseConstraintException;
use Nene2\Database\DatabaseQueryExecutorInterface;

final class UserRepository
{
    public function __construct(private readonly DatabaseQueryExecutorInterface $executor) {}

    /** @throws DuplicateEmailException */
    public function create(string $email, string $passwordHash): User
    {
        try {
            $now = (new \DateTimeImmutable())->format('Y-m-d\TH:i:s\Z');
            $id  = $this->executor->insert(
                'INSERT INTO users (email, password_hash, created_at) VALUES (?, ?, ?)',
                [$email, $passwordHash, $now],
            );

            return new User(id: $id, email: $email, passwordHash: $passwordHash, createdAt: $now);
        } catch (DatabaseConstraintException) {
            throw new DuplicateEmailException($email);
        }
    }
}
```

---

## ユーザー列挙防止（タイミング攻撃）

メールが見つからない場合に即座に 401 を返すと、タイミング差によってメールが存在するかどうかが明かされます — 見つからない場合は即座に返り、パスワード不正の場合は Argon2id の計算時間全体がかかります。

```php
// ❌ タイミング漏洩 — 見つからない場合が測定可能なほど速い
if ($user === null) {
    return $this->problems->create($request, 'invalid-credentials', ...);
}
if (!password_verify($password, $user->passwordHash)) {
    return $this->problems->create($request, 'invalid-credentials', ...);
}

// ✅ 常に password_verify を実行 — ユーザーが存在するかどうかに関係なく定数時間
$dummyHash   = '$argon2id$v=19$m=65536,t=4,p=1$dummysaltdummysaltdummysalt$dummyhashvaluedummyhashvaluedummyh';
$hashToCheck = $user !== null ? $user->passwordHash : $dummyHash;

if (!password_verify($password, $hashToCheck) || $user === null) {
    return $this->problems->create($request, 'invalid-credentials', 'Invalid Credentials', 401,
        'The email or password is incorrect.');
}
```

ダミーハッシュは `$argon2id$` で始まる有効な Argon2id 形式文字列**でなければなりません**。そうでない場合、`password_verify()` はショートサーキットして即座に `false` を返し、タイミング漏洩が再発します。

---

## `password_verify()` はアルゴリズム非依存

`password_verify()` はハッシュプレフィックスを読み取ってアルゴリズムを判定します。bcrypt から Argon2id に移行するときに検証コードを変更する必要はありません。

```php
// bcrypt と Argon2id のハッシュの両方で動作する
$result = password_verify($plaintext, $storedHash); // 常に正確
```

ログイン成功時に `password_needs_rehash()` を使ってレガシーハッシュを透明にアップグレードしてください:

```php
if (password_verify($password, $user->passwordHash)) {
    if (password_needs_rehash($user->passwordHash, PASSWORD_ARGON2ID)) {
        $newHash = password_hash($password, PASSWORD_ARGON2ID);
        $this->repo->updatePasswordHash($user->id, $newHash);
    }
    // 認証済みユーザーで続行する
}
```

---

## レスポンスに `password_hash` を絶対に含めない

`toArray()` や類似のヘルパーはすべてのカラムを含む場合があります。返すつもりのフィールドのみを明示的にリストしてください。

```php
// ❌ $user が toArray() メソッドを持つ場合に password_hash が漏洩する可能性
return $this->json->create($user->toArray(), 201);

// ✅ 明示的なフィールドリスト — password_hash は絶対に存在しない
return $this->json->create([
    'id'         => $user->id,
    'email'      => $user->email,
    'created_at' => $user->createdAt,
], 201);
```

---

## `RouteRegistrar::register()` の名前の競合

NENE2 の `RouteRegistrar` コントラクトはパブリックな `register(Router $router)` メソッドを要求します。ルートハンドラーを `register()` と命名**しないでください** — PHP は重複したメソッド名を拒否します。

```php
// ❌ 致命的エラー: RouteRegistrar::register() を再宣言できない
$router->post('/register', $this->register(...));
private function register(...) { ... }

// ✅ 区別できるハンドラー名を使う
$router->post('/register', $this->handleRegister(...));
private function handleRegister(...) { ... }
```

---

## コードレビューチェックリスト

- [ ] `PASSWORD_ARGON2ID` で `password_hash()` を使う（MD5、SHA-1、bcrypt、または `PASSWORD_DEFAULT` ではない）
- [ ] 比較に `password_verify()` を使う（`===`、`hash_equals()`、またはカスタム比較ではない）
- [ ] ユーザーが見つからない場合でも `password_verify()` が実行される（ダミーハッシュパターン）
- [ ] 重複メール/ユーザー名の検出に `DatabaseConstraintException` がキャッチされる
- [ ] `password_hash` / `password` フィールドがすべての API レスポンスから除外される
- [ ] ログインは不明なメールに 401（404 ではない）を返す — メールが存在するかどうかを絶対に明かさない
- [ ] 平文パスワードがログに書き込まれない
