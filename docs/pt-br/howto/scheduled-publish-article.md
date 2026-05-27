# Como Fazer: Publicação Agendada de Artigos

> **Referência FT**: FT330 (`NENE2-FT/pubschedulelog`) — Ciclo de vida de rascunho/agendamento/publicação/arquivamento de artigos, acesso a rascunho somente para proprietário, artigos publicados públicos, gatilho de publicação agendada, 34 testes / 95 assertivas PASS.

Este guia mostra como construir um sistema de gerenciamento de artigos com publicação diferida: autores escrevem rascunhos, os agendam para um horário futuro, e um job em background (ou chamada de API) os faz transitar para publicado.

## Schema

```sql
CREATE TABLE articles (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    author_id  INTEGER NOT NULL,
    title      TEXT    NOT NULL,
    body       TEXT    NOT NULL DEFAULT '',
    status     TEXT    NOT NULL DEFAULT 'draft',   -- draft | scheduled | published | archived
    publish_at TEXT,                               -- ISO-8601, NULL exceto quando agendado
    created_at TEXT    NOT NULL,
    updated_at TEXT    NOT NULL
);
```

## Transições de Status

```
draft ──publish──► published ──archive──► archived
  │
  └──schedule──► scheduled ──(tempo passa)──► published
  │                  │
  │               unschedule
  │                  │
  └──────────────────┘
```

Apenas transições permitidas — transições inválidas retornam 409.

## Endpoints

| Método | Caminho | Descrição |
|--------|---------|-----------|
| `POST`  | `/articles` | Criar rascunho (`X-User-Id` obrigatório) |
| `GET`   | `/articles/{id}` | Obter (rascunho: somente proprietário; publicado: público) |
| `PUT`   | `/articles/{id}` | Atualizar rascunho (`X-User-Id` obrigatório) |
| `POST`  | `/articles/{id}/publish` | Publicar imediatamente |
| `POST`  | `/articles/{id}/schedule` | Agendar para horário futuro |
| `POST`  | `/articles/{id}/unschedule` | Retornar para rascunho |
| `POST`  | `/articles/{id}/archive` | Arquivar artigo publicado |
| `GET`   | `/articles` | Listar (com filtro `?status=`) |
| `POST`  | `/publish-due` | Disparar artigos agendados com publish_at passado |

## Criar Rascunho

```php
POST /articles  X-User-Id: 1
{"title": "Hello", "body": "World"}
→ 201  {"id": 1, "status": "draft", "author_id": 1}

// Sem autenticação → 401
```

## Regras de Visibilidade

```php
// Rascunho: somente proprietário
GET /articles/1  X-User-Id: 1  → 200   // autor vê próprio rascunho
GET /articles/1  X-User-Id: 2  → 404   // outro usuário não consegue ver rascunho
GET /articles/1               → 404   // sem auth, rascunho oculto

// Publicado: qualquer um
GET /articles/1               → 200   // público
```

## Publicar e Arquivar

```php
POST /articles/1/publish  X-User-Id: 1  → 200  {"status": "published"}
POST /articles/1/archive  X-User-Id: 1  → 200  {"status": "archived"}

// Não é possível arquivar um rascunho
POST /articles/1/archive  X-User-Id: 1  → 409
```

## Agendar

```php
// Agendar para 1 hora a partir de agora
POST /articles/1/schedule  X-User-Id: 1
{"publish_at": "2026-05-27T15:00:00+09:00"}
→ 200  {"status": "scheduled", "publish_at": "2026-05-27T15:00:00+09:00"}

// Horário passado → 422
POST /articles/1/schedule  X-User-Id: 1
{"publish_at": "2020-01-01T00:00:00Z"}
→ 422

// Cancelar agendamento → volta para rascunho
POST /articles/1/unschedule  X-User-Id: 1
→ 200  {"status": "draft", "publish_at": null}
```

## Disparar Artigos Agendados

Um cron job ou endpoint admin faz a transição de todos os artigos agendados com `publish_at <= now`:

```php
POST /publish-due
→ 200  {"published_count": 3}
```

## Listar Artigos

```php
GET /articles?status=published      → 200  // público, sem auth necessária
GET /articles?status=draft  X-User-Id: 1  → 200  // apenas próprios rascunhos
```

---

## O Que NÃO Fazer

| Antipadrão | Risco |
|---|---|
| Mostrar rascunho para usuário não autenticado | Vaza conteúdo não publicado |
| Permitir agendamento no passado | Artigo publicaria "imediatamente" via job de disparo, contornando revisão |
| Usar now() do relógio de parede no teste para o gatilho de agendamento | Testes se tornam dependentes do tempo; use force-insert com `publish_at` passado nos testes |
| Hard-delete no arquivamento | Perde trilha de auditoria; use campo de status |
| Permitir transição de archived → published | Traz de volta conteúdo removido; exigir re-publicação explícita |
