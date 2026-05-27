# Como Fazer: Autenticação por Magic Link

> **Referência FT**: FT309 (`NENE2-FT/magiclog`) — Autenticação por magic link: token armazenado como hash SHA-256 (nunca em texto simples), TTL de 15 minutos, used-at previne reutilização, expiração verificada antes de used-at, token de sessão com 64+ chars hex armazenado como SHA-256, sessões revogadas/expiradas negadas, 202 sempre em /auth/request (prevenção de enumeração de usuário), Bearer token obrigatório (header X-User-Id ignorado), VULN-A〜L todos SAFE, 43 testes / 91 asserções PASS.

Este guia mostra como construir um sistema de autenticação sem senha via magic-link onde a segurança depende de entropia do token, armazenamento de hash, TTL curto e reforço de uso único.

## Schema

```sql
CREATE TABLE users (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    email      TEXT    NOT NULL UNIQUE,
    created_at TEXT    NOT NULL
);

CREATE TABLE magic_links (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    token_hash TEXT    NOT NULL UNIQUE,   -- SHA-256(raw_token)
    expires_at TEXT    NOT NULL,          -- now + 15 minutos
    used_at    TEXT,                      -- definido na primeira verificação bem-sucedida
    created_at TEXT    NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

CREATE TABLE auth_sessions (
    id                 INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id            INTEGER NOT NULL,
    session_token_hash TEXT    NOT NULL UNIQUE,   -- SHA-256(raw_token)
    expires_at         TEXT    NOT NULL,
    revoked_at         TEXT,
    created_at         TEXT    NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id)
);
```

Tanto `magic_links.token_hash` quanto `auth_sessions.session_token_hash` armazenam hashes SHA-256. Tokens brutos nunca são armazenados.

## Endpoints

| Método | Caminho | Auth | Descrição |
|--------|------|------|-------------|
| `POST` | `/auth/request` | — | Solicitar magic link (sempre 202) |
| `POST` | `/auth/verify` | — | Verificar token → sessão |
| `POST` | `/auth/logout` | `Bearer` | Revogar sessão |
| `GET` | `/me` | `Bearer` | Obter usuário atual |

## Geração e Hashing de Token

```php
// Gerar 64 chars hex (256 bits de entropia)
$rawToken   = bin2hex(random_bytes(32));
$tokenHash  = hash('sha256', $rawToken);
$expiresAt  = (new \DateTimeImmutable())->modify('+15 minutes')->format('c');

$this->repo->createMagicLink($userId, $tokenHash, $expiresAt);

// Retornar token bruto ao chamador (enviado por email ao usuário)
return ['token' => $rawToken];
```

O token bruto é retornado na resposta (para ser enviado como parâmetro URL no email). Apenas o hash SHA-256 é armazenado. `UNIQUE(token_hash)` previne colisões de hash.

## Token de Sessão

```php
$rawSessionToken  = bin2hex(random_bytes(32)); // 64 chars hex
$sessionTokenHash = hash('sha256', $rawSessionToken);
$sessionExpiry    = (new \DateTimeImmutable())->modify('+24 hours')->format('c');

$this->repo->createSession($userId, $sessionTokenHash, $sessionExpiry);

return ['session_token' => $rawSessionToken]; // retornado uma vez, depois apenas hash
```

Token de sessão: 64 caracteres hex = 256 bits de entropia. Armazenado como hash SHA-256. Mínimo de 64 chars reforçado pela fonte de entropia (`bin2hex(random_bytes(32))`).

## Verificar — Ordem das Verificações Importa

```php
// 1. Encontrar por hash
$magicLink = $this->repo->findByTokenHash(hash('sha256', $token));
if ($magicLink === null) {
    return 401; // não encontrado
}

// 2. Verificar expiração PRIMEIRO
if ($magicLink['expires_at'] < date('c')) {
    return 401; // 'expired' na mensagem de erro
}

// 3. Verificar used_at SEGUNDO
if ($magicLink['used_at'] !== null) {
    return 401; // 'already been used' na mensagem de erro
}

// 4. Marcar como usado
$this->repo->markUsed($magicLink['id'], date('c'));

// 5. Criar sessão
```

Expiração é verificada **antes** de `used_at`. Se um token está expirado e já foi usado, o erro diz "expired" — não "already been used". Isso previne ataques de temporização onde um atacante sonda se um token foi usado.

## Prevenção de Enumeração de Usuário — Sempre 202

```php
public function handleRequest(ServerRequestInterface $request): ResponseInterface
{
    $email = $body['email'] ?? '';
    
    $user = $this->repo->findUserByEmail($email);
    if ($user !== null) {
        // Criar magic link e (em produção) enviar email
        $rawToken = bin2hex(random_bytes(32));
        $this->repo->createMagicLink($user['id'], hash('sha256', $rawToken), ...);
    }
    // Sempre retornar 202 independente de o email existir
    return $this->json->create(['message' => 'If registered, a magic link has been sent.'], 202);
}
```

Endereços de email inexistentes retornam a mesma resposta 202 que os válidos. Nenhuma mensagem "email not found" é retornada.

## Validação de Sessão

```php
$token = substr($authHeader, 7); // remover 'Bearer '
$tokenHash = hash('sha256', $token);
$session = $this->repo->findSessionByHash($tokenHash);

if ($session === null) {
    return 401; // sessão não encontrada
}
if ($session['revoked_at'] !== null) {
    return 401; // 'revoked'
}
if ($session['expires_at'] < date('c')) {
    return 401; // 'expired'
}
```

Três verificações de sessão: existência → revogada → expirada. Sessões revogadas pelo logout retornam "revoked" — distinto de "expired" para mensagens de erro claras.

