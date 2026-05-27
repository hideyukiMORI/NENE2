# How-to : Défense contre l'injection SQL

> **Référence FT** : FT264 (`NENE2-FT/injectionlog`) — Défense contre l'injection SQL : requêtes paramétrées, injection LIKE, liste blanche ORDER BY
> **ATK** : FT264 — test d'attaque cracker-mindset (ATK-01 à ATK-12)

Démontre les trois principaux vecteurs d'injection SQL dans une API PHP — injection de valeur, injection de wildcard LIKE, et injection de colonne ORDER BY — et la défense correcte pour chacun. Inclut une évaluation complète d'attaque cracker-mindset.

---

## Routes

| Méthode | Chemin | Description |
|---------|--------|-------------|
| `GET` | `/products` | Lister/chercher des produits (filtrable, trié) |
| `POST` | `/products` | Créer un produit |
| `GET` | `/products/{id}` | Obtenir un produit |
| `DELETE` | `/products/{id}` | Supprimer un produit |

---

## Schéma

```sql
CREATE TABLE IF NOT EXISTS products (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    name        TEXT    NOT NULL,
    category    TEXT    NOT NULL,
    price       REAL    NOT NULL DEFAULT 0.0,
    description TEXT    NOT NULL DEFAULT ''
);
```

---

## Les trois surfaces d'injection SQL

### 1. Injection de valeur : requêtes paramétrées

```php
// ❌ Interpolation de chaîne — injectable
$rows = $db->fetchAll("SELECT * FROM products WHERE id = {$id}");

// ✅ Paramétré — le driver échappe toutes les valeurs
$rows = $db->fetchAll('SELECT * FROM products WHERE id = ?', [$id]);
```

Le placeholder `?` de PDO lie la valeur comme un paramètre typé. La valeur n'est jamais interpolée
dans la chaîne SQL. Un attaquant qui envoie `id = "1; DROP TABLE products; --"` voit
toute son entrée stockée comme une liaison de chaîne littérale — le SQL n'est pas modifié.

### 2. Injection de wildcard LIKE : wildcards paramétrés

```php
// ❌ LIKE interpolé — injectable ET échappé par wildcard
$rows = $db->fetchAll("SELECT * FROM products WHERE name LIKE '%{$q}%'");

// ✅ Wildcard paramétré — la valeur ? est liée après la concaténation ||
$rows = $db->fetchAll(
    "SELECT * FROM products WHERE name LIKE '%' || ? || '%' OR description LIKE '%' || ? || '%'",
    [$q, $q],
);
```

`'%' || ? || '%'` est la concaténation de chaîne SQL standard (SQLite, PostgreSQL). La valeur `?`
est liée comme paramètre — les wildcards `%` sont des littéraux dans la chaîne SQL, pas depuis l'entrée utilisateur.

**Échappement des métacaractères LIKE** : `%` et `_` dans l'entrée utilisateur `$q` NE sont PAS échappés dans cette
implémentation. Une recherche de `%` correspondrait à tout. En production, échapper les métacaractères LIKE :

```php
$escaped = str_replace(['%', '_', '\\'], ['\\%', '\\_', '\\\\'], $q);
$rows = $db->fetchAll("... WHERE name LIKE '%' || ? || '%' ESCAPE '\\'", [$escaped, $escaped]);
```

### 3. Injection ORDER BY : liste blanche de colonnes

```php
private const array ALLOWED_SORT_FIELDS = ['id', 'name', 'category', 'price'];

public function search(string $query = '', string $sortField = 'id', string $sortDir = 'asc'): array
{
    if (!in_array($sortField, self::ALLOWED_SORT_FIELDS, true)) {
        throw new InvalidSortFieldException("Invalid sort field: {$sortField}");
    }

    $sortDir    = strtolower($sortDir) === 'desc' ? 'DESC' : 'ASC';
    $sortClause = $sortField . ' ' . $sortDir;   // sûr : colonne en liste blanche + direction sur liste blanche

    $rows = $db->fetchAll(
        "SELECT * FROM products ORDER BY {$sortClause}",
    );
}
```

`ORDER BY` ne peut pas utiliser des placeholders paramétrés — le nom de colonne doit être interpolé.
La défense correcte est une liste blanche explicite : seules les valeurs dans `ALLOWED_SORT_FIELDS` peuvent apparaître
dans la chaîne SQL. Toute autre valeur lève une exception (400 dans le contrôleur).

`sortDir` est mappé exactement à `'ASC'` ou `'DESC'` — l'entrée utilisateur n'est jamais directement interpolée.

---

## ATK — Test d'attaque cracker-mindset (FT264)

### ATK-01 — Injection SELECT classique via paramètre GET

