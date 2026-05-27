# How-to: Mehrstufigen Workflow hinzufügen

Sequentielle Genehmigungsabläufe modellieren, bei denen jeder Schritt genehmigt werden muss, bevor der nächste beginnt.

## Schema

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

## Routen

| Methode | Pfad | Beschreibung |
|---------|------|-------------|
| `POST` | `/workflows` | Einen Workflow definieren |
| `GET` | `/workflows/{id}` | Workflow + Schritte abrufen |
| `POST` | `/workflows/{id}/steps` | Einen Schritt anhängen (automatisch geordnet) |
| `POST` | `/runs` | Einen neuen Run starten (beginnt bei Schritt 1) |
| `GET` | `/runs/{id}` | Run-Zustand + vollständige Aktionshistorie abrufen |
| `POST` | `/runs/{id}/approve` | Aktuellen Schritt genehmigen |
| `POST` | `/runs/{id}/reject` | Ablehnen → beendet den Run |

## Zustandsmaschine

```
in_progress --approve (weitere Schritte)--> in_progress (nächster Schritt)
in_progress --approve (letzter Schritt)--> completed
in_progress --reject (beliebiger Schritt)--> rejected
```

Abgeschlossene und abgelehnte Runs geben bei weiteren approve/reject-Aufrufen 409 zurück.

## Automatische Schrittordnung

Nur-Anhängen: jeder neue Schritt erhält `max(step_order) + 1`:

```php
$existingSteps = $this->repo->findSteps($workflowId);
$maxOrder      = 0;
foreach ($existingSteps as $s) {
    $maxOrder = max($maxOrder, (int) $s['step_order']);
}
$stepOrder = $maxOrder + 1;
$this->repo->addStep($workflowId, $name, $stepOrder);
```

## Weiterschalten oder Abschließen bei Genehmigung

```php
// Zuerst Aktion aufzeichnen, dann Übergang durchführen
$this->repo->recordAction($runId, $currentStepId, 'approve', $actor, $comment, $now);

$nextStep = $this->repo->findNextStep($workflowId, $currentStepOrder);
if ($nextStep !== null) {
    $this->repo->updateRun($runId, 'in_progress', (int) $nextStep['id'], $now);
} else {
    $this->repo->updateRun($runId, 'completed', null, $now);
}
```

Den nächsten Schritt mit einer einzigen SQL-Abfrage finden:

```sql
SELECT * FROM workflow_steps
WHERE workflow_id = ? AND step_order > ?
ORDER BY step_order ASC LIMIT 1
```

## Abgeschlossene/Abgelehnte Runs schützen

```php
if ((string) $run['status'] !== 'in_progress') {
    return $this->json->create(['error' => 'Run is not in progress'], 409);
}
```

## Historien-Join

Die vollständige Aktionshistorie mit Schrittnamen in der `GET /runs/{id}`-Antwort zurückgeben:

```sql
SELECT wa.*, ws.name AS step_name
FROM workflow_actions wa
JOIN workflow_steps ws ON wa.step_id = ws.id
WHERE wa.run_id = ?
ORDER BY wa.id ASC
```

## Wichtige Designentscheidungen

- **Nur-Anhängen-Schritte**: `step_order` ist monoton; keine Neuordnung nach der Erstellung.
- **Ablehnen beendet sofort**: jede Schrittablehnung beendet den Run (keine Teilgenehmigung).
- **`current_step_id = NULL`** bei abgeschlossenen/abgelehnten Runs — `status` verwenden, um zu unterscheiden.
- **Run-Start erfordert mindestens einen Schritt**: 409 zurückgeben, wenn der Workflow keine Schritte hat.
