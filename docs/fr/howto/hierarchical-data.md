# How-to : Données hiérarchiques avec chemin matérialisé

> **Référence FT** : FT171 — Données hiérarchiques : chemin matérialisé `/1/3/7/`, INSERT puis UPDATE du chemin, sous-arbre via `WHERE path LIKE ? AND id != ?`, ancêtres depuis l'analyse du chemin, déplacement avec cascade vers les descendants, validations (profondeur max, circulaire, feuille seulement).

Ce guide montre comment construire une API de catégories hiérarchiques avec le pattern chemin matérialisé — stockage de la hiérarchie comme chaîne de chemin (ex: `/1/3/7/`) pour des requêtes de sous-arbre O(1).

## Schéma

```sql
CREATE TABLE categories (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT    NOT NULL,
    parent_id  INTEGER,
    path       TEXT    NOT NULL UNIQUE,
    depth      INTEGER NOT NULL DEFAULT 0,
    created_at TEXT    NOT NULL,
    FOREIGN KEY (parent_id) REFERENCES categories(id)
);
```

`path` stocke le chemin complet depuis la racine : `/1/` pour un nœud racine, `/1/3/` pour un enfant, `/1/3/7/` pour un petit-enfant. `UNIQUE` sur `path` empêche les doublons.

## Convention de chemin

```
Racine   :  /1/
Enfant   :  /1/3/
Petit-fils:  /1/3/7/
```

- Commence et se termine par `/`
- Chaque segment est l'`id` du nœud à ce niveau
- La profondeur = le nombre de segments - 1

## Création d'un nœud — INSERT puis UPDATE

SQLite (et la plupart des DBs) ne permettent pas d'utiliser l'ID auto-généré dans le même INSERT. Utiliser une valeur temporaire puis mettre à jour :

```php
public function create(string $name, ?int $parentId, string $now): int
{
    // 1. Insérer avec chemin temporaire
    $this->executor->execute(
        'INSERT INTO categories (name, parent_id, path, depth, created_at) VALUES (?, ?, ?, ?, ?)',
        [$name, $parentId, '__tmp__', 0, $now],
    );

    $id = (int) $this->executor->lastInsertId();

    // 2. Construire le vrai chemin
    if ($parentId === null) {
        $path  = '/' . $id . '/';
        $depth = 0;
    } else {
        $parent = $this->findById($parentId);
        $path   = $parent['path'] . $id . '/';
        $depth  = $parent['depth'] + 1;
    }

    // 3. Mettre à jour avec le vrai chemin
    $this->executor->execute(
        'UPDATE categories SET path = ?, depth = ? WHERE id = ?',
        [$path, $depth, $id],
    );

    return $id;
}
```

## Requête de sous-arbre — O(1) avec LIKE sur index

Tous les descendants ont un chemin qui **commence par** le chemin du nœud parent :

```php
public function getSubtree(int $id): array
{
    $node = $this->findById($id);
    if ($node === null) {
        return [];
    }

    return $this->executor->fetchAll(
        'SELECT * FROM categories WHERE path LIKE ? AND id != ? ORDER BY path',
        [$node['path'] . '%', $id],
    );
}
```

`path LIKE '/1/3/%'` retourne tous les descendants de `/1/3/`. Exclure le nœud lui-même avec `AND id != ?`.

## Ancêtres depuis l'analyse du chemin

Les ancêtres sont encodés dans le chemin — pas besoin de requête récursive :

```php
public function getAncestors(int $id): array
{
    $node = $this->findById($id);
    if ($node === null) {
        return [];
    }

    // "/1/3/7/" → ["1", "3", "7"] → [1, 3, 7] → exclure l'ID propre
    $segments  = array_filter(explode('/', $node['path']));
    $ancestorIds = array_map('intval', array_filter(
        $segments,
        fn(string $s) => (int) $s !== $id,
    ));

    if (empty($ancestorIds)) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($ancestorIds), '?'));
    return $this->executor->fetchAll(
        "SELECT * FROM categories WHERE id IN ($placeholders) ORDER BY depth",
        $ancestorIds,
    );
}
```

## Déplacement d'un nœud — Cascade vers les descendants

Déplacer un nœud nécessite de mettre à jour le chemin de lui-même et de tous ses descendants :

