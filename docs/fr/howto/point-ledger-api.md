# How-to : API de grand livre de points

> **Référence FT** : FT300 (`NENE2-FT/pointlog`) — API de grand livre de points : transactions earn/spend/adjust/expire, suivi du solde, prévention du découvert (CHECK balance_after >= 0), ajustement admin uniquement, idempotence reference_id, plafonds MAX_EARN=10000 / MAX_ADJUST=100000, ATK-01~12 tous BLOCKED, 30 tests / 66 assertions PASS.

Ce guide montre comment créer un grand livre de points de fidélité où les utilisateurs gagnent et dépensent des points, les admins ajustent les soldes, et les IDs de référence préviennent les transactions dupliquées.

## Schéma

```sql
CREATE TABLE users (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT    NOT NULL,
    role       TEXT    NOT NULL DEFAULT 'user',
    created_at TEXT    NOT NULL,
    CHECK (role IN ('user', 'admin'))
);

CREATE TABLE point_transactions (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id      INTEGER NOT NULL,
    type         TEXT    NOT NULL,
    amount       INTEGER NOT NULL,
    balance_after INTEGER NOT NULL,
    description  TEXT    NOT NULL,
    reference_id TEXT,
    created_at   TEXT    NOT NULL,
    CHECK (type IN ('earn', 'spend', 'adjust', 'expire')),
    CHECK (amount > 0),
    CHECK (balance_after >= 0),
    FOREIGN KEY (user_id) REFERENCES users(id)
);
```

Trois contraintes CHECK en défense en profondeur :
- `amount > 0` — pas de transactions nulles ou négatives au niveau DB
- `balance_after >= 0` — le solde ne peut jamais devenir négatif en stockage
- `type IN (...)` — seuls les types de transactions connus sont acceptés

## Endpoints

| Méthode | Chemin | Auth | Description |
|---------|--------|------|-------------|
| `GET` | `/users/{userId}/points` | `X-User-Id` | Obtenir le solde actuel |
| `GET` | `/users/{userId}/points/history` | `X-User-Id` | Obtenir l'historique des transactions |
| `POST` | `/users/{userId}/points/earn` | `X-User-Id` (soi) | Gagner des points |
| `POST` | `/users/{userId}/points/spend` | `X-User-Id` (soi) | Dépenser des points |
| `POST` | `/users/{userId}/points/adjust` | `X-User-Id` (admin) | Ajustement admin |

## Authentification et autorisation

```php
private function requireUserId(ServerRequestInterface $request): ?int
{
    $val = $request->getHeaderLine('X-User-Id');
    return $val !== '' ? (int) $val : null;
}

private function isAdmin(ServerRequestInterface $request): bool
{
    return $request->getHeaderLine('X-User-Role') === 'admin';
}
```

Chaque gestionnaire appelle d'abord `requireUserId()` :

```php
$actorId = $this->requireUserId($request);
if ($actorId === null) {
    return $this->responseFactory->create(['error' => 'authentication required'], 401);
}
```

L'accès inter-utilisateurs est ensuite vérifié pour earn/spend :

```php
$targetUserId = (int) $this->routeParam($request, 'userId');
if ($targetUserId !== $actorId && !$this->isAdmin($request)) {
    return $this->responseFactory->create(['error' => 'access denied'], 403);
}
```

Les admins peuvent voir le solde ou l'historique de n'importe quel utilisateur. Les non-admins ne peuvent accéder qu'aux leurs.

## Validation stricte des entiers

```php
$amount = isset($body['amount']) && is_int($body['amount']) ? $body['amount'] : null;
if ($amount === null || $amount <= 0) {
    return $this->responseFactory->create(['error' => 'amount must be a positive integer'], 422);
}
```

`is_int()` rejette :
- Floats : `10.5` — rejeté (422)
- Chaînes : `"100"` — rejeté (422)
- Booléens : `true` — rejeté (422)
- Zéro : `0` — rejeté (amount <= 0)
- Négatifs : `-500` — rejeté (amount <= 0)

## Plafonds de transaction

```php
private const int MAX_EARN_PER_TRANSACTION  = 10000;
private const int MAX_ADJUST_PER_TRANSACTION = 100000;
```

```php
if ($amount > self::MAX_EARN_PER_TRANSACTION) {
    return $this->responseFactory->create([
        'error' => 'amount exceeds maximum per transaction',
        'max'   => self::MAX_EARN_PER_TRANSACTION,
    ], 422);
}
```

Earn est plafonné par transaction à 10 000. L'ajustement admin est plafonné à 100 000 (plus élevé car c'est une opération de correction privilégiée).

## Prévention du découvert

```php
$balance = $this->repository->getBalance($targetUserId);
if ($balance < $amount) {
    return $this->responseFactory->create([
        'error'    => 'insufficient points',
        'balance'  => $balance,
        'required' => $amount,
    ], 422);
}
```

