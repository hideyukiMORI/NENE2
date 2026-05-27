# Como Fazer: Sistema de Autenticação OTP

> **Referência FT**: FT290 (`NENE2-FT/otplog`) — Autenticação OTP: código numérico de 6 dígitos com armazenamento de hash SHA-256, lockout por força bruta (3 tentativas → 10 min), TTL do OTP (5 min), prevenção de ataque de repetição via `used_at`, token de sessão com SHA-256 + revogação, prevenção de enumeração de usuário via endpoint always-202, ATK-01~12 PASS, 35 testes / 44 assertivas PASS.

Este guia mostra como construir um sistema de autenticação OTP (One-Time Password) sem senha onde usuários recebem um código de 6 dígitos e o trocam por um token de sessão.

## Schema

```sql
CREATE TABLE users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    email TEXT NOT NULL UNIQUE,
    created_at TEXT NOT NULL
);

CREATE TABLE otp_codes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    code_hash TEXT NOT NULL,
    expires_at TEXT NOT NULL,
    used_at TEXT,
    attempt_count INTEGER NOT NULL DEFAULT 0,
    locked_until TEXT,
    created_at TEXT NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

CREATE TABLE otp_sessions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    session_token_hash TEXT NOT NULL UNIQUE,
    expires_at TEXT NOT NULL,
    revoked_at TEXT,
    created_at TEXT NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id)
);
```

Pontos principais de design:
- `code_hash` armazena o SHA-256 do OTP, nunca o código bruto.
- `attempt_count` + `locked_until` implementam lockout por força bruta por linha de OTP.
- `used_at` previne ataques de repetição (OTP só pode ser usado uma vez).
- `session_token_hash` armazena o SHA-256 do token de sessão; `UNIQUE` previne colisões.
- `revoked_at` permite logout explícito sem excluir a linha.

## Endpoints

| Método | Caminho | Auth | Descrição |
|--------|---------|------|-----------|
| `POST` | `/otp/request` | nenhuma | Solicitar OTP (cria usuário se necessário) |
| `POST` | `/otp/verify` | nenhuma | Verificar OTP, receber token de sessão |
| `GET` | `/otp/session` | `Bearer <token>` | Obter informações da sessão |
| `DELETE` | `/otp/session` | `Bearer <token>` | Logout (revogar sessão) |

## Geração de OTP — Nunca Armazene o Código Bruto

```php
private const int MAX_ATTEMPTS = 3;
private const int OTP_TTL_MINUTES = 5;
private const int LOCK_MINUTES = 10;
private const int SESSION_TTL_HOURS = 24;

$rawCode = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
$codeHash = hash('sha256', $rawCode);
$this->repository->createOtp($userId, $codeHash, $now);
```

`str_pad` garante zeros à esquerda (ex.: `random_int(0, 999999)` retornando `42` → `'000042'`). O código bruto é enviado ao e-mail do usuário; apenas o hash é armazenado. `random_int()` é criptograficamente seguro.

## Prevenção de Enumeração de Usuário — Sempre 202

```php
// Sempre 202 — previne enumeração de usuário
// Em produção: enviar e-mail. Neste FT retornamos o código para teste.
return $this->responseFactory->create([
    'message' => 'OTP code sent',
    'code' => $rawCode,  // remover em produção
], 202);
```

Independente de o e-mail existir ou não, a resposta é sempre `202 Accepted`. Um atacante não consegue distinguir "conta existe" de "conta não existe".

## Auto-Criação de Usuário na Primeira Solicitação

```php
public function findOrCreateUser(string $email, string $now): int
{
    $user = $this->findUserByEmail($email);
    if ($user !== null) {
        return (int) $user['id'];
    }
    return $this->executor->insert(
        'INSERT INTO users (email, created_at) VALUES (?, ?)',
        [$email, $now]
    );
}
```

Os usuários são criados implicitamente na primeira solicitação de OTP — nenhuma etapa de registro separada é necessária. A restrição `UNIQUE(email)` previne duplicatas em inserções concorrentes.

## Verificação de OTP — Verificações Ordenadas

```php
// 1. Verificação de lockout (primeiro — antes de qualquer comparação de código)
if ($otp['locked_until'] !== null && $now < (string) $otp['locked_until']) {
    return $this->responseFactory->create(['error' => 'too many attempts, try again later'], 429);
}

// 2. Verificação de expiração
if ($now > (string) $otp['expires_at']) {
    return $this->responseFactory->create(['error' => 'code expired'], 401);
}

// 3. Verificação de já usado
if ($otp['used_at'] !== null) {
    return $this->responseFactory->create(['error' => 'code already used'], 401);
}

// 4. Verificação do código com hash_equals (seguro contra timing)
$codeHash = hash('sha256', $code);
if (!hash_equals((string) $otp['code_hash'], $codeHash)) {
    $this->repository->incrementAttempt((int) $otp['id'], $now);
    return $this->responseFactory->create(['error' => 'invalid code'], 401);
}
```

