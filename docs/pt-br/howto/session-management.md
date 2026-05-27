# Como Construir um Gerenciador de Sessões Multi-Dispositivo

> **Padrão comprovado pelo FT186 sessionlog** — rastreamento de sessão multi-dispositivo, prevenção de IDOR, guarda de atribuição em massa, revogação sem oráculo de timing.

---

## O Que Este Guia Cobre

Um gerenciador de sessões multi-dispositivo permite que os usuários:

1. **Criem sessões** no login (cada dispositivo recebe seu próprio token)
2. **Listem sessões ativas** com escopo ao seu ID de usuário
3. **Revoguem uma única sessão** (fazer logout de um dispositivo)
4. **Revoguem todas exceto a atual** (fazer logout de todos os outros dispositivos)

Garantias de segurança demonstradas:

| Preocupação | Técnica |
|---|---|
| Prevenção de IDOR | Todas as mutações usam `WHERE token = ? AND user_id = ?` |
| Atribuição em massa | `token`, `user_id`, `created_at`, `revoked_at` definidos somente pelo servidor |
| Oráculo de timing | 404 genérico para todas as falhas — sem vazamento de propriedade |
| Overflow de inteiro | Guarda `strlen` de 18 dígitos com `V::queryInt()` |
| Confusão de tipo | `V::str()` rejeita `device_name`/`ip_address` não-string |
| Entropia do token | `bin2hex(random_bytes(32))` — 256 bits, 64 chars hex |
| Injeção SQL | Queries parametrizadas PDO + porta `/^[0-9a-f]{64}$/` |

---

## Schema

```sql
CREATE TABLE sessions (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id     INTEGER NOT NULL,
    token       TEXT    NOT NULL UNIQUE,
    device_name TEXT,
    ip_address  TEXT,
    last_active TEXT    NOT NULL,
    created_at  TEXT    NOT NULL,
    revoked_at  TEXT    -- NULL = ativa
);
```

`revoked_at IS NULL` é o predicado de sessão ativa. Soft delete evita perder histórico de auditoria.

---

## Design da API

| Método | Caminho | Header | Descrição |
|---|---|---|---|
| `POST` | `/sessions` | `X-User-Id` | Criar uma sessão |
| `GET` | `/sessions` | `X-User-Id` | Listar próprias sessões ativas |
| `DELETE` | `/sessions/{token}` | `X-User-Id` | Revogar uma sessão |
| `DELETE` | `/sessions` | `X-User-Id` + `X-Current-Session` | Revogar todas exceto a atual |

---

## Padrão Principal: Geração de Token de 256 bits

```php
public function create(int $userId, ?string $deviceName, ?string $ipAddress): Session
{
    $token = bin2hex(random_bytes(32)); // 256 bits de entropia, 64 chars hex
    $now   = $this->now();

    $stmt = $this->pdo->prepare(
        'INSERT INTO sessions (user_id, token, device_name, ip_address, last_active, created_at)
         VALUES (:user_id, :token, :device_name, :ip_address, :now, :now2)',
    );
    $stmt->execute([...]);
    // ...
}
```

`bin2hex(random_bytes(32))` produz 64 caracteres hex minúsculos de uma fonte criptograficamente segura. Nunca aceite tokens da entrada do usuário.

---

## Padrão Principal: Prevenção de IDOR

```php
// ERRADO — permite que qualquer usuário autenticado revogue qualquer sessão
UPDATE sessions SET revoked_at = ? WHERE token = ?

// CORRETO — deve ser proprietário da sessão
public function revokeForUser(string $token, int $userId): bool
{
    $stmt = $this->pdo->prepare(
        'UPDATE sessions SET revoked_at = :now
         WHERE token = :token AND user_id = :user_id AND revoked_at IS NULL',
    );
    $stmt->execute(['now' => $this->now(), 'token' => $token, 'user_id' => $userId]);

    return $stmt->rowCount() > 0;
}
```

`rowCount() > 0` retorna `false` quando o token existe mas pertence a outro usuário — o handler responde com um 404 genérico (ver seção de oráculo de timing).

---

## Padrão Principal: Guarda de Atribuição em Massa

```php
// Handler POST /sessions — corpo do atacante: {"token": "custom", "user_id": 999, "revoked_at": "now"}
private function handleCreate(ServerRequestInterface $request): ResponseInterface
{
    // userId vem do header X-User-Id — nunca do corpo
    $userId = V::userId($request->getHeaderLine('X-User-Id'));

    $body = $this->parseBody($request);

    // Apenas campos seguros e validados são passados adiante
    $deviceName = V::str($body['device_name'] ?? null, 200);
    $ipAddress  = V::str($body['ip_address'] ?? null, 45);

    // token, user_id, created_at, revoked_at definidos pelo repositório — não do corpo
    $session = $this->repository->create($userId, $deviceName, $ipAddress);

    return $this->responseFactory->create($session->toArray(), 201);
}
```

