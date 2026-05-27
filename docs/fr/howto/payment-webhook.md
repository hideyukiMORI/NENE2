# Guide d'implémentation de réception de Webhook de paiement

## Vue d'ensemble

Ce guide explique comment implémenter une API de réception de Webhook de paiement avec NENE2.
Fournit la vérification de signature HMAC-SHA256, le traitement idempotent (contrainte UNIQUE event_id) et les gardes de transition d'état.

---

## Schéma DB

```sql
CREATE TABLE payments (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    external_id TEXT    NOT NULL UNIQUE,
    amount      INTEGER NOT NULL,               -- unité monétaire minimale (yen, centimes)
    currency    TEXT    NOT NULL DEFAULT 'usd',
    status      TEXT    NOT NULL DEFAULT 'pending'
                        CHECK (status IN ('pending', 'succeeded', 'failed', 'refunded')),
    created_at  TEXT    NOT NULL,
    updated_at  TEXT    NOT NULL
);

CREATE TABLE webhook_events (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    event_id     TEXT    NOT NULL UNIQUE,   -- clé d'idempotence
    event_type   TEXT    NOT NULL,
    payload      TEXT    NOT NULL,          -- JSON
    processed_at TEXT    NOT NULL
);
```

`webhook_events.event_id` est au cœur du **traitement idempotent**. Même si le même event_id est reçu deux fois, il n'est traité qu'une seule fois.

---

## Design des endpoints

| Méthode | Chemin | Description |
|---------|--------|-------------|
| POST | `/webhooks/payment` | Réception et traitement des événements Webhook |
| GET | `/payments` | Liste des paiements |
| GET | `/payments/{id}` | Détail d'un paiement |

---

## Transitions d'état

```
[créé] → pending → succeeded → refunded
                 ↘ failed
```

Géré avec une table de transitions :

```php
private const array VALID_TRANSITIONS = [
    'payment.succeeded' => ['from' => 'pending',   'to' => 'succeeded'],
    'payment.failed'    => ['from' => 'pending',   'to' => 'failed'],
    'payment.refunded'  => ['from' => 'succeeded', 'to' => 'refunded'],
];
```

Les transitions invalides (failed → succeeded, etc.) retournent 409 Conflict.

---

## Points clés de conception

### Vérification de signature HMAC-SHA256

Vérifier l'ensemble du corps de la requête avec HMAC-SHA256. Utiliser l'en-tête `X-Webhook-Signature: sha256=<hex>` compatible Stripe :

```php
private function verifySignature(string $body, string $header): bool
{
    if (!str_starts_with($header, 'sha256=')) {
        return false;
    }
    $provided = substr($header, 7);
    $expected = hash_hmac('sha256', $body, $this->webhookSecret);
    return hash_equals($expected, $provided); // prévention d'attaque de timing
}
```

Comparer à temps constant avec `hash_equals()`. `===` et `strcmp()` se terminent tôt et sont donc vulnérables.

### Traitement idempotent

Les fournisseurs de Webhook réessaient. Dédupliquer avec `event_id` :

```php
// Vérifier avant traitement
if ($this->repo->isEventProcessed($eventId)) {
    return $this->json->create(['status' => 'already_processed']);
}

// Enregistrer après traitement
$this->repo->recordEvent($eventId, $eventType, $payload, $now);
```

### Ordre de traitement

```
1. Vérification de signature → 401
2. Vérification de duplication de event_id → 200 already_processed
3. Traitement par type d'événement
4. Enregistrement dans webhook_events
5. Retourner 200 processed
```

**Effectuer la vérification de signature en premier** pour empêcher les attaquants de contaminer la table event_id.

### Retourner 200 pour les types d'événements inconnus

Quand le fournisseur ajoute un nouveau type d'événement, retourner 4xx déclenche des réessais.
Retourner silencieusement 200 et enregistrer les types inconnus :

```php
// Type d'événement inconnu — acquitter sans traiter
return null; // → 200 processed
```

### Test : générer la signature avec SECRET injecté

```php
private const string SECRET = 'test-webhook-secret';

private function signedReq(string $path, array $body): ResponseInterface
{
    $rawBody = json_encode($body);
    $sig     = 'sha256=' . hash_hmac('sha256', $rawBody, self::SECRET);
    // ...
}
```

Passer le même secret à l'application avec `AppFactory::createSqlite($dbFile, self::SECRET)`.

---

## Exemples de payload d'événement

### payment.created

```json
{
  "event_id": "evt_001",
  "event_type": "payment.created",
  "data": {"id": "pay_abc", "amount": 5000, "currency": "jpy"}
}
```

### payment.succeeded

```json
{
  "event_id": "evt_002",
  "event_type": "payment.succeeded",
  "data": {"id": "pay_abc"}
}
```

### Réponse (succès)

```json
{"status": "processed", "event_type": "payment.succeeded"}
```

### Réponse (renvoi idempotent)

```json
{"status": "already_processed"}
```

---

## Implémentation de référence

`../NENE2-FT/paymentlog/` — Field Trial FT163 (18 tests, vérification de signature, traitement idempotent, gardes de transition)
