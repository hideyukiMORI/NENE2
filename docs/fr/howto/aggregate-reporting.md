# How-to : API de rapports agrégés

> **Référence FT** : FT245 (`NENE2-FT/agglog`) — API de rapports agrégés

Démontre une API de rapports agrégés multi-dimensionnelle où une table de commandes unique
est découpée en totaux récapitulatifs, ventilation quotidienne, distribution des statuts et top articles —
tout cela avec un filtrage optionnel par plage de dates, `COALESCE` pour des agrégations sans valeur nulle,
et `COUNT(CASE WHEN...)` pour des comptages conditionnels sans sous-requêtes.

---

## Routes

| Méthode | Chemin                 | Description                                          |
|--------|----------------------|------------------------------------------------------|
| `POST` | `/orders`            | Enregistrer une commande                             |
| `GET`  | `/reports/summary`   | Total des commandes, revenus, valeur moyenne des commandes, nombre de complétées |
| `GET`  | `/reports/daily`     | Revenus et nombre de commandes par jour              |
| `GET`  | `/reports/by-status` | Nombre de commandes et revenus groupés par statut    |
| `GET`  | `/reports/top-items` | Top articles par revenu (limités, classés)           |

---

## Schéma

```sql
CREATE TABLE IF NOT EXISTS orders (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    customer_id TEXT    NOT NULL,
    item_name   TEXT    NOT NULL,
    amount      INTEGER NOT NULL,
    status      TEXT    NOT NULL DEFAULT 'pending'
                    CHECK(status IN ('pending', 'completed', 'refunded', 'cancelled')),
    created_at  TEXT    NOT NULL
);
```

`status` est contraint par un `CHECK` au niveau DB comme filet de sécurité. `amount` est
stocké en entier (plus petite unité monétaire). `created_at` est une chaîne ISO — les comparaisons de dates
utilisent l'ordre des chaînes au format `YYYY-MM-DD`, qui est lexicographiquement
cohérent avec l'ordre chronologique.

---

## Agrégation récapitulative : `COALESCE` + `COUNT(CASE WHEN ...)`

L'endpoint récapitulatif retourne plusieurs métriques agrégées dans une seule requête :

```php
$row = $this->db->fetchOne(
    "SELECT COUNT(*) AS total_orders,
            COALESCE(SUM(amount), 0) AS total_revenue,
            COALESCE(AVG(amount), 0) AS avg_order_value,
            COUNT(CASE WHEN status = 'completed' THEN 1 END) AS completed_orders
     FROM orders {$where}",
    $params,
);
```

`COALESCE(SUM(amount), 0)` — retourne `0` au lieu de `NULL` quand la table n'a pas de
lignes correspondantes. `SUM()` et `AVG()` retournent `NULL` sur des ensembles vides ; `COALESCE` convertit
cela en zéro sécurisé.

`COUNT(CASE WHEN status = 'completed' THEN 1 END)` — compte uniquement les lignes où `status =
'completed'`, sans sous-requête ni second passage. `CASE WHEN` retourne `NULL` pour
les lignes non correspondantes ; `COUNT` ignore `NULL`, donc seules les commandes complétées sont comptées.

C'est équivalent à un `COUNT` filtré mais s'exécute en un seul scan, ce qui le rend plus
efficace que des requêtes séparées pour chaque statut.

---

## Ventilation quotidienne : `substr()` pour la troncature de date

```php
$rows = $this->db->fetchAll(
    "SELECT substr(created_at, 1, 10) AS date,
            COUNT(*) AS order_count,
            SUM(amount) AS revenue
     FROM orders {$where}
     GROUP BY date
     ORDER BY date ASC",
    $params,
);
```

`substr(created_at, 1, 10)` extrait les 10 premiers caractères (`YYYY-MM-DD`) de la
chaîne datetime ISO, regroupant tous les événements du même jour calendaire. C'est une
alternative à `strftime('%Y-%m-%d', created_at)` de SQLite pour les chaînes timestamp au
format ISO 8601 avec un préfixe fixe.