```php
public function move(int $id, ?int $newParentId): void
{
    $node = $this->findById($id);
    $oldPath = $node['path'];

    // Calculer le nouveau chemin
    if ($newParentId === null) {
        $newPath  = '/' . $id . '/';
        $newDepth = 0;
    } else {
        $parent   = $this->findById($newParentId);
        $newPath  = $parent['path'] . $id . '/';
        $newDepth = $parent['depth'] + 1;
    }

    $depthDelta = $newDepth - $node['depth'];

    // Mettre à jour le nœud lui-même
    $this->executor->execute(
        'UPDATE categories SET path = ?, depth = ?, parent_id = ? WHERE id = ?',
        [$newPath, $newDepth, $newParentId, $id],
    );

    // Mettre à jour tous les descendants (remplacer le préfixe de chemin)
    $descendants = $this->executor->fetchAll(
        'SELECT * FROM categories WHERE path LIKE ? AND id != ?',
        [$oldPath . '%', $id],
    );

    foreach ($descendants as $desc) {
        $updatedPath  = $newPath . substr($desc['path'], strlen($oldPath));
        $updatedDepth = $desc['depth'] + $depthDelta;
        $this->executor->execute(
            'UPDATE categories SET path = ?, depth = ? WHERE id = ?',
            [$updatedPath, $updatedDepth, $desc['id']],
        );
    }
}
```

## Validations

### Profondeur maximale

```php
private const int MAX_DEPTH = 10;

if ($parentDepth + 1 > self::MAX_DEPTH) {
    throw new CategoryDepthException("Max depth of " . self::MAX_DEPTH . " exceeded");
}
```

### Référence circulaire (déplacement)

Un nœud ne peut pas être déplacé dans son propre sous-arbre :

```php
$targetNode = $this->findById($newParentId);

// Vérifier si le nouvel ancêtre est le nœud lui-même ou un de ses descendants
if ($targetNode['path'] === $node['path'] ||
    str_starts_with($targetNode['path'], $node['path'])) {
    throw new CategoryCircularException("Cannot move a node into its own subtree");
}
```

### Suppression — Feuille seulement

```php
$children = $this->executor->fetchAll(
    'SELECT id FROM categories WHERE parent_id = ?',
    [$id],
);

if (!empty($children)) {
    throw new CategoryHasChildrenException("Cannot delete a category with children");
}
```

## Endpoints

| Méthode    | Chemin                          | Description                              |
|------------|---------------------------------|------------------------------------------|
| `POST`     | `/categories`                   | Créer une catégorie                      |
| `GET`      | `/categories`                   | Lister les catégories racines            |
| `GET`      | `/categories/{id}`              | Obtenir une catégorie avec ancêtres      |
| `DELETE`   | `/categories/{id}`              | Supprimer une catégorie (feuille seulement) |
| `GET`      | `/categories/{id}/subtree`      | Obtenir le sous-arbre                    |
| `GET`      | `/categories/{id}/ancestors`    | Obtenir les ancêtres                     |
| `POST`     | `/categories/{id}/move`         | Déplacer un nœud                        |

## Forme de réponse

`GET /categories/{id}` inclut le tableau `ancestors` :

```json
{
    "id": 7,
    "name": "Électronique mobile",
    "parent_id": 3,
    "path": "/1/3/7/",
    "depth": 2,
    "ancestors": [
        { "id": 1, "name": "Racine", "depth": 0 },
        { "id": 3, "name": "Électronique", "depth": 1 }
    ]
}
```

## Exceptions de domaine

```php
class CategoryNotFoundException extends DomainException {}
class CategoryDepthException extends DomainException {}
class CategoryCircularException extends DomainException {}
class CategoryHasChildrenException extends DomainException {}
```

Mapper vers les codes de statut HTTP appropriés dans le gestionnaire d'erreurs :
- `CategoryNotFoundException` → 404
- `CategoryDepthException` → 422
- `CategoryCircularException` → 422
- `CategoryHasChildrenException` → 422

## Compromis

| Approche | Requête de sous-arbre | Déplacement | Profondeur |
|----------|----------------------|-------------|------------|
| **Chemin matérialisé** | O(1) LIKE sur index | O(n) mise à jour des descendants | Limitée par la longueur de chaîne |
| Table de fermeture | O(1) JOIN | O(n²) mise à jour | Illimitée |
| Ensembles imbriqués | O(1) `left BETWEEN` | O(n) renumération | Illimitée |

Le chemin matérialisé est le meilleur compromis pour les hiérarchies à faibles mouvements avec des lectures fréquentes de sous-arbres.

## À ne pas faire

| Anti-pattern | Risque |
|---|---|
| Utiliser `parent_id` seul (adjacency list) | Les requêtes de sous-arbre nécessitent une récursion N niveaux profonds |
| `path` sans index UNIQUE | Chemins dupliqués ; sous-arbres corrompus |
| Mettre à jour `path` sans cascade vers les descendants | Les descendants ont des chemins invalides après déplacement |
| Pas de garde de profondeur max | Les hiérarchies profondes causent des chemins extrêmement longs |
| Permettre la suppression des nœuds non-feuilles | Les enfants orphelins restent avec des chemins corrompus |
| Pas de vérification de circularité dans le déplacement | Déplacer `/1/3/` dans `/1/3/7/` crée une boucle infinie |
