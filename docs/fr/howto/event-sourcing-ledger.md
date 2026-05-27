# How-to : Grand livre en Event Sourcing

> **Référence FT** : FT310 (`NENE2-FT/eventsourcelog`) — Grand livre de comptes en event sourcing : journal d'événements immuable (append-only), `replayBalance()` rejoue tous les événements pour calculer le solde courant, événements de dépôt/retrait jamais supprimés, validation stricte des montants avec `is_int()`, montant maximum 1 000 000 000, comptes séparés sans partage de solde, 17 tests / 24 assertions PASS.

Ce guide montre comment implémenter un grand livre de comptes avec l'event sourcing : le solde courant n'est pas stocké directement — il est dérivé en rejouant tous les événements passés.

## Schéma

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
    payload      TEXT    NOT NULL,  -- JSON : { "amount": 100 }
    occurred_at  TEXT    NOT NULL,
    FOREIGN KEY (aggregate_id) REFERENCES accounts(id)
);
```

`events` est append-only. Il n'y a pas de `UPDATE` ou `DELETE` sur les événements. Chaque dépôt ou retrait ajoute une nouvelle ligne.

## Endpoints

| Méthode | Chemin                        | Description                                  |
|---------|-------------------------------|----------------------------------------------|
| `POST`  | `/accounts`                   | Créer un compte                              |
| `GET`   | `/accounts/{id}/balance`      | Obtenir le solde courant (rejoué)            |
| `POST`  | `/accounts/{id}/deposit`      | Ajouter un événement de dépôt               |
| `POST`  | `/accounts/{id}/withdraw`     | Ajouter un événement de retrait              |
| `GET`   | `/accounts/{id}/events`       | Lister tous les événements                   |

## Solde — Rejeu depuis les événements

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

Le solde n'est stocké nulle part — il est calculé à la volée en rejouant tous les événements. Les nouveaux comptes commencent à 0 (aucun événement). Le journal d'événements est la source de vérité.

## Validation des dépôts

```php
$amount = isset($body['amount']) && is_int($body['amount']) ? $body['amount'] : 0;
if ($amount <= 0 || $amount > 1_000_000_000) {
    return $this->problems->create($request, 'validation-failed',
        'amount must be a positive integer not exceeding 1000000000.', 422, '');
}
// Ajouter l'événement
$this->repo->appendEvent($id, 'AccountDeposited', ['amount' => $amount], date('c'));
```

- `is_int()` rejette les flottants, chaînes, null
- `> 0` rejette zéro et les négatifs
- `<= 1_000_000_000` plafonne le montant par transaction

## Retrait — Vérifier le solde rejoué d'abord

```php
$balance = $this->repo->replayBalance($id);
if ($amount > $balance) {
    return $this->problems->create($request, 'validation-failed',
        'insufficient funds.', 422, '');
}
$this->repo->appendEvent($id, 'AccountWithdrawn', ['amount' => $amount], date('c'));
```

Rejouer le solde avant d'accepter un retrait. La vérification du découvert se fait au niveau applicatif — pas dans les contraintes DB (la ligne d'événement n'a pas de notion de "solde après").

## Isolation des comptes

Chaque compte a son propre `aggregate_id`. `replayBalance()` filtre par `aggregate_id`, donc :
- Les dépôts du compte 1 n'affectent pas le solde du compte 2
- Les listes d'événements sont par compte (pas de contamination croisée)

---

## À ne pas faire

| Anti-pattern | Risque |
|---|---|
| Stocker une colonne `balance` plutôt que rejouer | L'état mutable peut se désynchroniser ; les événements sont la vérité |
| Autoriser la suppression d'événements | Supprimer des événements rend le calcul du solde incorrect rétroactivement |
| Accepter un `amount` flottant | Les montants fractionnaires dans le payload corrompent le rejeu entier |
| Pas de vérification de découvert au niveau applicatif | Solde négatif possible car les événements n'ont pas de contrainte de solde |
| Table d'événements partagée sans filtre `aggregate_id` | Tous les comptes partagent le même flux d'événements |
| Rejouer les événements de tous les comptes pour un solde | Scan complet de table au lieu d'un filtre par compte |
