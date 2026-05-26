# How-to: Tag / Label API

This guide demonstrates a generic entity tagging API where arbitrary tags can be attached to any entity ID, with tag-based reverse lookup.

## Pattern Overview

- Tags are stored globally and identified by a slug (`a-z0-9-`, 1–50 chars).
- Any entity (identified by integer ID) can have multiple tags.
- `POST /tags` — Create or retrieve a tag (find-or-create; idempotent).
- `GET /tags` — List all known tags.
- `GET /tags/{tag}/entities` — Reverse lookup: which entities have this tag?
- `POST /entities/{entityId}/tags` — Attach a tag to an entity.
- `GET /entities/{entityId}/tags` — List all tags for an entity.
- `DELETE /entities/{entityId}/tags/{tag}` — Detach a tag from an entity.

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

## Find-or-Create Pattern

Tag creation is idempotent — `POST /tags` returns 201 on first creation, 200 if the tag already exists:

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

The `attachTag` handler also uses find-or-create so clients can attach tags without a separate create step.

## Tag Name Validation

Tag names are normalized to lowercase and validated with a tight format regex:

```php
private const string TAG_PATTERN = '/\A[a-z0-9-]{1,50}\z/';

$name = strtolower(trim((string) ($body['name'] ?? '')));
if (!preg_match(self::TAG_PATTERN, $name)) {
    return $this->problem(422, 'validation-failed', '...');
}
```

Spaces, uppercase, underscores, and special characters are all rejected.

## Reverse Lookup (Tag → Entities)

`GET /tags/{tag}/entities` returns 404 if the tag doesn't exist in the database, and an empty array if it exists but is unused:

```php
if ($this->repo->findByName($tag) === null) {
    return $this->problem(404, 'not-found', 'Tag not found.');
}
return $this->json(['tag' => $tag, 'entity_ids' => $this->repo->entitiesForTag($tag)]);
```

SQL for reverse lookup:

```sql
SELECT entity_id FROM entity_tags WHERE tag_id = :tid ORDER BY entity_id ASC
```

## Attach / Detach Idempotency

Attaching the same tag to the same entity twice returns 200 (not 201) with `"attached": false`:

```php
$attached = $this->repo->attach($entityId, (int) $tag['id']);
return $this->json([...], $attached ? 201 : 200);
```

Detaching a tag that is not attached returns 404.

## Entity ID Validation

Entity IDs are validated with `ctype_digit()` to avoid ReDoS and ensure non-negative integers:

```php
if (!ctype_digit($raw) || strlen($raw) > 18) {
    return null;
}
$id = (int) $raw;
return $id > 0 ? $id : null;
```

## Routes

```
POST   /tags                           Create or retrieve a tag
GET    /tags                           List all tags
GET    /tags/{tag}/entities            Reverse lookup: entities with this tag
POST   /entities/{entityId}/tags       Attach tag to entity
GET    /entities/{entityId}/tags       List tags for entity
DELETE /entities/{entityId}/tags/{tag} Detach tag from entity
```

## See Also

- FT209 source: `../NENE2-FT/taglog/`
- Related: `docs/howto/note-taking.md` (FT202, tag-based note search)
