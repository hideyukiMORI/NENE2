# Comment prévenir l'injection SQL ORDER BY

Les clauses SQL `ORDER BY` ne peuvent pas être paramétrées avec des placeholders standard (`?`). Cela signifie
que les colonnes de tri et les directions contrôlées par l'utilisateur ne doivent jamais être interpolées directement dans le SQL.
Ce guide explique la seule approche sûre : une liste blanche explicite.

---

## Le problème

Les placeholders de requêtes préparées protègent les valeurs de colonnes dans les clauses `WHERE`, mais ils ne
fonctionnent **pas** pour les noms de colonnes ou les directions de tri dans `ORDER BY` :

```php
// ❌ INCORRECT — ceci ne protège PAS contre l'injection
$stmt = $pdo->prepare("SELECT * FROM articles ORDER BY ? ?");
$stmt->execute([$column, $direction]);
// Beaucoup de drivers DB traitent les arguments ORDER BY comme des littéraux, pas des identifiants.
```

Un attaquant envoyant `?sort=SLEEP(5)` ou `?sort=(SELECT password FROM users LIMIT 1)` peut
causer des attaques basées sur le temps, des fuites d'informations, ou des erreurs révélant les détails du schéma.

---

## La seule solution sûre : liste blanche explicite

```php
// ✅ SÛR — liste blanche + in_array strict
public const array SORT_COLUMNS = ['id', 'title', 'status', 'created_at'];
public const array SORT_DIRS    = ['asc', 'desc'];

$sql = "SELECT * FROM articles ORDER BY {$sortCol} {$sortDir} LIMIT ?";
```

Les valeurs de la liste blanche sont des **chaînes codées en dur** que vous contrôlez. Seules ces valeurs atteignent jamais le SQL.

---

## Pattern complet de handler de route

```php
// ── Colonne de tri — DOIT être validée contre la liste blanche ─────────────────────────
//
// SÉCURITÉ : ORDER BY ne supporte pas les placeholders ? en SQL standard.
// La SEULE approche sûre est une liste blanche explicite vérifiée avec in_array strict.
//
$rawSort = $params['sort'] ?? null;

if ($rawSort !== null) {
    // Injection de tableau : PSR-7 peut donner un tableau pour ?sort[]=id
    if (!is_string($rawSort)) {
        return $this->responseFactory->create(['error' => 'sort must be a string.'], 422);
    }

    // Vérification d'octet nul — PSR-7 décode %00 en l'octet nul réel
    if (str_contains($rawSort, "\0")) {
        return $this->responseFactory->create(['error' => 'sort contains invalid characters.'], 422);
    }

    // Vérification de liste blanche — strict, sensible à la casse.
    // PSR-7 décode déjà les query strings une fois (%65 → e), donc les noms de colonnes
    // valides encodés simplement sont acceptés. Les valeurs doublement encodées (%2565 → %65 dans $rawSort)
    // ne sont PAS décodées une seconde fois, donc elles échouent la liste blanche et sont rejetées.
    if (!in_array($rawSort, MyRepository::SORT_COLUMNS, true)) {
        return $this->responseFactory->create(
            ['error' => sprintf('sort must be one of: %s.', implode(', ', MyRepository::SORT_COLUMNS))],
            422,
        );
    }

    $sortCol = $rawSort;
} else {
    $sortCol = 'created_at';  // valeur par défaut sûre
}

// ── Direction de tri — liste blanche uniquement ───────────────────────────────────────────
$rawOrder = $params['order'] ?? null;

if ($rawOrder !== null) {
    if (!is_string($rawOrder)) {
        return $this->responseFactory->create(['error' => 'order must be a string.'], 422);
    }

    $dir = strtolower(trim($rawOrder));

    if (!in_array($dir, MyRepository::SORT_DIRS, true)) {
        return $this->responseFactory->create(
            ['error' => sprintf('order must be one of: %s.', implode(', ', MyRepository::SORT_DIRS))],
            422,
        );
    }

    $sortDir = $dir;
} else {
    $sortDir = 'desc';  // valeur par défaut sûre
}
```

