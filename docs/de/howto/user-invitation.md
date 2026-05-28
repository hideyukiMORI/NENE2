# Benutzer-Einladungssystem

Neue Benutzer per E-Mail einladen, Ablauf durchsetzen und Missbrauch mit tokenbasierten Einladungen verhindern.

## Überblick

Ein Einladungssystem lässt bestehende Benutzer neue Kontoerstellungen sponsern. Die wichtigsten Invarianten sind:

- Tokens sind kryptografisch zufällig und nicht erratbar.
- Ablauf wird sowohl beim Lesen als auch beim Schreiben geprüft.
- Nur der ursprüngliche Einlader kann eine Einladung stornieren.
- Akzeptierte und stornierte Tokens können nicht wiederverwendet werden.

## Datenbankschema

```sql
CREATE TABLE users (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    email      TEXT    NOT NULL UNIQUE,
    name       TEXT    NOT NULL,
    created_at TEXT    NOT NULL
);

CREATE TABLE invitations (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    inviter_id  INTEGER NOT NULL,
    email       TEXT    NOT NULL,
    token       TEXT    NOT NULL UNIQUE,
    status      TEXT    NOT NULL DEFAULT 'pending',
    expires_at  TEXT    NOT NULL,
    accepted_at TEXT,
    created_at  TEXT    NOT NULL,
    FOREIGN KEY (inviter_id) REFERENCES users(id)
);
```

## Token-Generierung

Immer `bin2hex(random_bytes(32))` verwenden — 64 Hex-Zeichen, 256 Bit Entropie:

```php
$token = bin2hex(random_bytes(32));
```

Niemals sequenzielle IDs, UUIDs oder kurze Strings als Einladungstoken verwenden. Ein erratbares Token lässt einen Angreifer jede ausstehende Einladung annehmen.

## Einladung senden

Vor der Einladungserstellung verifizieren, dass die Ziel-E-Mail noch nicht registriert ist:

```php
// Einladung bereits registrierter Benutzer verhindern
if ($this->repo->findUserByEmail($email) !== null) {
    return $this->problems->create($request, 'conflict', 'Email already registered.', 409, '');
}

$expiresAt = (new \DateTimeImmutable())->modify('+24 hours')->format('Y-m-d H:i:s');
$token     = bin2hex(random_bytes(32));
$invite    = $this->repo->createInvitation($inviterId, $email, $token, $expiresAt, $now);
```

Die Rückgabe von 409 beim Einladen einer registrierten E-Mail enthüllt den Registrierungsstatus gegenüber dem Einlader. Das ist in einladungsbasierten Systemen akzeptabel, wo Einlader vertrauenswürdige Benutzer sind. In vollständig öffentlichen Systemen sollte die Antwort auf 202 vereinheitlicht werden.

## Einladung annehmen

Ablauf **vor** der Statusprüfung prüfen — eine ausstehende-aber-abgelaufene Einladung muss 410 zurückgeben, nicht 409:

```php
$invite = $this->repo->findByTokenOrNull($token);

if ($invite === null) {
    return $this->problems->create($request, 'not-found', 'Invitation not found.', 404, '');
}

$now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

if ($invite->isExpired($now)) {
    return $this->problems->create($request, 'gone', 'Invitation has expired.', 410, '');
}

if (!$invite->isPending()) {
    return $this->problems->create($request, 'conflict', 'Invitation is no longer valid.', 409, '');
}
```

`isExpired` vergleicht den aktuellen Timestamp-String direkt — SQLite-Datetime-Strings sortieren lexikografisch, wenn sie als `Y-m-d H:i:s` gespeichert sind:

```php
public function isExpired(string $now): bool
{
    return $now >= $this->expiresAt;
}

public function isPending(): bool
{
    return $this->status === 'pending';
}
```

## Einladung stornieren

Die Eigentümerschaft wird über die `inviter_id` aus dem Request-Body durchgesetzt (da in diesem minimalen Beispiel kein Session/JWT-Middleware vorhanden ist). In der Produktion den Akteur stattdessen aus einem authentifizierten Token ableiten:

```php
if ($invite->inviterId !== $inviterId) {
    return $this->problems->create($request, 'forbidden', 'Only the inviter may cancel this invitation.', 403, '');
}

if (!$invite->isPending()) {
    return $this->problems->create($request, 'conflict', 'Invitation is already ' . $invite->status . '.', 409, '');
}
```

403 (nicht 404) zurückgeben, wenn die Eigentümerschaftsprüfung fehlschlägt — die Existenz der Einladung zu verschleiern würde die Tatsache verbergen, dass der Angreifer ein echtes Token gefunden hat, aber 403 ist hier die korrekte Semantik, da die Ressource gefunden wurde, die Aktion aber verboten ist.

## Zustandsmaschine

```
pending ──accept──► accepted
pending ──cancel──► cancelled
```

Sobald eine Einladung `pending` verlässt, sind keine weiteren Übergänge erlaubt. Der Versuch, eine `accepted`- oder `cancelled`-Einladung anzunehmen, gibt 409 zurück.

## Sicherheitseigenschaften

| Eigenschaft | Implementierung |
|-------------|-----------------|
| Token-Entropie | `bin2hex(random_bytes(32))` — 256 Bit |
| Token-Eindeutigkeit | UNIQUE-Constraint auf `invitations.token` |
| Ablauf beim Lesen | Im Handler vor jedem Schreibvorgang geprüft |
| Wiederverwendungsschutz | `isPending()`-Guard vor Accept/Cancel |
| Eigentümerdurchsetzung | `inviter_id`-Gleichheitsprüfung → 403 |
| Kein E-Mail-PII-Leak | 409-Body enthüllt die eingeladene E-Mail nicht |
| SQL-Injection | PDO-parametrisierte Abfragen durchgehend |

## Routenübersicht

| Methode | Pfad | Beschreibung |
|---------|------|--------------|
| `POST` | `/users` | Benutzerkonto erstellen |
| `POST` | `/users/{id}/invitations` | Einladung senden |
| `GET` | `/invitations/{token}` | Einladung anzeigen |
| `POST` | `/invitations/{token}/accept` | Einladung annehmen |
| `DELETE` | `/invitations/{token}` | Einladung stornieren |