A ordem das verificações importa: lockout → expiração → usado → código. Incremente `attempt_count` apenas em código errado — não em lockout ou expiração.

## Lockout por Força Bruta

```php
public function incrementAttempt(int $otpId, string $now): void
{
    $otp = $this->executor->fetchOne('SELECT * FROM otp_codes WHERE id = ?', [$otpId]);
    if ($otp === null) {
        return;
    }
    $newCount = (int) $otp['attempt_count'] + 1;
    $lockedUntil = null;
    if ($newCount >= self::MAX_ATTEMPTS) {
        $lockedUntil = date('c', strtotime($now) + self::LOCK_MINUTES * 60);
    }
    $this->executor->execute(
        'UPDATE otp_codes SET attempt_count = ?, locked_until = ? WHERE id = ?',
        [$newCount, $lockedUntil, $otpId]
    );
}
```

Após `MAX_ATTEMPTS` (3) códigos errados, `locked_until` é definido 10 minutos no futuro. A verificação de lockout acontece antes de qualquer comparação de código, então tentativas durante o lockout não redefinem o temporizador.

## Apenas o OTP Mais Recente — Nova Solicitação Substitui a Anterior

```php
public function findLatestOtpForUser(int $userId): ?array
{
    return $this->executor->fetchOne(
        'SELECT * FROM otp_codes WHERE user_id = ? ORDER BY id DESC LIMIT 1',
        [$userId]
    );
}
```

Múltiplas solicitações de OTP criam múltiplas linhas, mas apenas a mais recente é usada para verificação. OTPs antigos são efetivamente invalidados — submetê-los retorna 401.

## Token de Sessão — SHA-256 + Revogação

```php
// Emitir token de sessão
$rawToken = bin2hex(random_bytes(32));   // entropia de 256 bits, 64 chars hex
$tokenHash = hash('sha256', $rawToken);
$this->repository->createSession((int) $user['id'], $tokenHash, $now);

return $this->responseFactory->create([
    'session_token' => $rawToken,
    'user_id' => (int) $user['id'],
], 200);
```

Apenas o hash SHA-256 é armazenado. Se o banco for comprometido, os tokens brutos nunca são expostos.

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

Uma string vazia após `Bearer ` (ex.: `Authorization: Bearer `) é tratada como ausente — retorna 401.

## Logout — Sucesso Silencioso

```php
$session = $this->repository->findSessionByTokenHash($tokenHash);
if ($session !== null && $session['revoked_at'] === null) {
    $this->repository->revokeSession($tokenHash, date('c'));
}

return $this->responseFactory->create(['message' => 'logged out'], 200);
```

O logout sempre retorna 200 — não revela se o token era válido. Isso previne que atacantes sondiem a validade do token pelo endpoint de logout.

---

## Avaliação ATK — Teste de Ataque com Mentalidade de Cracker

### ATK-01 — Força Bruta do OTP 🚫 BLOCKED

**Ataque**: Testar todas as combinações `000000`–`999999` sequencialmente.
**Resultado**: BLOCKED — após `MAX_ATTEMPTS` (3) códigos errados, `locked_until` é definido 10 minutos no futuro. Tentativas subsequentes retornam 429 até o lockout expirar.

---

### ATK-02 — Ataque de Repetição (reutilizar OTP usado) 🚫 BLOCKED

**Ataque**: Capturar um OTP válido e submetê-lo uma segunda vez após já ter sido usado.
**Resultado**: BLOCKED — `used_at` é definido na primeira verificação bem-sucedida. Uma segunda tentativa encontra `used_at !== null` → 401.

---

### ATK-03 — Enumeração de Usuário via /otp/request 🚫 BLOCKED

**Ataque**: Sondar `/otp/request` com e-mails conhecidos e desconhecidos para descobrir quais contas existem.
**Resultado**: BLOCKED — e-mails existentes e não existentes sempre retornam `202 Accepted` com corpos de resposta idênticos.

---

### ATK-04 — Verificar para Usuário Inexistente 🚫 BLOCKED

**Ataque**: Chamar `/otp/verify` com um e-mail que não tem conta.
**Resultado**: BLOCKED — retorna 401 (`invalid code`), não 404 ou 500. Sem stack trace ou sinal de existência de conta na resposta.

---

### ATK-05 — Injeção de SQL no Campo de E-mail 🚫 BLOCKED

**Ataque**: Submeter `'; DROP TABLE users; --` como e-mail.
**Resultado**: BLOCKED — `filter_var($email, FILTER_VALIDATE_EMAIL)` rejeita strings de injeção como formato de e-mail inválido antes de qualquer query no banco. Todas as queries usam declarações parametrizadas.

---

### ATK-06 — Código de 5 Dígitos (muito curto) 🚫 BLOCKED

**Ataque**: Submeter um código de 5 caracteres para ignorar a verificação de formato do OTP.
**Resultado**: BLOCKED — `/^\d{6}$/` exige exatamente 6 dígitos. Retorna 422.

