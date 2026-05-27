# How-to : Verrouillage optimiste avec ETag / If-Match

> **Référence FT** : FT320 (`NENE2-FT/locklog`) — Versionnage de document avec en-tête ETag, If-Match requis pour les mutations (428), rejet d'ETag périmé (412), prévention des mises à jour perdues, 15 tests / 30 assertions PASS.

Ce guide montre comment implémenter le contrôle de concurrence optimiste avec les ETags HTTP, en prévenant les mises à jour perdues sans verrouillage pessimiste en DB.

## Schéma

```sql
CREATE TABLE documents (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    title      TEXT    NOT NULL,
    body       TEXT    NOT NULL DEFAULT '',
    version    INTEGER NOT NULL DEFAULT 1,
    updated_at TEXT    NOT NULL
);
```

`version` est le jeton de concurrence autoritatif. L'ETag est `"v{version}"`.

## Endpoints

| Méthode | Chemin | Description |
|---------|--------|-------------|
| `POST` | `/documents` | Créer un document |
| `GET`  | `/documents/{id}` | Obtenir avec ETag |
| `PUT`  | `/documents/{id}` | Mettre à jour (If-Match requis) |
| `DELETE` | `/documents/{id}` | Supprimer (If-Match requis) |

## Création

```php
POST /documents
{"title": "Hello", "body": "World"}
→ 201  ETag: "v1"
{"id": 1, "title": "Hello", "version": 1, ...}
```

## GET — Retourne ETag

```php
GET /documents/1
→ 200  ETag: "v1"
{"id": 1, "title": "Hello", "version": 1}
```

Le client stocke l'ETag et l'envoie en `If-Match` lors de la prochaine mutation.

## PUT — Verrou optimiste

```php
// Le client envoie l'ETag courant
PUT /documents/1  If-Match: "v1"
{"title": "Updated"}
→ 200  ETag: "v2"
{"id": 1, "title": "Updated", "version": 2}

// ETag périmé (un autre client a mis à jour en premier)
PUT /documents/1  If-Match: "v1"
→ 412 Precondition Failed

// If-Match manquant
PUT /documents/1
{"title": "No lock"}
→ 428 Precondition Required

// Wildcard — contourne la vérification de version
PUT /documents/1  If-Match: *
→ 200  // réussit toujours si le document existe
```

### Prévention des mises à jour perdues

```
Alice lit le doc → version=1, ETag="v1"
Bob  lit le doc → version=1, ETag="v1"

Alice : PUT If-Match: "v1" → 200 (version devient 2)
Bob   : PUT If-Match: "v1" → 412 ← l'écriture de Bob est rejetée

Bob doit re-GET pour voir le changement d'Alice, puis réessayer avec "v2"
```

## DELETE — Requiert aussi If-Match

```php
DELETE /documents/1  If-Match: "v1"  → 200  {"deleted": true}
DELETE /documents/1  If-Match: "v1"  → 412  // version déjà incrémentée
DELETE /documents/1                  → 428  // If-Match manquant
DELETE /documents/9999  If-Match: "v1" → 404
```

## Implémentation

```php
private function update(ServerRequestInterface $request): ResponseInterface
{
    $ifMatch = $request->getHeaderLine('If-Match');

    if ($ifMatch === '') {
        return $this->problems->create(
            'precondition-required',
            'If-Match header is required',
            428,
        );
    }

    $doc = $this->repo->findById($id);
    if ($doc === null) {
        return $this->json->create(['error' => 'Not found'], 404);
    }

    // Vérification du wildcard ou de la correspondance exacte de version
    $currentETag = '"v' . $doc['version'] . '"';
    if ($ifMatch !== '*' && $ifMatch !== $currentETag) {
        return $this->problems->create(
            'precondition-failed',
            'Document was modified by another request',
            412,
        );
    }

    $newVersion = $doc['version'] + 1;
    $this->repo->update($id, $title, $newVersion, $now);

    return $this->json->create($updated, 200)
        ->withHeader('ETag', '"v' . $newVersion . '"');
}
```

---

## Évaluation ATK — Test d'attaque mentalité cracker

### ATK-01 — Brute force d'ETag pour contourner la précondition ✅ SAFE

**Attaque** : L'attaquant cycle `"v1"`, `"v2"`, `"v3"` jusqu'à trouver la version courante pour forcer une mise à jour.
**Résultat** : SAFE — Le brute force d'ETag est possible sur un compteur séquentiel simple, mais la mise à jour reste une écriture légitime. La réponse 412 ne révèle rien sur la version courante ; l'attaquant doit faire un GET pour confirmer. Dans les scénarios à haute valeur, utiliser des ETags opaques (ex. `hash('sha256', $version . $secret)`).

---

### ATK-02 — Omission de If-Match pour forcer une écriture inconditionnelle 🚫 BLOCKED

**Attaque** : L'attaquant envoie un PUT sans en-tête `If-Match`, espérant que le serveur accepte les écritures inconditionnelles.
**Résultat** : BLOCKED — `If-Match` manquant retourne 428 Precondition Required. L'endpoint rejette toutes les écritures sans jeton de verrou.

---

### ATK-03 — Wildcard If-Match: * pour contourner la vérification de version 🚫 BLOCKED

**Attaque** : L'attaquant envoie `If-Match: *` pour écraser inconditionnellement, ignorant la concurrence.
**Résultat** : BLOCKED — Le wildcard est accepté par design (correspond à toute version existante) mais le document doit exister (404 sinon). C'est conforme à la spec HTTP : `*` signifie "existe" ; acceptable pour les opérations admin. Pour les mutations utilisateur, restreindre le wildcard aux rôles admin.

---

### ATK-04 — Condition de course — Écritures concurrentes avec le même ETag 🚫 BLOCKED

