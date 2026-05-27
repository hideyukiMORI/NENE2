# Anleitung: Schrittbasierter Workflow mit Genehmigung

> **FT-Referenz**: FT247 (`NENE2-FT/stepflowlog`) — Schrittbasierte Workflow-Genehmigungs-API

Demonstriert ein zweistufiges Workflow-System, bei dem eine wiederverwendbare Workflow-Definition
eine geordnete Liste von Schritten enthält und ein Workflow-Durchlauf eine Instanz dieser Definition
ist, die durch Genehmigen/Ablehnen-Aktionen die Schritte durchläuft. Jede Aktion wird in einer
Prüfhistorie protokolliert.

---

## Routen

| Methode | Pfad                      | Beschreibung                                                    |
|---------|---------------------------|----------------------------------------------------------------|
| `POST` | `/workflows`              | Neuen Workflow definieren                                       |
| `GET`  | `/workflows/{id}`         | Workflow mit Schritten abrufen                                  |
| `POST` | `/workflows/{id}/steps`   | Schritt zu einem Workflow hinzufügen (automatisch sortiert)     |
| `POST` | `/runs`                   | Durchlauf eines Workflows starten (schlägt fehl, wenn keine Schritte) |
| `GET`  | `/runs/{id}`              | Durchlauf-Status mit Aktionshistorie abrufen                   |
| `POST` | `/runs/{id}/approve`      | Aktuellen Schritt genehmigen (rückt vor oder schließt ab)      |
| `POST` | `/runs/{id}/reject`       | Aktuellen Schritt ablehnen (beendet Durchlauf als abgelehnt)   |

---

## Schema

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

`UNIQUE(workflow_id, step_order)` verhindert doppelte Reihenfolgen innerhalb eines Workflows.
`current_step_id` ist nullable — `NULL` bedeutet, der Durchlauf ist `completed` oder `rejected`
(kein aktiver Schritt). `action` hat eine DB-Ebene `CHECK` für `approve`/`reject`.

---

## Automatische Schrittordnung

Beim Hinzufügen eines Schritts berechnet der Controller die nächste `step_order` automatisch:

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

`step_order` beginnt bei `1` und erhöht sich für jeden neuen Schritt um `1`. Die `UNIQUE`-
Bedingung verhindert, dass zwei Schritte dieselbe Reihenfolge teilen. Schritte werden immer
in Ordnung zurückgegeben:

```php
$this->db->fetchAll(
    'SELECT * FROM workflow_steps WHERE workflow_id = ? ORDER BY step_order ASC',
    [$workflowId],
);
```

---

## Durchlauf starten: Initialisierung des ersten Schritts

Ein Durchlauf wird mit dem ersten Schritt des Workflows als `current_step_id` initialisiert:

```php
$steps = $this->repo->findSteps($workflowId);
if ($steps === []) {
    return $this->json->create(['error' => 'Workflow has no steps'], 409);
}

$firstStep = $steps[0];
$runId = $this->repo->createRun($workflowId, $title, (int) $firstStep['id'], $now);
```

`409 Conflict` wird zurückgegeben, wenn der Workflow keine Schritte hat — ein Durchlauf kann
keinen schrittlosen Workflow durchlaufen. Der erste Schritt (niedrigste `step_order`) wird der aktive Schritt.

---

## `approve`: Zum nächsten Schritt vorrücken oder abschließen

`POST /runs/{id}/approve` prüft den aktuellen Status, zeichnet die Aktion auf und findet dann
den nächsten Schritt über `step_order`:

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

`findNextStep` holt den Schritt mit der nächsten `step_order`:

```php
public function findNextStep(int $workflowId, int $currentOrder): ?array
{
    return $this->db->fetchOne(
        'SELECT * FROM workflow_steps WHERE workflow_id = ? AND step_order > ? ORDER BY step_order ASC LIMIT 1',
        [$workflowId, $currentOrder],
    );
}
```

`step_order > current` + `ORDER BY step_order ASC LIMIT 1` findet den unmittelbar folgenden
Schritt. Wenn kein nächster Schritt existiert (letzter Schritt), gibt `findNextStep` `null` zurück
→ der Durchlauf wird als `completed` markiert mit `current_step_id = null`.

---

## `reject`: Durchlauf beenden

`POST /runs/{id}/reject` zeichnet die Aktion auf und markiert den Durchlauf als `rejected`:

```php
$this->repo->recordAction($id, $currentStepId, 'reject', $actor, $comment, $this->now());
$this->repo->updateRun($id, 'rejected', null, $this->now());
```

`current_step_id` wird bei Ablehnung auf `null` gesetzt — kein aktiver Schritt verbleibt. Der Durchlauf
ist terminal: weitere `approve`/`reject`-Aufrufe geben `409` zurück, weil `status !== 'in_progress'`.

---

## Aktionshistorie: JOIN mit Schrittname

Die Durchlauf-Antwort enthält die vollständige Aktionshistorie:

```php
$run     = $this->repo->findRun($id);
$actions = $this->repo->findActions($id);
return $this->json->create(array_merge($run, ['history' => $actions]));
```

Aktionen werden mit einem `JOIN` abgerufen, um jede Zeile mit dem Schrittnamen anzureichern:

```php
$this->db->fetchAll(
    'SELECT wa.*, ws.name AS step_name FROM workflow_actions wa
     JOIN workflow_steps ws ON wa.step_id = ws.id
     WHERE wa.run_id = ? ORDER BY wa.id ASC',
    [$runId],
);
```

`ORDER BY wa.id ASC` bewahrt die chronologische Einfügereihenfolge für den Prüfpfad.

---

## Durchlauf-Zustandsmaschine

```
                 POST /runs
                     │
                     ▼
               in_progress  ──approve (letzter Schritt)──► completed
                     │
               approve (nicht letzter Schritt)
                     │
                     ▼
               in_progress (nächster Schritt)
                     │
                  reject
                     │
                     ▼
                 rejected
```

Die Zustände `completed` und `rejected` sind terminal — keine weiteren Zustandsübergänge sind erlaubt.
Jedes `approve`/`reject` auf einem terminalen Durchlauf gibt `409 Conflict` zurück.

---

## `findRun` mit `current_step_name` via `LEFT JOIN`

Der Durchlauf wird mit einem `LEFT JOIN` abgerufen, um den Namen des aktuellen Schritts einzuschließen:

```php
$this->db->fetchOne(
    'SELECT wr.*, ws.name AS current_step_name, ws.step_order AS current_step_order
     FROM workflow_runs wr
     LEFT JOIN workflow_steps ws ON wr.current_step_id = ws.id
     WHERE wr.id = ?',
    [$id],
);
```

`LEFT JOIN` (nicht `INNER JOIN`) — wenn `current_step_id` `null` ist (abgeschlossener/abgelehnter
Durchlauf), sind `ws.*`-Spalten `null` statt die Zeile verschwinden zu lassen.

---

## Verwandte Anleitungen

- [`approval-workflow.md`](approval-workflow.md) — Genehmigungsmuster mit pending/approved/rejected-Zuständen
- [`state-machine-audit-log.md`](state-machine-audit-log.md) — Zustandsübergangsaufzeichnung und InvalidTransitionException
- [`multi-step-workflow.md`](multi-step-workflow.md) — Sequenzielles mehrstufiges Formular/Prozess-Muster
- [`audit-trail.md`](audit-trail.md) — Nur-Anhänge-Ereignisaufzeichnungsmuster
