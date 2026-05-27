# Como Fazer: API de Reserva de Recursos / Agendamento de Slots de Tempo

Este guia mostra como construir um sistema de agendamento de slots de tempo com prevenção de sobreposição usando o NENE2.
Padrão demonstrado pelo field trial **reservationlog** (FT216).

## Funcionalidades

- Criar recursos nomeados (salas de reunião, equipamentos, etc.) — somente admin
- Reservar slots de tempo com detecção automática de sobreposição
- Listar reservas por recurso (admin) ou por usuário (próprio)
- Cancelar reservas com verificação de propriedade
- Respostas públicas excluem `user_id` (prevenção de IDOR)
- Visão admin inclui `user_id` para auditoria

## Schema

```sql
CREATE TABLE IF NOT EXISTS resources (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT    NOT NULL,
    created_at TEXT    NOT NULL
);

CREATE TABLE IF NOT EXISTS bookings (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    resource_id INTEGER NOT NULL,
    user_id     INTEGER NOT NULL,
    starts_at   TEXT    NOT NULL,   -- ISO 8601 UTC
    ends_at     TEXT    NOT NULL,   -- ISO 8601 UTC
    note        TEXT,
    created_at  TEXT    NOT NULL,
    FOREIGN KEY (resource_id) REFERENCES resources(id) ON DELETE CASCADE
);

-- Índice para queries rápidas de sobreposição
CREATE INDEX IF NOT EXISTS idx_bookings_resource_time
    ON bookings (resource_id, starts_at, ends_at);
```

## Endpoints

| Método | Caminho | Auth | Descrição |
|--------|---------|------|-----------|
| `POST` | `/resources` | Admin | Criar recurso |
| `GET` | `/resources/{id}/bookings` | Admin | Listar todas as reservas do recurso |
| `POST` | `/resources/{id}/book` | Usuário | Reservar um slot de tempo |
| `GET` | `/bookings` | Usuário | Listar próprias reservas |
| `DELETE` | `/bookings/{id}` | Usuário | Cancelar própria reserva |

## Detecção de Sobreposição

Dois intervalos de tempo `[A.start, A.end)` e `[B.start, B.end)` se sobrepõem se e somente se:

```
A.start < B.end AND A.end > B.start
```

Isso trata corretamente todos os casos de sobreposição (contém, sobrepõe, idêntico) enquanto permite
slots adjacentes (A.end = B.start está OK — semântica de intervalo semi-aberto).

```sql
SELECT COUNT(*) FROM bookings
WHERE resource_id = :rid
  AND starts_at < :ends_at
  AND ends_at   > :starts_at
```

```php
public function book(int $resourceId, int $userId, string $startsAt, string $endsAt, ?string $note): ?Booking
{
    $overlap = $this->countOverlaps($resourceId, $startsAt, $endsAt, excludeId: null);
    if ($overlap > 0) {
        return null; // → 409 Conflict
    }
    // ... INSERT
}
```

## Value Objects

Usando value objects readonly para clareza de domínio:

```php
final readonly class Booking
{
    public function __construct(
        public int     $id,
        public int     $resourceId,
        public int     $userId,
        public string  $startsAt,
        public string  $endsAt,
        public ?string $note,
        public string  $createdAt,
    ) {}

    /** Visão pública: exclui user_id (prevenção de IDOR) */
    public function toPublicArray(): array { ... }

    /** Visão admin: inclui user_id para auditoria */
    public function toAdminArray(): array { ... }
}
```

## Prevenção de IDOR

As reservas expõem visões públicas e de admin com campos diferentes:

```php
// Usuário: GET /bookings — visão pública (sem user_id)
return $this->responseFactory->create([
    'data'  => array_map(fn(Booking $b) => $b->toPublicArray(), $bookings),
    'total' => count($bookings),
]);

// Admin: GET /resources/{id}/bookings — visão admin (inclui user_id)
return $this->responseFactory->create([
    'data'  => array_map(fn(Booking $b) => $b->toAdminArray(), $bookings),
    'total' => count($bookings),
]);
```

Cancelar retorna 403 (não 404) quando um usuário tenta cancelar a reserva de outra pessoa,
já que o ID da reserva já é visível (existência não oculta):

```php
/** @return 'cancelled'|'not_found'|'not_owner' */
public function cancel(int $id, int $userId): string
{
    $booking = $this->findBookingById($id);
    if ($booking === null) return 'not_found';     // → 404
    if ($booking->userId !== $userId) return 'not_owner'; // → 403
    // DELETE ...
    return 'cancelled'; // → 200
}
```

## Padrões de Segurança

- **Admin fail-closed**: `if ($this->adminKey === '') return false;` antes de `hash_equals()`
- **`ctype_digit()`**: validação de inteiro segura contra ReDoS para IDs de caminho
- **Validação ISO 8601**: padrão regex + comparação lexicográfica (funciona em UTC)
- **Guarda de comprimento de nota**: `mb_strlen($note) > 500` retorna 422
- **Cascade delete**: `ON DELETE CASCADE` garante que reservas sejam removidas com o recurso

## Avaliação VULN + ATK (FT216)

Este FT passa nas avaliações completas VULN-A a VULN-L e ATK-01 a ATK-12:

- **VULN-B**: Sem atribuição em massa — campos de recurso/reserva são explicitamente vinculados
- **VULN-C**: Cancelar retorna 403 para proprietário errado; buscas de recurso/reserva usam IDs tipados
- **VULN-D**: Admin fail-closed — chave admin vazia sempre retorna false
- **VULN-F**: Regex ISO 8601 impede injeção de datetime
- **VULN-G**: `ctype_digit()` protege todos os parâmetros de caminho inteiros
- **ATK-01**: Injeção SQL bloqueada via queries parametrizadas
- **ATK-02/03**: Overflow de inteiro em IDs bloqueado pela guarda `strlen > 18`
- **ATK-06**: Bypass de autenticação bloqueado pela verificação admin fail-closed
- **ATK-09**: Lógica de sobreposição impede corretamente reservas duplas
