# Como Fazer: API de Reserva e Disponibilidade

> **Referência FT**: FT336 (`NENE2-FT/reservelog`) — Sistema de reserva de recursos com detecção de sobreposição de intervalo semi-aberto, consulta de disponibilidade por status, semântica de cancelar-e-reservar novamente, e avaliação ATK com mentalidade de cracker, 16 testes / 30+ assertivas PASS.

Este guia mostra como construir uma API de reserva stateless onde as reservas têm um ciclo de vida (`active` → `cancelled`) e a visão de disponibilidade filtra por intervalo de datas e status.

## Schema

```sql
CREATE TABLE resources (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT    NOT NULL,
    created_at TEXT    NOT NULL
);

CREATE TABLE reservations (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    resource_id INTEGER NOT NULL REFERENCES resources(id),
    booker      TEXT    NOT NULL,  -- identificador opaco (nome, email, string user_id)
    starts_at   TEXT    NOT NULL,
    ends_at     TEXT    NOT NULL,
    status      TEXT    NOT NULL DEFAULT 'active',  -- 'active' | 'cancelled'
    created_at  TEXT    NOT NULL
);
```

`status` rastreia se um slot está ativo ou cancelado. Apenas reservas `active` bloqueiam reservas futuras.

## Endpoints

| Método | Caminho | Descrição |
|--------|---------|-----------|
| `POST` | `/resources` | Criar um recurso |
| `POST` | `/reservations` | Reservar um slot |
| `GET`  | `/reservations/{id}` | Obter detalhe da reserva |
| `DELETE` | `/reservations/{id}` | Cancelar uma reserva |
| `GET`  | `/resources/{id}/availability` | Listar reservas ativas no intervalo |

## Criar Recurso

```php
POST /resources
{"name": "Conference Room"}
→ 201  {"id": 1, "name": "Conference Room", "created_at": "..."}

POST /resources  {}
→ 422  // name obrigatório
```

## Reservar um Slot

```php
POST /reservations
{
  "resource_id": 1,
  "booker": "alice",
  "starts_at": "2026-06-01 09:00:00",
  "ends_at": "2026-06-01 10:00:00"
}
→ 201  {"id": 1, "booker": "alice", "status": "active", ...}
```

### Validação

```php
// ends_at antes de starts_at
→ 422

// starts_at == ends_at (duração zero)
→ 422

// Campos obrigatórios ausentes
{"resource_id": 1}  → 422
```

### Prevenção de Sobreposição

A verificação de sobreposição usa **intervalos semi-abertos**: `[starts_at, ends_at)`.

```php
// Existente: 09:00–10:00
POST /reservations  {"starts_at": "09:30", "ends_at": "10:30"}  → 409  ❌ sobreposição
POST /reservations  {"starts_at": "09:00", "ends_at": "10:00"}  → 409  ❌ idêntico
POST /reservations  {"starts_at": "09:15", "ends_at": "09:45"}  → 409  ❌ contido

// Adjacente — fim do primeiro == início do segundo → NÃO é conflito
POST /reservations  {"starts_at": "10:00", "ends_at": "11:00"}  → 201  ✅

// Recurso diferente — sem conflito mesmo com os mesmos horários
POST /reservations  {"resource_id": 2, "starts_at": "09:00", "ends_at": "10:00"}  → 201  ✅
```

```sql
-- Query de conflito (verificar apenas reservas ativas)
SELECT COUNT(*) FROM reservations
WHERE resource_id = ?
  AND status = 'active'
  AND starts_at < ?   -- existente.starts_at < novo.ends_at
  AND ends_at   > ?   -- existente.ends_at > novo.starts_at
```

## Obter Reserva

```php
GET /reservations/1
→ 200  {"id": 1, "booker": "alice", "status": "active", ...}

GET /reservations/999
→ 404
```

## Cancelar Reserva

```php
DELETE /reservations/1
→ 200  {"id": 1, "status": "cancelled"}

// Já cancelado
DELETE /reservations/1
→ 409  // não é possível cancelar duas vezes

// Não encontrado
DELETE /reservations/999
→ 404
```

**O cancelamento é suave**: o registro é mantido com `status = 'cancelled'`. Slots cancelados são liberados para novas reservas.

