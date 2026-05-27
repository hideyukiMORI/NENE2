# How-to : API d'historique des prix de produit

> **Référence FT** : FT67 (`NENE2-FT/pricelog`) — API d'historique des prix de produit
> **ATK** : FT228 — test d'attaque mentalité cracker (ATK-01 à ATK-12)

Démontre une API d'historique des prix où chaque produit maintient une timeline de paliers de prix (périodes effectives). Le prix actuel et le prix à tout moment donné peuvent être interrogés. La section ATK documente douze vecteurs d'attaque avec leurs verdicts.

---

## Routes

| Méthode | Chemin | Description |
|---------|--------|-------------|
| `POST` | `/products` | Créer un produit |
| `GET` | `/products` | Lister tous les produits |
| `GET` | `/products/{id}` | Obtenir un seul produit |
| `POST` | `/products/{id}/prices` | Définir un nouveau prix (ouvre un nouveau palier) |
| `GET` | `/products/{id}/prices` | Lister l'historique complet des prix |
| `GET` | `/products/{id}/prices/current` | Prix actuel actif |
| `GET` | `/products/{id}/prices/at` | Prix à un datetime spécifique (`?datetime=`) |

---

## Modèle de palier de prix

Chaque prix a un horodatage `effective_from` et `effective_to`. Un palier est "actif" quand :

```
effective_from <= now  AND  (effective_to IS NULL  OR  effective_to > now)
```

`effective_to IS NULL` signifie que le palier n'a pas encore de date de fin (intervalle ouvert).

```sql
CREATE TABLE price_tiers (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    product_id     INTEGER NOT NULL REFERENCES products(id),
    amount         INTEGER NOT NULL,       -- centimes (non négatif)
    currency       TEXT    NOT NULL DEFAULT 'USD',
    effective_from TEXT    NOT NULL,
    effective_to   TEXT,                  -- NULL = ouvert (actuel)
    created_at     TEXT    NOT NULL
);
```

---

## Définir un prix : fermer l'ancien palier, ouvrir un nouveau

```php
public function setPrice(int $productId, int $amount, string $currency, string $effectiveFrom): PriceTier
{
    // Fermer tout palier ouvert qui commence avant le nouveau effective_from
    $this->db->execute(
        'UPDATE price_tiers
         SET effective_to = ?
         WHERE product_id = ? AND effective_to IS NULL AND effective_from <= ?',
        [$effectiveFrom, $productId, $effectiveFrom],
    );

    // Ouvrir un nouveau palier
    $id = $this->db->insert(
        'INSERT INTO price_tiers (product_id, amount, currency, effective_from, effective_to, created_at)
         VALUES (?, ?, ?, ?, NULL, ?)',
        [$productId, $amount, $currency, $effectiveFrom, $now],
    );
    // ...
}
```

L'UPDATE ferme tout palier ouvert dont `effective_from <= newEffectiveFrom`. Cela gère correctement trois scénarios :
- **Nouveau effective_from dans le futur** : ferme le palier actuel à la date future.
- **Nouveau effective_from dans le passé** : antédate la fermeture de l'ancien palier et ouvre un nouveau palier historique.
- **effective_from dupliqué** : ferme l'ancien palier au même instant où il a commencé (durée nulle), puis ouvre le nouveau.

> **Mise en garde de concurrence** : l'UPDATE et l'INSERT ne sont pas enveloppés dans une transaction. Deux appels `setPrice` concurrents avec le même `effective_from` peuvent tous deux passer la phase UPDATE et tous deux insérer, laissant deux paliers ouverts (`effective_to IS NULL`). Les requêtes utilisent `ORDER BY effective_from DESC LIMIT 1`, donc le dernier insert gagne, mais l'historique est corrompu. Envelopper dans `transactional()` pour la correction sous concurrence.

---

## Interroger le prix à un moment donné

