# Comment ajouter une agrégation de rapports administrateur

Construisez des endpoints d'agrégation de style tableau de bord avec des filtres de plage de dates, du regroupement et un clamping de limite.

## Schéma

```sql
CREATE TABLE orders (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    customer_id TEXT NOT NULL, item_name TEXT NOT NULL,
    amount INTEGER NOT NULL,
    status TEXT NOT NULL DEFAULT 'pending'
        CHECK(status IN ('pending','completed','refunded','cancelled')),
    created_at TEXT NOT NULL
);
```

## Routes

| Méthode | Chemin | Description |
|--------|------|-------------|
| `POST` | `/orders` | Insérer une commande |
| `GET` | `/reports/summary` | Total des commandes, revenus, moyenne, nombre de complétées |
| `GET` | `/reports/daily` | Commandes groupées par date |
| `GET` | `/reports/by-status` | Commandes groupées par statut |
| `GET` | `/reports/top-items` | Top N articles par revenu |

Paramètres de requête (tous les rapports) : `from=YYYY-MM-DD`, `to=YYYY-MM-DD`

## Filtre de plage de dates (sécurisé avec paramètres)

Construisez la clause WHERE dynamiquement, passez les valeurs comme paramètres liés — ne jamais interpoler :

```php
private function dateFilter(?string $from, ?string $to): array
{
    $conditions = [];
    $params     = [];
    if ($from !== null) { $conditions[] = 'created_at >= ?'; $params[] = $from; }
    if ($to !== null)   { $conditions[] = 'created_at <= ?'; $params[] = $to; }
    $where = $conditions !== [] ? 'WHERE ' . implode(' AND ', $conditions) : '';
    return [$where, $params];
}
```

## Validation des dates (protection contre l'injection)

Rejetez les dates non conformes à ISO 8601 avant qu'elles n'atteignent la requête :

```php
private function isValidDate(string $date): bool
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return false;
    }
    $d = \DateTimeImmutable::createFromFormat('Y-m-d', $date);
    return $d !== false && $d->format('Y-m-d') === $date; // rejeter 2026-13-01
}
```

Rejeter `from > to` :

```php
if ($from !== null && $to !== null && $from > $to) {
    $errors[] = new ValidationError('from', 'from must be before or equal to to', 'invalid');
}
```

## Clamping de la limite

```php
private const int MAX_LIMIT = 100;

$limit = min((int) $q['limit'], self::MAX_LIMIT);
if ($limit <= 0) { /* 422 */ }
```

## Requêtes d'agrégation

**Résumé** :
```sql
SELECT COUNT(*) AS total_orders,
       COALESCE(SUM(amount), 0) AS total_revenue,
       COALESCE(AVG(amount), 0) AS avg_order_value,
       COUNT(CASE WHEN status = 'completed' THEN 1 END) AS completed_orders
FROM orders {where}
```

**Ventilation quotidienne** (sous-chaîne de date SQLite) :
```sql
SELECT substr(created_at, 1, 10) AS date, COUNT(*) AS order_count, SUM(amount) AS revenue
FROM orders {where}
GROUP BY date ORDER BY date ASC
```

**Top articles** :
```sql
SELECT item_name, COUNT(*) AS order_count, SUM(amount) AS revenue
FROM orders {where}
GROUP BY item_name ORDER BY revenue DESC LIMIT ?
```

## Notes de sécurité

- Tous les paramètres de requête validés avant utilisation — l'injection SQL via `from`/`to`/`limit` est rejetée avec 422.
- Les noms d'articles et les IDs client sont stockés via des requêtes paramétrées — les caractères spéciaux et les tentatives d'injection sont des chaînes littérales.
- `COALESCE(SUM(...), 0)` empêche NULL dans les résumés quand aucune ligne ne correspond.
- La limite est clampée à `MAX_LIMIT` — empêche l'épuisement des ressources par des valeurs `LIMIT` énormes.
