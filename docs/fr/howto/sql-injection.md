# Défense contre l'injection SQL

Les méthodes de base de données de NENE2 (`execute`, `insert`, `fetchOne`, `fetchAll`) utilisent en interne des requêtes préparées PDO. Toute valeur passée dans le tableau `$parameters` est liée comme un paramètre PDO — jamais interpolée dans la chaîne SQL.

## Sûr par défaut : paramètres de valeur

```php
// Toutes les valeurs passent par la liaison PDO — immunisé aux injections quelle que soit leur contenu
$product = $this->db->fetchOne(
    'SELECT * FROM products WHERE id = ?',
    [$userId],
);

// Recherche LIKE — wildcard dans le littéral SQL, valeur liée séparément
$rows = $this->db->fetchAll(
    "SELECT * FROM products WHERE name LIKE '%' || ? || '%'",
    [$searchQuery],
);
```

Les payloads classiques (`' OR '1'='1`, `'; DROP TABLE products; --`, `UNION SELECT ...`) deviennent des chaînes de recherche littérales car PDO ne les interpole jamais dans le SQL.

## Le piège ORDER BY — liste blanche requise

**PDO ne peut pas paramétrer les noms de colonnes ni les éléments structuraux SQL.** `ORDER BY ?` ne fonctionne pas — il lie une valeur de chaîne littérale, pas une référence de colonne.

Si un développeur met directement l'entrée utilisateur dans `ORDER BY`, cela devient un vecteur d'injection :

```php
// NON SÛR — ne jamais faire ceci
$sort = QueryStringParser::string($request, 'sort') ?? 'id';
$rows = $this->db->fetchAll("SELECT * FROM products ORDER BY {$sort} ASC");
// ?sort=id;+DROP+TABLE+products;+-- exécute le DROP
```

**Toujours valider contre une liste blanche explicite avant d'interpoler les noms de colonnes :**

```php
private const array ALLOWED_SORT_FIELDS = ['id', 'name', 'price', 'created_at'];

public function list(string $sortField, string $sortDir): array
{
    if (!in_array($sortField, self::ALLOWED_SORT_FIELDS, true)) {
        throw new InvalidSortFieldException("Invalid sort field: {$sortField}");
    }

    // Uniquement ASC ou DESC — normaliser, jamais interpoler l'entrée utilisateur brute
    $dir  = strtolower($sortDir) === 'desc' ? 'DESC' : 'ASC';
    $rows = $this->db->fetchAll(
        "SELECT * FROM products ORDER BY {$sortField} {$dir}",
    );

    return $rows;
}
```

Le même principe s'applique à tout élément structurel SQL : noms de tables, noms de colonnes dans `GROUP BY`, `HAVING`, `INSERT INTO ... (col1, col2)` — aucun de ces éléments ne peut être lié comme paramètre PDO. Valider avec une liste blanche avant d'interpoler.

## Clause IN avec longueur variable

PDO ne supporte pas la liaison d'une liste de longueur variable directement. Construire la liste de placeholders explicitement :

```php
$ids          = [1, 2, 3];
$placeholders = implode(', ', array_fill(0, count($ids), '?'));
$rows         = $this->db->fetchAll(
    "SELECT * FROM products WHERE id IN ({$placeholders})",
    $ids,
);
```

## Résumé

| Type d'entrée | Méthode sûre |
|---|---|
| Valeur filtre (`WHERE col = ?`) | Placeholder `?` dans `$parameters` |
| Valeur LIKE | `'%' \|\| ? \|\| '%'` — valeur dans `$parameters` |
| Colonne ORDER BY | Liste blanche `in_array` + interpoler seulement après validation |
| Direction ORDER | Normaliser en littéral `'ASC'` ou `'DESC'` |
| Liste IN | Construire les placeholders `?` depuis `count()`, répandre le tableau comme params |
| Nom de table/colonne | Liste blanche uniquement — jamais accepter depuis l'entrée utilisateur |
