# Autenticação sem Senha (Magic Link)

Guia de implementação de autenticação sem senha (Magic Link). Explica os padrões de design seguros para um sistema de links de uso único que autentica apenas com endereço de e-mail.

## Visão Geral

A autenticação por Magic Link funciona com o seguinte fluxo:

1. O usuário envia seu endereço de e-mail
2. O servidor gera um token de uso único (Magic Link) e o envia por e-mail
3. O usuário envia o token e recebe um token de sessão
4. O token de sessão é usado para acessar a API

## Endpoints

| Método | Caminho | Descrição |
|---|---|---|
| `POST` | `/auth/request` | Apresentar e-mail → gerar Magic Link (sempre 202) |
| `POST` | `/auth/verify` | Verificar token Magic Link → emitir token de sessão |
| `POST` | `/auth/logout` | Invalidar sessão (sempre 204) |
| `GET` | `/me` | Obter informações do usuário autenticado |

## Design do Banco de Dados

```sql
CREATE TABLE users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    email TEXT NOT NULL UNIQUE,
    created_at TEXT NOT NULL
);

CREATE TABLE magic_links (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    token_hash TEXT NOT NULL UNIQUE,  -- Armazena hash SHA-256 (valor bruto nunca armazenado)
    expires_at TEXT NOT NULL,          -- Expiração de 15 minutos
    used_at TEXT,                      -- Uso único (NULL = não usado)
    created_at TEXT NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

CREATE TABLE auth_sessions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    session_token_hash TEXT NOT NULL UNIQUE,  -- Armazena hash SHA-256
    expires_at TEXT NOT NULL,                  -- Expiração de 24 horas
    revoked_at TEXT,                           -- Definido no logout
    created_at TEXT NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id)
);
```

## Design de Segurança

### Token armazenado com hash SHA-256

```php
// Geração: aleatório de 256 bits → string hex (64 caracteres)
$rawToken = bin2hex(random_bytes(32));
$tokenHash = hash('sha256', $rawToken);

// Apenas tokenHash é armazenado no banco
// Apenas rawToken é enviado por e-mail (segurança em caso de vazamento do banco)
```

Mesmo que o banco seja comprometido, o hash não pode ser revertido para o token. O mesmo princípio do hash de senha.

### Prevenção de enumeração de usuário

```php
// POST /auth/request sempre retorna 202
// Não muda a resposta para e-mails registrados vs não registrados
return $this->responseFactory->create([
    'message' => 'if this email is registered, a magic link has been sent',
], 202);
```

O atacante não consegue verificar a validade de endereços de e-mail.

### Verificação de expiração antes de used_at

```php
// Verificar expiração primeiro
if ($now > (string) $link['expires_at']) {
    return $this->responseFactory->create(['error' => 'token has expired'], 401);
}

// Depois verificar used_at
if ($link['used_at'] !== null) {
    return $this->responseFactory->create(['error' => 'token has already been used'], 401);
}
```

Previne que um token expirado revele se foi "usado" (evita vazamento de informação de timing).

### Uso único (prevenção de ataque de repetição)

```php
// Definir used_at imediatamente após verificação bem-sucedida
$this->repository->markMagicLinkUsed($linkId, $now);
```

O mesmo Magic Link não pode ser usado duas vezes. Previne reutilização de links interceptados.

### Invalidação de sessão (logout)

```php
// logout sempre retorna 204 — não vaza existência da sessão
if ($session !== null && $session['revoked_at'] === null) {
    $this->repository->revokeSession((int) $session['id'], date('c'));
}
return $this->responseFactory->createEmpty(204);
```

Em `/me`, retorna 401 se `revoked_at !== null`.

## Fluxo de Validação de Sessão

```php
private function handleMe(ServerRequestInterface $request): ResponseInterface
{
    $rawToken = $this->extractBearerToken($request);
    if ($rawToken === '') {
        return $this->responseFactory->create(['error' => 'authentication required'], 401);
    }

    $tokenHash = hash('sha256', $rawToken);
    $session = $this->repository->findSessionByTokenHash($tokenHash);

    if ($session === null) { return 401; }
    if ($session['revoked_at'] !== null) { return 401 revoked; }
    if ($now > $session['expires_at']) { return 401 expired; }

    // ...
}
```

## Extração do Token Bearer

```php
private function extractBearerToken(ServerRequestInterface $request): string
{
    $header = $request->getHeaderLine('Authorization');
    if (!str_starts_with($header, 'Bearer ')) {
        return '';
    }
    return trim(substr($header, 7));
}
```

Não usar header `X-User-Id` para autenticação. Apenas `Authorization: Bearer <token>`.

## Auto-Criação de Novo Usuário

```php
public function findOrCreateUser(string $email, string $now): int
{
    $user = $this->findUserByEmail($email);
    if ($user !== null) {
        return (int) $user['id'];
    }
    $this->executor->execute('INSERT INTO users (email, created_at) VALUES (?, ?)', [$email, $now]);
    return (int) $this->executor->lastInsertId();
}
```

Cria automaticamente o usuário no primeiro login. Característica da autenticação sem senha.

## Expiração do Magic Link

- **Magic Link**: 15 minutos (900 segundos) — tempo para abrir o e-mail e clicar
- **Token de Sessão**: 24 horas (86400 segundos) — sessão normal de API

```php
$expiresAt = date('c', time() + 900);    // magic link: 15 min
$sessionExpiresAt = date('c', time() + 86400);  // sessão: 24h
```

## Considerações para Produção

- **Envio de e-mail**: Este FT inclui o `token` na resposta (para fins de teste).
  Em produção, envie via SMTP para o e-mail do usuário e remova da resposta.
- **Rate limiting**: Aplique rate limit nas requisições para `/auth/request` por IP / e-mail.
- **Invalidação de links antigos não usados**: Ao chamar `/auth/request` várias vezes com o mesmo e-mail,
  considere invalidar explicitamente links antigos não usados.
- **HTTPS obrigatório**: Como os tokens Magic Link são incluídos em parâmetros de URL,
  HTTPS é obrigatório (prevenção de ataques man-in-the-middle).
