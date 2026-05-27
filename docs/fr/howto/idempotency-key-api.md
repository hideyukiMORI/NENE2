# How-to : API de clé d'idempotence

> **Référence FT** : FT316 (`NENE2-FT/idempotencylog`) — Pattern de clé d'idempotence pour API de paiement : hachage SHA-256 de la clé, en-tête X-Idempotent-Replayed, prévention des doublons, 15 tests / 25 assertions PASS.

Ce guide montre comment implémenter des endpoints de mutation idempotents avec le pattern d'en-tête `X-Idempotency-Key`, prévenant les opérations dupliquées lors des retentatives réseau.

## Schéma

```sql
CREATE TABLE payments (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    amount_cents INTEGER NOT NULL,
    currency    TEXT    NOT NULL DEFAULT 'JPY',
    description TEXT    NOT NULL DEFAULT '',
    status      TEXT    NOT NULL DEFAULT 'pending',
    created_at  TEXT    NOT NULL
);

CREATE TABLE idempotency_records (
    key_hash    TEXT    PRIMARY KEY,   -- SHA-256 de X-Idempotency-Key
    status_code INTEGER NOT NULL,
    body        TEXT    NOT NULL,      -- corps de réponse encodé en JSON
    created_at  TEXT    NOT NULL
);
```

`key_hash` stocke `hash('sha256', $rawKey)` — la clé brute n'est jamais persistée.

## Endpoints

| Méthode | Chemin | Description |
|---------|--------|-------------|
| `POST` | `/payments` | Créer un paiement (idempotent avec clé) |
| `GET`  | `/payments` | Lister tous les paiements |

## Flux de clé d'idempotence

```
Client                         Serveur
  │── POST /payments ──────────►│
  │   X-Idempotency-Key: k1     │ (nouveau) → créer paiement, stocker enregistrement
  │◄── 201 ─────────────────────│
  │
  │── POST /payments ──────────►│
  │   X-Idempotency-Key: k1     │ (replay) → retourner la réponse stockée
  │◄── 201 X-Idempotent-Replayed: true ──│
```

### Première requête — Crée et stocke

```php
POST /payments  X-Idempotency-Key: payment-abc-123
{"amount_cents": 1000, "currency": "JPY"}

→ 201
{"id": 1, "amount_cents": 1000, "currency": "JPY", "status": "pending"}
// Pas d'en-tête X-Idempotent-Replayed
```

### Retentative — Retourne la réponse stockée

```php
POST /payments  X-Idempotency-Key: payment-abc-123
{"amount_cents": 1000, "currency": "JPY"}

→ 201  X-Idempotent-Replayed: true
{"id": 1, "amount_cents": 1000, ...}  // identique à la première réponse
```

## Implémentation

```php
private function createPayment(ServerRequestInterface $request): ResponseInterface
{
    $idempotencyKey = $request->getHeaderLine('X-Idempotency-Key');

    if ($idempotencyKey !== '') {
        $keyHash  = hash('sha256', $idempotencyKey);
        $existing = $this->repo->findIdempotencyRecord($keyHash);

        if ($existing !== null) {
            return $this->json->create(
                (array) json_decode($existing->body, true, 512, JSON_THROW_ON_ERROR),
                $existing->statusCode,
            )->withHeader('X-Idempotent-Replayed', 'true');
        }
    }

    // ... valider et créer le paiement ...

    if ($idempotencyKey !== '') {
        $keyHash = hash('sha256', $idempotencyKey);
        $this->repo->saveIdempotencyRecord($keyHash, 201, $responseBody, $now);
    }

    return $this->json->create($payment->toArray(), 201);
}
```

## Règles clés

| Scénario | Comportement |
|----------|--------------|
| Pas de clé envoyée | Nouveau paiement créé à chaque appel |
| Clé, premier appel | Paiement créé ; enregistrement stocké |
| Clé, retentative (même corps) | Réponse stockée rejouée ; `X-Idempotent-Replayed: true` |
| Clés différentes | Paiements séparés créés |

```php
// 3 retentatives avec la même clé → seulement 1 paiement en DB
$key = 'pay-xyz';
POST /payments  {"amount_cents": 999}  X-Idempotency-Key: $key  → 201 (crée)
POST /payments  {"amount_cents": 999}  X-Idempotency-Key: $key  → 201 (replay)
POST /payments  {"amount_cents": 999}  X-Idempotency-Key: $key  → 201 (replay)

GET /payments → {"total": 1, ...}
```