```php
public function priceAt(int $productId, string $datetime): ?PriceTier
{
    $row = $this->db->fetchOne(
        'SELECT * FROM price_tiers
         WHERE product_id = ? AND effective_from <= ?
           AND (effective_to IS NULL OR effective_to > ?)
         ORDER BY effective_from DESC
         LIMIT 1',
        [$productId, $datetime, $datetime],
    );

    return $row !== null ? $this->hydrateTier($row) : null;
}
```

La comparaison est une comparaison de chaînes lexicographique sur des datetimes ISO 8601 stockés en TEXT. Cela fonctionne correctement **uniquement quand tous les datetimes utilisent le même format et fuseau horaire** (ex. tous UTC `2026-05-27 09:00:00`). Mélanger les formats ou les décalages de fuseau horaire produit de mauvais résultats.

**Exemple** : Si `effective_from` est stocké comme `"2026-05-27T09:00:00+09:00"` (JST) et `?datetime=2026-05-27T00:30:00Z` (UTC, même instant), la comparaison de chaînes les voit comme différents et peut retourner un mauvais palier. Normaliser tous les datetimes en UTC à l'écriture.

---

## Montant en centimes (entier)

Les valeurs monétaires sont stockées en entiers (centimes) pour éviter les arrondis en virgule flottante :

```php
// POST /products/{id}/prices
$amount = isset($body['amount']) && is_int($body['amount']) ? $body['amount'] : null;

if ($amount === null || $amount < 0) {
    $errors[] = ['field' => 'amount', 'code' => 'required', 'message' => 'amount must be a non-negative integer (cents).'];
}
```

- `is_int()` rejette les floats JSON (`9.99` → float PHP) et les chaînes.
- `$amount < 0` rejette les prix négatifs.
- `$amount === 0` est **autorisé** (produits gratuits / promotions).

---

## ATK — Test d'attaque cracker (FT228)

### ATK-01 — Pas d'authentification

**Attaque** : Définir un prix sur n'importe quel produit sans credentials.

```http
POST /products/1/prices
{"amount": 1, "currency": "USD", "effective_from": "2026-01-01T00:00:00Z"}
```

**Observé** : `201 Created` — pas de token requis.

**Verdict** : **EXPOSED** (par conception pour la démo FT67).
Protéger les mutations de prix derrière un rôle admin ou une clé API en production.

---

### ATK-02 — Manipulation de prix antidatée

**Attaque** : Définir `effective_from` à une date passée pour altérer l'historique des prix.

```json
{"amount": 0, "currency": "USD", "effective_from": "2020-01-01T00:00:00Z"}
```

**Observé** : `201 Created`. L'UPDATE ferme tout palier ouvert existant à `2020-01-01`, et un nouveau palier à prix zéro couvrant depuis 2020 est inséré. Les requêtes historiques (`priceAt`) retournent maintenant le prix antidaté pour les dates passées.

**Verdict** : **EXPOSED** — sans authentification, il n'y a pas de propriétaire pour autoriser l'antidatage. Avec auth, exiger que `effective_from >= now()` sauf si l'appelant est admin.

---

### ATK-03 — Injection SQL via `?datetime=`

**Attaque** : Injecter du SQL via le paramètre de requête `datetime`.

```http
GET /products/1/prices/at?datetime=2026-01-01' OR '1'='1
```

**Observé** : `404 Not Found` — la chaîne injectée est utilisée comme valeur paramétrée, donc la chaîne littérale est comparée contre `effective_from`, ce qui ne correspond à rien.

**Verdict** : **BLOCKED** — les instructions paramétrées PDO préviennent l'injection SQL.

---

### ATK-04 — Prix à montant zéro

**Attaque** : Définir un prix de produit à zéro (gratuit).

```json
{"amount": 0, "currency": "USD", "effective_from": "2026-05-27T00:00:00Z"}
```

**Observé** : `201 Created`.