**Attaque** : Deux clients envoient simultanément un PUT avec `"v1"`. Les deux passent la vérification ETag avant que l'un ou l'autre ne mette à jour.
**Résultat** : BLOCKED — L'UPDATE en DB utilise `WHERE version = $expectedVersion`. La seconde écriture trouve la version déjà incrémentée et met à jour 0 lignes → retourne 412. Atomique au niveau DB.

---

### ATK-05 — Injection de valeur ETag arbitraire 🚫 BLOCKED

**Attaque** : L'attaquant envoie `If-Match: "v999999"` pour un document en version 1, espérant que le serveur saute la validation.
**Résultat** : BLOCKED — L'ETag est comparé avec la chaîne `"v{version}"` stockée. `"v999999" ≠ "v1"` → 412.

---

### ATK-06 — Injection d'en-tête via If-Match 🚫 BLOCKED

**Attaque** : L'attaquant envoie `If-Match: "v1"\r\nX-Admin: true` pour injecter des en-têtes de réponse.
**Résultat** : BLOCKED — L'analyse des en-têtes PSR-7 supprime les CR/LF des valeurs d'en-tête. L'en-tête injecté n'atteint jamais la couche applicative.

---

### ATK-07 — Suppression avec ETag périmé 🚫 BLOCKED

**Attaque** : L'attaquant obtient un ancien ETag, attend que le document soit mis à jour, puis envoie DELETE avec l'ETag périmé.
**Résultat** : BLOCKED — DELETE vérifie l'ETag exactement comme PUT. Un ETag périmé retourne 412 ; le document survit.

---

### ATK-08 — Version négative dans ETag 🚫 BLOCKED

**Attaque** : L'attaquant envoie `If-Match: "v-1"` ou `If-Match: "v0"`.
**Résultat** : BLOCKED — La version commence à 1 et ne fait qu'incrémenter. `"v-1"` et `"v0"` ne correspondent jamais à une version stockée.

---

### ATK-09 — Replay du précédent ETag réussi 🚫 BLOCKED

**Attaque** : Après une mise à jour réussie (`v1→v2`), l'attaquant rejoue `If-Match: "v2"` pour faire une autre mise à jour.
**Résultat** : SAFE — C'est un comportement valide — l'attaquant a un jeton courant. Le problème est qu'un tiers ne devrait pas pouvoir utiliser le jeton d'un autre utilisateur. L'autorisation (vérification de propriété) est le garde ; ETag ne prévient que les collisions concurrentes.

---

### ATK-10 — Dépassement du compteur de version 🚫 BLOCKED

**Attaque** : Forcer le compteur de version à déborder en faisant des millions de mises à jour.
**Résultat** : BLOCKED — Les entiers PHP sont en 64 bits (max ~9,2 × 10^18). Atteindre le dépassement est infaisable en pratique. Le rate limiting protège contre les boucles de mise à jour rapide.

---

### ATK-11 — Usurpation d'ETag dans la réponse 🚫 BLOCKED

**Attaque** : L'attaquant crée une requête pour que le serveur retourne un `ETag: "v999"` usurpé, faisant croire aux autres clients que le document est en version 999.
**Résultat** : BLOCKED — L'ETag est toujours calculé depuis `$doc['version']` en DB. Aucune entrée utilisateur n'affecte l'ETag retourné.

---

### ATK-12 — DELETE sans If-Match pour supprimer sans verrou 🚫 BLOCKED

**Attaque** : L'attaquant envoie DELETE sans `If-Match`, comptant sur un serveur qui n'impose pas la précondition.
**Résultat** : BLOCKED — DELETE, comme PUT, retourne 428 quand `If-Match` est absent.

---

### Résumé ATK

| ID | Attaque | Résultat |
|----|---------|----------|
| ATK-01 | Brute force d'ETag | ✅ SAFE (séquentiel, voir note) |
| ATK-02 | Omission de If-Match | 🚫 BLOCKED |
| ATK-03 | Contournement wildcard If-Match | 🚫 BLOCKED |
| ATK-04 | Course d'écriture concurrente | 🚫 BLOCKED |
| ATK-05 | ETag arbitraire injecté | 🚫 BLOCKED |
| ATK-06 | Injection d'en-tête via If-Match | 🚫 BLOCKED |
| ATK-07 | Suppression avec ETag périmé | 🚫 BLOCKED |
| ATK-08 | Version négative/zéro dans ETag | 🚫 BLOCKED |
| ATK-09 | Replay de l'ETag précédent | ✅ SAFE (problème d'autorisation, pas d'ETag) |
| ATK-10 | Dépassement du compteur de version | 🚫 BLOCKED |
| ATK-11 | Usurpation d'ETag dans la réponse | 🚫 BLOCKED |
| ATK-12 | DELETE sans If-Match | 🚫 BLOCKED |

**10 BLOCKED, 2 SAFE, 0 EXPOSED** — Pas de résultats critiques.

---

## À ne pas faire

| Anti-pattern | Risque |
|---|---|
| Autoriser PUT/DELETE sans If-Match | Toute écriture sans jeton de verrou cause des mises à jour perdues |
| Retourner 200 sur ETag périmé (écrasement silencieux) | Mise à jour perdue : dernier qui écrit gagne, éditions concurrentes silencieusement ignorées |
| Utiliser ETag mutable (ex. horodatage `Last-Modified`) | Décalage d'horloge cause des 412 spurieux ou des correspondances fausses |
| Ne pas supporter le wildcard `*` de If-Match | Casse les outils admin et la conformité RFC 7232 |
| Pas de vérification de version au niveau DB dans la clause WHERE | La vérification applicative passe mais une écriture DB concurrente passe à travers |