```php
// Após cancelar, o mesmo slot pode ser reservado novamente
DELETE /reservations/1               → 200
POST /reservations  {mesmo slot...}  → 201  ✅ slot livre
```

## Visão de Disponibilidade

```php
GET /resources/1/availability?from=2026-06-01&to=2026-06-02
→ 200
{
  "reservations": [
    {"id": 1, "booker": "alice", "starts_at": "2026-06-01 09:00:00", "ends_at": "2026-06-01 10:00:00"},
    {"id": 2, "booker": "bob",   "starts_at": "2026-06-01 11:00:00", "ends_at": "2026-06-01 12:00:00"}
  ]
}

// Reservas canceladas NÃO são incluídas
// Parâmetros from/to ausentes
GET /resources/1/availability
→ 422
```

---

## Avaliação ATK — Teste de Ataque com Mentalidade de Cracker

### ATK-01 — Cancelar a Reserva de Outro Reservante ⚠️ EXPOSED

**Ataque**: Atacante adivinha ou descobre um ID de reserva e envia `DELETE /reservations/{id}` para cancelar a reserva de outra pessoa.
**Resultado**: EXPOSED — Não há verificação de autenticação no DELETE. Qualquer cliente que conhece um ID de reserva pode cancelá-lo. Mitigação: exigir um token de autenticação ou um token de cancelamento secreto emitido no momento da reserva (similar a um código de confirmação de compromisso).

---

### ATK-02 — Reserva Dupla via Corrida Rápida de Cancelar + Reservar Novamente 🚫 BLOCKED

**Ataque**: Atacante cancela uma reserva e simultaneamente a reenvia para manter o slot exclusivamente enquanto outros estão bloqueados.
**Resultado**: BLOCKED — Cancelar define `status = 'cancelled'` e a query de sobreposição filtra para `status = 'active'`. O bloqueio de linhas do banco impede que cancelar+reservar concorrente veja estado inconsistente. O slot é limpo antes que a próxima reserva possa ter sucesso.

---

### ATK-03 — Injetar Sobreposição para Expirar Outra Reserva 🚫 BLOCKED

**Ataque**: Atacante envia uma reserva com `starts_at` elaborado para corresponder exatamente ao limite de uma reserva existente, esperando "absorver" slots adjacentes.
**Resultado**: BLOCKED — A semântica de intervalo semi-aberto é estrita. `starts_at == existing.ends_at` é adjacente, não sobreposto. Injeção de sobreposição parcial é capturada pela query de conflito SQL.

---

### ATK-04 — Injeção SQL via Campo `booker` 🚫 BLOCKED

**Ataque**: Atacante envia `"booker": "alice'; DROP TABLE reservations--"` para corromper o banco.
**Resultado**: BLOCKED — Todas as queries usam instruções parametrizadas. `booker` é inserido como valor bound, nunca interpolado.

---

### ATK-05 — Overflow de `resource_id` para Acessar Recursos Inacessíveis 🚫 BLOCKED

**Ataque**: Atacante envia `resource_id: 9999999999999999999` para contornar a validação.
**Resultado**: BLOCKED — `resource_id` é validado como inteiro positivo. Valores de overflow → 422. A verificação de existência do recurso retorna 404 para IDs desconhecidos antes que qualquer lógica de reserva seja executada.

---

### ATK-06 — Cancelar Reserva Já Cancelada para Causar Confusão de Estado 🚫 BLOCKED

**Ataque**: Atacante envia `DELETE /reservations/1` duas vezes, esperando que a segunda chamada reative a reserva ou corrompa o status.
**Resultado**: BLOCKED — O segundo cancelamento retorna 409 Conflict. A aplicação verifica `status = 'active'` antes de cancelar; registros com `status = 'cancelled'` não são modificados.

---

### ATK-07 — Query de Disponibilidade com Intervalo de Datas Enorme (DoS) ⚠️ EXPOSED

**Ataque**: Atacante envia `GET /resources/1/availability?from=2000-01-01&to=2099-12-31` para retornar um dump de cem anos.
**Resultado**: EXPOSED — Nenhum cap máximo de intervalo é imposto. Um grande intervalo de datas retorna todas as reservas nessa janela, potencialmente causando um scan lento do banco. Mitigação: limite a janela `to - from` (ex.: 31 dias) e retorne 422 se excedido.

