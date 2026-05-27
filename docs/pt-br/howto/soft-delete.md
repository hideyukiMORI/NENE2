# Soft Delete (Exclusão Lógica)

Soft delete mantém um registro no banco de dados mas o marca como excluído definindo um timestamp `deleted_at`. Isso permite:
- Funcionalidade de desfazer / restaurar
- Trilhas de auditoria (quem excluiu o quê, quando)
- Integridade referencial (registros ainda podem ser referenciados até serem purgados)

## Schema

Adicione uma coluna `deleted_at` que é `NULL` para registros ativos e um timestamp para registros excluídos:

```sql
CREATE TABLE articles (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    title      TEXT NOT NULL,
    body       TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    deleted_at TEXT NULL          -- NULL = ativo, timestamp = excluído
);
```

## A Regra Crítica: Sempre Filtrar deleted_at

**Toda query que deve retornar apenas registros ativos deve incluir `AND deleted_at IS NULL`.** Faltando este filtro é o erro mais comum — o código funciona mas dados excluídos vazam nas respostas da API.

```php
// ❌ Filtro ausente — retorna registros excluídos também
$rows = $this->executor->fetchAll('SELECT * FROM articles WHERE id = ?', [$id]);

// ✅ Excluir deletados
$rows = $this->executor->fetchAll(
    'SELECT * FROM articles WHERE id = ? AND deleted_at IS NULL',
    [$id],
);
```

Isso se aplica a toda query: `findById`, `findAll`, `findByUser`, queries de paginação e alvos de JOIN.

## Entidade

```php
final readonly class Article
{
    public function __construct(
        public int $id,
        public string $title,
        public string $body,
        public string $createdAt,
        public string $updatedAt,
        public ?string $deletedAt,
    ) {
    }

    public function isDeleted(): bool
    {
        return $this->deletedAt !== null;
    }
}
```

## Padrão Repository

Use uma flag `$includeTrashed = false`. O padrão `false` significa que chamadores devem optar explicitamente para ver registros excluídos, o que previne vazamento acidental:

```php
final class ArticleRepository
{
    public function findById(int $id, bool $includeTrashed = false): ?Article
    {
        $sql = $includeTrashed
            ? 'SELECT * FROM articles WHERE id = ?'
            : 'SELECT * FROM articles WHERE id = ? AND deleted_at IS NULL';

        $row = $this->executor->fetchOne($sql, [$id]);
        return $row !== null ? $this->hydrate($row) : null;
    }

    /** @return list<Article> */
    public function findActive(): array
    {
        $rows = $this->executor->fetchAll(
            'SELECT * FROM articles WHERE deleted_at IS NULL ORDER BY created_at DESC',
        );
        return array_map($this->hydrate(...), $rows);
    }

    /** @return list<Article> */
    public function findTrashed(): array
    {
        $rows = $this->executor->fetchAll(
            'SELECT * FROM articles WHERE deleted_at IS NOT NULL ORDER BY deleted_at DESC',
        );
        return array_map($this->hydrate(...), $rows);
    }

    public function softDelete(int $id): ?Article
    {
        $article = $this->findById($id); // somente ativo
        if ($article === null) {
            return null;
        }
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $this->executor->execute('UPDATE articles SET deleted_at = ? WHERE id = ?', [$now, $id]);
        return new Article($article->id, $article->title, $article->body, $article->createdAt, $article->updatedAt, $now);
    }

    public function restore(int $id): ?Article
    {
        $article = $this->findById($id, includeTrashed: true);
        if ($article === null || !$article->isDeleted()) {
            return null; // não encontrado, ou não está na lixeira
        }
        $this->executor->execute('UPDATE articles SET deleted_at = NULL WHERE id = ?', [$id]);
        return new Article($article->id, $article->title, $article->body, $article->createdAt, $article->updatedAt, null);
    }

    /** Excluir permanentemente — permitido apenas da lixeira. */
    public function purge(int $id): bool
    {
        $article = $this->findById($id, includeTrashed: true);
        if ($article === null || !$article->isDeleted()) {
            return false; // guarda: deve estar na lixeira primeiro
        }
        $this->executor->execute('DELETE FROM articles WHERE id = ?', [$id]);
        return true;
    }
}
```

### Use `insert()` para INSERT

Ao criar registros, use `insert()` (não `execute()` + `lastInsertId()`):

```php
// ❌ Duas chamadas
$this->executor->execute('INSERT INTO articles ...', [...]);
$id = $this->executor->lastInsertId();

// ✅ Uma chamada — retorna o ID da linha inserida
$id = $this->executor->insert('INSERT INTO articles ...', [...]);
```

## Endpoints

Uma API típica de soft-delete:

| Método | Caminho | Descrição |
|---|---|---|
| `POST` | `/articles` | Criar |
| `GET` | `/articles` | Apenas registros ativos |
| `GET` | `/articles/trash` | Apenas registros excluídos |
| `GET` | `/articles/{id}` | Obter um (404 se excluído) |
| `DELETE` | `/articles/{id}` | Soft delete → 404 se já excluído |
| `POST` | `/articles/{id}/restore` | Restaurar → 404 se não estiver na lixeira |
| `DELETE` | `/articles/{id}/purge` | Hard delete → 404 se não estiver na lixeira |

**Nota sobre semântica REST:** `DELETE /articles/{id}` se comporta como um soft delete, não uma remoção permanente. Se isso surpreende clientes, documente claramente no spec OpenAPI, ou use `POST /articles/{id}/trash` para a ação de soft-delete.

## Sempre Inclua `deleted_at` nas Respostas

Inclua `deleted_at` em toda resposta para que clientes possam determinar o estado do recurso sem requisições extras:

```php
return $this->json->create([
    'id'         => $article->id,
    'title'      => $article->title,
    'body'       => $article->body,
    'created_at' => $article->createdAt,
    'updated_at' => $article->updatedAt,
    'deleted_at' => $article->deletedAt, // null = ativo; timestamp = excluído
]);
```

## Chaves Estrangeiras e Soft Delete

Quando outras tabelas referenciam um registro soft-deleted:
- Soft deletion não quebra restrições de chave estrangeira — a linha ainda existe
- Hard delete (purga) pode violar restrições se houver linhas referenciando
- Antes de purgar, verifique registros dependentes ou cascade soft delete para dependentes

## Checklist de Code Review

- [ ] Toda query para registros ativos inclui `AND deleted_at IS NULL`
- [ ] `findById()` padrão é `$includeTrashed = false` — chamadores optam explicitamente
- [ ] `purge()` protege contra hard-delete de registros ativos (verificação `isDeleted()`)
- [ ] `restore()` retorna `null` (→ 404) quando o registro não está na lixeira
- [ ] Queries JOIN em tabelas soft-deleted também filtram `deleted_at IS NULL` na tabela juntada
- [ ] `deleted_at` é incluído nas respostas da API para que clientes possam determinar estado
- [ ] O comportamento de `DELETE /articles/{id}` (soft vs hard) está documentado no OpenAPI
- [ ] Testes cobrem: delete → 404 no GET, lista exclui deletados, restore → visível novamente, purge → desaparecido em todo lugar, double-delete → 404, purge ativo → 404
- [ ] `insert()` é usado para INSERT (não `execute()` + `lastInsertId()`)
