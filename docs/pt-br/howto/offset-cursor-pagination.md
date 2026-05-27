# Como Fazer: Paginação por Offset e Cursor

> **Referência FT**: FT325 (`NENE2-FT/pagelog`) — Estratégia de paginação dupla (baseada em offset e baseada em cursor) com `next_offset`/`next_cursor`, `has_more`, filtro de categoria, 15 testes / 47 asserções PASS.

Este guia mostra como implementar endpoints de paginação tanto por offset quanto por cursor para o mesmo recurso, permitindo que os clientes escolham a estratégia que melhor se adapta ao seu caso de uso.

## Schema

```sql
CREATE TABLE articles (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    title      TEXT    NOT NULL,
    author     TEXT    NOT NULL,
    category   TEXT    NOT NULL DEFAULT 'general',
    created_at TEXT    NOT NULL
);
```

## Endpoints

| Método | Caminho | Descrição |
|--------|---------|-----------|
| `POST` | `/articles` | Criar artigo |
| `GET`  | `/articles/offset` | Paginação por offset |
| `GET`  | `/articles/cursor` | Paginação por cursor |
| `GET`  | `/articles/by-category` | Filtro por categoria |

## Paginação por Offset

```
GET /articles/offset?limit=10&offset=0
→ 200
{
  "items": [...],     // 10 itens
  "total": 25,
  "limit": 10,
  "offset": 0,
  "has_more": true,
  "next_offset": 10   // null na última página
}

// Página 2
GET /articles/offset?limit=10&offset=10
→ {"items": [...], "has_more": true, "next_offset": 20}

// Última página
GET /articles/offset?limit=10&offset=20
→ {"items": [...], "has_more": false, "next_offset": null}

// Além do final
GET /articles/offset?limit=10&offset=100
→ {"items": [], "has_more": false}
```

`next_offset = offset + limit` quando `has_more`, senão `null`.

## Paginação por Cursor

```
GET /articles/cursor?limit=10
→ 200
{
  "items": [...],        // mais recentes primeiro
  "has_more": true,
  "next_cursor": 15      // id do último item retornado
}

// Próxima página usando cursor
GET /articles/cursor?limit=10&after=15
→ {"items": [...], "has_more": true, "next_cursor": 5}

// Última página
GET /articles/cursor?limit=10&after=5
→ {"items": [...], "has_more": false, "next_cursor": null}
```

O cursor é o `id` do último item retornado: `WHERE id < $after ORDER BY id DESC LIMIT $limit + 1` (verifica um extra para determinar `has_more`).

## Filtro por Categoria

```
GET /articles/by-category?category=tech&limit=5
→ {"items": [...], "total": N}
```

## Offset vs Cursor — Quando Usar

| Critério | Offset | Cursor |
|----------|--------|--------|
| Salto aleatório de página | ✅ `?offset=50` | ❌ Deve percorrer |
| Contagem total necessária | ✅ Sempre incluída | ❌ Custoso |
| Resultados consistentes durante inserções | ❌ Nova linha desloca página | ✅ Estável |
| Desempenho em conjuntos de dados grandes | ❌ `OFFSET N` escaneia N linhas | ✅ `WHERE id < X` usa índice |
| Scroll infinito / feed | ❌ | ✅ |

---

## O Que NÃO Fazer

| Anti-padrão | Risco |
|---|---|
| Retornar `next_offset` mesmo na última página | Cliente faz requisição vazia extra |
| Usar `OFFSET N` em tabelas com milhões de linhas | Banco de dados escaneia N linhas antes de retornar resultados; use cursor para dados grandes |
| Omitir `has_more` da resposta de cursor | Cliente não consegue saber se deve buscar a próxima página |
| Usar timestamp como cursor | Timestamps duplicados causam linhas ignoradas ou repetidas; use id inteiro único |
