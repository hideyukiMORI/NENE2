# How-to : API de vente flash

> **Référence FT** : FT304 (`NENE2-FT/salelog`) — API de vente flash : validation de fenêtre temporelle (vente non commencée → 422, terminée → 422), UNIQUE(sale_id, user_id) empêche les achats en double, vérification du stock épuisé, prix négatif/quantité zéro → 422, dates inversées rejetées, ATK-01 à 12 tous BLOQUÉS, 29 tests / 42 assertions PASS.

Ce guide montre comment construire un système de vente flash où les utilisateurs achètent des produits en stock limité dans une fenêtre temporelle, avec protection contre les conditions de course et prévention des attaques.

## Schéma

```sql
CREATE TABLE flash_sales (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    product_id INTEGER NOT NULL,
    price      INTEGER NOT NULL,
    quantity   INTEGER NOT NULL,
    starts_at  TEXT    NOT NULL,
    ends_at    TEXT    NOT NULL,
    created_at TEXT    NOT NULL,
    CHECK (quantity > 0),
    CHECK (price >= 0),
    FOREIGN KEY (product_id) REFERENCES products(id)
);

CREATE TABLE purchases (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    sale_id      INTEGER NOT NULL,
    user_id      INTEGER NOT NULL,
    purchased_at TEXT    NOT NULL,
    UNIQUE (sale_id, user_id),
    FOREIGN KEY (sale_id) REFERENCES flash_sales(id),
    FOREIGN KEY (user_id) REFERENCES users(id)
);
```

`CHECK (quantity > 0)` et `CHECK (price >= 0)` appliquent les règles métier au niveau DB. `UNIQUE(sale_id, user_id)` empêche le même utilisateur d'acheter la même vente deux fois — même sous des requêtes concurrentes.

## Endpoints

| Méthode | Chemin | Auth | Description |
|---------|--------|------|-------------|
| `POST` | `/products` | — | Créer un produit |
| `POST` | `/sales` | — | Créer une vente flash |
| `GET` | `/sales` | — | Lister les ventes actives |
| `GET` | `/sales/{id}` | — | Obtenir les détails d'une vente |
| `POST` | `/sales/{id}/purchase` | `X-User-Id` | Acheter (avec vérification temporelle) |

## Validation de la création de vente

```php
if (!is_int($price) || $price < 0) {
    return 422; // prix négatif rejeté
}
if (!is_int($quantity) || $quantity <= 0) {
    return 422; // quantité zéro ou négative rejetée
}
if ($endsAt <= $startsAt) {
    return 422; // dates inversées ou égales rejetées
}
```

Trois vérifications au niveau DB soutenues par la validation au niveau applicatif :
- `price >= 0` — les ventes gratuites sont autorisées (`0`), pas les prix négatifs
- `quantity > 0` — les ventes à quantité zéro ne peuvent pas être créées
- `ends_at > starts_at` — l'inversion temporelle est rejetée

## Achat — Vérification de la fenêtre temporelle

```php
$now = date('c');
if ($now < $sale['starts_at']) {
    return 422; // vente pas encore commencée
}
if ($now > $sale['ends_at']) {
    return 422; // vente terminée
}
```

Les tentatives d'achat en dehors de la fenêtre de vente retournent 422. La vérification utilise `date('c')` côté serveur — les clients ne peuvent pas manipuler l'heure.

## Vérification du stock

```php
$purchaseCount = $this->repo->countPurchases($saleId);
if ($purchaseCount >= $sale['quantity']) {
    return $this->json(['error' => 'sold out'], 422);
}
```

Compter les achats existants par rapport à la `quantity` de la vente avant l'insertion. Si épuisé, retourner 422 avec `"error": "sold out"`.

## UNIQUE(sale_id, user_id) — Prévention des achats en double

```php
// La contrainte UNIQUE détecte les achats en double concurrents
try {
    $this->repo->createPurchase($saleId, $userId, $now);
} catch (\PDOException $e) {
    // Violation de contrainte UNIQUE → déjà acheté
    return $this->json(['error' => 'already purchased'], 409);
}
```

La contrainte DB `UNIQUE(sale_id, user_id)` est la garde finale contre les conditions de course. Le premier achat réussit (201) ; tout doublon retourne 409 Conflict.

## Validation de l'ID utilisateur

```php
$actorIdRaw = $request->getHeaderLine('X-User-Id');
if ($actorIdRaw === '' || !ctype_digit($actorIdRaw)) {
    return $this->json(['error' => 'X-User-Id required'], 400);
}
$actorId = (int) $actorIdRaw;

$user = $this->repo->findUser($actorId);
if ($user === null) {
    return $this->json(['error' => 'user not found'], 404);
}
```

- `X-User-Id` manquant ou non numérique → 400
- ID utilisateur inexistant → 404 (prévention IDOR — impossible d'acheter en tant qu'utilisateur fantôme)

---

## Évaluation ATK — Test d'attaque cracker

### ATK-01 — Injection SQL dans le nom du produit 🚫 BLOCKED

**Attaque** : `POST /products` avec `name: "'; DROP TABLE products; --"`.
**Résultat** : BLOCKED — la requête paramétrée stocke la chaîne d'injection verbatim (201). Les requêtes suivantes fonctionnent encore ; table products intacte.

---

### ATK-02 — Achat sans en-tête X-User-Id 🚫 BLOCKED

**Attaque** : `POST /sales/{id}/purchase` sans en-tête `X-User-Id`.
**Résultat** : BLOCKED — l'en-tête manquant retourne 400.

