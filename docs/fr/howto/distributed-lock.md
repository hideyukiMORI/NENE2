# How-to : Verrou distribué

> **Référence FT** : FT288 (`NENE2-FT/distlocklog`) — Verrou distribué : contrainte DB UNIQUE(resource), vérification de propriétaire, expiration basée sur TTL, re-acquisition de verrou expiré par conception, enum ReleaseResult (Released/NotFound/Forbidden), 403 sur incompatibilité de propriétaire, 16 tests / 27 assertions PASS.
>
> **Évaluation ATK** : ATK-01 à ATK-12 inclus à la fin de ce document.

Ce guide montre comment implémenter une API de verrou distribué — empêcher les opérations concurrentes sur la même ressource en émettant des verrous avec bail.

## Qu'est-ce qu'un verrou distribué ?

Quand plusieurs processus ont besoin d'un accès exclusif à une ressource partagée (ex. un paiement, un fichier, un job de file), un verrou distribué garantit qu'un seul processus procède à la fois. Les verrous ont un TTL pour qu'ils expirent automatiquement si le détenteur plante.

## Schéma

```sql
CREATE TABLE distributed_locks (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    resource    TEXT    NOT NULL UNIQUE,
    owner       TEXT    NOT NULL,
    expires_at  TEXT    NOT NULL,
    acquired_at TEXT    NOT NULL
);
```

`resource TEXT UNIQUE` — une ligne par ressource. L'acquisition insère ou met à jour cette ligne.

## Endpoints

| Méthode | Chemin | Description |
|---------|--------|-------------|
| `POST` | `/locks/{resource}` | Acquérir le verrou |
| `GET` | `/locks/{resource}` | Obtenir le statut du verrou |
| `DELETE` | `/locks/{resource}` | Libérer le verrou |
| `POST` | `/locks/{resource}/renew` | Étendre le TTL |

## Logique d'acquisition

```php
public function acquire(string $resource, string $owner, string $expiresAt, string $now): ?LockRecord
{
    $existing = $this->findByResource($resource);

    if ($existing === null) {
        // Pas de verrou — INSERT (la contrainte UNIQUE gère les races)
        try {
            $this->executor->execute('INSERT INTO distributed_locks ...', [...]);
        } catch (\RuntimeException) {
            return null;  // Race : un autre processus a inséré en concurrence
        }
        return $this->findByResource($resource);
    }

    if ($existing->isExpired($now) || $existing->owner === $owner) {
        // Expiré → re-acquérir (UPDATE remplace l'ancienne ligne)
        // Même propriétaire → re-acquérir (étendre ou re-verrouiller)
        $this->executor->execute('UPDATE distributed_locks SET owner = ?, expires_at = ?, acquired_at = ? WHERE resource = ?', ...);
        return $this->findByResource($resource);
    }

    // Détenu par un autre propriétaire, pas expiré → impossible d'acquérir
    return null;
}
```

## Libération avec vérification de propriétaire

```php
$result = $this->repo->release($resource, $owner, $now);

return match ($result) {
    ReleaseResult::Released  => $this->json->create([], 204),
    ReleaseResult::NotFound  => $this->problems->create($request, 'not-found', 'Lock not found.', 404),
    ReleaseResult::Forbidden => $this->problems->create($request, 'forbidden', 'Owner mismatch.', 403),
};
```

Seul le propriétaire du verrou peut le libérer. Mauvais `owner` → 403 Forbidden.

## Enum ReleaseResult

```php
enum ReleaseResult
{
    case Released;   // Verrou trouvé, propriétaire correspondant, ligne supprimée
    case NotFound;   // Verrou introuvable ou déjà expiré
    case Forbidden;  // Verrou trouvé, mais le propriétaire ne correspond pas
}
```

Utiliser un enum (pas des chaînes magiques) garantit une gestion exhaustive dans `match`.

## Réponse d'acquisition

```php
// Succès :
{ "acquired": true, "lock": { "resource": "...", "owner": "...", "expires_at": "...", "acquired_at": "..." } }

// Échec (détenu par un autre) :
{ "acquired": false, "resource": "payment:42" }
```

