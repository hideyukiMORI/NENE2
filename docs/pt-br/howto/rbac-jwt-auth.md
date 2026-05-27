# Como Fazer: RBAC + Autenticação JWT

> **Referência FT**: FT279 (`NENE2-FT/rbaclog`) — Controle de Acesso Baseado em Papel com JWT: hashing de senha Argon2id com proteção contra ataques de timing, claim de papel no JWT, distinção 401 vs 403, BearerTokenMiddleware com fallback manual, 14 testes / 48 assertivas PASS.
>
> **Avaliação VULN**: V-01 a V-10 incluídos no final deste documento.

Este guia mostra como construir um sistema de controle de acesso baseado em papel (RBAC) usando tokens JWT com o NENE2.

## Funcionalidades

- Login com email + senha (hashing Argon2id)
- Claim de papel embutido no JWT (`user` / `admin`)
- Endpoints públicos, autenticados e somente para admin
- `BearerTokenMiddleware` com fallback por handler
- Semântica correta de `401 Unauthorized` vs `403 Forbidden`

## Schema

```sql
CREATE TABLE IF NOT EXISTS users (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    email         TEXT NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    role          TEXT NOT NULL DEFAULT 'user',
    created_at    TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS posts (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    title      TEXT NOT NULL,
    body       TEXT NOT NULL,
    author_id  INTEGER NOT NULL,
    created_at TEXT NOT NULL
);
```

## Endpoints

| Método | Caminho | Auth | Descrição |
|--------|---------|------|-----------|
| `POST` | `/auth/login` | Nenhuma | Login, receber JWT |
| `GET` | `/posts` | Nenhuma | Listar todos os posts (público) |
| `POST` | `/posts` | Usuário ou Admin | Criar um post |
| `DELETE` | `/posts/{id}` | Apenas Admin | Excluir um post |

## Login com Proteção contra Ataque de Timing

O truque do hash dummy garante que o login sempre leve o mesmo tempo, independentemente de o email existir:

```php
$user = $this->users->findByEmail(trim($body['email']));

$dummyHash   = '$argon2id$v=19$m=65536,t=4,p=1$dummysaltdummysaltdummysalt$dummyhashvaluedummyhashvaluedummyh';
$hashToCheck = $user !== null ? $user->passwordHash : $dummyHash;

if (!password_verify($body['password'], $hashToCheck) || $user === null) {
    return $this->problems->create($request, 'invalid-credentials', 'Invalid Credentials', 401, '...');
}
```

Sem o hash dummy, um ataque de timing pode detectar endereços de email válidos medindo o tempo de resposta — o cálculo do hash é pulado para emails desconhecidos.

## Claim de Papel no JWT

O papel é armazenado no payload do JWT para evitar uma consulta ao banco em cada requisição:

```php
$token = $this->issuer->issue([
    'sub'   => $user->id,
    'email' => $user->email,
    'role'  => $user->role->value,   // Role::User → 'user', Role::Admin → 'admin'
    'iat'   => $now,
    'exp'   => $now + self::TOKEN_TTL_SECONDS,
]);
```

## Verificação de Papel com Enum

```php
private function requireRole(ServerRequestInterface $request, Role $required): array|ResponseInterface
{
    $claims = $this->requireAuth($request);
    if ($claims instanceof ResponseInterface) {
        return $claims;
    }

    $actualRole = Role::tryFrom((string) ($claims['role'] ?? ''));

    if ($actualRole !== $required) {
        return $this->problems->create(
            $request, 'forbidden', 'Forbidden', 403,
            "This action requires the '{$required->value}' role."
        );
    }

    return $claims;
}
```

`Role::tryFrom()` mapeia com segurança o claim de string de volta para o enum — strings de papel inválidas se tornam `null`, o que falha na verificação.

## Distinção entre 401 e 403

| Status | Significado | Quando |
|--------|-------------|--------|
| `401 Unauthorized` | Não autenticado | Sem token, token inválido, token expirado |
| `403 Forbidden` | Autenticado, mas papel insuficiente | Token válido, papel errado |

Esta distinção importa para os clientes: um `401` deve solicitar um novo login; um `403` deve mostrar uma mensagem de "acesso negado".

## BearerTokenMiddleware com Fallback