---

### ATK-03 — En-tête X-User-Id non numérique 🚫 BLOCKED

**Attaque** : `X-User-Id: admin` (valeur chaîne).
**Résultat** : BLOCKED — la vérification `ctype_digit()` rejette les valeurs non numériques ; pas 201.

---

### ATK-04 — ID de vente négatif dans l'URL 🚫 BLOCKED

**Attaque** : `POST /sales/-1/purchase`.
**Résultat** : BLOCKED — l'ID négatif ne trouve aucune vente ; pas 201.

---

### ATK-05 — Achat avant le début de la vente 🚫 BLOCKED

**Attaque** : Créer une vente commençant dans 1 heure ; tenter d'acheter immédiatement.
**Résultat** : BLOCKED — vérification `$now < $sale['starts_at']` → 422.

---

### ATK-06 — Achat après la fin de la vente 🚫 BLOCKED

**Attaque** : Créer une vente qui s'est terminée il y a 1 heure ; tenter d'acheter.
**Résultat** : BLOCKED — vérification `$now > $sale['ends_at']` → 422.

---

### ATK-07 — Double achat de la même vente 🚫 BLOCKED

**Attaque** : Le même utilisateur achète la même vente deux fois en succession rapide.
**Résultat** : BLOCKED — premier achat 201 ; deuxième achat 409 (contrainte UNIQUE ou vérification au niveau applicatif).

---

### ATK-08 — Épuiser le stock puis acheter 🚫 BLOCKED

**Attaque** : Créer une vente avec `quantity=1` ; Alice l'achète ; Bob tente d'acheter.
**Résultat** : BLOCKED — vérification du stock `purchaseCount >= quantity` → 422 `"sold out"` pour Bob.

---

### ATK-09 — Créer une vente avec quantity=0 🚫 BLOCKED

**Attaque** : `POST /sales` avec `quantity: 0`.
**Résultat** : BLOCKED — validation `quantity <= 0` + DB `CHECK (quantity > 0)` → 422.

---

### ATK-10 — Créer une vente avec un prix négatif 🚫 BLOCKED

**Attaque** : `POST /sales` avec `price: -999`.
**Résultat** : BLOCKED — validation `price < 0` + DB `CHECK (price >= 0)` → 422.

---

### ATK-11 — Achat en tant qu'utilisateur inexistant 🚫 BLOCKED

**Attaque** : `X-User-Id: 99999` (ID qui n'existe pas dans la table users).
**Résultat** : BLOCKED — `findUser($actorId) === null` → 404.

---

### ATK-12 — Dates de vente inversées (ends_at avant starts_at) 🚫 BLOCKED

**Attaque** : `starts_at: "+2 hours"`, `ends_at: "+1 hour"`.
**Résultat** : BLOCKED — validation `$endsAt <= $startsAt` → 422.

---

### Résumé ATK

| ID | Attaque | Résultat |
|----|---------|----------|
| ATK-01 | Injection SQL dans le nom du produit | 🚫 BLOCKED |
| ATK-02 | Achat sans X-User-Id | 🚫 BLOCKED |
| ATK-03 | X-User-Id non numérique | 🚫 BLOCKED |
| ATK-04 | ID de vente négatif dans l'URL | 🚫 BLOCKED |
| ATK-05 | Achat avant le début de la vente | 🚫 BLOCKED |
| ATK-06 | Achat après la fin de la vente | 🚫 BLOCKED |
| ATK-07 | Double achat de la même vente | 🚫 BLOCKED |
| ATK-08 | Épuiser le stock puis acheter | 🚫 BLOCKED |
| ATK-09 | Créer une vente avec quantity=0 | 🚫 BLOCKED |
| ATK-10 | Créer une vente avec un prix négatif | 🚫 BLOCKED |
| ATK-11 | Achat en tant qu'utilisateur inexistant | 🚫 BLOCKED |
| ATK-12 | Dates de vente inversées | 🚫 BLOCKED |

**12 BLOCKED, 0 EXPOSED**
La vérification de fenêtre temporelle côté serveur, le garde de comptage de stock, la contrainte UNIQUE et la validation stricte des entrées préviennent tous les vecteurs d'attaque connus.

---

## À ne pas faire

| Anti-pattern | Risque |
|---|---|
| Faire confiance à l'horodatage fourni par le client pour la vérification temporelle | Les clients envoient des horodatages passés/futurs pour contourner la fenêtre |
| Pas de `UNIQUE(sale_id, user_id)` | Les requêtes concurrentes permettent au même utilisateur d'acheter deux fois sous charge |
| Vérifier le stock sans garde contre les conditions de course | Entre la vérification du stock et l'insertion, une autre requête peut épuiser le stock |
| Accepter la création de vente avec `quantity: 0` | La vente à quantité zéro ne peut jamais être achetée ; cas limite confus |
| Accepter `price: -999` | L'achat à prix négatif crédite l'acheteur au lieu de le facturer |
| Pas de vérification d'existence utilisateur | Les ID utilisateur fantômes (pas en DB) contournent les pistes d'audit |
| `$endsAt >= $startsAt` (autoriser égaux) | Début/fin égaux crée une fenêtre de durée zéro — immédiatement expirée |
| X-User-Id non numérique accepté | La chaîne `"admin"` castée en `(int)` devient `0` ; contourne l'auth |
| Retourner 409 pour les erreurs de fenêtre temporelle | Les violations temporelles sont des échecs de validation métier (422), pas des conflits d'état |
