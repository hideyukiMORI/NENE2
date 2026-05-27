# How-to : Versionnage d'API

> **Référence FT** : FT346 (`NENE2-FT/versionlog`) — Versionnage par chemin URL avec namespaces /v1/ et /v2/, V1 dépréciée portant les en-têtes Deprecation/Sunset/Link, V2 avec une réponse enrichie, stockage sous-jacent partagé, 16 tests PASS.

Ce guide montre comment implémenter le versionnage par chemin URL : faire tourner deux versions d'API côte à côte avec des formes de réponse différentes, marquer l'ancienne version comme dépréciée avec des en-têtes HTTP, et partager une base de données unique entre les versions.

## Stratégie de versionnage

| Version | Statut      | Préfixe  | Enveloppe de liste              |
|---------|-------------|---------|---------------------------|
| V1      | Dépréciée  | `/v1/`  | `{"notes": [...]}`        |
| V2      | Actuelle     | `/v2/`  | `{"data": [...], "meta": {...}}` |

Les deux versions partagent les mêmes tables de base de données. Les clients V1 peuvent continuer à utiliser leur intégration existante pendant que les en-têtes de dépréciation signalent une date limite de migration.

## Endpoints

| Méthode   | Chemin V1         | Chemin V2         | Description     |
|----------|-----------------|-----------------|-----------------|
| `POST`   | `/v1/notes`     | `/v2/notes`     | Créer une note     |
| `GET`    | `/v1/notes`     | `/v2/notes`     | Lister les notes      |
| `GET`    | `/v1/notes/{id}`| `/v2/notes/{id}`| Obtenir une note |

## Forme de réponse V1

```php
// POST /v1/notes
{"title": "Hello", "content": "World"}
→ 201
{
  "id": 1,
  "title": "Hello",
  "content": "World",    // ← nom du champ : "content"
  "created_at": "..."
  // Pas de "body", "tags", ni "updated_at"
}

// GET /v1/notes
→ 200
{
  "notes": [              // ← clé d'enveloppe : "notes"
    {"id": 1, "title": "Hello", "content": "World", ...}
  ]
}
```

## Forme de réponse V2

```php
// POST /v2/notes
{"title": "Hello", "body": "World", "tags": ["php", "api"]}
→ 201
{
  "data": {               // ← clé d'enveloppe : "data"
    "id": 2,
    "title": "Hello",
    "body": "World",      // ← nom du champ : "body"
    "tags": ["php", "api"],  // ← tags ajoutés
    "updated_at": "...",     // ← updated_at ajouté
    "created_at": "..."
  }
}

// GET /v2/notes
→ 200
{
  "data": [...],          // ← enveloppe de liste : "data"
  "meta": {               // ← section meta
    "limit": 20,
    "offset": 0
  }
}
```

## En-têtes de dépréciation V1

Chaque réponse V1 porte trois en-têtes informant les clients de migrer :

```
Deprecation: true
Sunset: Sat, 01 Jan 2027 00:00:00 GMT
Link: </v2/notes>; rel="successor-version"
```

```php
// Chaque endpoint V1 ajoute :
return $response
    ->withHeader('Deprecation', 'true')
    ->withHeader('Sunset', 'Sat, 01 Jan 2027 00:00:00 GMT')
    ->withHeader('Link', '</v2/notes>; rel="successor-version"');
```

Les réponses V2 ne portent **aucun** de ces en-têtes.

```php
// En-têtes de GET /v1/notes :
Deprecation: true
Sunset: Sat, 01 Jan 2027 00:00:00 GMT
Link: </v2/notes>; rel="successor-version"

// En-têtes de GET /v2/notes :
// (pas de Deprecation, Sunset, ni Link)
```

## Stockage partagé — Accès inter-versions

Les deux versions partagent la même table `notes`. Une note créée via V1 est lisible depuis V2 (et vice versa) :

