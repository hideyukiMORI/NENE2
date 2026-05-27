# How-to: Bulk-Status-Update-API

> **FT-Referenz**: FT85 (`NENE2-FT/bulkupdatelog`) — Bulk-Status-Update-API
> **VULN**: FT231 — Sicherheits-/Schwachstellenbewertung (V-01 bis V-10)

Demonstriert zwei Muster für Bulk-Statusänderungen: Pro-Element-Updates (jedes Element erhält seinen eigenen Zielstatus) und homogene Bulk-Updates (alle Elemente erhalten denselben Status). Beide unterstützen Teilerfolg — die Antwort meldet, welche IDs erfolgreich waren und welche fehlgeschlagen sind.

---

## Routen

| Methode | Pfad | Beschreibung |
|---------|------|-------------|
| `POST`  | `/tasks` | Eine Aufgabe erstellen |
| `GET`   | `/tasks` | Alle Aufgaben auflisten |
| `PATCH` | `/tasks/status` | Pro-Element-Bulk-Status-Update (gemischte Zielstatus) |
| `PATCH` | `/tasks/done` | Eine Reihe von IDs als erledigt markieren (einzelner Zielstatus) |

---

## Pro-Element-Bulk-Update (`PATCH /tasks/status`)

Jedes Update-Element gibt seinen eigenen Zielstatus an:

```json
{
  "updates": [
    {"id": 1, "status": "done"},
    {"id": 2, "status": "cancelled"},
    {"id": 3, "status": "in_progress"}
  ]
}
```

Das Repository verarbeitet jedes Element einzeln und akkumuliert Erfolge und Fehler:

```php
public function bulkUpdateStatus(array $items, string $now): BulkUpdateResult
{
    $updatedIds = [];
    $failed     = [];

    foreach ($items as $item) {
        $itemArr = is_array($item) ? $item : [];
        $id      = isset($itemArr['id']) && is_int($itemArr['id']) ? $itemArr['id'] : null;
        $status  = isset($itemArr['status']) && is_string($itemArr['status'])
            ? TaskStatus::tryFrom($itemArr['status'])
            : null;

        if ($id === null) {
            $failed[] = ['id' => 0, 'error' => 'id must be an integer'];
            continue;
        }

        if ($status === null) {
            $failed[] = ['id' => $id, 'error' => 'invalid status value'];
            continue;
        }

        $affected = $this->executor->execute(
            'UPDATE tasks SET status = ?, updated_at = ? WHERE id = ?',
            [$status->value, $now, $id],
        );

        if ($affected === 0) {
            $failed[] = ['id' => $id, 'error' => 'task not found'];
        } else {
            $updatedIds[] = $id;
        }
    }

    return new BulkUpdateResult($updatedIds, $failed);
}
```

### Antwortstruktur

```json
{
  "updated": [1, 3],
  "failed": [
    {"id": 2, "error": "task not found"}
  ]
}
```

HTTP-Status ist immer `200 OK` — auch wenn alle Elemente fehlschlagen. Der Aufrufer muss `failed` prüfen, um Pro-Element-Fehler zu erkennen.

---

## Homogenes Bulk-Update (`PATCH /tasks/done`)

Alle IDs wechseln zum gleichen Zielstatus in einem einzigen `UPDATE ... WHERE id IN (?)`:

```php
// Body: {"ids": [1, 2, 3]}
$ids = isset($body['ids']) && is_array($body['ids'])
    ? array_values(array_filter($body['ids'], static fn (mixed $v): bool => is_int($v)))
    : [];

if ($ids === []) {
    return $this->json->create(['error' => 'ids array is required and must not be empty'], 422);
}
```

Nicht-Integer-Werte werden stillschweigend per `array_filter(..., is_int(...))` gefiltert. Wenn das Ergebnis nach der Filterung leer ist, wird 422 zurückgegeben.

```php
public function bulkSetStatus(array $ids, TaskStatus $status, string $now): array
{
    $placeholders = implode(',', array_fill(0, count($ids), '?'));

    $this->executor->execute(
        "UPDATE tasks SET status = ?, updated_at = ? WHERE id IN ({$placeholders})",
        [$status->value, $now, ...$ids],
    );

    // IDs zurückgeben, die existieren und jetzt den Zielstatus haben
    $rows = $this->executor->fetchAll(
        "SELECT id FROM tasks WHERE id IN ({$placeholders}) AND status = ?",
        [...$ids, $status->value],
    );

    return array_map(static fn (array $r): int => (int) $r['id'], $rows);
}
```

`implode(',', array_fill(0, count($ids), '?'))` generiert die korrekte Anzahl von `?`-Platzhaltern — sicher, parametrisiert.

