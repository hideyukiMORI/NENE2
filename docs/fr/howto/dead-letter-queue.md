# How-to : File d'attente de messages morts (DLQ)

> **Référence FT** : FT72 (`NENE2-FT/deadletterlog`) — API de file d'attente de messages morts

Démontre une file d'attente de messages fiable avec des retentatives à backoff exponentiel et une file d'attente de messages morts. Les messages échoués sont automatiquement replanifiés avec des délais croissants ; après épuisement de toutes les retentatives, ils passent à l'état `dead` où ils peuvent être inspectés et rejoués. Prend en charge plusieurs files nommées via le paramètre de chemin.

---

## Cycle de vie d'un message

```
enqueue ──▶ pending ──claim──▶ processing
                                    │
                        ┌──succeed──┤──fail (retentatives restantes)──▶ pending (retry_after)
                        │           │
                        ▼           └──fail (épuisé)──▶ dead ──replay──▶ pending
                    succeeded
```

| Statut | Description |
|--------|-------------|
| `pending` | Prêt à être réclamé (ou en attente jusqu'à `retry_after`) |
| `processing` | Réclamé par un worker, en cours de traitement |
| `succeeded` | Complété avec succès |
| `dead` | A épuisé toutes les retentatives — dans la file d'attente de messages morts |

---

## Routes

| Méthode | Chemin                                        | Description                              |
|---------|-----------------------------------------------|------------------------------------------|
| `POST`  | `/queues/{queue}/messages`                    | Mettre en file un message                |
| `GET`   | `/queues/{queue}/messages`                    | Lister les messages dans une file        |
| `GET`   | `/queues/{queue}/messages/{id}`               | Obtenir un seul message                  |
| `POST`  | `/queues/{queue}/claim`                       | Réclamer le prochain message pending     |
| `POST`  | `/queues/{queue}/messages/{id}/succeed`       | Marquer comme réussi                     |
| `POST`  | `/queues/{queue}/messages/{id}/fail`          | Marquer comme échoué (retentative ou DLQ)|
| `POST`  | `/queues/{queue}/messages/{id}/replay`        | Rejouer un message mort                  |

---

## Mise en file d'un message

```php
// POST /queues/emails/messages
$body = [
    'payload'     => '{"to":"alice@example.com","subject":"Welcome"}',  // chaîne requise
    'max_retries' => 5,  // optionnel, défaut 3, plage 1–10
];
```

`max_retries` est validé pour être entre 1 et 10 :

```php
$maxRetries = isset($body['max_retries']) && is_int($body['max_retries']) ? $body['max_retries'] : 3;

if ($maxRetries < 1 || $maxRetries > 10) {
    return $this->problems->create($request, 'validation-failed', 'Validation Failed', 422, null, [
        'errors' => [['field' => 'max_retries', 'code' => 'invalid', 'message' => 'max_retries must be between 1 and 10.']],
    ]);
}
```

---

## Réclamer le prochain message pending

Un worker appelle `POST /queues/{queue}/claim` pour dé-queuer un message de façon atomique :

```php
public function claim(string $queue, string $now): ?Message
{
    $rows = $this->executor->fetchAll(
        "SELECT * FROM messages
         WHERE queue = ? AND status = 'pending'
           AND (retry_after IS NULL OR retry_after <= ?)
         ORDER BY created_at ASC LIMIT 1",
        [$queue, $now],
    );

    if ($rows === []) {
        return null;  // aucun message disponible
    }

    $id = (int) $rows[0]['id'];
    $this->executor->execute(
        "UPDATE messages SET status = 'processing', updated_at = ? WHERE id = ?",
        [$now, $id],
    );

    return $this->findById($id);
}
```

`retry_after <= now` filtre les messages qui attendent entre les retentatives. Les messages sont réclamés dans l'ordre FIFO (`ORDER BY created_at ASC`).

> **Note sur l'atomicité** : Sans transaction, deux workers concurrents peuvent réclamer le même message s'ils lisent tous les deux la même ligne avant qu'un UPDATE s'exécute. Envelopper le SELECT + UPDATE dans une transaction avec `SELECT ... FOR UPDATE` (MySQL/PostgreSQL) ou utiliser `UPDATE ... WHERE status = 'pending' RETURNING id` pour un claim véritablement atomique.

---

## Gestion des échecs avec backoff exponentiel

Quand un worker signale un échec (`POST .../fail`), le repository planifie soit une retentative, soit promeut le message dans la file de messages morts :

```php
public function fail(int $id, string $error, string $now): ?Message
{
    $msg = $this->findById($id);
    if ($msg === null || $msg->status !== MessageStatus::Processing) {
        return null;
    }

    $newRetryCount = $msg->retryCount + 1;

    if ($newRetryCount >= $msg->maxRetries) {
        // Épuisé — déplacer vers la DLQ
        $this->executor->execute(
            "UPDATE messages SET status = 'dead', retry_count = ?, last_error = ?, updated_at = ? WHERE id = ?",
            [$newRetryCount, $error, $now, $id],
        );
    } else {
        // Planifier une retentative avec backoff exponentiel
        $backoffSeconds = min(2 ** $newRetryCount, 3600);
        $retryAfter     = (new \DateTimeImmutable($now))
            ->modify("+{$backoffSeconds} seconds")
            ->format('Y-m-d H:i:s');

        $this->executor->execute(
            "UPDATE messages SET status = 'pending', retry_count = ?, last_error = ?,
             retry_after = ?, updated_at = ? WHERE id = ?",
            [$newRetryCount, $error, $retryAfter, $now, $id],
        );
    }

    return $this->findById($id);
}
```

### Calendrier de backoff (max_retries = 5)

| Tentative | Secondes de backoff | Formule |
|-----------|---------------------|---------|
| 1er échec | 2 s | 2^1 |
| 2ème échec | 4 s | 2^2 |
| 3ème échec | 8 s | 2^3 |
| 4ème échec | 16 s | 2^4 |
| 5ème échec | → dead | retentatives épuisées |

`min(2 ** $newRetryCount, 3600)` plafonne le backoff maximum à 1 heure. Pour les grands compteurs de retentatives, cela évite les délais de plusieurs jours tout en donnant au service le temps de se rétablir.

---

## Rejouer des messages morts

Un message mort peut être rejoué en le réinitialisant à `pending` avec l'état de retentative effacé :

```php
public function replay(int $id, string $now): ?Message
{
    $msg = $this->findById($id);
    if ($msg === null || $msg->status !== MessageStatus::Dead) {
        return null;  // 409 Conflict
    }

    $this->executor->execute(
        "UPDATE messages SET status = 'pending', retry_count = 0,
         last_error = NULL, retry_after = NULL, updated_at = ? WHERE id = ?",
        [$now, $id],
    );

    return $this->findById($id);
}
```

`retry_count` est réinitialisé à 0 pour que le message obtienne à nouveau le budget complet de `max_retries`. La valeur `max_retries` originale est préservée.

> **Bonne pratique** : avant de rejouer, corriger la cause sous-jacente de l'échec. Rejouer dans un système cassé repeuplera simplement la DLQ.

---

## Files nommées multiples

Le paramètre de chemin `{queue}` achemine les messages par nom. Toute chaîne non vide est valide :

```
POST /queues/emails/messages
POST /queues/notifications/messages
POST /queues/webhooks/messages
```

Toutes les requêtes filtrent par `queue = ?`, donc chaque file est isolée. Aucune étape d'enregistrement de file n'est nécessaire — les files sont créées implicitement au premier enqueue.

---

## Schéma

```sql
CREATE TABLE messages (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    queue       TEXT    NOT NULL DEFAULT 'default',
    payload     TEXT    NOT NULL,
    status      TEXT    NOT NULL DEFAULT 'pending',
    retry_count INTEGER NOT NULL DEFAULT 0,
    max_retries INTEGER NOT NULL DEFAULT 3,
    retry_after TEXT,           -- NULL quand non planifié pour retentative
    last_error  TEXT,           -- NULL jusqu'au premier échec
    created_at  TEXT    NOT NULL,
    updated_at  TEXT    NOT NULL
);
```

Choix de conception clés :
- `payload` est une chaîne opaque — la file n'inspecte ni ne valide le contenu des messages.
- `last_error` stocke le message d'échec le plus récent pour le débogage.
- `retry_after` est `NULL` pour les nouveaux messages et effacé au replay, permettant à `retry_after <= now` de fonctionner sans cas spéciaux.

---

## Pattern worker

Un worker poll et traite un message à la fois :

```php
// Boucle worker (pseudo-code)
while (true) {
    $msg = claim('/queues/emails/messages');
    if ($msg === null) {
        sleep(5);  // aucun message, reculer
        continue;
    }

    try {
        sendEmail(json_decode($msg->payload));
        succeed($msg->id);
    } catch (Exception $e) {
        fail($msg->id, $e->getMessage());
    }
}
```

Garder les cycles claim-to-succeed/fail courts. Un traitement long sans délai d'expiration laisse les messages à l'état `processing` indéfiniment si le worker plante. Ajouter une colonne `processing_timeout` et un job de reaper pour récupérer les messages expirés.

---

## Guides associés

- [`job-queue.md`](job-queue.md) — file de jobs basique sans DLQ
- [`notification-queue.md`](notification-queue.md) — patterns de file de notification
- [`idempotency.md`](idempotency.md) — traitement idempotent pour la livraison at-least-once
- [`webhook-delivery.md`](webhook-delivery.md) — patterns de retentative webhook
