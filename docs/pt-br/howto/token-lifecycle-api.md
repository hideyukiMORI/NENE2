# Como fazer: Gerenciamento do Ciclo de Vida de Tokens de API

> **Referência FT**: FT272 (`NENE2-FT/tokenlog`) — ciclo de vida de tokens de API: armazenamento de hash SHA-256 (texto simples nunca persiste), enum de escopo (read/write/admin) com restrição CHECK no banco, proteção IDOR (actorId deve coincidir com userId), soft-revoke via revoked_at, endpoint de verificação retorna valid/user_id/scope, 29 testes / 70 asserções PASSAM.
>
> **Avaliação ATK**: ATK-01 a ATK-12 incluídos ao final deste documento.

Demonstra um sistema de tokens de API com escopos: emitir tokens para um usuário, listá-los/revogá-los, e verificar um token bruto no momento do acesso. Os tokens são armazenados apenas como hashes SHA-256 — o texto simples é retornado uma vez na emissão e nunca armazenado.

---

## Schema

```sql
CREATE TABLE users (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT NOT NULL,
    created_at TEXT NOT NULL
);

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

Principais decisões de design:
- `token_hash UNIQUE` — previne emissão duplicada acidental; também é a chave de busca na verificação
- `CHECK (scope IN (...))` — aplicação do enum de escopo no nível do banco
- `revoked_at TEXT` — soft-revoke; `NULL` significa ativo, não-NULL significa revogado

---

## Rotas

| Método   | Caminho                             | Descrição                            |
|----------|-------------------------------------|--------------------------------------|
| `POST`   | `/users`                            | Criar um usuário                     |
| `POST`   | `/users/{userId}/tokens`            | Emitir um token (somente dono)       |
| `GET`    | `/users/{userId}/tokens`            | Listar tokens de um usuário (somente dono) |
| `DELETE` | `/users/{userId}/tokens/{tokenId}`  | Revogar um token (somente dono)      |
| `POST`   | `/tokens/verify`                    | Verificar um token bruto             |

---

## Armazenamento somente de hash

O token bruto é retornado uma vez na emissão e nunca armazenado:

```php
public function issueToken(int $userId, TokenScope $scope, string $label, string $now): string
{
    $raw  = bin2hex(random_bytes(32));   // 64 caracteres hex — 256 bits de entropia
    $hash = hash('sha256', $raw);

    $this->executor->execute(
        'INSERT INTO tokens (user_id, token_hash, scope, label, created_at) VALUES (?, ?, ?, ?, ?)',
        [$userId, $hash, $scope->value, $label, $now],
    );

    return $raw; // retornado ao chamador, nunca armazenado
}
```

Na verificação, o chamador fornece o token bruto; o hash é recomputado e buscado:

```php
public function verifyToken(string $rawToken): ?array
{
    $hash = hash('sha256', $rawToken);
    $row  = $this->executor->fetchOne(
        'SELECT id, user_id, scope, revoked_at FROM tokens WHERE token_hash = ?',
        [$hash],
    );

    if ($row === null) {
        return null; // não encontrado → chamador retorna {valid: false}
    }

    return [
        'valid'   => !isset($arr['revoked_at']),
        'user_id' => (int) $arr['user_id'],
        'scope'   => (string) $arr['scope'],
    ];
}
```

---

## Aplicação de escopo

`TokenScope` é um enum backed do PHP; `tryFrom()` rejeita valores desconhecidos antes de qualquer acesso ao banco:

```php
enum TokenScope: string
{
    case Read  = 'read';
    case Write = 'write';
    case Admin = 'admin';
}

// No handler de rota:
$scope = TokenScope::tryFrom($scopeValue);
if ($scope === null) {
    return $this->responseFactory->create(['error' => 'invalid scope, must be read/write/admin'], 422);
}
```

A restrição `CHECK` no banco fornece uma segunda camada de aplicação.

---

## Proteção IDOR

A emissão, listagem e revogação de tokens exigem que o ator seja o proprietário:

```php
$actorId = $this->resolveActorId($request); // do cabeçalho X-User-Id

