# How-to : API de lot avec succès partiel

> **Référence FT** : FT294 (`NENE2-FT/batchlog`) — INSERT en lot avec succès partiel : garde MAX_BATCH=50, validation indépendante par élément avec suivi d'index, réponse mixed created/errors (toujours 200), contraintes CHECK DB, validation de type JSON stricte via `is_int()`, 36 tests / 79 assertions PASS.
>
> **Précurseur FT** : FT182 (première couverture batchlog).

Quand les clients soumettent un tableau d'éléments dans une seule requête, certains éléments peuvent être valides et d'autres invalides. Rejeter tout le lot sur n'importe quelle erreur gaspille les éléments valides ; ignorer silencieusement les erreurs cache les bugs. Le pattern _succès partiel_ accepte ce qu'il peut et signale ce qu'il ne peut pas — par élément, par index.

---

## Le problème central

Les corps de tableau JSON introduisent deux couches de validation :

1. **Niveau lot** — La forme globale de la requête est-elle valide ? (clé présente ? est-ce une liste ? le comptage est-il dans la plage ?)
2. **Niveau élément** — Chaque élément individuel est-il valide ? (type ? plage ? champs requis ?)

Traiter les deux couches de la même façon mène soit au sur-rejet (un mauvais élément tue tout le lot) soit à la sur-acceptation (les mauvais éléments sont silencieusement ignorés).

---

## Conventions HTTP

| Scénario | Statut | Corps |
|---|---|---|
| Erreur au niveau lot (clé manquante, mauvais type, vide, surdimensionné) | `422` | `{"error": "..."}` |
| Erreurs au niveau élément uniquement / mix succès+erreur | `200` | `{created, errors, total_created, total_errors}` |
| Tous les éléments valides | `200` | `{created: [...], errors: [], ...}` |
| Tous les éléments invalides | `200` | `{created: [], errors: [...], ...}` |

**Pourquoi 200 pour tout-invalide ?** L'opération de lot elle-même a réussi — le serveur a traité chaque élément et pris une décision sur chacun. L'appelant sait ce qui s'est passé en inspectant `total_created` et `errors`. Utiliser 422 pour "certains éléments invalides" confondrait deux types d'échec différents.

---

## V::bodyInt() — Application stricte du type JSON

`V::bodyInt()` est l'outil clé pour détecter la confusion de types JSON dans les payloads de lot. `json_decode` de PHP préserve les types JSON, mais les appelants peuvent envoyer de mauvais types par accident (ou intentionnellement).

```php
// V::bodyInt(mixed $raw, int $min, int $max): ?int
V::bodyInt(5, 1, 999)         // → 5        ✓ PHP int
V::bodyInt("5", 1, 999)       // → null     ✗ confusion de type JSON : "5" n'est pas 5
V::bodyInt(5.5, 1, 999)       // → null     ✗ float
V::bodyInt(true, 1, 999)      // → null     ✗ bool
V::bodyInt(null, 1, 999)      // → null     ✗ null
V::bodyInt([5], 1, 999)       // → null     ✗ array
```

La différence critique avec les chaînes de requête : `V::queryInt()` accepte la chaîne `"5"` (car les paramètres de requête sont toujours des chaînes), tandis que `V::bodyInt()` nécessite un `int` PHP (car JSON distingue `5` de `"5"`).

**Attaque de confusion de types ATK-07** — envoyer `{"quantity": "5"}` au lieu de `{"quantity": 5}` doit échouer. `is_int()` est la seule vérification sûre.

---

## Logique de validation de lot

```php
// 1. Analyser le corps (repli sur [] pour un JSON non-objet)
$body = json_decode((string) $request->getBody(), true);
$body = is_array($body) ? $body : [];

// 2. Gardes au niveau lot → 422
if (!array_key_exists('items', $body)) {
    return 422; // clé manquante
}
$rawItems = $body['items'];
if (!is_array($rawItems)) {
    return 422; // pas un tableau
}
if (count($rawItems) === 0) {
    return 422; // vide
}
if (count($rawItems) > MAX_BATCH) {
    return 422; // surdimensionné
}

// 3. Traitement par élément → 200 avec errors[]
$created = [];
$errors  = [];

foreach ($rawItems as $index => $rawItem) {
    $intIndex = (int) $index;

    // Chaque élément doit être un objet JSON (tableau assoc), pas un scalaire ou une liste
    if (!is_array($rawItem) || array_is_list($rawItem)) {
        $errors[] = ['index' => $intIndex, 'error' => 'Each item must be a JSON object.'];
        continue;
    }

    $name = V::str($rawItem['name'] ?? null, 100);
    if ($name === null || $name === '') {
        $errors[] = ['index' => $intIndex, 'error' => 'name is required (max 100 chars).'];
        continue;
    }

    $quantity = V::bodyInt($rawItem['quantity'] ?? null, 1, 999);
    if ($quantity === null) {
        $errors[] = ['index' => $intIndex, 'error' => 'quantity must be an integer between 1 and 999.'];
        continue;
    }

    // … d'autres champs …

    $item      = $repository->create(/* ... */);
    $created[] = $item->toArray();
}

// 4. Toujours 200 ; l'appelant lit total_created / total_errors
return 200 with [
    'created'       => $created,
    'errors'        => $errors,
    'total_created' => count($created),
    'total_errors'  => count($errors),
];
```

