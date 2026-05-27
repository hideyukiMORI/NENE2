# How-to : Workflow multi-étapes

Modéliser des flux d'approbation séquentiels où chaque étape doit être approuvée avant de passer à la suivante.

## Schéma

```sql
CREATE TABLE workflows (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL UNIQUE, description TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL
);
CREATE TABLE workflow_steps (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    workflow_id INTEGER NOT NULL REFERENCES workflows(id) ON DELETE CASCADE,
    name TEXT NOT NULL, step_order INTEGER NOT NULL,
    UNIQUE(workflow_id, step_order)
);
CREATE TABLE workflow_runs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    workflow_id INTEGER NOT NULL REFERENCES workflows(id),
    title TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'pending' CHECK(status IN ('pending','in_progress','completed','rejected')),
    current_step_id INTEGER REFERENCES workflow_steps(id),
    created_at TEXT NOT NULL, updated_at TEXT NOT NULL
);
CREATE TABLE workflow_actions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    run_id INTEGER NOT NULL REFERENCES workflow_runs(id) ON DELETE CASCADE,
    step_id INTEGER NOT NULL REFERENCES workflow_steps(id),
    action TEXT NOT NULL CHECK(action IN ('approve','reject')),
    actor TEXT NOT NULL, comment TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL
);
```

## Routes

| Méthode | Chemin | Description |
|---------|--------|-------------|
| `POST` | `/workflows` | Définir un workflow |
| `GET` | `/workflows/{id}` | Obtenir le workflow + étapes |
| `POST` | `/workflows/{id}/steps` | Ajouter une étape (ordonnée automatiquement) |
| `POST` | `/runs` | Démarrer un nouveau run (commence à l'étape 1) |
| `GET` | `/runs/{id}` | Obtenir l'état du run + historique complet des actions |
| `POST` | `/runs/{id}/approve` | Approuver l'étape courante |
| `POST` | `/runs/{id}/reject` | Rejeter → termine le run |

## Machine à états

```
in_progress --approve (plus d'étapes)--> in_progress (étape suivante)
in_progress --approve (étape finale)--> completed
in_progress --reject (toute étape)-----> rejected
```

Les runs complétés et rejetés retournent 409 sur une approbation/rejet ultérieur.

## Ordonnancement automatique des étapes

Ajout uniquement : chaque nouvelle étape obtient `max(step_order) + 1` :

```php
$existingSteps = $this->repo->findSteps($workflowId);
$maxOrder      = 0;
foreach ($existingSteps as $s) {
    $maxOrder = max($maxOrder, (int) $s['step_order']);
}
$stepOrder = $maxOrder + 1;
$this->repo->addStep($workflowId, $name, $stepOrder);
```

## Avancement ou complétion à l'approbation

```php
// Enregistrer l'action d'abord, puis la transition
$this->repo->recordAction($runId, $currentStepId, 'approve', $actor, $comment, $now);

$nextStep = $this->repo->findNextStep($workflowId, $currentStepOrder);
if ($nextStep !== null) {
    $this->repo->updateRun($runId, 'in_progress', (int) $nextStep['id'], $now);
} else {
    $this->repo->updateRun($runId, 'completed', null, $now);
}
```

Trouver l'étape suivante avec une seule requête SQL :

```sql
SELECT * FROM workflow_steps
WHERE workflow_id = ? AND step_order > ?
ORDER BY step_order ASC LIMIT 1
```

## Protéger les runs complétés/rejetés

```php
if ((string) $run['status'] !== 'in_progress') {
    return $this->json->create(['error' => 'Run is not in progress'], 409);
}
```

## Jointure d'historique

Retourner l'historique complet des actions avec les noms d'étapes dans la réponse `GET /runs/{id}` :

```sql
SELECT wa.*, ws.name AS step_name
FROM workflow_actions wa
JOIN workflow_steps ws ON wa.step_id = ws.id
WHERE wa.run_id = ?
ORDER BY wa.id ASC
```

## Décisions de design clés

- **Étapes ajout-uniquement** : `step_order` est monotone ; pas de réordonnancement après création.
- **Le rejet termine immédiatement** : le rejet d'une étape quelconque met fin au run (pas d'approbation partielle).
- **`current_step_id = NULL`** sur les runs complétés/rejetés — utiliser `status` pour distinguer.
- **Démarrer un run nécessite au moins une étape** : retourner 409 si le workflow n'a pas d'étapes.
