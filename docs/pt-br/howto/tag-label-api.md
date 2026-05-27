# Como Fazer: API de Tag / Label

Este guia demonstra uma API de tagueamento genérico de entidades onde tags arbitrárias podem ser anexadas a qualquer ID de entidade, com lookup reverso baseado em tags.

## Visão Geral do Padrão

- Tags são armazenadas globalmente e identificadas por um slug (`a-z0-9-`, 1–50 chars).
- Qualquer entidade (identificada por ID inteiro) pode ter múltiplas tags.
- `POST /tags` — Criar ou recuperar uma tag (find-or-create; idempotente).
- `GET /tags` — Listar todas as tags conhecidas.
- `GET /tags/{tag}/entities` — Lookup reverso: quais entidades têm esta tag?
- `POST /entities/{entityId}/tags` — Anexar uma tag a uma entidade.
- `GET /entities/{entityId}/tags` — Listar todas as tags de uma entidade.
- `DELETE /entities/{entityId}/tags/{tag}` — Desanexar uma tag de uma entidade.

## Schema

```sql
CREATE TABLE IF NOT EXISTS tags (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT    NOT NULL UNIQUE,
    created_at TEXT    NOT NULL
);
CREATE TABLE IF NOT EXISTS entity_tags (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    entity_id  INTEGER NOT NULL,
    tag_id     INTEGER NOT NULL,
    created_at TEXT    NOT NULL,
    FOREIGN KEY (tag_id) REFERENCES tags(id) ON DELETE CASCADE,
    UNIQUE (entity_id, tag_id)
);
```

## Padrão Find-or-Create

A criação de tag é idempotente — `POST /tags` retorna 201 na primeira criação, 200 se a tag já existe:

```php
public function findOrCreate(string $name): array
{
    $existing = $this->findByName($name);
    if ($existing !== null) {
        return $existing;
    }
    $this->pdo->prepare(
        'INSERT INTO tags (name, created_at) VALUES (:name, :now)'
    )->execute([':name' => $name, ':now' => $this->now()]);

    return $this->findByName($name) ?? [];
}
```

O handler `attachTag` também usa find-or-create para que clientes possam anexar tags sem uma etapa separada de criação.

## Validação do Nome da Tag

Nomes de tag são normalizados para minúsculas e validados com uma regex de formato rigorosa:

```php
private const string TAG_PATTERN = '/\A[a-z0-9-]{1,50}\z/';

$name = strtolower(trim((string) ($body['name'] ?? '')));
if (!preg_match(self::TAG_PATTERN, $name)) {
    return $this->problem(422, 'validation-failed', '...');
}
```

Espaços, maiúsculas, underscores e caracteres especiais são todos rejeitados.

## Lookup Reverso (Tag → Entidades)

`GET /tags/{tag}/entities` retorna 404 se a tag não existe no banco, e um array vazio se existe mas não está em uso:

```php
if ($this->repo->findByName($tag) === null) {
    return $this->problem(404, 'not-found', 'Tag não encontrada.');
}
return $this->json(['tag' => $tag, 'entity_ids' => $this->repo->entitiesForTag($tag)]);
```

SQL para lookup reverso:

```sql
SELECT entity_id FROM entity_tags WHERE tag_id = :tid ORDER BY entity_id ASC
```

## Idempotência de Anexar / Desanexar

Anexar a mesma tag à mesma entidade duas vezes retorna 200 (não 201) com `"attached": false`:

```php
$attached = $this->repo->attach($entityId, (int) $tag['id']);
return $this->json([...], $attached ? 201 : 200);
```

Desanexar uma tag que não está anexada retorna 404.

## Validação de ID de Entidade

IDs de entidade são validados com `ctype_digit()` para evitar ReDoS e garantir inteiros não negativos:

```php
if (!ctype_digit($raw) || strlen($raw) > 18) {
    return null;
}
$id = (int) $raw;
return $id > 0 ? $id : null;
```

## Rotas

```
POST   /tags                           Criar ou recuperar uma tag
GET    /tags                           Listar todas as tags
GET    /tags/{tag}/entities            Lookup reverso: entidades com esta tag
POST   /entities/{entityId}/tags       Anexar tag à entidade
GET    /entities/{entityId}/tags       Listar tags da entidade
DELETE /entities/{entityId}/tags/{tag} Desanexar tag da entidade
```

## Veja Também

- Fonte FT209: `../NENE2-FT/taglog/`
- Relacionado: `docs/howto/note-taking.md` (FT202, busca de notas baseada em tags)
