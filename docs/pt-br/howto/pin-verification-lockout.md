# Autenticação por PIN e Lockout

> **Referência FT**: FT252 (`NENE2-FT/pinverifylog`) — Verificação de PIN com Lockout
> **ATK**: FT252 — teste de ataque com mentalidade de cracker (ATK-01 a ATK-12)

Guia de implementação de prevenção de força bruta para PIN de 6 dígitos, proteção contra ataques de timing e desbloqueio por administrador.
Explica armazenamento de hash HMAC-SHA256, comparação em tempo constante e lockout por número de tentativas.

**Segurança comprovada em FT192**: VULN-A~L todos Pass / ATK-01~12 todos Pass.

## Visão Geral

- Admin cria o PIN (armazenamento de hash HMAC-SHA256 — texto puro não armazenado)
- Usuário verifica o PIN (lockout após atingir o limite de falhas)
- Admin desbloqueia
- Histórico de tentativas registrado como log de auditoria

## Endpoints

| Método | Caminho | Auth | Descrição |
|---|---|---|---|
| `POST` | `/pins` | `X-Admin-Key` | Criar PIN |
| `POST` | `/pins/{id}/verify` | — | Verificar PIN |
| `GET` | `/pins/{id}` | `X-Admin-Key` | Verificar status (tentativas restantes, prazo de lock) |
| `POST` | `/pins/{id}/unlock` | `X-Admin-Key` | Desbloquear |
| `DELETE` | `/pins/{id}` | `X-Admin-Key` | Excluir PIN |

## Design do Banco de Dados

```sql
CREATE TABLE IF NOT EXISTS pins (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    label        TEXT    NOT NULL,
    pin_hash     TEXT    NOT NULL,        -- HMAC-SHA256(pin, secret)
    attempts     INTEGER NOT NULL DEFAULT 0,
    max_attempts INTEGER NOT NULL DEFAULT 5,
    locked_until TEXT,                    -- ISO 8601 UTC, NULL = desbloqueado
    created_at   TEXT    NOT NULL,
    updated_at   TEXT    NOT NULL
);

CREATE TABLE IF NOT EXISTS pin_attempts (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    pin_id       INTEGER NOT NULL,
    success      INTEGER NOT NULL DEFAULT 0,
    attempted_at TEXT    NOT NULL
);
```

`locked_until` é armazenado como string ISO 8601 e o estado de lock é determinado por comparação de strings com o horário atual (`$lockedUntil > $now`). Sem custo de conversão.

## Hash HMAC-SHA256 do PIN

O PIN não é armazenado em texto puro; é hashado com HMAC-SHA256. Misturar a chave secreta do servidor (`$hmacSecret`) dificulta a força bruta em caso de vazamento do banco:

```php
private function hashPin(string $pin): string
{
    return hash_hmac('sha256', $pin, $this->hmacSecret);
}
```

## Comparação em Tempo Constante (VULN-E / ATK-02)

`===` termina antecipadamente durante a comparação de bytes, permitindo que ataques de timing adivinhem o hash correto. `hash_equals()` sempre compara todos os bytes:

```php
// ❌ Perigoso: adivinhável por ataque de timing
if ($stored === $provided) { ... }

// ✅ Seguro: comparação em tempo constante
$provided = $this->hashPin($pin);
$success  = hash_equals($pin1->pinHash, $provided);
```

## Prevenção de Força Bruta (ATK-01)

Quando o número de falhas atinge `max_attempts`, define `locked_until` e rejeita todas as tentativas subsequentes (incluindo PIN correto) com 423:

```php
public function verify(int $id, string $pin): string
{
    $now  = $this->now();
    $pin1 = $this->findById($id);

    // 1. Verificar lock antes da tentativa
    if ($pin1->isLocked($now)) {
        return 'locked'; // → 423
    }

    // 2. Comparação em tempo constante
    $provided = $this->hashPin($pin);
    $success  = hash_equals($pin1->pinHash, $provided);

    if ($success) {
        // Redefinir contagem de tentativas no sucesso
        $this->resetAttempts($id, $now);
        return 'success'; // → 200
    }

    // 3. Falha: incrementar → lock no limite
    $newAttempts = $pin1->attempts + 1;
    $lockedUntil = null;

    if ($newAttempts >= $pin1->maxAttempts) {
        $lockedUntil = $this->lockUntil($now); // 5 minutos depois
    }

    $this->incrementAttempts($id, $newAttempts, $lockedUntil, $now);

    return $newAttempts >= $pin1->maxAttempts ? 'locked' : 'wrong'; // → 423 ou 401
}
```

**Importante**: A verificação de lock deve ocorrer antes da tentativa. Se verificada depois, a última tentativa que atinge o estado de lock pode passar.

## Chave de Admin fail-closed (VULN-H / ATK-03)

```php
private function isAdmin(ServerRequestInterface $request): bool
{
    if ($this->adminKey === '') {
        return false; // adminKey vazia sempre recusada
    }

    $provided = $request->getHeaderLine('X-Admin-Key');

    return $provided !== '' && hash_equals($this->adminKey, $provided);
}
```

