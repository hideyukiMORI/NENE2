# How-to : Pattern Idempotency-Key

> **Référence FT** : FT276 (`NENE2-FT/csrflog`) — En-tête Idempotency-Key pour les requêtes de mutation : contrainte DB UNIQUE, le replay retourne le résultat original (200), les changements de corps lors du replay sont ignorés, condition de course gérée par DatabaseConstraintException, 15 tests / 30 assertions PASS.
>
> **ATK assessment** : ATK-01 à ATK-12 inclus à la fin de ce document.

Prévenir les commandes dupliquées ou la création de ressources causées par les retentatives réseau en exigeant des clients qu'ils fournissent un en-tête `Idempotency-Key` sur chaque requête de mutation.

## Pourquoi c'est important

Quand un client envoie `POST /orders` et que le réseau tombe avant de recevoir une réponse, il va retenter. Sans idempotence, cette retentative crée une deuxième commande. Avec un `Idempotency-Key`, le serveur peut détecter la retentative et retourner le résultat original au lieu de créer un doublon.

Stripe, GitHub et beaucoup d'autres APIs de production utilisent exactement ce pattern.

## Schéma de base de données

Ajouter une contrainte `UNIQUE` sur la colonne de clé d'idempotence. Cette seule contrainte gère la condition de course décrite ci-dessous.

```sql
CREATE TABLE orders (
    id               INTEGER PRIMARY KEY AUTOINCREMENT,
    idempotency_key  TEXT    NOT NULL UNIQUE,
    item             TEXT    NOT NULL,
    quantity         INTEGER NOT NULL,
    total_price      REAL    NOT NULL,
    created_at       TEXT    NOT NULL
);
```

## Implémentation du gestionnaire

```php
// 1. Lire et valider l'en-tête
$key = trim($request->getHeaderLine('Idempotency-Key'));
if ($key === '') {
    return $problems->create(
        $request,
        'missing-idempotency-key',
        'Idempotency-Key header is required for this endpoint.',
        [],
        422,
    );
}

// 2. Vérifier une entrée existante (chemin de replay)
$existing = $repo->findByIdempotencyKey($key);
if ($existing !== null) {
    return $json->create($existing->toArray(), 200); // replay — retourner l'original avec 200
}

// 3. Valider le corps de la requête
$body = json_decode((string) $request->getBody(), true);
// ... valider les champs ...

// 4. Créer — la contrainte UNIQUE gère la condition de course
try {
    $order = $repo->create($key, $item, $quantity, $totalPrice);
    return $json->create($order->toArray(), 201);
} catch (DatabaseConstraintException) {
    // Une autre requête avec la même clé a gagné la course — retourner son résultat
    $existing = $repo->findByIdempotencyKey($key);
    if ($existing !== null) {
        return $json->create($existing->toArray(), 200);
    }
    return $problems->create($request, 'conflict', 'Conflict.', [], 409);
}
```

## Repository

```php
public function findByIdempotencyKey(string $key): ?Order
{
    $row = $this->executor->fetchOne(
        'SELECT * FROM orders WHERE idempotency_key = ?',
        [$key],
    );
    return $row !== null ? Order::fromRow($row) : null;
}

public function create(string $key, string $item, int $quantity, float $totalPrice): Order
{
    // Lève DatabaseConstraintException sur violation UNIQUE (condition de course)
    $this->executor->insert(
        'INSERT INTO orders (idempotency_key, item, quantity, total_price, created_at) VALUES (?, ?, ?, ?, ?)',
        [$key, $item, $quantity, $totalPrice, (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM)],
    );
    // ...
}
```

## Décisions de conception clés

### Le replay retourne 200, pas 201

La deuxième requête est un replay, pas une création. Utiliser `200 OK` indique au client "vous avez déjà vu ça" sans créer de confusion sur ce qui a été créé.

### Le replay ignore le corps

