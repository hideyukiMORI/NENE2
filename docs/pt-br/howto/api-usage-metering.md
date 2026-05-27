# Como Fazer: Medição de Uso e Gerenciamento de Quota de API

> **Referência FT**: FT321 (`NENE2-FT/meterlog`) — Gerenciamento de quota diária por usuário, registro de uso protegido por machine-key, detalhamento por endpoint, proteção IDOR, garantia de que o saldo restante nunca fica negativo, 24 testes / 92 asserções PASS.

Este guia mostra como construir um sistema de medição de uso que rastreia chamadas de API por usuário por dia e aplica cotas diárias configuráveis.

## Schema

```sql
CREATE TABLE quotas (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id     INTEGER NOT NULL UNIQUE,
    daily_limit INTEGER NOT NULL,
    updated_at  TEXT    NOT NULL
);

CREATE TABLE usage_events (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id     INTEGER NOT NULL,
    endpoint    TEXT    NOT NULL,
    day_key     TEXT    NOT NULL,   -- 'YYYY-MM-DD'
    recorded_at TEXT    NOT NULL
);

CREATE INDEX idx_usage_user_day ON usage_events(user_id, day_key);
```

## Constantes

```php
const DEFAULT_DAILY_LIMIT = 1000;  // aplicado quando não existe linha de quota
```

## Modelo de Auth

```
POST /quotas               → X-Admin-Key   (configuração de quota)
POST /usage                → X-Machine-Key (registro de uso no lado do servidor)
POST /usage/check          → X-Machine-Key (verificação de quota pré-voo)
GET  /usage/{id}/breakdown → X-User-Id (próprio) OU X-Admin-Key (qualquer)
```

## Gerenciamento de Quota (Admin)

```php
POST /quotas  X-Admin-Key: admin-secret
{"user_id": 1, "daily_limit": 500}
→ 200  {"user_id": 1, "daily_limit": 500}

// Upsert — atualizar quota existente
POST /quotas  X-Admin-Key: admin-secret
{"user_id": 1, "daily_limit": 1000}
→ 200  {"user_id": 1, "daily_limit": 1000}

// Sem chave admin  → 401
// Chave errada     → 401
// daily_limit <= 0 → 422
```

## Status de Quota

```php
GET /quotas/1
→ 200
{
  "user_id": 1,
  "daily_limit": 500,
  "used": 3,
  "remaining": 497,
  "allowed": true
}

// Usuário sem linha de quota → DEFAULT_DAILY_LIMIT aplicado
GET /quotas/99
→ 200  {"user_id": 99, "daily_limit": 1000, "used": 0, "remaining": 1000, "allowed": true}
```

`remaining = max(0, daily_limit - used)` — **nunca fica negativo**.

## Registro de Uso

Chamado no lado do servidor após cada requisição de API bem-sucedida:

```php
POST /usage  X-Machine-Key: machine-secret
{"user_id": 1, "endpoint": "GET /articles"}
→ 201
{
  "recorded": true,
  "user_id": 1,
  "endpoint": "GET /articles",
  "day_key": "2026-05-27"
}

// Sem machine key → 401
// user_id <= 0   → 422
// endpoint vazio → 422
```

## Verificação de Quota Pré-voo

```php
POST /usage/check  X-Machine-Key: machine-secret
{"user_id": 1}
→ 200  {"allowed": true,  "remaining": 5, "used": 0}  // dentro da quota
→ 200  {"allowed": false, "remaining": 0, "used": 2}  // esgotada
```

## Detalhamento de Uso

```php
GET /usage/1/breakdown?date=2026-05-27  X-User-Id: 1
→ 200
{
  "user_id": 1,
  "date": "2026-05-27",
  "total": 3,
  "breakdown": [
    {"endpoint": "GET /articles", "count": 2},
    {"endpoint": "POST /articles", "count": 1}
  ]
}

// IDOR bloqueado
GET /usage/1/breakdown  X-User-Id: 2        → 403
// Admin pode acessar qualquer usuário
GET /usage/1/breakdown  X-Admin-Key: admin  → 200
// Data inválida
GET /usage/1/breakdown?date=not-a-date      → 422
```

---

