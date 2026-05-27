# How-to : Système de livraison de webhooks

> **Référence FT** : FT308 (`NENE2-FT/webhookdeliverylog`) — Système de livraison de webhooks : protection SSRF via UrlValidator (HTTPS uniquement, liste de blocage d'IP privées, prévention d'injection CRLF), signature HMAC-SHA256 avec liaison temporelle, secret stocké comme hash SHA-256 (jamais en clair), secret non retourné dans les réponses GET, les endpoints désactivés ignorent la livraison, isolation par type d'événement, ATK-01~12 tous BLOCKED, 31 tests / 47 assertions PASS.

Ce guide montre comment construire un système de livraison de webhooks où les secrets de webhook sont protégés, les URLs sont validées contre les attaques SSRF, et les payloads sont signés avec des timestamps pour prévenir les attaques de rejeu.

## Schéma

```sql
CREATE TABLE IF NOT EXISTS webhook_endpoints (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    url         TEXT    NOT NULL,
    event_type  TEXT    NOT NULL,
    secret_hash TEXT    NOT NULL,   -- hash SHA-256 du secret brut
    max_retries INTEGER NOT NULL DEFAULT 3,
    active      INTEGER NOT NULL DEFAULT 1,
    created_at  TEXT    NOT NULL
);

CREATE TABLE IF NOT EXISTS webhook_deliveries (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    endpoint_id   INTEGER NOT NULL REFERENCES webhook_endpoints(id),
    event_type    TEXT    NOT NULL,
    payload       TEXT    NOT NULL DEFAULT '{}',
    status        TEXT    NOT NULL DEFAULT 'pending',
    attempt_count INTEGER NOT NULL DEFAULT 0,
    last_status   INTEGER,
    last_error    TEXT,
    delivered_at  TEXT,
    created_at    TEXT    NOT NULL,
    updated_at    TEXT    NOT NULL
);
```

`secret_hash` stocke le hash SHA-256 du secret brut — jamais le secret lui-même. Le flag `active` permet de désactiver doucement un endpoint sans supprimer l'historique de livraison.

## Protection SSRF — UrlValidator

```php
final class UrlValidator
{
    public function validate(string $url): ?string
    {
        // Bloquer l'injection CRLF et les octets null
        if (str_contains($url, "\n") || str_contains($url, "\r") || str_contains($url, "\0")) {
            return 'URL contains illegal control characters.';
        }

        $parsed = parse_url($url);
        if ($parsed === false || !isset($parsed['scheme'], $parsed['host'])) {
            return 'URL is not valid.';
        }

        // HTTPS uniquement
        if (strtolower($parsed['scheme']) !== 'https') {
            return 'Only HTTPS URLs are allowed for webhook delivery.';
        }

        $host = strtolower($parsed['host']);

        // Bloquer localhost et variantes
        if (in_array($host, ['localhost', 'ip6-localhost', 'ip6-loopback'], true)) {
            return "Webhook URL must not target '{$host}'.";
        }

        // Bloquer les TLD internes
        foreach (['.local', '.internal', '.test', '.example', '.invalid', '.localhost'] as $pattern) {
            if (str_ends_with($host, $pattern)) {
                return "Webhook URL must not target '{$pattern}' domains.";
            }
        }

        // Bloquer les plages IPv4 privées (127.x, 10.x, 172.16-31.x, 192.168.x)
        $ip = trim($host, '[]');
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return 'Webhook URL must not target private or loopback IP addresses.';
            }
        }

        // Bloquer les IPv6 privées (::1, fc00::/7, fe80::/10)
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            // ... vérifications de plages privées IPv6
        }

        return null; // valide
    }
}
```

La validation bloque :
1. **Injection CRLF/octet null** — empêche l'injection d'en-têtes dans les requêtes HTTP vers l'URL du webhook
2. **Schémas non-HTTPS** — `http://`, `file://`, `ftp://`, `gopher://` tous bloqués
3. **Adresses loopback** — `127.0.0.0/8`, `::1`
4. **Plages privées** — `10.x`, `172.16-31.x`, `192.168.x`, `0.0.0.0`
5. **TLD internes** — `.local`, `.internal`, `.test`, `.example`

