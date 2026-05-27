# Como Fazer: Padrão de Refresh Token

> **Referência FT**: FT281 (`NENE2-FT/refreshlog`) — Padrão de refresh token: token de acesso de curta duração (5 min JWT) + token de refresh de longa duração (7 dias), armazenamento por hash SHA-256, rotação de token no uso, detecção de ataque de replay (token revogado → revogar tudo), logout retorna 204 sempre, 15 testes / 63 assertivas PASS.

Este guia mostra como implementar o padrão de refresh token — tokens de acesso de curta duração para segurança, tokens de refresh para continuidade de sessão.

## Por que importa

JWTs são stateless. Uma vez emitidos, não podem ser revogados até que expirem. Um TTL de 5 minutos limita a exposição se um token for roubado. Tokens de refresh estendem sessões sem prompts repetidos de senha, e podem ser rotacionados (revogados e reemitidos) em cada uso para detectar roubo.

## Schema

```sql
CREATE TABLE IF NOT EXISTS refresh_tokens (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL REFERENCES users(id),
    token_hash TEXT    NOT NULL UNIQUE,
    expires_at TEXT    NOT NULL,
    revoked    INTEGER NOT NULL DEFAULT 0,
    created_at TEXT    NOT NULL
);
```

`token_hash` armazena o SHA-256 do token bruto — nunca o valor bruto. `revoked` é um flag de soft-delete (vs hard delete para detecção de replay).

## Endpoints

| Método | Caminho | Auth | Descrição |
|--------|---------|------|-----------|
| `POST` | `/auth/login` | Nenhuma | Email + senha → token de acesso + token de refresh |
| `POST` | `/auth/refresh` | Token de refresh no corpo | Rotacionar token de refresh, emitir novo par |
| `POST` | `/auth/logout` | Token de refresh no corpo | Revogar token de refresh |
| `GET` | `/auth/me` | Token de acesso Bearer | Obter informações do usuário atual |

## Tempos de Vida dos Tokens

```php
private const int ACCESS_TOKEN_TTL_SECONDS = 300; // 5 minutos — curto para segurança
// Tokens de refresh: 7 dias (RefreshTokenRepository::TTL_DAYS)
```

Tokens de acesso curtos limitam a exposição se roubados. Tokens de refresh longos permitem que os usuários permaneçam logados entre sessões sem reinserir senhas.

## Emitindo o Par de Tokens

```php
private function issueTokenPair(User $user): array
{
    $now         = time();
    $accessToken = $this->issuer->issue([
        'jti'   => bin2hex(random_bytes(8)),  // ID único do token — permite rastreamento futuro de revogação
        'sub'   => $user->id,
        'email' => $user->email,
        'iat'   => $now,
        'exp'   => $now + self::ACCESS_TOKEN_TTL_SECONDS,
    ]);

    $refreshToken = $this->refreshTokens->issue($user->id);

    return [
        'access_token'  => $accessToken,
        'token_type'    => 'Bearer',
        'expires_in'    => self::ACCESS_TOKEN_TTL_SECONDS,
        'refresh_token' => $refreshToken,
    ];
}
```

## Armazenando Apenas o Hash

```php
public function issue(int $userId): string
{
    $raw       = bin2hex(random_bytes(32));  // 64 chars hex = 256 bits de entropia
    $hash      = hash('sha256', $raw);
    // INSERT token_hash = $hash ...

    return $raw;  // ← retornar bruto para o cliente; nunca armazenado
}

public function findByRaw(string $raw): ?RefreshToken
{
    $hash = hash('sha256', $raw);
    // SELECT WHERE token_hash = $hash
}
```

Se o banco for violado, os atacantes obtêm hashes — inúteis sem os tokens brutos mantidos pelos clientes.

## Rotação de Token

```php
// No /auth/refresh bem-sucedido:
$this->refreshTokens->revoke($stored->id);      // revogar antigo
return $this->json->create($this->issueTokenPair($user));  // emitir novo par
```

Cada refresh rotaciona o token. O token antigo se torna inválido imediatamente, então um token de refresh roubado só pode ser usado uma vez antes de a rotação invalidá-lo.

## Detecção de Ataque de Replay

```php
$stored = $this->refreshTokens->findByRaw($body['refresh_token']);

if ($stored === null || !$stored->isValid()) {
    // Um token revogado sendo reutilizado → potencial ataque de replay
    if ($stored !== null && $stored->revoked) {
        $this->refreshTokens->revokeAllForUser($stored->userId);
    }
    return $this->problems->create($request, 'invalid-refresh-token', '...', 401);
}
```

Se um atacante rouba um token de refresh, usa-o, e o cliente legítimo então tenta usá-lo (agora revogado) — o sistema detecta isso e revoga todas as sessões do usuário, forçando reautenticação.

## Logout Sempre Retorna 204

```php
private function logout(ServerRequestInterface $request): ResponseInterface
{
    $stored = $this->refreshTokens->findByRaw($body['refresh_token']);

    if ($stored !== null && !$stored->revoked) {
        $this->refreshTokens->revoke($stored->id);
    }

    // Sempre 204 — nunca revelar se o token era válido
    return $this->json->createEmpty(204);
}
```

Retornar 401 para um token já revogado no logout permitiria a um atacante sondar se foi deslogado.

## Verificação de Validade do Token

```php
public function isValid(): bool
{
    if ($this->revoked) {
        return false;
    }
    return $this->expiresAt > (new \DateTimeImmutable())->format('Y-m-d\TH:i:s\Z');
}
```

Tanto revogação quanto expiração são verificadas. Tokens expirados mas não revogados também são rejeitados.

## Resumo de Propriedades de Segurança

| Propriedade | Implementação |
|---|---|
| TTL do token de acesso | 5 minutos (minimizar exposição por roubo) |
| TTL do token de refresh | 7 dias (continuidade de sessão) |
| Armazenamento de token | Apenas hash SHA-256; valor bruto nunca armazenado |
| Rotação de token | Token antigo revogado em cada refresh bem-sucedido |
| Detecção de replay | Reutilização de token revogado → revogar todas as sessões do usuário |
| Logout | Sempre 204 (nunca vazar validade do token) |
| Claim `jti` | Único por token (rastreamento futuro de revogação) |

---

## O Que NÃO Fazer

| Antipadrão | Risco |
|---|---|
| Armazenar token de refresh bruto no banco | Violação do banco expõe todas as sessões ativas |
| Usar hard delete na revogação | Não consegue detectar ataques de replay (precisa de `revoked = 1` para saber que o token existiu) |
| TTL longo do token de acesso (horas/dias) | Token roubado fornece acesso de longo prazo; derrota o propósito dos tokens de refresh |
| Retornar 401 no logout com token inválido | Atacante pode sondar se ainda está logado |
| Sem `jti` no token de acesso | Não consegue rastrear tokens individuais para listas futuras de revogação |
| Token único (somente acesso, sem refresh) | Usuário deve reautenticar a cada 5 minutos, ou usar TTLs perigosamente longos |
| MD5 ou SHA-1 para hash do token | Hash fraco; use SHA-256 ou melhor |
| Sem expiração nos tokens de refresh | Tokens de refresh vivem para sempre; um token roubado fornece acesso indefinido |
