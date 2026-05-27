# Como Fazer: Rotação de Refresh Token JWT

Este guia cobre a implementação de tokens de acesso de curta duração combinados com tokens de
refresh de longa duração. A propriedade chave é a **rotação**: cada uso de um token de refresh
o revoga imediatamente e emite um novo. Um token de refresh reutilizado (já revogado) aciona
a revogação de todos os tokens desse usuário.

---

## Por que dois tokens?

| Token | TTL | Armazenamento | Finalidade |
|---|---|---|---|
| Token de acesso | 5 min | Memória do cliente | Autentica requisições de API (stateless, sem consulta ao banco) |
| Token de refresh | 7 dias | Banco (hash) | Emite novos tokens de acesso; gerenciado via rotação |

Um token de acesso de curta duração limita o dano se vazar — expira em minutos. O
token de refresh estende a sessão sem exigir novo login, mas é revogável porque
vive no banco de dados.

---

## Schema

```sql
CREATE TABLE refresh_tokens (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL REFERENCES users(id),
    token_hash TEXT    NOT NULL UNIQUE,  -- hash SHA-256; nunca o valor bruto
    expires_at TEXT    NOT NULL,
    revoked    INTEGER NOT NULL DEFAULT 0,
    created_at TEXT    NOT NULL
);
```

`token_hash` — sempre armazene o hash, nunca o token bruto. Se o banco vazar,
tokens hasheados não podem ser usados diretamente.

---

## Emitindo tokens

### Token de acesso: adicionar `jti` para unicidade

Sem `jti`, dois tokens emitidos no mesmo segundo para o mesmo usuário são idênticos —
seus payloads são iguais byte a byte. `jti` (JWT ID) garante que cada token seja único
e é a base para listas de bloqueio futuras de tokens de acesso:

```php
$accessToken = $this->issuer->issue([
    'jti'   => bin2hex(random_bytes(8)),  // único por emissão
    'sub'   => $user->id,
    'email' => $user->email,
    'iat'   => time(),
    'exp'   => time() + 300,  // 5 minutos
]);
```

### Token de refresh: armazenar o hash, retornar o valor bruto

```php
public function issue(int $userId): string
{
    $raw       = bin2hex(random_bytes(32));  // token aleatório de 256 bits
    $hash      = hash('sha256', $raw);       // armazenar apenas isso
    $expiresAt = (new \DateTimeImmutable())->modify('+7 days')->format('Y-m-d\TH:i:s\Z');
    $createdAt = (new \DateTimeImmutable())->format('Y-m-d\TH:i:s\Z');

    $this->executor->insert(
        'INSERT INTO refresh_tokens (user_id, token_hash, expires_at, revoked, created_at)
         VALUES (?, ?, ?, 0, ?)',
        [$userId, $hash, $expiresAt, $createdAt],
    );

    return $raw;  // o cliente recebe isso; o banco nunca armazena
}
```

Para buscar um token de um valor fornecido pelo cliente:

```php
public function findByRaw(string $raw): ?RefreshToken
{
    $hash = hash('sha256', $raw);

    $row = $this->executor->fetchOne(
        'SELECT ... FROM refresh_tokens WHERE token_hash = ?',
        [$hash],
    );
    // ...
}
```

---

## Rotação de token

Cada requisição de refresh deve revogar o token antigo antes de emitir um novo:

```php
private function refresh(ServerRequestInterface $request): ResponseInterface
{
    // ... analisar corpo, encontrar token armazenado ...

    if ($stored === null || !$stored->isValid()) {
        // Reutilização de um token revogado é um potencial ataque de replay —
        // revogar todos os tokens do usuário para forçar reautenticação.
        if ($stored !== null && $stored->revoked) {
            $this->refreshTokens->revokeAllForUser($stored->userId);
        }

        return $this->problems->create(
            $request,
            'invalid-refresh-token',
            'Invalid or Expired Refresh Token',
            401,
            'The refresh token is invalid, expired, or has already been used.',
        );
    }

    $user = $this->users->findById($stored->userId);

    // Rotação: revogar token antigo primeiro, depois emitir novo par
    $this->refreshTokens->revoke($stored->id);

    return $this->json->create($this->issueTokenPair($user));
}
```

