# How-to : API de liste de surveillance média

> **Référence FT** : FT59 (`NENE2-FT/watchlog`) — API Media Watch List

Démontre une liste de surveillance média personnelle avec des enums de chaîne backed pour le statut et le type, des champs nullable optionnels utilisant `array_key_exists`, l'archivage/restauration via des endpoints d'action POST, et une note entière de 1 à 5. Toute la validation de statut et de type utilise `BackedEnum::tryFrom()` de PHP pour garantir que seules les valeurs connues sont acceptées.

---

## Routes

| Méthode | Chemin | Description |
|---------|--------|-------------|
| `GET` | `/watch` | Lister les entrées (filtrées et paginées) |
| `POST` | `/watch` | Ajouter une entrée à la liste |
| `GET` | `/watch/{id}` | Obtenir une seule entrée |
| `PATCH` | `/watch/{id}/status` | Mettre à jour le statut (et optionnellement la note/commentaire) |
| `POST` | `/watch/{id}/archive` | Déplacer l'entrée vers l'archive |
| `POST` | `/watch/{id}/restore` | Restaurer une entrée archivée |
| `DELETE` | `/watch/{id}` | Supprimer définitivement une entrée |

---

## Validation par enum backed

Le statut et le type de média sont validés avec `BackedEnum::tryFrom()`. L'enum sert aussi de type dans la sérialisation, donc la valeur de chaîne écrite dans la DB et la valeur de chaîne dans la réponse JSON restent synchronisées automatiquement.

```php
enum WatchStatus: string
{
    case WantToWatch = 'want-to-watch';
    case Watching    = 'watching';
    case Completed   = 'completed';
    case Dropped     = 'dropped';
}

enum MediaType: string
{
    case Movie = 'movie';
    case Tv    = 'tv';
}
```

Dans le contrôleur, `tryFrom()` retourne `null` pour les valeurs inconnues, ce qui correspond à un 422 :

```php
$statusRaw = isset($body['status']) && is_string($body['status']) ? $body['status'] : null;
$status    = $statusRaw !== null ? WatchStatus::tryFrom($statusRaw) : null;

if ($statusRaw === null) {
    $errors[] = new ValidationError('status', 'status is required.', 'required');
} elseif ($status === null) {
    $errors[] = new ValidationError('status', 'Invalid status value.', 'invalid_value');
}
```

La vérification en deux étapes distingue "champ absent" (required) de "champ présent mais invalide" (invalid_value), produisant de meilleurs messages d'erreur.

---

## Listage avec filtres typés par enum

Les paramètres de requête sont analysés via `QueryStringParser`, puis validés via `tryFrom()` :

```php
$statusRaw = QueryStringParser::string($request, 'status');   // null si absent
$status    = $statusRaw !== null ? WatchStatus::tryFrom($statusRaw) : null;

if ($statusRaw !== null && $status === null) {
    $errors[] = new ValidationError('status', 'Invalid status value.', 'invalid_value');
}
```

Ce pattern — analyser, tenter la conversion en enum, valider — garde la logique de routage hors du code domaine. Le repository accepte `?WatchStatus` et `?MediaType` et filtre en conséquence.

**Filtres supportés** :
- `?status=watching` — filtrer par statut
- `?media_type=movie` — filtrer par type de média
- `?include_archived=1` — inclure les entrées archivées (exclues par défaut)
- `?limit=20&offset=0` — pagination

---

## Champs nullable avec `array_key_exists`

`rating` et `note` sont nullable — les appelants peuvent les définir explicitement à `null` pour les effacer. Utiliser `isset()` manquerait un `null` explicitement envoyé. Utiliser `array_key_exists()` :

```php
// ✓ Correct : distingue absent de null explicite
$rating = array_key_exists('rating', $body) ? $body['rating'] : null;

// ✗ Faux : array_key_exists($body, 'rating') avale le null intentionnel
if ($rating !== null) {
    if (!is_int($rating) || $rating < 1 || $rating > 5) {
        $errors[] = new ValidationError('rating', 'rating must be an integer from 1 to 5.', 'out_of_range');
    }
}
```

`is_int($rating)` rejette les floats JSON (`4.0` → PHP `float`) et les chaînes (`"4"`). Seul un entier JSON littéral (`4`) passe la vérification de type stricte.

---

## Archive / restauration via endpoints d'action POST

L'archivage et la restauration sont des mutations (ils changent l'état et enregistrent un horodatage), donc ils utilisent `POST`, pas `DELETE` ou `PATCH`. Cela suit le pattern d'endpoint d'action :