**Verdict** : **ACCEPTÉ PAR CONCEPTION** — `amount === 0` est intentionnellement autorisé (plans d'essai, promotions). Documenter que `amount` signifie centimes et 0 signifie gratuit. Si le prix zéro n'est pas valide pour votre domaine, changer `$amount < 0` en `$amount <= 0`.

---

### ATK-05 — Montant négatif

**Attaque** : Définir un prix négatif (attaque de remboursement ?).

```json
{"amount": -100, "currency": "USD", "effective_from": "2026-05-27T00:00:00Z"}
```

**Observé** : `422 Unprocessable Entity` — la vérification `$amount < 0` retourne false.

**Verdict** : **BLOCKED** — les montants négatifs sont rejetés au niveau applicatif.

---

### ATK-06 — Injection de code de devise (pas d'allowlist)

**Attaque** : Définir un prix avec une chaîne de devise arbitraire ou malveillante.

```json
{"amount": 100, "currency": "NOTCURRENCY", "effective_from": "2026-05-27T00:00:00Z"}
{"amount": 100, "currency": "<script>alert(1)</script>", "effective_from": "..."}
{"amount": 100, "currency": "'; DROP TABLE price_tiers; --", "effective_from": "..."}
```

**Observé** : Tout retourne `201 Created`. La chaîne de devise est stockée telle quelle. La chaîne d'injection SQL est sûre (paramétrée), mais `"NOTCURRENCY"` et le payload XSS sont stockés.

**Verdict** : **EXPOSED** — valider `currency` contre une allowlist ISO 4217 :
```php
$validCurrencies = ['USD', 'EUR', 'GBP', 'JPY', 'CAD', 'AUD'];
if (!in_array($currency, $validCurrencies, true)) {
    $errors[] = ['field' => 'currency', 'code' => 'invalid_value', 'message' => 'Unsupported currency code.'];
}
```

---

### ATK-07 — Montant extrêmement large

**Attaque** : Soumettre un montant plus grand que ce que PHP/SQLite peut gérer.

```json
{"amount": 9999999999999999999, "currency": "USD", "effective_from": "..."}
```

**Observé** : PHP analyse les grands entiers JSON comme floats quand ils dépassent `PHP_INT_MAX` (2^63 - 1 sur 64 bits). `is_int($body['amount'])` retourne false pour un float → 422.

**Verdict** : **BLOCKED** — `is_int()` rejette correctement les entiers JSON qui débordent vers PHP float. Les valeurs dans `PHP_INT_MAX` sont stockées correctement comme entiers SQLite.

---

### ATK-08 — Format datetime invalide dans `?datetime=`

**Attaque** : Passer une chaîne non-date à l'endpoint `priceAt`.

```http
GET /products/1/prices/at?datetime=not-a-date
GET /products/1/prices/at?datetime=2026-02-30T00:00:00Z
```

**Observé** : Les deux retournent `404 Not Found` — les chaînes sont comparées lexicographiquement aux valeurs `effective_from` stockées et ne correspondent à rien. Pas d'exception lancée.

**Verdict** : **PARTIELLEMENT EXPOSED** — l'endpoint accepte silencieusement les dates invalides et retourne 404, ce qui peut dérouter les appelants attendant un 422. Ajouter une validation de format :
```php
$dt = DateTimeImmutable::createFromFormat(DateTimeInterface::ATOM, $datetime);
if ($dt === false) {
    return $this->problems->create($request, 'validation-failed', 'Validation Failed', 422, null, [
        'errors' => [['field' => 'datetime', 'code' => 'invalid_format', 'message' => 'datetime must be ISO 8601.']],
    ]);
}
```

---

### ATK-09 — effective_from futur (prix programmé)

**Attaque** : Définir `effective_from` à une date future pour programmer un changement de prix.

```json
{"amount": 999, "currency": "USD", "effective_from": "2099-12-31T00:00:00Z"}
```

**Observé** : `201 Created`. `currentPrice()` retourne toujours le prix précédent (l'`effective_from` du palier futur est > now), mais `priceAt("2099-12-31T01:00:00Z")` retourne le nouveau palier.

**Verdict** : **ACCEPTÉ PAR CONCEPTION** — les prix programmés sont un cas d'utilisation légitime. Documenter dans la spec API. Si la programmation doit être restreinte aux admins, exiger auth et vérifier `effective_from <= now + 30 days` pour les appelants non-admin.

---

### ATK-10 — Définition de prix concurrente (condition de course)

**Attaque** : Envoyer deux `POST /products/1/prices` simultanés avec le même `effective_from`.

**Observé** : Sans transaction enveloppant le UPDATE + INSERT, les deux requêtes peuvent passer la phase UPDATE et toutes deux insérer, créant deux paliers ouverts (`effective_to IS NULL`). Les requêtes utilisent `ORDER BY effective_from DESC LIMIT 1`, donc les résultats sont non déterministes.

**Verdict** : **EXPOSED** — envelopper `setPrice` dans `transactional()` :
```php
return $this->txManager->transactional(function ($tx) use (...) {
    // UPDATE puis INSERT dans la transaction
});
```

---

### ATK-11 — product_id inexistant

**Attaque** : Définir un prix pour un produit qui n'existe pas.

```http
POST /products/99999/prices
{"amount": 100, "currency": "USD", "effective_from": "2026-05-27T00:00:00Z"}
```

**Observé** : `404 Not Found` — `findProduct(99999)` retourne `null` et le controller retourne une réponse Problem Details non trouvé avant d'appeler `setPrice`.

**Verdict** : **BLOCKED** — vérification d'existence avant mutation.

---

### ATK-12 — IDs de chemin non numériques

**Attaque** : Passer des chaînes non chiffres comme `{id}`.

```http
GET /products/abc
GET /products/-1
POST /products/0/prices
```

**Observé** : Tout retourne `404 Not Found`. `(int) "abc"` = `0` ; `findProduct(0)` retourne `null` (pas de produit avec ID 0) ; le controller retourne 404.

**Verdict** : **BLOCKED** en pratique. Note : `(int) "9abc"` = `9` — un produit avec l'ID 9 correspondrait. Utiliser `ctype_digit()` pour une validation stricte des chemins si nécessaire.

---

## Résumé ATK

| # | Vecteur d'attaque | Verdict |
|---|-------------------|---------|
| ATK-01 | Pas d'authentification | EXPOSED (par conception) |
| ATK-02 | Manipulation de prix antidatée | EXPOSED |
| ATK-03 | Injection SQL via `?datetime=` | BLOCKED |
| ATK-04 | Prix à montant zéro | ACCEPTÉ PAR CONCEPTION |
| ATK-05 | Montant négatif | BLOCKED |
| ATK-06 | Injection de code de devise (pas d'allowlist) | EXPOSED |
| ATK-07 | Montant extrêmement large | BLOCKED |
| ATK-08 | Format datetime invalide | PARTIELLEMENT EXPOSED |
| ATK-09 | `effective_from` futur (prix programmé) | ACCEPTÉ PAR CONCEPTION |
| ATK-10 | Condition de course setPrice concurrent | EXPOSED |
| ATK-11 | Produit inexistant | BLOCKED |
| ATK-12 | IDs de chemin non numériques | BLOCKED |

**Vulnérabilités réelles à corriger avant la production** :
1. **ATK-01** — Ajouter authentification/autorisation
2. **ATK-02** — Restreindre l'antidatage aux appelants admin (ou l'interdire complètement)
3. **ATK-06** — Valider `currency` contre une allowlist ISO 4217
4. **ATK-08** — Valider le format `?datetime=` avant la requête DB
5. **ATK-10** — Envelopper l'UPDATE+INSERT de `setPrice` dans une transaction

---

## Howtos associés

- [`expense-tracker.md`](expense-tracker.md) — validation de montant `is_int()` et aller-retour de date ISO 8601
- [`habit-tracker.md`](habit-tracker.md) — pattern ATK-01~12 (cycle ATK précédent)
- [`prevent-double-booking.md`](prevent-double-booking.md) — lecture-vérification-écriture transactionnelle
- [`iso-datetime-validation.md`](iso-datetime-validation.md) — validation stricte ISO 8601
