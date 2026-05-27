# Gerenciamento de Lista de Espera

Guia de implementação de lista de espera baseada em posição.
Explica o cálculo dinâmico de posição, máquina de estados, prevenção de IDOR e endpoints de administrador.

## Visão Geral

- Usuários entram na lista de espera (com nota opcional)
- **Cálculo dinâmico de posição**: a posição não é armazenada no banco, é calculada com `COUNT(*)` a cada consulta
- Máquina de estados: `waiting` → `approved` / `declined` (unidirecional, irreversível)
- Somente usuários em espera podem sair (`approved`/`declined` não podem)
- Administradores listam, aprovam e recusam entradas (cabeçalho `X-Admin-Key`)
- `user_id` não é incluído nas respostas ao usuário (prevenção de IDOR)

## Endpoints

| Método | Caminho | Auth | Descrição |
|---|---|---|---|
| `POST` | `/waitlist` | `X-User-Id` | Entrar na lista de espera |
| `GET` | `/waitlist/me` | `X-User-Id` | Obter própria entrada e posição |
| `DELETE` | `/waitlist/me` | `X-User-Id` | Sair da lista de espera |
| `GET` | `/waitlist` | `X-Admin-Key` | Listar todas as entradas (admin) |
| `POST` | `/waitlist/{id}/approve` | `X-Admin-Key` | Aprovar entrada |
| `POST` | `/waitlist/{id}/decline` | `X-Admin-Key` | Recusar entrada |

## Design do Banco de Dados

```sql
CREATE TABLE IF NOT EXISTS waitlist_entries (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL UNIQUE,
    status     TEXT    NOT NULL DEFAULT 'waiting',
    note       TEXT,
    created_at TEXT    NOT NULL,
    updated_at TEXT    NOT NULL
);
```

A restrição `UNIQUE` em `user_id` garante uma entrada por usuário.
Não há coluna de posição (calculada dinamicamente).

## Cálculo Dinâmico de Posição

Calcula a posição de uma entrada em espera pela ordem relativa de `id`:

```sql
SELECT COUNT(*) FROM waitlist_entries
WHERE status = 'waiting' AND id <= :id
```

Vantagens:
- Não é necessário atualizar todas as entradas a cada saída, aprovação ou recusa
- Sem conflitos de escrita
- Entradas com `status` diferente de `waiting` retornam `null`

```php
public function positionOf(WaitlistEntry $entry): ?int
{
    if ($entry->status !== WaitlistStatus::Waiting) {
        return null;
    }

    $stmt = $this->pdo->prepare(
        "SELECT COUNT(*) FROM waitlist_entries
         WHERE status = 'waiting' AND id <= :id",
    );
    $stmt->execute(['id' => $entry->id]);

    return (int) $stmt->fetchColumn();
}
```

## Máquina de Estados

```
waiting ──→ approved
        └─→ declined
```

- `waiting` só pode transitar para `approved` ou `declined`
- Uma vez em estado terminal, não pode ser alterado (verificado com `isTerminal()`)
- Somente usuários em espera podem usar `DELETE /waitlist/me` para sair

```php
enum WaitlistStatus: string
{
    case Waiting  = 'waiting';
    case Approved = 'approved';
    case Declined = 'declined';

    public function isTerminal(): bool
    {
        return $this !== self::Waiting;
    }
}
```

## Prevenção de IDOR

O endpoint para usuários (`/waitlist/me`) usa o cabeçalho `X-User-Id` para obter **somente a própria entrada**.
Não há como passar o `user_id` de outro usuário no caminho, e `user_id` não é incluído na resposta.

```php
/** Resposta para usuário (sem user_id) */
public function toPublicArray(): array
{
    return [
        'id'         => $this->id,
        'status'     => $this->status->value,
        'note'       => $this->note,
        'created_at' => $this->createdAt,
    ];
}

/** Resposta para administrador (com user_id) */
public function toAdminArray(): array
{
    return [
        'id'         => $this->id,
        'user_id'    => $this->userId,
        'status'     => $this->status->value,
        'note'       => $this->note,
        'created_at' => $this->createdAt,
        'updated_at' => $this->updatedAt,
    ];
}
```

## Autenticação de Administrador

Compara o cabeçalho `X-Admin-Key` em tempo constante com `hash_equals()`.
`adminKey` vazio sempre retorna `false` (fail-closed):

```php
private function isAdmin(ServerRequestInterface $request): bool
{
    if ($this->adminKey === '') {
        return false; // fail-closed
    }

    $provided = $request->getHeaderLine('X-Admin-Key');

    return $provided !== '' && hash_equals($this->adminKey, $provided);
}
```

## Ordem das Rotas

`GET /waitlist/me` deve ser registrado antes de `GET /waitlist`.
Se registrado depois, `me` pode ser capturado como `{id}`:

```php
$this->router->post('/waitlist',            $this->handleJoin(...));
$this->router->get('/waitlist/me',          $this->handleMe(...));      // antes de /waitlist
$this->router->delete('/waitlist/me',       $this->handleLeave(...));
$this->router->get('/waitlist',             $this->handleAdminList(...));
$this->router->post('/waitlist/{id}/approve', $this->handleApprove(...));
$this->router->post('/waitlist/{id}/decline', $this->handleDecline(...));
```

## Validação de X-User-Id

Previne overflow de inteiro, zero, negativos e não-numéricos:

```php
private function resolveUserId(ServerRequestInterface $request): ?int
{
    $raw = $request->getHeaderLine('X-User-Id');

    if ($raw === '' || !ctype_digit($raw) || strlen($raw) > 18) {
        return null;
    }

    $id = (int) $raw;

    return $id > 0 ? $id : null;
}
```

## Pontos de Segurança

| Ameaça | Medida |
|---|---|
| IDOR | `/waitlist/me` acessa apenas a própria entrada; `user_id` não incluído na resposta |
| Interceptação de chave admin | Comparação em tempo constante com `hash_equals()` |
| Overflow de inteiro | Proteção com `strlen > 18` |
| Entrada duplicada | Restrição `UNIQUE(user_id)` → 409 |
| Transição de estado inválida | `isTerminal()` proíbe mudanças após estado terminal |
| Injeção SQL | PDO prepared statements |

## Exemplos de Resposta

```json
// POST /waitlist (201)
{
    "entry": { "id": 1, "status": "waiting", "note": "Solicitação VIP", "created_at": "..." },
    "position": 1
}

// GET /waitlist/me — caso approved (200)
{
    "entry": { "id": 1, "status": "approved", "note": "Solicitação VIP", "created_at": "..." },
    "position": null
}

// GET /waitlist (admin, 200)
{
    "data": [
        { "id": 1, "user_id": 101, "status": "approved", "note": "Solicitação VIP", ... },
        { "id": 2, "user_id": 102, "status": "waiting",  "note": null,              ... }
    ],
    "total": 2
}
```

## Guias Relacionados

- [Gerenciamento de anúncios do sistema](system-announcement-management.md) — padrão de autenticação com chave de admin (mesmo `hash_equals()`)
- [Gerenciamento de consentimento de privacidade](privacy-consent-management.md) — UPSERT e operações idempotentes
- [Soft delete](soft-delete.md) — padrão de flag de exclusão (saída é exclusão física)
- [Prevenção de double-booking em reservas](prevent-double-booking.md) — prevenção de conflitos com restrição UNIQUE
