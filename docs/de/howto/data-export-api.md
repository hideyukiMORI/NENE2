# How-to: Datenexport-API

> **FT-Referenz**: FT312 (`NENE2-FT/exportlog`) — Datenexport (DSGVO-Stil): asynchrone `pending→ready`-Zustandsmaschine via token-basiertem Download, PII-Ausschluss via `toPublicArray()` (password_hash und phone niemals in GET-Antwort oder Export-Payload), ARGON2ID-Passwort-Hashing, 64-Hex-Zeichen-Export-Token, 410 Gone für abgelaufene Exporte, 409 für Versuch des Downloads bei pending, 19 Tests / 32 Assertions PASS.

Diese Anleitung zeigt, wie ein Benutzerdaten-Exportsystem (DSGVO Art. 20 Portabilität) aufgebaut wird, bei dem Exporte asynchron, durch Tokens geschützt sind und PII-sensible Felder niemals geleakt werden.

## Schema

```sql
CREATE TABLE users (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    email         TEXT    NOT NULL UNIQUE,
    name          TEXT    NOT NULL,
    phone         TEXT    NOT NULL DEFAULT '',
    password_hash TEXT    NOT NULL,
    created_at    TEXT    NOT NULL
);

CREATE TABLE data_exports (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    token      TEXT    NOT NULL UNIQUE,  -- 64 Hex-Zeichen
    status     TEXT    NOT NULL DEFAULT 'pending',
    payload    TEXT,                     -- JSON, gesetzt wenn status='ready'
    expires_at TEXT    NOT NULL,
    created_at TEXT    NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id)
);
```

`token` ist ein 64-stelliger Hex-String für die Download-URL. `payload` ist null, bis der Export verarbeitet wird.

## Endpunkte

| Methode | Pfad | Beschreibung |
|---------|------|--------------|
| `POST` | `/users` | Benutzer registrieren |
| `GET` | `/users/{id}` | Benutzer abrufen (PII ausgeschlossen) |
| `POST` | `/users/{id}/export` | Datenexport anfragen → 202 |
| `POST` | `/exports/{token}/process` | Export verarbeiten (async Worker) |
| `GET` | `/exports/{token}` | Abgeschlossenen Export herunterladen |

## PII-Ausschluss — toPublicArray()

```php
final class User
{
    public function toPublicArray(): array
    {
        return [
            'id'         => $this->id,
            'email'      => $this->email,
            'name'       => $this->name,
            'created_at' => $this->createdAt,
            // phone und password_hash absichtlich von der öffentlichen Ansicht ausgeschlossen
        ];
    }
}
```

Die `GET /users/{id}`-Antwort ruft `toPublicArray()` auf — niemals das vollständige Array. `phone` und `password_hash` werden gespeichert, aber niemals über die API zurückgegeben.

Dieselbe Ausschlussregel gilt für den Export-Payload: Der Export wird aus `toPublicArray()` (oder Äquivalent) erstellt, nicht aus einer rohen DB-Zeile.

## Passwort-Hashing — ARGON2ID

```php
$passwordHash = password_hash($password, PASSWORD_ARGON2ID);
```

ARGON2ID ist der empfohlene moderne Algorithmus (memory-hard, resistent gegen GPU-Angriffe). `PASSWORD_BCRYPT` ist akzeptabel, aber schwächer gegen GPU-Knacken.

## Asynchroner Export — pending → ready

```
POST /users/{id}/export  →  202 Accepted
  → erstellt data_exports-Zeile: status='pending', token='<64hex>'

POST /exports/{token}/process  →  200 OK
  → baut Payload, setzt status='ready'

GET /exports/{token}  →  200 OK (Download)
  → gibt Payload zurück wenn status='ready'
```

**Export-Token-Generierung:**
```php
$token = bin2hex(random_bytes(32)); // 64 Hex-Zeichen
```

**Process-Handler:**
```php
if ($export->status === 'ready') {
    return 200; // Bereits verarbeitet, idempotent
}
if ($export->expiresAt < date('c')) {
    return 410; // Abgelaufen — nicht verarbeiten
}
// Payload erstellen und speichern
$this->repo->markReady($export->token, json_encode($export->user->toPublicArray()));
```

## Statusprüfungen — 409 und 410

```php
// Download-Handler
if ($export->expiresAt < date('c')) {
    return $this->problems->create($request, 'gone', 'Export has expired.', 410, '');
}

if ($export->status !== 'ready') {
    return $this->problems->create($request, 'conflict', 'Export is not yet ready.', 409, '');
}
```

| Status | Download-Antwort |
|--------|-----------------|
| `pending` | 409 Conflict |
| `ready` (nicht abgelaufen) | 200 OK mit Payload |
| `ready` (abgelaufen) | 410 Gone |

410 Gone wird für abgelaufene Ressourcen verwendet (DSGVO: Exportdaten sollten nicht unbegrenzt persistiert werden).

---

## Was Sie NICHT tun sollten

| Anti-Pattern | Risiko |
|---|---|
| `password_hash` in GET-Antwort einschließen | Passwort-Hash exponiert; ermöglicht Offline-Knacken |
| `phone` ohne Auth in GET-Antwort einschließen | PII-Leck; Telefonnummern für jeden mit Benutzer-ID sichtbar |
| `password_hash` in Export-Payload einschließen | DSGVO-Verstoß; Export ist ein benutzerseitiges Datenportabilitätsdokument |
| `PASSWORD_MD5` oder `PASSWORD_DEFAULT` verwenden | Schwaches Passwort-Hashing; auf ARGON2ID upgraden |
| 404 für abgelaufene Exporte zurückgeben (nicht 410) | 404 verbirgt den Unterschied zwischen "nie existiert" und "abgelaufen" |
| 200 für ausstehenden Download zurückgeben | Client denkt, Export ist bereit; erhält leeren oder fehlerhaften Payload |
| Kurzes Export-Token (< 64 Zeichen) | Erratbares Token; jeder kann den Export eines beliebigen Benutzers herunterladen |
| Kein `expires_at` bei Exporten | Exporte persistieren unbegrenzt; DSGVO-Compliance-Problem |
