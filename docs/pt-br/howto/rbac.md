# Como Fazer: Controle de Acesso Baseado em Papel (RBAC)

Implementando controle de acesso baseado em papel com claims JWT e `BearerTokenMiddleware`.

---

## Início rápido

```php
// 1. Incluir papel no JWT no login
$token = $issuer->issue([
    'sub'  => $user->id,
    'role' => $user->role->value,  // 'user' ou 'admin'
    'exp'  => time() + 3600,
]);

// 2. Verificar o papel no handler
/** @var array<string, mixed>|null $claims */
$claims     = $request->getAttribute('nene2.auth.claims');
$actualRole = Role::tryFrom((string) ($claims['role'] ?? ''));

if ($actualRole !== Role::Admin) {
    return $this->problems->create($request, 'forbidden', 'Forbidden', 403,
        "This action requires the 'admin' role.");
}
```

---

## Embutindo papéis nos claims JWT

**Duas abordagens:**

| Abordagem | Prós | Contras |
|---|---|---|
| Papel nos claims JWT | Sem consulta ao banco por requisição | Mudanças de papel só têm efeito após a expiração do token |
| Consulta ao banco por requisição | Mudanças de papel imediatas | Consulta extra em cada requisição autenticada |

Para a maioria das aplicações, a abordagem JWT é adequada. Para contextos de alta segurança (médico, financeiro, revogação de privilégio admin) adicione uma consulta ao banco em operações sensíveis.

```php
// Login — embutir papel nos claims
$token = $issuer->issue([
    'sub'   => $user->id,
    'email' => $user->email,
    'role'  => $user->role->value,   // string: 'user' | 'admin'
    'iat'   => time(),
    'exp'   => time() + 3600,
]);
```

---

## 401 Unauthorized vs 403 Forbidden

Esta distinção importa para o tratamento de erros do cliente (401 → redirecionar para login, 403 → mostrar erro de permissão):

| Situação | Status |
|---|---|
| Sem token / expirado / assinatura inválida | **401** Unauthorized |
| Token válido, mas papel insuficiente | **403** Forbidden |
| Recurso não encontrado | **404** Not Found |

```php
// ❌ Errado — usuário autenticado recebe 401 (implica "não logado")
if ($role !== Role::Admin) {
    return $this->problems->create($request, 'unauthorized', 'Unauthorized', 401, '...');
}

// ✅ Correto — autenticado mas sem permissão
if ($role !== Role::Admin) {
    return $this->problems->create($request, 'forbidden', 'Forbidden', 403,
        "This action requires the 'admin' role.");
}
```

---

## Padrão `requireAuth()` / `requireRole()`

Um par de helpers reutilizáveis no registrador de rotas:

```php
use Nene2\Auth\TokenVerificationException;
use Nene2\Auth\TokenVerifierInterface;

final class RouteRegistrar
{
    public function __construct(
        private readonly TokenVerifierInterface $verifier,
        // ... outras dependências
    ) {}

    /**
     * Retorna os claims em caso de sucesso ou uma ResponseInterface 401.
     * Verifica primeiro o atributo do middleware; faz fallback para verificação manual
     * para caminhos excluídos do BearerTokenMiddleware.
     *
     * @return array<string, mixed>|ResponseInterface
     */
    private function requireAuth(ServerRequestInterface $request): array|ResponseInterface
    {
        /** @var array<string, mixed>|null $claims */
        $claims = $request->getAttribute('nene2.auth.claims');

        if (is_array($claims)) {
            return $claims;
        }

        $authorization = $request->getHeaderLine('Authorization');

        if ($authorization === '' || !str_starts_with($authorization, 'Bearer ')) {
            return $this->problems->create($request, 'unauthorized', 'Unauthorized', 401,
                'Authentication required.');
        }

        try {
            return $this->verifier->verify(substr($authorization, 7));
        } catch (TokenVerificationException) {
            return $this->problems->create($request, 'unauthorized', 'Unauthorized', 401,
                'Token is invalid or expired.');
        }
    }

    /**
     * Retorna os claims se o usuário tem o papel necessário, ou uma ResponseInterface 401/403.
     *
     * @return array<string, mixed>|ResponseInterface
     */
    private function requireRole(ServerRequestInterface $request, Role $required): array|ResponseInterface
    {
        $claims = $this->requireAuth($request);

        if ($claims instanceof ResponseInterface) {
            return $claims;
        }

        $actualRole = Role::tryFrom((string) ($claims['role'] ?? ''));

        if ($actualRole !== $required) {
            return $this->problems->create($request, 'forbidden', 'Forbidden', 403,
                "This action requires the '{$required->value}' role.");
        }

        return $claims;
    }
}
```

