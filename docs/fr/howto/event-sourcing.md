# Event Sourcing (Basique)

Persister l'état comme une séquence immuable d'événements de domaine. Dériver l'état courant en rejouant le flux d'événements.

## Vue d'ensemble

L'event sourcing stocke **ce qui s'est passé** (événements) plutôt que **ce qui est** (état courant). Le solde d'un compte n'est pas stocké ; il est calculé en rejouant tous les événements de dépôt et de retrait. Les événements sont immuables — ils ne sont jamais mis à jour ni supprimés.

## Schéma de base de données

```sql
CREATE TABLE accounts (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    owner      TEXT    NOT NULL,
    created_at TEXT    NOT NULL
);

CREATE TABLE events (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    aggregate_id INTEGER NOT NULL,
    event_type   TEXT    NOT NULL,
    payload      TEXT    NOT NULL,  -- JSON
    occurred_at  TEXT    NOT NULL,
    FOREIGN KEY (aggregate_id) REFERENCES accounts(id)
);
```

`accounts` est la racine d'agrégat. `events` est le journal d'événements append-only. Il n'y a pas de colonne `balance` — elle est toujours calculée depuis les événements.

## Types d'événements

Définir les types d'événements comme constantes pour prévenir les fautes de frappe et permettre l'analyse statique :

```php
public const string TYPE_ACCOUNT_CREATED = 'account_created';
public const string TYPE_DEPOSITED       = 'deposited';
public const string TYPE_WITHDRAWN       = 'withdrawn';
```

## Ajout d'événements

Les événements sont toujours insérés, jamais mis à jour. L'API n'a pas d'endpoint qui modifie ou supprime des événements :

```php
public function appendEvent(int $aggregateId, string $eventType, array $payload, string $now): DomainEvent
{
    $payloadJson = json_encode($payload, JSON_THROW_ON_ERROR);

    $this->executor->execute(
        'INSERT INTO events (aggregate_id, event_type, payload, occurred_at) VALUES (?, ?, ?, ?)',
        [$aggregateId, $eventType, $payloadJson, $now],
    );
    ...
}
```

## Rejeu d'état

Charger les événements dans l'ordre d'insertion et les réduire en état courant :

```php
public function replayBalance(int $aggregateId): int
{
    $events  = $this->findEventsByAggregateId($aggregateId);
    $balance = 0;

    foreach ($events as $event) {
        $amount = isset($event->payload['amount']) ? (int) $event->payload['amount'] : 0;

        if ($event->eventType === DomainEvent::TYPE_DEPOSITED) {
            $balance += $amount;
        } elseif ($event->eventType === DomainEvent::TYPE_WITHDRAWN) {
            $balance -= $amount;
        }
    }

    return $balance;
}
```

`ORDER BY id ASC` garantit l'ordre de rejeu. `ORDER BY occurred_at ASC` est fragile — deux événements avec le même horodatage auraient un ordre indéfini.

## Validation des montants

Valider strictement les montants avant d'ajouter des événements :

```php
$amount = isset($body['amount']) && is_int($body['amount']) ? $body['amount'] : 0;

if ($amount <= 0 || $amount > 1_000_000_000) {
    return 422;
}
```

- `is_int()` rejette les valeurs flottantes (ex. `1.9`) que PHP tronquerait sinon silencieusement à `1`
- La borne supérieure empêche le débordement d'entier lors de la somme de multiples grands dépôts
- Rejeter au niveau API — ne pas laisser des montants invalides atteindre le journal d'événements

## Fonds insuffisants

Vérifier le solde avant d'ajouter un événement de retrait :

```php
$balance = $this->repo->replayBalance($id);

if ($amount > $balance) {
    return $this->problems->create($request, 'insufficient-funds', 'Insufficient funds.', 422, '');
}

$event = $this->repo->appendEvent($id, DomainEvent::TYPE_WITHDRAWN, ['amount' => $amount], $now);
```

La vérification du solde se fait dans le gestionnaire (pas dans le repository) car c'est une règle métier, pas une contrainte d'intégrité des données.

## Isolation des événements

Les événements sont limités à leur agrégat par `aggregate_id`. Rejouer les événements du compte A ne touche jamais le compte B :

```sql
SELECT * FROM events WHERE aggregate_id = ? ORDER BY id ASC
```

## Propriétés de sécurité

| Propriété | Implémentation |
|---|---|
| Immuabilité des événements | Pas d'endpoint DELETE/UPDATE sur les événements |
| Plage de montant | 1–1 000 000 000 (int) — rejette les flottants et les valeurs en débordement |
| Fonds insuffisants | Solde rejoué avant retrait ; 422 si insuffisant |
| Isolation entre comptes | Toutes les requêtes filtrent par aggregate_id |
| Injection de payload | Payload toujours `['amount' => int]` ; pas de clés contrôlées par l'utilisateur |
| Injection de type d'événement | Type d'événement toujours depuis des constantes ; pas d'event_type contrôlé par l'utilisateur |

## Récapitulatif des routes

| Méthode | Chemin | Description |
|---|---|---|
| `POST` | `/accounts` | Créer un compte |
| `POST` | `/accounts/{id}/deposit` | Ajouter un événement de dépôt |
| `POST` | `/accounts/{id}/withdraw` | Ajouter un événement de retrait (vérification du solde) |
| `GET` | `/accounts/{id}/balance` | Rejouer le solde depuis les événements |
| `GET` | `/accounts/{id}/events` | Lister tous les événements du compte |
