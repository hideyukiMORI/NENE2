# Verrouillage distribué

Un verrou distribué empêche les processus concurrents d'exécuter une section critique en même temps. Les verrous soutenus par DB échangent le débit contre la simplicité — pas de Redis requis, et la même DB qui contient vos données contient vos verrous.

## Concepts clés

- **Ressource** : le nom de la chose verrouillée (ex. `job:42`, `report:monthly-2026-05`)
- **Propriétaire** : un token qui identifie le détenteur du verrou — seul le propriétaire peut libérer ou renouveler
- **Expiration (TTL)** : les verrous expirent automatiquement pour qu'un propriétaire planté ne puisse pas détenir un verrou indéfiniment
- **Réclamation de verrou périmé** : un verrou expiré peut être repris par un nouveau propriétaire

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

La contrainte `UNIQUE` sur `resource` garantit qu'il n'existe qu'une seule ligne par ressource. Les INSERTs concurrents sont sérialisés au niveau DB.

## Logique d'acquisition

```php
public function acquire(string $resource, string $owner, string $expiresAt, string $now): ?LockRecord
{
    $existing = $this->findByResource($resource);

    if ($existing === null) {
        // Pas de verrou — INSERT (peut échouer en cas de race ; l'appelant obtient null et réessaie)
        $this->executor->execute(
            'INSERT INTO distributed_locks (resource, owner, expires_at, acquired_at) VALUES (?, ?, ?, ?)',
            [$resource, $owner, $expiresAt, $now],
        );
        return $this->findByResource($resource);
    }

    if ($existing->isExpired($now) || $existing->owner === $owner) {
        // Expiré (périmé) ou même propriétaire re-acquérant — UPDATE pour récupérer
        $this->executor->execute(
            'UPDATE distributed_locks SET owner = ?, expires_at = ?, acquired_at = ? WHERE resource = ?',
            [$owner, $expiresAt, $now, $resource],
        );
        return $this->findByResource($resource);
    }

    // Détenu par un autre propriétaire et encore valide — impossible d'acquérir
    return null;
}
```

Conventions de valeur de retour :
- Retourne un `LockRecord` en cas de succès (`acquired: true` dans la réponse API)
- Retourne `null` quand le verrou est détenu par un autre propriétaire (`acquired: false`)

## Libération imposée par le propriétaire

Seul le propriétaire peut libérer. Retourner 403 (pas 404) quand le propriétaire ne correspond pas dit à l'appelant que le verrou existe mais qu'il ne le détient pas :

```php
return match ($result) {
    ReleaseResult::Released  => $this->json->create([], 204),
    ReleaseResult::NotFound  => $this->problems->create($request, 'not-found', 'Lock not found.', 404, ''),
    ReleaseResult::Forbidden => $this->problems->create($request, 'forbidden', 'Owner mismatch.', 403, ''),
};
```

## Renouvellement TTL

Les tâches longues ont besoin d'étendre leur verrou avant qu'il expire. Seul le propriétaire actuel peut renouveler — un renouvellement par un mauvais propriétaire retourne 409 (pas 403) car il signale un conflit d'état, pas un refus de permission :

```php
if ($existing->isExpired($now)) {
    return null; // → 409 : impossible de renouveler un verrou expiré (quelqu'un d'autre le détient peut-être maintenant)
}
if ($existing->owner !== $owner) {
    return null; // → 409 : mauvais propriétaire
}
// Étendre expires_at
```

## Détection de verrou périmé

`LockRecord::isExpired()` compare l'heure actuelle avec `expires_at` :

```php
public function isExpired(string $now): bool
{
    return $now >= $this->expiresAt;
}
```

Cela signifie que `GET /locks/{resource}` retourne 404 pour les verrous expirés (traitant les expirés comme inexistants), et `POST /locks/{resource}` laisse un nouveau propriétaire réclamer un verrou expiré.

## Décisions de conception

**Pourquoi pas Redis SETNX ?**
Redis donne un SETNX atomique avec TTL en une seule commande et est le standard de production pour le verrouillage à haut débit. Le verrouillage soutenu par DB est plus simple à déployer (pas de service supplémentaire), cohérent avec le reste de vos données transactionnelles, et suffisant pour les scénarios de contention faible à moyenne (jobs en arrière-plan, génération de rapport, traitement par lot).

**Pourquoi pas DELETE+INSERT lors de la re-acquisition ?**
UPDATE préserve l'ID de ligne et est atomique. DELETE+INSERT créerait une brève fenêtre où aucune ligne de verrou n'existe, permettant à un processus concurrent d'INSERTer et de voler le verrou.

**Pourquoi séparer `acquired_at` de `expires_at` ?**
`acquired_at` est l'horodatage quand la propriété a été établie pour la dernière fois (utile pour l'audit). `expires_at` change au renouvellement. Les garder séparés évite l'ambiguïté.

**Non-bloquant par conception**
L'endpoint de verrou retourne immédiatement avec `acquired: false` plutôt que de bloquer jusqu'à ce que le verrou soit disponible. Les appelants implémentent leur propre stratégie de retentative (backoff exponentiel, file de messages morts, etc.) selon leurs exigences de délai.