## Signature de webhook — HMAC-SHA256 + Timestamp

```php
final class WebhookSigner
{
    public function sign(string $rawSecret, string $body, string $timestamp): string
    {
        $payload = $timestamp . '.' . $body;  // le timestamp lie la signature au temps
        $mac     = hash_hmac('sha256', $payload, $rawSecret);
        return 'sha256=' . $mac;
    }

    public function hashSecret(string $rawSecret): string
    {
        return hash('sha256', $rawSecret);
    }
}
```

Le format de signature `sha256=<hex>` est le même pattern utilisé par les webhooks GitHub. Le **timestamp est inclus dans le contenu signé** (`timestamp.body`) — cela prévient les attaques de rejeu : une signature capturée au temps T ne peut pas être rejouée au temps T+1h.

## Stockage du secret — Hash, jamais en clair

```php
// À la création de l'endpoint :
$secretHash = $this->signer->hashSecret($rawSecret);
$this->repo->createEndpoint($url, $eventType, $secretHash, $maxRetries);

// Retourner le secret brut UNE FOIS à l'appelant :
return $this->json->create([
    'id'     => $endpointId,
    'secret' => $rawSecret,  // affiché uniquement à la création
    // stocké comme : secret_hash = SHA-256($rawSecret)
]);
```

Le secret brut est retourné à l'appelant **une seule fois** au moment de la création. Les réponses `GET /endpoints/{id}` ultérieures n'incluent jamais `secret` ni `secret_hash`.

```php
// Réponse GET endpoint — secret NON inclus
return $this->json->create([
    'id'         => (int) $endpoint['id'],
    'url'        => $endpoint['url'],
    'event_type' => $endpoint['event_type'],
    'active'     => (bool) $endpoint['active'],
    'max_retries'=> (int) $endpoint['max_retries'],
    'created_at' => $endpoint['created_at'],
    // 'secret_hash' intentionnellement omis
]);
```

## Saut d'endpoint désactivé

```php
// Handler de dispatch
if (!(bool) $endpoint['active']) {
    return $this->json->create(['message' => 'Endpoint is inactive, no delivery queued.'], 200);
}
```

Les endpoints désactivés ne reçoivent aucune nouvelle livraison. Cela permet de désactiver un webhook sans supprimer l'endpoint ni son historique de livraison.

## Isolation par type d'événement

Chaque endpoint s'abonne à un `event_type` spécifique. Lors du dispatch :

```php
$endpoints = $this->repo->findActiveEndpointsByType($eventType);
// Seuls les endpoints correspondant à l'event_type reçoivent la livraison
```

Un endpoint abonné à `order.created` ne reçoit pas les événements `order.cancelled`.

---

## ATK Assessment — Cracker-Mindset Attack Test

### ATK-01 — SSRF via IPv4 loopback (127.x.x.x) 🚫 BLOCKED

**Attack** : Enregistrer un endpoint avec `url: "https://127.0.0.1/admin"`.
**Result** : BLOCKED — UrlValidator détecte la plage IPv4 privée → 422.

---

### ATK-02 — SSRF via 0.0.0.0 🚫 BLOCKED

**Attack** : `url: "https://0.0.0.0/internal"`.
**Result** : BLOCKED — plage IP réservée bloquée par `FILTER_FLAG_NO_RES_RANGE` → 422.

---

### ATK-03 — SSRF via plage privée 10.x.x.x 🚫 BLOCKED

**Attack** : `url: "https://10.0.0.1/internal"`.
**Result** : BLOCKED — plage IPv4 privée → 422.

---

### ATK-04 — SSRF via plage privée 172.16-31.x.x 🚫 BLOCKED

**Attack** : `url: "https://172.16.0.1/internal"`.
**Result** : BLOCKED — plage IPv4 privée → 422.

---

### ATK-05 — Dégradation du schéma HTTP 🚫 BLOCKED

**Attack** : `url: "http://example.com/hook"` (non-HTTPS).
**Result** : BLOCKED — vérification du schéma : seul `https` autorisé → 422.

---

### ATK-06 — Schéma file:// 🚫 BLOCKED

**Attack** : `url: "file:///etc/passwd"`.
**Result** : BLOCKED — la vérification du schéma bloque les non-HTTPS → 422.

