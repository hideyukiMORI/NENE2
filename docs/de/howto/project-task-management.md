# Anleitung: Projekt- und Aufgabenverwaltung mit verschachtelten Ressourcen

> **FT-Referenz**: FT241 (`NENE2-FT/projtrack`) — Projekt- und Aufgabenverwaltungs-API

Demonstriert eine zweistufige verschachtelte Ressourcen-API, bei der Aufgaben zu Projekten gehören,
mit Überprüfung der Eltern-Existenz, selektiven PATCH-Aktualisierungen über `array_key_exists()`,
Status-Allowlist über CHECK-Einschränkung, Priorität als Integer und `204 No Content`
für DELETE-Antworten.

---

## Routen

| Methode   | Pfad                                  | Beschreibung                                          |
|-----------|---------------------------------------|-------------------------------------------------------|
| `GET`     | `/projects`                           | Projekte auflisten (paginiert)                        |
| `POST`    | `/projects`                           | Projekt erstellen                                     |
| `GET`     | `/projects/{id}`                      | Einzelnes Projekt abrufen                             |
| `DELETE`  | `/projects/{id}`                      | Projekt löschen (kaskadiert auf Aufgaben)             |
| `GET`     | `/projects/{projectId}/tasks`         | Aufgaben eines Projekts auflisten (paginiert, filterbar) |
| `POST`    | `/projects/{projectId}/tasks`         | Aufgabe in einem Projekt erstellen                    |
| `GET`     | `/projects/{projectId}/tasks/{taskId}`| Einzelne Aufgabe abrufen                              |
| `PATCH`   | `/projects/{projectId}/tasks/{taskId}`| Aufgabe selektiv aktualisieren (fehlende Felder beibehalten) |
| `DELETE`  | `/projects/{projectId}/tasks/{taskId}`| Aufgabe löschen (`204 No Content`)                    |

---

## Schema

```sql
CREATE TABLE IF NOT EXISTS projects (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    name        TEXT    NOT NULL,
    description TEXT    NOT NULL DEFAULT '',
    created_at  TEXT    NOT NULL,
    updated_at  TEXT    NOT NULL
);

CREATE TABLE IF NOT EXISTS tasks (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    project_id  INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
    title       TEXT    NOT NULL,
    status      TEXT    NOT NULL DEFAULT 'open' CHECK(status IN ('open', 'in_progress', 'done')),
    priority    INTEGER NOT NULL DEFAULT 0,
    created_at  TEXT    NOT NULL,
    updated_at  TEXT    NOT NULL
);
```

`status` ist auf DB-Ebene durch `CHECK(status IN (...))` eingeschränkt — ein Sicherheitsnetz
gegen ungültige Werte. `ON DELETE CASCADE` bedeutet, dass das Löschen eines Projekts
alle zugehörigen Aufgaben automatisch entfernt. `priority` ist standardmäßig `0`; höhere Werte sortieren zuerst.

---

## Verschachtelte Ressourcen: Überprüfung der Eltern-Existenz

Jede Aufgabenoperation validiert, dass das übergeordnete Projekt **vor** der Aufgabenverarbeitung
existiert. Wenn die Projekt-ID unbekannt ist, wird sofort `ProjectNotFoundException` geworfen:

```php
private function listTasks(ServerRequestInterface $request): ResponseInterface
{
    $params    = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
    $projectId = (int) ($params['projectId'] ?? 0);

    // Sicherstellen, dass das Projekt existiert (wirft ProjectNotFoundException → 404)
    $this->projects->findById($projectId);

    $items = $this->tasks->findByProject($projectId, $status, $pagination->limit, $pagination->offset);
    // ...
}
```

`ProjectNotFoundException` ist als Exception-Handler registriert, der auf
`404 Not Found` abbildet. Das bedeutet: `/projects/99/tasks` gibt `404` zurück, wenn Projekt 99 nicht
existiert — denselben Status wie eine fehlende Aufgabe. Aufrufer können „Projekt fehlt"
nicht von „Aufgabe fehlt" unterscheiden, ohne das Problem-Details-`detail`-Feld zu lesen.

Das Aufgaben-Repository erzwingt auch auf SQL-Ebene die Projektzugehörigkeit:

```php
public function findByProjectAndId(int $projectId, int $taskId): Task
{
    $row = $this->executor->fetchOne(
        'SELECT * FROM tasks WHERE id = ? AND project_id = ?',
        [$taskId, $projectId],
    );
    if ($row === null) {
        throw new TaskNotFoundException($projectId, $taskId);
    }
    return $this->hydrate($row);
}
```