---

## Couche Repository

Le repository reçoit des valeurs déjà validées et les interpole directement :

```php
/**
 * $sortCol et $sortDir DOIVENT être vérifiés par liste blanche par l'appelant.
 * Cette méthode leur fait confiance et les interpole directement dans le SQL.
 *
 * @return array{data: list<Article>, total: int, sort: string, order: string, limit: int}
 */
public function list(string $sortCol, string $sortDir, ?ArticleStatus $status, int $limit): array
{
    $where  = $status !== null ? 'WHERE status = ?' : '';
    $params = $status !== null ? [$status->value] : [];

    // $sortCol et $sortDir sont pré-validés — sûrs à interpoler.
    // Ne jamais mettre ici l'entrée utilisateur brute.
    $sql  = "SELECT * FROM articles {$where} ORDER BY {$sortCol} {$sortDir} LIMIT ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([...$params, $limit]);
    ...
}
```

---

## Patterns d'attaque bloqués par cette approche

| Attaque | Entrée | Résultat |
|---|---|---|
| Injection DROP TABLE | `?sort='; DROP TABLE articles--` | 422 — pas dans la liste blanche |
| Exfiltration UNION SELECT | `?sort=1; SELECT password` | 422 — pas dans la liste blanche |
| Extraction par sous-requête | `?sort=(SELECT name FROM sqlite_master)` | 422 — pas dans la liste blanche |
| Blind basé sur le temps | `?sort=SLEEP(5)` | 422 — pas dans la liste blanche |
| Injection d'index de colonne | `?sort=1` | 422 — pas dans la liste blanche |
| Colonne inconnue | `?sort=password` | 422 — pas dans la liste blanche |
| Contournement par casse/commentaire | `?sort=CREATED_AT--` | 422 — sensible à la casse |
| Contournement par octet nul | `?sort=created_at%00` | 422 — vérification d'octet nul |
| Injection de tableau | `?sort[]=created_at` | 422 — vérification de type |
| Double encodage URL | `?sort=cr%2565ated_at` | 422 — PSR-7 décode une fois ; `cr%65ated_at` pas dans la liste blanche |
| Encodage URL simple (valide) | `?sort=cr%65ated_at` | 200 — PSR-7 décode en `created_at` ✓ |
| Injection de direction | `?order=asc; UNION SELECT 1--` | 422 — pas dans la liste blanche |

---

## Points clés

1. **Pas de `rawurldecode()` après PSR-7** : `getQueryParams()` de PSR-7 décode déjà la query string
   une fois. Appeler `rawurldecode()` à nouveau permettrait aux valeurs doublement encodées de passer
   la vérification de liste blanche.

2. **`in_array($value, $allowlist, true)`** : Le troisième argument `true` active la comparaison stricte
   (sûre au niveau des types). Sans lui, `in_array(0, ['id', 'created_at'])` retourne `true`
   car PHP force les chaînes en entiers.

3. **Vérification sensible à la casse** : Les noms de colonnes doivent être en minuscules et correspondre exactement. Ne jamais
   utiliser `strcasecmp` ou `strtolower` avant la vérification de liste blanche — `CREATED_AT` n'est pas
   le même token que `created_at` du point de vue de la confiance.

4. **Direction : `strtolower(trim())` est sûr** : Contrairement aux noms de colonnes, la direction (`asc`/`desc`)
   n'a que deux valeurs valides. Normaliser la casse avant la vérification de liste blanche est acceptable car
   la liste blanche elle-même est exhaustive et en minuscules.

5. **Documenter le contrat** : La méthode du repository doit documenter qu'elle fait confiance à ses entrées.
   Les appelants ne doivent jamais passer l'entrée utilisateur brute.

---

## Connexes

- FT180 — sortlog : injection SQL ORDER BY & prévention du tri/filtre dynamique
- [RFC 3986](https://www.rfc-editor.org/rfc/rfc3986) — Encodage URI
- [PSR-7](https://www.php-fig.org/psr/psr-7/) — `ServerRequestInterface::getQueryParams()`