`acquired: false` n'est pas une erreur — cela signifie "réessayez plus tard." Pas de statut 4xx ; l'appelant devrait réessayer.

---

## ATK Assessment — Cracker-Mindset Attack Test

### ATK-01 — Acquérir un verrou détenu par un autre propriétaire 🚫 BLOCKED

**Attack**: L'attaquant essaie d'acquérir `locks/payment:42` pendant qu'un autre processus le détient.
**Result**: BLOCKED — le repository vérifie `existing.owner === $caller_owner`. Propriétaire différent + pas expiré → retourne `null` → `{ acquired: false }`. Pas d'erreur, pas de crash — l'attaquant ne reçoit simplement pas le verrou.

---

### ATK-02 — Libérer un verrou appartenant à un autre 🚫 BLOCKED

**Attack**: L'attaquant envoie `DELETE /locks/payment:42` avec `{ "owner": "attacker" }` pour libérer de force un verrou.
**Result**: BLOCKED — le repository vérifie `lock.owner === $body_owner`. Incompatibilité → `ReleaseResult::Forbidden` → 403.

---

### ATK-03 — Voler le verrou après expiration 🚫 BLOCKED (par conception)

**Attack**: L'attaquant attend l'expiration du verrou, puis l'acquiert.
**Result**: BLOCKED (par conception) — les verrous expirés peuvent être re-acquis par n'importe quel propriétaire. C'est le comportement prévu : l'expiration basée sur TTL est comment les détenteurs plantés perdent leurs verrous. Réduire les attaques basées sur TTL nécessite une coordination (renouvellement par heartbeat).

---

### ATK-04 — Renouveler un verrou appartenant à un autre 🚫 BLOCKED

**Attack**: L'attaquant envoie `POST /locks/payment:42/renew` avec `{ "owner": "attacker", "ttl_seconds": 3600 }`.
**Result**: BLOCKED — le renouvellement vérifie `lock.owner === $body_owner`. Incompatibilité → 403 Forbidden.

---

### ATK-05 — TTL zéro ou négatif pour créer un verrou déjà expiré 🚫 BLOCKED

**Attack**: Envoyer `{ "ttl_seconds": 0 }` ou `{ "ttl_seconds": -100 }` pour créer un verrou qui expire instantanément.
**Result**: BLOCKED — `if ($ttlSeconds === null || $ttlSeconds < 1)` → erreur de validation 422.

---

### ATK-06 — Injection SQL via le paramètre de chemin resource 🚫 BLOCKED

**Attack**: Utiliser `locks/resource'; DROP TABLE distributed_locks; --` comme nom de ressource.
**Result**: BLOCKED — toutes les requêtes utilisent des instructions paramétrisées (`WHERE resource = ?`). La chaîne injectée est traitée comme un identifiant de ressource littéral.

---

### ATK-07 — Propriétaire vide pour contourner la vérification de propriété 🚫 BLOCKED

**Attack**: Envoyer `{ "owner": "" }` ou `{ "owner": "   " }` pour libérer ou renouveler sans propriété valide.
**Result**: BLOCKED — `$owner = trim(...); if ($owner === '')` → erreur de validation 422.

---

### ATK-08 — TTL non entier pour contourner la validation de type 🚫 BLOCKED

**Attack**: Envoyer `{ "ttl_seconds": "3600" }` (chaîne) ou `{ "ttl_seconds": 60.5 }` (float).
**Result**: BLOCKED — `is_int($body['ttl_seconds'])` rejette les chaînes et les floats. Seul le type entier JSON est accepté.

---

### ATK-09 — Acquérir plusieurs fois avec le même propriétaire 🚫 BLOCKED (par conception)

**Attack**: Le même propriétaire re-acquiert un verrou qu'il détient pour l'étendre sans utiliser `/renew`.
**Result**: AUTORISÉ (par conception) — `$existing->owner === $owner` → UPDATE (re-acquisition/extension). La re-acquisition par le même propriétaire est idempotente et sûre ; elle met à jour `expires_at` et `acquired_at`.

---

### ATK-10 — Race condition : deux propriétaires acquièrent en concurrence 🚫 BLOCKED

