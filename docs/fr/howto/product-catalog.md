# How-to : API de catalogue de produits (ATK-01~12)

Ce guide présente une API de catalogue de produits avec des opérations d'écriture réservées aux admins, une recherche par mot-clé et une suppression douce — couvrant les vecteurs d'attaque ATK-01~12.

## Vue d'ensemble du pattern

- Les lectures du catalogue sont publiques ; les écritures (créer, supprimer) nécessitent un admin (`X-Admin-Key`).
- Les SKUs sont alphanumériques en majuscules avec des tirets (`/\A[A-Z0-9\-]{1,32}\z/`).
- La suppression douce (`active = 0`) masque les produits sans perdre l'historique.
- La recherche par mot-clé utilise `LIKE` avec une garde de longueur pour prévenir les attaques par mot-clé.

## Schéma

```sql
CREATE TABLE IF NOT EXISTS products (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    sku         TEXT    NOT NULL UNIQUE,
    name        TEXT    NOT NULL,
    description TEXT    NOT NULL DEFAULT '',
    price_cents INTEGER NOT NULL,
    active      INTEGER NOT NULL DEFAULT 1,
    created_at  TEXT    NOT NULL
);
```

## ATK-01 : Injection SQL dans le mot-clé de recherche

```php
$kw   = '%' . $keyword . '%';
$stmt = $this->pdo->prepare(
    'SELECT * FROM products WHERE active = 1 AND (name LIKE :kw OR ...) LIMIT :lim OFFSET :off'
);
$stmt->bindValue(':kw', $kw, PDO::PARAM_STR);
```

Le wildcard `%` fait partie de la valeur littérale passée à une requête paramétrée — aucune interpolation ne se produit.

## ATK-02 : Admin fail-closed

```php
private function isAdmin(ServerRequestInterface $req): bool
{
    if ($this->adminKey === '') {
        return false;
    }
    return hash_equals($this->adminKey, $req->getHeaderLine('X-Admin-Key'));
}
```

Clé admin vide → toujours 403. Mauvaise clé → `hash_equals()` évite les fuites de timing.

## ATK-03 : Dépassement d'entier dans l'ID de produit

```php
if (!ctype_digit($raw) || strlen($raw) > 18) {
    return null;  // → 404
}
```

Une chaîne d'ID de 20 chiffres dépasse 18 caractères et est rejetée avant tout cast `(int)` ou requête DB.

## ATK-04 : ID négatif

`ctype_digit()` sur `-1` échoue (caractère non numérique) → 404.

## ATK-05 : Prix float

```php
if (!is_int($priceCents) || $priceCents < 0) {
    return $this->problem(422, ...);
}
```

`is_int(9.99)` retourne `false` — les prix flottants sont rejetés.

## ATK-06 : Injection de SKU

Le regex de SKU `/\A[A-Z0-9\-]{1,32}\z/` rejette `; DROP TABLE`, les guillemets, les espaces et les minuscules. Seul le format exact est accepté.

## ATK-07 : Injection de wildcard dans la recherche

`%` dans un mot-clé de recherche est traité comme un wildcard SQL LIKE — il correspond à tout. C'est intentionnel (les utilisateurs peuvent rechercher tout). Le LIKE est paramétré donc `%; DROP TABLE products; --` n'est pas exécuté comme SQL :

```sql
WHERE name LIKE '%%; DROP TABLE products; --%'
```

Le résultat est juste une correspondance LIKE plus large, pas une injection.

## ATK-08 : Double suppression

La méthode `delete()` du repository vérifie d'abord `findById()` (active=1 uniquement). Un produit soft-deleted retourne null → 404 lors de la deuxième suppression.

## ATK-09 : SKU trop long

Le quantificateur de regex `{1,32}` rejette les SKUs de plus de 32 caractères avant d'atteindre la DB.

## ATK-10 : Mauvaise clé admin

La comparaison `hash_equals()` prend toujours le même temps quel que soit le nombre de caractères correspondants.

## Garde de longueur de mot-clé

```php
if ($keyword !== null && strlen($keyword) > 100) {
    return $this->problem(422, 'validation-failed', 'q too long (max 100).');
}
```

Prévient l'envoi d'un pattern LIKE de 10 Mo à la base de données.

## Suppression douce

```php
$this->pdo->prepare('UPDATE products SET active = 0 WHERE id = :id')->execute([':id' => $id]);
```

Toutes les lectures incluent `WHERE active = 1`. Les produits supprimés deviennent invisibles sans suppression physique.

## Routes

```
POST   /products      Créer un produit (admin uniquement)
GET    /products      Lister/rechercher des produits (public)
GET    /products/{id} Obtenir un produit (public)
DELETE /products/{id} Supprimer un produit (admin uniquement)
```

## Voir aussi

- Source FT212 : `../NENE2-FT/productlog/`
- Connexe : `docs/howto/inventory-management.md` (FT203, stock basé sur SKU)
- Connexe : `docs/howto/session-token-management.md` (FT208, aussi ATK)
