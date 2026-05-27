# Como Fazer: Autenticação JWT

> **Referência FT**: FT261 (`NENE2-FT/jwtlog`) — Autenticação JWT com hashing de senha Argon2id e BearerTokenMiddleware
> **VULN**: FT261 — avaliação de vulnerabilidades (V-01 a V-10)

Emissão e verificação de tokens JWT Bearer com `LocalBearerTokenVerifier` e `BearerTokenMiddleware`.

---

## Início rápido

```php
use Nene2\Auth\BearerTokenMiddleware;
use Nene2\Auth\LocalBearerTokenVerifier;

$secret   = getenv('NENE2_LOCAL_JWT_SECRET') ?: throw new \RuntimeException('JWT secret not set');
$verifier = new LocalBearerTokenVerifier($secret);

// Proteger todos os caminhos exceto /auth/login
$authMiddleware = new BearerTokenMiddleware(
    problemDetails: $problems,
    verifier: $verifier,
    excludedPaths: ['/auth/login'],
);

$app = (new RuntimeApplicationFactory($psr17, $psr17, authMiddleware: $authMiddleware, ...))->create();
```

---

## Emissão de tokens

`LocalBearerTokenVerifier` implementa tanto `TokenIssuerInterface` quanto `TokenVerifierInterface` — uma instância trata ambos.

```php
$now   = time();
$token = $verifier->issue([
    'sub'   => $user->id,       // subject: identificador do usuário (int ou string)
    'email' => $user->email,    // claim customizado
    'iat'   => $now,            // issued-at (Unix timestamp — int)
    'exp'   => $now + 3600,     // expiração (Unix timestamp — int, obrigatório para expiração funcionar)
]);
```

**`exp` deve ser um Unix timestamp (int).** Passar uma string de data (`'2026-06-01'`) silenciosamente ignora a aplicação de expiração porque `LocalBearerTokenVerifier` verifica `is_int($claims['exp'])` antes de comparar.

---

## Lendo claims em um handler

`BearerTokenMiddleware` armazena claims decodificados no atributo de requisição `nene2.auth.claims` após verificação bem-sucedida:

```php
private function me(ServerRequestInterface $request): ResponseInterface
{
    /** @var array<string, mixed>|null $claims */
    $claims = $request->getAttribute('nene2.auth.claims');

    // Esta guarda null não deve acionar — o middleware já rejeitou tokens ausentes.
    // Inclua-a mesmo assim para PHPStan level 8 e clareza defensiva.
    if (!is_array($claims)) {
        return $this->problems->create($request, 'unauthorized', 'Unauthorized', 401);
    }

    return $this->json->create([
        'id'    => $claims['sub'],
        'email' => $claims['email'],
    ]);
}
```

Também disponível: `$request->getAttribute('nene2.auth.credential_type')` retorna `'bearer'`.

---

## Modos de proteção de caminho

`BearerTokenMiddleware` suporta três modos — a primeira configuração não-vazia vence:

| Configuração | Comportamento | Quando usar |
|---|---|---|
| `protectedPaths: ['/me', '/admin']` | Apenas caminhos exatos listados são protegidos | Caminhos públicos são a maioria |
| `protectedPathPrefixes: ['/api/']` | Caminhos começando com prefixo são protegidos | Protegendo uma sub-árvore inteira |
| `excludedPaths: ['/login', '/register']` | Todos os caminhos exceto os listados são protegidos | Caminhos públicos são a minoria |
| (padrão — todos os arrays vazios) | Todos os caminhos são protegidos | API totalmente privada |

```php
// ✅ /auth/login é público, todo o resto requer um token
new BearerTokenMiddleware($problems, $verifier, excludedPaths: ['/auth/login']);

// ✅ Apenas /auth/me é protegido
new BearerTokenMiddleware($problems, $verifier, protectedPaths: ['/auth/me']);

// ✅ Todos os caminhos /api/ são protegidos
new BearerTokenMiddleware($problems, $verifier, protectedPathPrefixes: ['/api/']);

// ⚠️  protectedPaths: [] NÃO é "proteger nada" — desativa o modo allowlist
//     e cai para o próximo modo (prefixos, depois blocklist, depois proteger-tudo).
```

---

## Ataque `alg: none` — já rejeitado

`LocalBearerTokenVerifier` verifica que `alg == 'HS256'` no header do token antes de verificar a assinatura. Qualquer outro algoritmo — incluindo `none` — lança `TokenVerificationException`:

```
Token algorithm must be HS256.
```

Isso previne o bypass clássico de `alg: none` onde um atacante cria um token sem header com nenhuma assinatura. Ao implementar um verificador customizado, sempre aplique o algoritmo esperado explicitamente.

---

## Respostas de erro

`BearerTokenMiddleware` retorna Problem Details 401 e automaticamente adiciona o header `WWW-Authenticate` (RFC 6750):

