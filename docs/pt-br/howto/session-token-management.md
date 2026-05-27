# Como Fazer: API de Gerenciamento de Sessão / Token (ATK-01~12)

Este guia demonstra uma API de token de sessão segura cobrindo todos os vetores de ataque ATK-01~12 com mentalidade de cracker.

## Visão Geral do Padrão

- `POST /sessions` — Emitir um novo token opaco para um usuário (`X-User-Id` obrigatório).
- `GET /sessions/{token}` — Validar um token (404 se revogado ou expirado).
- `DELETE /sessions/{token}` — Revogar um token (proprietário ou admin).
- `GET /users/{userId}/sessions` — Listar sessões ativas (proprietário ou admin).

Tokens são `bin2hex(random_bytes(32))` — 64 caracteres hex minúsculos.

## Schema

```sql
CREATE TABLE IF NOT EXISTS sessions (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    token      TEXT    NOT NULL UNIQUE,
    label      TEXT    NOT NULL DEFAULT '',
    revoked    INTEGER NOT NULL DEFAULT 0,
    expires_at TEXT    NOT NULL,
    created_at TEXT    NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_sessions_token ON sessions (token);
CREATE INDEX IF NOT EXISTS idx_sessions_user  ON sessions (user_id, revoked);
```

## Geração de Token

```php
$token = bin2hex(random_bytes(32));  // 64 chars hex minúsculos
```

`random_bytes()` usa um CSPRNG; tokens são impossíveis de adivinhar e não sequenciais.

## Validação do Formato do Token

Antes de qualquer consulta ao banco, valide o formato do token com uma regex rigorosa:

```php
private const string TOKEN_PATTERN = '/\A[0-9a-f]{64}\z/';

private function pathToken(ServerRequestInterface $req): ?string
{
    $token = $params['token'] ?? '';
    if (!preg_match(self::TOKEN_PATTERN, $token)) {
        return null;  // 404 — nunca atinge o banco
    }
    return $token;
}
```

Isso rejeita payloads de injeção SQL, entradas muito grandes, hex maiúsculo e strings não-hex antes de qualquer query no banco.

## ATK-01: Injeção SQL no Caminho do Token

A regex de formato do token rejeita `' OR '1'='1` imediatamente. Mesmo que passasse, a query do banco usa uma instrução preparada:

```php
$stmt = $this->pdo->prepare('SELECT * FROM sessions WHERE token = :token');
$stmt->execute([':token' => $token]);
```

## ATK-02~04: Ataques de Formato de Token

Todos rejeitados pela regex `/\A[0-9a-f]{64}\z/`:
- String vazia (comprimento 0 ≠ 64)
- String muito grande (256 chars ≠ 64)
- Chars não-hex (`g`, `A`–`F` maiúsculo, chars especiais)
- Comprimento errado (63 ou 65 chars)

## ATK-05: Overflow de Inteiro no X-User-Id

```php
if ($raw === '' || !ctype_digit($raw) || strlen($raw) > 18) {
    return null;
}
```

Um inteiro de 19 dígitos excede o limite de 18 chars e é rejeitado antes de qualquer cast `(int)`.

## ATK-06: ID de Usuário Negativo / Zero

```php
$id = (int) $raw;
return $id > 0 ? $id : null;
```

`0` e valores negativos retornam `null`, o que dispara 400.

## ATK-07: Admin Key Fail-Closed

Um `adminKey` vazio nunca concede acesso admin:

```php
private function isAdmin(ServerRequestInterface $req): bool
{
    if ($this->adminKey === '') {
        return false;
    }
    return hash_equals($this->adminKey, $req->getHeaderLine('X-Admin-Key'));
}
```

`hash_equals()` impede ataques de timing ao comparar a chave.

## ATK-08: Bypass de Auth via X-User-Id: 0

`uid()` retorna `null` para ID=0 → 400, não 200.

## ATK-09: ID de Usuário Não-Numérico no Caminho de Lista

`ctype_digit()` rejeita `abc`, `1.5`, `-1`:

```php
if (!ctype_digit($raw) || strlen($raw) > 18) {
    return $this->problem(404, 'not-found', 'User not found.');
}
```

## ATK-10: TTL Float

`is_int()` é a verificação de tipo estrita do PHP — `60.5` retorna `false`:

```php
if (!is_int($ttlRaw) || $ttlRaw < 1 || $ttlRaw > self::MAX_TTL) {
    return $this->problem(422, ...);
}
```

## ATK-11: Revogação Dupla Retorna 404

O repositório verifica `revoked === 1` antes de atualizar:

```php
if ($session === null || (int) $session['revoked'] === 1) {
    return false;
}
```

Sessões já revogadas não podem ser "des-revogadas" reenviando um DELETE.

## ATK-12: Rejeição de Formato de Token para Força Bruta

Qualquer token que não corresponda exatamente a 64 chars hex minúsculos é rejeitado com 404 antes de tocar o banco de dados. Tentativas de força bruta atingem a barreira da regex, não o banco.

## IDOR: Proprietário vs Admin

- Não-proprietários tentando revogar ou listar sessões de outro usuário recebem 404 (não 403).
- Admins usam o header `X-Admin-Key`; fail-closed quando a chave não está configurada.

## Veja Também

- Fonte FT208: `../NENE2-FT/sessionlog/`
- Relacionado: `docs/howto/rate-limiting.md` (FT200, ATK)
- Relacionado: `docs/howto/coupon-redemption.md` (FT204, VULN + ATK)
