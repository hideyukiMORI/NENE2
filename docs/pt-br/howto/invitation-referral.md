# Como Fazer: API de Convite / Referral

Este guia mostra como construir um sistema de convites baseado em token com expiração e uso único usando NENE2.
Padrão demonstrado pelo field trial **invitelog** (FT221).

## Funcionalidades

- Gerar tokens de convite (`bin2hex(random_bytes(16))` = 32 caracteres hex minúsculos)
- Definir data de expiração por convite (ISO 8601)
- Aceitar/usar convite (uso único, rastreia convidado)
- Lista de convites com escopo por usuário (IDOR: apenas o próprio usuário pode ver)
- Ciclo de vida do status: `pending → used` (expirado detectado no uso)

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
```

## Endpoints

| Método | Caminho | Auth | Descrição |
|--------|------|------|-------------|
| `POST` | `/invitations` | Usuário | Criar convite (retorna token) |
| `GET` | `/invitations/{token}` | Público (token = segredo) | Obter status do convite |
| `POST` | `/invitations/{token}/use` | Usuário | Aceitar convite |
| `GET` | `/users/{userId}/invitations` | Usuário (apenas o próprio) | Listar próprios convites |

## Geração de Token

```php
$token = bin2hex(random_bytes(16)); // 32 chars hex minúsculos, criptograficamente seguro
```

Padrão de token validado em parâmetros de caminho:

```php
/** Token: 32 chars hex minúsculos (16 bytes aleatórios) */
public const string TOKEN_PATTERN = '/\A[0-9a-f]{32}\z/';
```

## Lógica de Uso Único

```php
/** @return 'ok'|'not_found'|'already_used'|'expired' */
public function use(string $token, int $inviteeId): string
{
    $inv = $this->findByToken($token);
    if ($inv === null) return 'not_found';
    if ($inv['status'] === 'used') return 'already_used'; // → 409
    if ($inv['expires_at'] < $this->now()) return 'expired'; // → 409

    // Marcar como usado + registrar convidado
    $this->pdo->prepare(
        "UPDATE invitations SET status = 'used', invitee_id = :iid, used_at = :now WHERE token = :token"
    )->execute([...]);

    return 'ok';
}
```

## Proteção IDOR

O endpoint de lista de convites reforça acesso apenas pelo próprio usuário:

```php
$callerUid = $this->uid($req);
if ($callerUid !== $targetUid) {
    return $this->problem(404, 'not-found', 'User not found.');
}
```

O endpoint `GET /invitations/{token}` usa o próprio token como segredo — conhecer o token concede acesso. Este é o padrão "token = capability".

## Padrões de Segurança

- **`bin2hex(random_bytes(16))`**: Token criptograficamente seguro com 128 bits de entropia
- **Validação do padrão de token**: `/\A[0-9a-f]{32}\z/` — bloqueia SQL injection, tokens excessivamente grandes
- **`ctype_digit()`**: validação de inteiro segura contra ReDoS para parâmetros de caminho de ID de usuário
- **Validação de expiração ISO 8601**: padrão regex + comparação lexicográfica (UTC)
- **Expiração verificada no uso**: Não pré-filtrada — consulta ao token retorna resultado, depois expiração é verificada
