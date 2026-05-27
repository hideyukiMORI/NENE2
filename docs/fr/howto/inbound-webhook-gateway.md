# How-to : Passerelle de webhooks entrants

> **Référence FT** : FT317 (`NENE2-FT/inboundlog`) — Passerelle de webhooks entrants avec vérification de signature HMAC-SHA256 par source, idempotence event_id, secret jamais exposé dans les réponses, 17 tests / 18 assertions PASS.

Ce guide montre comment construire un récepteur de webhooks entrants multi-sources qui valide l'authenticité des requêtes avant de les traiter.

## Schéma

```sql
CREATE TABLE sources (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT    NOT NULL,
    secret     TEXT    NOT NULL,   -- secret partagé pour HMAC
    active     INTEGER NOT NULL DEFAULT 1,
    created_at TEXT    NOT NULL
);

CREATE TABLE webhook_events (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    source_id   INTEGER NOT NULL REFERENCES sources(id),
    event_id    TEXT    NOT NULL,  -- clé de déduplication fournie par le provider
    event_type  TEXT    NOT NULL,
    payload     TEXT    NOT NULL,  -- corps JSON brut
    received_at TEXT    NOT NULL,
    UNIQUE(source_id, event_id)
);
```

## Endpoints

| Méthode | Chemin | Description |
|---------|--------|-------------|
| `POST` | `/sources` | Enregistrer une nouvelle source de webhook |
| `POST` | `/sources/{id}/receive` | Recevoir un événement webhook |
| `GET`  | `/sources/{id}/events` | Lister les événements pour une source |
| `GET`  | `/events/{id}` | Obtenir un événement unique |

## Enregistrement de source

```php
POST /sources
{"name": "stripe", "secret": "whsec_abc123..."}

→ 201
{"id": 1, "name": "stripe", "active": true, "created_at": "..."}
// le secret n'est JAMAIS retourné
```

```php
POST /sources  {"secret": "abc"}   → 422  // name requis
POST /sources  {"name": "github"}  → 422  // secret requis
```

## Vérification de signature HMAC-SHA256

Chaque webhook entrant doit inclure un en-tête `X-Webhook-Signature` avec le HMAC-SHA256 du corps brut :

```
X-Webhook-Signature: sha256=<hex_digest>
```

```php
private function verifySignature(string $body, string $header, string $secret): bool
{
    if (!str_starts_with($header, 'sha256=')) {
        return false;
    }
    $expected = hash_hmac('sha256', $body, $secret);
    return hash_equals($expected, substr($header, 7));  // comparaison à temps constant
}
```

**Important** : utiliser `hash_equals()` — pas `===` — pour prévenir les attaques temporelles.

## Réception d'événements

```php
// L'expéditeur (ex: Stripe) calcule :
$sig = 'sha256=' . hash_hmac('sha256', $rawBody, $sharedSecret);

POST /sources/1/receive
X-Webhook-Signature: sha256=<digest>
Content-Type: application/json

{"event_id": "evt-001", "event_type": "payment.succeeded", "data": {...}}

→ 201  {"id": 5, "event_type": "payment.succeeded", "status": "processed"}
```

### Cas d'erreur

```php
// Signature incorrecte ou manquante
POST /sources/1/receive  (mauvaise sig)  → 401 Unauthorized

// Source non trouvée
POST /sources/9999/receive               → 404 Not Found

// event_id manquant dans le payload
POST /sources/1/receive  {"event_type": "x"}  → 422
```

## Idempotence des événements dupliqués

Les retentatives du provider sont courantes — la déduplication par `event_id` prévient le double-traitement :

```php
// Première livraison
POST /sources/1/receive  {"event_id": "evt-dup", "event_type": "order.created"}
→ 201  {"status": "processed", "id": 5}

// Retentative (même event_id)
POST /sources/1/receive  {"event_id": "evt-dup", "event_type": "order.created"}
→ 200  {"status": "already_processed", "id": 5}
```

`UNIQUE(source_id, event_id)` dans la DB applique cela au niveau stockage.

## Interrogation des événements

```php
GET /sources/1/events
→ 200  {"events": [...], "count": 2}

GET /events/5
→ 200  {"id": 5, "source_id": 1, "event_type": "payment.succeeded", ...}

GET /events/9999
→ 404
```

---

## À ne pas faire

| Anti-pattern | Risque |
|---|---|
| Retourner `secret` dans la réponse source | Divulgue la clé de signature à tout client pouvant lire la réponse API |
| Utiliser `===` au lieu de `hash_equals()` pour la signature | L'attaque temporelle révèle le HMAC octet par octet |
| Pas de déduplication par `event_id` | Les retentatives du provider causent un double traitement (double facturation, emails dupliqués) |
| Vérifier la signature après le parsing JSON | L'attaquant peut créer un corps qui passe le parsing JSON mais échoue HMAC ; toujours vérifier les octets bruts d'abord |
| Secret global unique pour toutes les sources | La compromission d'une intégration expose toutes les autres |
