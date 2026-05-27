# How-to : Suppression douce, corbeille et purge permanente

> **Référence FT** : FT257 (`NENE2-FT/softdeletelog`) — Pattern de suppression douce / corbeille / purge permanente avec colonne `deleted_at`

Démontre un cycle de vie en trois étapes pour les enregistrements : actif → soft-supprimé (corbeille) → purgé définitivement.
Les listes actives excluent automatiquement les enregistrements supprimés. Un endpoint corbeille dédié liste uniquement les enregistrements supprimés.
La restauration retourne un enregistrement de la corbeille à l'état actif. La purge supprime physiquement l'enregistrement de la base de données (autorisée uniquement depuis la corbeille).

---

## Routes

| Méthode | Chemin | Description |
|---------|--------|-------------|
| `POST` | `/notes` | Créer une note |
| `GET` | `/notes` | Lister les notes actives (exclut les soft-supprimées) |
| `GET` | `/notes/trash` | Lister uniquement les notes dans la corbeille |
| `GET` | `/notes/{id}` | Obtenir une note active unique |
| `DELETE` | `/notes/{id}` | Soft-supprimer une note (déplacer vers la corbeille) |
| `POST` | `/notes/{id}/restore` | Restaurer de la corbeille vers l'état actif |
| `DELETE` | `/notes/{id}/purge` | Supprimer définitivement (uniquement depuis la corbeille) |

> **Ordre des routes** : `/notes/trash` doit être enregistré avant `/notes/{id}` pour que le segment littéral `trash` ne soit pas capturé comme paramètre de chemin.

---

## Schéma

```sql
CREATE TABLE IF NOT EXISTS notes (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    title      TEXT NOT NULL,
    body       TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    deleted_at TEXT NULL
);
```

`deleted_at TEXT NULL` est le marqueur de suppression douce. Quand `NULL`, l'enregistrement est actif ; quand défini à un timestamp ISO, l'enregistrement est dans la corbeille. Aucun booléen `is_deleted` séparé n'est nécessaire — le timestamp enregistre également _quand_ la suppression s'est produite, ce qui est utile pour les pistes d'audit et les jobs de purge basés sur TTL.

---

## Objet domaine

```php
final readonly class Note
{
    public function __construct(
        public int     $id,
        public string  $title,
        public string  $body,
        public string  $createdAt,
        public string  $updatedAt,
        public ?string $deletedAt,     // null = actif, non-null = dans la corbeille
    ) {}

    public function isDeleted(): bool
    {
        return $this->deletedAt !== null;
    }
}
```

`isDeleted()` encapsule la vérification null pour que les appelants n'aient pas besoin de connaître le détail d'implémentation.

---

## Repository : le flag `includeTrashed`

```php
public function findById(int $id, bool $includeTrashed = false): ?Note
{
    $sql = $includeTrashed
        ? 'SELECT * FROM notes WHERE id = ?'
        : 'SELECT * FROM notes WHERE id = ? AND deleted_at IS NULL';

    $rows = $this->executor->fetchAll($sql, [$id]);
    return $rows === [] ? null : $this->hydrate($rows[0]);
}
```

La valeur par défaut (`includeTrashed: false`) applique le filtre `deleted_at IS NULL` pour que les appelants obtiennent automatiquement le comportement sûr. Seuls la restauration et la purge ont besoin de voir les enregistrements dans la corbeille et passent `includeTrashed: true` explicitement.

**Pourquoi pas une méthode `findByIdIncludingTrashed()` séparée ?**

Un paramètre booléen nommé est auto-documenté au site d'appel :
- `findById($id)` — clairement actif uniquement
- `findById($id, includeTrashed: true)` — clairement aware de la corbeille

Une méthode séparée dupliquerait la logique d'hydratation ou nécessiterait un helper interne partagé.

---

## Listing : actif vs corbeille

```php
public function listActive(): array
{
    return $this->executor->fetchAll(
        'SELECT * FROM notes WHERE deleted_at IS NULL ORDER BY created_at DESC',
        [],
    );
}

public function listTrashed(): array
{
    return $this->executor->fetchAll(
        'SELECT * FROM notes WHERE deleted_at IS NOT NULL ORDER BY deleted_at DESC',
        [],
    );
}
```

Les notes actives sont triées par temps de création (les plus récentes en premier). Les notes dans la corbeille sont triées par temps de suppression (les plus récemment supprimées en premier), ce qui est naturel pour une UI "récemment supprimé".

---

## Suppression douce

```php
public function softDelete(int $id, string $now): ?Note
{
    $note = $this->findById($id);   // recherche actif uniquement
    if ($note === null) {
        return null;   // introuvable OU déjà dans la corbeille → 404
    }

    $this->executor->execute(
        'UPDATE notes SET deleted_at = ? WHERE id = ?',
        [$now, $id],
    );

    return new Note($note->id, $note->title, $note->body, $note->createdAt, $note->updatedAt, $now);
}
```

