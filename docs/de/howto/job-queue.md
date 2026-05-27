# Hintergrund-Job-Queue mit Retry und Idempotenz

Diese Anleitung behandelt die Implementierung einer persistenten Hintergrund-Job-Queue in NENE2-Anwendungen. Das Muster unterstützt Prioritätswarteschlangen, automatischen Retry mit Rückzählern und idempotente Job-Erstellung.

## Kernkonzepte

Eine Job-Queue entkoppelt Arbeit von HTTP-Request-Zyklen. Der HTTP-Handler reiht einen Job ein und kehrt sofort zurück; ein separater Worker-Prozess beansprucht und führt Jobs aus.

Wichtige Zustände: `pending` → `running` → `completed` oder `failed` (mit automatischem Wiedereinreihen, wenn Wiederholungen verbleiben).

## Schema-Design

```sql
CREATE TABLE IF NOT EXISTS jobs (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    type            TEXT    NOT NULL,
    payload         TEXT    NOT NULL DEFAULT '{}',
    priority        INTEGER NOT NULL DEFAULT 0,
    status          TEXT    NOT NULL DEFAULT 'pending',
    retry_count     INTEGER NOT NULL DEFAULT 0,
    max_retries     INTEGER NOT NULL DEFAULT 3,
    idempotency_key TEXT    UNIQUE,
    claimed_at      TEXT,
    worker_id       TEXT,
    error           TEXT,
    created_at      TEXT    NOT NULL,
    updated_at      TEXT    NOT NULL
);
```

`idempotency_key UNIQUE` wird auf Datenbankebene erzwungen, nicht nur auf Anwendungsebene. Das verhindert Race Conditions, bei denen zwei gleichzeitige HTTP-Anfragen beide die Anwendungsebenenprüfung bestehen und beide ein INSERT versuchen.

## Job-Lebenszyklus

```
POST /jobs                  → pending (retry_count=0)
POST /jobs/claim            → running (worker_id, claimed_at gesetzt)
POST /jobs/{id}/complete    → completed
POST /jobs/{id}/fail        → pending (retry_count+1) wenn Retries verbleiben
                            → failed wenn retry_count >= max_retries
```

## Retry-Logik

Wenn ein Worker `fail` aufruft, entscheidet das Repository, ob wiedereingereiht oder dauerhaft fehlgeschlagen wird:

```php
public function fail(int $id, string $error, string $now): ?Job
{
    $job = $this->findById($id);
    if ($job === null || $job->status !== JobStatus::Running) {
        return null;
    }

    if ($job->retryCount < $job->maxRetries) {
        $this->executor->execute(
            "UPDATE jobs SET status = 'pending', retry_count = retry_count + 1,
             error = ?, claimed_at = NULL, worker_id = NULL, updated_at = ? WHERE id = ?",
            [$error, $now, $id],
        );
    } else {
        $this->executor->execute(
            "UPDATE jobs SET status = 'failed', error = ?, updated_at = ? WHERE id = ?",
            [$error, $now, $id],
        );
    }

    return $this->findById($id);
}
```

Das `error`-Feld speichert den **zuletzt aufgetretenen** Fehlergrund auch bei Wiedereinreihung, was Operatoren einen Diagnose-Trail auf dem Job-Datensatz gibt.

## Idempotenz

Einen `idempotency_key` beim Erstellen eines Jobs übergeben, um die Operation für Wiederholungsversuche vom HTTP-Client sicher zu machen:

```http
POST /jobs
Content-Type: application/json

{
  "type": "send-invoice",
  "payload": {"invoice_id": 42},
  "idempotency_key": "invoice-42-send-2026-05"
}
```

- Erster Aufruf: `201 Created` — Job wird erstellt.
- Nachfolgende Aufrufe mit demselben Key: `200 OK` — vorhandener Job zurückgegeben, kein Duplikat erstellt.

Der `UNIQUE`-Constraint der Datenbank auf `idempotency_key` ist das Sicherheitsnetz. Zuerst auf Anwendungsebene prüfen, um nicht primär auf Exception-Handling angewiesen zu sein:

```php
if ($idempotencyKey !== null) {
    $existing = $this->repo->findByIdempotencyKey($idempotencyKey);
    if ($existing !== null) {
        return $this->json->create($existing->toArray(), 200);
    }
}
$job = $this->repo->create(..., $idempotencyKey, $maxRetries);
return $this->json->create($job->toArray(), 201);
```

## Prioritätswarteschlange

Jobs werden nach Priorität DESC, dann created_at ASC (FIFO innerhalb einer Ebene) beansprucht:

```sql
SELECT * FROM jobs
WHERE status = 'pending'
ORDER BY priority DESC, created_at ASC
LIMIT 1
```

Prioritätsstufen (Integer-Werte gespeichert, menschenlesbare Labels exponiert):

| Label | Wert |
|-------|------|
| low | 0 |
| medium | 10 |
| high | 20 |
| critical | 30 |

## Worker-Muster

Worker sind zustandslose Prozesse, die in einer Schleife arbeiten: beanspruchen → ausführen → abschließen oder fehlschlagen lassen.

```
Schleife:
  job = POST /jobs/claim { worker_id: "worker-1" }
  wenn job null ist → schlafen, fortfahren

  versuchen:
    ausführen(job.type, job.payload)
    POST /jobs/{job.id}/complete {}
  fehler abfangen:
    POST /jobs/{job.id}/fail { error: error.message }
```

Worker identifizieren sich mit `worker_id`, damit Operatoren sehen können, welcher Worker einen Job hält, und blockierte Worker diagnostizieren können.

## Blockierte-Job-Erkennung

Jobs im `running`-Status mit einem `claimed_at`-Zeitstempel, der älter als ein Schwellenwert ist, sind blockiert (Worker abgestürzt). Ein Wartungsprozess sollte sie erkennen und wiedereinreihen:

```sql
UPDATE jobs
SET status = 'pending', retry_count = retry_count + 1,
    claimed_at = NULL, worker_id = NULL, updated_at = ?
WHERE status = 'running'
  AND claimed_at < ?             -- älter als Timeout-Schwellenwert
  AND retry_count < max_retries
```

## max_retries=0 für nicht-wiederholbare Jobs

Einige Jobs dürfen nicht wiederholt werden (z.B. Zahlungen, externe Webhooks, bei denen eine Wiederholung Schaden anrichten würde). Bei der Erstellung `max_retries: 0` setzen:

```json
{ "type": "charge-card", "max_retries": 0, "idempotency_key": "charge-order-99" }
```

Der erste `fail`-Aufruf überführt den Job sofort in den Zustand `failed`.

## Designentscheidungen

**Warum Retry-Logik im Repository, nicht im Worker?** Die Entscheidung zum Wiedereinreihen ist eine Datenschicht-Invariante (retry_count < max_retries), keine Geschäftslogik. Sie im Repository zu platzieren, hält Worker einfach und verhindert Inkonsistenz durch Worker, die die Prüfung unterschiedlich implementieren.

**Warum `UNIQUE`-Constraint auf idempotency_key auf DB-Ebene?** Anwendungsebenenprüfungen haben Race Conditions unter gleichzeitigen Anfragen. Der DB-Constraint ist der maßgebliche Schutz; die Anwendungsebenenprüfung ist eine Optimierung, um nicht auf Exception-Handling angewiesen zu sein.

**Warum Priorität als Integer speichern?** Ermöglicht das Hinzufügen von Zwischen-Prioritätsstufen später ohne Schema-Änderungen. Das menschenlesbare Label wird abgeleitet, nicht gespeichert.
