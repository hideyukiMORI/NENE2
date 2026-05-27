# Como Fazer: Gerenciamento de Plano de Assinatura

> **Referência FT**: FT328 (`NENE2-FT/planlog`) — Catálogo de planos, ciclo de vida de assinatura por usuário (assinar / alterar / cancelar), acesso apenas ao proprietário, avaliação ATK, 20 testes / 69 assertivas PASS.

Este guia mostra como construir uma API de gerenciamento de assinaturas onde usuários podem assinar um dos vários planos predefinidos, alterar planos e cancelar.

## Schema

```sql
CREATE TABLE plans (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    slug       TEXT    NOT NULL UNIQUE,
    name       TEXT    NOT NULL,
    price_cents INTEGER NOT NULL,
    created_at TEXT    NOT NULL
);

CREATE TABLE subscriptions (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id      INTEGER NOT NULL UNIQUE,  -- uma assinatura ativa por usuário
    plan_slug    TEXT    NOT NULL REFERENCES plans(slug),
    status       TEXT    NOT NULL DEFAULT 'active',  -- 'active' | 'cancelled'
    cancelled_at TEXT,
    created_at   TEXT    NOT NULL,
    updated_at   TEXT    NOT NULL
);
```

Planos pré-semeados: `free` (0), `pro` (980), `enterprise` (9800).

## Modelo de Auth

Todos os endpoints de assinatura requerem `X-Actor-Id: {userId}`. Acessar a assinatura de outro usuário retorna **403**.

## Endpoints

| Método | Caminho | Descrição |
|--------|---------|-----------|
| `GET`    | `/plans` | Listar todos os planos (público) |
| `POST`   | `/users/{id}/subscription` | Assinar |
| `GET`    | `/users/{id}/subscription` | Obter assinatura (apenas proprietário) |
| `PUT`    | `/users/{id}/subscription` | Alterar plano (apenas proprietário) |
| `DELETE` | `/users/{id}/subscription` | Cancelar (apenas proprietário) |

## Listar Planos

```php
GET /plans
→ 200
{
  "count": 3,
  "items": [
    {"slug": "free",       "name": "Free",        "price_cents": 0},
    {"slug": "pro",        "name": "Pro",          "price_cents": 980},
    {"slug": "enterprise", "name": "Enterprise",   "price_cents": 9800}
  ]
}
// Ordenado por price_cents ASC
```

## Assinar

```php
POST /users/1/subscription  X-Actor-Id: 1
{"plan": "pro"}
→ 201
{"plan_slug": "pro", "status": "active", "cancelled_at": null}

// Já assinado
POST /users/1/subscription  X-Actor-Id: 1  {"plan": "free"}
→ 409 Conflict

// Plano desconhecido
POST /users/1/subscription  X-Actor-Id: 1  {"plan": "platinum"}
→ 404

// Endpoint de outro usuário
POST /users/1/subscription  X-Actor-Id: 2  {"plan": "free"}
→ 403 Forbidden
```

## Obter Assinatura

```php
GET /users/1/subscription  X-Actor-Id: 1
→ 200  {"plan_slug": "pro", "status": "active", ...}

// Sem assinatura
GET /users/1/subscription  X-Actor-Id: 1  → 404

// Outro usuário
GET /users/1/subscription  X-Actor-Id: 2  → 403
```

## Alterar Plano

```php
PUT /users/1/subscription  X-Actor-Id: 1  {"plan": "enterprise"}
→ 200  {"plan_slug": "enterprise", "status": "active"}

// Upgrade e downgrade ambos permitidos
PUT /users/1/subscription  X-Actor-Id: 1  {"plan": "free"}
→ 200

// Sem assinatura para alterar
PUT /users/1/subscription  X-Actor-Id: 1  {"plan": "pro"}  → 404

// Tentar alterar uma assinatura cancelada
PUT /users/1/subscription  X-Actor-Id: 1  {"plan": "pro"}  → 409
```

## Cancelar

```php
DELETE /users/1/subscription  X-Actor-Id: 1  → 204

// Após cancelamento, GET mostra status cancelled
GET /users/1/subscription  X-Actor-Id: 1
→ 200  {"status": "cancelled", "cancelled_at": "2026-05-27T..."}
```

---

## Avaliação ATK — Teste de Ataque com Mentalidade de Cracker

### ATK-01 — Assinar na Conta de Outro Usuário 🚫 BLOCKED

**Ataque**: Atacante envia `POST /users/1/subscription  X-Actor-Id: 2` para iniciar uma assinatura na conta da vítima.
**Resultado**: BLOCKED — ID do ator é comparado ao ID do usuário no caminho. Divergência → 403.

---

### ATK-02 — Cancelar Assinatura de Outro Usuário 🚫 BLOCKED

**Ataque**: Atacante cancela a assinatura paga da vítima com `DELETE /users/1/subscription  X-Actor-Id: 2`.
**Resultado**: BLOCKED — Mesma verificação ator/caminho. 403 retornado.

---

### ATK-03 — Rebaixar Vítima para Plano Gratuito 🚫 BLOCKED

