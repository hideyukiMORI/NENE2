# How-to : Prévention des bornes de pagination et injection de limite

> **Référence FT** : FT319 (`NENE2-FT/limitlog`) — Pagination par offset et curseur avec validation stricte de limite/page, application du plafond MAX_LIMIT, validation ctype_digit résistante aux ReDoS, 20 tests / 384 assertions PASS.

Ce guide montre comment implémenter une pagination sécurisée avec les stratégies offset et curseur, tout en prévenant les attaques sur les bornes d'entiers et l'injection de limite.

## Constantes

```php
const DEFAULT_LIMIT = 20;
const MAX_LIMIT     = 100;
```

## Pagination par offset

```php
GET /articles?page=1&limit=10
→ 200
{
  "data": [...],      // 10 articles
  "total": 25,
  "limit": 10,
  "page": 1,
  "has_more": true
}
```

```php
// page 3 sur 25 articles à limit=10 → dernière page
GET /articles?page=3&limit=10
→ 200  {"data": [...], "has_more": false}  // 5 articles
```

**Calcul de l'OFFSET** : `(page - 1) * limit` — la page doit être ≥ 1 pour éviter un OFFSET négatif.

## Pagination par curseur

```php
GET /articles/cursor?limit=5
→ 200  {"data": [...], "next_cursor": 42, "has_more": true}

GET /articles/cursor?after=42&limit=5
→ 200  {"data": [...], "next_cursor": 37, "has_more": true}

GET /articles/cursor?after=37&limit=5
→ 200  {"data": [...], "next_cursor": null, "has_more": false}
```

Le curseur est l'`id` du dernier article : `WHERE id < $after ORDER BY id DESC LIMIT $limit`.

## Filtre par auteur

```php
GET /articles/by-author?author_id=2&limit=10
→ 200  {"data": [...]}  // uniquement les articles avec author_id = 2
```

`author_id` doit être un entier positif (même validation que `limit`).

## Validation de limite — Pattern `ctype_digit`

Utiliser `ctype_digit()` pour une validation O(n) — immunisée contre les ReDoS contrairement à la regex `^\d+$` :

```php
/**
 * Analyser un paramètre entier de chaîne de requête.
 * Rejette : zéro, négatif, float, dépassement, non numérique, espaces.
 */
function parseQueryInt(string $raw, int $min, int $max): int
{
    // Rejeter vide, floats, signes, espaces, caractères non chiffres
    if ($raw === '' || !ctype_digit($raw)) {
        throw new ValidationException(/* 422 */);
    }
    // Garde contre le dépassement 64 bits avant le cast
    if (strlen($raw) > 18) {
        throw new ValidationException(/* 422 */);
    }
    $val = (int) $raw;
    if ($val < $min || $val > $max) {
        throw new ValidationException(/* 422 */);
    }
    return $val;
}
```

### Ce que `ctype_digit` bloque

| Entrée | `ctype_digit` | Pourquoi |
|--------|--------------|----------|
| `"10"` | ✅ Passe | Chiffres valides |
| `"0"` | ✅ Passe (ctype) | Rejeté par la vérification min=1 |
| `"-1"` | ❌ Rejette | `-` n'est pas un chiffre |
| `"10.5"` | ❌ Rejette | `.` n'est pas un chiffre |
| `"1e2"` | ❌ Rejette | `e` n'est pas un chiffre |
| `"+10"` | ❌ Rejette | `+` n'est pas un chiffre |
| `" 10"` | ❌ Rejette | l'espace n'est pas un chiffre |
| `"0x10"` | ❌ Rejette | `x` n'est pas un chiffre |
| `"10\x00"` | ❌ Rejette | l'octet nul n'est pas un chiffre |
| Chaîne de 20 chiffres | ❌ Rejette | garde strlen > 18 |
| Payload ReDoS `"1...1x"` | ❌ Rejette (rapide) | scan O(n), pas de retour arrière |

### Cas d'erreur

```php
GET /articles?limit=999999  → 422  // dépasse MAX_LIMIT
GET /articles?limit=0       → 422  // min=1
GET /articles?limit=-1      → 422  // pas ctype_digit
GET /articles?limit=10.5    → 422  // float
GET /articles?limit=abc     → 422  // non numérique
GET /articles?page=0        → 422  // OFFSET négatif
GET /articles/cursor?after=99999999999999999999  → 422  // dépassement
```

## Attaque par paramètre dupliqué

```php
GET /articles?limit=5&limit=1000
// PHP prend la dernière valeur : 1000 → dépasse MAX_LIMIT → 422
```

La plupart des implémentations PSR-7 prennent la dernière occurrence. Soit 422 (dernière valeur au-dessus de MAX) soit 200 avec la valeur valide est acceptable — ne jamais utiliser silencieusement 1000.

## Grand numéro de page

```php
GET /articles?page=999999&limit=10
→ 200  {"data": [], "has_more": false}  // vide, pas un crash
```

Une énorme page qui dépasse le total est valide — elle retourne des données vides, pas une erreur.

---

## À ne pas faire

| Anti-pattern | Risque |
|---|---|
| `(int) $raw` sans `ctype_digit` | `-1`, `1.5`, `" 10"` se castent tous silencieusement en entiers |
| Regex `/^\d+$/` pour la validation d'entier | Retour arrière catastrophique (ReDoS) sur les longues entrées mixtes |
| Pas de plafond MAX_LIMIT | `limit=999999` vide la table entière en une seule requête |
| Permettre `page=0` | `OFFSET = (0-1)*limit = -limit` corrompt ou génère une erreur dans la requête SQL |
| Garde de dépassement par strlen uniquement | `"1.5"` fait 3 caractères — assez court pour passer mais pas un entier valide |
| Pas de vérification minimum sur `author_id` | `author_id=0` retourne un résultat vide silencieusement ; sémantiquement invalide |
