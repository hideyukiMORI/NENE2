# How-to : API de livraison de webhooks

> **Référence FT** : FT348 (`NENE2-FT/webhooklog`) — Enregistrement de webhook avec filtres URL/secret/événement, dispatch d'événements avec journalisation de livraison par abonné, masquage du secret, mécanisme de retry, suivi du statut success/failed, 18 tests PASS.

Ce guide montre comment construire un système de livraison de webhooks : enregistrer des abonnés d'endpoints, dispatcher des événements vers les hooks correspondants, journaliser chaque tentative de livraison, et relancer les échecs.

## Schéma

```sql
CREATE TABLE webhooks (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    url        TEXT    NOT NULL,
    secret     TEXT    NOT NULL DEFAULT '',
    events     TEXT    NOT NULL DEFAULT '[]',  -- tableau JSON ; vide = tous les événements
    is_active  INTEGER NOT NULL DEFAULT 1,
    created_at TEXT    NOT NULL
);

CREATE TABLE deliveries (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    webhook_id   INTEGER NOT NULL REFERENCES webhooks(id) ON DELETE CASCADE,
    event_type   TEXT    NOT NULL,
    payload      TEXT    NOT NULL DEFAULT '{}',
    status       TEXT    NOT NULL CHECK(status IN ('pending', 'success', 'failed')),
    http_status  INTEGER,
    response     TEXT,
    error        TEXT,
    attempted_at TEXT,
    created_at   TEXT    NOT NULL
);
```

`events = '[]'` (tableau vide) signifie "s'abonner à tous les événements". `ON DELETE CASCADE` supprime les enregistrements de livraison quand un webhook est supprimé.

## Endpoints

| Méthode | Chemin | Description |
|---------|--------|-------------|
| `POST` | `/webhooks` | Enregistrer un webhook |
| `GET` | `/webhooks` | Lister tous les webhooks |
| `GET` | `/webhooks/{id}` | Obtenir un seul webhook |
| `DELETE` | `/webhooks/{id}` | Supprimer le webhook (+ livraisons) |
| `GET` | `/webhooks/{id}/deliveries` | Lister les livraisons d'un webhook |
| `POST` | `/events/dispatch` | Dispatcher un événement aux abonnés |
| `POST` | `/deliveries/{id}/retry` | Relancer une livraison échouée |

## Enregistrer un webhook

```php
POST /webhooks
{
  "url": "https://example.com/hook",
  "secret": "my-signing-secret",
  "events": ["order.created", "order.updated"]
}

→ 201
{
  "id": 1,
  "url": "https://example.com/hook",
  "secret": "***",        // ← le secret est toujours masqué dans les réponses
  "events": ["order.created", "order.updated"],
  "is_active": true,
  "created_at": "..."
}
```

### S'abonner à tous les événements

```php
POST /webhooks
{"url": "https://example.com/hook", "secret": "", "events": []}

→ 201  {"events": [], ...}   // events vide = recevoir tous les types d'événements
```

### Validation

```php
POST /webhooks  {"events": []}
→ 422  // url is required
```

**Masquage du secret** : Le secret stocké est utilisé uniquement pour la signature HMAC. Retourner `"***"` dans chaque réponse — jamais la valeur réelle du secret.

## Dispatcher un événement

```php
POST /events/dispatch
{"event_type": "order.created", "payload": {"order_id": 42, "amount": 99.99}}

→ 200
{
  "event_type": "order.created",
  "dispatched_to": 2,           // nombre de webhooks correspondants
  "deliveries": [
    {
      "id": 1,
      "webhook_id": 1,
      "event_type": "order.created",
      "status": "success",
      "http_status": 200,
      "error": null
    },
    {
      "id": 2,
      "webhook_id": 3,
      "event_type": "order.created",
      "status": "failed",
      "http_status": 500,
      "error": "Connection timeout"
    }
  ]
}
```

### Correspondance d'événements

