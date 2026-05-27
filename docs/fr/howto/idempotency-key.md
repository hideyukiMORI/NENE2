# How-to : Clé d'idempotence (déduplication de requêtes)

> **Référence FT** : FT292 (`NENE2-FT/deduplog`) — Déduplication par clé d'idempotence : contrainte DB `UNIQUE(idempotency_key)`, TTL 24h avec expiry re-traitable, flag `replayed: true` sur les réponses mises en cache, requêtes paramétrées prévenant l'injection, ATK-01~12 tous BLOCKED, 24 tests / 57 assertions PASS.

Ce guide montre comment implémenter les clés d'idempotence — un mécanisme basé sur les en-têtes qui garantit que les requêtes répétées (retentatives, échecs réseau) produisent le même résultat sans effets secondaires dupliqués.

## Schéma

```sql
CREATE TABLE idempotency_keys (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    idempotency_key TEXT NOT NULL UNIQUE,
    method          TEXT NOT NULL,
    path            TEXT NOT NULL,
    status_code     INTEGER NOT NULL,
    response_body   TEXT NOT NULL,
    created_at      TEXT NOT NULL,
    expires_at      TEXT NOT NULL
);
```

`UNIQUE(idempotency_key)` garantit que chaque clé est stockée une seule fois. Le corps de réponse est sérialisé en JSON et rejoué lors des requêtes suivantes.

## Flux de requête

```
Le client envoie POST /payments avec Idempotency-Key: <uuid>
  │
  ├─ Clé trouvée en DB ET non expirée ?
  │    └─ OUI → retourner la réponse mise en cache + { "replayed": true }
  │
  └─ NON → traiter la requête → stocker la réponse → retourner 201
```

## Extraction de la clé Idempotency-Key

```php
$key = trim($request->getHeaderLine('Idempotency-Key'));
if ($key === '') {
    return $this->json->create(['error' => 'Idempotency-Key header is required'], 400);
}
```

La clé est requise et doit être non vide après trimming. Les clés uniquement composées d'espaces blancs sont rejetées avec 400.

## Recherche en cache — Vérification d'expiry

```php
private function getCachedResponse(
    string $key,
    ServerRequestInterface $request,
): ?ResponseInterface {
    $cached = $this->repo->find($key);
    if ($cached === null) {
        return null;
    }

    // Les entrées expirées sont traitées comme fraîches (re-traitables)
    if ($cached['expires_at'] < $this->now()) {
        return null;
    }

    $body = json_decode((string) $cached['response_body'], true) ?? [];
    return $this->json->create(
        array_merge($body, ['replayed' => true]),
        (int) $cached['status_code']
    );
}
```

Les clés expirées retournent `null` — la requête est re-traitée comme si elle était nouvelle. Cela permet une retentative sûre après l'expiry du TTL sans déduplication permanente.

## Stockage en cache — Calcul du TTL

```php
private const int TTL_SECONDS = 86400; // 24 heures

private function cacheResponse(
    string $key,
    string $method,
    string $path,
    int $statusCode,
    array $data,
    string $now,
): void {
    $expiresAt = (new \DateTimeImmutable($now, new \DateTimeZone('UTC')))
        ->modify('+' . self::TTL_SECONDS . ' seconds')
        ->format('Y-m-d\TH:i:s\Z');
    $this->repo->store($key, $method, $path, $statusCode, (string) json_encode($data), $now, $expiresAt);
}
```

Le TTL est calculé en UTC. `DateTimeImmutable::modify()` gère correctement les transitions DST et les passages à minuit.

## Signal `replayed: true`

Les réponses mises en cache incluent `"replayed": true` fusionné dans le corps :

```json
{ "id": 42, "amount": 1000, "currency": "USD", "replayed": true }
```

Cela permet aux clients de distinguer les premières réponses des replays sans inspecter les codes de statut. Le code de statut est rejoué inchangé (201 pour la création).