Alguns caminhos servem métodos tanto públicos quanto protegidos (ex.: `GET /posts` é público, `POST /posts` é autenticado). O middleware exclui o caminho completamente, e handlers que requerem autenticação chamam `requireAuth()` manualmente:

```php
$authMiddleware = new BearerTokenMiddleware(
    problemDetails: $problems,
    verifier: $this->verifier,
    excludedPaths: ['/auth/login', '/posts'],  // /posts precisa de tratamento por método
);
```

```php
private function requireAuth(ServerRequestInterface $request): array|ResponseInterface
{
    // Caminho rápido: middleware já verificou
    $claims = $request->getAttribute('nene2.auth.claims');
    if (is_array($claims)) {
        return $claims;
    }

    // Caminho lento: extração manual para caminhos excluídos
    $authorization = $request->getHeaderLine('Authorization');
    if ($authorization === '' || !str_starts_with($authorization, 'Bearer ')) {
        return $this->problems->create($request, 'unauthorized', 'Unauthorized', 401, '...');
    }

    try {
        return $this->verifier->verify(substr($authorization, 7));
    } catch (TokenVerificationException) {
        return $this->problems->create($request, 'unauthorized', 'Unauthorized', 401, '...');
    }
}
```

---

## Avaliação VULN — Diagnóstico de Vulnerabilidade

### V-01 — Elevação de papel via claim JWT forjado 🛡️ SAFE

**Ameaça**: Atacante cria um JWT com `"role": "admin"` e o assina com um segredo aleatório.
**Defesa**: `LocalBearerTokenVerifier` valida a assinatura HMAC-HS256 contra o segredo do servidor. Um segredo incompatível causa `TokenVerificationException` → 401.
**Resultado**: SAFE — a verificação de assinatura impede a falsificação de claim.

---

### V-02 — Ataque de timing via enumeração de email no login 🛡️ SAFE

**Ameaça**: Atacante envia requisições de login para emails desconhecidos vs conhecidos e mede o tempo de resposta para enumerar contas válidas.
**Defesa**: Para emails desconhecidos, `password_verify()` é chamado contra um hash Argon2id dummy (mesmos parâmetros de custo). Ambos os caminhos levam ~200ms. A mensagem de falha de login é idêntica para email errado e senha errada.
**Resultado**: SAFE — o timing é equalizado; a mensagem de erro é genérica.

---

### V-03 — Token expirado aceito como válido 🛡️ SAFE

**Ameaça**: Atacante reutiliza um JWT capturado após sua expiração.
**Defesa**: `LocalBearerTokenVerifier` verifica o claim `exp` contra `time()`. Tokens expirados lançam `TokenVerificationException` → 401.
**Resultado**: SAFE — a verificação de `exp` é aplicada.

---

### V-04 — Downgrade de papel modificando payload JWT (sem re-assinar) 🛡️ SAFE

**Ameaça**: Atacante decodifica o payload JWT em base64, muda `"role": "user"` para `"role": "admin"`, recodifica e envia com a assinatura original.
**Defesa**: A assinatura JWT cobre o header + payload. Modificar o payload invalida a assinatura → `TokenVerificationException` → 401.
**Resultado**: SAFE — adulteração do payload detectada pelo HMAC.

---

### V-05 — Endpoint admin acessível com papel de usuário 🛡️ SAFE

**Ameaça**: Atacante faz login como `user` e tenta `DELETE /posts/{id}`.
**Defesa**: `requireRole($request, Role::Admin)` verifica o claim `role` do JWT. Um token `user` tem `role: 'user'` → `Role::tryFrom('user') !== Role::Admin` → 403.
**Resultado**: SAFE — 403 Forbidden retornado; token de usuário não pode elevar para admin.

---

### V-06 — Acesso não autenticado a endpoint protegido 🛡️ SAFE

**Ameaça**: Atacante envia `POST /posts` ou `DELETE /posts/{id}` sem header Authorization.
**Defesa**: `requireAuth()` verifica o prefixo `Bearer `; header ausente → 401 `unauthorized`.
**Resultado**: SAFE — 401 Unauthorized retornado.

---

### V-07 — Confusão de 401 vs 403 (vazamento de informação) 🛡️ SAFE

