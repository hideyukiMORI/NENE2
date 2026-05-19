# JWT 認証の追加

このガイドでは NENE2 アプリケーションに JWT Bearer 認証を追加する方法を示します。
ユーザー登録、ログイン（トークン発行）、保護エンドポイントの実装を扱います。

**前提条件**: 動作する NENE2 アプリケーションと少なくとも 1 つのルートが必要です。
まだない場合は [カスタムルートの追加](./add-custom-route.md) から始めてください。

---

## 概要

NENE2 は必要なミドルウェアとインターフェースを提供します。
具体的な JWT 発行・検証の実装とドメインロジックはアプリ側で用意します。

```
POST /auth/register   →  ユーザー作成、201 を返す
POST /auth/login      →  パスワード検証、JWT 発行、トークンを返す
GET  /tasks           →  保護 — Bearer トークン必須
POST /tasks           →  保護 — Bearer トークン必須
GET  /tasks/{id}      →  保護 — Bearer トークン必須
```

フレームワーク側が提供するもの:

| クラス / インターフェース | 役割 |
|---|---|
| `TokenIssuerInterface` | 署名済み JWT を発行するコントラクト |
| `TokenVerifierInterface` | JWT を検証してクレームを返すコントラクト |
| `LocalBearerTokenVerifier` | 開発用の HS256 実装（両インターフェースを実装） |
| `BearerTokenMiddleware` | Bearer トークンを強制する PSR-15 ミドルウェア |
| `RuntimeApplicationFactory` | `$authMiddleware` として任意の `MiddlewareInterface` を受け取る |

---

## ステップ 1 — 環境変数の設定

`.env` に `NENE2_LOCAL_JWT_SECRET` を追加します:

```dotenv
NENE2_LOCAL_JWT_SECRET=your-local-dev-secret-at-least-32-chars
```