## Avaliação de Vulnerabilidades

### V-01 — Quota Admin sem Chave ✅ SAFE

**Risco**: Chamador não autenticado define quota como 0 ou INT_MAX para qualquer usuário.
**Resultado**: SAFE — `POST /quotas` exige `X-Admin-Key`. Chave ausente ou errada retorna 401.

---

### V-02 — Bypass de Chave Admin por Variante de Capitalização ✅ SAFE

**Risco**: Atacante tenta `ADMIN-SECRET`, `admin_secret`, `""` para contornar a verificação de chave.
**Resultado**: SAFE — Correspondência exata com `hash_equals()`. Todas as variantes retornam 401.

---

### V-03 — daily_limit não Positivo ✅ SAFE

**Risco**: `daily_limit=0` ou `-1` bloqueia permanentemente o usuário.
**Resultado**: SAFE — 422 para `daily_limit <= 0`.

---

### V-04 — Registro de Uso sem Machine Key ✅ SAFE

**Risco**: Chamador externo registra uso falso para esgotar quota.
**Resultado**: SAFE — `POST /usage` exige `X-Machine-Key`. 401 para chave ausente/errada.

---

### V-05 — SQL Injection no Campo Endpoint ✅ SAFE

**Risco**: `"'; DROP TABLE usage_events; --"` corrompe o banco de dados.
**Resultado**: SAFE — Consultas parametrizadas. Injeção armazenada como string literal. Tabela sobrevive.

---

### V-06 — user_id não Positivo no Uso ✅ SAFE

**Risco**: `user_id=0/-1` insere linha para usuário inexistente.
**Resultado**: SAFE — 422 para `user_id <= 0`.

---

### V-07 — IDOR no Detalhamento ✅ SAFE

**Risco**: Usuário lê padrões de uso de endpoint de outro usuário.
**Resultado**: SAFE — `X-User-Id` comparado com `{id}` do caminho. Incompatibilidade → 403. Admin contorna.

---

### V-08 — Data Inválida no Detalhamento ✅ SAFE

**Risco**: Path traversal ou data impossível no parâmetro `date=` causa crash ou erro SQL.
**Resultado**: SAFE — Validação `/^\d{4}-\d{2}-\d{2}$/` + `checkdate()`. Inválida → 422.

---

### V-09 — Quota Restante Fica Negativa ✅ SAFE

**Risco**: `remaining` negativo mostrado aos clientes quando o uso excede quota reduzida.
**Resultado**: SAFE — `remaining = max(0, $daily_limit - $used)`.

---

### V-10 — String de Endpoint Vazia ✅ SAFE

**Risco**: Endpoint vazio cria linhas de detalhamento inutilizáveis.
**Resultado**: SAFE — 422 para `endpoint === ''`.

---

### Resumo VULN

| ID | Vulnerabilidade | Resultado |
|----|-----------------|-----------|
| V-01 | Quota admin sem chave | ✅ SAFE |
| V-02 | Bypass de chave por variante | ✅ SAFE |
| V-03 | daily_limit não positivo | ✅ SAFE |
| V-04 | Uso sem machine key | ✅ SAFE |
| V-05 | SQL injection no endpoint | ✅ SAFE |
| V-06 | user_id não positivo | ✅ SAFE |
| V-07 | IDOR no detalhamento | ✅ SAFE |
| V-08 | Formato de data inválido | ✅ SAFE |
| V-09 | Quota restante negativa | ✅ SAFE |
| V-10 | String de endpoint vazia | ✅ SAFE |

**10 SAFE, 0 EXPOSED** — Nenhuma descoberta crítica.

---

## O Que NÃO Fazer

| Anti-padrão | Risco |
|---|---|
| Deixar `remaining` ficar negativo | Números negativos confusos; lógica de portão quebra |
| Sem machine-key no registro de uso | Qualquer cliente infla/deflaciona a quota de outro usuário |
| Sem verificação IDOR no detalhamento | Padrões de uso de endpoint vazam para usuários não autorizados |
| Registrar uso antes da verificação de quota | Chamadas rejeitadas ainda consomem quota |
| Permitir `daily_limit=0` | Usuário permanentemente bloqueado desde o início |