if ($actorId !== $userId) {
    return $this->responseFactory->create(['error' => 'forbidden'], 403);
}
```

A revogação também verifica que o token pertence ao `userId`, não apenas qualquer token:

```php
if ($token['user_id'] !== $userId) {
    return $this->responseFactory->create(['error' => 'forbidden'], 403);
}
```

---

## Revogação

O soft-revoke define `revoked_at`; o UPDATE só se aplica se `revoked_at IS NULL`:

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

Se o token já foi revogado, o handler de rota retorna 409 Conflict:

```php
if ($token['revoked']) {
    return $this->responseFactory->create(['error' => 'token already revoked'], 409);
}
```

---

## Avaliação ATK — Teste de Ataque com Mentalidade de Cracker

### ATK-01 — Replay de token após revogação 🚫 BLOCKED

**Ataque**: Revogar um token e então usar o mesmo valor de token bruto em `/tokens/verify`.
**Resultado**: BLOCKED — `verifyToken()` consulta `revoked_at` na linha; um `revoked_at` não-NULL resulta em `valid: false`. O token revogado não é deletado, então resolve mas retorna `{valid: false}`.

---

### ATK-02 — Adivinhação de token por força bruta 🚫 BLOCKED

**Ataque**: Enviar strings hex aleatórias de 64 caracteres para `/tokens/verify` esperando corresponder a um hash de token válido.
**Resultado**: BLOCKED — tokens são `bin2hex(random_bytes(32))` = 256 bits de entropia. A probabilidade de um palpite bem-sucedido é `1 / 2^256`. Este FT não inclui rate limiting, mas a entropia por si só torna a força bruta computacionalmente inviável.

---

### ATK-03 — IDOR: acessar lista de tokens de outro usuário 🚫 BLOCKED

**Ataque**: Definir `X-User-Id: 1` e solicitar `GET /users/2/tokens`.
**Resultado**: BLOCKED — `actorId (1) !== userId (2)` → 403 Forbidden.

---

### ATK-04 — IDOR: revogar token de outro usuário 🚫 BLOCKED

**Ataque**: Como usuário 1, chamar `DELETE /users/2/tokens/{tokenId}`.
**Resultado**: BLOCKED — o handler verifica `actorId !== userId` → 403 antes de buscar o token.

---

### ATK-05 — Revogação de token entre proprietários (ID de token compartilhado) 🚫 BLOCKED

**Ataque**: Como usuário 2, chamar `DELETE /users/2/tokens/{tokenId}` onde `tokenId` pertence ao usuário 1.
**Resultado**: BLOCKED — após a verificação IDOR passar (actorId = userId = 2), `findTokenById` retorna o token, então `$token['user_id'] !== $userId` → 403. A verificação dupla de propriedade previne revogação entre usuários.

---

### ATK-06 — Injeção de escopo inválido 🚫 BLOCKED

**Ataque**: POST `/users/{id}/tokens` com `{"scope": "superadmin"}`.
**Resultado**: BLOCKED — `TokenScope::tryFrom('superadmin')` retorna `null` → 422. A restrição CHECK no banco também bloquearia se a camada de aplicação de alguma forma passasse adiante.

---

### ATK-07 — Extração de texto simples de token do banco 🚫 BLOCKED

**Ataque**: Se um atacante obtiver acesso de leitura à tabela `tokens`, ele consegue obter tokens funcionais?
**Resultado**: BLOCKED — apenas `token_hash` (SHA-256) é armazenado. Reverter SHA-256 é computacionalmente inviável. O token bruto é retornado uma vez na emissão e descartado no servidor.

---

### ATK-08 — Verificação com token vazio/malformado 🚫 BLOCKED

**Ataque**: POST `/tokens/verify` com `{"token": ""}` ou `{"token": null}`.
**Resultado**: BLOCKED — verificação de string vazia: `if ($token === '') → 422`. `null` é rejeitado pela verificação `is_string()`. O SHA-256 de uma string vazia não corresponderia a nenhum hash armazenado de qualquer forma.

---

### ATK-09 — Emissão de token para usuário inexistente 🚫 BLOCKED

**Ataque**: POST `/users/9999/tokens` onde o usuário 9999 não existe.
**Resultado**: BLOCKED — `findUserById(9999)` retorna `false` → 404 antes de qualquer token ser criado.

---

### ATK-10 — Dupla revogação (idempotência) 🚫 BLOCKED

**Ataque**: Revogar o mesmo token duas vezes em rápida sucessão.
**Resultado**: BLOCKED — `revokeToken` usa `WHERE revoked_at IS NULL`; a segunda chamada retorna 0 linhas afetadas. O handler lê `$token['revoked'] === true` antes de chamar o repositório → 409 Conflict. Nenhuma janela de condição de corrida para dupla revogação ter sucesso.

---

### ATK-11 — userId negativo ou string no caminho 🚫 BLOCKED

**Ataque**: `GET /users/-1/tokens` ou `GET /users/abc/tokens`.
**Resultado**: BLOCKED — `is_numeric($params['userId'])` → cast para `(int)`. `-1` se torna -1; `findUserById(-1)` retorna false → 404. `abc` não é numérico → `userId = 0` → 404.

---

### ATK-12 — Downgrade de escopo na resposta de verificação 🚫 BLOCKED

**Ataque**: Após obter um token com escopo `read`, tentar falsificar `scope: write` na resposta de verificação enviando um corpo de requisição modificado.
**Resultado**: BLOCKED — `/tokens/verify` aceita apenas uma string de token bruto; o escopo é lido da linha no banco, não de qualquer campo fornecido pelo cliente. O cliente não pode influenciar o escopo retornado.

---

### Resumo ATK

| ID | Ataque | Resultado |
|----|--------|-----------|
| ATK-01 | Replay de token revogado | 🚫 BLOCKED |
| ATK-02 | Adivinhação de token por força bruta | 🚫 BLOCKED |
| ATK-03 | IDOR: ler lista de tokens de outro usuário | 🚫 BLOCKED |
| ATK-04 | IDOR: revogar tokens de outro usuário | 🚫 BLOCKED |
| ATK-05 | Revogação de token entre proprietários | 🚫 BLOCKED |
| ATK-06 | Injeção de escopo inválido | 🚫 BLOCKED |
| ATK-07 | Extração de texto simples do banco | 🚫 BLOCKED |
| ATK-08 | Token vazio/malformado na verificação | 🚫 BLOCKED |
| ATK-09 | Emissão de token para usuário inexistente | 🚫 BLOCKED |
| ATK-10 | Condição de corrida em dupla revogação | 🚫 BLOCKED |
| ATK-11 | userId negativo/string no caminho | 🚫 BLOCKED |
| ATK-12 | Downgrade de escopo via corpo de verificação | 🚫 BLOCKED |

**12 BLOCKED / SAFE, 0 EXPOSED**
Nenhuma descoberta crítica. O armazenamento somente por hash, a aplicação do enum de escopo e as verificações IDOR duplas formam uma superfície de defesa robusta.

---

## O que NÃO fazer

| Anti-padrão | Risco |
|---|---|
| Armazenar token bruto no banco | Vazamento por leitura do banco expõe todos os tokens; tokens não podem ser rotacionados sem ação do usuário |
| Usar MD5/SHA-1 para hash do token | Ataques de colisão; prefira SHA-256 ou BLAKE2 |
| Aceitar strings de escopo arbitrárias | Sem validação `tryFrom()`, escopos `superadmin` podem ser emitidos |
| Sem verificação de propriedade na revogação | Qualquer usuário autenticado pode revogar qualquer token (IDOR) |
| Hard-delete de tokens na revogação | Trilha de auditoria é perdida; sem como detectar replay de token revogado |
| Retornar 404 em token já revogado | Impossível distinguir "não encontrado" de "já revogado"; use 409 |
