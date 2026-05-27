# How-to: Benachrichtigungs-Warteschlangen-API

Diese Anleitung demonstriert eine Benachrichtigungs-Warteschlange, bei der Admins zielgerichtete Benachrichtigungen an Benutzer senden, die diese auflisten, lesen und löschen können.

## Übersicht des Musters

- Admins senden Benachrichtigungen an bestimmte Benutzer über `POST /notifications` (nur Admin).
- Benutzer empfangen und verwalten ihre eigenen Benachrichtigungen über `GET`, `POST /read`, `DELETE`.
- `unread_count` wird mit jeder Listenantwort zurückgegeben.
- `?unread=1` filtert auf nur-ungelesene Benachrichtigungen.
- Als-Gelesen-Markieren ist idempotent (bereits gelesene Benachrichtigungen geben 200 zurück, kein Fehler).

## Schema

```sql
CREATE TABLE IF NOT EXISTS notifications (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    type       TEXT    NOT NULL DEFAULT 'info',
    title      TEXT    NOT NULL,
    body       TEXT    NOT NULL DEFAULT '',
    is_read    INTEGER NOT NULL DEFAULT 0,
    created_at TEXT    NOT NULL,
    read_at    TEXT
);
```

## Typ-Allowlist

```php
private const array ALLOWED_TYPES = ['info', 'warning', 'error', 'success'];
```

Unbekannte Typen geben 422 zurück. Niemals ein Freitextfeld für Typ ohne Validierung verwenden.

## Idempotentes Als-Gelesen-Markieren

```php
public function markRead(int $id, int $userId): bool
{
    $notif = $this->findById($id);
    if ($notif === null || (int) $notif['user_id'] !== $userId) {
        return false;
    }
    if ((int) $notif['is_read'] === 1) {
        return true;  // Bereits gelesen — idempotent, Erfolg zurückgeben
    }
    $this->pdo->prepare(
        'UPDATE notifications SET is_read = 1, read_at = :now WHERE id = :id'
    )->execute([':now' => $this->now(), ':id' => $id]);
    return true;
}
```

## Ungelesen-Filter

```php
if ($unreadOnly === true) {
    $stmt = $this->pdo->prepare(
        'SELECT * FROM notifications WHERE user_id = :uid AND is_read = 0 ORDER BY id DESC'
    );
}
```

Der Query-Parameter `?unread=1` aktiviert diesen Pfad; jeder andere Wert listet alle auf.

## IDOR: Benutzer-Scoping

Alle Lese-/Lösch-/Listen-Operationen prüfen `user_id`:

```php
if (!$isAdmin && (int) $notif['user_id'] !== $userId) {
    return false;  // → 404
}
```

Nicht-Admin-Benutzer können Benachrichtigungen anderer Benutzer nicht lesen, markieren oder löschen.

## Nur-Admin-Senden

```php
private function send(ServerRequestInterface $req): ResponseInterface
{
    if (!$this->isAdmin($req)) {
        return $this->problem(403, 'forbidden', 'Admin access required.');
    }
    ...
}
```

Die Ziel-`user_id` wird im Request-Body angegeben, validiert als `is_int() && >= 1`.

## Validierungszusammenfassung

| Feld | Regel |
|---|---|
| `user_id` Body | Integer >= 1 (kein String/Float) |
| `type` Body | Eines von: info, warning, error, success |
| `title` Body | Nicht leer, max. 200 Zeichen |
| `X-User-Id`-Header | Erforderlich für Lesen/Löschen; `ctype_digit`, >0 |
| `X-Admin-Key`-Header | Erforderlich für Senden; Fail-Closed wenn leer |

## Routen

```
POST   /notifications                  Benachrichtigung senden (nur Admin)
GET    /users/{userId}/notifications   Benachrichtigungen auflisten (Eigentümer oder Admin)
POST   /notifications/{id}/read        Als gelesen markieren (nur Eigentümer)
DELETE /notifications/{id}             Benachrichtigung löschen (Eigentümer oder Admin)
```

## Weitere Informationen

- FT214-Quelle: `../NENE2-FT/notiflog/`
- Verwandt: `docs/howto/session-token-management.md` (FT208, Admin-Key-Muster)
