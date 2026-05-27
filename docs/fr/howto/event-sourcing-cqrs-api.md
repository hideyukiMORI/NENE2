# How-to : API Event Sourcing & CQRS

> **Référence FT** : `NENE2-FT/eventstore` — Journal d'événements append-only avec numéros de séquence par agrégat, types d'événements sur liste blanche, projection de modèle de lecture reconstruit depuis les événements, suivi de solde, 17 tests PASS.

Ce guide montre comment implémenter l'event sourcing : stocker chaque changement d'état comme un événement immuable, calculer l'état courant depuis le journal d'événements, et exposer une projection de modèle de lecture.

## Schéma

```sql
-- Journal d'événements append-only — ne jamais UPDATE ou DELETE des lignes
CREATE TABLE domain_events (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    aggregate_id   TEXT    NOT NULL,  -- ex. "acc-001"
    aggregate_type TEXT    NOT NULL,  -- ex. "account"
    event_type     TEXT    NOT NULL,  -- ex. "MoneyDeposited"
    payload        TEXT    NOT NULL DEFAULT '{}',  -- JSON
    sequence       INTEGER NOT NULL,  -- compteur par agrégat, commence à 1
    occurred_at    TEXT    NOT NULL,
    UNIQUE(aggregate_id, sequence)
);

-- Modèle de lecture : état courant du compte reconstruit depuis les événements
CREATE TABLE account_projections (
    account_id    TEXT    PRIMARY KEY,
    owner         TEXT    NOT NULL,
    balance_cents INTEGER NOT NULL DEFAULT 0,
    is_open       INTEGER NOT NULL DEFAULT 1,
    last_sequence INTEGER NOT NULL DEFAULT 0,
    updated_at    TEXT    NOT NULL
);
```

`UNIQUE(aggregate_id, sequence)` empêche l'insertion d'événements dupliqués. La projection est toujours dérivée du journal d'événements — elle peut être reconstruite à tout moment par rejeu.

## Types d'événements (Liste blanche)

```php
const ALLOWED_EVENTS = [
    'AccountOpened',
    'MoneyDeposited',
    'MoneyWithdrawn',
    'AccountClosed',
];
```

Les types d'événements inconnus retournent 422. N'ajouter que des événements qui ont des gestionnaires explicites dans la logique de projection.

## Endpoints

| Méthode | Chemin                        | Description                                        |
|---------|-------------------------------|----------------------------------------------------|
| `POST`  | `/accounts`                   | Ouvrir un compte (émet AccountOpened)              |
| `GET`   | `/accounts`                   | Lister toutes les projections de comptes           |
| `GET`   | `/accounts/{id}`              | Obtenir la projection d'un compte (404 si introuvable) |
| `POST`  | `/accounts/{id}/events`       | Ajouter un événement au compte                     |
| `GET`   | `/accounts/{id}/events`       | Lister le journal d'événements du compte           |

## Ouvrir un compte

```php
POST /accounts
{"account_id": "acc-001", "owner": "Alice"}

→ 201
{
  "event_type": "AccountOpened",
  "aggregate_id": "acc-001",
  "sequence": 1,
  "payload": {"owner": "Alice"},
  "occurred_at": "..."
}
```

L'ouverture d'un compte crée un événement `AccountOpened` (sequence=1) et initialise la projection.

## Ajouter des événements

```php
POST /accounts/acc-001/events
{"event_type": "MoneyDeposited", "payload": {"amount_cents": 50000}}

→ 201
{
  "event_type": "MoneyDeposited",
  "aggregate_id": "acc-001",
  "sequence": 2,         // ← s'incrémente par agrégat
  "payload": {"amount_cents": 50000},
  "occurred_at": "..."
}
```

Chaque compte a un **compteur de séquence indépendant**. `acc-001` et `acc-002` commencent tous les deux à 1.

```php
// Type d'événement invalide → 422
POST /accounts/acc-001/events  {"event_type": "UnknownEvent"}
→ 422

// Compte inexistant → 404
POST /accounts/nonexistent/events  {"event_type": "MoneyDeposited", "payload": {"amount_cents": 1000}}
→ 404
```

## Projection du modèle de lecture

```php
GET /accounts/acc-001

→ 200
{
  "account_id": "acc-001",
  "owner": "Alice",
  "balance_cents": 60000,   // 50000 déposés + 10000 déposés
  "is_open": true,
  "last_sequence": 3
}

// Événement AccountClosed appliqué
GET /accounts/acc-001  // après ajout d'AccountClosed
→ 200  {"is_open": false, "last_sequence": 4}
```