**Detecção de reutilização**: se um token de refresh revogado chegar ao endpoint `/auth/refresh`,
significa que o usuário está reproduzindo um token antigo (incomum) ou um atacante o roubou.
`revokeAllForUser()` força todas as sessões a reautenticar, limitando o raio de explosão.

---

## Logout: sempre retornar 204

Nunca retorne diferentes códigos de status dependendo de o token de refresh ser válido.
Fazer isso permite a um atacante sondar se um token ainda está ativo:

```php
private function logout(ServerRequestInterface $request): ResponseInterface
{
    // ... analisar corpo ...

    $stored = $this->refreshTokens->findByRaw($body['refresh_token']);

    if ($stored !== null && !$stored->revoked) {
        $this->refreshTokens->revoke($stored->id);
    }

    // Sempre 204 — nunca vazar se o token era válido ou não
    return $this->json->createEmpty(204);
}
```

Isso também significa que logout duplo (chamar logout duas vezes com o mesmo token) retorna 204
em ambas as vezes — o cliente pode sempre chamar logout com segurança sem se preocupar com o estado do token.

---

## Verificação de validade na entidade RefreshToken

```php
public function isValid(): bool
{
    if ($this->revoked) {
        return false;
    }

    return $this->expiresAt > (new \DateTimeImmutable())->format('Y-m-d\TH:i:s\Z');
}
```

A comparação de string funciona para datas ISO-8601 ordenadas lexicograficamente. Se você armazenar
timestamps como inteiros Unix, compare com `time()` em vez disso.

---

## BearerTokenMiddleware: excluir caminhos de refresh/logout

Os endpoints de refresh e logout recebem um token de refresh no corpo, não um token de acesso Bearer
no header Authorization. Exclua-os do `BearerTokenMiddleware`:

```php
$authMiddleware = new BearerTokenMiddleware(
    problemDetails: $problems,
    verifier: $verifier,
    excludedPaths: ['/auth/login', '/auth/refresh', '/auth/logout'],
);
```

O endpoint `/auth/me` (e qualquer outro caminho protegido) permanece protegido pelo middleware.

---

## Formato da resposta

```json
{
  "access_token":  "eyJhbGci...",
  "token_type":    "Bearer",
  "expires_in":    300,
  "refresh_token": "a3f92c..."
}
```

`expires_in` (segundos) permite que o cliente agende um refresh proativo antes que o token de acesso
expire, evitando uma requisição falha seguida de um refresh.

---

## Checklist de revisão de código

1. A coluna `token_hash` armazena `hash('sha256', $raw)` — nunca o valor bruto
2. `revoke()` é chamado antes de `issueTokenPair()` no handler de refresh
3. Reutilização de token revogado aciona `revokeAllForUser()` (não apenas um 401)
4. Logout sempre retorna 204 — sem 401/404 condicional
5. TTL do token de acesso é curto (≤ 15 minutos)
6. Claim `jti` está presente nos tokens de acesso
7. Os testes cobrem rotação cruzada de tokens (token antigo inválido após refresh) e detecção de reutilização

---

## Testando rotação e detecção de reutilização

```php
public function testRefreshTokenRotation_OldTokenIsInvalidAfterRefresh(): void
{
    $tokens = $this->login();

    $this->post('/auth/refresh', ['refresh_token' => $tokens['refresh_token']]);

    // O token antigo deve ser rejeitado
    $res = $this->post('/auth/refresh', ['refresh_token' => $tokens['refresh_token']]);
    $this->assertSame(401, $res->getStatusCode());
}

public function testRefreshTokenReuseRevokesAllUserTokens(): void
{
    $tokens = $this->login();

    // Rotacionar uma vez — o token antigo agora está revogado
    $newTokens = $this->json($this->post('/auth/refresh', ['refresh_token' => $tokens['refresh_token']]));

    // Atacante reproduz o token antigo (revogado) — aciona revokeAllForUser()
    $this->post('/auth/refresh', ['refresh_token' => $tokens['refresh_token']]);

    // O token de refresh recém-emitido agora também está revogado
    $res = $this->post('/auth/refresh', ['refresh_token' => (string) $newTokens['refresh_token']]);
    $this->assertSame(401, $res->getStatusCode());
}
```

---

## Veja também

- `docs/howto/jwt-authentication.md` — emissão JWT, BearerTokenMiddleware, `nene2.auth.claims`
- `docs/howto/password-hashing.md` — Argon2id, padrão de hash dummy para prevenção de enumeração de usuário
