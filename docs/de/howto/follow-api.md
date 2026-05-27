# How-to: Follow / Unfollow API

> **FT-Referenz**: FT314 (`NENE2-FT/followlog`) — Social-Follow-Graph: idempotentes Follow (POST 201 beim ersten Mal, 200 bei Wiederholung), Selbst-Follow-Prävention (422), Unfollow (DELETE 204), Follower/Following-Zählungen über Stats, paginierte Listen nach neuesten zuerst geordnet, Is-Following-Prüfung, gegenseitiges Follow unterstützt, 20 Tests / 72 Assertions bestanden.

Diese Anleitung zeigt, wie ein soziales Follow-System aufgebaut wird, bei dem Benutzer sich gegenseitig folgen und entfolgen können, mit Follower/Following-Zählungen und Listen-Endpunkten.

## Schema

```sql
CREATE TABLE users (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE follows (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    follower_id INTEGER NOT NULL REFERENCES users(id),
    followee_id INTEGER NOT NULL REFERENCES users(id),
    created_at  TEXT NOT NULL DEFAULT (datetime('now')),
    UNIQUE (follower_id, followee_id)
);
```

Die `UNIQUE (follower_id, followee_id)`-Constraint erzwingt die Idempotenz von Follow-Beziehungen auf DB-Ebene.

## Endpunkte

| Methode | Pfad | Beschreibung |
|---------|------|--------------|
| `POST` | `/users` | Benutzer erstellen |
| `POST` | `/users/{id}/follow` | Einem anderen Benutzer folgen |
| `DELETE` | `/users/{id}/follow/{followeeId}` | Entfolgen |
| `GET` | `/users/{id}/stats` | Follower/Following-Zählungen abrufen |
| `GET` | `/users/{id}/followers` | Follower auflisten (neueste zuerst) |
| `GET` | `/users/{id}/following` | Following auflisten (neueste zuerst) |
| `GET` | `/users/{id}/is-following/{targetId}` | Prüfen, ob Following |

## Idempotentes Follow

```php
// Erstes Follow → 201 Created
POST /users/1/follow  {"followee_id": 2}
→ 201  {"following": true, "follower_id": 1, "followee_id": 2}

// Wiederholtes Follow mit demselben Paar → 200 OK (nicht 201, nicht 409)
POST /users/1/follow  {"followee_id": 2}
→ 200  {"following": true, "follower_id": 1, "followee_id": 2}
```

```php
// Handler-Logik
try {
    $this->repo->follow($followerId, $followeeId);
    return $json->ok($response, ['following' => true, ...], 201);
} catch (DuplicateFollowException $e) {
    return $json->ok($response, ['following' => true, ...], 200); // bereits folgend
}
```

## Selbst-Follow-Prävention

```php
POST /users/1/follow  {"followee_id": 1}
→ 422 Unprocessable Entity
```

```php
if ($followerId === $followeeId) {
    throw new ValidationException([
        ['field' => 'followee_id', 'message' => 'Cannot follow yourself.', 'code' => 'self-follow'],
    ]);
}
```

## Entfolgen

```php
DELETE /users/1/follow/2
→ 204 No Content   // erfolgreich entfolgt

DELETE /users/1/follow/2  // wenn nicht folgend
→ 404 Not Found
```

Entfolgen-dann-Wiederfolgen-Zyklus funktioniert korrekt: DELETE → POST gibt wieder 201 zurück.

## Stats

```php
GET /users/1/stats
→ 200
{
    "user_id": 1,
    "followers_count": 2,
    "following_count": 3
}
```

`followers_count` = wie viele Benutzer diesem Benutzer folgen.  
`following_count` = wie vielen Benutzern dieser Benutzer folgt.

Unbekannter Benutzer → 404.

## Follower-/Following-Listen

```php
GET /users/1/followers
→ 200
{
    "items": [
        {"id": 3, "name": "Carol", "created_at": "..."},
        {"id": 2, "name": "Bob",   "created_at": "..."}
    ],
    "count": 2
}
```

- Geordnet nach `follows.id DESC` (neuester Follower zuerst).
- Gleiche Struktur für `GET /users/{id}/following`.
- Unbekannter Benutzer → 404.

## Is-Following-Prüfung

```php
GET /users/1/is-following/2
→ 200  {"following": true}   // 1 folgt 2

GET /users/1/is-following/2  // nach Entfolgen
→ 200  {"following": false}
```

Gibt `false` (nicht 404) zurück, wenn nicht folgend — die Prüfung selbst ist immer gültig.

## Gegenseitiges Follow

```php
POST /users/1/follow  {"followee_id": 2}
POST /users/2/follow  {"followee_id": 1}

GET /users/1/is-following/2  → {"following": true}
GET /users/2/is-following/1  → {"following": true}
```

Gegenseitige Follows sind nur zwei separate Follow-Zeilen — keine spezielle Tabelle oder Logik nötig.

---

## Was man NICHT tun sollte

| Anti-Muster | Risiko |
|---|---|
| 409 für doppeltes Follow zurückgeben | Client-Retry-Logik bricht ab; idempotente Operationen sollten 200 zurückgeben, keinen Fehler |
| Selbst-Follow erlauben | Korrumpiert Stats (`followers_count` durch sich selbst aufgebläht); Feeds sehen falsch aus |
| Keine UNIQUE-Constraint auf (follower_id, followee_id) | Race Condition bei gleichzeitigen Follow-Klicks erzeugt doppelte Zeilen |
| DELETE von nicht-existierendem Follow gibt 204 zurück | Client kann nicht zwischen "entfolgt" und "nie gefolgt" unterscheiden; 404 verwenden |
| Nach Name oder ID statt Aktualität ordnen | Neueste Follower/Following in langer Liste verloren; UX-Erwartung ist "wer hat mir kürzlich gefolgt" |
| Gemeinsame Follow-Zählungen über Benutzer | Follower-Zählungen bluten zwischen unverbundenen Benutzern; immer nach user_id scopen |