**Ameaça**: Uso incorreto de 401/403 revela se um recurso existe ou se o usuário está autenticado.
**Defesa**: O sistema retorna 401 para acesso não autenticado (sem/token inválido) e 403 para acesso autenticado com papel insuficiente. A distinção é semanticamente correta e não revela a existência do recurso além do requisito de papel.
**Resultado**: SAFE — semântica de 401/403 está correta; testes `test401MeansNotAuthenticated` e `test403MeansAuthenticatedButForbidden` ambos passam.

---

### V-08 — Bypass via string de papel inválida no JWT 🛡️ SAFE

**Ameaça**: Atacante forja um JWT (com segredo válido, ex.: cenário de segredo comprometido) e define `role` para um valor desconhecido como `"superadmin"`.
**Defesa**: `Role::tryFrom((string) ($claims['role'] ?? ''))` retorna `null` para strings desconhecidas → `null !== Role::Admin` → 403.
**Resultado**: SAFE — `tryFrom()` é null-safe; papéis desconhecidos são tratados como insuficientes.

---

### V-09 — Injeção SQL via campo email no login 🛡️ SAFE

**Ameaça**: Atacante envia `{"email": "' OR '1'='1", "password": "anything"}`.
**Defesa**: `findByEmail()` usa query parametrizada (`WHERE email = ?`). A string injetada é tratada como um valor literal, não SQL.
**Resultado**: SAFE — queries parametrizadas impedem injeção SQL.

---

### V-10 — Senha armazenada em texto simples 🛡️ SAFE

**Ameaça**: Se o banco for violado, as senhas são legíveis.
**Defesa**: `password_hash($password, PASSWORD_ARGON2ID)` com parâmetros de custo `m=65536,t=4,p=1`. Apenas o hash Argon2id é armazenado; a senha em texto simples nunca é persistida.
**Resultado**: SAFE — Argon2id é o algoritmo recomendado atualmente (RFC 9106); PBKDF2/bcrypt/scrypt também passariam.

---

### Resumo VULN

| ID | Ameaça | Resultado |
|----|--------|-----------|
| V-01 | Elevação de papel via JWT forjado | 🛡️ SAFE |
| V-02 | Ataque de timing via enumeração de email | 🛡️ SAFE |
| V-03 | Token expirado aceito | 🛡️ SAFE |
| V-04 | Adulteração do payload JWT sem re-assinar | 🛡️ SAFE |
| V-05 | Endpoint admin com token de papel de usuário | 🛡️ SAFE |
| V-06 | Acesso não autenticado a endpoint protegido | 🛡️ SAFE |
| V-07 | Confusão de 401 vs 403 | 🛡️ SAFE |
| V-08 | Bypass via string de papel desconhecida | 🛡️ SAFE |
| V-09 | Injeção SQL via campo email | 🛡️ SAFE |
| V-10 | Senha armazenada em texto simples | 🛡️ SAFE |

**10 SAFE, 0 EXPOSED**
Hashing Argon2id, JWT assinado com HMAC, guarda `Role::tryFrom()` e queries parametrizadas impedem todos os vetores de vulnerabilidade testados.

---

## O Que NÃO Fazer

| Antipadrão | Risco |
|---|---|
| Armazenar papel no banco e consultar em cada requisição | Consulta extra ao banco por requisição; mudança de papel requer lógica de revogação de token |
| Usar `Role::from()` em vez de `Role::tryFrom()` | Strings de papel desconhecidas lançam `ValueError` — 500 em vez de 403 |
| Retornar 403 para requisições não autenticadas | Engana os clientes — 403 deve significar "autenticado mas proibido", não "não logado" |
| Retornar 401 para acesso com papel errado | Cliente pode tentar re-login em vez de mostrar "acesso negado" |
| Pular hash dummy no login | Ataque de timing revela endereços de email válidos |
| Armazenar senhas como MD5/SHA1/texto simples | Ataques de força bruta ou rainbow table expõem todas as senhas em uma violação do banco |
| Embutir permissões no JWT (não papéis) | Mudanças no conjunto de permissões requerem reemissão de token; papéis são estáveis, permissões mudam |
| Permitir JWT com `alg: none` | Atacante pode forjar tokens removendo a assinatura completamente |
| Usar `str_contains($role, 'admin')` em vez de verificação de enum | `"not-admin"` ou `"superadmin"` podem corresponder inesperadamente |
