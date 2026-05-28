# How-to: Wartelistensystem

> **FT-Referenz**: FT287 (`NENE2-FT/waitlistlog`) — Wartelistensystem: UNIQUE(user_id) Ein-Eintrag-Constraint, waiting→approved/declined-Zustandsmaschine, isTerminal()-Guard, /waitlist/me vor /{id} registriert um Route-Capture zu verhindern, X-Admin-Key-Authentifizierung, Queue-Positionsverfolgung, 39 Tests / 98 Assertions BESTANDEN.

Diese Anleitung zeigt, wie ein Wartelistensystem aufgebaut wird, bei dem Benutzer einer Warteschlange beitreten und Administratoren Einträge genehmigen oder ablehnen.

## Schema

```sql
CREATE TABLE IF NOT EXISTS waitlist_entries (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL UNIQUE,   -- ein Eintrag pro Benutzer
    status     TEXT    NOT NULL DEFAULT 'waiting',  -- waiting | approved | declined
    note       TEXT,                               -- optionale Benutzernotiz (max 500 Zeichen)
    created_at TEXT    NOT NULL,
    updated_at TEXT    NOT NULL
);
```

`user_id UNIQUE` setzt einen Eintrag pro Benutzer auf DB-Ebene durch — keine Anwendungsschicht-Prüfung für Race Conditions erforderlich.

## Endpunkte

| Methode | Pfad | Auth | Beschreibung |
|---------|------|------|--------------|
| `POST` | `/waitlist` | `X-User-Id` | Warteliste beitreten |
| `GET` | `/waitlist/me` | `X-User-Id` | Eigenen Status + Position abrufen |
| `DELETE` | `/waitlist/me` | `X-User-Id` | Warteliste verlassen |
| `GET` | `/waitlist` | `X-Admin-Key` | Admin: alle Einträge auflisten |
| `POST` | `/waitlist/{id}/approve` | `X-Admin-Key` | Admin: Eintrag genehmigen |
| `POST` | `/waitlist/{id}/decline` | `X-Admin-Key` | Admin: Eintrag ablehnen |

## Routenregistrierungsreihenfolge

`/waitlist/me` muss **vor** `/waitlist/{id}` registriert werden, um zu verhindern, dass der Pfadparameter den wörtlichen String `"me"` erfasst:

```php
// KORREKT: statischer Pfad vor dynamischem Pfad
$this->router->get('/waitlist/me', $this->handleMe(...));
$this->router->post('/waitlist/{id}/approve', $this->handleApprove(...));

// FALSCH: {id} würde "me" erfassen
$this->router->post('/waitlist/{id}/approve', $this->handleApprove(...));
$this->router->get('/waitlist/me', $this->handleMe(...));  // wird nie erreicht
```

## Status-Lebenszyklus

```
waiting ──────→ approved (terminal)
       └──────→ declined (terminal)
```

Einmal genehmigt oder abgelehnt, kann ein Eintrag nicht in einen anderen Zustand übergehen. Die `isTerminal()`-Methode schützt dies:

```php
enum WaitlistStatus: string
{
    case Waiting  = 'waiting';
    case Approved = 'approved';
    case Declined = 'declined';

    public function isTerminal(): bool
    {
        return $this !== self::Waiting;
    }
}
```

## Beitreten mit 409 bei Duplikat

```php
$entry = $this->repository->join($userId, $note);

if ($entry === null) {
    return $this->responseFactory->create(['error' => 'Already on the waitlist.'], 409);
}
```

Das Repository gibt `null` zurück, wenn `user_id` bereits existiert (aus `DatabaseConstraintException` abgefangen). Die Antwort ist 409 Conflict.

## Positionsverfolgung

```php
$position = $this->repository->positionOf($entry);

// positionOf() zählt Einträge mit status='waiting' und id <= $entry->id
// SELECT COUNT(*) FROM waitlist_entries WHERE status = 'waiting' AND id <= ?
```

