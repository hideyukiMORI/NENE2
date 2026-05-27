# Punkte-Loyalitätssystem

Implementierungsanleitung für ein Loyalitätssystem mit Punktevergabe, -verbrauch, Saldoverwaltung und Admin-Anpassung.
Erklärt idempotente Transaktionen (reference_id), Saldo-Untergrenzenschutz und Admin-RBAC.

## Übersicht

- Benutzer können Punkte verdienen (earn) und ausgeben (spend)
- Nur Admins können Punkte direkt anpassen (adjust: add/subtract)
- Saldo wird über `balance_after`-Transaktionsakkumulation verwaltet
- Idempotente Transaktionen per `reference_id` (verhindert Doppelgutschriften und -abbuchungen)
- Obergrenzenbeträge (MAX_EARN_PER_TRANSACTION) verhindern Massen-Betrugszuschreibungen

## Endpunkte

| Methode | Pfad | Beschreibung | Berechtigung |
|---|---|---|---|
| `GET` | `/users/{userId}/points` | Saldo abrufen | Eigentümer oder Admin |
| `GET` | `/users/{userId}/points/history` | Historie abrufen | Eigentümer oder Admin |
| `POST` | `/users/{userId}/points/earn` | Punkte vergeben | Eigentümer oder Admin |
| `POST` | `/users/{userId}/points/spend` | Punkte ausgeben | Eigentümer oder Admin |
| `POST` | `/users/{userId}/points/adjust` | Punkte anpassen | Nur Admin |

## Datenbankdesign

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

Der `balance_after >= 0`-CHECK-Constraint verhindert negativen Saldo auf DB-Ebene.
Der `amount > 0`-CHECK-Constraint lehnt Transaktionen mit 0 oder weniger auf DB-Ebene ab.

## Saldoberechnung

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

Das `balance_after` der neuesten Transaktion ist der aktuelle Saldo.
Da kein separater Saldotabelle gepflegt wird, ist die Transaktionshistorie die einzige Wahrheitsquelle (SSOT).

## Idempotente Transaktionen (reference_id)

```php
if ($referenceId !== null) {
    $existing = $this->repository->findByReferenceId($targetUserId, $referenceId);
    if ($existing !== null) {
        return $this->responseFactory->create($this->formatTransaction($existing), 200);
    }
}
// ... neue Transaktionsverarbeitung
```

Wenn `reference_id` bereits existiert, wird die vorhandene Transaktion zurückgegeben (200).
Verhindert Doppelgutschriften und Doppelabbuchungen.

## Saldoprüfung bei Punkteausgabe

```php
$balance = $this->repository->getBalance($targetUserId);
if ($balance < $amount) {
    return $this->responseFactory->create([
        'error' => 'insufficient points',
        'balance' => $balance,
        'required' => $amount,
    ], 422);
}
$balanceAfter = $balance - $amount;  // immer >= 0
```

Saldoprüfung auf Anwendungsebene als doppelte Verteidigung mit DB-CHECK-Constraint.

## Admin-Anpassung (adjust)

```php
// adjust_type: 'add' (Standard) oder 'subtract'
if ($adjustType === 'subtract') {
    if ($balance < $amount) { return 422 'insufficient points for adjustment'; }
    $balanceAfter = $balance - $amount;
} else {
    $balanceAfter = $balance + $amount;
}
$this->repository->addTransaction($userId, 'adjust', $amount, $balanceAfter, $description, null, $now);
```

## Obergrenzen-Kontrolle (MAX_EARN_PER_TRANSACTION)

```php
private const int MAX_EARN_PER_TRANSACTION = 10000;

if ($amount > self::MAX_EARN_PER_TRANSACTION) {
    return $this->responseFactory->create([
        'error' => 'amount exceeds maximum per transaction',
        'max' => self::MAX_EARN_PER_TRANSACTION,
    ], 422);
}
```

Verhindert Angriffe, bei denen in einer einzigen Transaktion große Mengen von Punkten unrechtmäßig vergeben werden.

## Zugangskontrolle

Eigenes Saldo und eigene Historie können nur vom Eigentümer eingesehen werden. Admins können alle Benutzer einsehen und verwalten.

```php
if ($targetUserId !== $actorId && !$this->isAdmin($request)) {
    return $this->responseFactory->create(['error' => 'access denied'], 403);
}
```

Earn/Spend können nur auf eigene Punkte angewendet werden (man kann keine Punkte eines anderen erhöhen).

## Antwort-Beispiele

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
