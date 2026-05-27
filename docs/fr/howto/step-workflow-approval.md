# How-to : Workflow par étapes avec approbation

> **Référence FT** : FT247 (`NENE2-FT/stepflowlog`) — API de workflow par étapes avec approbation

Démontre un système de workflow à deux niveaux où une définition de workflow réutilisable
contient une liste ordonnée d'étapes, et une exécution de workflow est une instance de cette définition
progressant à travers les étapes via des actions d'approbation/rejet. Chaque action est enregistrée dans un
journal d'historique d'audit.

---

## Routes

| Méthode | Chemin | Description |
|---------|--------|-------------|
| `POST` | `/workflows` | Définir un nouveau workflow |
| `GET` | `/workflows/{id}` | Obtenir le workflow avec ses étapes |
| `POST` | `/workflows/{id}/steps` | Ajouter une étape à un workflow (ordonné automatiquement) |
| `POST` | `/runs` | Démarrer une exécution d'un workflow (échoue si le workflow n'a pas d'étapes) |
| `GET` | `/runs/{id}` | Obtenir le statut d'exécution avec l'historique des actions |
| `POST` | `/runs/{id}/approve` | Approuver l'étape actuelle (avance à l'étape suivante ou complète) |
| `POST` | `/runs/{id}/reject` | Rejeter l'étape actuelle (termine l'exécution comme rejetée) |

---

## Schéma

```sql
CREATE TABLE IF NOT EXISTS workflows (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    name        TEXT    NOT NULL UNIQUE,
    description TEXT    NOT NULL DEFAULT '',
    created_at  TEXT    NOT NULL
);

CREATE TABLE IF NOT EXISTS workflow_steps (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    workflow_id INTEGER NOT NULL REFERENCES workflows(id) ON DELETE CASCADE,
    name        TEXT    NOT NULL,
    step_order  INTEGER NOT NULL,
    UNIQUE(workflow_id, step_order)
);

CREATE TABLE IF NOT EXISTS workflow_runs (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    workflow_id     INTEGER NOT NULL REFERENCES workflows(id),
    title           TEXT    NOT NULL,
    status          TEXT    NOT NULL DEFAULT 'pending'
                        CHECK(status IN ('pending', 'in_progress', 'completed', 'rejected')),
    current_step_id INTEGER REFERENCES workflow_steps(id),
    created_at      TEXT    NOT NULL,
    updated_at      TEXT    NOT NULL
);

CREATE TABLE IF NOT EXISTS workflow_actions (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    run_id     INTEGER NOT NULL REFERENCES workflow_runs(id) ON DELETE CASCADE,
    step_id    INTEGER NOT NULL REFERENCES workflow_steps(id),
    action     TEXT    NOT NULL CHECK(action IN ('approve', 'reject')),
    actor      TEXT    NOT NULL,
    comment    TEXT    NOT NULL DEFAULT '',
    created_at TEXT    NOT NULL
);
```

