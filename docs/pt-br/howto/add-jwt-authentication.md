# Adicionar Autenticação JWT

Este guia mostra como adicionar autenticação JWT Bearer a uma aplicação NENE2 —
registro de usuários, login (emissão de token) e endpoints protegidos.

**Pré-requisito**: Você tem uma aplicação NENE2 funcionando com pelo menos uma rota.
Se não, comece com [Adicionar uma rota personalizada](./add-custom-route.md).

---

## Visão geral

O NENE2 fornece o middleware e as interfaces necessárias; sua aplicação fornece a
implementação concreta do emissor JWT, a configuração do verificador e a lógica de domínio.

| Classe / Interface | Papel |
|---|---|
| `TokenIssuerInterface` | Contrato para emitir um JWT assinado |
| `TokenVerifierInterface` | Contrato para verificar um JWT e retornar as claims |
| `LocalBearerTokenVerifier` | Implementação HS256 para desenvolvimento (ambas as interfaces) |
| `BearerTokenMiddleware` | Middleware PSR-15 que exige Bearer token nas requisições |
| `RuntimeApplicationFactory` | Aceita qualquer `MiddlewareInterface` como `$authMiddleware` |

---

## Passo 1 — Configurar a variável de ambiente

Adicione `NENE2_LOCAL_JWT_SECRET` ao seu `.env`:

```dotenv
NENE2_LOCAL_JWT_SECRET=your-local-dev-secret-at-least-32-chars
```

---

## Passo 2 — Criar o domínio Auth

### 2a — Entidade User

```php
final readonly class User
{
    public function __construct(
        public int    $id,
        public string $email,
        public string $passwordHash,
    ) {}
}
```

### 2b — Interface do repositório

```php
interface UserRepositoryInterface
{
    public function findByEmail(string $email): ?User;
    public function create(string $email, string $passwordHash): User;
}
```

### 2c — UseCase Register

```php
final class RegisterUseCase
{
    public function __construct(
        private UserRepositoryInterface $users,
        private TokenIssuerInterface    $issuer,
    ) {}

    /** @return array{token: string} */
    public function execute(string $email, string $password): array
    {
        // Validação, verificação de duplicatas, hash de senha ...
        $user  = $this->users->create($email, password_hash($password, PASSWORD_BCRYPT));
        $token = $this->issuer->issue(['sub' => (string) $user->id, 'email' => $user->email]);

        return ['token' => $token];
    }
}
```

### 2d — UseCase Login

```php
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

        return ['token' => $this->issuer->issue(['sub' => (string) $user->id])];
    }
}
```

---

## Passo 3 — Integrar o middleware Bearer

Use `$excludedPaths` para proteger todas as rotas **exceto** os endpoints públicos de auth:

```php
$authMiddleware = new BearerTokenMiddleware(
    problemDetails: $problems,
    verifier:       $verifier,
    excludedPaths:  ['/auth/register', '/auth/login'],
);

$app = (new RuntimeApplicationFactory(
    $psr17,
    $psr17,
    routeRegistrars: [$authRegistrar, $taskRegistrar],
    authMiddleware:  $authMiddleware,
))->create();
```

---

## Passo 4 — Ler as claims no handler

```php
$claims = $request->getAttribute('nene2.auth.claims');
$userId = (int) ($claims['sub'] ?? 0);
```

---

## Passo 5 — Retornar 403 para recursos de outros usuários

Verificações de propriedade pertencem ao UseCase, não ao middleware:

```php
if ($task->userId !== $requestingUserId) {
    throw new AccessDeniedException();
}
```

Mapeie `AccessDeniedException` para 403 via `DomainExceptionHandlerInterface`.

---

## Para produção: usar uma biblioteca JWT

Implemente `TokenVerifierInterface` e `TokenIssuerInterface` com uma biblioteca JWT
como `firebase/php-jwt` e injete-a no lugar de `LocalBearerTokenVerifier`.

---

## Referências

- [ADR 0008 — Autenticação JWT](../adr/0008-jwt-authentication.md)
- [Adicionar rate limiting](./add-rate-limiting.md)
- Versão completa em inglês: [add-jwt-authentication.md](../../howto/add-jwt-authentication.md)