---

## Status-Allowlist (backed enum)

`TaskStatus` ist ein backed-String-Enum mit vier Fällen:

```php
enum TaskStatus: string
{
    case Pending    = 'pending';
    case InProgress = 'in_progress';
    case Done       = 'done';
    case Cancelled  = 'cancelled';
}
```

`TaskStatus::tryFrom($string)` gibt `null` für unbekannte Statuswerte zurück, was der Bulk-Handler auf einen Pro-Element-Fehler abbildet. Das Schema fügt `CHECK(status IN (...))` als DB-Auffangbecken hinzu.

---

## Schema

```sql
CREATE TABLE tasks (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    title      TEXT NOT NULL,
    status     TEXT NOT NULL DEFAULT 'pending'
                             CHECK(status IN ('pending', 'in_progress', 'done', 'cancelled')),
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
```

---

## VULN — Sicherheitsbewertung (FT231)

### V-01 — Keine Authentifizierung auf einem Endpunkt

**Angriff**: Alle Aufgaben ohne Anmeldedaten abbrechen.

```json
{"updates": [{"id": 1, "status": "cancelled"}, {"id": 2, "status": "cancelled"}]}
```

**Beobachtet**: `200 OK` — kein Token erforderlich.

**Urteil**: **EXPOSED** (by design für FT85-Demo). In der Produktion Authentifizierung und Autorisierung hinzufügen. Bulk-Mutationen auf den Aufgabeneigentümer oder eine Admin-Rolle beschränken.

---

### V-02 — Mass-Update-DoS (riesiges Array)

**Angriff**: Ein `updates`-Array mit Tausenden von Elementen senden, um CPU oder Speicher zu erschöpfen.

```python
{"updates": [{"id": i, "status": "done"} for i in range(100_000)]}
```

**Beobachtet**: In einer Schleife verarbeitet — jedes Element führt eine `UPDATE`-Abfrage aus. Bei 100.000 Elementen werden 100.000 einzelne SQL-Anweisungen in einer engen Schleife ohne Batch-Größen-Limit ausgeführt.

**Urteil**: **EXPOSED** — maximale Batch-Größe hinzufügen:
```php
$maxBatchSize = 500;
if (count($updates) > $maxBatchSize) {
    return $this->json->create(['error' => "Batch size must not exceed {$maxBatchSize} items."], 422);
}
```

---

### V-03 — SQL-Injection via `IN`-Klausel

**Angriff**: SQL durch das `ids`-Array einschleusen, das in `IN (?)` verwendet wird.

```json
{"ids": ["1; DROP TABLE tasks; --", 1, 2]}
```

**Beobachtet**: Der String `"1; DROP TABLE tasks; --"` wird vom `is_int()`-Filter in `array_filter()` abgelehnt. Nur Integer erreichen die `IN`-Klausel. Das `implode` + `array_fill`-Muster generiert die korrekte Anzahl von `?`-Platzhaltern — keine String-Verkettung von Benutzerdaten.

**Urteil**: **BLOCKED** — `is_int()`-Filter + parametrisierte `IN`-Klausel verhindert Injection.

---

### V-04 — Nicht-Integer-IDs in Pro-Element-Updates

**Angriff**: Nicht-Integer-`id`-Werte im `updates`-Array senden.

```json
{"updates": [{"id": "1", "status": "done"}, {"id": null, "status": "done"}]}
```

**Beobachtet**: Beide Elemente werden mit `'error' => 'id must be an integer'` zu `$failed` hinzugefügt. `is_int()` lehnt Strings und `null` ab.

**Urteil**: **BLOCKED** — strikte `is_int()`-Typprüfung pro Element.

---

### V-05 — Ungültiger Statuswert

**Angriff**: Einen unbekannten Status-String im `updates`-Array senden.

```json
{"updates": [{"id": 1, "status": "hacked"}]}
```

**Beobachtet**: Element wird mit `'error' => 'invalid status value'` zu `$failed` hinzugefügt. `TaskStatus::tryFrom("hacked")` gibt `null` zurück.

**Urteil**: **BLOCKED** — backed enum `tryFrom()` lehnt unbekannte Werte ab.

---

### V-06 — Leeres Array

**Angriff**: Ein leeres `updates`- oder `ids`-Array senden.

```json
{"updates": []}
{"ids": []}
```

**Beobachtet**: Beide geben `422 Unprocessable Entity` mit einer Fehlermeldung zurück.

**Urteil**: **BLOCKED** — Leer-Array-Prüfung vor der Verarbeitung.

---

### V-07 — Doppelte IDs im gleichen Batch

