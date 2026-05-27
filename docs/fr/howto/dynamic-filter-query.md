# How-to : Requête de filtre dynamique (clause WHERE dynamique)

> **Scénarios connexes** : DX Scénario 03, 18, 22, 25, 29, 30, 33, 37, 38, 41, 47, 48 — le howto manquant le plus fréquemment cité sur 50 scénarios DX.

De nombreux endpoints de liste acceptent des paramètres de requête optionnels qui se traduisent en conditions SQL. Le défi clé : quand un paramètre est absent (`null`), la condition doit être **totalement ignorée** — pas comparée contre `NULL` en SQL.

Ce guide montre le pattern canonique utilisé dans les howtos NENE2.

---

## Le pattern principal : tableau `$conditions` + `implode`

```php
public function search(
    ?string $status,
    ?int    $categoryId,
    ?string $keyword,
): array {
    $conditions = ['deleted_at IS NULL'];   // condition requise — toujours incluse
    $bindings   = [];

    if ($status !== null) {
        $conditions[] = 'status = ?';
        $bindings[]   = $status;
    }

    if ($categoryId !== null) {
        $conditions[] = 'category_id = ?';
        $bindings[]   = $categoryId;
    }

    if ($keyword !== null && $keyword !== '') {
        $conditions[] = '(title LIKE ? OR description LIKE ?)';
        $bindings[]   = "%{$keyword}%";
        $bindings[]   = "%{$keyword}%";
    }

    $where = 'WHERE ' . implode(' AND ', $conditions);

    return $this->db->fetchAll(
        "SELECT * FROM products {$where} ORDER BY created_at DESC",
        $bindings,
    );
}
```

**Pourquoi ça fonctionne** :
- `$conditions` a toujours au moins un élément (la condition requise), donc `implode(' AND ', $conditions)` ne produit jamais une chaîne vide.
- Chaque bloc optionnel ajoute à la fois le fragment SQL et sa valeur de binding — ils restent synchronisés.
- Si tous les paramètres optionnels sont `null`, la requête se réduit à `WHERE deleted_at IS NULL`.

---

## Anti-pattern : `WHERE 1=1`

Une alternative courante est `WHERE 1=1` comme condition initiale, puis toujours ajouter `AND` :

```php
// Fonctionne, mais moins clair :
$where = 'WHERE 1=1';
if ($status !== null) {
    $where    .= ' AND status = ?';
    $bindings[] = $status;
}
```

Cela fonctionne aussi. L'approche par tableau `$conditions` est préférée car elle sépare clairement les fragments SQL de leurs bindings et est plus facile à tester chaque condition isolément.

---

## Conditions de plage : filtres min/max

Plage de prix, plage de dates, et filtres similaires `>=` / `<=` :

```php
public function searchProperties(
    ?int    $priceMin,
    ?int    $priceMax,
    ?int    $bedroomsMin,
    ?string $dateFrom,
    ?string $dateTo,
): array {
    $conditions = ['status = ?'];
    $bindings   = ['available'];

    if ($priceMin !== null) {
        $conditions[] = 'price_yen >= ?';
        $bindings[]   = $priceMin;
    }

    if ($priceMax !== null) {
        $conditions[] = 'price_yen <= ?';
        $bindings[]   = $priceMax;
    }

    if ($bedroomsMin !== null) {
        $conditions[] = 'bedrooms >= ?';
        $bindings[]   = $bedroomsMin;
    }

    if ($dateFrom !== null) {
        $conditions[] = 'available_from >= ?';
        $bindings[]   = $dateFrom;
    }

    if ($dateTo !== null) {
        $conditions[] = 'available_from <= ?';
        $bindings[]   = $dateTo;
    }

    $where = 'WHERE ' . implode(' AND ', $conditions);

    return $this->db->fetchAll(
        "SELECT * FROM properties {$where} ORDER BY price_yen ASC",
        $bindings,
    );
}
```

Conditions `min` et `max` séparées plutôt que `BETWEEN` — cela permet au client de fournir une seule borne (ex. "prix jusqu'à 5M, pas de limite inférieure").

---

## Filtre enum / liste blanche

Quand une valeur de paramètre doit venir d'un ensemble fixe, valider avant d'ajouter à `$conditions` :

```php
private const VALID_STATUSES = ['draft', 'published', 'archived'];

public function listByStatus(?string $status): array
{
    $conditions = ['deleted_at IS NULL'];
    $bindings   = [];

    if ($status !== null) {
        if (!in_array($status, self::VALID_STATUSES, true)) {
            throw new \InvalidArgumentException("Invalid status: {$status}");
        }
        $conditions[] = 'status = ?';
        $bindings[]   = $status;
    }

    // ...
}
```

**Ne pas** interpoler `$status` directement dans la chaîne SQL même si cela semble sûr.
Toujours utiliser un paramètre de binding (`?`) et laisser PDO gérer le quotage.

---

## Clause IN : filtre multi-valeurs

Quand le client peut passer plusieurs valeurs (ex. `?category_ids[]=1&category_ids[]=3`) :

```php
public function filterByCategories(array $categoryIds): array
{
    if ($categoryIds === []) {
        return $this->findAll();   // pas de filtre — retourner tout
    }

    $placeholders = implode(',', array_fill(0, count($categoryIds), '?'));

    return $this->db->fetchAll(
        "SELECT * FROM products WHERE category_id IN ({$placeholders}) ORDER BY created_at DESC",
        $categoryIds,
    );
}
```

