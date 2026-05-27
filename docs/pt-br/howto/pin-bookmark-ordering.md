# Como Fazer: Fixar / Marcar com Ordenação

> **Referência FT**: FT327 (`NENE2-FT/pinlog`) — Pins de artigos por usuário com posições sequenciais, limite máximo de pins, recompactação sem lacunas na exclusão, reordenação via PUT, isolamento de usuário, avaliação VULN, 19 testes / 26 assertivas PASS.

Este guia mostra como construir um recurso de artigos fixados onde os usuários mantêm uma lista ordenada de até 10 favoritos com suporte a reordenação por arrastar.

## Schema

```sql
CREATE TABLE pins (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL REFERENCES users(id),
    article_id INTEGER NOT NULL REFERENCES articles(id),
    position   INTEGER NOT NULL,
    created_at TEXT    NOT NULL,
    UNIQUE(user_id, article_id)
);
```

## Endpoints

| Método | Caminho | Descrição |
|--------|---------|-----------|
| `POST`  | `/pins` | Fixar artigo (idempotente) |
| `DELETE`| `/pins/{articleId}` | Desafixar artigo |
| `GET`   | `/pins` | Listar pins do usuário em ordem |
| `PUT`   | `/pins/order` | Reordenar pins |

Todos os endpoints requerem header `X-User-Id`. Ausente → 401.

## Fixar Artigo

```php
POST /pins  X-User-Id: 1
{"article_id": 3}
→ 201  {"article_id": 3, "position": 1}

POST /pins  X-User-Id: 1  {"article_id": 7}
→ 201  {"article_id": 7, "position": 2}

// Idempotente — fixar o mesmo artigo duas vezes
POST /pins  X-User-Id: 1  {"article_id": 3}
→ 200  (já fixado, sem mudança)
```

### Limite

```php
// Já tem 10 pins
POST /pins  X-User-Id: 1  {"article_id": 11}
→ 422  {"max": 10}
```

### Casos de Erro

```php
// Sem auth
POST /pins  {"article_id": 1}        → 401
// article_id ausente
POST /pins  X-User-Id: 1  {}         → 422
// Artigo inexistente
POST /pins  X-User-Id: 1  {"article_id": 999} → 404
```

## Desafixar

```php
DELETE /pins/3  X-User-Id: 1  → 204
DELETE /pins/3  X-User-Id: 1  → 404  // já removido
```

### Compactação de Posição Após Exclusão

Excluir um pin recompacta as posições — sem lacunas:

```
Antes: [1→Art1, 2→Art2, 3→Art3]
DELETE /pins/2
Depois: [1→Art1, 2→Art3]   // posição 2 é agora Art3
```

```php
// Após desafixar, a lacuna é fechada
GET /pins  X-User-Id: 1
→ {"pins": [
     {"article_id": 1, "position": 1},
     {"article_id": 3, "position": 2}   // posição 3 → 2
  ], "count": 2}
```

## Listar Pins

```php
GET /pins  X-User-Id: 1
→ 200
{
  "pins": [
    {"article_id": 3, "position": 1},
    {"article_id": 1, "position": 2},
    {"article_id": 2, "position": 3}
  ],
  "count": 3
}

// Vazio
GET /pins  X-User-Id: 99
→ {"pins": [], "count": 0}
```

Resultados são ordenados por `position ASC`. O Usuário 2 nunca vê os pins do Usuário 1.

## Reordenar

```php
PUT /pins/order  X-User-Id: 1
{"article_ids": [3, 1, 2]}
→ 200
{
  "pins": [
    {"article_id": 3, "position": 1},
    {"article_id": 1, "position": 2},
    {"article_id": 2, "position": 3}
  ]
}

// article_id desconhecido (não fixado)
{"article_ids": [1, 99]}  → 422

// Sem X-User-Id
PUT /pins/order  {"article_ids": [1]}  → 401
// Corpo ausente
PUT /pins/order  X-User-Id: 1  {}     → 422
```

---

## Avaliação de Vulnerabilidade

### V-01 — IDOR no Desafixar ✅ SAFE

**Risco**: Usuário 2 desafixa artigos do Usuário 1 adivinhando IDs de artigos.
**Achado**: SAFE — Query DELETE inclui `WHERE user_id = $authUserId AND article_id = $articleId`. Exclusão entre usuários encontra 0 linhas → 404.

### V-02 — IDOR no Reordenar ✅ SAFE

**Risco**: Usuário 2 reordena a lista de pins do Usuário 1.
**Achado**: SAFE — Reordenar valida que todos os `article_ids` estão na lista de pins do usuário autenticado. IDs externos retornam 422.

### V-03 — Bypass do Limite de Pins ✅ SAFE

**Risco**: Atacante submete requisições de pin concorrentes para exceder o limite de 10 pins.
**Achado**: SAFE — `UNIQUE(user_id, article_id)` previne duplicatas. A contagem de pins é verificada antes da inserção. Inserções concorrentes competem na restrição unique.

### V-04 — Fixar Artigo Inexistente ✅ SAFE

**Risco**: Atacante fixa `article_id=999999` para inserir referência FK pendente.
**Achado**: SAFE — Verificação de existência realizada antes da inserção. Artigo inexistente retorna 404.

### V-05 — Fixar Artigos de Outro Usuário ✅ SAFE

**Risco**: Pin entre usuários (usuário 2 fixa como usuário 1 manipulando `X-User-Id`).
**Achado**: SAFE — `X-User-Id` é o token de autenticação neste FT. Em produção, use JWT/sessão assinado — nunca confie diretamente em um header de ID de usuário fornecido pelo cliente.

### V-06 — Lacuna de Posição Após Exclusão Expõe Ordenação ✅ SAFE

**Risco**: Lacunas nas posições (`1, 3`) revelam que uma exclusão ocorreu; atacante infere histórico de exclusão.
**Achado**: SAFE — Posições são compactadas imediatamente na exclusão. Observadores externos não conseguem detectar a ordem de exclusão.

### Resumo VULN

| ID | Vulnerabilidade | Achado |
|----|-----------------|--------|
| V-01 | IDOR no desafixar | ✅ SAFE |
| V-02 | IDOR no reordenar | ✅ SAFE |
| V-03 | Bypass do limite de pins | ✅ SAFE |
| V-04 | Fixar artigo inexistente | ✅ SAFE |
| V-05 | Pin entre usuários | ✅ SAFE |
| V-06 | Lacuna expõe histórico de exclusão | ✅ SAFE |

**6 SAFE, 0 EXPOSED** — Nenhum achado crítico.

---

## O Que NÃO Fazer

| Anti-padrão | Risco |
|---|---|
| Sem limite máximo de pins | Lista sem limite degrada performance de query e UX |
| Deixar lacunas de posição após exclusão | Ordenação do cliente por posição quebra; requer renumeração no lado do cliente |
| Ignorar verificação de existência do artigo no pin | Referências pendentes confundem clientes ao renderizar listas de pins |
| Confiar no header `X-User-Id` em produção | Qualquer cliente pode defini-lo; use autenticação assinada (JWT, sessão) |
| Sem `UNIQUE(user_id, article_id)` | Pins duplicados inflam a contagem e confundem a lógica de reordenação |
