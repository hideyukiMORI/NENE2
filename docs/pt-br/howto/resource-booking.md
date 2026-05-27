# Como Fazer: Sistema de Reserva de Recursos

## Visão Geral

Este guia cobre a construção de uma API de reserva de recursos com o NENE2. As funcionalidades incluem imposição de capacidade, prevenção de reservas duplas, isolamento IDOR por usuário e cancelamento administrativo.

**Implementação de referência**: `../NENE2-FT/bookinglog/`

---

## Design do Schema

```sql
CREATE TABLE IF NOT EXISTS resources (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT    NOT NULL UNIQUE,
    capacity   INTEGER NOT NULL DEFAULT 1,
    created_at TEXT    NOT NULL
);

CREATE TABLE IF NOT EXISTS bookings (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    resource_id INTEGER NOT NULL,
    user_id     INTEGER NOT NULL,
    slot_date   TEXT    NOT NULL,   -- 'YYYY-MM-DD'
    slot_hour   INTEGER NOT NULL,   -- 0-23
    created_at  TEXT    NOT NULL,
    cancelled   INTEGER NOT NULL DEFAULT 0,
    FOREIGN KEY (resource_id) REFERENCES resources(id) ON DELETE CASCADE,
    UNIQUE (resource_id, user_id, slot_date, slot_hour)
);
```

Restrições principais:
- `UNIQUE (resource_id, user_id, slot_date, slot_hour)` — uma reserva por usuário por slot.
- Flag de soft-delete `cancelled` — preserva o histórico enquanto permite novas reservas.
- Capacidade verificada no momento da consulta (conta reservas ativas vs resource.capacity).

---

## Tabela de Rotas

| Método | Caminho | Auth | Descrição |
|--------|---------|------|-----------|
| `GET` | `/resources` | Nenhuma | Listar todos os recursos |
| `POST` | `/resources` | Admin | Criar um recurso |
| `POST` | `/bookings` | Usuário | Reservar um slot |
| `GET` | `/bookings` | Usuário | Listar próprias reservas |
| `GET` | `/bookings/{id}` | Usuário | Obter uma reserva |
| `DELETE` | `/bookings/{id}` | Usuário/Admin | Cancelar uma reserva |

---

## Prevenção de Reservas Duplas

Primeiro, verificar se o usuário já tem este slot (nível de aplicação):

```php
$stmt = $this->pdo->prepare(
    'SELECT id FROM bookings WHERE resource_id = :rid AND user_id = :uid
     AND slot_date = :d AND slot_hour = :h AND cancelled = 0'
);
$stmt->execute([...]);
if ($stmt->fetch() !== false) {
    return 'double_booking';
}
```

Em seguida, verificar capacidade:

```php
$count = $this->countSlotBookings($resourceId, $date, $hour);
if ($count >= (int) $resource['capacity']) {
    return 'capacity_full';
}
```

---

## Isolamento IDOR

Os usuários só podem ler/cancelar suas próprias reservas. Retorne 404 (não 403) para evitar revelar existência:

```php
if (!$this->isAdmin($req) && (int) $booking['user_id'] !== $uid) {
    return $this->problem(404, 'not-found', 'Booking not found.');
}
```

---

## Admin Cancela Sem X-User-Id

O admin pode cancelar qualquer reserva sem fornecer seu próprio ID de usuário:

```php
$isAdmin = $this->isAdmin($req);
$uid     = $this->uid($req);
if ($uid === null && !$isAdmin) {
    return $this->problem(400, 'bad-request', 'X-User-Id required.');
}
$result = $this->repo->cancel($id, $uid ?? 0, $isAdmin);
```

---

## Regras de Validação

| Campo | Regra |
|-------|-------|
| `resource_id` | `is_int()` + positivo |
| `slot_date` | regex `/\A\d{4}-\d{2}-\d{2}\z/` |
| `slot_hour` | `is_int()` + 0–23 |
| `capacity` | `is_int()` + positivo |
| `name` | string não vazia |

---

## Códigos de Status HTTP

| Situação | Status |
|----------|--------|
| Recurso criado | 201 |
| Reserva confirmada | 201 |
| Reserva encontrada / lista | 200 |
| Sem X-User-Id | 400 |
| Tipo de campo inválido | 422 |
| Formato de data inválido | 422 |
| slot_hour fora de 0–23 | 422 |
| Recurso não encontrado | 404 |
| Reserva não encontrada | 404 |
| Sem chave admin | 403 |
| Cancelar própria reserva | 200 |
| Cancelar reserva de outro | 403 |
| Reserva dupla | 409 |
| Capacidade cheia | 409 |

---

## Padrões VULN Cobertos

| VULN | Padrão | Defesa |
|------|--------|--------|
| A | IDOR: usuário vê reserva de outro | `WHERE user_id = :uid` + 404 |
| B | resource_id negativo | Verificação `is_int() + > 0` |
| C | slot_hour zero (meia-noite) | Intervalo 0-23 permite 0 |
| D | Injeção SQL em slot_date | Validação regex + query parametrizada |
| E | Confusão de tipo resource_id string | Verificação estrita `is_int()` |
| F | Reserva dupla | Verificação de existência antes do INSERT |
| G | Overflow de capacidade | Verificação de COUNT vs capacity |
| H | Sem X-User-Id | 400 com mensagem |
| I | Cancelar reserva de outro usuário | Verificação de propriedade `user_id` → 403 |
| J | Lista vaza dados de outro usuário | `WHERE user_id = :uid` |
| K | Admin cancela qualquer reserva | Bypass de propriedade `isAdmin` |
| L | slot_hour = 24 (fora do intervalo) | `$hour > 23` → 422 |