`array_fill(0, count($ids), '?')` génère le bon nombre de placeholders `?`.
Ne jamais utiliser `implode(',', $categoryIds)` pour construire une chaîne `IN (1,2,3)` — c'est de l'injection SQL.

Pour la sémantique AND (éléments qui correspondent à **tous** les tags donnés), voir [`multi-value-tag-filter.md`](multi-value-tag-filter.md).

---

## ORDER BY sûr : interpolation par liste blanche

Les noms de colonnes `ORDER BY` **ne peuvent pas** utiliser des paramètres de binding — ils doivent être interpolés.
Toujours valider contre une liste blanche :

```php
private const SORT_COLUMNS  = ['name', 'price_yen', 'created_at'];
private const SORT_DIRECTION = ['asc', 'desc'];

public function list(?string $sortBy, ?string $order): array
{
    $col = in_array($sortBy, self::SORT_COLUMNS, true)  ? $sortBy : 'created_at';
    $dir = in_array($order,  self::SORT_DIRECTION, true) ? $order  : 'desc';

    $conditions = ['deleted_at IS NULL'];

    $where = 'WHERE ' . implode(' AND ', $conditions);

    return $this->db->fetchAll(
        "SELECT * FROM products {$where} ORDER BY {$col} {$dir}",
        [],
    );
}
```

Voir [`dynamic-sort-order-injection.md`](dynamic-sort-order-injection.md) pour un traitement complet de la prévention d'injection ORDER BY.

---

## Combiner filtre et pagination

Un pattern courant — filtre dynamique + pagination par curseur ou offset :

```php
public function paginatedSearch(
    ?string $status,
    ?string $keyword,
    int     $limit,
    int     $offset,
): array {
    $conditions = ['deleted_at IS NULL'];
    $bindings   = [];

    if ($status !== null) {
        $conditions[] = 'status = ?';
        $bindings[]   = $status;
    }

    if ($keyword !== null && $keyword !== '') {
        $conditions[] = 'title LIKE ?';
        $bindings[]   = "%{$keyword}%";
    }

    $where = 'WHERE ' . implode(' AND ', $conditions);

    // La requête COUNT réutilise le même WHERE
    $total = (int) ($this->db->fetchOne(
        "SELECT COUNT(*) AS cnt FROM products {$where}",
        $bindings,
    )['cnt'] ?? 0);

    // La requête de données ajoute LIMIT/OFFSET — NE PAS les ajouter à $bindings avant COUNT
    $rows = $this->db->fetchAll(
        "SELECT * FROM products {$where} ORDER BY created_at DESC LIMIT ? OFFSET ?",
        [...$bindings, $limit, $offset],
    );

    return ['total' => $total, 'items' => $rows];
}
```

Construire `$bindings` pour les conditions de filtre en premier, puis les répandre dans la requête `COUNT` et la requête de données. Ajouter `$limit` et `$offset` uniquement à la requête de données.

---

## Parsing des paramètres de requête optionnels

Utiliser les helpers `QueryStringParser` pour obtenir des valeurs typées null-safe depuis la requête :

```php
use Nene2\Http\QueryStringParser;

$status     = QueryStringParser::string($request, 'status');     // ?string
$priceMin   = QueryStringParser::int($request, 'price_min');     // ?int
$priceMax   = QueryStringParser::int($request, 'price_max');     // ?int
$keyword    = QueryStringParser::string($request, 'q');          // ?string
$sortBy     = QueryStringParser::string($request, 'sort');       // ?string
$categoryId = QueryStringParser::int($request, 'category_id');   // ?int
```

Tous les helpers retournent `null` quand le paramètre est absent ou ne peut pas être parsé vers le type cible. Passer ces valeurs nullable directement à la méthode du repository — la méthode ignore les conditions où la valeur est `null`.

---

## Erreurs courantes

| Erreur | Problème | Correction |
|--------|---------|-----------|
| `WHERE status = ?` avec binding `null` | SQLite évalue `status = NULL` → toujours false (devrait être `IS NULL`) | Ignorer la condition quand la valeur est `null` ; utiliser `IS NULL` uniquement quand on veut explicitement des lignes NULL |
| `WHERE 1=1` sans condition requise | Fuite de toutes les lignes si tous les params optionnels sont absents et qu'il n'y a pas de filtre tenant/owner | Toujours inclure au moins une condition requise (tenant, owner, deleted_at) |
| Interpoler `$status` directement | Injection SQL | Toujours utiliser le paramètre de binding `?` |
| `IN (implode(',', $ids))` | Injection SQL | Utiliser `array_fill` + placeholders `?` |
| Ajouter `LIMIT`/`OFFSET` à `$bindings` avant `COUNT(*)` | COUNT obtient des résultats incorrects | Construire `$bindings` de filtre en premier ; répandre dans COUNT, puis ajouter LIMIT/OFFSET pour la requête de données |

---

## Guides associés

- [`multi-value-tag-filter.md`](multi-value-tag-filter.md) — sémantique AND / OR pour les filtres de tags N:M (`HAVING COUNT(DISTINCT)`)
- [`dynamic-sort-order-injection.md`](dynamic-sort-order-injection.md) — ORDER BY sûr avec liste blanche
- [`add-pagination.md`](add-pagination.md) — combinaison avec la pagination offset / curseur
- [`contact-management.md`](contact-management.md) — exemple complet avec filtre LIKE + EXISTS