`UNIQUE(workflow_id, step_order)` empêche la duplication d'ordre dans un workflow.
`current_step_id` est nullable — `NULL` signifie que l'exécution est `completed` ou `rejected`
(pas d'étape active). `action` a un `CHECK` au niveau DB pour `approve`/`reject`.

---

## Ordonnancement automatique des étapes

Lors de l'ajout d'une étape, le contrôleur calcule automatiquement le prochain `step_order` :

```php
$existingSteps = $this->repo->findSteps($id);
$maxOrder      = 0;
foreach ($existingSteps as $s) {
    if ((int) $s['step_order'] > $maxOrder) {
        $maxOrder = (int) $s['step_order'];
    }
}
$stepOrder = $maxOrder + 1;
$stepId    = $this->repo->addStep($id, $name, $stepOrder);
```

`step_order` commence à `1` et s'incrémente de `1` pour chaque nouvelle étape. La contrainte `UNIQUE`
empêche deux étapes de partager le même ordre. Les étapes sont toujours retournées dans l'ordre :

```php
$this->db->fetchAll(
    'SELECT * FROM workflow_steps WHERE workflow_id = ? ORDER BY step_order ASC',
    [$workflowId],
);
```

---

## Démarrer une exécution : initialisation de la première étape

Une exécution est initialisée avec la première étape du workflow comme `current_step_id` :

```php
$steps = $this->repo->findSteps($workflowId);
if ($steps === []) {
    return $this->json->create(['error' => 'Workflow has no steps'], 409);
}

$firstStep = $steps[0];
$runId = $this->repo->createRun($workflowId, $title, (int) $firstStep['id'], $now);
```

`409 Conflict` est retourné quand le workflow n'a pas d'étapes — une exécution ne peut pas progresser
à travers un workflow sans étapes. La première étape (plus bas `step_order`) devient l'étape active.

---

## `approve` : avancer à l'étape suivante ou compléter

`POST /runs/{id}/approve` vérifie le statut actuel, enregistre l'action, puis
trouve l'étape suivante par `step_order` :

```php
if ((string) $run['status'] !== 'in_progress') {
    return $this->json->create(['error' => 'Run is not in progress'], 409);
}

$this->repo->recordAction($id, $currentStepId, 'approve', $actor, $comment, $this->now());

$nextStep = $this->repo->findNextStep($workflowId, $currentStepOrder);
if ($nextStep !== null) {
    $this->repo->updateRun($id, 'in_progress', (int) $nextStep['id'], $this->now());
} else {
    $this->repo->updateRun($id, 'completed', null, $this->now());
}
```

`findNextStep` récupère l'étape avec le `step_order` suivant :

```php
public function findNextStep(int $workflowId, int $currentOrder): ?array
{
    return $this->db->fetchOne(
        'SELECT * FROM workflow_steps WHERE workflow_id = ? AND step_order > ? ORDER BY step_order ASC LIMIT 1',
        [$workflowId, $currentOrder],
    );
}
```

`step_order > current` + `ORDER BY step_order ASC LIMIT 1` trouve l'étape immédiatement
suivante. Si aucune étape suivante n'existe (dernière étape), `findNextStep` retourne `null`
→ l'exécution est marquée `completed` avec `current_step_id = null`.

---

## `reject` : terminer l'exécution

`POST /runs/{id}/reject` enregistre l'action et marque l'exécution `rejected` :

```php
$this->repo->recordAction($id, $currentStepId, 'reject', $actor, $comment, $this->now());
$this->repo->updateRun($id, 'rejected', null, $this->now());
```

`current_step_id` est défini à `null` au rejet — plus d'étape active. L'exécution
est terminale : les appels ultérieurs à `approve`/`reject` retournent `409` car `status !== 'in_progress'`.

---

## Historique des actions : JOIN avec le nom de l'étape

La réponse d'exécution inclut l'historique complet des actions :

```php
$run     = $this->repo->findRun($id);
$actions = $this->repo->findActions($id);
return $this->json->create(array_merge($run, ['history' => $actions]));
```

Les actions sont récupérées avec un `JOIN` pour enrichir chaque ligne avec le nom de l'étape :

```php
$this->db->fetchAll(
    'SELECT wa.*, ws.name AS step_name FROM workflow_actions wa
     JOIN workflow_steps ws ON wa.step_id = ws.id
     WHERE wa.run_id = ? ORDER BY wa.id ASC',
    [$runId],
);
```

`ORDER BY wa.id ASC` préserve l'ordre d'insertion chronologique pour la piste d'audit.

---

## Machine à états d'exécution

```
                 POST /runs
                     │
                     ▼
               in_progress  ──approve (dernière étape)──► completed
                     │
               approve (pas la dernière étape)
                     │
                     ▼
               in_progress (étape suivante)
                     │
                  reject
                     │
                     ▼
                 rejected
```

Les états `completed` et `rejected` sont terminaux — aucune transition d'état ultérieure n'est
autorisée. Tout `approve`/`reject` sur une exécution terminale retourne `409 Conflict`.

---

## `findRun` avec `current_step_name` via `LEFT JOIN`

L'exécution est récupérée avec un `LEFT JOIN` pour inclure le nom de l'étape actuelle :

```php
$this->db->fetchOne(
    'SELECT wr.*, ws.name AS current_step_name, ws.step_order AS current_step_order
     FROM workflow_runs wr
     LEFT JOIN workflow_steps ws ON wr.current_step_id = ws.id
     WHERE wr.id = ?',
    [$id],
);
```

`LEFT JOIN` (pas `INNER JOIN`) — quand `current_step_id` est `null` (exécution complétée/rejetée),
les colonnes `ws.*` sont `null` plutôt que de faire disparaître la ligne.

---

## Howtos connexes

- [`approval-workflow.md`](approval-workflow.md) — Pattern d'approbation avec états pending/approved/rejected
- [`state-machine-audit-log.md`](state-machine-audit-log.md) — Enregistrement des transitions d'état et InvalidTransitionException
- [`multi-step-workflow.md`](multi-step-workflow.md) — Pattern de formulaire/processus séquentiel multi-étapes
- [`audit-trail.md`](audit-trail.md) — Patterns d'enregistrement d'événements append-only
