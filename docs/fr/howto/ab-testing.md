# How-to : Framework de tests A/B

> **Référence FT** : FT293 (`NENE2-FT/ablog`) — Framework d'expériences A/B : attribution déterministe de variantes pondérées via crc32, machine d'états draft→active→stopped, attribution idempotente UNIQUE(experiment_id, user_id), agrégation CVR en SQL, 16 tests / 26 assertions PASS.

Exécutez des expériences contrôlées en attribuant des utilisateurs à des variantes et en collectant des événements de conversion.

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
| `POST` | `/experiments` | Créer une expérience (démarre en `draft`) |
| `GET` | `/experiments` | Lister toutes les expériences |
| `GET` | `/experiments/{id}` | Obtenir une expérience + variantes |
| `PUT` | `/experiments/{id}/status` | Transition de statut |
| `POST` | `/experiments/{id}/variants` | Ajouter une variante |
| `POST` | `/experiments/{id}/assign` | Attribuer un utilisateur à une variante (idempotent) |
| `POST` | `/experiments/{id}/events` | Enregistrer un événement de conversion |
| `GET` | `/experiments/{id}/results` | CVR agrégé par variante |

## Cycle de vie du statut

```
draft → active → stopped
```

Rejeter les transitions invalides avec 422 :

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

## Attribution déterministe de variante

Les utilisateurs doivent toujours atterrir dans la même variante — utilisez `crc32` pour un seau reproductible et sans état :

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

La DB stocke l'attribution au premier appel ; les appels suivants retournent la variante stockée — déterminisme + vérité DB.

## Attribution idempotente

```php
// Retourner l'attribution existante sans re-tirer
$existing = $this->repo->findAssignment($id, $userId);
if ($existing !== null) {
    return $this->json->create($existing);   // 200, pas 201
}
// Première fois : calculer et stocker
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

Puis calculer le CVR en PHP :

```php
$row['cvr'] = $assignments > 0 ? round($events / $assignments, 4) : 0.0;
```

## Garde-fous

- Seules les expériences `active` acceptent les attributions (409 sinon).
- Les événements nécessitent que l'utilisateur soit attribué (404 sinon).
- `UNIQUE(experiment_id, user_id)` empêche la double attribution au niveau DB.
- Les poids doivent être des entiers positifs ; les variantes à poids zéro sont rejetées (422).

---

## Ce qu'il ne faut PAS faire

| Anti-pattern | Risque |
|---|---|
| Attribution aléatoire (non déterministe) | Le même utilisateur obtient des variantes différentes à chaque appel ; expérience incohérente |
| Pas de `UNIQUE(experiment_id, user_id)` | Les attributions concurrentes créent des lignes en doublon ; l'utilisateur se retrouve dans plusieurs variantes |
| Autoriser l'attribution en statut `draft` ou `stopped` | Les expériences en draft n'ont pas de variantes valides ; les expériences arrêtées ne doivent pas collecter de nouvelles données |
| Autoriser les transitions de statut inverses | `stopped → active` rouvre une expérience fermée ; données historiques contaminées |
| Pas de validation du poids (autoriser 0) | Un poids total de zéro cause une division par zéro dans le calcul du seau |
| Calculer le CVR dans l'application avec toutes les lignes | Récupérer toutes les lignes puis boucler ; utiliser l'agrégation SQL `GROUP BY` à la place |
| Pas de validation événement → attribution | Les événements sans attribution valide faussent les taux de conversion par variante |
