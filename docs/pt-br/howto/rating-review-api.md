# Como Fazer: API de Avaliação e Resenha

> **Referência FT**: FT333 (`NENE2-FT/ratinglog`) — Sistema de avaliação por item/usuário com validação de pontuação (1–5), semântica de upsert, resumo com distribuição, e avaliação de vulnerabilidade, 16 testes / 40+ assertivas PASS.

Este guia mostra como construir um sistema de avaliação onde usuários enviam pontuações numéricas com resenhas textuais opcionais, e a API calcula resumos agregados em tempo real.

## Schema

```sql
CREATE TABLE ratings (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    item_id    TEXT    NOT NULL,
    rater_id   TEXT    NOT NULL,
    score      INTEGER NOT NULL CHECK (score BETWEEN 1 AND 5),
    review     TEXT    NOT NULL DEFAULT '',
    created_at TEXT    NOT NULL,
    updated_at TEXT    NOT NULL,
    UNIQUE(item_id, rater_id)
);
```

`UNIQUE(item_id, rater_id)` impõe uma avaliação por avaliador por item. `item_id` e `rater_id` são identificadores string opacos — sem restrição de chave estrangeira necessária.

## Endpoints

| Método | Caminho | Descrição |
|--------|---------|-----------|
| `PUT`  | `/items/{itemId}/ratings/{raterId}` | Criar ou atualizar avaliação (upsert) |
| `GET`  | `/items/{itemId}/ratings` | Listar todas as avaliações do item |
| `GET`  | `/items/{itemId}/ratings/summary` | Resumo agregado com distribuição |
| `GET`  | `/items/{itemId}/ratings/{raterId}` | Obter a avaliação de um avaliador |
| `DELETE` | `/items/{itemId}/ratings/{raterId}` | Excluir uma avaliação |

## Criar / Atualizar Avaliação (Upsert)

```php
PUT /items/product-1/ratings/alice
{"score": 5, "review": "Excellent!"}
→ 200  {"rater_id": "alice", "score": 5, "review": "Excellent!", ...}

// Atualizar avaliação existente
PUT /items/product-1/ratings/alice
{"score": 3, "review": "Changed my mind."}
→ 200  {"score": 3}
```

`PUT` com `UNIQUE(item_id, rater_id)` age como um upsert natural (`INSERT OR REPLACE`). O mesmo endpoint trata tanto criação quanto atualização sem um `PATCH` separado.

### Validação

```php
// score ausente
PUT /items/product-1/ratings/alice  {"review": "Nice"}
→ 422

// fora do intervalo
PUT /items/product-1/ratings/alice  {"score": 6}
→ 422

PUT /items/product-1/ratings/alice  {"score": 0}
→ 422
```

O score deve ser um inteiro em [1, 5]. `review` é opcional (padrão `""`).

## Listar Avaliações

```php
GET /items/product-1/ratings
→ 200
{
  "ratings": [
    {"rater_id": "alice", "score": 5, "review": "Excellent!"},
    {"rater_id": "bob",   "score": 3, "review": ""}
  ]
}
```

As avaliações têm escopo para o item — as avaliações do `product-2` nunca aparecem na lista do `product-1`.

## Resumo com Distribuição

```php
GET /items/product-1/ratings/summary
→ 200
{
  "count": 3,
  "average": 4.0,
  "distribution": {
    "1": 0, "2": 0, "3": 1, "4": 1, "5": 1
  }
}

// Sem avaliações ainda
GET /items/product-2/ratings/summary
→ 200  {"count": 0, "average": 0.0, "distribution": {"1":0,"2":0,"3":0,"4":0,"5":0}}
```

`distribution` sempre retorna todas as cinco chaves mesmo quando os counts são zero — clientes podem renderizar barras de estrelas sem verificações de null.

## Obter Avaliação Individual

```php
GET /items/product-1/ratings/alice
→ 200  {"score": 4, "review": "..."}

GET /items/product-1/ratings/nobody
→ 404
```

## Excluir Avaliação

```php
DELETE /items/product-1/ratings/alice
→ 200  {"deleted": true}

DELETE /items/product-1/ratings/nobody
→ 404
```

Após a exclusão, o resumo é recalculado imediatamente na próxima requisição.

```php
// Antes: alice(5) + bob(1), average=3.0
DELETE /items/product-1/ratings/bob

// Depois: apenas alice(5)
GET /items/product-1/ratings/summary
→ 200  {"count": 1, "average": 5.0}
```

---

## Avaliação de Vulnerabilidade

### V-01 — Falsificação de avaliação (IDOR em raterId) ⚠️ EXPOSED

**Risco**: Qualquer cliente pode enviar ou excluir uma avaliação usando qualquer `raterId` no segmento de caminho.
**Descoberta**: EXPOSED — `raterId` na URL não é validado contra um ator autenticado. Um atacante pode postar uma resenha de 1 estrela como `raterId: "competitor"` ou excluir a resenha de outro usuário. Mitigação: autentique o avaliador (sessão, JWT ou header `X-User-Id`) e rejeite requisições onde a identidade autenticada não corresponde ao `raterId` do caminho.

---

### V-02 — Bypass de intervalo de score 🛡️ SAFE

**Risco**: Atacante envia `score: 0` ou `score: 6` para produzir dados inválidos ou distorcer médias.
**Descoberta**: SAFE — O score é validado para `[1, 5]` antes de qualquer escrita no banco. Valores fora do intervalo retornam 422. O `CHECK (score BETWEEN 1 AND 5)` no nível de banco fornece uma guarda secundária.

