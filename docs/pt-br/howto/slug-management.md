# Gerenciamento de Slug — Slugs de URL Únicos com Resolução de Colisão e Histórico

Gere slugs seguros para URL a partir de títulos, resolva colisões automaticamente e mantenha uma **tabela de histórico de slugs** para que slugs antigos redirecionem para a URL canônica sem quebrar links externos.

**Implementação de referência:** `FT174 sluglog` em
[hideyukiMORI/NENE2-examples](https://github.com/hideyukiMORI/NENE2-examples)

---

## Schema

```sql
CREATE TABLE articles (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    title      TEXT    NOT NULL,
    slug       TEXT    NOT NULL UNIQUE,   -- slug canônico atual
    body       TEXT    NOT NULL,
    created_at TEXT    NOT NULL,
    updated_at TEXT    NOT NULL
);

-- Slugs antigos mantidos para suporte de redirecionamento
CREATE TABLE slug_history (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    article_id  INTEGER NOT NULL,
    old_slug    TEXT    NOT NULL UNIQUE,  -- fonte de redirecionamento; UNIQUE previne duplicatas
    replaced_at TEXT    NOT NULL,
    FOREIGN KEY (article_id) REFERENCES articles(id)
);
```

---

## Geração de Slug

```php
final class SlugHelper
{
    public static function fromTitle(string $title): string
    {
        $slug = mb_strtolower($title);
        $slug = (string) preg_replace('/[^a-z0-9]+/', '-', $slug);
        $slug = trim($slug, '-');
        return $slug !== '' ? $slug : 'untitled';
    }

    /**
     * @param callable(string): bool $exists  Retorna true se o slug já está em uso.
     */
    public static function makeUnique(string $base, callable $exists): string
    {
        if (!$exists($base)) {
            return $base;
        }
        $counter = 2;
        while ($exists("{$base}-{$counter}")) {
            $counter++;
        }
        return "{$base}-{$counter}";
    }
}
```

### Verificação de Unicidade — Inclua Ambas as Tabelas

Ao verificar se um slug está "em uso", verifique **ambas** `articles.slug` e
`slug_history.old_slug`. Caso contrário, um novo artigo poderia reivindicar um slug que ainda está em uso ativo como fonte de redirecionamento:

```php
private function slugExists(string $slug): bool
{
    return $this->db->fetchOne('SELECT id FROM articles WHERE slug = ?', [$slug]) !== null
        || $this->db->fetchOne('SELECT id FROM slug_history WHERE old_slug = ?', [$slug]) !== null;
}
```

---

## Busca de Slug com Dica de Redirecionamento

```php
public function findBySlugWithRedirect(string $slug): ?array
{
    // 1. Verificar coluna de slug atual (200 OK)
    $article = $this->findBySlug($slug);
    if ($article !== null) {
        return ['found' => $article, 'redirect' => false];
    }

    // 2. Verificar histórico de slug (dica de Redirect 301)
    $row = $this->db->fetchOne(
        'SELECT article_id FROM slug_history WHERE old_slug = ?', [$slug],
    );
    if ($row === null) {
        return null;  // 404
    }

    $article = $this->findById((int) $row['article_id']);
    return $article !== null ? ['found' => $article, 'redirect' => true] : null;
}
```

O handler então retorna HTTP 301 com `canonical_slug` e `data`:

```json
// GET /articles/by-slug/titulo-antigo  →  301
{
  "redirect": true,
  "canonical_slug": "novo-titulo",
  "data": { "id": 1, "slug": "novo-titulo", ... }
}
```

---

## Atualização de Slug — Registrar Histórico

Quando um artigo é renomeado, mova o slug antigo para `slug_history`:

```php
if ($newSlug !== $article->slug) {
    // Inserir apenas se ainda não estiver no histórico (idempotente)
    $alreadyIn = $this->db->fetchOne(
        'SELECT id FROM slug_history WHERE old_slug = ?', [$article->slug],
    );
    if ($alreadyIn === null) {
        $this->db->insert(
            'INSERT INTO slug_history (article_id, old_slug, replaced_at) VALUES (?, ?, ?)',
            [$id, $article->slug, $now],
        );
    }
}
```

### Tratamento de Colisão na Atualização

Ao calcular o novo slug para um artigo atualizado, exclua o próprio slug **atual** do artigo da verificação de "existe" — caso contrário, incrementaria desnecessariamente para `-2`:

```php
$newSlug = SlugHelper::makeUnique(
    $newSlugBase,
    fn (string $s): bool => $s !== $article->slug && $this->slugExists($s),
);
```

---

## Endpoints

| Método | Caminho | Descrição |
|---|---|---|
| `POST` | `/articles` | Criar artigo — slug derivado automaticamente do título |
| `GET` | `/articles/{id}` | Obter por ID numérico |
| `GET` | `/articles/by-slug/{slug}` | Obter por slug (200 atual / 301 histórico / 404) |
| `PUT` | `/articles/{id}` | Atualizar título/corpo/slug; slug antigo → histórico |
| `GET` | `/articles/{id}/slug-history` | Listar slugs históricos |

---

## Cenários de Colisão

| Cenário | Resultado |
|---|---|
| Primeiro "Hello World" | `hello-world` |
| Segundo "Hello World" | `hello-world-2` |
| Terceiro "Hello World" | `hello-world-3` |
| Artigo renomeado de `hello` para slug já em uso | `slug-em-uso-2` |
| Mesmo título, sem alteração no slug | Sem entrada no histórico, slug inalterado |
| Slug antigo corresponde a uma entrada no histórico | Resposta de redirecionamento 301 |

---

## Estrutura da Camada de Domínio

```
src/Article/
├── Article.php
├── ArticleRepository.php   # create / findBySlug / findBySlugWithRedirect / update / slugHistory
├── SlugHelper.php          # fromTitle() + makeUnique()
└── ArticleNotFoundException.php
```

---

## Veja Também

- [Soft Delete](./soft-delete.md) — combinar histórico de slug com registros soft-deleted
- [Versionamento de Conteúdo](./content-versioning.md) — histórico de versões junto com histórico de slug
- [Ciclo de Vida de Rascunho de Conteúdo](./content-draft-lifecycle.md) — comportamento de slug em estados de rascunho