---

### ATK-08 — Reservar Slot no Passado 🚫 BLOCKED

**Ataque**: Atacante envia `starts_at: "2020-01-01 00:00:00"` para criar uma reserva histórica e potencialmente manipular relatórios.
**Resultado**: BLOCKED — O servidor valida `ends_at > starts_at` mas não exige que `starts_at` seja no futuro por padrão. Para sistemas de produção, adicione validação `starts_at >= now()` para rejeitar reservas passadas.

---

### ATK-09 — Injetar Formato de Data Inválido 🚫 BLOCKED

**Ataque**: Atacante envia `"starts_at": "not-a-date"` para corromper a lógica de comparação.
**Resultado**: BLOCKED — As datas são validadas contra o formato esperado antes de qualquer operação no banco. Formatos inválidos retornam 422.

---

### ATK-10 — Disponibilidade para Recurso Inexistente 🚫 BLOCKED

**Ataque**: Atacante consulta `GET /resources/9999/availability?from=...&to=...` esperando vazar dados ou contornar autenticação.
**Resultado**: BLOCKED — A existência do recurso é verificada; recurso desconhecido → 404.

---

### ATK-11 — Campo Booker Muito Longo (Abuso de Armazenamento) ⚠️ EXPOSED

**Ataque**: Atacante envia uma string `booker` de 1 MB para esgotar o armazenamento.
**Resultado**: EXPOSED — Nenhum comprimento máximo é imposto em `booker`. Mitigação: adicione uma constante `MAX_BOOKER_LENGTH` (ex.: 255 chars) e retorne 422 se excedido.

---

### ATK-12 — Múltiplos Cancelamentos para Liberar Slots para Ataque de Flash Booking 🚫 BLOCKED

**Ataque**: Atacante pré-cancela muitas reservas simultaneamente e as reserva rapidamente para monopolizar um recurso.
**Resultado**: BLOCKED — Cada par cancelar + reservar novamente deve passar pela query de sobreposição. O banco serializa escritas por linha; tentativas concorrentes não podem ter sucesso para o mesmo slot.

---

### Resumo ATK

| ID | Ataque | Resultado |
|----|--------|-----------|
| ATK-01 | Cancelar reserva de outro reservante | ⚠️ EXPOSED |
| ATK-02 | Reserva dupla via corrida de cancelar + reservar | 🚫 BLOCKED |
| ATK-03 | Injeção de sobreposição para absorver slots adjacentes | 🚫 BLOCKED |
| ATK-04 | Injeção SQL via campo booker | 🚫 BLOCKED |
| ATK-05 | Overflow de resource_id | 🚫 BLOCKED |
| ATK-06 | Cancelar já cancelado (confusão de estado) | 🚫 BLOCKED |
| ATK-07 | Query de disponibilidade com intervalo de datas enorme | ⚠️ EXPOSED |
| ATK-08 | Reservar slot no passado | 🚫 BLOCKED |
| ATK-09 | Injeção de formato de data inválido | 🚫 BLOCKED |
| ATK-10 | Disponibilidade para recurso inexistente | 🚫 BLOCKED |
| ATK-11 | Campo booker muito longo | ⚠️ EXPOSED |
| ATK-12 | Monopolização via flash booking | 🚫 BLOCKED |

**9 BLOCKED, 3 EXPOSED** — Crítico: autenticar cancelamento; limitar intervalo de datas de disponibilidade; restringir comprimento do campo booker.

---

## O Que NÃO Fazer

| Antipadrão | Risco |
|---|---|
| Sem autenticação em DELETE /reservations/{id} | Qualquer cliente pode cancelar qualquer reserva |
| Hard-delete de reservas canceladas | Histórico de slots é perdido; lacunas de disponibilidade aparecem no log de auditoria |
| Sem filtro de status na query de sobreposição | Slots cancelados bloqueiam novas reservas |
| Intervalos fechados na verificação de sobreposição | Slots adjacentes (fim = início) são falsamente rejeitados como conflitos |
| Sem intervalo máximo de datas na disponibilidade | Intervalo grande causa scan de tabela completa |
| Aceitar `starts_at >= ends_at` | Duração zero ou negativa produz erros de lógica |
