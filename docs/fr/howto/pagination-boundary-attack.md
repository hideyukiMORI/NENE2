# How-to : Attaque sur les bornes de pagination et injection de limite

**FT177 — limitlog**

Validation de paramètres entiers infaillible pour la pagination par offset et par curseur —
prévenant les dumps de DB, les dépassements, la confusion de types et les ReDoS.

---

## La surface d'attaque

Tout endpoint de pagination expose au moins deux paramètres entiers (`limit`, `page` / `after`).
Les attaquants sondent routinièrement ces paramètres avec :

| Attaque | Exemple | Risque |
|---------|---------|--------|
| Limite surdimensionnée | `limit=999999` | Dump de table complète |
| Zéro/négatif | `limit=0`, `limit=-1` | OFFSET négatif → erreur DB ou enroulement |
| Injection float | `limit=10.5`, `limit=1e2` | Cast silencieux : `(int)"10.5" === 10` |
| Padded / signé | `limit=+10`, `limit= 10` | Trim silencieux : `(int)" 10" === 10` |
| Dépassement entier | `limit=99999999999999999999` | Enroulement 64 bits vers négatif |
| Non numérique | `limit=abc`, `limit=1;DROP TABLE` | Erreur de type ou injection |
| Hex / octal | `limit=0x10`, `limit=010` | `0x` → échoue ctype ; `010` passe ! |
| Paramètre dupliqué | `?limit=5&limit=1000` | La dernière valeur masque celle validée |
| Payload ReDoS | `limit=111...1x` | Retour arrière exponentiel de regex |

---

## Le pattern `clampInt()`

```php
/**
 * @param array<string, mixed> $params
 */
private function clampInt(array $params, string $key, ?int $default, int $min, int $max): ?int
{
    if (!array_key_exists($key, $params)) {
        return $default;  // absent → utiliser le défaut (pas null = invalide)
    }

    $raw = $params[$key];

    // ctype_digit : O(n), immunisé contre ReDoS, rejette '' / '-' / '.' / '+' / ' ' / 'e'
    // ctype_digit('') === false  →  chaîne vide déjà rejetée
    if (!is_string($raw) || !ctype_digit($raw)) {
        return null;  // signal : l'appelant doit retourner 422
    }

    // Prévenir le dépassement silencieux PHP : (int)"99999999999999999999" s'enroule
    if (strlen($raw) > 18) {
        return null;
    }

    $value = (int) $raw;

    if ($value < $min || $value > $max) {
        return null;
    }

    return $value;
}
```

### Pourquoi `ctype_digit`, pas de regex

| Validateur | Résistant ReDoS ? | Rejette `010` ? | Rejette `+10` ? |
|------------|------------------|-----------------|-----------------|
| `/^\d+$/` | ❌ exponentiel sur `111...1x` | ✅ | ❌ |
| `ctype_digit()` | ✅ O(n) | ✅ (préfixe `0` : passe — mais capé par plage) | ✅ |
| `is_numeric()` | ✅ | ❌ | ❌ |
| `filter_var(FILTER_VALIDATE_INT)` | ✅ | ✅ | ❌ (`+10` passe !) |

**Utiliser `ctype_digit()`** — c'est le plus strict et le plus rapide.

### Le piège de `010`

`ctype_digit('010')` → `true` (passe la vérification de chiffres), `(int)'010'` → `10` (décimal, pas octal).
C'est sûr car PHP n'effectue pas d'interprétation octale sur les entiers castés depuis des chaînes
(contrairement à `010` comme littéral PHP). Confirmer dans les tests si votre équipe n'est pas sûre.

---

## Pagination par curseur

```php
// Récupérer une ligne supplémentaire pour déterminer has_more — pas de requête COUNT nécessaire
$rows = $this->db->fetchAll(
    'SELECT * FROM articles WHERE id < ? ORDER BY id DESC LIMIT ?',
    [$afterId, $limit + 1],
);

$hasMore = count($rows) > $limit;
if ($hasMore) {
    array_pop($rows);  // supprimer la ligne sentinelle
}

$nextCursor = $hasMore && count($rows) > 0 ? end($rows)->id : null;
```

### Sentinelle de curseur pour la "première page"

```php
private const int NO_CURSOR = PHP_INT_MAX;

// GET /articles/cursor (pas de paramètre ?after) → afterId vaut par défaut PHP_INT_MAX
// WHERE id < PHP_INT_MAX  ==>  effectivement toutes les lignes
```

---

## Pagination par offset — garde page zéro

`page=0` produit `OFFSET = (0-1) * limit = -limit` — un OFFSET négatif est une erreur SQL
dans certaines bases de données (MySQL le rejette) ou s'enroule silencieusement dans d'autres.

```php
$page  = $this->clampInt($params, 'page', 1, 1, PHP_INT_MAX);
// min=1 → page=0 retourne null → 422
```

---

## Garde contre le dépassement d'entier

Le cast `(int)` de PHP sur une chaîne de 20 chiffres s'enroule silencieusement :

```php
(int)'99999999999999999999'  // === -1 sur PHP 64 bits
```

La garde `strlen($raw) > 18` prévient cela avant le cast. 18 chiffres couvre en toute sécurité
`PHP_INT_MAX` (19 chiffres) avec une marge pour que le cast soit toujours sûr.

---

## Liste de vérification VULN-A à VULN-L

| # | Test | Attente |
|---|------|---------|
| VULN-A | `limit` au-dessus de MAX (100) | 422 — rejet explicite, pas troncature silencieuse |
| VULN-B | `limit=0`, `limit=-1` | 422 — `0` échoue min=1 ; `-` échoue ctype_digit |
| VULN-C | Chaîne float `10.5`, `1e2`, `1.0` | 422 — `.` et `e` échouent ctype_digit |
| VULN-D | Padded `%2010`, `10%20`, `%2B10` | 422 — espace/`+` échouent ctype_digit |
| VULN-E | Dépassement `9999...` (20 chiffres) | 422 — garde strlen > 18 |
| VULN-F | Non numérique, hex `0x10`, injection SQL | 422 — ctype_digit rejette tout |
| VULN-G | `page=0` (pagination par offset) | 422 — garde min=1 |
| VULN-H | Borne curseur : `after=0` valide, curseur débordant 422 | Mixte |
| VULN-I | `author_id=0`, `-1`, `abc`, `1.5` | 422 |
| VULN-J | Très grande page (page=999999) | 200 vide — ne doit pas planter |
| VULN-K | Paramètre dupliqué `?limit=5&limit=1000` | 200 (sûr) ou 422 — jamais > MAX |
| VULN-L | Payload ReDoS `111...1x` (50 chiffres + x) | 422 en < 100ms |

---

## Note de test : VULN-J vs VULN-A

Ces cas semblent contradictoires mais servent des objectifs différents :

- **VULN-A** : `limit=999999` → **422** — rejeter un nombre de lignes déraisonnablement grand
- **VULN-J** : `page=999999&limit=10` → **200 vide** — une page valide qui n'a simplement pas de données

Le serveur ne doit pas planter ou générer d'erreur sur une page sémantiquement valide mais pratiquement vide.
`OFFSET = (999999-1) * 10 = 9999980` est un OFFSET SQL légal ; le résultat est simplement vide.