`findById($id)` sans `includeTrashed` signifie que l'appel de `DELETE /notes/{id}` sur une note déjà dans la corbeille retourne `null` → 404. Cela prévient la confusion de double-suppression : un client ne peut pas savoir d'un 404 si la note était active et manquante, ou déjà dans la corbeille.

---

## Restaurer

```php
public function restore(int $id): ?Note
{
    $note = $this->findById($id, includeTrashed: true);
    if ($note === null || !$note->isDeleted()) {
        return null;   // introuvable OU déjà actif → 404
    }

    $this->executor->execute(
        'UPDATE notes SET deleted_at = NULL WHERE id = ?',
        [$id],
    );

    return new Note($note->id, $note->title, $note->body, $note->createdAt, $note->updatedAt, null);
}
```

`includeTrashed: true` est requis ici — la note EST supprimée, donc le filtre par défaut la cacherait.
La garde `!$note->isDeleted()` rejette une note active : appeler restore sur une note active retourne `null` → 404. Cela rend restore idempotent dans le chemin "déjà restauré" : un client qui appelle restore deux fois obtient 200 au premier appel et 404 au second.

---

## Purge (suppression permanente)

```php
public function purge(int $id): bool
{
    $note = $this->findById($id, includeTrashed: true);
    if ($note === null || !$note->isDeleted()) {
        return false;   // introuvable OU encore actif → 404
    }

    $this->executor->execute('DELETE FROM notes WHERE id = ?', [$id]);
    return true;
}
```

`purge()` ne fonctionne que sur les enregistrements dans la corbeille (`isDeleted()` doit être true). Appeler `DELETE /notes/{id}/purge` sur une note active retourne `false` → 404. Cela protège contre la destruction accidentelle de données via le mauvais endpoint — un client doit explicitement soft-supprimer avant de pouvoir purger.

---

## Machine d'états

```
           POST /notes
               │
               ▼
           [actif]  ←──────── POST /notes/{id}/restore ────────┐
               │                                                  │
    DELETE /notes/{id}                                           │
               │                                                  │
               ▼                                                  │
           [corbeille]  ────────────────────────────────────────┘
               │
    DELETE /notes/{id}/purge
               │
               ▼
          [disparu — DELETE physique]
```

`actif → corbeille` est réversible. `corbeille → disparu` est irréversible. Il n'y a pas de chemin direct de `actif → disparu` : la purge nécessite une étape préalable de suppression douce.

---

## Contrôleur : ordre d'enregistrement des routes

```php
public function register(Router $router): void
{
    $router->post('/notes',              $this->create(...));
    $router->get('/notes',               $this->listActive(...));
    $router->get('/notes/trash',         $this->listTrashed(...));   // ← doit venir avant {id}
    $router->get('/notes/{id}',          $this->get(...));
    $router->delete('/notes/{id}',       $this->softDelete(...));
    $router->post('/notes/{id}/restore', $this->restore(...));
    $router->delete('/notes/{id}/purge', $this->purge(...));
}
```

`/notes/trash` doit être enregistré avant `/notes/{id}`. Si l'ordre était inversé, une requête `GET /notes/trash` correspondrait à `{id}` avec `id = "trash"`, échouerait au cast entier, et retournerait 404 ou 200 avec un corps vide au lieu de la liste de la corbeille.

---

## Sémantique HTTP

| Action | Méthode | Pourquoi |
|--------|---------|---------|
| Suppression douce | `DELETE` | Le client entend supprimer la ressource de sa vue |
| Restauration | `POST` | Pas idempotent (second appel retourne 404) ; `POST` est approprié |
| Purge | `DELETE` | Le client entend une suppression permanente |

`PATCH /notes/{id}` avec `{"deleted_at": null}` est une alternative pour la restauration, mais `POST /restore` est plus explicite et évite de fuiter le nom de colonne interne dans le contrat d'API.

---

## Comparaison de conception

| Approche | Filtre actif | Marqueur de suppression | Restauration | Purge |
|---|---|---|---|---|
| Timestamp `deleted_at` | `WHERE deleted_at IS NULL` | Timestamp + piste d'audit | `SET deleted_at = NULL` | `DELETE` physique |
| Booléen `is_deleted` | `WHERE is_deleted = 0` | Booléen uniquement | `SET is_deleted = 0` | `DELETE` physique |
| Table `deleted_notes` séparée | Pas de filtre nécessaire | Déplacer la ligne vers une autre table | Déplacer la ligne en retour | Supprimer de `deleted_notes` |

`deleted_at` est le pattern le plus courant : une colonne, changement de schéma minimal, et un timestamp d'audit intégré sans coût supplémentaire.

---

## Guides liés

- [`article-versioning-api.md`](article-versioning-api.md) — historique des versions pour le contenu (pattern de piste d'audit)
- [`mass-assignment-defence.md`](mass-assignment-defence.md) — whitelisting DTO explicite pour prévenir l'injection de champs
- [`transaction-scope-pattern.md`](transaction-scope-pattern.md) — opérations multi-écritures atomiques