`GROUP BY date` utilise l'alias — SQLite supporte l'aliasing dans `GROUP BY` (contrairement à certaines
autres bases de données qui nécessitent de répéter l'expression).

---

## Distribution des statuts : `GROUP BY status ORDER BY count DESC`

```php
$rows = $this->db->fetchAll(
    "SELECT status, COUNT(*) AS order_count, SUM(amount) AS revenue
     FROM orders {$where}
     GROUP BY status
     ORDER BY order_count DESC",
    $params,
);
```

`ORDER BY order_count DESC` place le statut le plus courant en premier. L'ensemble de résultats a
au maximum autant de lignes qu'il y a de valeurs de statut distinctes (quatre dans ce schéma).

---

## Top articles : classés par revenu avec `LIMIT`

```php
$rows = $this->db->fetchAll(
    "SELECT item_name, COUNT(*) AS order_count, SUM(amount) AS revenue
     FROM orders {$where}
     GROUP BY item_name
     ORDER BY revenue DESC
     LIMIT ?",
    $params,
);
```

`ORDER BY revenue DESC LIMIT ?` — `LIMIT` paramétré sélectionne les N premiers articles par
revenu total. Le paramètre de chemin `limit` est clampé côté serveur :

```php
private const int MAX_LIMIT = 100;

$limit = min((int) $q['limit'], self::MAX_LIMIT);
```

`min(..., MAX_LIMIT)` empêche les clients de demander plus de 100 articles. Note :
`is_numeric($q['limit'])` est utilisé ici (plutôt que `is_int`) parce que les valeurs de query string
sont toujours des chaînes — `is_int` échouerait toujours sur les entrées de query string.

---

## Clause `WHERE` dynamique avec `dateFilter()`

Toutes les requêtes d'agrégation partagent un helper `dateFilter()` qui ajoute des conditions uniquement
quand une borne de date est fournie :

```php
private function dateFilter(?string $from, ?string $to): array
{
    $conditions = [];
    $params     = [];
    if ($from !== null) {
        $conditions[] = 'created_at >= ?';
        $params[]     = $from;
    }
    if ($to !== null) {
        $conditions[] = 'created_at <= ?';
        $params[]     = $to;
    }
    $where = $conditions !== [] ? 'WHERE ' . implode(' AND ', $conditions) : '';
    return [$where, $params];
}
```

Quand `from` et `to` sont tous les deux `null`, `$where` est `''` — la table complète est scannée.
L'appelant incorpore `{$where}` dans la chaîne SQL avant l'exécution de la requête. Les
valeurs réelles sont toujours paramétrées (`?`) — seul le mot-clé `WHERE` est interpolé.

---

## Validation des dates : aller-retour avec `createFromFormat()`

Accepter `from` et `to` comme chaînes YYYY-MM-DD nécessite de valider que la date est
à la fois bien formée et sémantiquement valide (par ex. `2026-02-30` est rejeté) :

```php
private function isValidDate(string $date): bool
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return false;
    }
    $d = \DateTimeImmutable::createFromFormat('Y-m-d', $date);
    return $d !== false && $d->format('Y-m-d') === $date;
}
```

Validation en deux étapes :
1. `preg_match` — rejette rapidement le format non correspondant sans overhead d'objet date.
2. `createFromFormat` + aller-retour `format()` — détecte les dates sémantiquement invalides comme
   `2026-02-30` (que PHP déborderait en `2026-03-02` si validé uniquement par regex).

La direction de la plage est également validée :
```php
if ($from !== null && $to !== null && $from > $to) {
    $errors[] = new ValidationError('from', 'from must be before or equal to to', 'invalid');
}
```

La comparaison de chaînes fonctionne correctement ici parce que les deux dates sont au format `YYYY-MM-DD` — un format
où l'ordre lexicographique est égal à l'ordre chronologique.

---

## Fonctions intégrées NENE2 utilisées

| Fonction intégrée | Objectif |
|---|---|
| `ValidationException` / `ValidationError` | `422` structuré avec tableau `errors` |
| `JsonResponseFactory::create()` | Encode la réponse JSON |
| Constantes `Router` | `PARAMETERS_ATTRIBUTE` pour les paramètres de chemin |

---

## Howtos associés

- [`event-analytics-api.md`](event-analytics-api.md) — analytique de blob JSON avec `json_extract()`, regroupement `COUNT(DISTINCT)`
- [`cqrs-pattern.md`](cqrs-pattern.md) — vue SQL comme modèle de lecture pour l'agrégation des commandes
- [`credit-ledger.md`](credit-ledger.md) — calcul de solde `COALESCE(SUM(amount * direction), 0)`
- [`admin-report-aggregation.md`](admin-report-aggregation.md) — patterns d'agrégation limités à l'admin