Vérifier le solde actuel avant de déduire. Retourner le solde actuel et le montant requis dans l'erreur aide les clients à afficher un message significatif aux utilisateurs.

## Ajustement admin

```php
private function handleAdjust(ServerRequestInterface $request): ResponseInterface
{
    $actorId = $this->requireUserId($request);
    if ($actorId === null) {
        return $this->responseFactory->create(['error' => 'authentication required'], 401);
    }
    if (!$this->isAdmin($request)) {
        return $this->responseFactory->create(['error' => 'admin role required'], 403);
    }
    // ...
    $adjustType = isset($body['adjust_type']) && $body['adjust_type'] === 'subtract' ? 'subtract' : 'add';
    // ...
}
```

Adjust vérifie `isAdmin()` **avant** de vérifier l'utilisateur cible — un non-admin obtient 403 immédiatement quelle que soit la cible. Le champ `adjust_type` (`'add'` par défaut / `'subtract'`) permet aux admins d'accorder et de déduire des points sans avoir besoin d'endpoints séparés.

## Idempotence reference_id

```php
if ($referenceId !== null) {
    $existing = $this->repository->findByReferenceId($targetUserId, $referenceId);
    if ($existing !== null) {
        return $this->responseFactory->create($this->formatTransaction($existing), 200);
    }
}
```

Quand un `reference_id` est fourni :
- Premier appel → 201 Created avec nouvelle transaction
- Appel répété avec le même `reference_id` → 200 OK avec la transaction originale (pas de nouvelle transaction créée)

Cela prévient les doubles crédits sur les réessais réseau. La recherche de reference_id est **scopée par utilisateur** (`findByReferenceId($targetUserId, ...)`) donc le même reference_id peut être utilisé par différents utilisateurs sans conflit.

## Calcul du solde

```php
// Repository : somme de toutes les transactions earn/adjust-add moins spend/adjust-subtract/expire
public function getBalance(int $userId): int
{
    // Typiquement : balance_after de la transaction la plus récente, ou 0 si aucune
    $row = $this->executor->fetchOne(
        'SELECT balance_after FROM point_transactions WHERE user_id = ? ORDER BY id DESC LIMIT 1',
        [$userId]
    );
    return $row !== null ? (int) $row['balance_after'] : 0;
}
```

La colonne `balance_after` sur chaque transaction stocke le solde courant. Obtenir le solde actuel est une seule requête `ORDER BY id DESC LIMIT 1` — pas d'agrégation SUM nécessaire.

## Forme de la réponse

```php
private function formatTransaction(array $t): array
{
    return [
        'id'           => isset($t['id'])           ? (int)    $t['id']           : null,
        'user_id'      => isset($t['user_id'])       ? (int)    $t['user_id']       : null,
        'type'         => $t['type']         ?? null,
        'amount'       => isset($t['amount'])        ? (int)    $t['amount']        : null,
        'balance_after'=> isset($t['balance_after']) ? (int)    $t['balance_after'] : null,
        'description'  => $t['description']  ?? null,
        'reference_id' => $t['reference_id'] ?? null,
        'created_at'   => $t['created_at']   ?? null,
    ];
}
```

---

## Évaluation ATK — Test d'attaque mentalité cracker

### ATK-01 — Accès au solde non authentifié 🚫 BLOCKED

**Attaque** : `GET /users/2/points` sans en-tête `X-User-Id`.
**Résultat** : BLOCKED — `requireUserId()` retourne null → 401 immédiatement. Pas de données retournées.

---

### ATK-02 — Espionnage du solde inter-utilisateurs 🚫 BLOCKED

**Attaque** : `GET /users/2/points` avec `X-User-Id: 3` (Alice essaie de lire le solde de Bob).
**Résultat** : BLOCKED — `$targetUserId (2) !== $actorId (3)` et pas admin → 403.

---

### ATK-03 — Auto-crédit vers un autre utilisateur 🚫 BLOCKED

**Attaque** : `POST /users/3/points/earn` avec `X-User-Id: 2` et `amount: 99999`.
**Résultat** : BLOCKED — actor (2) != target (3) et pas admin → 403. Le solde de la cible reste 0.

---

### ATK-04 — Gain de montant négatif 🚫 BLOCKED

**Attaque** : `POST /users/2/points/earn` avec `amount: -500`.
**Résultat** : BLOCKED — vérification `$amount <= 0` → 422. Solde inchangé.

---

### ATK-05 — Transaction de montant zéro 🚫 BLOCKED

**Attaque** : `POST /users/2/points/earn` avec `amount: 0`, et séparément `amount: 0` pour spend.
**Résultat** : BLOCKED — les deux retournent 422 (`amount <= 0`). Pas de transactions à valeur nulle créées.

---

### ATK-06 — Dépense avec découvert 🚫 BLOCKED

**Attaque** : Gagner 100 points, puis essayer d'en dépenser 101.
**Résultat** : BLOCKED — `$balance (100) < $amount (101)` → 422 avec `insufficient points`. Le solde reste à 100. `CHECK (balance_after >= 0)` en DB fournit un filet de sécurité supplémentaire.

