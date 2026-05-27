# How-to : API de grand livre de crédits

> **Référence FT** : FT234 (`NENE2-FT/creditslog`) — API de grand livre de crédits

Montre un grand livre de crédits append-only où les soldes ne sont jamais stockés directement — ils sont calculés au moment de la requête avec `SUM(amount * direction)`. Prend en charge l'acquisition de crédits, la dépense de crédits avec une garde contre le découvert, l'acquisition idempotente via une clé unique, et un historique de transactions filtrable.

---

## Routes

| Méthode | Chemin | Description |
|---------|--------|-------------|
| `POST` | `/users/{userId}/credits/earn` | Acquérir des crédits (ajouter au solde) |
| `POST` | `/users/{userId}/credits/spend` | Dépenser des crédits (déduire du solde, 409 sur découvert) |
| `GET` | `/users/{userId}/credits/balance` | Obtenir le solde actuel |
| `GET` | `/users/{userId}/credits/transactions` | Lister l'historique des transactions (optionnel `?type=`) |

---

## Modèle de grand livre : `direction` au lieu de montant signé

Au lieu de stocker des montants positifs et négatifs, chaque transaction stocke un `amount` positif et une `direction` signée (`+1` pour acquisition, `-1` pour dépense) :

```sql
CREATE TABLE IF NOT EXISTS credit_transactions (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id         TEXT    NOT NULL,
    type            TEXT    NOT NULL CHECK(type IN ('earn', 'spend', 'adjust')),
    amount          INTEGER NOT NULL CHECK(amount > 0),
    direction       INTEGER NOT NULL CHECK(direction IN (1, -1)),
    description     TEXT    NOT NULL DEFAULT '',
    idempotency_key TEXT    UNIQUE,
    created_at      TEXT    NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_credit_transactions_user ON credit_transactions (user_id);
```

Avantages du pattern avec colonne `direction` :
- `CHECK(amount > 0)` garantit que le montant brut est toujours positif — pas de bugs de double-négation accidentels lors de l'insertion.
- `CHECK(direction IN (1, -1))` contraint le multiplicateur à deux valeurs valides.
- La formule de solde est uniforme : `SUM(amount * direction)` — pas de branchement conditionnel dans l'agrégation.
- Un type `adjust` est disponible pour les corrections manuelles (ex. remboursements, crédits admin) en utilisant l'une ou l'autre direction.

---

## Calcul du solde

Le solde est calculé au moment de la lecture — aucune colonne `balance` n'est jamais mise à jour :

```php
public function balance(string $userId): int
{
    $row = $this->db->fetchOne(
        'SELECT COALESCE(SUM(amount * direction), 0) AS bal FROM credit_transactions WHERE user_id = ?',
        [$userId],
    );

    return (int) ($row['bal'] ?? 0);
}
```

`COALESCE(..., 0)` gère le cas où un utilisateur n'a pas de transactions — `SUM` d'un ensemble vide retourne `NULL` en SQL, ce qui se castera en `0` de toute façon, mais `COALESCE` rend l'intention explicite.

L'index sur `user_id` assure que l'agrégation `SUM` ne scanne que les lignes de cet utilisateur. Pour les grands livres importants, une colonne de solde mise en cache avec verrouillage optimiste ou des snapshots event-sourcés vaut la peine d'être envisagée (voir `add-optimistic-locking.md`).

---

## Acquisition avec clé d'idempotence optionnelle

Fournir `idempotency_key` rend l'opération d'acquisition sûre à réessayer — une clé en doublon retourne la transaction originale au lieu d'en insérer une nouvelle :

```php
public function earn(string $userId, int $amount, string $description, ?string $idempotencyKey): CreditTransaction
{
    $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');

    if ($idempotencyKey !== null) {
        try {
            $id = $this->db->insert(
                'INSERT INTO credit_transactions (user_id, type, amount, direction, description, idempotency_key, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$userId, 'earn', $amount, 1, $description, $idempotencyKey, $now],
            );
        } catch (DatabaseConstraintException) {
            // Clé déjà utilisée — retourner la transaction originale
            $row = $this->db->fetchOne(
                'SELECT * FROM credit_transactions WHERE idempotency_key = ?',
                [$idempotencyKey],
            );
            assert($row !== null);

            return $this->hydrate($row);
        }
    } else {
        $id = $this->db->insert(
            'INSERT INTO credit_transactions (user_id, type, amount, direction, description, idempotency_key, created_at) VALUES (?, ?, ?, NULL, ?)',
            [$userId, 'earn', $amount, 1, $description, $now],
        );
    }

    $row = $this->db->fetchOne('SELECT * FROM credit_transactions WHERE id = ?', [$id]);
    assert($row !== null);

    return $this->hydrate($row);
}
```