---

### V-03 — Envenenamento de média via avaliações falsas em massa ⚠️ EXPOSED

**Risco**: Atacante registra milhares de IDs de usuário e envia avaliações de 1 estrela para afundar a média de um produto.
**Descoberta**: EXPOSED — Sem limitação de taxa ou verificação de conta no endpoint de avaliação. Mitigação: exigir idade de conta / verificação de email antes de avaliar; aplicar limites de taxa por IP e por usuário; detectar anomalias estatísticas (surto súbito de pontuações baixas).

---

### V-04 — XSS via texto de resenha ✅ SAFE

**Risco**: Atacante armazena `<script>alert(1)</script>` em `review` para executar JavaScript em clientes que renderizam a resenha como HTML.
**Descoberta**: SAFE — A API retorna `application/json`. A codificação JSON escapa caracteres especiais HTML (`<`, `>`, `&`). Desde que os clientes analisem e renderizem o valor JSON como texto (não `innerHTML`), XSS armazenado é impedido. Codificação HTML do lado do servidor como camada adicional é recomendada.

---

### V-05 — Injeção SQL via itemId / raterId 🛡️ SAFE

**Risco**: Atacante envia `item_id = "x' OR '1'='1"` ou `rater_id = "'; DROP TABLE ratings--"` para manipular a query.
**Descoberta**: SAFE — Todas as queries usam instruções parametrizadas (placeholders `?`). Segmentos de caminho são passados como valores de bind, nunca interpolados em strings SQL.

---

### V-06 — Texto de resenha ilimitado (abuso de armazenamento) ⚠️ EXPOSED

**Risco**: Atacante envia uma string de resenha de 100 MB para esgotar recursos do banco de dados/memória.
**Descoberta**: EXPOSED — Sem verificação de `max_length` em `review`. Mitigação: adicione uma constante `MAX_REVIEW_LENGTH` (ex.: 2000 caracteres) e retorne 422 se excedido. Middleware de tamanho de requisição fornece uma guarda secundária.

---

### V-07 — Truncamento de inteiro na média do resumo 🛡️ SAFE

**Risco**: Calcular a média de 3 avaliações (5+3+4=12, 12/3=4.0) poderia perder precisão em alguns motores de banco.
**Descoberta**: SAFE — `AVG()` no SQLite retorna um float. O PHP converte o resultado para `float` antes de codificar. Truncamento estilo `(int)(5+3)/2` não é usado.

---

### V-08 — Chaves ausentes na distribuição (crash do cliente) 🛡️ SAFE

**Risco**: Se `distribution` omitir chaves para scores com zero avaliações, clientes que acessam `distribution[1]` travam com `undefined`.
**Descoberta**: SAFE — A API sempre retorna todas as cinco chaves (`1`–`5`) inicializadas em `0`. Clientes não precisam de verificações defensivas de null.

---

### V-09 — Vazamento de dados entre itens 🛡️ SAFE

**Risco**: `GET /items/product-1/ratings` retorna avaliações do `product-2`.
**Descoberta**: SAFE — Todas as queries incluem `WHERE item_id = ?`. O teste de isolamento verifica explicitamente que avaliar `product-2` não aparece na lista do `product-1`.

---

### V-10 — Score float para contornar validação de inteiro 🛡️ SAFE

**Risco**: Atacante envia `score: 4.9` (arredonda para 5) ou `score: 5.1` (arredonda para 5 ou 6) para contornar a verificação de intervalo.
**Descoberta**: SAFE — O score é validado como um inteiro estrito. Um float JSON falha na validação de tipo e retorna 422 antes de qualquer verificação de intervalo.

---

### Resumo VULN

| ID | Vulnerabilidade | Descoberta |
|----|-----------------|------------|
| V-01 | Falsificação de avaliação (IDOR em raterId) | ⚠️ EXPOSED |
| V-02 | Bypass de intervalo de score | 🛡️ SAFE |
| V-03 | Envenenamento de média via avaliações falsas em massa | ⚠️ EXPOSED |
| V-04 | XSS via texto de resenha | ✅ SAFE |
| V-05 | Injeção SQL via itemId / raterId | 🛡️ SAFE |
| V-06 | Texto de resenha ilimitado (abuso de armazenamento) | ⚠️ EXPOSED |
| V-07 | Truncamento de inteiro na média do resumo | 🛡️ SAFE |
| V-08 | Chaves ausentes na distribuição | 🛡️ SAFE |
| V-09 | Vazamento de dados entre itens | 🛡️ SAFE |
| V-10 | Score float para contornar validação de inteiro | 🛡️ SAFE |

**7 SAFE, 3 EXPOSED** — Crítico: autenticar `raterId`; adicionar cap de comprimento para `review`; aplicar limitação de taxa contra avaliações falsas em massa.

---

## O Que NÃO Fazer

| Antipadrão | Risco |
|---|---|
| Confiar em `raterId` do caminho sem autenticação | Qualquer cliente pode avaliar ou excluir como qualquer usuário |
| Sem `max_length` no texto de resenha | Bomba de armazenamento — uma única requisição escreve gigabytes no banco |
| Retornar `null` para chaves de distribuição com count zero | Código de cliente que acessa `distribution[2]` trava |
| Recalcular média no PHP com `array_sum` | Aritmética de float com perda em conjuntos de dados grandes; deixe o banco fazer `AVG()` |
| Sem limite de taxa por usuário | Contas falsas em massa envenenam médias de produtos |
| Usar `SELECT * FROM ratings` sem `WHERE item_id` | Vazamento de dados entre itens |
