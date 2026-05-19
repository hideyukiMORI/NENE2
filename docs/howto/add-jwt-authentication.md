# Add JWT Authentication

This guide shows how to add JWT Bearer authentication to a NENE2 application — user
registration, login (token issuance), and protected endpoints.

**Prerequisite**: You have a working NENE2 application with at least one route. If not,
start with [Add a custom route](./add-custom-route.md).

---

## Overview

NENE2 ships the middleware and interfaces you need; your application provides the
concrete JWT issuer, verifier wiring, and domain logic.

```
POST /auth/register   →  create user, return 201
POST /auth/login      →  verify password, issue JWT, return token
GET  /tasks           →  protected — requires Bearer token
POST /tasks           →  protected — requires Bearer token
GET  /tasks/{id}      →  protected — requires Bearer token
```

The framework pieces:

| Class / Interface | Role |
|---|---|
| `TokenIssuerInterface` | Contract for issuing a signed JWT |
| `TokenVerifierInterface` | Contract for verifying a JWT and returning claims |
| `LocalBearerTokenVerifier` | Dev-only HS256 implementation of both interfaces |
| `BearerTokenMiddleware` | PSR-15 middleware that enforces Bearer token on requests |
| `RuntimeApplicationFactory` | Accepts any `MiddlewareInterface` as `$authMiddleware` |

---

## Step 1 — Wire the environment variable

Add `NENE2_LOCAL_JWT_SECRET` to your `.env`:

```dotenv
NENE2_LOCAL_JWT_SECRET=your-local-dev-secret-at-least-32-chars
```

For production replace `LocalBearerTokenVerifier` with a library-backed implementation
(see [Swap to a production JWT library](#swap-to-a-production-jwt-library) below).

---

## Step 2 — Create the Auth domain

### 2a — User entity

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

### 2b — Repository interface

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

### 2c — Register use case

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
            $errors[] = new ValidationError('email', 'Must be a valid email address.', 'invalid_email');
        }

        if (strlen($password) < 8) {
            $errors[] = new ValidationError('password', 'Must be at least 8 characters.', 'too_short');
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        if ($this->users->findByEmail($email) !== null) {
            throw new ValidationException([
                new ValidationError('email', 'Email is already registered.', 'duplicate_email'),
            ]);
        }

        $user  = $this->users->create($email, password_hash($password, PASSWORD_BCRYPT));
        $token = $this->issuer->issue(['sub' => (string) $user->id, 'email' => $user->email]);

        return ['token' => $token];
    }
}
```

### 2d — Login use case

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
                new ValidationError('credentials', 'Invalid email or password.', 'invalid_credentials'),
            ]);
        }

        $token = $this->issuer->issue(['sub' => (string) $user->id, 'email' => $user->email]);

        return ['token' => $token];
    }
}
```

---

## Step 3 — Create the HTTP handler

```php
<?php

declare(strict_types=1);

namespace MyApp\Auth;

use Nene2\Routing\Router;
use Nene2\Validation\ValidationError;
use Nene2\Validation\ValidationException;
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
        $body     = (array) $request->getParsedBody();
        $email    = is_string($body['email'] ?? null)    ? $body['email']    : '';
        $password = is_string($body['password'] ?? null) ? $body['password'] : '';

        return $this->register->execute($email, $password);
    }

    private function handleLogin(ServerRequestInterface $request): mixed
    {
        $body     = (array) $request->getParsedBody();
        $email    = is_string($body['email'] ?? null)    ? $body['email']    : '';
        $password = is_string($body['password'] ?? null) ? $body['password'] : '';

        return $this->login->execute($email, $password);
    }
}
```

> The router handler can return an array — NENE2 serialises it to JSON automatically.

---

## Step 4 — Wire the Bearer middleware

Use `$excludedPaths` to protect all routes **except** the public auth endpoints.
This avoids the need to enumerate every protected path individually.

```php
<?php

declare(strict_types=1);

use Nene2\Auth\BearerTokenMiddleware;
use Nene2\Auth\LocalBearerTokenVerifier;
use Nene2\Error\ProblemDetailsResponseFactory;
use Nene2\Http\RuntimeApplicationFactory;
use Nyholm\Psr7\Factory\Psr17Factory;

$psr17      = new Psr17Factory();
$problems   = new ProblemDetailsResponseFactory($psr17, $psr17);
$jwtSecret  = (string) getenv('NENE2_LOCAL_JWT_SECRET');
$verifier   = new LocalBearerTokenVerifier($jwtSecret);
$issuer     = $verifier;  // LocalBearerTokenVerifier implements both interfaces

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
    routeRegistrars:  [$authRegistrar, $taskRegistrar],
    authMiddleware:   $authMiddleware,
))->create();
```

---

## Step 5 — Read claims in a protected handler

After `BearerTokenMiddleware` verifies the token it stores the decoded claims as a
request attribute. Read them in your handler:

```php
$claims = $request->getAttribute('nene2.auth.claims');
$userId = (int) ($claims['sub'] ?? 0);
```

Use the `sub` claim to scope queries to the authenticated user:

```php
$router->get('/tasks', function (ServerRequestInterface $request) use ($listTasks): mixed {
    $claims = $request->getAttribute('nene2.auth.claims');
    $userId = (int) ($claims['sub'] ?? 0);

    return $listTasks->execute($userId);
});
```

---

## Step 6 — Return 403 for other users' resources

Ownership checks belong in the use case, not the middleware:

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

Map `AccessDeniedException` to 403 with a domain exception handler:

```php
use Nene2\Error\DomainExceptionHandlerInterface;
use Nene2\Error\ProblemDetailsResponseFactory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class AccessDeniedExceptionHandler implements DomainExceptionHandlerInterface
{
    public function handles(\Throwable $e): bool
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

Pass it to `RuntimeApplicationFactory`:

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

## Request / response examples

### Register

```http
POST /auth/register
Content-Type: application/json

{"email": "alice@example.com", "password": "s3cr3tpass"}
```

```json
{"token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9..."}
```

### Login

```http
POST /auth/login
Content-Type: application/json

{"email": "alice@example.com", "password": "s3cr3tpass"}
```

```json
{"token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9..."}
```

### Protected endpoint

```http
GET /tasks
Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...
```

Without a valid token:

```json
{
  "type": "https://nene2.dev/problems/unauthorized",
  "title": "Unauthorized",
  "status": 401,
  "detail": "No Bearer token was provided."
}
```

---

## Swap to a production JWT library

`LocalBearerTokenVerifier` uses no external library and is intentionally limited to
local development. For production, implement `TokenVerifierInterface` and
`TokenIssuerInterface` backed by a JWT library:

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

Inject it wherever `TokenVerifierInterface` / `TokenIssuerInterface` are required — no
other application code changes.

---

## Design reference

- [ADR 0008 — JWT Authentication](../adr/0008-jwt-authentication.md)
- [ADR 0009 — Public API scope](../adr/0009-v1.0-public-api-scope.md) — stable Auth interfaces
- [Add rate limiting](./add-rate-limiting.md) — per-user rate limits after auth
