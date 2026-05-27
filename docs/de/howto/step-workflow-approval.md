# How-to: Schrittbasierter Workflow mit Genehmigung

> **FT-Referenz**: FT247 (`NENE2-FT/stepflowlog`) — Schritt-Workflow-Genehmigungs-API

Demonstriert ein zweistufiges Workflow-System, bei dem eine wiederverwendbare Workflow-Definition
eine geordnete Liste von Schritten enthält und ein Workflow-Lauf eine Instanz dieser Definition ist,
die durch Schritte via Genehmigungs-/Ablehnungsaktionen voranschreitet. Jede Aktion wird in einem
Audit-Historie-Protokoll aufgezeichnet.

---

## Routen

| Methode | Pfad                      | Beschreibung                                                       |
|---------|---------------------------|-------------------------------------------------------------------|
| `POST`  | `/workflows`              | Neuen Workflow definieren                                          |
| `GET`   | `/workflows/{id}`         | Workflow mit seinen Schritten abrufen                              |
| `POST`  | `/workflows/{id}/steps`   | Schritt zu einem Workflow hinzufügen (automatisch geordnet)        |
| `POST`  | `/runs`                   | Lauf eines Workflows starten (schlägt fehl wenn Workflow keine Schritte hat) |
| `GET`   | `/runs/{id}`              | Laufstatus mit Aktionshistorie abrufen                             |
| `POST`  | `/runs/{id}/approve`      | Aktuellen Schritt genehmigen (rückt zum nächsten Schritt oder schließt ab) |
| `POST`  | `/runs/{id}/reject`       | Aktuellen Schritt ablehnen (beendet Lauf als abgelehnt)            |

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

`UNIQUE(workflow_id, step_order)` verhindert doppelte Reihenfolge innerhalb eines Workflows.
`current_step_id` ist nullable — `NULL` bedeutet, dass der Lauf `completed` oder `rejected` ist
(kein aktiver Schritt). `action` hat einen DB-Ebene-`CHECK` für `approve`/`reject`.

---

## Automatische Schritt-Reihenfolge

Beim Hinzufügen eines Schritts berechnet der Controller den nächsten `step_order` automatisch:

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

`step_order` beginnt bei `1` und erhöht sich um `1` für jeden neuen Schritt. Der `UNIQUE`-Constraint
verhindert, dass zwei Schritte dieselbe Reihenfolge teilen. Schritte werden stets in Reihenfolge
zurückgegeben:

```php
$this->db->fetchAll(
    'SELECT * FROM workflow_steps WHERE workflow_id = ? ORDER BY step_order ASC',
    [$workflowId],
);
```

---

## Lauf starten: Initialisierung des ersten Schritts

Ein Lauf wird mit dem ersten Schritt des Workflows als `current_step_id` initialisiert:

```php
$steps = $this->repo->findSteps($workflowId);
if ($steps === []) {
    return $this->json->create(['error' => 'Workflow has no steps'], 409);
}

$firstStep = $steps[0];
$runId = $this->repo->createRun($workflowId, $title, (int) $firstStep['id'], $now);
```

`409 Conflict` wird zurückgegeben, wenn der Workflow keine Schritte hat — ein Lauf kann durch
einen schrittlosen Workflow nicht voranschreiten. Der erste Schritt (niedrigstes `step_order`)
wird zum aktiven Schritt.

---

## `approve`: Zum nächsten Schritt vorrücken oder abschließen

`POST /runs/{id}/approve` prüft den aktuellen Status, zeichnet die Aktion auf, dann
findet den nächsten Schritt nach `step_order`:

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

`findNextStep` ruft den Schritt mit dem nächsten `step_order` ab:

```php
public function findNextStep(int $workflowId, int $currentOrder): ?array
{
    return $this->db->fetchOne(
        'SELECT * FROM workflow_steps WHERE workflow_id = ? AND step_order > ? ORDER BY step_order ASC LIMIT 1',
        [$workflowId, $currentOrder],
    );
}
```

`step_order > current` + `ORDER BY step_order ASC LIMIT 1` findet den unmittelbar folgenden Schritt.
Wenn kein nächster Schritt existiert (letzter Schritt), gibt `findNextStep` `null` zurück → der Lauf
wird als `completed` mit `current_step_id = null` markiert.

---

## `reject`: Lauf beenden

`POST /runs/{id}/reject` zeichnet die Aktion auf und markiert den Lauf als `rejected`:

```php
$this->repo->recordAction($id, $currentStepId, 'reject', $actor, $comment, $this->now());
$this->repo->updateRun($id, 'rejected', null, $this->now());
```

`current_step_id` wird bei Ablehnung auf `null` gesetzt — kein aktiver Schritt verbleibt. Der Lauf
ist terminal: weitere `approve`/`reject`-Aufrufe geben `409` zurück, da `status !== 'in_progress'`.

---

## Aktionshistorie: JOIN mit Schrittname

Die Laufantwort enthält die vollständige Aktionshistorie:

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

`ORDER BY wa.id ASC` bewahrt die chronologische Einfügereihenfolge für das Audit-Protokoll.

---

## Lauf-Zustandsautomat

```
                 POST /runs
                     │
                     ▼
               in_progress  ──genehmigen (letzter Schritt)──► completed
                     │
               genehmigen (nicht letzter Schritt)
                     │
                     ▼
               in_progress (nächster Schritt)
                     │
                  ablehnen
                     │
                     ▼
                 rejected
```

Die Zustände `completed` und `rejected` sind terminal — keine weiteren Zustandsübergänge sind erlaubt.
Jedes `approve`/`reject` auf einem terminalen Lauf gibt `409 Conflict` zurück.

---

## `findRun` mit `current_step_name` via `LEFT JOIN`

Der Lauf wird mit einem `LEFT JOIN` abgerufen, um den Namen des aktuellen Schritts einzuschließen:

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
Lauf), sind `ws.*`-Spalten `null` anstatt die Zeile verschwinden zu lassen.

---

## Verwandte Anleitungen

- [`approval-workflow.md`](approval-workflow.md) — Genehmigungs-Muster mit pending/approved/rejected-Zuständen
- [`state-machine-audit-log.md`](state-machine-audit-log.md) — Zustandsübergangs-Aufzeichnung und InvalidTransitionException
- [`multi-step-workflow.md`](multi-step-workflow.md) — Sequenzielles mehrstufiges Formular-/Prozessmuster
- [`audit-trail.md`](audit-trail.md) — Nur-anfügen-Ereignisaufzeichnungs-Muster