```php
// POST /watch/{id}/archive
private function archive(ServerRequestInterface $request): ResponseInterface
{
    $id    = (int) ($request->getAttribute(Router::PARAMETERS_ATTRIBUTE)['id'] ?? 0);
    $entry = $this->repository->archive($id, (new \DateTimeImmutable())->format('Y-m-d\TH:i:s\Z'));

    return $this->json->create($this->serialize($entry));
}

// POST /watch/{id}/restore
private function restore(ServerRequestInterface $request): ResponseInterface
{
    $id    = (int) ($request->getAttribute(Router::PARAMETERS_ATTRIBUTE)['id'] ?? 0);
    $entry = $this->repository->restore($id, (new \DateTimeImmutable())->format('Y-m-d\TH:i:s\Z'));

    return $this->json->create($this->serialize($entry));
}
```

`archive()` définit `archived_at` à l'horodatage courant ; `restore()` le remet à `null`. L'endpoint de liste cache les entrées archivées par défaut (`include_archived=false`).

Pourquoi `POST` et pas `DELETE` pour l'archivage ? `DELETE` implique une suppression permanente. L'archivage est un changement d'état doux — l'entrée reste dans la DB et est récupérable. Nommer les endpoints d'après l'action (`/archive`, `/restore`) rend l'intention explicite.

---

## Schéma : les contraintes CHECK correspondent aux valeurs d'enum

```sql
CREATE TABLE watch_entries (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    title       TEXT NOT NULL,
    media_type  TEXT NOT NULL CHECK(media_type IN ('movie', 'tv')),
    status      TEXT NOT NULL DEFAULT 'want-to-watch'
                              CHECK(status IN ('want-to-watch', 'watching', 'completed', 'dropped')),
    rating      INTEGER CHECK(rating IS NULL OR (rating >= 1 AND rating <= 5)),
    note        TEXT NOT NULL DEFAULT '',
    created_at  TEXT NOT NULL,
    updated_at  TEXT NOT NULL,
    archived_at TEXT
);
```

Les contraintes `CHECK` DB reflètent les cases d'enum — si un nouveau statut est ajouté à l'enum sans mettre à jour le `CHECK`, l'insertion échoue à la couche DB. Garder les deux synchronisés : ajouter le nouveau case à l'enum, au `CHECK`, et à toute migration.

`rating CHECK(rating IS NULL OR ...)` autorise correctement la colonne à être `NULL` tout en appliquant la plage 1–5 quand une valeur est présente.

`archived_at TEXT` (nullable) agit comme indicateur d'archivage : `NULL` = actif, non-null = archivé. C'est le pattern d'archive douce minimal — pas besoin d'une colonne `is_archived BOOLEAN` séparée.

---

## Index pour la performance des listes

```sql
CREATE INDEX idx_watch_status      ON watch_entries (status);
CREATE INDEX idx_watch_archived_at ON watch_entries (archived_at);
```

`idx_watch_archived_at` supporte le filtre courant `WHERE archived_at IS NULL` (entrées actives). SQLite peut utiliser cet index pour les conditions `IS NULL` via un pattern d'index partiel, mais un index simple est suffisant pour la plupart des listes de surveillance.

---

## Sérialisation

```php
/** @return array<string, mixed> */
private function serialize(WatchEntry $entry): array
{
    return [
        'id'          => $entry->id,
        'title'       => $entry->title,
        'media_type'  => $entry->mediaType->value,  // enum → string
        'status'      => $entry->status->value,      // enum → string
        'rating'      => $entry->rating,             // int|null
        'note'        => $entry->note,
        'created_at'  => $entry->createdAt,
        'updated_at'  => $entry->updatedAt,
        'archived_at' => $entry->archivedAt,         // string|null
    ];
}
```

`->value` sur un enum backed retourne la valeur de chaîne du case (ex: `'want-to-watch'`). Sérialiser les enums de cette façon plutôt qu'en appelant `->name` — le name est l'identifiant PHP (`WantToWatch`), pas la valeur du contrat API.

---

## Howtos associés

- [`content-draft-lifecycle.md`](content-draft-lifecycle.md) — machine à états avec transitions de statut
- [`soft-delete.md`](soft-delete.md) — suppression douce avec horodatage `deleted_at`
- [`implement-patch-endpoint.md`](implement-patch-endpoint.md) — mises à jour partielles avec `array_key_exists`
- [`add-custom-route.md`](add-custom-route.md) — pattern d'endpoint d'action POST (`/archive`, `/restore`, `/publish`)