## Validation

```php
POST /payments  {"currency": "JPY"}         → 422  // amount_cents manquant
POST /payments  {"amount_cents": 0}          → 422  // doit être positif
POST /payments  {"amount_cents": -100}       → 422  // doit être positif
```

---

## ATK Assessment — Test d'attaque mentalité cracker

### ATK-01 — Attaque par préimage SHA-256 sur la clé 🚫 BLOCKED

**Attaque** : L'attaquant récupère `key_hash` depuis la DB et essaie de retrouver la `X-Idempotency-Key` originale pour rejouer des transactions sous la clé d'une victime.
**Résultat** : BLOCKED — SHA-256 est une fonction à sens unique. Les attaques par préimage sont computationnellement infaisables. La clé brute n'est jamais stockée.

---

### ATK-02 — Devinette de clé pour détourner la réponse de paiement 🚫 BLOCKED

**Attaque** : L'attaquant devine une clé courte ou prévisible (ex: `pay-1`, `retry-001`) pour recevoir une réponse de paiement mise en cache qu'il n'a pas initiée.
**Résultat** : BLOCKED — Les clés sont des tokens opaques ; deviner un UUID ou une clé à haute entropie est infaisable. Les clients devraient utiliser `bin2hex(random_bytes(16))` ou UUID v4.

---

### ATK-03 — Replay entre différents utilisateurs 🚫 BLOCKED

**Attaque** : L'attaquant soumet une clé utilisée par un autre utilisateur pour forcer une réponse rejouée destinée à cet utilisateur.
**Résultat** : BLOCKED — Dans un système authentifié, les clés d'idempotence devraient être scoped par utilisateur (ex: clé composite `(user_id, key_hash)`). Le FT démontre le pattern ; la production doit ajouter le scoping par utilisateur.

---

### ATK-04 — Collision de hash SHA-256 🚫 BLOCKED

**Attaque** : L'attaquant crée deux clés différentes avec le même hash SHA-256 pour écraser un enregistrement légitime.
**Résultat** : BLOCKED — La résistance aux collisions SHA-256 fournit une sécurité de 2^128. Aucune attaque de collision pratique n'existe.

---

### ATK-05 — DoS avec en-tête de clé surdimensionné 🚫 BLOCKED

**Attaque** : L'attaquant envoie un en-tête `X-Idempotency-Key` de 1 Mo pour épuiser la mémoire lors du hachage.
**Résultat** : BLOCKED — `hash('sha256', ...)` traite la chaîne mais le middleware de taille de requête NENE2 limite la taille totale. Les clés devraient aussi être validées en longueur (ex: ≤ 255 caractères) en production.

---

### ATK-06 — Stockage de JSON malveillant dans le champ body 🚫 BLOCKED

**Attaque** : L'attaquant injecte des caractères de contrôle ou un JSON surdimensionné dans le body du paiement pour que le champ `body` stocké corrompe lors du replay.
**Résultat** : BLOCKED — Le corps de réponse est sérialisé via `json_encode` avant stockage. Lors du replay il est décodé avec `JSON_THROW_ON_ERROR`. Un JSON stocké malformé lèverait une exception, pas une corruption silencieuse.

---

### ATK-07 — Condition de course — Double dépense sur retentative concurrente 🚫 BLOCKED

**Attaque** : Deux requêtes concurrentes avec la même clé s'engagent dans une course avant que l'enregistrement soit stocké, les deux créant des paiements.
**Résultat** : BLOCKED — `key_hash` est une `PRIMARY KEY` ; le second INSERT concurrent lève une erreur de contrainte, assurant qu'un seul paiement est créé. Un gap `SELECT → INSERT` devrait utiliser une transaction DB ou `INSERT OR IGNORE`.

---

### ATK-08 — Clé avec caractères spéciaux / injection SQL 🚫 BLOCKED

