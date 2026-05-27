# Como Construir Gerenciamento de Tokens de Acesso com NENE2

Este guia mostra como construir um sistema de tokens de acesso pessoal (PAT) — os usuários emitem, listam e revogam seus próprios tokens de API, cada um com um escopo (`read`/`write`/`admin`). Os tokens nunca são armazenados em texto puro; apenas o hash SHA-256 é mantido.

**Field Trial**: FT136  
**Versão NENE2**: ^1.5  
**Tópicos cobertos**: hash de token, enums de escopo, verificação de propriedade, idempotência de revogação, endpoint de verificação

---

## O que estamos construindo

- `POST /users/{id}/tokens` — emitir um token (somente o proprietário, retorna o token bruto uma vez)
- `GET /users/{id}/tokens` — listar tokens (somente o proprietário, sem token bruto na resposta)
- `DELETE /users/{id}/tokens/{tokenId}` — revogar um token (somente o proprietário, 409 se já revogado)
- `POST /tokens/verify` — verificar um token bruto (retorna válido/inválido + escopo)

---

## Schema do banco de dados

```sql
CREATE TABLE tokens (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    token_hash TEXT    NOT NULL UNIQUE,
    scope      TEXT    NOT NULL DEFAULT 'read',
    label      TEXT    NOT NULL DEFAULT '',
    created_at TEXT    NOT NULL,
    revoked_at TEXT,
    FOREIGN KEY (user_id) REFERENCES users(id),
    CHECK (scope IN ('read', 'write', 'admin'))
);
```

- `token_hash` — SHA-256 do token bruto; nunca armazene o token bruto
- `revoked_at` — timestamp nullable; `NULL` = ativo, não nulo = revogado
- `CHECK (scope IN (...))` — restrição de escopo no nível do banco de dados como defesa em profundidade

---

## Enum de escopo do token

```php
enum TokenScope: string
{
    case Read  = 'read';
    case Write = 'write';
    case Admin = 'admin';
}
```

`TokenScope::tryFrom($value)` retorna `null` para escopos desconhecidos — use isso para validar a entrada antes de armazenar.

---

## Emissão de tokens

```php
public function issueToken(int $userId, TokenScope $scope, string $label, string $now): string
{
    $raw  = bin2hex(random_bytes(32)); // string hex de 64 caracteres
    $hash = hash('sha256', $raw);

    $this->executor->execute(
        'INSERT INTO tokens (user_id, token_hash, scope, label, created_at) VALUES (?, ?, ?, ?, ?)',
        [$userId, $hash, $scope->value, $label, $now],
    );

    return $raw; // retornado uma vez, nunca armazenado
}
```

O token bruto é retornado ao chamador exatamente uma vez. Depois disso, apenas o hash está no banco de dados — não há como recuperar o token bruto.

---

## Verificação de tokens

```php
public function verifyToken(string $rawToken): ?array
{
    $hash = hash('sha256', $rawToken);
    $row  = $this->executor->fetchOne(
        'SELECT id, user_id, scope, revoked_at FROM tokens WHERE token_hash = ?',
        [$hash],
    );

    if ($row === null) {
        return null;
    }

    $arr = (array) $row;

    return [
        'valid'   => !isset($arr['revoked_at']),
        'user_id' => isset($arr['user_id']) ? (int) $arr['user_id'] : 0,
        'scope'   => isset($arr['scope']) && is_string($arr['scope']) ? $arr['scope'] : 'read',
    ];
}
```

**Por que `!isset($arr['revoked_at'])` e não `=== null`?** Após `isset()` retornar true, o PHPStan elimina `null` do tipo — comparar com `null` seria `identical.alwaysFalse`. Use `isset()` sozinho para verificar null.

O endpoint de verificação sempre retorna 200 com `{ "valid": false }` para tokens desconhecidos ou revogados — nunca 404. Isso evita a enumeração de tokens.

---

## Verificação de propriedade

Cada endpoint mutante verifica que o ator autenticado corresponde ao proprietário do recurso:

```php
$actorId = $this->resolveActorId($request); // do cabeçalho X-User-Id

if ($actorId !== $userId) {
    return $this->responseFactory->create(['error' => 'forbidden'], 403);
}
```

Para revogar, há uma segunda verificação de propriedade no próprio token:

```php
$token = $this->repo->findTokenById($tokenId);

if ($token['user_id'] !== $userId) {
    return $this->responseFactory->create(['error' => 'forbidden'], 403);
}
```

Isso previne ATK-04 — Bob usando seu próprio caminho de usuário, mas revogando o token ID de Alice.

---

## Revogar — 409 para já revogado

```php
public function revokeToken(int $tokenId, string $now): bool
{
    $count = $this->executor->execute(
        'UPDATE tokens SET revoked_at = ? WHERE id = ? AND revoked_at IS NULL',
        [$now, $tokenId],
    );

    return $count > 0;
}
```

A guarda `WHERE revoked_at IS NULL` significa que o UPDATE é um no-op se o token já foi revogado. O handler mapeia `$count === 0` para 409 Conflict.

---

## Listar tokens — nunca inclua o token bruto

A resposta de listagem inclui `id`, `scope`, `label`, `created_at`, `revoked` (bool). O token bruto nunca é retornado após a chamada inicial de emissão.

---

## Armadilha do PHPStan nível 8: isset + comparação com null

```php
// ERRADO — PHPStan reporta `notIdentical.alwaysTrue`
'revoked' => isset($arr['revoked_at']) && $arr['revoked_at'] !== null,

// CORRETO — isset() já implica não nulo
'revoked' => isset($arr['revoked_at']),

// ERRADO — PHPStan reporta `identical.alwaysFalse`
'valid' => !isset($arr['revoked_at']) || $arr['revoked_at'] === null,

// CORRETO
'valid' => !isset($arr['revoked_at']),
```

---

## Resultados do teste de ataque cracker (FT136)

| Ataque | Esperado | Resultado |
|--------|----------|-----------|
| ATK-01: Emitir token para outro usuário (IDOR) | 403 | Pass |
| ATK-02: Listar tokens de outro usuário (IDOR) | 403 | Pass |
| ATK-03: Revogar token de outro usuário via caminho deles | 403 | Pass |
| ATK-04: Revogar token de outro usuário via caminho próprio | 403 | Pass |
| ATK-05: Escopo inválido (`superuser`) | 422 | Pass |
| ATK-06: Usar token revogado para verificação | valid=false | Pass |
| ATK-07: Força bruta com token aleatório | valid=false | Pass |
| ATK-08: SQL injection no corpo de verificação | valid=false | Pass |
| ATK-09: X-User-Id não numérico (`admin`) | não 201 | Pass |
| ATK-10: ID de usuário negativo | 404 | Pass |
| ATK-11: String de escopo com 10KB | 422 | Pass |
| ATK-12: Token vazio ou apenas com espaços | 422 | Pass |

Todos os 12 testes de ataque passam.

---

## Armadilhas comuns

| Armadilha | Correção |
|-----------|---------|
| `isset($x) && $x !== null` | Use `isset($x)` sozinho — PHPStan nível 8 rejeita a verificação redundante |
| Armazenar token bruto no banco de dados | Armazene apenas `hash('sha256', $raw)` |
| Retornar token bruto na resposta de listagem | Retorne o token bruto apenas na resposta de emissão |
| Não verificar propriedade do token ao revogar | Verifique `token['user_id'] === userId` após encontrar o token |
| Retornar 404 para token inválido na verificação | Sempre retorne 200 com `valid: false` — evita enumeração |
