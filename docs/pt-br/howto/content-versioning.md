# Guia de Implementação de Versionamento de Conteúdo

## Visão Geral

Este guia explica como implementar versionamento de conteúdo (preservação de todo o histórico, referência a versões específicas, rollback) usando o NENE2.
Preserva todas as versões das alterações de artigos de forma append-only e oferece rollback para qualquer revisão.

---

## Schema do BD

```sql
CREATE TABLE articles (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    title           TEXT    NOT NULL,
    body            TEXT    NOT NULL,
    current_version INTEGER NOT NULL DEFAULT 1,
    created_at      TEXT    NOT NULL,
    updated_at      TEXT    NOT NULL
);

CREATE TABLE article_versions (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    article_id INTEGER NOT NULL,
    version    INTEGER NOT NULL,
    title      TEXT    NOT NULL,
    body       TEXT    NOT NULL,
    created_at TEXT    NOT NULL,
    UNIQUE (article_id, version),
    FOREIGN KEY (article_id) REFERENCES articles(id)
);
```

`articles` é a tabela pai que mantém a versão atual mais recente.
`article_versions` acumula o histórico de alterações de conteúdo de forma **append-only**.

---

## Design dos Endpoints

| Método | Caminho | Descrição |
|--------|---------|-----------|
| POST | `/articles` | Criar artigo (primeiro commit como v1) |
| GET | `/articles/{id}` | Obter versão mais recente |
| PUT | `/articles/{id}` | Atualizar (acrescentar nova versão) |
| GET | `/articles/{id}/versions` | Listar versões |
| GET | `/articles/{id}/versions/{version}` | Obter versão específica |
| POST | `/articles/{id}/rollback` | Fazer rollback para versão especificada |

---

## Pontos de Design

### Versionamento Append-Only

Tanto update quanto rollback **acrescentam uma nova versão**. Não sobrescrevem linhas existentes:

```php
public function update(int $id, string $title, string $body, string $now): bool
{
    $article     = $this->find($id);
    $nextVersion = (int) $article['current_version'] + 1;

    $this->db->insert(
        'UPDATE articles SET title = ?, body = ?, current_version = ?, updated_at = ? WHERE id = ?',
        [$title, $body, $nextVersion, $now, $id],
    );
    $this->db->insert(
        'INSERT INTO article_versions (article_id, version, title, body, created_at) VALUES (?, ?, ?, ?, ?)',
        [$id, $nextVersion, $title, $body, $now],
    );
    return true;
}
```

**Vantagem**: Qualquer versão sempre pode ser referenciada. O rollback do BD e o rollback lógico são independentes.

### Rollback = Salvo como Nova Versão

Rollback é a operação de "criar uma nova versão com o conteúdo de uma versão específica".
Isso faz com que **o próprio rollback fique no histórico**, disponível para auditoria:

```
v1: Original title
v2: Modified title
v3: Original title  ← rollback para v1 é salvo aqui como nova versão
```

```php
public function rollback(int $id, int $version, string $now): bool
{
    $target      = $this->findVersion($id, $version);   // alvo para voltar
    $article     = $this->find($id);
    $nextVersion = (int) $article['current_version'] + 1;

    // Salvar conteúdo da versão alvo como nova versão
    $this->db->insert('UPDATE articles SET title = ?, body = ?, current_version = ? ...', [...]);
    $this->db->insert('INSERT INTO article_versions ...', [$id, $nextVersion, $target['title'], $target['body'], $now]);
    return true;
}
```

### Lista de Versões Exclui o Corpo

A API de listagem retorna apenas metadados sem `body`. O `body` é incluído na obtenção individual:

```
GET /articles/{id}/versions → [{version: 1, title: "...", created_at: "..."}, ...]
GET /articles/{id}/versions/1 → {version: 1, title: "...", body: "...", created_at: "..."}
```

### PHPStan: Consistência entre Valor de Retorno Nullable e Verificação de Null

Ao re-chamar `find()` após o rollback, o PHPStan pode considerar a verificação de null como "sempre verdadeira".
Projetar `formatArticle(?array)` para aceitar null elimina a necessidade de assert:

```php
// Incorreto: assert pode ser visto pelo PHPStan como "sempre verdadeiro"
$article = $this->repo->find($id);
assert($article !== null);
return $this->json->create($this->formatArticle($article));

// Correto: projetar formatArticle para aceitar null
return $this->json->create(array_merge($this->formatArticle($this->repo->find($id)), ['rolled_back_from' => $version]));
```

---

## Exemplos de Resposta

### POST /articles

```json
{
  "id": 1,
  "title": "My Post",
  "body": "Hello world",
  "current_version": 1,
  "created_at": "2026-01-01T00:00:00Z",
  "updated_at": "2026-01-01T00:00:00Z"
}
```

### GET /articles/{id}/versions

```json
{
  "versions": [
    {"id": 1, "article_id": 1, "version": 1, "title": "My Post", "created_at": "..."},
    {"id": 2, "article_id": 1, "version": 2, "title": "Updated", "created_at": "..."}
  ],
  "count": 2
}
```

### POST /articles/{id}/rollback

```json
{
  "id": 1,
  "title": "My Post",
  "current_version": 3,
  "rolled_back_from": 1
}
```

---

## Implementação de Referência

`../NENE2-FT/contentvlog/` — Field Trial FT162 (18 testes, histórico append-only, rollback)