Si le client envoie la même `Idempotency-Key` avec un corps différent, le résultat **original** est retourné. Le serveur traite une clé correspondante comme preuve que la requête a déjà été traitée, indépendamment de ce que le corps dit.

```
POST /orders  Idempotency-Key: uuid-abc  body: {quantity: 1, price: 9.99}
→ 201 Created  {id: 1, quantity: 1}

POST /orders  Idempotency-Key: uuid-abc  body: {quantity: 99, price: 0.01}
→ 200 OK  {id: 1, quantity: 1}   ← commande originale, corps ignoré
```

C'est intentionnel. Si le client veut créer une ressource vraiment différente, il doit utiliser une nouvelle clé.

### Contrainte UNIQUE comme garde contre les conditions de course

Deux requêtes concurrentes avec la même clé s'engagent dans une course. La contrainte `UNIQUE` de la DB assure qu'un seul insert réussit. Le perdant attrape `DatabaseConstraintException` et récupère la ligne du gagnant.

## Ce que les clients devraient utiliser comme clé

UUID v4 est le choix le plus courant. Le client génère la clé avant d'envoyer la requête et la stocke localement pour pouvoir retenter avec la même clé si nécessaire.

```js
// Côté client (JavaScript)
const key = crypto.randomUUID();
const response = await fetch('/orders', {
    method: 'POST',
    headers: {
        'Content-Type': 'application/json',
        'Idempotency-Key': key,
    },
    body: JSON.stringify({ item: 'Widget', quantity: 1, price: 9.99 }),
});
```

## Lecture de l'en-tête

Les noms d'en-têtes PSR-7 sont insensibles à la casse. `getHeaderLine('Idempotency-Key')`, `getHeaderLine('idempotency-key')` et `getHeaderLine('IDEMPOTENCY-KEY')` retournent tous la même valeur. NENE2 utilise Nyholm/PSR-7 qui implémente correctement ceci.

---

## ATK Assessment — Test d'attaque mentalité cracker

### ATK-01 — Omettre Idempotency-Key pour contourner la vérification de doublon 🚫 BLOCKED

**Attaque** : Envoyer `POST /orders` sans l'en-tête `Idempotency-Key`.
**Résultat** : BLOCKED — `trim($request->getHeaderLine('Idempotency-Key')) === ''` → 422 avec le problem detail `missing-idempotency-key`. Aucune commande n'est créée.

---

### ATK-02 — Envoyer une Idempotency-Key vide 🚫 BLOCKED

**Attaque** : Envoyer `Idempotency-Key: ` (espaces blancs seulement).
**Résultat** : BLOCKED — `trim()` réduit les chaînes d'espaces blancs à `''` → même 422 que ATK-01.

---

### ATK-03 — Replay avec corps modifié pour changer le contenu de la commande 🚫 BLOCKED

**Attaque** : Envoyer `POST /orders` avec la clé `uuid-abc` et `{quantity: 1}`. Lors du replay, utiliser la même clé avec `{quantity: 99}`.
**Résultat** : BLOCKED — le serveur trouve la ligne existante par `idempotency_key` et la retourne immédiatement, avant de lire le corps. Le nouveau corps n'est jamais traité.

---

### ATK-04 — Créer deux commandes avec des clés différentes 🚫 BLOCKED (intentionnel)

**Attaque** : Utiliser deux valeurs `Idempotency-Key` différentes pour créer légitimement deux commandes.
**Résultat** : SAFE (par conception) — des clés différentes sont des requêtes différentes. Les deux commandes sont créées. C'est le comportement prévu : l'idempotence est par clé, pas par corps.

---

### ATK-05 — Condition de course : deux requêtes concurrentes avec la même clé 🚫 BLOCKED

**Attaque** : Envoyer deux requêtes identiques de façon concurrente avant que l'une se termine.
**Résultat** : BLOCKED — les deux requêtes passent la vérification `findByIdempotencyKey` (aucune ligne existante encore), mais seul un INSERT réussit. Le perdant attrape `DatabaseConstraintException`, récupère la ligne du gagnant et la retourne avec 200. La contrainte UNIQUE est la garde contre les conditions de course.

