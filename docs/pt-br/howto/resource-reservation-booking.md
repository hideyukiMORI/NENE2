# Como Fazer: API de Reserva e Agendamento de Recursos

> **Referência FT**: FT335 (`NENE2-FT/reservationlog`) — Agendamento de slots de tempo de recursos com prevenção de sobreposição de intervalo semi-aberto, user_id excluído das respostas públicas, proteção IDOR no cancelamento (403), acesso de dois níveis admin/usuário, 30 testes / 70+ assertivas PASS.

Este guia mostra como construir um sistema de reserva de sala/recurso: criar recursos reserváveis (admin), reservar slots de tempo (usuários), prevenir sobreposições atomicamente e proteger a privacidade do usuário nas respostas públicas.

## Schema

```sql
CREATE TABLE resources (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT    NOT NULL,
    created_at TEXT    NOT NULL
);

CREATE TABLE bookings (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    resource_id INTEGER NOT NULL REFERENCES resources(id),
    user_id     INTEGER NOT NULL,
    starts_at   TEXT    NOT NULL,   -- ISO 8601 UTC
    ends_at     TEXT    NOT NULL,
    note        TEXT,               -- opcional
    created_at  TEXT    NOT NULL
);
```

As datas são armazenadas como strings ISO 8601 UTC (`2026-06-01T09:00:00Z`). A comparação lexicográfica é correta para strings ISO UTC.

## Endpoints

| Método | Caminho | Auth | Descrição |
|--------|---------|------|-----------|
| `POST` | `/resources` | Admin | Criar um recurso reservável |
| `POST` | `/resources/{id}/book` | Usuário | Reservar um slot de tempo |
| `DELETE` | `/bookings/{id}` | Usuário (proprietário) | Cancelar própria reserva |
| `GET` | `/bookings` | Usuário | Listar próprias reservas |
| `GET` | `/resources/{id}/bookings` | Admin | Listar todas as reservas do recurso |

## Criar Recurso (Admin)

```php
POST /resources
X-Admin-Key: admin-secret
{"name": "Meeting Room 1"}
→ 201  {"resource": {"id": 1, "name": "Meeting Room 1", "created_at": "..."}}

// Sem chave admin
POST /resources  {"name": "Room"}
→ 401

POST /resources  X-Admin-Key: admin-secret  {"name": ""}
→ 422  // name obrigatório

POST /resources  X-Admin-Key: admin-secret  {"name": "x".repeat(201)}
→ 422  // name muito longo (máx 200 chars)
```

## Reservar um Slot de Tempo

```php
POST /resources/1/book
X-User-Id: 101
{"starts_at": "2026-06-01T09:00:00Z", "ends_at": "2026-06-01T10:00:00Z"}
→ 201
{
  "booking": {
    "id": 1,
    "resource_id": 1,
    "starts_at": "2026-06-01T09:00:00Z",
    "ends_at": "2026-06-01T10:00:00Z",
    "note": null,
    "created_at": "..."
    // user_id NÃO é retornado — prevenção de IDOR
  }
}

// Nota opcional
POST /resources/1/book  X-User-Id: 101
{"starts_at": "2026-06-01T09:00:00Z", "ends_at": "2026-06-01T10:00:00Z", "note": "Team meeting"}
→ 201  {"booking": {..., "note": "Team meeting"}}
```

### Erros de Validação

```php
// ends_at antes de starts_at
{"starts_at": "2026-06-01T10:00:00Z", "ends_at": "2026-06-01T09:00:00Z"}
→ 422

// starts_at == ends_at (duração zero)
{"starts_at": "2026-06-01T09:00:00Z", "ends_at": "2026-06-01T09:00:00Z"}
→ 422

// starts_at ausente
{"ends_at": "2026-06-01T10:00:00Z"}
→ 422

// Sem X-User-Id
POST /resources/1/book  (sem header)
→ 400

// X-User-Id zero ou overflow
X-User-Id: 0    → 400
X-User-Id: 9999999999999999999  → 400

// Recurso desconhecido
POST /resources/9999/book  X-User-Id: 101  {...}
→ 404
```

## Prevenção de Sobreposição — Intervalos Semi-Abertos

Os slots são **semi-abertos**: `[starts_at, ends_at)`. O fim de uma reserva e o início da próxima são iguais mas não sobrepostos.