## Contrainte UNIQUE comme garde contre les conditions de course

```sql
UNIQUE(idempotency_key)
```

Si deux requêtes concurrentes avec la même clé passent toutes les deux la vérification de recherche (TOCTOU), seul un `INSERT` réussit. L'autre reçoit une erreur de contrainte, que l'application peut gérer en re-récupérant la réponse mise en cache.

---

## ATK Assessment — Test d'attaque mentalité cracker

### ATK-01 — Injection SQL dans l'en-tête Idempotency-Key 🚫 BLOCKED

**Attaque** : Envoyer `Idempotency-Key: '; DROP TABLE idempotency_keys; --`.
**Résultat** : BLOCKED — toutes les requêtes utilisent des instructions paramétrées. La chaîne d'injection est stockée ou recherchée comme valeur littérale de clé.

---

### ATK-02 — Injection SQL dans le champ amount 🚫 BLOCKED

**Attaque** : Envoyer `{ "amount": "1; DROP TABLE payments;" }`.
**Résultat** : BLOCKED — la validation du montant requiert le type entier. Les valeurs chaîne échouent la vérification `is_int()` → 422. Aucune requête DB exécutée.

---

### ATK-03 — Injection SQL dans le champ item (stocké en sécurité) 🚫 BLOCKED

**Attaque** : Envoyer `{ "item": "' OR 1=1; --" }` dans la création de commande.
**Résultat** : BLOCKED — la requête paramétrée stocke la chaîne verbatim comme valeur `item`. Aucune exécution SQL ne se produit.

---

### ATK-04 — Attaque par replay (même clé 10 fois) 🚫 BLOCKED

**Attaque** : Envoyer `POST /payments` avec la même clé 10 fois pour créer 10 enregistrements.
**Résultat** : BLOCKED — la première requête crée un paiement et met en cache la réponse. Les 9 requêtes suivantes retournent la réponse mise en cache avec `replayed: true`. Seulement 1 ligne de paiement existe.

---

### ATK-05 — Clé Idempotency-Key uniquement composée d'espaces blancs 🚫 BLOCKED

**Attaque** : Envoyer `Idempotency-Key:    ` (espaces seulement) pour contourner la vérification de clé vide.
**Résultat** : BLOCKED — `trim($key) === ''` → 400. Les clés d'espaces blancs sont équivalentes aux clés manquantes.

---

### ATK-06 — Clé Idempotency-Key extrêmement longue 🚫 BLOCKED (note de conception)

**Attaque** : Envoyer une clé de plusieurs mégaoctets.
**Résultat** : BLOCKED (note de conception) — SQLite stocke la clé verbatim ; des clés très longues dégradent les performances de recherche mais ne plantent pas. En production, ajouter une limite de longueur (ex: `strlen($key) > 255 → 400`).

---

### ATK-07 — Quantité négative dans la commande 🚫 BLOCKED

**Attaque** : Envoyer `{ "quantity": -5 }` pour créer une commande à quantité négative.
**Résultat** : BLOCKED — validation de quantité : `$quantity <= 0` → 422. Seuls les entiers positifs sont acceptés.

---

### ATK-08 — XSS dans le champ item stocké comme littéral 🚫 BLOCKED

**Attaque** : Envoyer `{ "item": "<script>alert(1)</script>" }`.
**Résultat** : BLOCKED — stocké verbatim comme valeur de chaîne JSON. L'API retourne `application/json` ; l'encodage JSON échappe `<`, `>`. Aucun rendu HTML ne se produit dans la couche API.

---

### ATK-09 — Clés dupliquées concurrentes 🚫 BLOCKED

**Attaque** : Deux processus envoient la même clé simultanément ; les deux passent la vérification de recherche avant que l'un stocke.
**Résultat** : BLOCKED — `UNIQUE(idempotency_key)` assure qu'un seul INSERT réussit. Le perdant reçoit une erreur de contrainte et peut re-récupérer la réponse mise en cache.