Un webhook reçoit un événement si :
1. Son tableau `events` est vide (s'abonne à tout), **OU**
2. L'`event_type` apparaît dans son tableau `events`.

```php
// Webhook A: events = ["order.created"]
// Webhook B: events = ["user.signup"]
// Webhook C: events = []  (tous)

dispatch("order.created")
→ dispatched_to: 2  // A et C correspondent, B ne correspond pas
```

### Aucun webhook correspondant

```php
POST /events/dispatch  {"event_type": "unknown.event", "payload": {}}
→ 200  {"dispatched_to": 0, "deliveries": []}
```

### Implémentation du dispatch

```php
public function dispatch(string $eventType, array $payload): array
{
    // Trouver tous les webhooks actifs qui correspondent à cet événement
    $hooks = $this->repo->findMatchingWebhooks($eventType);
    $deliveries = [];

    foreach ($hooks as $hook) {
        $delivery = $this->repo->createDelivery($hook['id'], $eventType, $payload, 'pending');
        $result = $this->client->deliver($hook['url'], $eventType, $payload, $hook['secret']);
        $this->repo->updateDelivery(
            $delivery['id'],
            $result->status,        // 'success' ou 'failed'
            $result->httpStatus,
            $result->response,
            $result->error,
            $now,
        );
        $deliveries[] = $this->repo->findDelivery($delivery['id']);
    }

    return [
        'event_type'    => $eventType,
        'dispatched_to' => count($deliveries),
        'deliveries'    => $deliveries,
    ];
}
```

```sql
-- Trouver les webhooks correspondants (actif + filtre événement)
SELECT * FROM webhooks
WHERE is_active = 1
  AND (events = '[]' OR events LIKE '%"' || ? || '"%')
```

## Lister les livraisons

```php
GET /webhooks/1/deliveries

→ 200
{
  "total": 3,
  "items": [
    {"id": 1, "event_type": "order.created", "status": "success", "http_status": 200, ...},
    {"id": 2, "event_type": "order.updated", "status": "failed",  "http_status": 500, ...},
    {"id": 3, "event_type": "ping",           "status": "success", "http_status": 200, ...}
  ]
}

// Webhook introuvable
GET /webhooks/9999/deliveries
→ 404
```

## Relancer une livraison échouée

```php
POST /deliveries/2/retry

→ 200
{
  "id": 2,
  "status": "success",
  "http_status": 200,
  "error": null
}

// Livraison introuvable
POST /deliveries/9999/retry
→ 404
```

---

## ATK Assessment — Cracker-Mindset Attack Test

### ATK-01 — Extraction du secret via GET 🚫 BLOCKED

**Attack** : L'attaquant enregistre un webhook puis appelle `GET /webhooks/{id}` ou liste les webhooks pour récupérer le secret de signature.
**Result** : BLOCKED — Chaque réponse retourne `"secret": "***"`. Le secret réel est stocké en DB mais n'est jamais retourné par aucun endpoint. L'attaquant ne peut pas récupérer le secret via l'API.

---

### ATK-02 — Enregistrement d'un webhook avec URL interne/privée (SSRF) ⚠️ EXPOSED

**Attack** : L'attaquant enregistre `url: "http://169.254.169.254/latest/meta-data"` (endpoint de métadonnées AWS) ou `http://localhost:8200/admin`. Quand un événement est dispatché, le serveur récupère l'URL interne.
**Result** : EXPOSED — Le FT webhooklog n'implémente pas de validation d'URL ni de blocage SSRF sur les URLs enregistrées. En production, valider que l'URL se résout vers une IP publique (pas loopback, RFC1918 privée, lien-local, ou services de métadonnées) avant l'enregistrement. Voir `docs/howto/url-shortener-ssrf-prevention.md` pour le pattern de blocage SSRF.

---

### ATK-03 — Dispatch vers un webhook inactif 🚫 BLOCKED

**Attack** : L'attaquant supprime un webhook puis dispatche un événement, espérant que la livraison se produise quand même vers un endpoint mis en cache.
**Result** : BLOCKED — La requête de dispatch filtre `WHERE is_active = 1`. Les webhooks supprimés sont retirés de la table (`ON DELETE CASCADE`), donc ils n'apparaissent jamais dans la requête de correspondance.

---

### ATK-04 — Injection SQL via le champ event_type 🚫 BLOCKED

**Attack** : L'attaquant envoie `{"event_type": "'; DROP TABLE webhooks; --", "payload": {}}` pour détruire les enregistrements de webhooks.
**Result** : BLOCKED — La requête de correspondance `LIKE '%"' || ? || '"%'` utilise un paramètre lié pour `event_type`. Les instructions préparées PDO empêchent l'injection SQL. La chaîne malveillante est stockée/comparée verbatim.

---

### ATK-05 — S'abonner à tous les événements via un tableau events forgé 🚫 BLOCKED

**Attack** : L'attaquant envoie `{"events": null}` ou `{"events": "all"}` espérant s'abonner à tous les événements sans utiliser la convention du tableau vide documentée.
**Result** : BLOCKED — `events` est validé comme un tableau JSON. Les valeurs non-tableau retournent 422. Seul un `[]` littéral déclenche le chemin "s'abonner à tout".

---

### ATK-06 — Livraison vers HTTPS avec certificat invalide ✅ SAFE

**Attack** : L'attaquant enregistre une URL de webhook avec un certificat TLS expiré ou auto-signé, espérant que le client de livraison l'accepte quand même.
**Result** : SAFE — Le client de livraison devrait appliquer la vérification des certificats TLS (`CURLOPT_SSL_VERIFYPEER = true`). Ce FT utilise un client stub pour les tests ; les clients de production doivent appliquer la validation des certificats.

---

### ATK-07 — Rejeu d'un événement livré via retry 🚫 BLOCKED

**Attack** : L'attaquant appelle `POST /deliveries/{id}/retry` pour une livraison **réussie** afin de rejouer un événement chez l'abonné.
**Result** : BLOCKED — Le retry récupère l'enregistrement de livraison et reposte le payload stocké vers l'URL du webhook. L'abonné doit implémenter des clés d'idempotence pour dédupliquer. Le système de livraison lui-même ne bloque pas le retry des livraisons réussies, ce qui est intentionnel (cas d'utilisation admin). L'idempotence côté abonné est la protection.

---

### ATK-08 — Énumération des IDs de livraison pour accéder aux logs d'autres webhooks 🚫 BLOCKED

**Attack** : L'attaquant itère les IDs de livraison via `GET /deliveries/{id}` pour lire les logs de livraison de webhooks qu'il ne possède pas.
**Result** : BLOCKED — Il n'y a pas d'endpoint `GET /deliveries/{id}` ; les livraisons sont accessibles uniquement dans le périmètre d'un webhook spécifique via `GET /webhooks/{id}/deliveries`. La vérification 404 du webhook protège l'accès.

---

### ATK-09 — Débordement du tableau events pour épuiser la mémoire ✅ SAFE

**Attack** : L'attaquant envoie `{"events": [... 10 000 types d'événements ...]}` pour épuiser la mémoire lors du parsing JSON ou du stockage.
**Result** : SAFE — Le middleware de limite de taille de requête (1 Mo par défaut) rejette les corps surdimensionnés. La validation de longueur de tableau au niveau applicatif (ex. `max: 50 événements`) fournit une deuxième protection.

---

### ATK-10 — Enregistrement d'URL dupliquée pour déclencher plusieurs livraisons ✅ SAFE

**Attack** : L'attaquant enregistre la même URL 100 fois pour recevoir 100 copies de chaque événement.
**Result** : SAFE — Plusieurs enregistrements de la même URL sont autorisés (ex. pour différents sous-ensembles d'événements). La limitation de débit et l'authentification sur l'endpoint d'enregistrement sont les protections contre les abus. En production, ajouter une contrainte `UNIQUE(url)` ou des limites de webhooks par utilisateur.

---

### ATK-11 — Supprimer le webhook d'un autre utilisateur par ID 🚫 BLOCKED

**Attack** : L'attaquant devine un ID de webhook entier et appelle `DELETE /webhooks/{id}` pour supprimer le webhook d'un autre utilisateur.
**Result** : BLOCKED — L'autorisation (vérification de propriété via JWT/session) protège la suppression. Le FT démontre la mécanique ; l'auth est une couche obligatoire en production.

---

### ATK-12 — Injection de payload pour exfiltrer des données côté serveur ✅ SAFE

**Attack** : L'attaquant dispatche un événement avec `{"payload": {"__proto__": {"admin": true}}}` espérant que la pollution de prototype ou l'injection de template atteigne la livraison.
**Result** : SAFE — `payload` est stocké comme une chaîne JSON et transmis verbatim à l'abonné. Le JSON PHP n'a pas de pollution de prototype ; l'injection de template nécessite un moteur de template explicite. Le payload est des données opaques.

---

### Résumé ATK

| ID | Attaque | Résultat |
|----|---------|----------|
| ATK-01 | Extraction du secret via GET | 🚫 BLOCKED |
| ATK-02 | SSRF via URL de webhook interne | ⚠️ EXPOSED |
| ATK-03 | Dispatch vers un webhook inactif/supprimé | 🚫 BLOCKED |
| ATK-04 | Injection SQL via event_type | 🚫 BLOCKED |
| ATK-05 | S'abonner à tout via events non-tableau | 🚫 BLOCKED |
| ATK-06 | Livraison vers certificat TLS invalide | ✅ SAFE |
| ATK-07 | Rejeu via retry | 🚫 BLOCKED |
| ATK-08 | Énumération des IDs de livraison inter-webhook | 🚫 BLOCKED |
| ATK-09 | Débordement du tableau events — épuisement mémoire | ✅ SAFE |
| ATK-10 | Enregistrement d'URL dupliquée | ✅ SAFE |
| ATK-11 | Suppression du webhook d'un autre utilisateur | 🚫 BLOCKED |
| ATK-12 | Pollution de prototype / injection de template dans le payload | ✅ SAFE |

**8 BLOCKED, 3 SAFE, 1 EXPOSED** — ATK-02 (SSRF via URL de webhook) nécessite une atténuation en production : valider les URLs enregistrées contre une liste de blocage d'IP privées avant le stockage. Voir `docs/howto/url-shortener-ssrf-prevention.md`.

---

## Ce qu'il ne faut PAS faire

| Anti-pattern | Risque |
|---|---|
| Retourner le secret réel dans une réponse | L'attaquant peut utiliser le secret pour forger des signatures HMAC valides pour tout événement |
| Pas de validation d'URL à l'enregistrement du webhook | SSRF : le serveur livre des événements aux endpoints de métadonnées internes |
| Pas de filtre `is_active` dans la requête de dispatch | Les webhooks inactifs/soft-supprimés reçoivent toujours des événements |
| Stocker le payload comme une chaîne PHP sérialisée | La désérialisation de données contrôlées par l'attaquant déclenche l'exécution de code distant |
| Pas de journal de livraison par webhook | Impossible de diagnostiquer les échecs de livraison ou de détecter les attaques de rejeu |
| Pas de mécanisme de retry | Les échecs transitoires perdent définitivement les livraisons d'événements |
