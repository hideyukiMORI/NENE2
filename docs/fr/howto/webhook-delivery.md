# Livraison de webhooks sortants

Les webhooks sortants notifient les systèmes tiers lorsque des événements se produisent dans votre application. Les principales préoccupations de sécurité sont le SSRF (envoi de requêtes vers l'infrastructure interne), la fuite de secrets et l'intégrité des signatures.

## Composants principaux

- **Registre des endpoints** : stocke l'URL, le filtre d'événements et un secret hashé par abonné.
- **File de livraison** : un enregistrement par paire (endpoint, événement), suivant le nombre de tentatives et le statut.
- **Signer** : génère des signatures HMAC-SHA256 que le destinataire peut vérifier.
- **Validateur d'URL** : bloque les cibles SSRF avant de stocker les endpoints.

## Schéma

```sql
CREATE TABLE webhook_endpoints (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    url         TEXT    NOT NULL,
    event_type  TEXT    NOT NULL,
    secret_hash TEXT    NOT NULL,       -- SHA-256 du secret brut ; le secret brut n'est jamais stocké
    max_retries INTEGER NOT NULL DEFAULT 3,
    active      INTEGER NOT NULL DEFAULT 1,
    created_at  TEXT    NOT NULL
);

CREATE TABLE webhook_deliveries (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    endpoint_id   INTEGER NOT NULL,
    event_type    TEXT    NOT NULL,
    payload       TEXT    NOT NULL,
    status        TEXT    NOT NULL DEFAULT 'pending',  -- pending | delivered | failed
    attempt_count INTEGER NOT NULL DEFAULT 0,
    last_status   INTEGER,                             -- dernier code de réponse HTTP
    last_error    TEXT,
    delivered_at  TEXT,
    created_at    TEXT    NOT NULL,
    updated_at    TEXT    NOT NULL
);
```

Seul le hash SHA-256 du secret est stocké. Le secret brut n'est jamais persisté — si la base de données est compromise, les hashes ne peuvent pas être inversés pour forger des signatures (SHA-256 sans HMAC n'est pas réversible pour un secret aléatoire de 32 octets).

## Format de signature

```
X-Webhook-Signature: sha256={hex}
X-Webhook-Timestamp: {unix_timestamp}
```

Contenu signé : `{timestamp}.{body}` — liant la signature à la fois au payload et à un moment dans le temps.

```php
public function sign(string $rawSecret, string $body, string $timestamp): string
{
    $payload = $timestamp . '.' . $body;
    $mac     = hash_hmac('sha256', $payload, $rawSecret);

    return 'sha256=' . $mac;
}
```

Inclure le timestamp dans le contenu signé prévient les attaques de rejeu : un attaquant qui capture un webhook valide ne peut pas le réutiliser plus tard car le timestamp serait périmé. Les destinataires doivent rejeter les signatures plus anciennes qu'un seuil (ex. 5 minutes).

## Prévention SSRF

Valider chaque URL de webhook avant de la stocker. Au minimum, bloquer :

```php
final class UrlValidator
{
    public function validate(string $url): ?string
    {
        // Bloquer l'injection CRLF/octet null
        if (str_contains($url, "\n") || str_contains($url, "\r") || str_contains($url, "\0")) {
            return 'URL contains illegal control characters.';
        }

        // HTTPS uniquement
        if (strtolower(parse_url($url, PHP_URL_SCHEME) ?? '') !== 'https') {
            return 'Only HTTPS URLs are allowed.';
        }

        // Bloquer les IP privées/loopback et les noms d'hôte réservés
        // ...
    }
}
```

Plages IPv4 privées à bloquer : `127.0.0.0/8`, `10.0.0.0/8`, `172.16.0.0/12`, `192.168.0.0/16`, `169.254.0.0/16`, `0.0.0.0`.

Noms d'hôte à bloquer : `localhost`, `*.local`, `*.internal`, `*.test`, `*.invalid`.

IPv6 : `::1`, `fc00::/7` (ULA), `fe80::/10` (lien-local).

**DNS rebinding** : valider l'URL à l'enregistrement n'est pas suffisant — l'enregistrement DNS pourrait changer entre l'enregistrement et la livraison pour pointer vers une IP interne. En production, valider également l'IP résolue au moment de la livraison avant d'ouvrir la connexion TCP.

## Filtrage des réponses — ne jamais exposer les secrets

La méthode `toArray()` sur `WebhookEndpoint` doit omettre à la fois `secret` et `secret_hash` :

```php
public function toArray(): array
{
    return [
        'id', 'url', 'event_type', 'max_retries', 'active', 'created_at',
        // secret_hash intentionnellement absent
    ];
}
```

Ceci s'applique à : GET /webhooks/{id}, liste des endpoints, et tout journal d'audit qui enregistre les métadonnées d'endpoint.

## Logique de retry

```php
public function markFailed(int $id, string $error, ?int $httpStatus, string $now, int $maxRetries): ?WebhookDelivery
{
    $newCount  = $delivery->attemptCount + 1;
    $newStatus = $newCount >= $maxRetries ? 'failed' : 'pending';

    $this->executor->execute(
        'UPDATE webhook_deliveries SET status = ?, attempt_count = ?, last_error = ?, updated_at = ? WHERE id = ?',
        [$newStatus, $newCount, $error, $now, $id],
    );
}
```

- `attempt_count < max_retries` → le statut reste `pending` → le worker le reprend.
- `attempt_count >= max_retries` → le statut devient `failed` → plus de retries.

Les workers devraient implémenter un backoff exponentiel (ex. `2^attempt_count` secondes) pour éviter de surcharger un destinataire en difficulté.

## Désactivation

Les endpoints désactivés (`active = 0`) sont exclus de la requête de fan-out au moment du dispatch :

```sql
SELECT * FROM webhook_endpoints WHERE event_type = ? AND active = 1
```

Cela donne aux abonnés un moyen de mettre la livraison en pause sans supprimer leur enregistrement.

## Décisions de conception

**Pourquoi stocker `secret_hash` plutôt que le secret brut ?**
Si la DB est compromise, l'attaquant ne peut pas extraire les secrets pour forger des signatures de webhook envoyées aux destinataires. Le secret brut est retourné une fois à la création et doit être stocké de manière sécurisée par l'appelant.

**Pourquoi inclure le timestamp dans la signature ?**
Les signatures sans timestamps sont rejouables indéfiniment. Inclure `{timestamp}.{body}` dans le HMAC signifie qu'un attaquant qui intercepte un webhook ne peut pas le renvoyer — les destinataires peuvent rejeter les timestamps en dehors d'une fenêtre de ±5 minutes.

**Pourquoi valider l'URL à l'enregistrement, pas au dispatch ?**
Bloquer les URLs invalides à l'enregistrement donne un retour immédiat à l'abonné et empêche les mauvaises données d'entrer dans la file de livraison. Les attaques de DNS rebinding nécessitent une validation supplémentaire au moment du dispatch.
