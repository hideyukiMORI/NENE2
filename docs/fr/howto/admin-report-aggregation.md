# How-to : Ajouter l'agrégation de rapports admin

Construire des endpoints d'agrégation de type tableau de bord avec des filtres de plage de dates, des regroupements et une limitation du paramètre limit.

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
| `GET` | `/reports/summary` | Total commandes, revenu, moyenne, nombre de complétées |
| `GET` | `/reports/daily` | Commandes groupées par date |
| `GET` | `/reports/by-status` | Commandes groupées par statut |
| `GET` | `/reports/top-items` | Top N articles par revenu |

Paramètres de requête (tous les rapports) : `from=YYYY-MM-DD`, `to=YYYY-MM-DD`

## Filtre de plage de dates (paramétré sécurisé)

Construire la clause WHERE dynamiquement, passer les valeurs comme paramètres liés — ne jamais interpoler :

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

Rejeter les dates non-ISO-8601 avant qu'elles n'atteignent la requête :

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

## Limitation du paramètre limit

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

**Répartition quotidienne** (sous-chaîne de date SQLite) :
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
- Les noms d'articles et IDs clients stockés via des requêtes paramétrées — les caractères spéciaux et les tentatives d'injection sont des chaînes littérales.
- `COALESCE(SUM(...), 0)` empêche les NULL dans les résumés quand aucune ligne ne correspond.
- Le limit est limité à `MAX_LIMIT` — empêche l'épuisement des ressources par des valeurs `LIMIT` énormes.