Uso nos handlers:

```php
private function deletePost(ServerRequestInterface $request): ResponseInterface
{
    $claims = $this->requireRole($request, Role::Admin);
    if ($claims instanceof ResponseInterface) {
        return $claims;  // 401 ou 403
    }
    // $claims agora é o payload JWT de um admin verificado
}
```

---

## `BearerTokenMiddleware` não diferencia por método HTTP

`BearerTokenMiddleware` usa o caminho da requisição, não o método HTTP, para decidir se a autenticação é necessária. Quando `GET /posts` (público) e `POST /posts` (autenticação necessária) compartilham o mesmo caminho, exclua `/posts` do middleware e verifique o token manualmente no handler:

```php
// Middleware: exclui /posts completamente (cobre tanto GET quanto POST)
$auth = new BearerTokenMiddleware($problems, $verifier, excludedPaths: ['/auth/login', '/posts']);

// Para DELETE /posts/{id} (caminho /posts/1, /posts/2 etc.) — NÃO está em excludedPaths → middleware protege.
// Para POST /posts (caminho /posts) — excluído → handler deve chamar requireAuth() manualmente.
```

O helper `requireAuth()` acima trata isso de forma transparente: ele lê `nene2.auth.claims` do atributo do middleware se presente, e faz fallback para análise do header `Authorization` diretamente se não.

**Alternativa**: use prefixos de caminho distintos para evitar a ambiguidade completamente:
- `GET /public/posts` — sem autenticação
- `POST /posts` — autenticação necessária (middleware pode proteger `/posts` sem conflito)

---

## Padrão do enum `Role`

Use um enum backed para tratamento de papel com tipo seguro:

```php
enum Role: string
{
    case User  = 'user';
    case Admin = 'admin';
}

// ❌ Role::from() lança em valores desconhecidos
$role = Role::from($claims['role']);  // UnhandledMatchError se 'superuser' ou ''

// ✅ Role::tryFrom() retorna null para valores desconhecidos
$role = Role::tryFrom((string) ($claims['role'] ?? ''));
if ($role === null || $role !== Role::Admin) {
    return 403;
}
```

---

## 204 No Content — use `createEmpty()`

`JsonResponseFactory::create()` requer um argumento `array`. Para respostas 204 sem corpo, use `createEmpty()`:

```php
// ❌ Erro de tipo — create() não aceita null
return $this->json->create(null, 204);

// ❌ Retorna objeto JSON vazio {} (o corpo deve estar ausente para 204)
return $this->json->create([], 204);

// ✅ Correto — sem corpo, status correto
return $this->json->createEmpty(204);
```

---

## Checklist de revisão de código

- [ ] O claim `role` é decodificado com `Role::tryFrom()` (não `Role::from()` — lança em valores desconhecidos)
- [ ] 403 é retornado para permissões insuficientes, 401 para não autenticado (não ambos como 401)
- [ ] `requireRole()` também chama `requireAuth()` — sem verificações de auth duplicadas necessárias
- [ ] A exclusão do `BearerTokenMiddleware` é entendida: caminhos excluídos ignoram o atributo de claims
- [ ] Handlers em caminhos excluídos chamam `requireAuth()` com verificação manual de token
- [ ] Respostas 204 usam `createEmpty(204)` não `create(null, 204)`
- [ ] O cache de papel no JWT é entendido: mudanças de papel só têm efeito após a expiração do token