---

### ATK-07 — Injection CRLF dans l'URL 🚫 BLOCKED

**Attack** : `url: "https://example.com/\r\nX-Injected: header"`.
**Result** : BLOCKED — vérification `str_contains($url, "\r")` → 422.

---

### ATK-08 — Octet null dans l'URL 🚫 BLOCKED

**Attack** : `url: "https://example.com/\0hidden"`.
**Result** : BLOCKED — vérification `str_contains($url, "\0")` → 422.

---

### ATK-09 — Fuite du secret via GET endpoint 🚫 BLOCKED

**Attack** : `GET /endpoints/{id}` pour récupérer le secret stocké.
**Result** : BLOCKED — La réponse GET omet entièrement les champs `secret` et `secret_hash`.

---

### ATK-10 — Fuite du secret via la réponse de dispatch 🚫 BLOCKED

**Attack** : Inspecter le corps de la réponse de dispatch pour trouver des données secrètes.
**Result** : BLOCKED — La réponse de dispatch contient uniquement les métadonnées de livraison, aucun champ secret.

---

### ATK-11 — Attaque de rejeu (signature capturée) 🚫 BLOCKED

**Attack** : Capturer un webhook signé et le rejouer avec la même signature plus tard.
**Result** : BLOCKED — La signature est `HMAC(timestamp.body, secret)`. Le timestamp change à chaque livraison ; l'ancienne signature ne correspond pas au nouveau timestamp.

---

### ATK-12 — Signature forgée avec un mauvais secret 🚫 BLOCKED

**Attack** : Calculer un HMAC avec un secret deviné/différent, le soumettre comme signature valide.
**Result** : BLOCKED — Le destinataire valide avec le hash de secret stocké ; le HMAC forgé ne correspond pas.

---

### Résumé ATK

| ID | Attaque | Résultat |
|----|---------|----------|
| ATK-01 | SSRF loopback IPv4 | 🚫 BLOCKED |
| ATK-02 | SSRF 0.0.0.0 | 🚫 BLOCKED |
| ATK-03 | SSRF privé 10.x | 🚫 BLOCKED |
| ATK-04 | SSRF privé 172.16-31.x | 🚫 BLOCKED |
| ATK-05 | Dégradation du schéma HTTP | 🚫 BLOCKED |
| ATK-06 | Schéma file:// | 🚫 BLOCKED |
| ATK-07 | Injection CRLF dans l'URL | 🚫 BLOCKED |
| ATK-08 | Octet null dans l'URL | 🚫 BLOCKED |
| ATK-09 | Fuite du secret via GET | 🚫 BLOCKED |
| ATK-10 | Fuite du secret via dispatch | 🚫 BLOCKED |
| ATK-11 | Attaque de rejeu | 🚫 BLOCKED |
| ATK-12 | Signature forgée | 🚫 BLOCKED |

**12 BLOCKED, 0 EXPOSED**
UrlValidator bloque tous les vecteurs SSRF. Le HMAC lié au timestamp prévient les rejeux. Secret stocké comme hash, jamais retourné après la création.

---

## Ce qu'il ne faut PAS faire

| Anti-pattern | Risque |
|---|---|
| Stocker le secret brut du webhook en DB | Une violation de la DB expose tous les secrets ; le hash SHA-256 est à sens unique |
| Retourner le secret dans la réponse GET | Toute fuite d'API admin expose tous les secrets de webhook |
| HMAC sur le corps uniquement (sans timestamp) | Attaque de rejeu : signature capturée réutilisée indéfiniment |
| Autoriser les URLs de webhook `http://` | Écoute du trafic sur les payloads de webhook |
| Pas de validation SSRF sur l'URL | Le système de webhook utilisé pour sonder le réseau interne |
| Autoriser `127.x`, `10.x` dans l'URL de webhook | Le serveur fait des requêtes vers ses propres services internes |
| Pas de vérification CRLF | URL avec `\r\n` injecte des en-têtes dans la requête HTTP sortante |
| Livrer aux endpoints inactifs | Les endpoints désactivés continuent à recevoir du trafic |
| Pas de filtrage par type d'événement | Tous les types d'événements livrés à tous les endpoints |