```php
// Créer via V1
POST /v1/notes  {"title": "Cross-version", "content": "Shared body"}
→ 201  {"id": 5, "title": "Cross-version", "content": "Shared body", ...}

// Lire via V2 — même enregistrement, forme V2
GET /v2/notes/5
→ 200
{
  "data": {
    "id": 5,
    "title": "Cross-version",
    "body": "Shared body",    // V2 l'appelle "body", pas "content"
    "tags": [],
    "updated_at": "...",
    "created_at": "..."
  }
}
```

Les clients V1 ne voient jamais `tags` (pas dans la forme de réponse V1), même si la note a des tags d'une écriture V2.

## Schéma

```sql
CREATE TABLE notes (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    title      TEXT    NOT NULL,
    body       TEXT    NOT NULL DEFAULT '',
    tags       TEXT    NOT NULL DEFAULT '[]',  -- tableau JSON
    created_at TEXT    NOT NULL,
    updated_at TEXT    NOT NULL
);
```

La colonne sous-jacente est `body`. V1 la mappe vers `content` dans le transformateur de réponse.

## Implémentation — Transformateurs de réponse

```php
// Transformateur V1 — mappe la colonne "body" → champ "content", cache tags/updated_at
final class V1NoteTransformer
{
    /** @param array<string, mixed> $row */
    public function transform(array $row): array
    {
        return [
            'id'         => $row['id'],
            'title'      => $row['title'],
            'content'    => $row['body'],   // renommage de champ
            'created_at' => $row['created_at'],
            // Pas de "body", "tags", "updated_at"
        ];
    }
}

// Transformateur V2 — ligne complète, enveloppée dans "data"
final class V2NoteTransformer
{
    /** @param array<string, mixed> $row */
    public function transform(array $row): array
    {
        return [
            'id'         => $row['id'],
            'title'      => $row['title'],
            'body'       => $row['body'],
            'tags'       => json_decode($row['tags'], true),
            'updated_at' => $row['updated_at'],
            'created_at' => $row['created_at'],
        ];
    }
}
```

## Enregistrement des routes

```php
// V1Registrar::register()
$router->get('/v1/notes',       [V1ListHandler::class, 'handle']);
$router->post('/v1/notes',      [V1CreateHandler::class, 'handle']);
$router->get('/v1/notes/{id}',  [V1GetHandler::class, 'handle']);

// V2Registrar::register()
$router->get('/v2/notes',       [V2ListHandler::class, 'handle']);
$router->post('/v2/notes',      [V2CreateHandler::class, 'handle']);
$router->get('/v2/notes/{id}',  [V2GetHandler::class, 'handle']);
```

Les deux registrars sont passés à `RuntimeApplicationFactory` — les routes des deux sont enregistrées dans le même routeur.

## Version inconnue → 404

```php
GET /v3/notes
→ 404
```

Il n'y a pas de route V3 ; le routeur retourne 404. Aucun type d'erreur "version non supportée" n'est nécessaire — 404 est suffisant.

## Validation

```php
POST /v1/notes  {"content": "no title"}
→ 422  // title est requis

POST /v2/notes  {"body": "no title"}
→ 422  // title est requis
```

Les deux versions requièrent `title`. V1 accepte `content` comme champ de corps ; V2 accepte `body`.

---

## Ce qu'il ne faut PAS faire

| Anti-pattern | Risque |
|---|---|
| Tables DB différentes par version | Les lectures inter-versions se cassent ; les données migrent mal quand les versions ne partagent pas d'état |
| Retourner `Deprecation: true` sur V2 | Les clients ne peuvent pas distinguer quelle version est actuelle |
| Pas d'en-tête `Link` avec successeur | Les clients dépréciés ne savent pas vers où migrer |
| Renommer la colonne DB `body` → `content` pour V1 | Tout le code V2 doit changer ; utiliser le transformateur de réponse pour renommer, pas le schéma |
| Coder en dur la date Sunset dans les tests | Les tests échouent après la date sunset ; utiliser une constante future ou une valeur de configuration |
| Exposer les `tags` V1 dans la réponse | Les clients V1 reçoivent un champ qu'ils ne comprennent pas ; les contrats de forme se cassent silencieusement |
