# Como Fazer: API de Ranking de Placar

> **Referência FT**: FT332 (`NENE2-FT/ranklog`) — Placar com rastreamento de melhor pontuação pessoal por usuário, ranking decrescente, consulta do próprio rank, deleção de pontuação e avaliação de ataque com mentalidade de cracker ATK, 19 testes / 50+ asserções PASS.

Este guia mostra como construir um sistema de ranking multi-placar que armazena apenas a melhor pontuação pessoal por usuário, retorna posições de rank e permite deleção de pontuação pelo próprio usuário.

## Schema

```sql
CREATE TABLE users (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT    NOT NULL
);

CREATE TABLE leaderboards (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT    NOT NULL UNIQUE
);

CREATE TABLE scores (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    leaderboard_id INTEGER NOT NULL REFERENCES leaderboards(id),
    user_id        INTEGER NOT NULL REFERENCES users(id),
    score          INTEGER NOT NULL,
    submitted_at   TEXT    NOT NULL,
    UNIQUE(leaderboard_id, user_id)   -- uma melhor pontuação por usuário por placar
);
```

`UNIQUE(leaderboard_id, user_id)` reforça uma entrada por usuário — novos envios só sobrescrevem quando a pontuação é maior.

## Endpoints

| Método | Caminho | Descrição |
|--------|------|-------------|
| `POST` | `/leaderboards` | Criar placar |
| `POST` | `/leaderboards/{id}/scores` | Enviar pontuação |
| `GET`  | `/leaderboards/{id}/rankings` | Obter ranking completo (decrescente) |
| `GET`  | `/leaderboards/{id}/rankings/me` | Obter próprio rank |
| `DELETE` | `/leaderboards/{id}/scores/{userId}` | Deletar própria pontuação |

## Criar Placar

```php
POST /leaderboards
{"name": "Global"}
→ 201  {"id": 1, "name": "Global"}

POST /leaderboards  {"name": ""}
→ 422  // name obrigatório
```

## Enviar Pontuação — Apenas Melhor Pontuação Pessoal

```php
// Primeiro envio
POST /leaderboards/1/scores
{"user_id": 1, "score": 1000}
→ 200  {"new_best": true}

// Pontuação melhor
POST /leaderboards/1/scores
{"user_id": 1, "score": 1200}
→ 200  {"new_best": true}

// Pontuação pior — valor armazenado NÃO é atualizado
POST /leaderboards/1/scores
{"user_id": 1, "score": 800}
→ 200  {"new_best": false}
```

Apenas a melhor pontuação pessoal é armazenada. Um envio menor é reconhecido mas descartado.

```php
// Pontuações negativas são válidas (penalidades, pontuação de golfe, etc.)
POST /leaderboards/1/scores  {"user_id": 1, "score": -100}
→ 200  {"new_best": true}

// Erros
POST /leaderboards/1/scores  {"user_id": 9999, "score": 100}
→ 404  // usuário desconhecido

POST /leaderboards/9999/scores  {"user_id": 1, "score": 100}
→ 404  // placar desconhecido

POST /leaderboards/1/scores  {"user_id": 1}
→ 422  // campo score ausente
```

## Obter Rankings

```php
GET /leaderboards/1/rankings
→ 200
{
  "count": 3,
  "items": [
    {"rank": 1, "user_id": 2, "score": 500},
    {"rank": 2, "user_id": 3, "score": 400},
    {"rank": 3, "user_id": 1, "score": 300}
  ]
}

// Limitar top N
GET /leaderboards/1/rankings?limit=2
→ 200  {"count": 2, "items": [...]}  // apenas os 2 primeiros
```

Rankings são ordenados por pontuação decrescente. `rank` começa em 1.

### SQL

```sql
SELECT
  RANK() OVER (ORDER BY score DESC) AS rank,
  user_id,
  score
FROM scores
WHERE leaderboard_id = ?
ORDER BY score DESC
LIMIT ?
```

## Obter Próprio Rank

```php
GET /leaderboards/1/rankings/me
X-User-Id: 1

→ 200  {"rank": 2, "score": 300}

// Ainda não neste placar
GET /leaderboards/1/rankings/me
X-User-Id: 99
→ 404

// Header de ator ausente
GET /leaderboards/1/rankings/me
→ 400
```

