# How-to : Tri dynamique et filtrage avec prévention d'injection ORDER BY

> **Référence FT** : FT341 (`NENE2-FT/sortlog`) — API de tri/filtrage dynamique avec prévention d'injection SQL ORDER BY via liste blanche, liste blanche de filtre de statut, validation O(n) immune aux ReDoS, 40+ tests couvrant VULN-A à VULN-L et ATK-01 à ATK-12, tous PASS.

Ce guide montre comment implémenter un endpoint de liste triable et filtrable de façon sûre. Comme `ORDER BY` ne peut pas utiliser de placeholders paramétrisés en SQL, la colonne et la direction doivent être validées contre une liste blanche stricte avant interpolation.

## Schéma

```sql
CREATE TABLE articles (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    title      TEXT    NOT NULL,
    body       TEXT    NOT NULL,
    status     TEXT    NOT NULL DEFAULT 'draft',
    created_at TEXT    NOT NULL
);
```

## Endpoint

```
GET /articles?sort=created_at&order=desc&status=published&limit=20
```

### Paramètres valides

| Param | Valeurs autorisées | Défaut |
|-------|-------------------|--------|
| `sort` | `id`, `title`, `status`, `created_at` | `created_at` |
| `order` | `asc`, `desc` | `desc` |
| `status` | `draft`, `published`, `archived` | (tous) |
| `limit` | 1–100 | 20 |

## Réponse

```php
GET /articles?sort=title&order=asc&status=published
→ 200
{
  "data": [
    {"id": 2, "title": "Alpha", "status": "published", ...},
    {"id": 1, "title": "Beta",  "status": "published", ...}
  ],
  "total": 2,
  "sort": "title",
  "order": "asc"
}
```

## Validation par liste blanche — Le seul pattern sûr

Les clauses `ORDER BY` **ne peuvent pas utiliser des valeurs de binding paramétrisées**. Le nom de colonne doit être interpolé directement dans le SQL. Cela rend la validation par liste blanche obligatoire.

```php
private const SORT_COLUMNS = ['id', 'title', 'status', 'created_at'];
private const SORT_ORDERS  = ['asc', 'desc'];
private const STATUSES     = ['draft', 'published', 'archived'];

public function parseSort(string $sort, string $order): array
{
    // Correspondance de chaîne exacte dans la liste blanche — O(n), sensible à la casse, pas de regex
    if (!in_array($sort, self::SORT_COLUMNS, true)) {
        throw new ValidationException("Invalid sort column: {$sort}");
    }
    if (!in_array($order, self::SORT_ORDERS, true)) {
        throw new ValidationException("Invalid sort direction: {$order}");
    }
    return [$sort, $order];
}

public function parseStatus(?string $status): ?string
{
    if ($status === null) {
        return null;  // pas de filtre
    }
    if (!in_array($status, self::STATUSES, true)) {
        throw new ValidationException("Invalid status: {$status}");
    }
    return $status;
}
```

**Pourquoi `in_array()` plutôt que regex :**
- `in_array($v, $list, true)` est O(n) — immune aux ReDoS
- La regex `/^[a-z_]+$/` sur des payloads attaquants de 50 caractères peut causer un backtracking catastrophique
- Le troisième argument strict (`true`) active la comparaison type-safe

### Sensibilité à la casse

La liste blanche est sensible à la casse par conception :

```php
GET /articles?sort=ID       → 422  // 'ID' pas dans la liste blanche
GET /articles?sort=TITLE    → 422
GET /articles?sort=Created_At → 422
GET /articles?sort=created_at → 200  ✅ correspondance exacte
```

## Construction de requête

```php
$sql = 'SELECT * FROM articles';

if ($status !== null) {
    // Le statut utilise un placeholder paramétrisé (sûr)
    $sql .= ' WHERE status = ?';
    $params[] = $status;
}

// La colonne et la direction de tri viennent de la liste blanche — sûr à interpoler
$sql .= " ORDER BY {$sort} {$order}";
$sql .= ' LIMIT ?';
$params[] = $limit;
```

`ORDER BY` utilise des valeurs interpolées de liste blanche ; les valeurs de la clause `WHERE` utilisent toujours des placeholders `?`.

## Payloads rejetés

### Patterns d'injection → 422

```php
// Injection SQL dans sort
?sort='; DROP TABLE articles--             → 422
?sort=id UNION SELECT 1,2,3,4,5           → 422
?sort=(SELECT name FROM sqlite_master)    → 422
?sort=CASE WHEN 1=1 THEN id ELSE title END → 422
?sort=created_at--                        → 422  // commentaire
?sort=created_at%00                       → 422  // octet nul
?sort=1                                   → 422  // index de colonne (pas dans la liste blanche)

// Injection de direction
?order=asc; UNION SELECT 1,2,3--          → 422
?order=DESC;                              → 422

// Injection de filtre de statut
?status=' OR '1'='1                       → 422
?status=draft UNION SELECT 1,2--          → 422
?status=1                                 → 422  // doit être un nom de statut exact
?status=TRUE                              → 422

// Contournement par espaces blancs
?sort=created_at%09                       → 422  // TAB
?sort= created_at                         → 422  // espace en tête

// Injection de tableau (PSR-7)
?sort[]=created_at                        → 422  // tableau, pas chaîne
```

### Injection de limite → 422

```php
?limit=999999           → 422  // dépasse MAX_LIMIT=100
?limit=9999999999999999999999  → 422  // débordement (strlen > 18)
?limit=-1               → 422  // négatif
?limit=10.5             → 422  // float
```

### Requêtes valides → 200

```php
GET /articles                                  → 200  // défauts
GET /articles?sort=title&order=asc             → 200
GET /articles?sort=id&order=desc&status=draft  → 200
GET /articles?limit=50                         → 200
```

## Sécurité de timing

Chaque rejet est instantané (<100ms). La vérification de liste blanche utilise `in_array()` qui court-circuite à la première non-correspondance — pas de backtracking regex :

```php
// Payload ReDoS : "aaaa...a!" (50 a + '!')
// in_array("aaaa...a!", ['id','title','status','created_at'], true) → false immédiatement
```

---

## Ce qu'il ne faut PAS faire

| Anti-pattern | Risque |
|---|---|
| Interpoler `?sort=` directement : `ORDER BY $sort` | Injection SQL — l'attaquant contrôle entièrement la clause `ORDER BY` |
| Valider avec regex `/^[a-z_]+$/` uniquement | ReDoS sur des payloads de 50+ caractères ; autorise les noms de colonnes inconnus comme `password` |
| Comparaison insensible à la casse (`strcasecmp`) | `ORDER BY CREATED_AT` est du SQL valide mais contourne les tests sensibles à la casse |
| Paramétiser `ORDER BY $sort` comme valeur de binding | La plupart des drivers DB le traitent silencieusement comme un littéral ou lancent une erreur |
| Liste blanche uniquement `sort`, pas la direction `order` | `order=asc; UNION SELECT ...` contourne la vérification de colonne |
| Faire confiance au tableau `sort[]` après le parsing PSR-7 | `implode(', ', $sort)` avec injection de tableau produit un ORDER BY multi-colonnes |
| Omettre la liste blanche du filtre `status` | `status=admin' OR '1'='1` devient `WHERE status = 'admin' OR '1'='1'` |