```
WWW-Authenticate: Bearer realm="NENE2", error="missing_token", error_description="No Bearer token was provided."
```

Valores possíveis de `error`: `missing_token` (sem header), `invalid_token` (esquema incorreto, assinatura incorreta, expirado, `nbf` no futuro, malformado).

---

## Gerenciamento de segredo

Nunca hardcode o segredo JWT. Leia-o de uma variável de ambiente:

```php
// ❌ Segredo hardcoded — commitado para controle de versão
$verifier = new LocalBearerTokenVerifier('my-secret');

// ✅ Variável de ambiente
$secret   = (string) (getenv('NENE2_LOCAL_JWT_SECRET') ?: throw new \RuntimeException('JWT secret not configured'));
$verifier = new LocalBearerTokenVerifier($secret);
```

Use um segredo aleatório forte em todos os ambientes. Para produção, use uma implementação com suporte de biblioteca (`firebase/php-jwt`, `lcobucci/jwt`) em vez de `LocalBearerTokenVerifier` — o prefixo "Local" sinaliza seu escopo.

---

## Revogação de token

JWT é stateless — não há revogação embutida. Tokens permanecem válidos até `exp`. Se você precisar de revogação imediata (ex.: logout, mudança de senha):

- Armazene uma blocklist de tokens no Redis com TTL correspondendo a `exp`
- Ou use tokens de curta duração (15 minutos) com tokens de refresh

---

## Nome do parâmetro `authMiddleware`

O parâmetro nomeado de `RuntimeApplicationFactory` é `authMiddleware:`, não `middlewares:` ou `middleware:`:

```php
// ❌ Parâmetro nomeado desconhecido $middlewares
new RuntimeApplicationFactory($psr17, $psr17, middlewares: [$authMiddleware]);

// ✅ Correto
new RuntimeApplicationFactory($psr17, $psr17, authMiddleware: $authMiddleware);
```

---

## Checklist de revisão de código

- [ ] Claim `exp` é um Unix timestamp (int), não uma string de data
- [ ] Segredo JWT é lido de uma variável de ambiente (não hardcoded)
- [ ] `LocalBearerTokenVerifier` não é usado em produção (use uma implementação de biblioteca)
- [ ] Atributo `nene2.auth.claims` é verificado para null antes do uso
- [ ] Escolha do modo `excludedPaths` / `protectedPaths` corresponde à intenção
- [ ] Resposta de token não contém `password_hash` ou outros segredos
- [ ] Header `Authorization` não é registrado em logs
- [ ] 401 é retornado para falhas de auth (não 404)

---

## Proteção contra ataques de temporização: hash fictício para enumeração de usuários

Quando um email não é encontrado, `$user === null`. Sem um hash fictício, o código pularia
`password_verify()` completamente — tornando a resposta visivelmente mais rápida para emails desconhecidos.

```php
$user = $this->repo->findByEmail(trim($body['email']));

// Sempre execute password_verify — previne enumeração de usuários baseada em temporização.
$dummyHash   = '$argon2id$v=19$m=65536,t=4,p=1$dummysaltdummysaltdummysalt$dummyhashvaluedummyhashvaluedummyh';
$hashToCheck = $user !== null ? $user->passwordHash : $dummyHash;

// ⚠️  Ordem importa: password_verify() ANTES de || $user === null
// Avaliação de curto-circuito pularia password_verify() se $user fosse verificado primeiro.
if (!password_verify($body['password'], $hashToCheck) || $user === null) {
    return 401;  // mesmo erro independente de se o email é desconhecido ou a senha está errada
}
```

---

## VULN — Avaliação de vulnerabilidades (FT261)

### V-01 — Sem proteção contra força bruta no login

**Risco**: `POST /auth/login` não tem rate limiting.

**Impacto**: Um atacante pode enviar tentativas de login ilimitadas. Argon2id é intencionalmente lento (~100ms),
mas sem rate limiting, requisições distribuídas ainda podem tentar milhares de senhas.

**Veredicto**: **EXPOSED** — adicionar `ThrottleMiddleware` em `POST /auth/login` (ex.: 5 req/min/IP).
Retornar 429 com `Retry-After`.

---

### V-02 — Força do segredo JWT depende do ambiente

**Risco**: Se `NENE2_LOCAL_JWT_SECRET` está vazio ou fraco (`secret`, `test`), tokens HMAC-HS256
podem ser forçados por brute-force ou adivinhados. Um token falsificado com claims de admin seria aceito.

**Veredicto**: **EXPOSED** — verificação de startup fail-closed:
```php
if (strlen($jwtSecret) < 32) {
    throw new \RuntimeException('NENE2_LOCAL_JWT_SECRET must be at least 32 random bytes.');
}
```

---