Position ist der 1-basierte Rang in der `waiting`-Warteschlange. Genehmigte/abgelehnte Einträge zählen nicht. Das gibt Benutzern einen aussagekräftigen Platz in der Schlange.

## Admin-Übergang mit match

```php
private function handleTransition(int $id, WaitlistStatus $newStatus): ResponseInterface
{
    $result = $this->repository->transition($id, $newStatus);

    return match ($result) {
        'ok'               => $this->responseFactory->create(['status' => $newStatus->value]),
        'not_found'        => $this->responseFactory->create(['error' => 'Entry not found.'], 404),
        'already_terminal' => $this->responseFactory->create(['error' => 'Entry is already approved or declined.'], 409),
        default            => $this->responseFactory->create(['error' => 'Unexpected error.'], 500),
    };
}
```

`match` ist erschöpfend — der `default`-Fall fängt unerwartete Rückgabewerte aus dem Repository ab.

## Verlassen (nur im Waiting-Status)

```php
return match ($result) {
    'removed'     => $this->responseFactory->create(['removed' => true], 200),
    'not_found'   => $this->responseFactory->create(['error' => 'Not on the waitlist.'], 404),
    'not_waiting' => $this->responseFactory->create(['error' => 'Cannot leave — status is no longer waiting.'], 409),
    default       => $this->responseFactory->create(['error' => 'Unexpected error.'], 500),
};
```

Einmal genehmigt oder abgelehnt, kann ein Benutzer die Warteliste nicht mehr verlassen — seine Entscheidung ist festgehalten. Das verhindert das Austricksen des Systems (genehmigen, dann verlassen um Tracking zu vermeiden).

## Admin-Authentifizierung

```php
private function isAdmin(ServerRequestInterface $request): bool
{
    if ($this->adminKey === '') {
        return false;  // fail-closed: kein konfigurierter Schlüssel → kein Admin-Zugriff
    }
    return hash_equals($this->adminKey, $request->getHeaderLine('X-Admin-Key'));
}
```

`hash_equals()` verhindert Timing-Angriffe. Leerer Admin-Key gibt immer false zurück (fail-closed).

## Notiz-Validierung

```php
private const int MAX_NOTE_LEN = 500;

private function resolveNote(mixed $raw): ?string
{
    if (!is_string($raw) || trim($raw) === '') {
        return null;
    }
    return mb_strlen($raw) > self::MAX_NOTE_LEN ? mb_substr($raw, 0, self::MAX_NOTE_LEN) : $raw;
}
```

Notizen sind optional (null wenn fehlend/leer), max 500 Zeichen, werden bei Überschreitung abgeschnitten (nicht abgelehnt).

---

## Was NICHT zu tun ist

| Anti-Muster | Risiko |
|-------------|--------|
| Kein `UNIQUE(user_id)`-Constraint | Gleichzeitige Beitritte erzeugen doppelte Einträge; Race Condition |
| `/{id}` vor `/me` registrieren | `/waitlist/me` wird unerreichbar — von `{id}` mit `"me"` erfasst |
| Übergang aus Terminal-Zustand erlauben | Genehmigter Eintrag nach Zugangsgewährung abgelehnt; Zustandsmaschine kaputt |
| Verlassen aus Terminal-Zustand erlauben | Genehmigter Benutzer verlässt; Zugangsgewährung wird verwaist |
| Position basierend auf `id ASC` mit allen Einträgen berechnen | Zählt genehmigte/abgelehnte Benutzer; Positionsnummer ist irreführend |
| Admin-Key in DB speichern | Key-Rotation erfordert DB-Update; stattdessen Env-Var verwenden |
| `==` statt `hash_equals()` für Admin-Key | Timing-Angriff enthüllt Key ein Zeichen nach dem anderen |
| Kein Admin-Fail-Closed | Leerer Key in Env erlaubt unauthentifizierten Admin-Zugriff |
| Notiz ablehnen wenn zu lang | UX: Abschneiden ist benutzerfreundlicher als Ablehnen für weiche Metadaten wie Notizen |