Se `adminKey` for uma string vazia, retorna `false` incondicionalmente (evita tornar o admin aberto quando a variável de ambiente não está definida).

## Validação de ID (VULN-A / ATK-07)

```php
private function resolveId(ServerRequestInterface $request): ?int
{
    $raw = Router::param($request, 'id');

    if ($raw === null || !ctype_digit($raw) || strlen($raw) > 18) {
        return null; // → 422
    }

    $id = (int) $raw;

    return $id > 0 ? $id : null;
}
```

`strlen($raw) > 18` previne overflow de inteiro de 64 bits (`PHP_INT_MAX` tem 19 dígitos, mas com margem de segurança).

## Validação do PIN (VULN-D)

Use `ctype_digit()`. Regex (`/^[0-9]+$/`) pode resultar em ReDoS com complexidade O(n²), enquanto `ctype_digit()` é O(n) e seguro:

```php
private function validatePin(mixed $pin): ?string
{
    if (!is_string($pin)) {
        return 'pin must be a string.'; // VULN-G: prevenção de confusão de tipo
    }

    $len = strlen($pin);
    if ($len < self::MIN_PIN_LEN || $len > self::MAX_PIN_LEN) {
        return 'pin must be between 4 and 8 digits.';
    }

    if (!ctype_digit($pin)) { // O(n), sem ReDoS
        return 'pin must contain only digits.';
    }

    return null;
}
```

## Design da Resposta

**Nunca incluir o hash do PIN na resposta.** O mesmo vale para respostas de admin:

```php
public function toAdminArray(): array
{
    return [
        'id'                 => $this->id,
        'label'              => $this->label,
        'attempts'           => $this->attempts,
        'max_attempts'       => $this->maxAttempts,
        'locked_until'       => $this->lockedUntil,
        'remaining_attempts' => $this->remainingAttempts(),
        'created_at'         => $this->createdAt,
        // pin_hash não incluído
        // updated_at não incluído como informação interna
    ];
}
```

## Exemplos de Resposta

```json
// POST /pins (201)
{
    "pin": {
        "id": 1,
        "label": "vault",
        "attempts": 0,
        "max_attempts": 5,
        "locked_until": null,
        "remaining_attempts": 5,
        "created_at": "2026-05-26T10:00:00+00:00"
    }
}

// POST /pins/1/verify — sucesso (200)
{ "success": true, "locked": false }

// POST /pins/1/verify — falha (401)
{ "success": false, "locked": false }

// POST /pins/1/verify — bloqueado (423)
{ "success": false, "locked": true, "error": "PIN is locked due to too many failed attempts." }

// POST /pins/1/unlock (200)
{ "unlocked": true }
```

## Pontos de Segurança (VULN-A~L / ATK-01~12 todos Pass)

| Ameaça | Categoria | Contramedida |
|---|---|---|
| Força bruta | ATK-01 | Limite `max_attempts` → lock por 5 min com `locked_until` |
| Ataque de timing (PIN) | ATK-02 / VULN-E | Comparação em tempo constante com `hash_equals()` |
| Bypass de chave admin | ATK-03 / VULN-H | `adminKey = ''` → false (fail-closed) |
| Enumeração de ID | ATK-04 | ID inexistente retorna 404 (sem vazamento de informação) |
| Injeção SQL (valor PIN) | ATK-05 / VULN-B | `ctype_digit` passa apenas dígitos → PDO prepared statement |
| Injeção SQL (ID) | ATK-06 / VULN-B | Guarda `ctype_digit + strlen > 18` → 422 |
| Overflow de inteiro | ATK-07 / VULN-A / VULN-J | Guarda `strlen > 18` |
| Bypass de lockout | ATK-08 | Verificação de lock antes da tentativa, persistência no banco |
| Re-ataque após desbloqueio | ATK-09 | Após unlock, attempts = 0 reset (comportamento normal) |
| Injeção no corpo | ATK-10 / VULN-I | Aceitar apenas campos explícitos |
| Timing da chave admin | ATK-11 | Comparação em tempo constante com `hash_equals()` |
| Label BIDI/Unicode | ATK-12 / VULN-L | Verificação de comprimento com `mb_strlen`, armazenamento seguro com PDO |
| ReDoS | VULN-D | `ctype_digit()` O(n), sem regex |
| Confusão de tipo | VULN-G | Verificação `!is_string($pin)` |
| Overflow de max_attempts | VULN-F | Verificação de intervalo 1~20 |
| SSRF | VULN-K | Sem comunicação HTTP externa (N/A) |
| Path traversal | VULN-C | Sem operações de arquivo (N/A) |

## Guias Relacionados

- [Lockout de conta](account-lockout.md) — contagem de falhas por conta, design 423
- [Sistema de autenticação OTP](otp-authentication.md) — padrão de lockout similar (apenas o OTP mais recente é válido)
- [Verificação de assinatura de webhook](webhook-signature.md) — padrão `hash_equals()`
- [Código de verificação numérico](numeric-verification-code.md) — fluxo de geração e verificação de código de 6 dígitos
