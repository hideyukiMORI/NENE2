# Anleitung: Tag-/Label-API

Diese Anleitung demonstriert eine generische Entitäts-Tagging-API, bei der beliebige Tags an beliebige Entitäts-IDs angehängt werden können, mit tag-basierter Rückwärtssuche.

## Musterübersicht

- Tags werden global gespeichert und durch einen Slug (`a-z0-9-`, 1–50 Zeichen) identifiziert.
- Jede Entität (identifiziert durch Integer-ID) kann mehrere Tags haben.
- `POST /tags` — Tag erstellen oder abrufen (Find-or-Create; idempotent).
- `GET /tags` — Alle bekannten Tags auflisten.
- `GET /tags/{tag}/entities` — Rückwärtssuche: Welche Entitäten haben diesen Tag?
- `POST /entities/{entityId}/tags` — Tag an eine Entität anhängen.
- `GET /entities/{entityId}/tags` — Alle Tags für eine Entität auflisten.
- `DELETE /entities/{entityId}/tags/{tag}` — Tag von einer Entität ablösen.

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

## Find-or-Create-Muster

Tag-Erstellung ist idempotent — `POST /tags` gibt 201 bei der ersten Erstellung zurück, 200 wenn der Tag bereits existiert:

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

Der `attachTag`-Handler verwendet ebenfalls Find-or-Create, sodass Clients Tags anhängen können, ohne einen separaten Erstellungsschritt durchzuführen.

## Tag-Name-Validierung

Tag-Namen werden in Kleinbuchstaben normalisiert und mit einem strengen Format-Regex validiert:

```php
private const string TAG_PATTERN = '/\A[a-z0-9-]{1,50}\z/';

$name = strtolower(trim((string) ($body['name'] ?? '')));
if (!preg_match(self::TAG_PATTERN, $name)) {
    return $this->problem(422, 'validation-failed', '...');
}
```

Leerzeichen, Großbuchstaben, Unterstriche und Sonderzeichen werden alle abgelehnt.

## Rückwärtssuche (Tag → Entitäten)

`GET /tags/{tag}/entities` gibt 404 zurück, wenn der Tag nicht in der Datenbank existiert, und ein leeres Array, wenn er existiert, aber nicht verwendet wird:

```php
if ($this->repo->findByName($tag) === null) {
    return $this->problem(404, 'not-found', 'Tag not found.');
}
return $this->json(['tag' => $tag, 'entity_ids' => $this->repo->entitiesForTag($tag)]);
```

SQL für die Rückwärtssuche:

```sql
SELECT entity_id FROM entity_tags WHERE tag_id = :tid ORDER BY entity_id ASC
```

## Anhängen/Ablösen-Idempotenz

Das zweimalige Anhängen desselben Tags an dieselbe Entität gibt 200 zurück (nicht 201) mit `"attached": false`:

```php
$attached = $this->repo->attach($entityId, (int) $tag['id']);
return $this->json([...], $attached ? 201 : 200);
```

Das Ablösen eines Tags, das nicht angehängt ist, gibt 404 zurück.

## Entitäts-ID-Validierung

Entitäts-IDs werden mit `ctype_digit()` validiert, um ReDoS zu vermeiden und nicht-negative Integer sicherzustellen:

```php
if (!ctype_digit($raw) || strlen($raw) > 18) {
    return null;
}
$id = (int) $raw;
return $id > 0 ? $id : null;
```

## Routen

```
POST   /tags                           Tag erstellen oder abrufen
GET    /tags                           Alle Tags auflisten
GET    /tags/{tag}/entities            Rückwärtssuche: Entitäten mit diesem Tag
POST   /entities/{entityId}/tags       Tag an Entität anhängen
GET    /entities/{entityId}/tags       Tags für Entität auflisten
DELETE /entities/{entityId}/tags/{tag} Tag von Entität ablösen
```

## Siehe auch

- FT209-Quelle: `../NENE2-FT/taglog/`
- Verwandt: `docs/howto/note-taking.md` (FT202, tag-basierte Notizsuche)