**Attaque** : Injecter du SQL via la requête de recherche `?q=' OR '1'='1`.

```
GET /products?q=' OR '1'='1
```

**Observé** : `$q` est lié comme paramètre `?` dans `LIKE '%' || ? || '%'`. La chaîne entière
`' OR '1'='1` est traitée comme une valeur texte littérale à faire correspondre. Aucune ligne supplémentaire n'est retournée.

**Verdict** : **BLOCKED** — LIKE paramétré prévient l'injection de valeur.

---

### ATK-02 — Injection DROP TABLE via recherche

**Attaque** : Injecter une instruction destructrice.

```
GET /products?q='; DROP TABLE products; --
```

**Observé** : Le payload est lié comme un paramètre LIKE. `'; DROP TABLE products; --` est recherché
comme texte littéral. La table n'est pas supprimée.

**Verdict** : **BLOCKED** — les requêtes paramétrées ne peuvent pas exécuter des instructions injectées.

---

### ATK-03 — Injection de colonne ORDER BY : colonne arbitraire

**Attaque** : Injecter une colonne de tri non reconnue.

```
GET /products?sort=password
```

**Observé** : `in_array('password', self::ALLOWED_SORT_FIELDS, true)` retourne `false`.
`InvalidSortFieldException` est levée. Le contrôleur la capture et retourne 400.

**Verdict** : **BLOCKED** — la liste blanche de colonnes rejette les noms de colonnes inconnus.

---

### ATK-04 — Injection ORDER BY : injection de sous-requête

**Attaque** : Injecter une sous-requête comme colonne de tri.

```
GET /products?sort=(SELECT%20name%20FROM%20users%20LIMIT%201)
```

**Observé** : La valeur décodée `(SELECT name FROM users LIMIT 1)` n'est pas dans `ALLOWED_SORT_FIELDS`.
`InvalidSortFieldException` levée. 400 retourné.

**Verdict** : **BLOCKED** — la liste blanche rejette toute valeur non dans la liste de colonnes connues, y compris les sous-requêtes.

---

### ATK-05 — Injection ORDER BY : manipulation de direction

**Attaque** : Injecter du SQL via le paramètre de direction de tri.

```
GET /products?order=DESC;%20DROP%20TABLE%20products;--
```

**Observé** : `strtolower($sortDir) === 'desc'` est `false` pour la valeur injectée. La direction
passe en `'ASC'`. Le SQL injecté n'est jamais interpolé. 200 retourné avec les produits
ordonnés ASC.

**Verdict** : **BLOCKED** — la direction est mappée exactement à `'ASC'` ou `'DESC'`, jamais interpolée.

---

### ATK-06 — Injection UNION via requête de recherche

**Attaque** : Injecter un `UNION SELECT` pour exfiltrer des données.

```
GET /products?q=' UNION SELECT id,name,email,password,'' FROM users --
```

**Observé** : La chaîne d'injection complète est liée comme valeur de paramètre LIKE. `UNION SELECT`
est recherché comme texte littéral dans les colonnes `name` et `description`. Aucune donnée utilisateur n'est retournée.

**Verdict** : **BLOCKED** — la requête paramétrée prévient l'injection UNION.

---

### ATK-07 — Injection d'ID via paramètre de chemin

**Attaque** : Injecter du SQL via le paramètre de chemin.

```
GET /products/1;%20DROP%20TABLE%20products;
```

**Observé** : Le paramètre de chemin `{id}` est casté en `int` par `(int) $params['id']`. Le SQL
devient `WHERE id = 1` — le suffixe d'injection est tronqué par le cast. La table n'est pas supprimée.

**Verdict** : **BLOCKED** — le cast `(int)` tronque au premier caractère non numérique.

---

### ATK-08 — Injection aveugle basée sur booléen via recherche

**Attaque** : Faire fuiter des données via des conditions booléennes.

```
GET /products?q=' AND '1'='1
GET /products?q=' AND '1'='2
```

**Observé** : Les deux chaînes sont liées comme paramètres LIKE. Les deux retournent des produits dont le nom ou
la description contient le texte littéral `' AND '1'='1`. Ni l'une ni l'autre ne modifie la logique SQL WHERE.
Les deux retournent le même ensemble de résultats (vide).

**Verdict** : **BLOCKED** — la liaison paramétrée prévient l'injection booléenne.

---

### ATK-09 — Injection de second ordre : payload stocké récupéré plus tard

**Attaque** : Créer un produit avec un nom contenant du SQL, puis chercher tous les produits.

```json
POST /products {"name": "'; DROP TABLE products; --", "category": "test", "price": 1}
GET /products
```