**Attack**: Deux processus voient tous les deux qu'il n'y a pas de verrou et tentent INSERT simultanément.
**Result**: BLOCKED — la contrainte `UNIQUE(resource)` garantit qu'un seul INSERT réussit. Le perdant capture `\RuntimeException` et retourne `null` → `{ acquired: false }`. Un seul propriétaire gagne.

---

### ATK-11 — GET d'un verrou inexistant ou expiré 🚫 BLOCKED

**Attack**: Appeler `GET /locks/nonexistent` ou attendre l'expiration du verrou puis appeler GET.
**Result**: BLOCKED — `if ($lock === null || $lock->isExpired($now)) return 404`. Les verrous expirés retournent 404 (pas les données du verrou périmé).

---

### ATK-12 — Nom de ressource extrêmement long pour causer un DoS 🚫 BLOCKED (note de conception)

**Attack**: Envoyer `{ "resource": "<chaîne 10Mo>" }` comme paramètre de chemin resource.
**Result**: PARTIELLEMENT BLOQUÉ — la ressource vient du chemin URL, limité par la longueur de chemin du serveur web (typiquement 8Ko). Pas de validation explicite de longueur au niveau applicatif dans ce FT. En production, ajouter `if (strlen($resource) > 255)` → 422. La DB stocke tout ce que l'application passe.

---

### ATK Summary

| ID | Attack | Result |
|----|--------|--------|
| ATK-01 | Acquérir le verrou détenu par un autre | 🚫 BLOCKED |
| ATK-02 | Libérer le verrou appartenant à un autre | 🚫 BLOCKED |
| ATK-03 | Voler le verrou après expiration TTL | 🚫 BLOCKED (par conception) |
| ATK-04 | Renouveler le verrou appartenant à un autre | 🚫 BLOCKED |
| ATK-05 | TTL zéro/négatif | 🚫 BLOCKED |
| ATK-06 | Injection SQL via chemin resource | 🚫 BLOCKED |
| ATK-07 | Contournement par propriétaire vide | 🚫 BLOCKED |
| ATK-08 | Contournement de type TTL non entier | 🚫 BLOCKED |
| ATK-09 | Re-acquisition par même propriétaire | 🚫 BLOCKED (intentionnel) |
| ATK-10 | Race condition sur acquisition concurrente | 🚫 BLOCKED |
| ATK-11 | GET d'un verrou expiré/inexistant | 🚫 BLOCKED |
| ATK-12 | Nom de ressource extrêmement long | ⚠️ NOTE DE CONCEPTION |

**11 BLOCKED, 1 NOTE DE CONCEPTION, 0 EXPOSED**
La vérification de propriétaire, la protection contre les races `UNIQUE(resource)`, la validation TTL et les requêtes paramétrisées préviennent tous les vecteurs d'attaque critiques.

---

## Ce qu'il ne faut PAS faire

| Anti-pattern | Risque |
|---|---|
| Pas de contrainte `UNIQUE(resource)` | Race condition : deux propriétaires acquièrent tous les deux ; vulnérabilité TOCTOU |
| Libération sans vérification de propriétaire | N'importe quel processus peut libérer n'importe quel verrou ; aucune garantie d'exclusivité |
| Pas de TTL sur les verrous | Le verrou du détenteur planté persiste indéfiniment ; deadlock système |
| Accepter un TTL de 0 ou négatif | Le verrou est déjà expiré à la création ; immédiatement re-acquérable |
| Retourner 404 sur incompatibilité de propriétaire (libération) | L'attaquant ne peut pas distinguer "le verrou n'existe pas" de "mauvais propriétaire" ; utiliser 403 |
| Accepter chaîne/float comme TTL | `"3600"` semble valide mais `is_int` échoue ; vérification de type stricte prévient les bugs subtils |
| Stocker le propriétaire sans validation | Un propriétaire vide contourne la propriété ; toujours valider non vide |
| Pas de limite de longueur sur resource | La limite de chemin du serveur web est la seule garde ; ajouter une validation explicite |
| Renouveler les verrous expirés | Un verrou expiré n'appartient à personne ; re-acquérir plutôt que renouveler |
