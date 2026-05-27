# How-to : Validation JSON imbriquée

> **Référence FT** : FT322 (`NENE2-FT/nestedlog`) — API de commande avec validation d'articles imbriqués, chemins d'erreur `items.N.field`, réponse unique multi-erreurs, codes d'erreur, calcul de total, 19 tests / 43 assertions PASS.

Ce guide montre comment valider des tableaux JSON imbriqués (ex: lignes de commande) et retourner des chemins d'erreur structurés qui identifient exactement quel champ imbriqué a échoué.

## Schéma

```sql
CREATE TABLE orders (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    customer   TEXT    NOT NULL,
    total      REAL    NOT NULL DEFAULT 0.0,
    created_at TEXT    NOT NULL
);

CREATE TABLE order_items (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    order_id   INTEGER NOT NULL REFERENCES orders(id),
    product_id INTEGER NOT NULL,
    quantity   INTEGER NOT NULL,
    unit_price REAL    NOT NULL
);
```

## Endpoints

| Méthode | Chemin | Description |
|---------|--------|-------------|
| `POST` | `/orders` | Créer une commande avec articles |
| `GET` | `/orders` | Lister les commandes |
| `GET` | `/orders/{id}` | Obtenir une commande avec ses articles |

## Créer une commande

```php
POST /orders
{
  "customer": "Alice",
  "items": [
    {"product_id": 1, "quantity": 2, "unit_price": 9.99},
    {"product_id": 2, "quantity": 1, "unit_price": 4.50}
  ]
}
→ 201
{
  "id": 1,
  "customer": "Alice",
  "items": [...],
  "total": 24.48      // 2×9.99 + 1×4.50
}
```

## Chemins d'erreur imbriqués — `items.N.field`

Chaque erreur d'article inclut l'index du tableau dans le chemin du champ :

```php
POST /orders
{
  "customer": "Alice",
  "items": [
    {"product_id": "not-an-int", "quantity": 2, "unit_price": 9.99},
    {"product_id": 1, "quantity": 1, "unit_price": -5.0}
  ]
}
→ 422
{
  "errors": [
    {"field": "items.0.product_id", "message": "...", "code": "invalid-type"},
    {"field": "items.1.unit_price",  "message": "...", "code": "min-value"}
  ]
}
```

## Toutes les erreurs en une seule réponse

Tous les échecs de validation — tant au niveau supérieur qu'imbriqués — sont collectés et retournés ensemble. Ne jamais retourner une seule erreur à la fois pour les soumissions en lot :

```php
POST /orders
{
  "customer": "",      // erreur : required
  "items": [
    {"product_id": 0, "quantity": -1, "unit_price": 1.0}  // 2 erreurs
  ]
}
→ 422
{
  "errors": [
    {"field": "customer",          "code": "required"},
    {"field": "items.0.product_id","code": "min-value"},
    {"field": "items.0.quantity",  "code": "min-value"}
  ]
}
```

## Règles de validation

| Champ | Règle |
|-------|-------|
| `customer` | Requis, non-vide, max 200 caractères |
| `items` | Requis, tableau non-vide |
| `items[].product_id` | Entier, ≥ 1 |
| `items[].quantity` | Entier, ≥ 1 |
| `items[].unit_price` | Nombre (int ou float), > 0 |

## Pattern d'implémentation

```php
final class OrderValidator
{
    /** @return list<ValidationError> */
    public function validate(array $data): array
    {
        $errors = [];

        // Validation au niveau supérieur
        $customer = trim($data['customer'] ?? '');
        if ($customer === '') {
            $errors[] = new ValidationError('customer', 'required', 'required');
        } elseif (strlen($customer) > 200) {
            $errors[] = new ValidationError('customer', 'max 200 chars', 'max-length');
        }

        $items = $data['items'] ?? null;
        if (!is_array($items) || $items === []) {
            $errors[] = new ValidationError('items', 'required non-empty array', 'required');
            return $errors;  // impossible de valider les articles plus loin
        }

        // Validation d'article imbriqué avec index
        foreach ($items as $i => $item) {
            $prefix = "items.{$i}";

            $productId = $item['product_id'] ?? null;
            if (!is_int($productId) || $productId < 1) {
                $errors[] = new ValidationError("{$prefix}.product_id", 'must be int >= 1', 'min-value');
            }

            $quantity = $item['quantity'] ?? null;
            if (!is_int($quantity) || $quantity < 1) {
                $errors[] = new ValidationError("{$prefix}.quantity", 'must be int >= 1', 'min-value');
            }

            $price = $item['unit_price'] ?? null;
            if ((!is_int($price) && !is_float($price)) || $price <= 0) {
                $errors[] = new ValidationError("{$prefix}.unit_price", 'must be number > 0', 'min-value');
            }
        }

        return $errors;
    }
}
```

## Codes d'erreur

| Code | Signification |
|------|---------------|
| `required` | Champ manquant ou vide |
| `max-length` | Dépasse la longueur maximale |
| `min-value` | En dessous de la valeur minimale (int/float) |
| `invalid-type` | Mauvais type (ex: chaîne là où un int est attendu) |

---

## À ne pas faire

| Anti-pattern | Risque |
|---|---|
| Retourner uniquement la première erreur | Le client doit soumettre, obtenir l'erreur, corriger, resoumettre N fois — UX terrible pour les formulaires en lot |
| Chemin d'erreur plat `"product_id"` pour les articles imbriqués | Le client ne peut pas identifier quel article (index 0, 1, ...) a échoué |
| Accepter silencieusement `unit_price: 0` | Les articles à prix zéro corrompent les totaux de commande |
| Valider les articles seulement après que le niveau supérieur passe | Retarde le feedback ; collecter toutes les erreurs en un seul passage |
| Arrêter la validation à la première erreur d'article | Masque d'autres erreurs dans les articles restants |