---

## Padrão Principal: Prevenção de Oráculo de Timing

```php
private function handleRevokeOne(ServerRequestInterface $request): ResponseInterface
{
    $userId   = V::userId($request->getHeaderLine('X-User-Id'));
    $rawToken = Router::param($request, 'token');

    // VULN-I: formato inválido → 404 imediatamente (sem query no banco)
    if ($rawToken === null || !preg_match('/^[0-9a-f]{64}$/', $rawToken)) {
        return $this->responseFactory->create(['error' => 'Session not found.'], 404);
    }

    // Guarda IDOR: revokeForUser retorna false quando:
    //   - token não existe
    //   - token pertence a usuário diferente
    //   - token já está revogado
    // Todos os casos retornam o MESMO 404 — sem oráculo de propriedade
    $revoked = $this->repository->revokeForUser($rawToken, $userId);

    if (!$revoked) {
        return $this->responseFactory->create(['error' => 'Session not found.'], 404);
    }

    return $this->responseFactory->create([], 204);
}
```

Nunca distinga "não encontrado" de "usuário errado" na resposta. Um atacante que conhece o token da vítima não deve saber se está ativo ou pertence a esse usuário.

---

## Padrão Principal: Revogar Todos Exceto o Atual

```php
public function revokeAllExcept(int $userId, string $currentToken): int
{
    $stmt = $this->pdo->prepare(
        'UPDATE sessions SET revoked_at = :now
         WHERE user_id = :user_id AND token != :current AND revoked_at IS NULL',
    );
    $stmt->execute(['now' => $this->now(), 'user_id' => $userId, 'current' => $currentToken]);

    return $stmt->rowCount();
}
```

O chamador passa o header `X-Current-Session`. Tanto `user_id` quanto a condição de exclusão são aplicados em uma única query.

---

## Padrão Principal: Validação de Limit Segura contra Overflow

```php
// VULN-A: V::queryInt recusa >18 dígitos — impede overflow silencioso de int PHP
// VULN-F: ctype_digit é O(n) — sem risco de backtracking regex
$limit = V::queryInt($params, 'limit', 1, self::MAX_LIMIT, self::DEFAULT_LIMIT);

if ($limit === null) {
    return $this->responseFactory->create(
        ['error' => sprintf('limit must be between 1 and %d.', self::MAX_LIMIT)],
        422,
    );
}
```

`V::queryInt()` rejeita números negativos, floats, strings hex (`0x10`) e números > 18 dígitos.

---

## Validação de Token na Rota

Sempre valide o formato do token na camada de rota antes de consultar o banco:

```php
private const TOKEN_PATTERN = '/^[0-9a-f]{64}$/';

// No handler:
if ($rawToken === null || !preg_match(self::TOKEN_PATTERN, $rawToken)) {
    return $this->responseFactory->create(['error' => 'Session not found.'], 404);
}
```

Isso bloqueia strings de injeção SQL, tentativas de path traversal e tokens curtos/longos antes de qualquer interação com o banco de dados.

---

## Resultados dos Testes (FT186)

```
54 testes / 116 assertivas — todos PASS
PHPStan nível 8 — sem erros
PHP CS Fixer — limpo
```

Cobertura VULN-A~L:

| Vuln | Padrão | Teste |
|---|---|---|
| A | overflow de 19 dígitos no limit | `testVulnALimitOverflow19Digits` |
| B | confusão de tipo em device_name | `testVulnBDeviceNameAsInteger` |
| C | injeção SQL no token | `testVulnCSqlInjectionToken` |
| D | limit negativo/float/hex | `testVulnDNegativeLimitRejected` |
| E | revogação IDOR | `testVulnECannotRevokeOtherUsersSession` |
| F | limit muito longo estilo ReDoS | `testVulnFVeryLongLimitRejected` |
| H | oráculo de timing | `testVulnHSameResponseForAlreadyRevokedAndCrossUser` |
| I | token vazio/curto/traversal | `testVulnIEmptyTokenSegmentNotMatched` |
| L | atribuição em massa | `testVulnLTokenFromBodyIsIgnored` |

Fonte: [`../NENE2-FT/sessionlog/`](https://github.com/hideyukiMORI/NENE2-examples/tree/main/sessionlog)