```php
// Reservar 09:00–10:00
POST /resources/1/book  {"starts_at": "09:00", "ends_at": "10:00"}  → 201

// Adjacente — permitido
POST /resources/1/book  {"starts_at": "10:00", "ends_at": "11:00"}  → 201  ✅

// Sobreposição — dentro do slot existente
POST /resources/1/book  {"starts_at": "09:30", "ends_at": "11:00"}  → 409  ❌

// Slot idêntico
POST /resources/1/book  {"starts_at": "09:00", "ends_at": "10:00"}  → 409  ❌

// Sem sobreposição no mesmo recurso
POST /resources/1/book  {"starts_at": "14:00", "ends_at": "15:00"}  → 201  ✅

// Mesmo slot em recurso DIFERENTE — sempre permitido
POST /resources/2/book  {"starts_at": "09:00", "ends_at": "10:00"}  → 201  ✅
```

### Query SQL de Sobreposição

```sql
-- Detectar conflito: NOT (new.ends_at <= existing.starts_at OR new.starts_at >= existing.ends_at)
SELECT COUNT(*) FROM bookings
WHERE resource_id = ?
  AND starts_at < ?   -- existing.starts_at < new.ends_at
  AND ends_at   > ?   -- existing.ends_at > new.starts_at
```

Se count > 0, retornar 409 Conflict.

## Cancelar Reserva (Proteção IDOR)

```php
DELETE /bookings/1
X-User-Id: 101
→ 200  {"cancelled": true}

// Usuário errado → 403, NÃO 404
DELETE /bookings/1
X-User-Id: 102
→ 403  // usuário 102 não é proprietário da reserva 1

// Não encontrado → 404
DELETE /bookings/9999
X-User-Id: 101
→ 404
```

**Retorne 403 (não 404) para cancelamento com usuário errado** — retornar 404 permitiria que usuários sondem os IDs de reservas de outros usuários. A reserva existe; o solicitante não é o proprietário.

```php
// Após cancelamento, slot fica livre
DELETE /bookings/1  X-User-Id: 101  → 200
POST /resources/1/book  X-User-Id: 102  {"starts_at": "09:00", "ends_at": "10:00"}  → 201
```

### Validação de ID

```php
DELETE /bookings/0                    → 422  // zero é inválido
DELETE /bookings/99999999999999999999 → 422  // overflow
POST /resources/0/book  X-User-Id: 101 {...} → 422  // resource_id zero inválido
```

## Listar Próprias Reservas (Usuário)

```php
GET /bookings
X-User-Id: 101
→ 200
{
  "total": 2,
  "data": [
    {"id": 1, "resource_id": 1, "starts_at": "...", "ends_at": "...", "note": null, "created_at": "..."}
    // user_id NÃO está incluído
  ]
}

// Reservas de outros usuários não são retornadas
// Usuário 101 vê apenas suas próprias reservas mesmo se usuário 102 tiver reservas
```

## Listar Reservas do Recurso (Admin)

```php
GET /resources/1/bookings
X-Admin-Key: admin-secret
→ 200
{
  "total": 2,
  "data": [
    {"id": 1, "user_id": 101, "starts_at": "2026-06-01T09:00:00Z", ...},  // user_id visível
    {"id": 2, "user_id": 102, "starts_at": "2026-06-01T14:00:00Z", ...}
  ]
}
// Ordenado por starts_at ASC

GET /resources/1/bookings  (sem chave admin)  → 401
GET /resources/9999/bookings  X-Admin-Key: key  → 404
```

O admin recebe `user_id` na resposta; endpoints de usuário públicos nunca retornam `user_id`.

---

## O Que NÃO Fazer

| Antipadrão | Risco |
|---|---|
| Verificação de sobreposição com intervalos fechados | Slots adjacentes (fim de A = início de B) são rejeitados como sobrepostos |
| Retornar `user_id` nas respostas de reserva pública | Expõe quem possui cada reserva, habilitando enumeração de usuário |
| Retornar 404 para cancelamento com usuário errado | Atacante confirma que a reserva existe; use 403 para reconhecer incompatibilidade de propriedade |
| Aceitar `starts_at >= ends_at` | Reservas com duração zero ou negativa corrompem cálculos de disponibilidade |
| Sem escopo de resource_id na query de sobreposição | Reserva do usuário A no Recurso 1 bloqueia o Recurso 2 (conflito falso) |
| Confiar em `user_id` do corpo da requisição | Atacante faz reservas em nome de qualquer usuário; sempre leia a identidade do header `X-User-Id` |
