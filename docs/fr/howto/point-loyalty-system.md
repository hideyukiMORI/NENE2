# Système de fidélité par points

Guide d'implémentation d'un système de fidélité avec attribution, consommation, gestion du solde et ajustement admin de points.
Explique les transactions idempotentes (`reference_id`), la protection contre le solde négatif et le RBAC admin.

## Vue d'ensemble

- Les utilisateurs peuvent gagner (earn) et dépenser (spend) des points
- Seul l'admin peut ajuster directement les points (adjust: add/subtract)
- Le solde est géré en accumulant `balance_after` des transactions
- Transactions idempotentes via `reference_id` (prévention de double attribution/double débit)
- Prévention de l'attribution frauduleuse en masse par limite de montant (MAX_EARN_PER_TRANSACTION) plutôt que max_uses

## Endpoints

| Méthode | Chemin | Description | Permissions |
|---------|--------|-------------|-------------|
| `GET` | `/users/{userId}/points` | Obtenir le solde | Soi-même ou admin |
| `GET` | `/users/{userId}/points/history` | Obtenir l'historique | Soi-même ou admin |
| `POST` | `/users/{userId}/points/earn` | Attribuer des points | Soi-même ou admin |
| `POST` | `/users/{userId}/points/spend` | Consommer des points | Soi-même ou admin |
| `POST` | `/users/{userId}/points/adjust` | Ajuster les points | Admin uniquement |

## Conception de la base de données

```sql
CREATE TABLE point_transactions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    type TEXT NOT NULL CHECK (type IN ('earn', 'spend', 'adjust', 'expire')),
    amount INTEGER NOT NULL CHECK (amount > 0),
    balance_after INTEGER NOT NULL CHECK (balance_after >= 0),
    description TEXT NOT NULL,
    reference_id TEXT,
    created_at TEXT NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id)
);
```

La contrainte CHECK `balance_after >= 0` prévient un solde négatif au niveau DB.
La contrainte CHECK `amount > 0` rejette les transactions nulles ou inférieures au niveau DB.

## Calcul du solde

```php
public function getBalance(int $userId): int
{
    $row = $this->executor->fetchOne(
        'SELECT balance_after FROM point_transactions WHERE user_id = ? ORDER BY id DESC LIMIT 1',
        [$userId]
    );
    return $row !== null ? (int) $row['balance_after'] : 0;
}
```

`balance_after` de la dernière transaction est le solde actuel.
Pas de table séparée pour le solde, donc l'historique des transactions est la source unique de vérité (SSOT).

## Transactions idempotentes (reference_id)

```php
if ($referenceId !== null) {
    $existing = $this->repository->findByReferenceId($targetUserId, $referenceId);
    if ($existing !== null) {
        return $this->responseFactory->create($this->formatTransaction($existing), 200);
    }
}
// ... traitement de la nouvelle transaction
```

Si `reference_id` existe déjà, retourner la transaction existante (200).
Prévient le double crédit et le double débit.

## Vérification du solde lors de la consommation

```php
$balance = $this->repository->getBalance($targetUserId);
if ($balance < $amount) {
    return $this->responseFactory->create([
        'error' => 'insufficient points',
        'balance' => $balance,
        'required' => $amount,
    ], 422);
}
$balanceAfter = $balance - $amount;  // toujours >= 0
```

Vérification du solde au niveau applicatif, défense en profondeur avec la contrainte CHECK en DB.

## Ajustement admin (adjust)

```php
// adjust_type : 'add' (par défaut) ou 'subtract'
if ($adjustType === 'subtract') {
    if ($balance < $amount) { return 422 'insufficient points for adjustment'; }
    $balanceAfter = $balance - $amount;
} else {
    $balanceAfter = $balance + $amount;
}
$this->repository->addTransaction($userId, 'adjust', $amount, $balanceAfter, $description, null, $now);
```

## Contrôle de limite (MAX_EARN_PER_TRANSACTION)

```php
private const int MAX_EARN_PER_TRANSACTION = 10000;

if ($amount > self::MAX_EARN_PER_TRANSACTION) {
    return $this->responseFactory->create([
        'error' => 'amount exceeds maximum per transaction',
        'max' => self::MAX_EARN_PER_TRANSACTION,
    ], 422);
}
```

Prévient les attaques d'attribution frauduleuse de nombreux points en une seule transaction.

## Contrôle d'accès

Le solde et l'historique ne peuvent être consultés que par le propriétaire. L'admin peut consulter et opérer sur tous les utilisateurs.

```php
if ($targetUserId !== $actorId && !$this->isAdmin($request)) {
    return $this->responseFactory->create(['error' => 'access denied'], 403);
}
```

earn/spend n'opèrent que sur ses propres points (impossible d'augmenter les points d'un autre).

## Exemples de réponse

### POST /users/2/points/earn
```json
{
  "id": 1,
  "user_id": 2,
  "type": "earn",
  "amount": 100,
  "balance_after": 100,
  "description": "Purchase reward",
  "reference_id": "order-123",
  "created_at": "2026-05-21T..."
}
```

### GET /users/2/points/history
```json
{
  "user_id": 2,
  "balance": 70,
  "transactions": [
    {"id": 2, "type": "spend", "amount": 30, "balance_after": 70, ...},
    {"id": 1, "type": "earn", "amount": 100, "balance_after": 100, ...}
  ]
}
```
