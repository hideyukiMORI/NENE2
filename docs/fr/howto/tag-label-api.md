# How-to : API de tags / étiquettes

Ce guide démontre une API de tagging d'entités générique où des tags arbitraires peuvent être attachés à n'importe quel ID d'entité, avec recherche inverse basée sur les tags.

## Vue d'ensemble du pattern

- Les tags sont stockés globalement et identifiés par un slug (`a-z0-9-`, 1–50 caractères).
- Toute entité (identifiée par un ID entier) peut avoir plusieurs tags.
- `POST /tags` — Créer ou récupérer un tag (find-or-create ; idempotent).
- `GET /tags` — Lister tous les tags connus.
- `GET /tags/{tag}/entities` — Recherche inverse : quelles entités ont ce tag ?
- `POST /entities/{entityId}/tags` — Attacher un tag à une entité.
- `GET /entities/{entityId}/tags` — Lister tous les tags d'une entité.
- `DELETE /entities/{entityId}/tags/{tag}` — Détacher un tag d'une entité.

## Schéma

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

## Pattern find-or-create

La création de tag est idempotente — `POST /tags` retourne 201 à la première création, 200 si le tag existe déjà :

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

Le handler `attachTag` utilise aussi find-or-create pour que les clients puissent attacher des tags sans une étape de création séparée.

## Validation du nom de tag

Les noms de tags sont normalisés en minuscules et validés avec un regex de format strict :

```php
private const string TAG_PATTERN = '/\A[a-z0-9-]{1,50}\z/';

$name = strtolower(trim((string) ($body['name'] ?? '')));
if (!preg_match(self::TAG_PATTERN, $name)) {
    return $this->problem(422, 'validation-failed', '...');
}
```

Les espaces, majuscules, underscores et caractères spéciaux sont tous rejetés.

## Recherche inverse (Tag → Entités)

`GET /tags/{tag}/entities` retourne 404 si le tag n'existe pas dans la base de données, et un tableau vide s'il existe mais n'est pas utilisé :

```php
if ($this->repo->findByName($tag) === null) {
    return $this->problem(404, 'not-found', 'Tag not found.');
}
return $this->json(['tag' => $tag, 'entity_ids' => $this->repo->entitiesForTag($tag)]);
```

SQL pour la recherche inverse :

```sql
SELECT entity_id FROM entity_tags WHERE tag_id = :tid ORDER BY entity_id ASC
```

## Idempotence d'attacher / détacher

Attacher le même tag à la même entité deux fois retourne 200 (pas 201) avec `"attached": false` :

```php
$attached = $this->repo->attach($entityId, (int) $tag['id']);
return $this->json([...], $attached ? 201 : 200);
```

Détacher un tag qui n'est pas attaché retourne 404.

## Validation de l'ID d'entité

Les IDs d'entité sont validés avec `ctype_digit()` pour éviter ReDoS et assurer des entiers non négatifs :

```php
if (!ctype_digit($raw) || strlen($raw) > 18) {
    return null;
}
$id = (int) $raw;
return $id > 0 ? $id : null;
```

## Routes

```
POST   /tags                           Créer ou récupérer un tag
GET    /tags                           Lister tous les tags
GET    /tags/{tag}/entities            Recherche inverse : entités avec ce tag
POST   /entities/{entityId}/tags       Attacher un tag à une entité
GET    /entities/{entityId}/tags       Lister les tags d'une entité
DELETE /entities/{entityId}/tags/{tag} Détacher un tag d'une entité
```

## Voir aussi

- Source FT209 : `../NENE2-FT/taglog/`
- Connexe : `docs/howto/note-taking.md` (FT202, recherche de notes basée sur les tags)