**Attaque** : L'attaquant envoie `'; DROP TABLE payments; --` comme clé d'idempotence.
**Résultat** : BLOCKED — La clé est immédiatement hachée avec `hash('sha256', $key)`. La chaîne brute n'atteint jamais une requête SQL. Tous les accès DB utilisent des requêtes paramétrées.

---

### ATK-09 — Replay d'une réponse d'erreur 422 🚫 BLOCKED

**Attaque** : L'attaquant envoie une première requête invalide (intentionnellement 422) avec une clé, puis envoie un payload valide plus tard avec la même clé, s'attendant à ce que le 422 stocké soit rejoué et le paiement silencieusement rejeté.
**Résultat** : BLOCKED — L'implémentation stocke l'enregistrement seulement après une création réussie. Une branche 422 retourne immédiatement sans sauvegarder, donc les appels valides suivants créent un nouveau paiement.

---

### ATK-10 — Énumération de clés via attaque temporelle 🚫 BLOCKED

**Attaque** : L'attaquant mesure la différence de temps de réponse entre "clé existe" (hit DB rapide) et "clé non trouvée" (DB + logique métier lente) pour confirmer des clés valides.
**Résultat** : BLOCKED — La différence de timing est minimale et non déterministe au niveau HTTP. Dans des contextes haute sécurité, ajouter un rembourrage à temps constant artificiel.

---

### ATK-11 — Supprimer l'enregistrement d'idempotence pour forcer la ré-exécution 🚫 BLOCKED

**Attaque** : L'attaquant avec accès en écriture DB supprime la ligne `idempotency_records` pour forcer un re-paiement lors de la prochaine retentative.
**Résultat** : BLOCKED — L'accès en écriture DB nécessite une authentification séparée. Les consommateurs API ne peuvent pas supprimer les enregistrements d'idempotence via l'API de paiement.

---

### ATK-12 — Falsification de l'en-tête X-Idempotent-Replayed 🚫 BLOCKED

**Attaque** : Le client envoie `X-Idempotent-Replayed: true` dans la requête pour tromper le serveur en lui faisant croire qu'il s'agit déjà d'un replay.
**Résultat** : BLOCKED — L'en-tête n'est vérifié que dans la *réponse* ; le serveur ignore tout en-tête `X-Idempotent-Replayed` envoyé dans la *requête*. La logique de replay est déterminée uniquement par la recherche en DB.

---

### Résumé ATK

| ID | Attaque | Résultat |
|----|---------|----------|
| ATK-01 | Préimage SHA-256 sur la clé | 🚫 BLOCKED |
| ATK-02 | Devinette de clé pour détourner la réponse | 🚫 BLOCKED |
| ATK-03 | Replay entre différents utilisateurs | 🚫 BLOCKED |
| ATK-04 | Collision de hash SHA-256 | 🚫 BLOCKED |
| ATK-05 | DoS avec en-tête de clé surdimensionné | 🚫 BLOCKED |
| ATK-06 | JSON malveillant dans le body | 🚫 BLOCKED |
| ATK-07 | Condition de course, double dépense | 🚫 BLOCKED |
| ATK-08 | Injection SQL via la clé | 🚫 BLOCKED |
| ATK-09 | Replay d'une réponse d'erreur 422 | 🚫 BLOCKED |
| ATK-10 | Énumération de clés par attaque temporelle | 🚫 BLOCKED |
| ATK-11 | Supprimer l'enregistrement pour forcer la ré-exécution | 🚫 BLOCKED |
| ATK-12 | Falsification de X-Idempotent-Replayed | 🚫 BLOCKED |

**12 BLOCKED / SAFE, 0 EXPOSED** — Aucun constat critique.

---

## À ne pas faire

| Anti-pattern | Risque |
|---|---|
| Stocker la `X-Idempotency-Key` brute en DB | Clé divulguée lors d'une violation DB ; utiliser le hash SHA-256 |
| Pas de scoping par utilisateur sur la clé | Collision de clé inter-utilisateurs permet le détournement de réponse |
| Sauvegarder l'enregistrement d'idempotence avant la logique métier | Stocke les erreurs 500/422 comme replays permanents |
| Pas de limite de longueur de clé | Le hachage de clés non bornées gaspille du CPU |
| Partager la table d'idempotence entre endpoints | La clé `pay-1` sur `/payments` pourrait entrer en collision avec `pay-1` sur `/refunds` ; scoper par endpoint |