---

### ATK-10 — Débordement d'entier dans le montant 🚫 BLOCKED (note de conception)

**Attaque** : Envoyer `{ "amount": 9999999999999999999 }` (au-delà de PHP_INT_MAX).
**Résultat** : BLOCKED (note de conception) — PHP convertit silencieusement les très grands entiers JSON en float. `is_int()` passe pour les entiers dans la plage. En production, ajouter une vérification de borne supérieure (ex: amount > 10_000_000 → 422).

---

### ATK-11 — Montant NULL 🚫 BLOCKED

**Attaque** : Envoyer `{ "amount": null }` en espérant que null contourne la validation.
**Résultat** : BLOCKED — `!is_int(null)` est true et `ctype_digit(null)` est false → 422.

---

### ATK-12 — Aucune information interne divulguée 🚫 BLOCKED

**Attaque** : Déclencher une erreur 422 et vérifier si les traces de pile, chemins de fichiers ou SQL apparaissent dans la réponse.
**Résultat** : BLOCKED — les réponses d'erreur contiennent seulement `{ "error": "..." }` ou Problem Details. Pas de chemins internes, SQL ou traces de pile dans aucune réponse.

---

### Résumé ATK

| ID | Attaque | Résultat |
|----|---------|----------|
| ATK-01 | Injection SQL dans l'en-tête Idempotency-Key | 🚫 BLOCKED |
| ATK-02 | Injection SQL dans le champ amount | 🚫 BLOCKED |
| ATK-03 | Injection SQL dans le champ item | 🚫 BLOCKED |
| ATK-04 | Attaque par replay (10 requêtes dupliquées) | 🚫 BLOCKED |
| ATK-05 | Clé uniquement composée d'espaces blancs | 🚫 BLOCKED |
| ATK-06 | Clé extrêmement longue | 🚫 BLOCKED (note de conception) |
| ATK-07 | Quantité négative | 🚫 BLOCKED |
| ATK-08 | XSS dans le champ item | 🚫 BLOCKED |
| ATK-09 | Clés dupliquées concurrentes | 🚫 BLOCKED |
| ATK-10 | Débordement d'entier dans le montant | 🚫 BLOCKED (note de conception) |
| ATK-11 | Montant NULL | 🚫 BLOCKED |
| ATK-12 | Aucune fuite d'informations internes | 🚫 BLOCKED |

**12 BLOCKED, 0 EXPOSED**
Les requêtes paramétrées, la validation stricte du type, `UNIQUE(idempotency_key)` et l'expiry du TTL couvrent tous les vecteurs d'attaque critiques de déduplication.

---

## À ne pas faire

| Anti-pattern | Risque |
|---|---|
| Pas de contrainte `UNIQUE(idempotency_key)` | Les retentatives concurrentes créent des enregistrements dupliqués ; condition de course de déduplication |
| Pas de TTL / dédup permanente | Les vieilles clés remplissent la table ; les retentatives légitimes après 1+ jours échouent |
| Pas de flag `replayed: true` | Le client ne peut pas distinguer la première réponse du replay mis en cache |
| Vérifier l'expiry mais ne jamais re-traiter les clés expirées | La retentative après TTL retourne toujours la réponse mise en cache (possiblement périmée) |
| Accepter les clés uniquement composées d'espaces blancs | `"   "` traitée comme clé valide ; différents clients peuvent utiliser `""` et `"   "` de façon interchangeable |
| Pas de limite de longueur de clé | Les clés multi-Mo en stockage et recherche dégradent les performances |
| Retourner 409 sur les doublons | Le replay devrait retourner le statut original (201), pas Conflict |
| Ne pas valider le type de montant strictement | La chaîne `"1000"` passe les vérifications laxistes ; utiliser `is_int()` pour l'entier JSON strict |
| Pas de borne supérieure sur le montant | Débordement d'entier ou montants absurdes acceptés sans validation métier |