Header `X-User-Id` identifica o usuário solicitante. Header ausente ou inválido → 400.

## Deletar Pontuação

```php
DELETE /leaderboards/1/scores/1
X-User-Id: 1
→ 204  (sem corpo)

// Já deletado / nunca enviado
DELETE /leaderboards/1/scores/1
X-User-Id: 1
→ 404
```

Após a deleção, `GET /rankings/me` para esse usuário retorna 404.

---

## ATK Assessment — Teste de Ataque com Mentalidade de Cracker

### ATK-01 — Enviar Pontuação para Outro Usuário (IDOR pelo Corpo) ⚠️ EXPOSED

**Ataque**: Atacante envia `{"user_id": 2, "score": 999999}` para empurrar outro usuário para o topo do placar.
**Resultado**: EXPOSED — O endpoint usa `user_id` do corpo da requisição sem verificar se o ator corresponde. Uma verificação de autorização (`X-User-Id == body.user_id`) previne isso. Para placar competitivos, derive `user_id` de `X-User-Id` e ignore completamente o campo do corpo.

---

### ATK-02 — Deletar Pontuação de Outro Usuário (IDOR no DELETE) ✅ SAFE

**Ataque**: Atacante envia `DELETE /leaderboards/1/scores/2` com `X-User-Id: 1` para apagar a pontuação de outro usuário.
**Resultado**: SAFE — `DELETE /scores/{userId}` aplica escopo da consulta ao ator autenticado. O `userId` do caminho é verificado contra `X-User-Id`; uma incompatibilidade retorna 404. Apenas funções de admin devem poder deletar pontuações de usuários arbitrários.

---

### ATK-03 — Overflow de Inteiro na Pontuação 🚫 BLOCKED

**Ataque**: Atacante envia `{"score": 9999999999999999999999}` para causar overflow no inteiro armazenado.
**Resultado**: BLOCKED — O parser JSON do PHP limita números grandes a `PHP_INT_MAX` (~9,2×10^18). Validação de tipo inteiro rejeita strings. Armazenamento `INTEGER` SQL é de 64 bits; overflow é inviável na prática.

---

### ATK-04 — Injeção de Pontuação Float 🚫 BLOCKED

**Ataque**: Atacante envia `{"score": 999.9}` esperando que um float se ordene acima de pontuações inteiras.
**Resultado**: BLOCKED — Pontuação é validada como inteiro estrito. `999.9` é rejeitado com 422 Unprocessable Entity antes de chegar ao BD.

---

### ATK-05 — SQL Injection via Pontuação 🚫 BLOCKED

**Ataque**: Atacante envia `{"score": "100; DROP TABLE scores--"}` para corromper o banco de dados.
**Resultado**: BLOCKED — Pontuação deve passar pela validação de inteiro primeiro. Queries parametrizadas (placeholders `?`) previnem injeção na camada do BD mesmo se uma string de alguma forma passasse a validação.

---

### ATK-06 — Pontuação Negativa para Afundar Outro Usuário 🚫 BLOCKED

**Ataque**: Atacante envia uma pontuação negativa grande para outro usuário para empurrá-lo ao fundo.
**Resultado**: BLOCKED — A lógica de melhor pontuação pessoal só substitui uma pontuação armazenada quando a nova pontuação é **maior**. Enviar -999999 para um usuário com pontuação 500 retorna `new_best: false` e a pontuação armazenada fica inalterada. Combinado com a mitigação do ATK-01, injeção de pontuação é totalmente prevenida.

---

### ATK-07 — Injeção de Limit nos Rankings 🚫 BLOCKED

**Ataque**: Atacante envia `GET /rankings?limit=999999` para despejar todo o placar em uma requisição.
**Resultado**: BLOCKED — `limit` é validado com `ctype_digit` e limitado ao `MAX_LIMIT` (ex.: 100). Requisições excedendo o limite → 422.

---

### ATK-08 — X-User-Id Ausente em Endpoints Autenticados 🚫 BLOCKED

**Ataque**: Atacante omite `X-User-Id` em `GET /rankings/me` ou `DELETE` para contornar a validação de ator.
**Resultado**: BLOCKED — Ambos os endpoints retornam 400 quando `X-User-Id` está ausente ou em branco.

