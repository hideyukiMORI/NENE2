# マス代入防御

マス代入とは、攻撃者がリクエストボディに `role=admin` や `is_active=false` などの余分なフィールドを追加し、サーバーが意図せずそれらを永続化してしまう脆弱性です。

NENE2 には誤って発生しやすくする `create($body)` マジックメソッドはありません。それでも、DTO ホワイトリストパターンが正しく明示的な防御手段です。

## 脆弱性

```php
// ❌ 危険: $body が直接 INSERT に渡される
$body = json_decode((string) $request->getBody(), true);

$this->executor->insert(
    'INSERT INTO users (name, email, role, is_active) VALUES (?, ?, ?, ?)',
    [$body['name'], $body['email'], $body['role'] ?? 'user', $body['is_active'] ?? 1],
);
```

攻撃者が送信します:

```json
{
  "name": "Attacker",
  "email": "attacker@example.com",
  "role": "admin"
}
```

`$body['role']` がリクエストから読み取られるため、攻撃者はデータベースに `role=admin` を取得します。

## 防御: 明示的 DTO ホワイトリスト

ユーザーが提供を許可されているフィールドのみを含む DTO を定義してください:

```php
/**
 * ユーザー入力から受け付けるのは name と email のみ。
 * role と is_active はサーバーサイドのロジックで設定され、リクエストからは設定されない。
 */
final readonly class CreateUserInput
{
    public function __construct(
        public string $name,
        public string $email,
    ) {}
}
```

コントローラーでは、許可されたフィールドのみを DTO にマップしてください:

```php
// ✅ 余分なフィールド（role、is_active、id、created_at）は $body から決して読み取らない
$input = new CreateUserInput(
    name:  trim((string) $body['name']),
    email: strtolower(trim((string) $body['email'])),
);

$user = $this->repo->create($input);
```

リポジトリでは、DTO プロパティを直接使用してください:

```php
public function create(CreateUserInput $input): User
{
    $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
    $id = $this->executor->insert(
        'INSERT INTO users (name, email, role, is_active, created_at) VALUES (?, ?, ?, ?, ?)',
        [$input->name, $input->email, 'user', 1, $now], // role と is_active はハードコード
    );
    // ...
}
```

攻撃者が `role=admin` を送信しても、`$input` には `name` と `email` のみがあります — 余分なフィールドは INSERT に到達しません。

## 対象攻撃シナリオ

| フィールド | 攻撃意図 | 防御 |
|---------|--------|------|
| `role=admin` | 権限昇格 | `role` は `CreateUserInput` にない; リポジトリで常に `'user'` に設定 |
| `is_active=false` | 無効なアカウントを作成またはユーザーをロック | `is_active` は DTO にない; 常に `1` に設定 |
| `id=9999` | 主キーを上書き | `id` は DTO にない; SQLite によって自動割り当て |
| `created_at=2000-01-01` | 監査タイムスタンプを偽造 | `created_at` は DTO にない; 常に現在時刻に設定 |

## レスポンスフィールド制御

防御はレスポンスにも及びます: DB 行を直接返さないでください。含めるものを明示的にマップしてください:

```php
return $this->json->create([
    'id'         => $user->id,
    'name'       => $user->name,
    'email'      => $user->email,
    'role'       => $user->role,
    'is_active'  => $user->isActive,
    'created_at' => $user->createdAt,
    // password_hash は意図的に除外
    // deleted_at は意図的に除外
], 201);
```

機密フィールドの不在をテストしてください:

```php
$this->assertArrayNotHasKey('password_hash', $data);
$this->assertArrayNotHasKey('deleted_at', $data);
```

## 信頼された内部サービス

内部サービスが管理者ユーザーを作成する必要がある場合（例: プロビジョニングサービス）、別の DTO を使用してください:

```php
final readonly class AdminCreateUserInput
{
    public function __construct(
        public string $name,
        public string $email,
        public string $role,   // 内部呼び出し元のみに許可
        public bool $isActive,
    ) {}
}
```

この DTO は呼び出し元のアイデンティティを既に確認したコードパス（例: マシン API キー、内部サービス認証）からのみ呼び出してください。`AdminCreateUserInput` を直接受け付ける公開 HTTP エンドポイントを公開しないでください。

## レスポンスの `create()` vs `createList()`

リストを返す場合は `create()` の代わりに `createList()` を使用してください:

```php
// ✅ トップレベルの JSON 配列
return $this->json->createList(array_map(fn (User $u) => [...], $users));

// ✅ トップレベルの JSON オブジェクト
return $this->json->create(['id' => $user->id, ...], 201);
```

`create()` は `array<string, mixed>`（オブジェクト）を期待します。`array_map()` の出力を直接 `create()` に渡すと、`array_map` が `list<T>` を返すため PHPStan level 8 の型エラーが発生します。

## コードレビューチェックリスト

- [ ] リクエストボディフィールドはリポジトリに渡される前に DTO にマップされている
- [ ] DTO にはユーザーが提供を許可されているフィールドのみが含まれている
- [ ] サーバー制御フィールド（`role`、`is_active`、タイムスタンプ、主キー）はリポジトリで設定され、`$body` から読み取られない
- [ ] レスポンスは返されるフィールドを明示的にリストする; ワイルドカード `SELECT *` や行を直接 JSON にシリアライズしない
- [ ] テストが余分なリクエストフィールドが無視され、永続化された値に影響しないことを確認している