---

### ATK-06 — Injection de quantité négative 🚫 BLOCKED

**Attaque** : Envoyer `{item: "widget", quantity: -1, price: 9.99}` avec une clé valide.
**Résultat** : BLOCKED — `if ($quantity <= 0)` → erreur de validation 422. Aucune commande n'est créée.

---

### ATK-07 — Injection de quantité zéro 🚫 BLOCKED

**Attaque** : Envoyer `{item: "widget", quantity: 0, price: 9.99}`.
**Résultat** : BLOCKED — même garde `quantity <= 0` → 422.

---

### ATK-08 — Champs de corps requis manquants 🚫 BLOCKED

**Attaque** : Envoyer `{quantity: 1}` sans le champ `item`.
**Résultat** : BLOCKED — `if ($item === '')` → erreur de validation 422.

---

### ATK-09 — CSRF via requête navigateur cross-origin 🚫 BLOCKED (conception)

**Attaque** : Un site malveillant fait une requête `POST /orders` cross-origin depuis un navigateur.
**Résultat** : BLOCKED (par conception) — les APIs JSON requièrent `Content-Type: application/json`. Les attaques CSRF navigateur ne peuvent envoyer que des corps form-encodés ou plain-text via `<form>` sans preflight. Un corps JSON déclenche un preflight CORS ; la politique CORS du serveur détermine si les écritures cross-origin sont autorisées. De plus, exiger `Idempotency-Key` fournit une protection secondaire car les requêtes falsifiées ne peuvent pas prédire une clé unique.

---

### ATK-10 — Injection de prix négatif 🚫 BLOCKED

**Attaque** : Envoyer `{item: "widget", quantity: 1, price: -100.0}`.
**Résultat** : BLOCKED — `if ($price < 0)` → erreur de validation 422.

---

### ATK-11 — Coercition float/string de quantité 🚫 BLOCKED

**Attaque** : Envoyer `{quantity: "1"}` ou `{quantity: 1.5}` (chaîne ou float).
**Résultat** : BLOCKED — `is_int($body['quantity'])` rejette les chaînes et les floats ; `1.5` est float → 422.

---

### ATK-12 — Injection SQL via Idempotency-Key 🚫 BLOCKED

**Attaque** : Envoyer `Idempotency-Key: '; DROP TABLE orders; --`.
**Résultat** : BLOCKED — la clé n'est utilisée que dans des requêtes paramétrées (`WHERE idempotency_key = ?`). L'injection SQL via la valeur d'en-tête n'est pas possible.

---

### Résumé ATK

| ID | Attaque | Résultat |
|----|---------|----------|
| ATK-01 | Idempotency-Key manquant | 🚫 BLOCKED |
| ATK-02 | Clé vide/uniquement espaces blancs | 🚫 BLOCKED |
| ATK-03 | Replay avec corps modifié | 🚫 BLOCKED |
| ATK-04 | Clés différentes = commandes différentes | ✅ SAFE (intentionnel) |
| ATK-05 | Condition de course sur la même clé | 🚫 BLOCKED |
| ATK-06 | Quantité négative | 🚫 BLOCKED |
| ATK-07 | Quantité zéro | 🚫 BLOCKED |
| ATK-08 | Champs de corps manquants | 🚫 BLOCKED |
| ATK-09 | CSRF via POST cross-origin | 🚫 BLOCKED |
| ATK-10 | Prix négatif | 🚫 BLOCKED |
| ATK-11 | Coercition float/string de quantité | 🚫 BLOCKED |
| ATK-12 | Injection SQL via en-tête de clé | 🚫 BLOCKED |

**12 BLOCKED / SAFE, 0 EXPOSED**
Le pattern Idempotency-Key, les requêtes paramétrées et la validation stricte `is_int()` préviennent tous les vecteurs d'attaque testés.