## Header X-User-Id Ignorado para Auth

O endpoint `/me` requer um token de sessão `Bearer` válido. O header `X-User-Id` (usado por outros endpoints como auth conveniente) é explicitamente ignorado aqui:

```php
// Apenas autenticação por Bearer token — X-User-Id não é aceito
$authHeader = $request->getHeaderLine('Authorization');
if (!str_starts_with($authHeader, 'Bearer ')) {
    return 401;
}
```

---

## Avaliação de Vulnerabilidades

### V-01 — Token expirado rejeitado antes da verificação de used ✅ SAFE

**Risco**: Token expirado mas ainda não usado; atacante tenta usá-lo e recebe erro "already used" revelando ordem das verificações.
**Descoberta**: SAFE — expiração é verificada primeiro. Tokens expirados+usados retornam "expired".

---

### V-02 — Token de sessão armazenado como hash ✅ SAFE

**Risco**: Violação do BD revela tokens de sessão.
**Descoberta**: SAFE — `session_token_hash = SHA-256(raw_token)`. Token bruto não está no BD.

---

### V-03 — Magic link usado não pode ser reutilizado ✅ SAFE

**Risco**: Atacante captura a URL do magic link e a usa após o usuário pretendido.
**Descoberta**: SAFE — `used_at` é definido no primeiro uso; segunda tentativa retorna 401 "already been used".

---

### V-04 — Logout invalida sessão ✅ SAFE

**Risco**: Cookie/token de sessão ainda funciona após logout.
**Descoberta**: SAFE — logout define `revoked_at`; `/me` subsequente com o token retorna 401 "revoked".

---

### V-05 — Email inexistente retorna 202 ✅ SAFE

**Risco**: Atacante verifica quais emails estão registrados observando diferentes respostas de erro.
**Descoberta**: SAFE — `/auth/request` sempre retorna 202 com o mesmo corpo. Sem vazamento de "not found".

---

### V-06 — Sessão revogada negada ✅ SAFE

**Risco**: Sessão manualmente revogada ainda concede acesso.
**Descoberta**: SAFE — verificação de `revoked_at` nega acesso; mensagem de erro diz "revoked".

---

### V-07 — Sessão expirada negada ✅ SAFE

**Risco**: Sessão antiga de muito tempo atrás ainda funciona.
**Descoberta**: SAFE — verificação de `expires_at` nega acesso; mensagem de erro diz "expired".

---

### V-08 — Token de magic link armazenado como hash ✅ SAFE

**Risco**: Violação do BD revela tokens de magic link; atacante autentica como qualquer usuário.
**Descoberta**: SAFE — `token_hash = SHA-256(raw_token)`. Token bruto não está no BD.

---

### V-09 — Magic link expira em 15 minutos ✅ SAFE

**Risco**: Magic link de longa duração permite interceptação atrasada e replay.
**Descoberta**: SAFE — TTL ≤ 900 segundos (15 minutos) confirmado por teste.

---

### V-10 — Sessão tem expiração ✅ SAFE

**Risco**: Sessão nunca expira; tokens antigos permanecem válidos indefinidamente.
**Descoberta**: SAFE — `expires_at` é definido no futuro na criação da sessão; confirmado não-nulo.

---

### V-11 — Token de sessão tem entropia suficiente ✅ SAFE

**Risco**: Token de sessão curto pode ser forçado por brute-force.
**Descoberta**: SAFE — `bin2hex(random_bytes(32))` = 64 chars hex = 256 bits de entropia.

---

### V-12 — Header X-User-Id não pode contornar auth ✅ SAFE

**Risco**: Header `X-User-Id: 1` concede acesso a `/me` sem sessão válida.
**Descoberta**: SAFE — `/me` requer `Authorization: Bearer <token>`. X-User-Id é ignorado.

---

### Resumo VULN

| ID | Vulnerabilidade | Descoberta |
|----|---------------|---------|
| V-01 | Ordem de verificação expiração vs used | ✅ SAFE |
| V-02 | Token de sessão em texto simples no BD | ✅ SAFE |
| V-03 | Reutilização de magic link usado | ✅ SAFE |
| V-04 | Sessão válida após logout | ✅ SAFE |
| V-05 | Enumeração de email | ✅ SAFE |
| V-06 | Acesso por sessão revogada | ✅ SAFE |
| V-07 | Acesso por sessão expirada | ✅ SAFE |
| V-08 | Token de magic link no BD | ✅ SAFE |
| V-09 | TTL de magic link > 15 min | ✅ SAFE |
| V-10 | Sem expiração de sessão | ✅ SAFE |
| V-11 | Baixa entropia do token de sessão | ✅ SAFE |
| V-12 | Bypass por X-User-Id | ✅ SAFE |

**12 SAFE, 0 EXPOSED**

---

## O Que NÃO Fazer

| Anti-padrão | Risco |
|---|---|
| Armazenar token de magic link bruto no BD | Violação do BD permite atacante autenticar como qualquer usuário |
| Armazenar token de sessão bruto no BD | Violação do BD invalida todas as sessões |
| Verificar used_at antes de expires_at | Vazamento de temporização revela se o token foi usado |
| Retornar erro para email inexistente | Atacante enumera emails registrados |
| Sem TTL em magic links | Tokens válidos indefinidamente; ataque de interceptação atrasada |
| Sem expiração de sessão | Sessões permanecem válidas indefinidamente |
| Aceitar X-User-Id para auth Bearer | Bypass de auth baseado em header sem token |
| Tokens de baixa entropia (`rand()` ou 8 chars) | Tokens passíveis de brute-force |
| Reutilizar o mesmo magic link para múltiplas sessões | Exposição de um único token concede todas as sessões subsequentes |
