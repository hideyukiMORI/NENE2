# Como Fazer: Sistema de Convites

> **Referência FT**: FT283 (`NENE2-FT/invitelog`) — Sistema de código de convite: token hex de 32 chars (128 bits de entropia), validação de datetime ISO 8601, ciclo de vida de status pending→used, mapeamento de status com match expression, lista de convites protegida por IDOR, 23 testes / 47 asserções PASS.

Este guia mostra como construir um sistema de convites seguro — gerar tokens de uso único que concedem acesso quando resgatados.

## Caso de uso

Um usuário cria um link de convite (token) e o compartilha. O destinatário resgata o token para participar. Cada token é de uso único e tem tempo limitado.

## Schema

```sql
CREATE TABLE IF NOT EXISTS invitations (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    token       TEXT    NOT NULL UNIQUE,
    inviter_id  INTEGER NOT NULL,
    invitee_id  INTEGER,
    status      TEXT    NOT NULL DEFAULT 'pending',
    expires_at  TEXT    NOT NULL,
    used_at     TEXT,
    created_at  TEXT    NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_invitations_token ON invitations (token);
CREATE INDEX IF NOT EXISTS idx_invitations_inviter ON invitations (inviter_id, id DESC);
```

Pontos principais:
- `token TEXT UNIQUE` — reforça um-token-por-linha no nível do BD
- `invitee_id` é `NULL` até ser resgatado
- `status` — `'pending'` | `'used'`
- `used_at` — definido quando resgatado, fornece timestamp de auditoria

## Endpoints

| Método | Caminho | Auth | Descrição |
|--------|------|------|-------------|
| `POST` | `/invitations` | `X-User-Id` | Criar convite |
| `GET` | `/invitations/{token}` | Nenhuma | Buscar convite por token |
| `POST` | `/invitations/{token}/use` | `X-User-Id` | Resgatar convite |
| `GET` | `/users/{userId}/invitations` | `X-User-Id` (apenas o próprio) | Listar convites do usuário |

## Geração de Token

```php
/** Token: 32 chars hex minúsculos (16 bytes aleatórios = 128 bits de entropia) */
public const string TOKEN_PATTERN = '/\A[0-9a-f]{32}\z/';

$token = bin2hex(random_bytes(16));
```

`random_bytes(16)` gera 128 bits de dados aleatórios criptograficamente seguros. A representação hex tem 32 caracteres. Este é o mesmo nível de entropia do UUID v4 (122 bits utilizáveis).

## Validação de expires_at

```php
private const string ISO_DATE_PATTERN = '/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/';

$expiresAt = trim((string) ($body['expires_at'] ?? ''));
if (!preg_match(self::ISO_DATE_PATTERN, $expiresAt)) {
    return $this->problem(422, 'validation-failed', 'expires_at must be ISO 8601 datetime.');
}
```

A regex valida apenas o formato. O tratamento de fuso horário (UTC vs local) é responsabilidade da aplicação — usar um fuso horário consistente (ex.: UTC) evita casos extremos.

## Ciclo de Vida do Status

```
pending → used (unidirecional, irreversível)
```

Apenas convites `pending` podem ser resgatados. Uma vez usado, o convite é permanentemente consumido.

## Resgate com match Expression

```php
$result = $this->repo->use($token, $uid);

return match ($result) {
    'not_found'    => $this->problem(404, 'not-found', 'Invitation not found.'),
    'already_used' => $this->problem(409, 'conflict', 'Invitation already used.'),
    'expired'      => $this->problem(409, 'conflict', 'Invitation has expired.'),
    default        => $this->json(['message' => 'Invitation accepted.']),
};
```

`match` é exaustivo (ao contrário de `switch`): sem fall-through, deve tratar todos os casos. O repositório retorna um tipo de resultado string; o handler mapeia para respostas HTTP de forma limpa.

## Repositório — Resgate Atômico

```php
/** @return 'ok'|'not_found'|'already_used'|'expired' */
public function use(string $token, int $inviteeId): string
{
    $inv = $this->findByToken($token);
    if ($inv === null) {
        return 'not_found';
    }
    if ($inv['status'] === 'used') {
        return 'already_used';
    }
    // Verificar expiração
    $now = $this->now();
    if ($inv['expires_at'] < $now) {
        return 'expired';
    }

    // Marcar como usado
    $this->pdo->prepare('UPDATE invitations SET status = \'used\', invitee_id = ?, used_at = ? WHERE token = ?')
        ->execute([$inviteeId, $now, $token]);

    return 'ok';
}
```

A sequência verificar-depois-atualizar é uma potencial condição de corrida TOCTOU para resgates concorrentes do mesmo token. Para produção, use uma transação de BD ou `UPDATE WHERE status = 'pending'` e verifique as linhas afetadas.

## IDOR — Lista de Convites

Apenas o convidante pode ver seus próprios convites:

```php
$callerUid = $this->uid($req);
if ($callerUid !== $targetUid) {
    return $this->problem(404, 'not-found', 'User not found.');
}
```

Retorna 404 (não 403) para ocultar se o usuário alvo existe.

## Validação do Header X-User-Id

```php
private function uid(ServerRequestInterface $req): ?int
{
    $raw = $req->getHeaderLine('X-User-Id');
    if ($raw === '' || !ctype_digit($raw) || strlen($raw) > 18) {
        return null;
    }
    $id = (int) $raw;
    return $id > 0 ? $id : null;
}
```

`ctype_digit()` é seguro contra ReDoS; `strlen > 18` previne overflow de int PHP em 64 bits; `> 0` rejeita user ID 0.

---

## O Que NÃO Fazer

| Anti-padrão | Risco |
|---|---|
| Usar token curto/sequencial (PIN de 4 dígitos) | Brute-forceable em milissegundos; use ≥128 bits aleatórios |
| Armazenar token sem constraint `UNIQUE` | Colisão de token duplicado causa confusão no resgate |
| Verificar `status === 'pending'` com comparação permissiva | PHP `'0' == false`; sempre use `===` estrito |
| Sem validação de expiração no resgate | Convites expirados permanecem resgatáveis indefinidamente |
| Retornar 403 na verificação IDOR da lista de convites | Revela que o usuário alvo existe; use 404 para ocultar enumeração |
| Resgate atômico sem transação | Requisições concorrentes podem ambas ver `pending` e ambas ter sucesso — duplo resgate |
| Soft delete (`deleted_at`) em vez de coluna de status | Coluna de status é auto-documentada; `pending`/`used` é mais claro que null/não-null |
| Aceitar qualquer string como `expires_at` | SQL-injectable se não parametrizado; use query parametrizada + validação de formato |
| Resetar status para `pending` em token expirado | Permite reutilização de tokens que foram legitimamente expirados |
