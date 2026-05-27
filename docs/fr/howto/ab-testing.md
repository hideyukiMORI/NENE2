# How-to : Framework de Tests A/B

> **Référence FT** : FT293 (`NENE2-FT/ablog`) — framework d'expérimentation A/B : affectation déterministe pondérée via graine crc32, machine d'état draft→active→stopped, affectation idempotente UNIQUE(experiment_id, user_id), agrégation CVR en SQL, 16 tests / 26 assertions PASS.

Exécutez des expériences contrôlées en affectant des utilisateurs à des variantes et en collectant des événements de conversion.

## Schéma

```sql
CREATE TABLE experiments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL UNIQUE,
    description TEXT NOT NULL DEFAULT '',
    status TEXT NOT NULL DEFAULT 'draft' CHECK(status IN ('draft', 'active', 'stopped')),
    created_at TEXT NOT NULL, updated_at TEXT NOT NULL
);
CREATE TABLE experiment_variants (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    experiment_id INTEGER NOT NULL REFERENCES experiments(id) ON DELETE CASCADE,
    name TEXT NOT NULL, weight INTEGER NOT NULL DEFAULT 100,
    UNIQUE(experiment_id, name)
);
CREATE TABLE experiment_assignments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    experiment_id INTEGER NOT NULL REFERENCES experiments(id) ON DELETE CASCADE,
    user_id TEXT NOT NULL, variant_id INTEGER NOT NULL REFERENCES experiment_variants(id),
    assigned_at TEXT NOT NULL, UNIQUE(experiment_id, user_id)
);
CREATE TABLE experiment_events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    experiment_id INTEGER NOT NULL REFERENCES experiments(id) ON DELETE CASCADE,
    assignment_id INTEGER NOT NULL REFERENCES experiment_assignments(id),
    event_type TEXT NOT NULL, created_at TEXT NOT NULL
);
```

## Routes

| Méthode | Chemin | Description |
|--------|------|-------------|
| `POST` | `/experiments` | Créer une expérience (commence en `draft`) |
| `GET` | `/experiments` | Lister toutes les expériences |
| `GET` | `/experiments/{id}` | Obtenir une expérience + ses variantes |
| `PUT` | `/experiments/{id}/status` | Changer le statut |
| `POST` | `/experiments/{id}/variants` | Ajouter une variante |
| `POST` | `/experiments/{id}/assign` | Affecter un utilisateur à une variante (idempotent) |
| `POST` | `/experiments/{id}/events` | Enregistrer un événement de conversion |
| `GET` | `/experiments/{id}/results` | CVR agrégé par variante |

## Cycle de vie du statut

```
draft → active → stopped
```

Rejetez les transitions invalides avec 422 :

```php
private const array VALID_TRANSITIONS = [
    'draft'   => ['active'],
    'active'  => ['stopped'],
    'stopped' => [],
];

$allowed = self::VALID_TRANSITIONS[$current] ?? [];
if (!in_array($status, $allowed, true)) {
    throw new ValidationException([...]);
}
```

## Affectation déterministe de variante

Les utilisateurs doivent toujours tomber dans la même variante — utilisez `crc32` pour un bucket reproductible et sans état :

```php
class VariantAssigner
{
    /** @param list<array<string, mixed>> $variants */
    public function assign(array $variants, string $userId, int $experimentId): ?array
    {
        $totalWeight = array_sum(array_column($variants, 'weight'));
        $seed        = abs(crc32($userId . ':' . $experimentId));
        $bucket      = $seed % $totalWeight;

        $cumulative = 0;
        foreach ($variants as $v) {
            $cumulative += (int) $v['weight'];
            if ($bucket < $cumulative) {
                return $v;
            }
        }
        return $variants[0];
    }
}
```

La base de données stocke l'affectation au premier appel ; les appels suivants retournent la variante stockée — déterminisme + vérité DB.

## Affectation idempotente

```php
// Retourner l'affectation existante sans recalculer
$existing = $this->repo->findAssignment($id, $userId);
if ($existing !== null) {
    return $this->json->create($existing);   // 200, pas 201
}
// Premier appel : calculer et stocker
$variant      = $this->assigner->assign($variants, $userId, $id);
$assignmentId = $this->repo->createAssignment($id, $userId, $variant['id'], $now);
return $this->json->create($assignment, 201);
```

## Agrégation des résultats (CVR)

```sql
SELECT ev.id AS variant_id, ev.name AS variant_name,
       COUNT(DISTINCT ea.id) AS assignments,
       COUNT(ee.id) AS events
FROM experiment_variants ev
LEFT JOIN experiment_assignments ea ON ea.variant_id = ev.id
LEFT JOIN experiment_events ee ON ee.assignment_id = ea.id
WHERE ev.experiment_id = ?
GROUP BY ev.id, ev.name, ev.weight
ORDER BY ev.id ASC
```

Puis calculez le CVR en PHP :

```php
$row['cvr'] = $assignments > 0 ? round($events / $assignments, 4) : 0.0;
```

## Garde-fous

- Seules les expériences `active` acceptent des affectations (409 sinon).
- Les événements nécessitent que l'utilisateur soit affecté (404 sinon).
- `UNIQUE(experiment_id, user_id)` empêche les doubles affectations au niveau DB.
- Les poids doivent être des entiers positifs ; les variantes à poids zéro sont rejetées (422).

---

## Ce qu'il ne faut PAS faire

| Anti-pattern | Risque |
|---|---|
| Affectation aléatoire (non déterministe) | Le même utilisateur obtient des variantes différentes à chaque appel ; expérience incohérente |
| Pas de `UNIQUE(experiment_id, user_id)` | Les affectations concurrentes créent des doublons ; l'utilisateur se retrouve dans plusieurs variantes |
| Autoriser l'affectation en statut `draft` ou `stopped` | Les expériences draft n'ont pas de variantes valides ; les expériences stopped ne doivent pas collecter de nouvelles données |
| Autoriser les transitions de statut en arrière | `stopped → active` rouvre une expérience fermée ; les données historiques sont contaminées |
| Pas de validation des poids (autoriser 0) | Un poids total nul provoque une division par zéro dans le calcul du bucket |
| Calculer le CVR en application avec toutes les lignes | Récupérer toutes les lignes puis boucler ; utilisez plutôt l'agrégation SQL `GROUP BY` |
| Pas de validation événement → affectation | Les événements sans affectation valide faussent les taux de conversion par variante |