---

## array_is_list() — Objet JSON vs Tableau JSON au niveau élément

`json_decode` de PHP mappe les objets JSON en tableaux associatifs et les tableaux JSON en tableaux liste. Utiliser `array_is_list()` pour les distinguer au niveau élément :

```php
// Corps JSON : {"items": [{"name": "foo"}, "bar", 42, [1,2]]}
is_array(["name" => "foo"])   // true — objet JSON valide
array_is_list(["name" => "foo"]) // false — associatif → objet ✓

is_array("bar")                  // false → attrapé par la vérification is_array
is_array(42)                     // false → attrapé
is_array([1, 2])                 // true
array_is_list([1, 2])            // true → rejeté : liste ≠ objet ✗
```

La garde `!is_array($rawItem) || array_is_list($rawItem)` attrape les scalaires, les tableaux JSON et tout ce qui n'est pas un objet JSON ordinaire.

---

## Garde de taille MAX_BATCH

Sans limite supérieure, un appelant pourrait envoyer des milliers d'éléments dans une seule requête, consommant une mémoire et un CPU illimités.

```php
const MAX_BATCH = 50; // ajuster selon votre cas d'utilisation

if (count($rawItems) > self::MAX_BATCH) {
    return $this->responseFactory->create(
        ['error' => sprintf('"items" must contain at most %d entries.', self::MAX_BATCH)],
        422,
    );
}
```

Rejeter au niveau lot (422) avant d'itérer — ne pas compter les erreurs par élément pour un lot surdimensionné.

---

## Préservation de l'index d'erreur

Signaler l'index d'entrée original dans chaque erreur pour que les clients puissent corréler les erreurs avec les éléments qu'ils ont soumis, même quand les indices de tableau ne sont pas séquentiels (ex. après un filtrage côté client) :

```php
// Entrée :  [valide, invalide, valide, invalide]
// Erreurs de sortie : [{index: 1, error: "..."}, {index: 3, error: "..."}]
```

Toujours caster l'index en `int` explicitement — les clés `foreach` peuvent être `string` quand le tableau PHP a été construit à partir d'un JSON non-séquentiel :

```php
$intIndex = (int) $index;
```

---

## Schéma de réponse

```json
{
  "created": [
    {"id": 1, "user_id": 1, "name": "Widget A", "quantity": 3, "price_cents": 999, "created_at": "..."},
    {"id": 2, "user_id": 1, "name": "Widget B", "quantity": 1, "price_cents": 4999, "created_at": "..."}
  ],
  "errors": [
    {"index": 1, "error": "quantity must be an integer between 1 and 999."},
    {"index": 3, "error": "name is required (max 100 chars)."}
  ],
  "total_created": 2,
  "total_errors": 2
}
```

---

## Considération d'idempotence

Le succès partiel crée un scénario écriture-puis-erreur. Si le client réessaie le lot complet après une défaillance réseau, les éléments précédemment créés peuvent être dupliqués. Options :

- **Clé d'idempotence** : inclure un UUID généré par le client par lot ; le serveur le stocke et déduplique.
- **Déduplication client** : le client trace quels index ont réussi et ne resoume que les éléments échoués.
- **Unicité naturelle** : utiliser une contrainte unique (ex. ID externe) et traiter les erreurs de clé dupliquée comme un succès.

Le FT `batchlog` utilise l'approche la plus simple (pas de clé d'idempotence) pour la clarté. Les API de lot en production devraient implémenter l'une des stratégies ci-dessus.

---

## Notes de sécurité

- **V::bodyInt() pour tous les champs numériques** — rejeter les chaînes, floats, bools, null dans le corps JSON.
- **V::str() pour les champs chaîne** — rejette les non-chaînes, coupe les espaces, vérifie la longueur ; vérifier `=== ''` pour les champs requis après le trim.
- **Scope utilisateur** — chaque élément est lié à l'ID utilisateur authentifié depuis l'en-tête (`V::userId()`), jamais depuis le corps de la requête.
- **Garde MAX_BATCH** — 422 avant d'itérer pour prévenir les DoS via des lots surdimensionnés.

---

## Points clés

| Pattern | Règle |
|---|---|
| Erreur au niveau lot | 422 — toute la requête rejetée |
| Erreur au niveau élément | 200 — signaler index + message dans `errors[]` |
| Confusion de types en JSON | `V::bodyInt()` / `is_int()` — pas `is_numeric()` |
| Objet JSON vs tableau | `!is_array() \|\| array_is_list()` — rejeter les deux |
| DoS par taille | `count($items) > MAX_BATCH` → 422 avant itération |
| Corrélation d'erreurs | Préserver l'`$index` original dans la réponse d'erreur |
