# How-to : API de suivi des dépenses

Ce guide montre comment construire un système personnel de suivi des dépenses avec filtrage par catégorie,
requêtes par plage de dates, agrégation de résumé mensuel et CRUD complet en utilisant NENE2.
Pattern démontré par l'essai sur le terrain **expenselog** (FT223).

## Fonctionnalités

- Créer, lire, mettre à jour, supprimer des dépenses (date, montant, catégorie, note)
- Lister avec filtre de plage de dates (`?from=` / `?to=`) et filtre de catégorie
- Agrégation de résumé mensuel (total par catégorie par mois)
- Pagination avec compte total
- Validation de liste blanche de catégorie
- Validation de montant : entier positif (centimes)

## Schéma

```sql
CREATE TABLE IF NOT EXISTS expenses (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    date       TEXT    NOT NULL,
    amount     INTEGER NOT NULL,
    category   TEXT    NOT NULL,
    note       TEXT    NOT NULL DEFAULT '',
    created_at TEXT    NOT NULL,
    updated_at TEXT    NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_expenses_date ON expenses (date DESC);
CREATE INDEX IF NOT EXISTS idx_expenses_category ON expenses (category, date DESC);
```

## Endpoints

| Méthode   | Chemin                  | Description                            |
|-----------|-------------------------|----------------------------------------|
| `GET`     | `/expenses`             | Lister les dépenses (filtrable, paginé) |
| `POST`    | `/expenses`             | Créer une dépense                      |
| `GET`     | `/expenses/summary`     | Résumé mensuel par catégorie           |
| `GET`     | `/expenses/{id}`        | Obtenir une seule dépense              |
| `PATCH`   | `/expenses/{id}`        | Mise à jour partielle                  |
| `DELETE`  | `/expenses/{id}`        | Supprimer une dépense                  |

## Patterns de validation

### Montant (entier positif, stocké en centimes)

```php
if (!is_int($amount) || $amount <= 0) {
    $errors[] = new ValidationError('amount', 'Amount must be a positive integer (cents).');
}
```

L'utilisation de `is_int()` rejette les flottants provenant de JSON (`1.5` n'est pas un int en mode strict de PHP).

### Date (format ISO 8601)

```php
$date = DateTime::createFromFormat('Y-m-d', $dateStr);
if (!$date || $date->format('Y-m-d') !== $dateStr) {
    $errors[] = new ValidationError('date', 'date must be a valid YYYY-MM-DD date.');
}
```

Validation par aller-retour : analyser puis reformater — garantit que la chaîne était canonique.

### Liste blanche de catégorie

```php
private const array VALID_CATEGORIES = [
    'food', 'transport', 'utilities', 'entertainment',
    'health', 'shopping', 'travel', 'other',
];

if (!in_array($category, self::VALID_CATEGORIES, true)) {
    $errors[] = new ValidationError('category', 'Invalid category.');
}
```

## Filtrage par plage de dates

```php
public function findAll(
    ?string $from = null,
    ?string $to = null,
    ?string $category = null,
    int $limit = 20,
    int $offset = 0,
): array
```

Les filtres sont optionnels — omettre pour les requêtes sur toute la période. Les dates sont comparées lexicographiquement (les chaînes ISO 8601 sont triables en UTC).

## Requête de résumé mensuel

Agréger par année-mois en utilisant `strftime` de SQLite :

```sql
SELECT strftime('%Y-%m', date) AS month, category, SUM(amount) AS total, COUNT(*) AS count
FROM expenses
WHERE date BETWEEN :from AND :to
GROUP BY month, category
ORDER BY month DESC, category ASC
```

Retourne les totaux par catégorie pour chaque mois :

```json
{
  "summary": [
    { "month": "2026-05", "category": "food", "total": 42000, "count": 12 },
    { "month": "2026-05", "category": "transport", "total": 8500, "count": 5 }
  ]
}
```

## Mise à jour partielle PATCH

Seuls les champs fournis dans le corps sont mis à jour — les champs absents conservent leurs valeurs actuelles :

```php
if (isset($body['amount'])) {
    if (!is_int($body['amount']) || $body['amount'] <= 0) {
        $errors[] = new ValidationError('amount', 'Amount must be a positive integer.');
    } else {
        $updates['amount'] = $body['amount'];
    }
}
// Même pattern pour date, category, note
```

## Patterns de validation

| Champ | Vérification | Raison |
|-------|--------------|--------|
| `amount` | `is_int() && > 0` | Rejette les flottants, zéro, négatifs |
| `date` | Parse aller-retour `Y-m-d` | ISO 8601 canonique uniquement |
| `category` | `in_array(strict: true)` | Prévient les fautes de frappe et l'injection |
| `limit` / `offset` | `max(1, min(100, $limit))` | Prévient DoS et injection SQL |