`WHERE id = ? AND project_id = ?` verhindert projektübergreifenden Zugriff — Aufgabe 5 unter Projekt
1 kann nicht über `/projects/2/tasks/5` abgerufen werden, selbst wenn Aufgabe 5 existiert.

---

## PATCH: selektive Feldaktualisierung mit `array_key_exists()`

`PATCH /projects/{projectId}/tasks/{taskId}` akzeptiert jede Teilmenge von `title`, `status`
und `priority`. Im Request-Body fehlende Felder werden beibehalten.

`isset()` kann nicht zwischen „Schlüssel fehlt" und „Schlüssel vorhanden mit null" unterscheiden. Für
PATCH-Semantik ist `array_key_exists()` das richtige Werkzeug:

```php
$title    = null;
$status   = null;
$priority = null;

if (array_key_exists('title', $body)) {
    if (!is_string($body['title']) || trim($body['title']) === '') {
        $errors[] = new ValidationError('title', 'title must be a non-empty string.', 'invalid_value');
    } else {
        $title = trim($body['title']);
    }
}

if (array_key_exists('status', $body)) {
    $validStatuses = ['open', 'in_progress', 'done'];
    if (!is_string($body['status']) || !in_array($body['status'], $validStatuses, true)) {
        $errors[] = new ValidationError('status', 'status must be one of: open, in_progress, done.', 'invalid_value');
    } else {
        $status = $body['status'];
    }
}

if (array_key_exists('priority', $body)) {
    if (!is_int($body['priority'])) {
        $errors[] = new ValidationError('priority', 'priority must be an integer.', 'invalid_type');
    } else {
        $priority = $body['priority'];
    }
}
```

`$title`, `$status` und `$priority` bleiben `null`, wenn der Schlüssel fehlt. Das
Repository interpretiert `null` als „nicht ändern":

```php
public function update(int $projectId, int $taskId, ?string $title, ?string $status, ?int $priority, string $now): Task
{
    $existing    = $this->findByProjectAndId($projectId, $taskId);
    $newTitle    = $title    ?? $existing->title;
    $newStatus   = $status   ?? $existing->status;
    $newPriority = $priority ?? $existing->priority;

    $this->executor->execute(
        'UPDATE tasks SET title = ?, status = ?, priority = ?, updated_at = ? WHERE id = ? AND project_id = ?',
        [$newTitle, $newStatus, $newPriority, $now, $taskId, $projectId],
    );

    return $this->findByProjectAndId($projectId, $taskId);
}
```

Der Null-Koaleszierende `??` führt bereitgestellte Werte mit dem vorhandenen Datensatz zusammen. Ein einziges
`UPDATE` wird immer ausgeführt (keine dynamische Spaltenliste nötig) — der vorhandene Wert
ersetzt sich einfach selbst, wenn ein Feld fehlt.

---

## `is_int()` für priority: Fließkommazahlen und Strings aus JSON ablehnen

JSON `1` dekodiert als PHP `int`, aber `1.0` dekodiert als `float` und `"1"` als `string`.
`is_int()` akzeptiert nur die Integer-Form:

```php
if (isset($body['priority'])) {
    if (!is_int($body['priority'])) {
        $errors[] = new ValidationError('priority', 'priority must be an integer.', 'invalid_type');
    } else {
        $priority = $body['priority'];
    }
}
```

`is_numeric()` würde `"1"` und `1.0` akzeptieren — verwenden Sie `is_int()` für strikte Integer-Validierung.
Hinweis: `priority` ist bei der Erstellung optional (Standard: `0`); beim PATCH gilt dieselbe Prüfung
innerhalb des `array_key_exists('priority', $body)`-Blocks.

---

## Status-Allowlist-Validierung

Der Status wird gegen eine explizite Allowlist validiert, bevor er die DB erreicht:

```php
$validStatuses = ['open', 'in_progress', 'done'];
if (!is_string($body['status']) || !in_array($body['status'], $validStatuses, true)) {
    $errors[] = new ValidationError('status', 'status must be one of: open, in_progress, done.', 'invalid_value');
}
```

`in_array(..., true)` — strenger Vergleich — stellt sicher, dass der Wert ein String ist, der einem
der erlaubten Zustände entspricht. Die DB-`CHECK`-Einschränkung bietet eine zweite Verteidigungslinie,
aber die Prüfung auf Anwendungsebene liefert ein strukturiertes `422` mit einer aussagekräftigen Fehlermeldung
statt einem rohen DB-Fehler.