**Angriff**: Dieselbe `id` mehrfach in einer Anfrage aufnehmen.

```json
{"updates": [{"id": 1, "status": "done"}, {"id": 1, "status": "cancelled"}]}
```

**Beobachtet**: Beide Updates gelingen. Das zweite UPDATE überschreibt das erste — die Aufgabe endet als `cancelled`. Keine Deduplizierung findet statt.

**Urteil**: **ACCEPTED BY DESIGN** — Last-Write-Wins-Semantik ist konsistent für einfache Aufgabenverwaltung. Wenn Konflikte abgelehnt werden sollen, `ids` vor der Verarbeitung deduplizieren und einen Fehler bei Duplikaten zurückgeben.

---

### V-08 — Negative und Null-IDs

**Angriff**: IDs `0` oder `-1` senden.

```json
{"ids": [0, -1]}
```

**Beobachtet**: `is_int(0)` = true, `is_int(-1)` = true — beide bestehen den Filter. Das UPDATE läuft mit `WHERE id IN (0, -1)`, was keine Zeilen trifft. Antwort: `{"requested": 2, "updated": 0, "ids": []}`.

**Urteil**: **BLOCKED** in der Praxis (keine betroffenen Zeilen). Für nicht existierende IDs wird kein Fehler zurückgegeben — dies ist konsistent mit dem Teilerfolg-Muster. Einen Positiv-Integer-Guard hinzufügen, wenn negative IDs mit 422 abgelehnt werden sollen.

---

### V-09 — Bulk-Update überspringt nicht existierende Aufgaben stillschweigend

**Angriff**: IDs aufnehmen, die nicht in der Datenbank vorhanden sind.

```json
{"ids": [99999, 100000]}
```

**Beobachtet**: `{"requested": 2, "updated": 0, "ids": []}` — kein Fehler, kein Hinweis darauf, dass die Aufgaben nicht existieren.

**Urteil**: **ACCEPTED BY DESIGN** — Teilerfolg-Modell. Dieses Verhalten in der API-Spezifikation dokumentieren. Wenn Aufrufer "keine solche Aufgabe" von "Aufgabe bereits im Zielstatus" unterscheiden müssen, kann die Antwort eine `not_found`-Liste enthalten.

---

### V-10 — Gleichzeitige Bulk-Updates auf denselben IDs

**Angriff**: Zwei gleichzeitige `PATCH /tasks/done`-Anfragen für dieselbe Reihe von IDs senden.

**Beobachtet**: Beide UPDATE-Anweisungen laufen auf der DB. SQLites Zeilen-Level-Locking bedeutet, dass ein UPDATE zuerst abgeschlossen wird, dann läuft das zweite UPDATE auf bereits-`done`-Zeilen. Beide Antworten geben `updated`-IDs zurück (da die Zeilen noch mit `status = done` existieren).

**Urteil**: **BLOCKED** — idempotente Schreibvorgänge. Beide Anfragen liefern dasselbe Ergebnis (alle IDs auf `done` gesetzt). Bei `status`-Updates, bei denen der Zielstatus pro Aufrufer unterschiedlich ist, verwenden gleichzeitige Schreibvorgänge Last-Write-Wins.

---

## VULN-Zusammenfassung

| # | Angriffsvektor | Urteil |
|---|----------------|--------|
| V-01 | Keine Authentifizierung | EXPOSED (by design) |
| V-02 | Mass-Update-DoS (riesiges Array) | EXPOSED |
| V-03 | SQL-Injection via `IN`-Klausel | BLOCKED |
| V-04 | Nicht-Integer-IDs | BLOCKED |
| V-05 | Ungültiger Statuswert | BLOCKED |
| V-06 | Leeres Array | BLOCKED |
| V-07 | Doppelte IDs im Batch | ACCEPTED BY DESIGN |
| V-08 | Negative/Null-IDs | BLOCKED |
| V-09 | Nicht existierende Aufgaben stillschweigend übersprungen | ACCEPTED BY DESIGN |
| V-10 | Gleichzeitige Bulk-Updates | BLOCKED |

**Echte Schwachstellen, die vor der Produktion behoben werden müssen**:
1. **V-01** — Authentifizierung und Autorisierung hinzufügen
2. **V-02** — Maximale Batch-Größe hinzufügen (z. B. 500 Elemente)

---

## Verwandte Anleitungen

- [`implement-bulk-endpoint.md`](implement-bulk-endpoint.md) — Bulk-Create mit Pro-Element-Fehlern
- [`batch-api-partial-success.md`](batch-api-partial-success.md) — Teilerfolg-Muster
- [`approval-workflow.md`](approval-workflow.md) — Statusübergänge mit Enum-Guard