**Observé** : L'`INSERT` utilise `?` paramétré — le payload d'injection est stocké comme texte
littéral. Les requêtes `SELECT *` et `LIKE` utilisent aussi des requêtes paramétrées. Le payload est retourné
comme valeur de chaîne, jamais exécuté comme SQL.

**Verdict** : **BLOCKED** — tous les chemins de lecture et d'écriture utilisent des requêtes paramétrées.

---

### ATK-10 — Inondation de métacaractère LIKE : recherche `%`

**Attaque** : Envoyer `?q=%` pour correspondre à tous les produits, contournant un défaut de recherche vide prévu.

```
GET /products?q=%25   (URL-décodé : %)
```

**Observé** : `$q = '%'` est lié comme paramètre LIKE. `LIKE '%' || '%' || '%'` = `LIKE '%%%'`
qui correspond à chaque ligne. Tous les produits sont retournés.

**Verdict** : **EXPOSED** — `%` et `_` dans l'entrée utilisateur ne sont pas échappés. Une recherche de `%` correspond à
tout ; une recherche de `_` correspond à n'importe quel caractère. Échapper les métacaractères LIKE ou documenter
le comportement comme intentionnel.

---

### ATK-11 — Injection d'octet nul

**Attaque** : Intégrer un octet nul dans la requête de recherche.

```
GET /products?q=widget%00extra
```

**Observé** : La liaison `?` de PHP passe la chaîne brute incluant l'octet nul à la requête paramétrée
de SQLite. SQLite traite l'octet nul comme une partie de la chaîne. `LIKE '%widget\0extra%'`
ne correspond pas aux noms de produits normaux. Aucune injection ne se produit.

**Verdict** : **BLOCKED** — les requêtes paramétrées gèrent les octets nuls comme contenu de chaîne littérale.

---

### ATK-12 — Requêtes empilées (injection multi-instructions)

**Attaque** : Injecter une deuxième instruction après un point-virgule.

```
GET /products?q=test'; INSERT INTO products VALUES (99,'hacked','x',0,''); --
```

**Observé** : PDO n'exécute qu'une instruction par appel `query()`/`prepare()` — les requêtes empilées
ne sont pas supportées par défaut. Même si PDO autorisait plusieurs instructions, la valeur est liée comme
paramètre (pas interpolée). L'INSERT injecté est stocké comme texte de recherche LIKE littéral.

**Verdict** : **BLOCKED** — liaison paramétrée + mode instruction unique PDO préviennent les requêtes empilées.

---

## Résumé ATK

| # | Vecteur d'attaque | Verdict |
|---|---|---|
| ATK-01 | Injection SELECT classique via `?q=` | BLOCKED |
| ATK-02 | DROP TABLE via recherche | BLOCKED |
| ATK-03 | ORDER BY colonne inconnue | BLOCKED |
| ATK-04 | Injection de sous-requête ORDER BY | BLOCKED |
| ATK-05 | Injection de direction de tri | BLOCKED |
| ATK-06 | UNION SELECT via recherche | BLOCKED |
| ATK-07 | Injection d'ID via paramètre de chemin | BLOCKED |
| ATK-08 | Injection aveugle basée sur booléen | BLOCKED |
| ATK-09 | Injection de second ordre | BLOCKED |
| ATK-10 | Inondation de métacaractère LIKE (`%`) | EXPOSED |
| ATK-11 | Injection d'octet nul | BLOCKED |
| ATK-12 | Requêtes empilées | BLOCKED |

**Vulnérabilités réelles à corriger avant la production** :
1. **ATK-10** — Échapper les métacaractères LIKE (`%`, `_`, `\`) avant la liaison pour prévenir l'inondation de wildcards.

---

## Résumé des défenses

| Surface | Pattern vulnérable | Pattern sûr |
|---|---|---|
| Valeur dans WHERE | `WHERE id = {$id}` | `WHERE id = ?` avec `[$id]` |
| Recherche LIKE | `WHERE name LIKE '%{$q}%'` | `WHERE name LIKE '%' \|\| ? \|\| '%'` |
| Colonne ORDER BY | `ORDER BY {$sortField}` | `in_array($sortField, ALLOWED, true)` + interpoler |
| Direction ORDER BY | `ORDER BY col {$dir}` | `$dir === 'desc' ? 'DESC' : 'ASC'` |
| ID paramètre de chemin | `WHERE id = {$id}` | `(int) $id` + paramétré |

---

## Howtos connexes

- [`mass-assignment-defence.md`](mass-assignment-defence.md) — Whitelist DTO explicite comme pattern de défense plus large
- [`sqlite-fts5-search.md`](sqlite-fts5-search.md) — FTS5 comme alternative à LIKE pour la recherche plein texte
- [`jwt-authentication.md`](jwt-authentication.md) — Évaluation VULN incluant l'injection SQL (V-08)