### V-03 — Sem revogação de token

**Risco**: JWTs emitidos permanecem válidos até `exp`. Tokens roubados, ou tokens pertencentes a
usuários deletados, permanecem aceitos por até 1 hora.

**Veredicto**: **EXPOSED** — implementar uma blocklist de tokens (ex.: `revoked_tokens(jti TEXT PK, revoked_at TEXT)`)
ou usar tokens de curta duração (15 min) com tokens de refresh.

---

### V-04 — Sem endpoint de registro de usuário

**Risco**: Nenhuma rota `POST /auth/register` existe. Usuários de teste requerem inserção direta no BD, contornando
a política de hashing de senha aplicada pela aplicação.

**Veredicto**: **DESIGN GAP** — adicionar `POST /auth/register` com validação de email e hashing Argon2id.

---

### V-05 — Case sensitivity de email: sem normalização

**Risco**: `WHERE email = ?` é case-sensitive. `USER@EXAMPLE.COM` e `user@example.com` são
lookups diferentes. Duas contas com cases diferentes podem coexistir.

**Veredicto**: **EXPOSED** — normalizar email para minúsculas (`strtolower()`) no registro e login.

---

### V-06 — TTL do token: 1 hora pode ser muito longo para APIs sensíveis

**Risco**: `TOKEN_TTL_SECONDS = 3600`. Tokens roubados permanecem válidos por até uma hora.

**Veredicto**: **DESIGN CONSIDERATION** — 1 hora é aceitável para a maioria das APIs. Para operações sensíveis,
use TTLs mais curtos (5–15 min) com tokens de refresh. Torne o TTL configurável.

---

### V-07 — `password_hash` não está nos claims JWT

**Risco**: A chamada `issue()` inclui apenas `sub`, `email`, `iat`, `exp`.

**Veredicto**: **SAFE** — claims são mínimos. Mesmo se um token for decodificado (base64, não criptografado),
nenhum dado interno sensível é exposto.

---

### V-08 — SQL injection via email

**Ataque**: `{"email": "' OR '1'='1", "password": "x"}`

**Observado**: `WHERE email = ?` é uma query parametrizada. A injeção é tratada como uma
string literal. Nenhum usuário é encontrado; 401 é retornado.

**Veredicto**: **BLOCKED** — queries parametrizadas previnem SQL injection.

---

### V-09 — Sem validação de formato de email

**Risco**: Qualquer string não-vazia é aceita como email (ex.: `"not-an-email"`).

**Impacto**: Computação Argon2id desperdiçada; usuários inválidos no BD; fluxos de reset de senha quebrados.

**Veredicto**: **EXPOSED** — adicionar `filter_var($email, FILTER_VALIDATE_EMAIL)` no registro e login.

---

### V-10 — Sem aplicação de HTTPS

**Risco**: Tokens JWT e senhas são transmitidos em texto simples via HTTP.

**Veredicto**: **EXPOSED** — aplicar HTTPS em produção. Adicionar header `Strict-Transport-Security` via
`SecurityHeadersMiddleware`.

---

## Resumo VULN

| # | Vulnerabilidade | Veredicto |
|---|---------------|---------|
| V-01 | Sem proteção contra força bruta | EXPOSED |
| V-02 | Força do segredo JWT (dependente do ambiente) | EXPOSED |
| V-03 | Sem revogação de token | EXPOSED |
| V-04 | Sem endpoint de registro | DESIGN GAP |
| V-05 | Case sensitivity de email / sem normalização | EXPOSED |
| V-06 | TTL do token 1 hora | DESIGN CONSIDERATION |
| V-07 | password_hash não nos claims JWT | SAFE |
| V-08 | SQL injection via email | BLOCKED |
| V-09 | Sem validação de formato de email | EXPOSED |
| V-10 | Sem aplicação de HTTPS | EXPOSED |

**Correções críticas antes de produção**:
1. **V-01** — `ThrottleMiddleware` em `POST /auth/login` (5 req/min/IP)
2. **V-02** — Validação fail-closed do segredo JWT na inicialização (`strlen >= 32`)
3. **V-03** — Lista de revogação de token ou TTL curto + tokens de refresh
4. **V-05** — Normalizar email para minúsculas no registro e login
5. **V-09** — `filter_var($email, FILTER_VALIDATE_EMAIL)` no registro

---

## Howtos relacionados

- [`pin-verification-lockout.md`](pin-verification-lockout.md) — bloqueio por força bruta para verificação de PIN
- [`fixed-window-rate-limiter.md`](fixed-window-rate-limiter.md) — middleware de rate limiting
- [`webhook-signature-verification.md`](webhook-signature-verification.md) — HMAC-SHA256 + comparação segura em termos de temporização
- [`mass-assignment-defence.md`](mass-assignment-defence.md) — whitelisting explícito de DTO
