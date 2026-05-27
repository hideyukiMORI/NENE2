# How-to: Passwort-Authentifizierung mit Argon2id

> **FT-Referenz**: FT331 (`NENE2-FT/pwdlog`) — Benutzerregistrierung und Login mit Argon2id-Passwort-Hashing, Passwort/Hash niemals in Antworten exponiert, Benutzer-Enumerations-Prävention (gleiche 401 für falsches Passwort und unbekannte E-Mail), algorithmische Migrations-Rehash, 14 Tests / 40 Assertions PASS.

Diese Anleitung zeigt, wie sichere passwortbasierte Authentifizierung aufgebaut wird: Passwörter sicher mit Argon2id speichern, Anmeldedaten niemals in Antworten preisgeben und verhindern, dass Angreifer registrierte E-Mail-Adressen aufzählen.

## Schema

```sql
CREATE TABLE users (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    email         TEXT    NOT NULL UNIQUE,
    password_hash TEXT    NOT NULL,
    created_at    TEXT    NOT NULL
);
```

`password_hash` speichert den vollständigen Argon2id-Ausgabe-String (z.B. `$argon2id$v=19$m=65536,...`). **Niemals Klartext oder MD5/SHA-1 speichern.**

## Endpunkte

| Methode | Pfad | Beschreibung |
|--------|------|-------------|
| `POST` | `/register` | Neuen Benutzer registrieren |
| `POST` | `/login` | Authentifizieren und Benutzerdaten zurückgeben |

## Registrierung

```php
POST /register
{"email": "alice@example.com", "password": "correct-horse"}

→ 201
{"id": 1, "email": "alice@example.com", "created_at": "2026-05-27T09:00:00Z"}
```

**`password` und `password_hash` werden NIEMALS** in der Antwort zurückgegeben — nicht einmal maskiert oder abgeschnitten.

### Validierung

```php
POST /register  {"email": "alice@example.com", "password": "short"}
→ 422  // Passwort zu kurz (mindestens 8 Zeichen)

POST /register  {"email": "not-an-email", "password": "correct-horse"}
→ 422  // ungültiges E-Mail-Format

POST /register  {"email": "alice@example.com"}
→ 400  // Passwortfeld fehlt

POST /register  {"email": "alice@example.com", "password": "battery-staple"}
// (nachdem Alice bereits registriert ist)
→ 409  {"type": ".../email-taken", "detail": "Email already registered"}
```

## Login

```php
POST /login
{"email": "alice@example.com", "password": "correct-horse"}

→ 200
{"id": 1, "email": "alice@example.com", "created_at": "..."}
// password_hash nicht zurückgegeben
```

### Benutzer-Enumerations-Prävention

```php
// Falsches Passwort für bekannte E-Mail
POST /login  {"email": "alice@example.com", "password": "wrong"}
→ 401  {"type": ".../invalid-credentials", "detail": "Invalid email or password"}

// Unbekannte E-Mail
POST /login  {"email": "ghost@example.com", "password": "any"}
→ 401  {"type": ".../invalid-credentials", "detail": "Invalid email or password"}
```

**Beide Fälle geben dieselbe 401 mit identischer `detail`-Meldung zurück.** Die Rückgabe von 404 für unbekannte E-Mail würde Angreifern ermöglichen, die Benutzerdatenbank zu sondieren.

```php
// Test: gleicher detail-String
$this->assertSame($wrongPasswordBody['detail'], $unknownEmailBody['detail']);
```

## Implementierung

### Passwortspeicherung — Argon2id

```php
// Registrierung
$hash = password_hash($plaintext, PASSWORD_ARGON2ID);
// Speichert: $argon2id$v=19$m=65536,t=4,p=1$...

// Niemals speichern:
// md5($plaintext)          — in Sekunden reversibel
// sha1($plaintext)         — Rainbow-Table-Angriff
// $plaintext               — Klartext-Speicherung
```

PHPs `password_hash(PASSWORD_ARGON2ID)` führt automatisch:
- Generiert ein zufälliges Salt pro Hash
- Speichert Algorithmus, Parameter, Salt und Digest in einem String
- Widersteht GPU-Brute-Force (speicherintensiv)

### Verifizierung — Konstante Zeit

```php
$row = $this->repo->findByEmail($email);

if ($row === null || !password_verify($plaintext, $row['password_hash'])) {
    // Gleiche Antwort, egal ob E-Mail unbekannt oder Passwort falsch
    return $this->problems->create('invalid-credentials', 'Invalid email or password', 401);
}
```

`password_verify()` ist zeitkonstant und funktioniert über Algorithmenfamilien hinweg (bcrypt, Argon2id usw.).

### Algorithmische Migrations-Rehash

Beim Upgrade von bcrypt auf Argon2id beim erfolgreichen Login erneut hashen:

```php
if (password_needs_rehash($row['password_hash'], PASSWORD_ARGON2ID)) {
    $newHash = password_hash($plaintext, PASSWORD_ARGON2ID);
    $this->repo->updateHash($row['id'], $newHash);
}
```

Benutzer werden bei ihrer nächsten Anmeldung still auf den stärkeren Algorithmus migriert — kein erzwungenes Passwort-Reset erforderlich.

### Niemals Anmeldedaten zurückgeben

```php
private function toPublic(array $user): array
{
    // Sensible Felder explizit entfernen
    unset($user['password_hash']);
    return $user;
}
```

`toPublic()` auf jede Antwort anwenden: register 201, login 200 und alle Profilendpunkte.

---

## Was man NICHT tun sollte

| Anti-Muster | Risiko |
|---|---|
| 404 für unbekannte E-Mail beim Login zurückgeben | Benutzer-Enumeration: Angreifer entdeckt, welche E-Mails registriert sind |
| Unterschiedliche `detail`-Meldung für falsches Passwort vs. unbekannte E-Mail zurückgeben | Verrät, welche Bedingung fehlgeschlagen ist |
| Passwort als MD5 oder SHA-1 speichern | Rainbow-Table-Angriff knackt alle Passwörter innerhalb von Stunden |
| Passwort als bcrypt speichern ohne Migrationspfad | Kann nicht auf stärkeren Algorithmus upgraden ohne erzwungenes Reset |
| `password_hash` in einer Antwort zurückgeben | Hash kann für Offline-Brute-Force verwendet werden |
| `password_needs_rehash()` beim Login überspringen | Alte schwache Hashes bleiben für immer bestehen, auch nach Algorithmus-Upgrade |
| `===` für Hash-Vergleich verwenden | Timing-Angriff verrät Hash-Bytes; immer `password_verify()` verwenden |