---

## Status-Filter für die Aufgabenauflistung

`GET /projects/{projectId}/tasks?status=open` filtert Aufgaben nach Status. Der Querystring
wird mit `QueryStringParser::string()` gelesen:

```php
$status = QueryStringParser::string($request, 'status');

$validStatuses = ['open', 'in_progress', 'done'];
if ($status !== null && !in_array($status, $validStatuses, true)) {
    throw new ValidationException([
        new ValidationError('status', 'status must be one of: open, in_progress, done.', 'invalid_value'),
    ]);
}
```

`QueryStringParser::string()` gibt `null` zurück, wenn der Parameter fehlt — kein Filter
wird angewendet. Ein ungültiger Wert gibt `422 Unprocessable Entity` zurück, anstatt stillschweigend
eine leere Liste zurückzugeben.

Das Repository baut die WHERE-Klausel dynamisch auf:

```php
public function findByProject(int $projectId, ?string $status = null, int $limit = 20, int $offset = 0): array
{
    $where  = ['project_id = ?'];
    $params = [$projectId];

    if ($status !== null) {
        $where[]  = 'status = ?';
        $params[] = $status;
    }

    $sql = 'SELECT * FROM tasks WHERE ' . implode(' AND ', $where)
        . ' ORDER BY priority DESC, created_at ASC LIMIT ? OFFSET ?';
    $params[] = $limit;
    $params[] = $offset;

    return array_map($this->hydrate(...), $this->executor->fetchAll($sql, $params));
}
```

Aufgaben werden nach `priority DESC` sortiert (höhere Priorität zuerst), dann nach `created_at ASC`
(ältere Aufgaben zuerst bei gleicher Priorität).

---

## `204 No Content` für DELETE

DELETE-Antworten enthalten keinen Body. `JsonResponseFactory::createEmpty(204)` erzeugt eine
`204 No Content`-Antwort:

```php
private function deleteTask(ServerRequestInterface $request): ResponseInterface
{
    $params    = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
    $projectId = (int) ($params['projectId'] ?? 0);
    $taskId    = (int) ($params['taskId'] ?? 0);

    $this->projects->findById($projectId);
    $this->tasks->delete($projectId, $taskId);

    return $this->json->createEmpty(204);
}
```

Das Aufgaben-Repository prüft die Existenz vor dem Löschen:

```php
public function delete(int $projectId, int $taskId): void
{
    $this->findByProjectAndId($projectId, $taskId);  // wirft TaskNotFoundException, wenn nicht vorhanden
    $this->executor->execute('DELETE FROM tasks WHERE id = ? AND project_id = ?', [$taskId, $projectId]);
}
```

Wenn die Aufgabe nicht existiert (oder zu einem anderen Projekt gehört), wird `TaskNotFoundException`
geworfen → `404 Not Found`, bevor ein DELETE ausgeführt wird.

---

## Verwendete NENE2-Bausteine

| Baustein | Zweck |
|---|---|
| `PaginationQueryParser::parse()` | Liest `?limit=` und `?offset=` mit sicheren Standardwerten |
| `PaginationResponse` | Erzeugt `{ items, total, limit, offset }` Hüllstruktur |
| `ValidationException` / `ValidationError` | Strukturiertes `422` mit `errors`-Array |
| `QueryStringParser::string()` | Liest einen benannten Querystring-Parameter, gibt `null` zurück, wenn nicht vorhanden |
| `JsonRequestBodyParser::parse()` | Dekodiert JSON-Body |
| `JsonResponseFactory::create()` | Kodiert JSON-Antwort |
| `JsonResponseFactory::createEmpty()` | Erzeugt eine körperlose Antwort (z. B. `204`) |
| `Router::PARAMETERS_ATTRIBUTE` | Ruft Pfadparameter aus dem Request ab |

---

## Verwandte Anleitungen

- [`note-management-ownership.md`](note-management-ownership.md) — IDOR-Prävention mit `WHERE id = ? AND owner_id = ?`
- [`contact-management.md`](contact-management.md) — Viele-zu-viele-Beziehungen, Suchfilterung
- [`document-versioning.md`](document-versioning.md) — Append-only-Versionierung mit `is_current`-Flag
- [`scheduled-reminders.md`](scheduled-reminders.md) — V::userId()-Header-Validierungsmuster