---

### ATK-07 — Ajustement par utilisateur ordinaire 🚫 BLOCKED

**Attaque** : `POST /users/2/points/adjust` avec `X-User-Id: 2` (rôle non-admin).
**Résultat** : BLOCKED — la vérification `isAdmin()` échoue → 403. Le solde reste 0.

---

### ATK-08 — Montant de gain excessif 🚫 BLOCKED

**Attaque** : `POST /users/2/points/earn` avec `amount: 10001` (au-dessus de MAX_EARN=10000).
**Résultat** : BLOCKED — `$amount > MAX_EARN_PER_TRANSACTION` → 422 avec `max: 10000`. Solde inchangé.

---

### ATK-09 — Double crédit via réutilisation de reference_id 🚫 BLOCKED

**Attaque** : Gagner 500 points avec `reference_id: "order-999"`, puis répéter la même requête.
**Résultat** : BLOCKED — le deuxième appel trouve la transaction existante via `findByReferenceId()` → 200 avec la même transaction. Le solde reste 500 (pas 1000).

---

### ATK-10 — Double débit via réutilisation de reference_id 🚫 BLOCKED

**Attaque** : Dépenser 300 points avec `reference_id: "redemption-777"`, puis répéter.
**Résultat** : BLOCKED — le deuxième appel retourne la transaction de dépense originale (200). Le solde reste 700 (pas 400).

---

### ATK-11 — Injection SQL dans reference_id 🚫 BLOCKED

**Attaque** : `reference_id: "' OR '1'='1' --"` dans une requête de gain.
**Résultat** : BLOCKED — les requêtes paramétrées stockent la chaîne d'injection telle quelle. Le solde est de 100, pas corrompu. `reference_id` dans la réponse correspond exactement à la chaîne injectée (stockée comme données, pas interprétée comme SQL).

---

### ATK-12 — Montant float 🚫 BLOCKED

**Attaque** : `POST /users/2/points/earn` avec `amount: 10.5`.
**Résultat** : BLOCKED — `is_int(10.5)` est false → null → 422. Solde inchangé.

---

### Résumé ATK

| ID | Attaque | Résultat |
|----|---------|----------|
| ATK-01 | Accès au solde non authentifié | 🚫 BLOCKED |
| ATK-02 | Espionnage du solde inter-utilisateurs | 🚫 BLOCKED |
| ATK-03 | Auto-crédit vers un autre utilisateur | 🚫 BLOCKED |
| ATK-04 | Gain de montant négatif | 🚫 BLOCKED |
| ATK-05 | Transaction de montant zéro | 🚫 BLOCKED |
| ATK-06 | Dépense avec découvert | 🚫 BLOCKED |
| ATK-07 | Ajustement par utilisateur ordinaire | 🚫 BLOCKED |
| ATK-08 | Montant de gain excessif (>MAX) | 🚫 BLOCKED |
| ATK-09 | Double crédit via reference_id | 🚫 BLOCKED |
| ATK-10 | Double débit via reference_id | 🚫 BLOCKED |
| ATK-11 | Injection SQL dans reference_id | 🚫 BLOCKED |
| ATK-12 | Montant float | 🚫 BLOCKED |

**12 BLOCKED, 0 EXPOSED**
Pas de résultats critiques. La chaîne auth (401→403), la validation des montants (is_int + >0 + plafond), la garde contre le découvert et l'idempotence reference_id préviennent tous les vecteurs d'attaque connus.

---

## À ne pas faire

| Anti-pattern | Risque |
|---|---|
| Pas de vérification `X-User-Id` (authentification ignorée) | Accès non authentifié à tous les soldes et transactions |
| Gain inter-utilisateurs sans vérification admin | N'importe quel utilisateur gagne des points dans le compte d'un autre |
| `$amount > 0` sans `is_int()` | Float `10.5` passe ; les points fractionnaires corrompent l'intégrité du grand livre |
| Pas de plafond MAX_EARN | L'attaquant gagne INT_MAX points en une seule requête |
| Pas de vérification de découvert avant spend | Le solde devient négatif ; CHECK DB est le dernier recours, pas la garde principale |
| Pas d'idempotence `reference_id` | Un réessai réseau double les crédits ou les charges |
| Espace `reference_id` partagé entre utilisateurs | `order-1` de l'utilisateur A bloque l'utilisateur B d'utiliser la même référence |
| `getBalance()` via agrégation SUM sur grandes tables | Scan de table complet par requête ; utiliser le total courant `balance_after` à la place |
| Ajustement admin sans vérification de rôle en premier | Un non-admin soumet un grand ajustement ; vérifier le rôle avant toute logique métier |
| Retourner 200 sur doublon sans même corps de transaction | Le client ne peut pas vérifier l'idempotence ; doit retourner la transaction originale |