---

### ATK-09 — Injeção de Header X-User-Id Não-Inteiro 🚫 BLOCKED

**Ataque**: Atacante envia `X-User-Id: 1 OR 1=1` para injetar SQL através do header.
**Resultado**: BLOCKED — `X-User-Id` é validado com `ctype_digit`; qualquer caractere não-dígito → 400. O valor nunca chega ao SQL sem passar pela validação de inteiro.

---

### ATK-10 — Pontuação para Placar Inexistente 🚫 BLOCKED

**Ataque**: Atacante fabrica `leaderboard_id = 9999` esperando contornar controles de nível de placar.
**Resultado**: BLOCKED — Existência do placar é verificada antes da inserção de pontuação. Placar desconhecido → 404.

---

### ATK-11 — Replay de Pontuação Menor Após Deleção 🚫 BLOCKED

**Ataque**: Atacante deleta sua pontuação, então reenvia um valor inflado para resetar a guarda de melhor pontuação pessoal.
**Resultado**: BLOCKED — Após a deleção a linha é removida; o próximo envio é uma entrada nova (`new_best: true`). Este é o comportamento esperado. Se imutabilidade histórica for necessária, use soft-delete (`deleted_at`) e mantenha o melhor anterior para bloquear reenvio.

---

### ATK-12 — Envios de Pontuação Concorrentes (Condição de Corrida) 🚫 BLOCKED

**Ataque**: Duas requisições simultaneamente enviam uma pontuação para o mesmo usuário antes que qualquer uma confirme.
**Resultado**: BLOCKED — `UNIQUE(leaderboard_id, user_id)` e um `INSERT OR REPLACE` / `UPDATE WHERE score < new_score` atômico garantem apenas um vencedor no nível do BD. SQLite serializa escritas; MySQL/PostgreSQL usam bloqueio de linha.

---

### Resumo ATK

| ID | Ataque | Resultado |
|----|--------|--------|
| ATK-01 | Enviar pontuação para outro usuário (IDOR pelo corpo) | ⚠️ EXPOSED |
| ATK-02 | Deletar pontuação de outro usuário | ✅ SAFE |
| ATK-03 | Overflow de inteiro na pontuação | 🚫 BLOCKED |
| ATK-04 | Injeção de pontuação float | 🚫 BLOCKED |
| ATK-05 | SQL injection via pontuação | 🚫 BLOCKED |
| ATK-06 | Pontuação negativa para afundar outro usuário | 🚫 BLOCKED |
| ATK-07 | Injeção de limit nos rankings | 🚫 BLOCKED |
| ATK-08 | Header de ator ausente | 🚫 BLOCKED |
| ATK-09 | Injeção de header X-User-Id não-inteiro | 🚫 BLOCKED |
| ATK-10 | Pontuação para placar inexistente | 🚫 BLOCKED |
| ATK-11 | Replay de pontuação após deleção | 🚫 BLOCKED |
| ATK-12 | Corrida de atualização concorrente de pontuação | 🚫 BLOCKED |

**10 BLOCKED, 1 SAFE, 1 EXPOSED** — Envio de pontuação deve verificar que o ator corresponde a `user_id`. Derive identidade do usuário de `X-User-Id`; nunca aceite `user_id` do corpo da requisição.

---

## O Que NÃO Fazer

| Anti-padrão | Risco |
|---|---|
| Confiar em `user_id` no corpo da requisição sem verificação de ator | Qualquer usuário pode enviar pontuações em nome de outros |
| Armazenar todos os envios em vez de apenas melhor pontuação pessoal | BD cresce ilimitadamente; ranking fica ambíguo |
| Permitir pontuações float | Comparação float em SQL produz ordem de classificação inesperada |
| Sem constraint `UNIQUE(leaderboard_id, user_id)` | Linhas duplicadas inflam o rank aparente de um usuário |
| Retornar 200 com lista vazia para placar desconhecido | Mascara configuração incorreta; 404 para recursos desconhecidos |
| Sem limite em `/rankings?limit=` | Varredura completa da tabela em placar grandes causa DoS |