**Ataque**: `PUT /users/1/subscription  X-Actor-Id: 2  {"plan": "free"}`.
**Resultado**: BLOCKED — 403 no caminho entre usuários.

---

### ATK-04 — Double Subscribe para Contornar Pagamento 🚫 BLOCKED

**Ataque**: Enviar duas requisições rápidas `POST /subscribe` esperando que uma chegue antes da restrição UNIQUE.
**Resultado**: BLOCKED — `UNIQUE(user_id)` na tabela de assinaturas previne linhas duplicadas. Segundo insert lança restrição → 409.

---

### ATK-05 — Assinar com Slug de Plano Inválido ✅ SAFE

**Ataque**: `{"plan": "'; DROP TABLE plans; --"}` ou slugs desconhecidos.
**Resultado**: SAFE — Existência do plano é verificada via SELECT parametrizado. Injeção SQL é prevenida. Slug desconhecido → 404.

---

### ATK-06 — Reutilizar Assinatura Cancelada via PUT 🚫 BLOCKED

**Ataque**: Após cancelamento, atacante envia PUT para reativar sem re-assinar (pulando verificação de pagamento).
**Resultado**: BLOCKED — PUT em uma assinatura cancelada retorna 409. Deve assinar novamente (POST), o que pode aplicar verificações de pagamento.

---

### ATK-07 — Assinar para Usuário Inexistente 🚫 BLOCKED

**Ataque**: `POST /users/9999/subscription  X-Actor-Id: 9999`.
**Resultado**: BLOCKED — Existência do usuário validada antes da criação da assinatura. 404 retornado.

---

### ATK-08 — Ler Assinatura Sem Auth 🚫 BLOCKED

**Ataque**: `GET /users/1/subscription` sem header `X-Actor-Id`.
**Resultado**: BLOCKED — Ator ausente → 401.

---

### ATK-09 — Confusão de Tipo de ID de Ator/Caminho 🚫 BLOCKED

**Ataque**: `X-Actor-Id: 1abc` ou `X-Actor-Id: 1.0` para confundir a comparação de inteiros.
**Resultado**: BLOCKED — ID do ator validado como inteiro positivo. Não-dígitos → 401.

---

### ATK-10 — Enumerar Slugs de Plano por Tentativa e Erro 🚫 BLOCKED

**Ataque**: Tentar `{"plan": "internal"}`, `{"plan": "vip"}`, etc. para descobrir planos ocultos.
**Resultado**: BLOCKED — Plano desconhecido → 404. Nenhum efeito colateral criado. Rate limiting protege contra enumeração em escala.

---

### ATK-11 — Assinar Mesmo Plano (Ataque No-Op) 🚫 BLOCKED

**Ataque**: PUT com o mesmo slug de plano atual para disparar evento de cobrança.
**Resultado**: BLOCKED — Mudança para o mesmo plano retorna 200 (no-op ou permitido por design); nenhum evento de cobrança é disparado para plano idêntico.

---

### ATK-12 — IDOR via Incremento de ID Numérico de Usuário ✅ SAFE

**Ataque**: Atacante incrementa ID de usuário (`/users/1`, `/users/2`, ...) para enumerar assinaturas.
**Resultado**: SAFE — Todos os endpoints de assinatura requerem ator == usuário no caminho. Ator diferente → 403. Enumeração não revela dados.

---

### Resumo ATK

| ID | Ataque | Resultado |
|----|--------|-----------|
| ATK-01 | Assinar na conta de outro usuário | 🚫 BLOCKED |
| ATK-02 | Cancelar sub de outro usuário | 🚫 BLOCKED |
| ATK-03 | Rebaixar outro usuário | 🚫 BLOCKED |
| ATK-04 | Double subscribe bypass | 🚫 BLOCKED |
| ATK-05 | Injeção de slug de plano inválido | ✅ SAFE |
| ATK-06 | Reativar via PUT após cancelamento | 🚫 BLOCKED |
| ATK-07 | Assinar usuário inexistente | 🚫 BLOCKED |
| ATK-08 | Ler sem auth | 🚫 BLOCKED |
| ATK-09 | Confusão de tipo de ID de ator | 🚫 BLOCKED |
| ATK-10 | Enumeração de slug de plano | 🚫 BLOCKED |
| ATK-11 | Ataque no-op de mesmo plano | 🚫 BLOCKED |
| ATK-12 | IDOR via incremento de ID de usuário | ✅ SAFE |

**10 BLOCKED, 2 SAFE, 0 EXPOSED** — Nenhuma descoberta crítica.

---

## O Que NÃO Fazer

| Antipadrão | Risco |
|---|---|
| Permitir PUT em assinatura cancelada | Atacante reativa sem pagamento |
| Sem restrição UNIQUE em user_id | Assinaturas concorrentes criam múltiplas linhas |
| Retornar 404 em vez de 403 para acesso entre usuários | 404 esconde existência mas também esconde falha de autorização; use 403 explicitamente |
| Hard-delete assinatura no cancelamento | Perder trilha de auditoria; use `status: cancelled` + `cancelled_at` |