La contrainte `UNIQUE` sur `idempotency_key` fait de la DB l'autorité — l'application capture `DatabaseConstraintException` et récupère la ligne existante. Cela évite une race condition SELECT-avant-INSERT : deux retries concurrents avec la même clé résulteront en exactement un INSERT réussi.

---

## Dépense avec garde contre le découvert

```php
public function spend(string $userId, int $amount, string $description): CreditTransaction
{
    $balance = $this->balance($userId);
    if ($balance < $amount) {
        throw new InsufficientCreditsException("Insufficient credits: balance={$balance}, requested={$amount}");
    }

    $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    $id  = $this->db->insert(
        'INSERT INTO credit_transactions (user_id, type, amount, direction, description, idempotency_key, created_at) VALUES (?, ?, ?, ?, ?, NULL, ?)',
        [$userId, 'spend', $amount, -1, $description, $now],
    );
    // ...
}
```

Le contrôleur mappe `InsufficientCreditsException` sur `409 Conflict` :

```php
try {
    $tx = $this->repo->spend($userId, $amount, $description);
} catch (InsufficientCreditsException $e) {
    return $this->problems->create($request, 'insufficient-credits', 'Insufficient Credits', 409, $e->getMessage());
}
```

`409 Conflict` est préféré à `422 Unprocessable Entity` car la requête est valide — c'est l'état du solde qui l'empêche. Un appelant qui réessaie après avoir acquis plus de crédits réussira.

> **Note de concurrence** : la vérification du solde et l'insertion ne sont pas enveloppées dans une transaction. Deux requêtes de dépense concurrentes peuvent toutes les deux lire un solde suffisant et toutes les deux insérer, laissant le solde négatif. Envelopper dans une transaction avec `SELECT ... FOR UPDATE` (MySQL/PostgreSQL) ou utiliser les écritures sérialisées de SQLite pour une correctness sous concurrence.

---

## Validation du montant

```php
$amount = isset($body['amount']) && is_int($body['amount']) ? $body['amount'] : 0;

$errors = [];
if ($amount <= 0) {
    $errors[] = ['field' => 'amount', 'code' => 'invalid', 'message' => 'amount must be a positive integer.'];
}
```

La vérification stricte `is_int()` rejette les floats JSON (`1.5`) et les chaînes (`"10"`). Le `CHECK(amount > 0)` au niveau DB agit comme filet de sécurité, mais rejeter au niveau applicatif donne une réponse Problem Details structurée au lieu d'une erreur DB.

---

## Historique des transactions avec filtre de type

```php
private function transactions(ServerRequestInterface $request): ResponseInterface
{
    $params = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
    $userId = (string) ($params['userId'] ?? '');
    $q      = $request->getQueryParams();
    $type   = isset($q['type']) && is_string($q['type']) ? $q['type'] : null;

    $txs = $this->repo->listTransactions($userId, $type);

    return $this->json->create([
        'user_id'      => $userId,
        'transactions' => array_map(fn (CreditTransaction $t) => $t->toArray(), $txs),
    ]);
}
```

`?type=earn` ou `?type=spend` restreint la liste. Aucune validation n'est effectuée sur la valeur du type — un type inconnu (ex. `?type=refund`) retourne une liste vide plutôt qu'une erreur, ce qui est acceptable pour un paramètre de filtre.

---

## Notes de conception du schéma

| Colonne | But |
|---------|-----|
| `amount` | Toujours positif ; `CHECK(amount > 0)` l'applique |
| `direction` | `+1` (acquisition) ou `-1` (dépense) ; `CHECK(direction IN (1, -1))` |
| `type` | Label humain : `earn`, `spend`, `adjust` ; liste d'autorisation `CHECK` |
| `idempotency_key` | Clé `UNIQUE` optionnelle pour les opérations d'acquisition retry-safe |
| `description` | Note en texte libre pour la transaction |

Pas de colonne `balance` — le solde actuel est toujours dérivé du grand livre.

---

## Guides associés

- [`idempotency.md`](idempotency.md) — patterns généraux de clé d'idempotence
- [`multi-currency-wallet.md`](multi-currency-wallet.md) — gestion de solde multi-devises
- [`point-loyalty-system.md`](point-loyalty-system.md) — acquisition/utilisation de points avec niveaux de fidélité
- [`add-optimistic-locking.md`](add-optimistic-locking.md) — solde mis en cache avec garde de version
- [`transactions.md`](transactions.md) — envelopper la vérification du solde et l'insertion dans une transaction