---

### ATK-07 — Código de 7 Dígitos (muito longo) 🚫 BLOCKED

**Ataque**: Submeter um código de 7 dígitos para ignorar a validação de formato.
**Resultado**: BLOCKED — o mesmo regex rejeita códigos que não tenham exatamente 6 dígitos. Retorna 422.

---

### ATK-08 — Reutilização do Token de Sessão Após Logout 🚫 BLOCKED

**Ataque**: Usar um token após fazer logout para manter acesso.
**Resultado**: BLOCKED — `revokeSession()` define `revoked_at`. O handler GET verifica `$session['revoked_at'] !== null` → 401.

---

### ATK-09 — Adivinhação Aleatória de Token 🚫 BLOCKED

**Ataque**: Submeter uma string hex aleatória de 64 caracteres como token Bearer.
**Resultado**: BLOCKED — o hash SHA-256 do token aleatório não corresponde a nenhum `session_token_hash`. Retorna 401. O espaço de tokens é 2^256.

---

### ATK-10 — Token Bearer Vazio 🚫 BLOCKED

**Ataque**: Enviar `Authorization: Bearer ` (vazio após o prefixo Bearer).
**Resultado**: BLOCKED — `trim(substr($header, 7))` retorna string vazia → `if ($token === '') return 401`.

---

### ATK-11 — Código Alfabético (não numérico) 🚫 BLOCKED

**Ataque**: Submeter `abcdef` como código OTP.
**Resultado**: BLOCKED — `/^\d{6}$/` exige apenas dígitos decimais. Retorna 422 antes de qualquer interação com o banco.

---

### ATK-12 — Nova Solicitação de OTP Invalida Código Antigo 🚫 BLOCKED (por design)

**Ataque**: Obter um OTP válido, deixar a vítima solicitar um novo, depois submeter o código original.
**Resultado**: BLOCKED — `findLatestOtpForUser()` recupera apenas `ORDER BY id DESC LIMIT 1`. O OTP antigo é substituído; submetê-lo retorna 401 (hash de código errado para o OTP mais recente).

---

### Resumo ATK

| ID | Ataque | Resultado |
|----|--------|-----------|
| ATK-01 | Força bruta do OTP | 🚫 BLOCKED |
| ATK-02 | Ataque de repetição (OTP usado) | 🚫 BLOCKED |
| ATK-03 | Enumeração de usuário via /otp/request | 🚫 BLOCKED |
| ATK-04 | Verificar usuário inexistente | 🚫 BLOCKED |
| ATK-05 | Injeção de SQL no e-mail | 🚫 BLOCKED |
| ATK-06 | Código de 5 dígitos (muito curto) | 🚫 BLOCKED |
| ATK-07 | Código de 7 dígitos (muito longo) | 🚫 BLOCKED |
| ATK-08 | Reutilização de sessão após logout | 🚫 BLOCKED |
| ATK-09 | Adivinhação aleatória de token | 🚫 BLOCKED |
| ATK-10 | Token Bearer vazio | 🚫 BLOCKED |
| ATK-11 | Código alfabético | 🚫 BLOCKED |
| ATK-12 | OTP antigo invalidado por nova solicitação | 🚫 BLOCKED |

**12 BLOCKED, 0 EXPOSED**
Armazenamento baseado em hash, lockout por força bruta, guarda de repetição `used_at`, validação de formato e prevenção de enumeração always-202 cobrem todos os vetores de ataque críticos de OTP.

---

## O Que NÃO Fazer

| Anti-padrão | Risco |
|---|---|
| Armazenar código OTP bruto no banco | Comprometimento do banco expõe todos os OTPs ativos; sempre SHA-256 hash |
| Sem lockout por força bruta | OTP de 6 dígitos tem 10^6 combinações — bruteforceável em segundos sem lockout |
| Retornar 404 para e-mail desconhecido na verificação | Revela quais e-mails têm contas (enumeração de usuário) |
| Retornar status diferente para e-mail conhecido vs desconhecido na /request | Mesmo risco de enumeração; sempre retornar 202 |
| Sem flag `used_at` | OTP pode ser repetido indefinidamente até expirar |
| Aceitar códigos alfabéticos ou com número de dígitos diferente de 6 | Ignora contrato de formato; adicionar verificação `/^\d{6}$/` |
| Armazenar token de sessão bruto no banco | Violação do banco expõe todas as sessões; armazenar apenas hash SHA-256 |
| Excluir linha de sessão no logout | Não é possível detectar tokens revogados; use `revoked_at` para soft-revoke |
| Revelar sucesso/falha do logout com base na validade do token | Atacantes sondiam validade do token via logout; sempre retornar 200 |
| Usar `findAllOtpsForUser()` e escolher o válido | Múltiplos OTPs ativos confundem o estado; usar `ORDER BY id DESC LIMIT 1` |
| Sem limite de tamanho de e-mail | RFC 5321 máximo é 254 chars; entrada excessiva causa problemas no banco/e-mail |
