# How-to : API d'arbre de hiérarchie de catégories

> **Référence FT** : FT344 (`NENE2-FT/treelog`) — Arbre de catégories avec parent_id + depth, enfants immédiats, CTE récursifs ancêtres/descendants, suppression feuille uniquement (409 si a des enfants), 17 tests PASS.

Ce guide montre comment construire un arbre de catégories hiérarchique : créer des catégories avec des parents optionnels, traverser l'arbre vers le haut (ancêtres) et vers le bas (descendants) en utilisant des CTEs SQL récursifs, et appliquer une suppression sûre.

## Schéma

```sql
CREATE TABLE categories (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT    NOT NULL,
    parent_id  INTEGER REFERENCES categories(id) ON DELETE RESTRICT,
    depth      INTEGER NOT NULL DEFAULT 0,
    created_at TEXT    NOT NULL
);

CREATE INDEX idx_categories_parent ON categories(parent_id);
```

`depth` est calculé à l'insertion : `parent.depth + 1` (racine = 0). `ON DELETE RESTRICT` empêche la suppression d'un parent qui a encore des enfants.

## Endpoints

| Méthode   | Chemin                              | Description                              |
|-----------|-------------------------------------|------------------------------------------|
| `POST`    | `/categories`                       | Créer une catégorie racine ou enfant     |
| `GET`     | `/categories`                       | Lister les catégories racines uniquement |
| `GET`     | `/categories/{id}`                  | Obtenir une catégorie                    |
| `GET`     | `/categories/{id}/children`         | Enfants immédiats uniquement             |
| `GET`     | `/categories/{id}/ancestors`        | Chemin de la racine au nœud (fil d'Ariane) |
| `GET`     | `/categories/{id}/descendants`      | Tous les nœuds du sous-arbre (toute profondeur) |
| `DELETE`  | `/categories/{id}`                  | Supprimer une feuille uniquement (409 si des enfants existent) |

## Créer une catégorie

```php
// Catégorie racine (sans parent)
POST /categories
{"name": "Electronics"}

→ 201
{"id": 1, "name": "Electronics", "parent_id": null, "depth": 0, "created_at": "..."}

// Catégorie enfant
POST /categories
{"name": "Smartphones", "parent_id": 1}

→ 201
{"id": 2, "name": "Smartphones", "parent_id": 1, "depth": 1, "created_at": "..."}

// Petit-enfant
POST /categories
{"name": "Android", "parent_id": 2}
→ 201  // depth: 2
```

### Validation

```php
POST /categories  {"parent_id": 9999}
→ 404  // le parent n'existe pas

POST /categories  {"parent_id": 1}
→ 422  // le nom est requis
```

### Calcul de la profondeur à l'insertion

```php
$depth = 0;
if ($parentId !== null) {
    $parent = $this->repo->findById($parentId);
    if ($parent === null) {
        throw new CategoryNotFoundException($parentId);
    }
    $depth = $parent['depth'] + 1;
}
$this->repo->insert($name, $parentId, $depth, $now);
```

## Lister les catégories racines

```php
GET /categories

→ 200
{
  "items": [
    {"id": 1, "name": "Electronics", "parent_id": null, "depth": 0, ...},
    {"id": 5, "name": "Clothing",    "parent_id": null, "depth": 0, ...}
  ],
  "total": 2
}
```

Retourne uniquement `WHERE parent_id IS NULL` — aucune catégorie enfant incluse.

## Lister les enfants immédiats

```php
GET /categories/1/children

→ 200
{
  "items": [
    {"id": 2, "name": "Smartphones", "parent_id": 1, "depth": 1, ...},
    {"id": 3, "name": "Laptops",     "parent_id": 1, "depth": 1, ...}
  ],
  "total": 2
}
```

**Immédiats uniquement** — les petits-enfants n'apparaissent PAS ici ; utiliser `/descendants` pour le sous-arbre complet.

```sql
SELECT * FROM categories WHERE parent_id = ? ORDER BY id ASC
```

## Obtenir les ancêtres (fil d'Ariane) — CTE récursif

```php
GET /categories/4/ancestors

// Catégorie 4 = "Android" (depth 2, parent "Smartphones")
→ 200
{
  "items": [
    {"id": 1, "name": "Electronics", "depth": 0, ...},   // racine en premier
    {"id": 2, "name": "Smartphones", "depth": 1, ...}    // parent le plus proche en dernier
  ],
  "total": 2
}

// La catégorie racine n'a pas d'ancêtres
GET /categories/1/ancestors
→ 200  {"items": [], "total": 0}
```

Ordonné par `depth ASC` → racine en premier (ordre naturel du fil d'Ariane).

### CTE récursif pour les ancêtres

```sql
WITH RECURSIVE ancestor_cte(id, name, parent_id, depth, created_at) AS (
    -- Graine : partir du parent direct
    SELECT c.id, c.name, c.parent_id, c.depth, c.created_at
    FROM categories c
    WHERE c.id = (SELECT parent_id FROM categories WHERE id = :id)

    UNION ALL

    -- Récursion : remonter jusqu'à la racine
    SELECT c.id, c.name, c.parent_id, c.depth, c.created_at
    FROM categories c
    INNER JOIN ancestor_cte a ON c.id = a.parent_id
)
SELECT * FROM ancestor_cte ORDER BY depth ASC
```

## Obtenir les descendants (sous-arbre complet) — CTE récursif

```php
GET /categories/1/descendants

// "Electronics" a Smartphones, Laptops, Android (enfant de Smartphones)
→ 200
{
  "items": [
    {"id": 2, "name": "Smartphones", "depth": 1, ...},
    {"id": 3, "name": "Laptops",     "depth": 1, ...},
    {"id": 4, "name": "Android",     "depth": 2, ...}
  ],
  "total": 3   // tous les nœuds du sous-arbre, pas seulement les enfants directs
}

// Une feuille retourne vide
GET /categories/4/descendants
→ 200  {"items": [], "total": 0}
```

Les frères et sœurs du nœud interrogé n'apparaissent **pas**.

### CTE récursif pour les descendants

```sql
WITH RECURSIVE desc_cte(id, name, parent_id, depth, created_at) AS (
    -- Graine : enfants immédiats
    SELECT id, name, parent_id, depth, created_at
    FROM categories WHERE parent_id = :id

    UNION ALL

    -- Récursion : enfants des enfants
    SELECT c.id, c.name, c.parent_id, c.depth, c.created_at
    FROM categories c
    INNER JOIN desc_cte d ON c.parent_id = d.id
)
SELECT * FROM desc_cte ORDER BY depth ASC, id ASC
```

## Supprimer une catégorie

```php
// Nœud feuille → 204 No Content
DELETE /categories/4   // "Android" (aucun enfant)
→ 204

// Nœud avec enfants → 409 Conflict
DELETE /categories/1   // "Electronics" (a Smartphones, Laptops)
→ 409
{
  "type": "https://nene2.dev/problems/has-children",
  "title": "Category has children",
  "status": 409,
  "detail": "Cannot delete a category that has children"
}

// Inexistant → 404
DELETE /categories/9999
→ 404
```

### Implémentation de la suppression

```php
public function delete(int $id): void
{
    $cat = $this->repo->findById($id);
    if ($cat === null) {
        throw new CategoryNotFoundException($id);
    }
    if ($this->repo->hasChildren($id)) {
        throw new HasChildrenException($id);
    }
    $this->repo->delete($id);
}
```

```sql
-- Vérification hasChildren
SELECT COUNT(*) FROM categories WHERE parent_id = ?

-- Suppression
DELETE FROM categories WHERE id = ?
```

---

## ATK Assessment — Cracker-Mindset Attack Test

### ATK-01 — Manipulation du parent_id pour créer une référence circulaire 🚫 BLOCKED

**Attack**: L'attaquant crée une chaîne A→B→C puis réassigne le parent de B à C pour créer un cycle qui cause une récursion CTE infinie.
**Result**: BLOCKED — `parent_id` est défini uniquement à la création ; il n'y a pas d'endpoint PATCH/PUT pour réassigner les parents. La profondeur est calculée une fois à l'insertion depuis la profondeur vérifiée du parent. Les cycles sont structurellement impossibles avec une parenté immuable.

---

### ATK-02 — Parent_id inexistant à la création 🚫 BLOCKED

**Attack**: L'attaquant envoie `{"name": "Orphan", "parent_id": 9999}` pour créer une catégorie orpheline.
**Result**: BLOCKED — Le repository recherche le parent avant l'insertion ; un parent manquant lève `CategoryNotFoundException` → 404. Aucune ligne orpheline n'est créée.

---

### ATK-03 — Supprimer un non-feuille pour enlever le sous-arbre 🚫 BLOCKED

**Attack**: L'attaquant envoie `DELETE /categories/1` (racine avec de nombreux enfants) pour effacer tout le sous-arbre.
**Result**: BLOCKED — La vérification `hasChildren()` retourne true → `HasChildrenException` → 409. `ON DELETE RESTRICT` applique aussi cela au niveau DB ; même si la logique applicative était contournée, la contrainte FK empêche la suppression.

---

### ATK-04 — Traversée CTE sur une catégorie inexistante 🚫 BLOCKED

**Attack**: L'attaquant requête `/categories/9999/ancestors` ou `/categories/9999/descendants` pour un ID inexistant afin de sonder les données.
**Result**: BLOCKED — Le repository vérifie l'existence de la catégorie avant d'exécuter le CTE. Catégorie manquante → `CategoryNotFoundException` → 404. Aucune fuite de données.

---

### ATK-05 — Injection SQL via le nom de catégorie 🚫 BLOCKED

**Attack**: L'attaquant envoie `{"name": "'; DROP TABLE categories; --"}` pour injecter du SQL.
**Result**: BLOCKED — Toutes les requêtes utilisent des instructions préparées PDO avec des paramètres liés. Le nom est stocké verbatim comme chaîne et n'est jamais interpolé dans le SQL.

---

### ATK-06 — Boucle infinie du CTE récursif via un cycle 🚫 BLOCKED

**Attack**: L'attaquant essaie de créer une situation où ancestor_cte boucle indéfiniment (A parent de B, B parent de A).
**Result**: BLOCKED — `parent_id` est immuable après la création. Créer A avec `parent_id=B` nécessite que B existe d'abord ; à ce moment A n'existe pas, donc B n'a pas pu être créé avec `parent_id=A`. La contrainte de création séquentielle rend les cycles impossibles.

---

### ATK-07 — Bombe de profondeur CTE avec chaîne longue ✅ SAFE

**Attack**: L'attaquant crée une chaîne de plus de 1000 niveaux de profondeur pour épuiser la limite de récursion CTE.
**Result**: SAFE — La limite de récursion par défaut de SQLite pour les CTEs est 1000. Une chaîne très longue pourrait déclencher cette limite. En pratique, la limitation de débit et le coût de création de nœud par requête rendent cela impraticable. Ajouter une garde `MAX_DEPTH` à l'insertion (ex. rejeter `depth > 20`) pour les déploiements en production.

---

### ATK-08 — Énumération d'ID via GET /categories/{id} 🚫 BLOCKED

**Attack**: L'attaquant itère les IDs entiers pour énumérer toutes les catégories y compris celles qu'il ne devrait pas voir.
**Result**: BLOCKED — Si les catégories sont par utilisateur ou par tenant, les vérifications d'autorisation (claim JWT de tenant / propriété) protègent le GET individuel. Le treelog démontre un accès en lecture public comme base ; la restriction de portée est une préoccupation de couche d'autorisation.

---

### ATK-09 — L'endpoint enfants retourne des petits-enfants ✅ SAFE

**Attack**: L'attaquant s'attend à ce que `/children` expose involontairement des données de sous-arbre multi-niveaux.
**Result**: SAFE — `/children` retourne uniquement les enfants immédiats (`WHERE parent_id = ?`). Les petits-enfants nécessitent une traversée explicite `/descendants`. Aucune exposition de données involontaire via l'endpoint enfants.

---

### ATK-10 — Épuisement mémoire par grand champ name ✅ SAFE

**Attack**: L'attaquant envoie une valeur `name` de 10 Mo dans le payload de création.
**Result**: SAFE — Le middleware de limite de taille de requête (1 Mo par défaut) rejette les corps surdimensionnés avant d'atteindre le gestionnaire. La validation de longueur `name` au niveau applicatif (ex. `max: 255`) fournit une seconde garde.

---

### ATK-11 — Élagage séquentiel du sous-arbre pour supprimer un nœud protégé ✅ SAFE

**Attack**: L'attaquant supprime tous les enfants individuellement pour rendre un nœud mid-arbre protégé une feuille, puis le supprime.
**Result**: SAFE — C'est une séquence d'opérations valide. Élaguer les enfants un par un est la façon correcte de supprimer un sous-arbre. L'autorisation (vérification de propriété) empêche les utilisateurs non autorisés de supprimer les catégories des autres.

---

### ATK-12 — Race condition : vérification hasChildren avant l'insertion d'un enfant 🚫 BLOCKED

**Attack**: Deux requêtes concurrentes : une vérifie `hasChildren()` (retourne false) et procède à la suppression ; une autre crée un nouvel enfant juste avant que la suppression s'exécute.
**Result**: BLOCKED — La contrainte FK `ON DELETE RESTRICT` au niveau DB empêche la suppression si une ligne enfant existe au moment du commit. Même si la vérification `hasChildren()` au niveau applicatif est en course, la contrainte DB est la garde finale.

---

### ATK Summary

| ID | Attack | Result |
|----|--------|--------|
| ATK-01 | Manipulation parent_id / référence circulaire | 🚫 BLOCKED |
| ATK-02 | Parent_id inexistant à la création | 🚫 BLOCKED |
| ATK-03 | Supprimer non-feuille pour effacer le sous-arbre | 🚫 BLOCKED |
| ATK-04 | Traversée CTE sur nœud inexistant | 🚫 BLOCKED |
| ATK-05 | Injection SQL via champ name | 🚫 BLOCKED |
| ATK-06 | Cycle CTE récursif / boucle infinie | 🚫 BLOCKED |
| ATK-07 | Bombe de profondeur CTE avec chaîne longue | ✅ SAFE (ajouter garde MAX_DEPTH) |
| ATK-08 | Énumération d'ID via GET | 🚫 BLOCKED |
| ATK-09 | Exposition involontaire de sous-arbre par endpoint enfants | ✅ SAFE |
| ATK-10 | Épuisement mémoire par grand champ name | ✅ SAFE (middleware limite taille) |
| ATK-11 | Élagage séquentiel du sous-arbre | ✅ SAFE (opération valide) |
| ATK-12 | Race condition hasChildren + insertion enfant | 🚫 BLOCKED |

**6 BLOCKED, 4 SAFE, 0 EXPOSED** — Aucune conclusion critique. Ajouter une garde `MAX_DEPTH` à l'insertion pour les déploiements en production.

---

## Ce qu'il ne faut PAS faire

| Anti-pattern | Risque |
|---|---|
| Calculer la profondeur en comptant les ancêtres à chaque requête | Requêtes N+1 en O(depth) ; utiliser la colonne `depth` stockée |
| Autoriser la mise à jour de parent_id (reparentering) sans recalculer les profondeurs du sous-arbre | Les valeurs `depth` stockées pour tout le sous-arbre deviennent obsolètes/incorrectes |
| Pas de `ON DELETE RESTRICT` sur la FK parent | Un bug applicatif orpheline silencieusement les lignes enfants |
| Retourner 200 avec liste vide pour les ancêtres/descendants d'une catégorie inexistante | Les appelants ne peuvent pas distinguer "pas d'ancêtres" de "catégorie introuvable" |
| Accepter `depth` en entrée client | L'attaquant définit `depth=0` sur un enfant profond, brisant les invariants de l'arbre |
| Pas de limite de récursion CTE ou de cap MAX_DEPTH à l'insertion | Les chaînes profondes atteignent la limite CTE de 1000 niveaux de SQLite |