```php
GET /accounts/nonexistent
→ 404
```

## Journal d'événements

```php
GET /accounts/acc-001/events

→ 200
{
  "total": 3,
  "items": [
    {"event_type": "AccountOpened",  "sequence": 1, ...},
    {"event_type": "MoneyDeposited", "sequence": 2, "payload": {"amount_cents": 50000}, ...},
    {"event_type": "MoneyWithdrawn", "sequence": 3, "payload": {"amount_cents": 30000}, ...}
  ]
}
```

Ordonné par `sequence ASC` — ordre chronologique.

```php
// Compte inconnu → liste vide (pas 404)
GET /accounts/nonexistent/events
→ 200  {"total": 0, "items": []}
```

## Implémentation

### Génération de séquence

```php
public function nextSequence(string $aggregateId): int
{
    $row = $this->db->fetchOne(
        'SELECT MAX(sequence) AS seq FROM domain_events WHERE aggregate_id = ?',
        [$aggregateId],
    );
    return (int) ($row['seq'] ?? 0) + 1;
}
```

### Ajouter un événement + Mettre à jour la projection (Transaction)

```php
public function appendEvent(string $aggregateId, string $eventType, array $payload): array
{
    $sequence = $this->nextSequence($aggregateId);
    $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

    $this->tx->begin();
    try {
        // Ajouter au journal immuable
        $id = $this->db->insert(
            'INSERT INTO domain_events (aggregate_id, aggregate_type, event_type, payload, sequence, occurred_at)
             VALUES (?, ?, ?, ?, ?, ?)',
            [$aggregateId, 'account', $eventType, json_encode($payload), $sequence, $now->format('Y-m-d H:i:s')],
        );

        // Mettre à jour la projection
        $this->applyProjection($aggregateId, $eventType, $payload, $sequence, $now);

        $this->tx->commit();
    } catch (\Throwable $e) {
        $this->tx->rollback();
        throw $e;
    }

    return $this->db->fetchOne('SELECT * FROM domain_events WHERE id = ?', [$id]);
}
```

### Logique de projection

```php
private function applyProjection(
    string $aggregateId,
    string $eventType,
    array $payload,
    int $sequence,
    \DateTimeImmutable $now,
): void {
    $ts = $now->format('Y-m-d H:i:s');
    match ($eventType) {
        'AccountOpened' => $this->db->execute(
            'INSERT INTO account_projections (account_id, owner, balance_cents, is_open, last_sequence, updated_at)
             VALUES (?, ?, 0, 1, ?, ?)',
            [$aggregateId, $payload['owner'] ?? '', $sequence, $ts],
        ),
        'MoneyDeposited' => $this->db->execute(
            'UPDATE account_projections SET balance_cents = balance_cents + ?, last_sequence = ?, updated_at = ?
             WHERE account_id = ?',
            [$payload['amount_cents'], $sequence, $ts, $aggregateId],
        ),
        'MoneyWithdrawn' => $this->db->execute(
            'UPDATE account_projections SET balance_cents = balance_cents - ?, last_sequence = ?, updated_at = ?
             WHERE account_id = ?',
            [$payload['amount_cents'], $sequence, $ts, $aggregateId],
        ),
        'AccountClosed' => $this->db->execute(
            'UPDATE account_projections SET is_open = 0, last_sequence = ?, updated_at = ? WHERE account_id = ?',
            [$sequence, $ts, $aggregateId],
        ),
        default => null,
    };
}
```

---

## À ne pas faire

| Anti-pattern | Risque |
|---|---|
| UPDATE ou DELETE des lignes dans `domain_events` | Détruit la piste d'audit ; les projections deviennent incohérentes avec l'historique |
| Pas de `UNIQUE(aggregate_id, sequence)` | Les événements dupliqués corrompent la projection lors du rejeu |
| Stocker le solde calculé dans `domain_events` | L'état dérivé appartient à la projection, pas au journal d'événements |
| Autoriser des types d'événements arbitraires | La logique de projection n'a pas de gestionnaire → no-op silencieux ou crash |
| Ajouter un événement sans mettre à jour la projection dans la même transaction | Fenêtre d'incohérence entre le journal d'événements et le modèle de lecture |
| Pas de compteur de séquence par agrégat | Impossible de détecter les lacunes de rejeu ou les conflits d'écriture concurrents |