本番環境では `LocalBearerTokenVerifier` をライブラリ実装に差し替えてください
（[本番向け JWT ライブラリへの切り替え](#本番向け-jwt-ライブラリへの切り替え) を参照）。

---

## ステップ 2 — Auth ドメインの作成

### 2a — User エンティティ

```php
<?php

declare(strict_types=1);

namespace MyApp\Auth;

final readonly class User
{
    public function __construct(
        public int    $id,
        public string $email,
        public string $passwordHash,
    ) {}
}
```

### 2b — リポジトリインターフェース

```php
<?php

declare(strict_types=1);

namespace MyApp\Auth;

interface UserRepositoryInterface
{
    public function findByEmail(string $email): ?User;
    public function create(string $email, string $passwordHash): User;
}
```

### 2c — Register ユースケース

```php
<?php

declare(strict_types=1);

namespace MyApp\Auth;

use Nene2\Auth\TokenIssuerInterface;
use Nene2\Validation\ValidationError;
use Nene2\Validation\ValidationException;

final class RegisterUseCase
{
    public function __construct(
        private UserRepositoryInterface $users,
        private TokenIssuerInterface    $issuer,
    ) {}

    /** @return array{token: string} */
    public function execute(string $email, string $password): array
    {
        $errors = [];

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = new ValidationError('email', '有効なメールアドレスを入力してください。', 'invalid_email');
        }

        if (strlen($password) < 8) {
            $errors[] = new ValidationError('password', '8 文字以上で入力してください。', 'too_short');
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        if ($this->users->findByEmail($email) !== null) {
            throw new ValidationException([
                new ValidationError('email', 'このメールアドレスは既に登録されています。', 'duplicate_email'),
            ]);
        }

        $user  = $this->users->create($email, password_hash($password, PASSWORD_BCRYPT));
        $token = $this->issuer->issue(['sub' => (string) $user->id, 'email' => $user->email]);

        return ['token' => $token];
    }
}
```

### 2d — Login ユースケース

```php
<?php

declare(strict_types=1);

namespace MyApp\Auth;

use Nene2\Auth\TokenIssuerInterface;
use Nene2\Validation\ValidationError;
use Nene2\Validation\ValidationException;

final class LoginUseCase
{
    public function __construct(
        private UserRepositoryInterface $users,
        private TokenIssuerInterface    $issuer,
    ) {}

    /** @return array{token: string} */
    public function execute(string $email, string $password): array
    {
        $user = $this->users->findByEmail($email);

        if ($user === null || !password_verify($password, $user->passwordHash)) {
            throw new ValidationException([
                new ValidationError('credentials', 'メールアドレスまたはパスワードが正しくありません。', 'invalid_credentials'),
            ]);
        }

        $token = $this->issuer->issue(['sub' => (string) $user->id, 'email' => $user->email]);

        return ['token' => $token];
    }
}
```

---

## ステップ 3 — HTTP ハンドラーの作成

```php
<?php

declare(strict_types=1);

namespace MyApp\Auth;

use Nene2\Routing\Router;
use Psr\Http\Message\ServerRequestInterface;

final class AuthRouteRegistrar
{
    public function __construct(
        private RegisterUseCase $register,
        private LoginUseCase    $login,
    ) {}

    public function __invoke(Router $router): void
    {
        $router->post('/auth/register', $this->handleRegister(...));
        $router->post('/auth/login',    $this->handleLogin(...));
    }

    private function handleRegister(ServerRequestInterface $request): mixed
    {
        $body     = JsonRequestBodyParser::parse($request);
        $email    = is_string($body['email'] ?? null)    ? $body['email']    : '';
        $password = is_string($body['password'] ?? null) ? $body['password'] : '';

        return $this->register->execute($email, $password);
    }

    private function handleLogin(ServerRequestInterface $request): mixed
    {
        $body     = JsonRequestBodyParser::parse($request);
        $email    = is_string($body['email'] ?? null)    ? $body['email']    : '';
        $password = is_string($body['password'] ?? null) ? $body['password'] : '';

        return $this->login->execute($email, $password);
    }
}
```

---

## ステップ 4 — Bearer ミドルウェアの組み込み

`$excludedPaths` を使って、公開エンドポイントを**除く全パスを保護**します。
保護するパスを個別に列挙する必要がなく、`/tasks/{id}` のような動的ルートも自動で保護されます。

```php
<?php

declare(strict_types=1);

use Nene2\Auth\BearerTokenMiddleware;
use Nene2\Auth\LocalBearerTokenVerifier;
use Nene2\Error\ProblemDetailsResponseFactory;
use Nene2\Http\RuntimeApplicationFactory;
use Nyholm\Psr7\Factory\Psr17Factory;

$psr17     = new Psr17Factory();
$problems  = new ProblemDetailsResponseFactory($psr17, $psr17);
$jwtSecret = (string) getenv('NENE2_LOCAL_JWT_SECRET');
$verifier  = new LocalBearerTokenVerifier($jwtSecret);
$issuer    = $verifier;  // LocalBearerTokenVerifier は両インターフェースを実装

$authMiddleware = new BearerTokenMiddleware(
    problemDetails: $problems,
    verifier:       $verifier,
    excludedPaths:  ['/auth/register', '/auth/login'],
);

$authRegistrar = new AuthRouteRegistrar(
    new RegisterUseCase($userRepository, $issuer),
    new LoginUseCase($userRepository, $issuer),
);

$app = (new RuntimeApplicationFactory(
    $psr17,
    $psr17,
    routeRegistrars: [$authRegistrar, $taskRegistrar],
    authMiddleware:  $authMiddleware,
))->create();
```

---

## ステップ 5 — 保護されたハンドラーでクレームを読む

`BearerTokenMiddleware` がトークンを検証すると、デコードされたクレームをリクエスト属性として保存します。
ハンドラー内で次のように取得します:

```php
$claims = $request->getAttribute('nene2.auth.claims');
$userId = (int) ($claims['sub'] ?? 0);
```

認証済みユーザーにスコープしたクエリを発行する例:

```php
$router->get('/tasks', function (ServerRequestInterface $request) use ($listTasks): mixed {
    $claims = $request->getAttribute('nene2.auth.claims');
    $userId = (int) ($claims['sub'] ?? 0);

    return $listTasks->execute($userId);
});
```

---

## ステップ 6 — 他ユーザーのリソースに 403 を返す

所有権チェックはミドルウェアではなくユースケースで行います:

```php
final class GetTaskUseCase
{
    public function execute(int $taskId, int $requestingUserId): Task
    {
        $task = $this->tasks->findById($taskId)
            ?? throw new TaskNotFoundException($taskId);

        if ($task->userId !== $requestingUserId) {
            throw new AccessDeniedException();
        }

        return $task;
    }
}
```

`AccessDeniedException` を 403 にマッピングするドメイン例外ハンドラー:

```php
use Nene2\Error\DomainExceptionHandlerInterface;
use Nene2\Error\ProblemDetailsResponseFactory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class AccessDeniedExceptionHandler implements DomainExceptionHandlerInterface
{
    public function supports(\Throwable $exception): bool
    {
        return $e instanceof AccessDeniedException;
    }

    public function handle(
        \Throwable $e,
        ServerRequestInterface $request,
        ProblemDetailsResponseFactory $problemDetails,
    ): ResponseInterface {
        return $problemDetails->create($request, 'forbidden', 'Forbidden', 403, 'Access denied.');
    }
}
```

`RuntimeApplicationFactory` に渡します:

```php
$app = (new RuntimeApplicationFactory(
    $psr17,
    $psr17,
    domainExceptionHandlers: [new AccessDeniedExceptionHandler()],
    authMiddleware:          $authMiddleware,
    routeRegistrars:         [$authRegistrar, $taskRegistrar],
))->create();
```

---

## リクエスト / レスポンスの例

### 登録

```http
POST /auth/register
Content-Type: application/json

{"email": "alice@example.com", "password": "s3cr3tpass"}
```

```json
{"token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9..."}
```

### ログイン

```http
POST /auth/login
Content-Type: application/json

{"email": "alice@example.com", "password": "s3cr3tpass"}
```

```json
{"token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9..."}
```

### 保護エンドポイント（トークンなし）

```http
GET /tasks
```

```json
{
  "type": "https://nene2.dev/problems/unauthorized",
  "title": "Unauthorized",
  "status": 401,
  "detail": "No Bearer token was provided."
}
```

---

## 本番向け JWT ライブラリへの切り替え

`LocalBearerTokenVerifier` は外部ライブラリを使用しない開発専用実装です。
本番環境では `TokenVerifierInterface` と `TokenIssuerInterface` を実装したクラスに差し替えます:

```php
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Nene2\Auth\TokenIssuerInterface;
use Nene2\Auth\TokenVerificationException;
use Nene2\Auth\TokenVerifierInterface;

final class FirebaseJwtService implements TokenVerifierInterface, TokenIssuerInterface
{
    public function __construct(
        private string $secret,
        private int    $ttlSeconds = 3600,
    ) {}

    public function verify(string $token): array
    {
        try {
            return (array) JWT::decode($token, new Key($this->secret, 'HS256'));
        } catch (\Exception $e) {
            throw new TokenVerificationException($e->getMessage());
        }
    }

    /** @param array<string, mixed> $claims */
    public function issue(array $claims): string
    {
        return JWT::encode(
            array_merge($claims, ['iat' => time(), 'exp' => time() + $this->ttlSeconds]),
            $this->secret,
            'HS256',
        );
    }
}
```

`TokenVerifierInterface` / `TokenIssuerInterface` を注入している箇所に差し込むだけで、
それ以外のアプリケーションコードは変更不要です。

---

## 設計参照

- [ADR 0008 — JWT 認証](../adr/0008-jwt-authentication.md)
- [ADR 0009 — 公開 API スコープ](../adr/0009-v1.0-public-api-scope.md) — 安定 Auth インターフェース
- [レート制限の追加](./add-rate-limiting.md) — 認証後のユーザー単位レート制限
