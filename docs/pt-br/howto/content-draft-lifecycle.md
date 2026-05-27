# Como Construir um Ciclo de Vida de Rascunho de Conteúdo (Rascunho → Publicado → Arquivado) com NENE2

Este guia percorre a construção de um sistema de gerenciamento de artigos com uma máquina de estados draft/publish/archive, onde apenas o autor pode fazer transições de estado e apenas artigos publicados são visíveis para leitores.

**Field Trial**: FT142
**Versão do NENE2**: ^1.5
**Tópicos abordados**: máquina de status com enum, guardas de transição, verificação de propriedade do autor, lista pública filtrada por status, estabilidade de ordenação no mesmo segundo

---

## O que estamos construindo

- `POST /articles` — criar um artigo (sempre começa como `draft`)
- `GET /articles` — listar apenas artigos publicados
- `GET /articles/{id}` — obter artigo (autor vê qualquer status; outros veem apenas `published`)
- `PUT /articles/{id}` — editar artigo (somente draft, somente o autor)
- `POST /articles/{id}/publish` — transição `draft → published` (somente o autor)
- `POST /articles/{id}/archive` — transição `published → archived` (somente o autor)

---

## Schema do banco de dados

```sql
CREATE TABLE articles (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    author_id    INTEGER NOT NULL,
    title        TEXT    NOT NULL,
    body         TEXT    NOT NULL DEFAULT '',
    status       TEXT    NOT NULL DEFAULT 'draft',
    published_at TEXT,
    archived_at  TEXT,
    created_at   TEXT    NOT NULL,
    updated_at   TEXT    NOT NULL,
    CHECK (status IN ('draft', 'published', 'archived')),
    FOREIGN KEY (author_id) REFERENCES users(id)
);
```

`published_at` e `archived_at` são nullable — são definidos apenas na transição correspondente.

---

## Enum ArticleStatus com guardas de transição

```php
enum ArticleStatus: string
{
    case Draft     = 'draft';
    case Published = 'published';
    case Archived  = 'archived';

    public function canEdit(): bool
    {
        return $this === self::Draft;
    }

    public function canPublish(): bool
    {
        return $this === self::Draft;
    }

    public function canArchive(): bool
    {
        return $this === self::Published;
    }
}
```

O handler lê o status atual, chama o método de guarda e retorna 422 se a transição for inválida:

```php
$status = ArticleStatus::tryFrom($article['status']) ?? ArticleStatus::Draft;

if (!$status->canPublish()) {
    return $this->responseFactory->create(['error' => 'only draft articles can be published'], 422);
}
```

Transições válidas:
- `draft → published` (via publish)
- `published → archived` (via archive)
- Não existe transição de volta para draft.

---

## Visibilidade do autor — rascunho oculto de outros

Não-autores não podem ler rascunhos. Retorne 404 (não 403) para evitar vazar que o artigo existe:

```php
if ($article['status'] !== 'published' && $article['author_id'] !== $actorId) {
    return $this->responseFactory->create(['error' => 'article not found'], 404);
}
```

Retornar 403 confirmaria a existência do artigo. 404 é a escolha correta para conteúdo que ainda não é público.

---

## Estabilidade de ordenação no mesmo segundo

Quando múltiplos artigos são publicados dentro do mesmo segundo, `ORDER BY published_at DESC` sozinho dá uma ordem não determinística. Adicione `id DESC` como desempate:

```sql
SELECT ... FROM articles WHERE status = 'published' ORDER BY published_at DESC, id DESC
```

Um `id` maior significa criado mais recentemente, então isso ordena efetivamente pela ordem de inserção dentro do mesmo segundo.

---

## Armadilhas comuns

| Armadilha | Correção |
|-----------|----------|
| Retornar 403 para leituras de rascunho por não-autores | Retornar 404 — previne vazamento de existência do conteúdo |
| Permitir reabertura `published → draft` | `canEdit()` retorna false exceto para `Draft`; sem endpoint "unpublish" |
| Publicar um artigo já publicado | `canPublish()` retorna false para `Published` → 422 |
| Arquivar um rascunho | `canArchive()` retorna false exceto para `Published` → 422 |
| Ordem de lista não determinística no mesmo timestamp | Adicione `id DESC` como ordenação secundária |
