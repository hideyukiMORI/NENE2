# How-to : API de suivi des dépenses

> **Référence FT** : FT311 (`NENE2-FT/expenselog`) — Suivi des dépenses : validation du format de date YYYY-MM-DD, catégorie en chaîne libre (pas d'enum), agrégation de résumé mensuel par catégorie, pagination offset avec limit/offset, mise à jour partielle PATCH (seuls les champs fournis sont modifiés), filtre de plage de dates, route statique `/summary` avant dynamique `/{id}`, 34 tests / 67 assertions PASS.

Ce guide montre comment construire une API de suivi des dépenses avec filtrage par date, agrégation par catégorie, pagination et mises à jour partielles.

## Schéma

```sql
CREATE TABLE IF NOT EXISTS expenses (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    date        TEXT    NOT NULL,  -- YYYY-MM-DD
    amount      INTEGER NOT NULL,  -- centimes
    category    TEXT    NOT NULL,
    note        TEXT    NOT NULL DEFAULT '',
    created_at  TEXT    NOT NULL,
    updated_at  TEXT    NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_expenses_date     ON expenses (date);
CREATE INDEX IF NOT EXISTS idx_expenses_category ON expenses (category);
```

Les index sur `date` et `category` permettent un filtrage rapide. `amount` en centimes entiers évite les problèmes de précision virgule flottante.

## Endpoints

| Méthode   | Chemin                  | Description                                    |
|-----------|-------------------------|------------------------------------------------|
| `GET`     | `/expenses`             | Lister avec filtres optionnels + pagination    |
| `POST`    | `/expenses`             | Créer une dépense                              |
| `GET`     | `/expenses/summary`     | Agrégation mensuelle par catégorie             |
| `GET`     | `/expenses/{id}`        | Obtenir une seule dépense                      |
| `PATCH`   | `/expenses/{id}`        | Mise à jour partielle                          |
| `DELETE`  | `/expenses/{id}`        | Supprimer                                      |

**Ordre des routes** : `/expenses/summary` doit être enregistré **avant** `/expenses/{id}` — sinon `summary` est capturé comme paramètre `id`.

## Validation de date — YYYY-MM-DD

```php
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    $errors[] = new ValidationError('date', 'date must be in YYYY-MM-DD format.', 'invalid_format');
}
```

Seul le format strict `YYYY-MM-DD` est accepté. Les chaînes ISO 8601 avec composantes horaires ou décalages de fuseau horaire sont rejetées.

## Catégorie — Chaîne libre (pas d'enum)

```php
$category = isset($body['category']) && is_string($body['category']) && $body['category'] !== ''
    ? $body['category']
    : null;
if ($category === null) {
    $errors[] = new ValidationError('category', 'category is required.', 'required');
}
```

Les catégories sont des chaînes libres (pas un enum fermé). Toute chaîne non vide est valide, permettant des catégories comme `"food"`, `"transport"`, `"entertainment"` sans modification de schéma.

## Résumé mensuel — Format YYYY-MM

```php
// Requête : SELECT category, SUM(amount), COUNT(*) WHERE date LIKE 'YYYY-MM-%'
$summary = $this->repository->monthlySummary($month);

return $this->json->create([
    'month'       => $summary->month,
    'total'       => $summary->total,
    'count'       => $summary->count,
    'by_category' => $summary->byCategory, // [{category, total, count}, ...]
]);
```

Format du paramètre mois : `YYYY-MM`. Agrège toutes les dépenses de ce mois, groupées par catégorie.

## Pagination — Basée sur l'offset

```php
$pagination = PaginationQueryParser::parse($request);
// Retourne : { limit: int, offset: int }

$items = $this->repository->findAll($from, $to, $category, $pagination->limit, $pagination->offset);
$total = $this->repository->count($from, $to, $category);

return $this->json->create([
    'items'  => $items,
    'total'  => $total,
    'limit'  => $pagination->limit,
    'offset' => $pagination->offset,
]);
```

`limit` invalide (non-entier, négatif, trop grand) → 422.

## PATCH — Mise à jour partielle

```php
// Mettre à jour uniquement les champs présents dans le corps
$date     = array_key_exists('date', $body)     ? $body['date']     : null;
$amount   = array_key_exists('amount', $body)   ? $body['amount']   : null;
$category = array_key_exists('category', $body) ? $body['category'] : null;
$note     = array_key_exists('note', $body)     ? $body['note']     : null;
```

`array_key_exists()` distingue "champ non fourni" de "champ fourni comme null". Seuls les champs fournis sont validés et mis à jour.

## Filtre de plage de dates

```php
// GET /expenses?date_from=2026-01-01&date_to=2026-01-31
$from = QueryStringParser::string($request, 'date_from');
$to   = QueryStringParser::string($request, 'date_to');
$category = QueryStringParser::string($request, 'category');

$items = $this->repository->findAll($from, $to, $category, $limit, $offset);
```

Tous les paramètres de filtre sont optionnels. Null signifie "pas de filtre sur cette dimension".

---

## À ne pas faire

| Anti-pattern | Risque |
|---|---|
| Enregistrer `/expenses/{id}` avant `/expenses/summary` | `"summary"` correspond comme `id` ; endpoint summary inaccessible |
| Stocker `amount` comme FLOAT | Précision virgule flottante : `0.1 + 0.2 ≠ 0.3` ; utiliser des centimes entiers |
| Accepter n'importe quelle chaîne de date (ISO 8601 avec heure) | Comparaison de dates incohérente dans les clauses WHERE |
| Enum de catégories fermé | Les nouvelles catégories nécessitent une migration de schéma |
| `isset($body['field'])` pour PATCH | `isset()` retourne false pour `null` ; utiliser `array_key_exists()` |
| Requête de comptage sans les mêmes filtres que la liste | Le total de pagination ne correspond pas au nombre filtré réel |
| Pas d'index sur date/category | Scan complet de table à chaque requête de liste filtrée |
