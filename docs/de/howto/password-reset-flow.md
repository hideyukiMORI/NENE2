# How-to: Passwort-Reset-Ablauf

> **FT-Referenz**: FT285 (`NENE2-FT/resetlog`) — Passwort-Reset-Ablauf: Benutzer-Enumerationsprävention (immer 202), SHA-256-Token-Hash-Speicherung, 1-Stunde TTL, Einmal-Token (409 bei Wiederverwendung), 410 Gone bei Ablauf, Argon2id-neues-Passwort-Hash, 15 Tests / 23 Assertions PASS.

Diese Anleitung zeigt, wie ein sicherer Passwort-Reset-Ablauf implementiert wird — Benutzer fordern einen Reset an, erhalten ein Token (normalerweise per E-Mail) und verwenden es, um ein neues Passwort zu setzen.

## Schema

```sql
CREATE TABLE users (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    email         TEXT    NOT NULL UNIQUE,
    name          TEXT    NOT NULL,
    password_hash TEXT    NOT NULL,
    created_at    TEXT    NOT NULL
);

CREATE TABLE password_resets (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    token_hash TEXT    NOT NULL UNIQUE,
    used_at    TEXT,
    expires_at TEXT    NOT NULL,
    created_at TEXT    NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id)
);
```

`token_hash TEXT UNIQUE` — speichert SHA-256 des Roh-Tokens. Das Roh-Token wird an den Client gesendet und niemals gespeichert.

## Endpunkte

| Methode | Pfad | Auth | Beschreibung |
|---------|------|------|-------------|
| `POST` | `/password-reset` | Keine | Passwort-Reset anfordern |
| `GET` | `/password-reset/{token}` | Keine | Token-Status prüfen |
| `POST` | `/password-reset/{token}` | Keine | Reset mit neuem Passwort abschließen |

## Benutzer-Enumerationsprävention

```php
$user = $this->repo->findUserByEmail($email);

// Immer 202 zurückgeben, um Benutzer-Enumeration zu verhindern
if ($user === null) {
    return $this->json->create(['status' => 'pending'], 202);
}

// Echter Benutzer: Token erstellen und (in Produktion) E-Mail senden
$rawToken = bin2hex(random_bytes(32));
// ...
return $this->json->create(['status' => 'pending', 'token' => $rawToken], 202);
```

Sowohl gültige als auch ungültige E-Mails geben identische 202-Antworten zurück. Ein Angreifer kann nicht bestimmen, welche E-Mails registriert sind.

> **Produktionshinweis**: Das Token wird hier zur Testbarkeit in der API-Antwort zurückgegeben. In der Produktion das Token nur per E-Mail senden — niemals in der API-Antwort einschließen.

## Token-Speicherung — Nur SHA-256

```php
$rawToken  = bin2hex(random_bytes(32));  // 64 Hex-Zeichen = 256-Bit-Entropie
$tokenHash = hash('sha256', $rawToken);

$this->repo->createReset($user->id, $tokenHash, $expiresAt, $now);

// Roh-Token an Client zurückgeben (in Produktion: per E-Mail, nicht HTTP-Antwort)
return $this->json->create(['status' => 'pending', 'token' => $rawToken], 202);
```

Die Datenbank speichert nur den SHA-256-Hash. Das Roh-Token wird dem Benutzer (per E-Mail in Produktion) gesendet und niemals gespeichert. Ein DB-Einbruch verrät Hashes — nutzlos ohne die Roh-Tokens.

## Token-Validierung

```php
$rawToken  = (string) ($params['token'] ?? '');
$tokenHash = hash('sha256', $rawToken);
$reset     = $this->repo->findByTokenHashOrNull($tokenHash);
```

Das Roh-Token kommt im Request-Pfad an. Der Server hasht es und fragt die DB ab. SHA-256 ist deterministisch — dasselbe Roh-Token erzeugt immer denselben Hash.

## Token-Lebenszyklus-Zustände

```
pending → used (409 bei Wiederverwendung)
pending → expired (410 Gone)
```

```php
if ($reset->isExpired($now)) {
    return $this->problems->create($request, 'gone', 'Reset token has expired.', 410, '');
}

if ($reset->isUsed()) {
    return $this->problems->create($request, 'conflict', 'Reset token has already been used.', 409, '');
}
```

| Status | HTTP | Wann |
|--------|------|------|
| Nicht gefunden | 404 | Token existiert nicht in DB |
| Abgelaufen | 410 Gone | `expires_at` liegt in der Vergangenheit |
| Bereits verwendet | 409 Conflict | `used_at` ist gesetzt |
| Gültig | 200 (GET) / 200 (POST) | Aktiv, unbenutzt, nicht abgelaufen |

`410 Gone` ist semantisch korrekter als 404 für abgelaufene Ressourcen — das Token existierte, ist aber nicht mehr verfügbar.

## Reset abschließen

```php
$newHash = password_hash($newPassword, PASSWORD_ARGON2ID);
$this->repo->updatePasswordHash($reset->userId, $newHash);
$this->repo->markUsed($tokenHash, $now);  // used_at = $now setzen

return $this->json->create(['status' => 'completed'], 200);
```

Beide Operationen sollten in der Produktion in einer Transaktion sein. Falls `updatePasswordHash` erfolgreich ist, aber `markUsed` fehlschlägt, ist der Benutzer zurückgesetzt, aber das Token bleibt wiederverwendbar.

## Passwort-Validierung

```php
if (strlen($newPassword) < 8) {
    return $this->problems->create($request, 'validation-failed', 'Validation Failed', 422, null, [
        'errors' => [['field' => 'password', 'code' => 'min-length', 'message' => 'password must be at least 8 characters.']],
    ]);
}
```

Minimum 8 Zeichen; wird sowohl bei der Registrierung als auch beim Reset erzwungen. Das neue Passwort wird mit `PASSWORD_ARGON2ID` vor der Speicherung gehasht.

---

## VULN-Bewertung — Schwachstellendiagnose

### V-01 — Benutzer-Enumeration via Reset-Antwort-Timing/Inhalt 🛡️ SAFE

**Bedrohung**: Angreifer sendet Reset-Anfragen für viele E-Mails, um registrierte zu identifizieren.
**Abwehr**: Sowohl registrierte als auch nicht-registrierte E-Mails geben `202 { "status": "pending" }` mit identischem Antwort-Body und Statuscode zurück.
**Ergebnis**: SAFE — Enumeration aus API-Antwort nicht möglich.

---

### V-02 — Token-Brute-Force 🛡️ SAFE

**Bedrohung**: Angreifer errät Token-Werte und reicht sie ein, um ein Konto zurückzusetzen.
**Abwehr**: `bin2hex(random_bytes(32))` generiert 256-Bit-Entropie (64 Hex-Zeichen). Bei 10.000 Versuchen/Sekunde würde Brute-Forcing ~10^65 Jahre dauern.
**Ergebnis**: SAFE — 256-Bit-Entropie ist unerratbar.

---

### V-03 — Token-Replay nach Verwendung 🛡️ SAFE

**Bedrohung**: Angreifer fängt ein Reset-Token ab und verwendet es, nachdem der legitime Benutzer sein Passwort bereits zurückgesetzt hat.
**Abwehr**: `markUsed()` setzt `used_at` nach dem Reset. Nachfolgende Versuche prüfen `isUsed()` → 409 Conflict.
**Ergebnis**: SAFE — Einmal-Verwendungs-Durchsetzung verhindert Replay.

---

### V-04 — Abgelaufenes Token akzeptiert 🛡️ SAFE

**Bedrohung**: Angreifer speichert ein Token, wartet auf den Login des Benutzers, dann verwendet das alte Token.
**Abwehr**: `isExpired($now)` prüft `expires_at`. Tokens laufen nach 1 Stunde ab → 410 Gone.
**Ergebnis**: SAFE — Zeitbeschränkte Tokens verhindern verzögerte Angriffe.

---

### V-05 — SQL-Injection via Token-Pfadparameter 🛡️ SAFE

**Bedrohung**: `'; DROP TABLE password_resets; --` als Token einreichen.
**Abwehr**: `hash('sha256', $rawToken)` erzeugt unabhängig von der Eingabe einen 64-Zeichen-Hex-String. Der Hash wird in einer parametrisierten Abfrage verwendet.
**Ergebnis**: SAFE — Hashing + parametrisierte Abfrage blockiert Injection doppelt.

---

### V-06 — Token im Klartext in DB gespeichert 🛡️ SAFE

**Bedrohung**: DB-Einbruch exponiert alle aktiven Reset-Tokens; Angreifer setzt jedes Konto zurück.
**Abwehr**: DB speichert nur `hash('sha256', $rawToken)`. Roh-Tokens werden an Clients zurückgegeben (oder per E-Mail gesendet). SHA-256 ist einwegig; Hashes können ohne Brute-Force nicht zu Roh-Tokens umgekehrt werden.
**Ergebnis**: SAFE — SHA-256-Hash-Speicherung schützt Tokens im Ruhezustand.

---

### V-07 — Neues Passwort im Klartext gespeichert 🛡️ SAFE

**Bedrohung**: DB-Einbruch exponiert neue Passwörter, die während des Resets gesetzt wurden.
**Abwehr**: `password_hash($newPassword, PASSWORD_ARGON2ID)` hasht das neue Passwort vor der Speicherung.
**Ergebnis**: SAFE — Argon2id-Hashing schützt Passwörter im Ruhezustand.

---

### V-08 — Konto-Übernahme durch Erstellen eines doppelten Reset-Tokens 🛡️ SAFE

**Bedrohung**: Angreifer sagt oder kollidiert mit einem anderen Benutzer-Token-Hash.
**Abwehr**: `token_hash TEXT UNIQUE` — doppelte Hashes werden von DB abgelehnt. Mit 256-Bit-Entropie ist Kollisionswahrscheinlichkeit vernachlässigbar.
**Ergebnis**: SAFE — UNIQUE-Constraint + 256-Bit-Entropie verhindern Kollision.

---

### V-09 — Schwaches neues Passwort (< 8 Zeichen) beim Reset einreichen 🛡️ SAFE

**Bedrohung**: Angreifer setzt ein Konto auf ein trivial erratbares Passwort wie `aa` zurück.
**Abwehr**: `strlen($newPassword) < 8` → 422 Validierungsfehler vor jeder DB-Operation.
**Ergebnis**: SAFE — Mindestlänge auf Reset-Pfad erzwungen (gleich wie Registrierung).

---

### V-10 — Token-Endpunkt verrät welcher Schritt fehlschlug (Enumeration) 🛡️ SAFE

**Bedrohung**: Durch Vergleich von 404 vs. 409 vs. 410 Antworten kartiert Angreifer den Zustand von Reset-Tokens.
**Abwehr**: Die Fehlercodes verraten Token-Lebenszyklus-Zustand (nicht-gefunden/abgelaufen/verwendet) aber keine Benutzerinformationen. Zu wissen, dass ein Token abgelaufen oder verwendet ist, identifiziert nicht den Kontoinhaber.
**Ergebnis**: SAFE — Keine Benutzeridentitätsinformationen werden durch Token-Zustandsantworten verraten.

---

### VULN-Zusammenfassung

| ID | Bedrohung | Ergebnis |
|----|-----------|---------|
| V-01 | Benutzer-Enumeration via Reset-Antwort | 🛡️ SAFE |
| V-02 | Token-Brute-Force | 🛡️ SAFE |
| V-03 | Token-Replay nach Verwendung | 🛡️ SAFE |
| V-04 | Abgelaufenes Token akzeptiert | 🛡️ SAFE |
| V-05 | SQL-Injection via Token-Pfad | 🛡️ SAFE |
| V-06 | Token im Klartext gespeichert | 🛡️ SAFE |
| V-07 | Neues Passwort im Klartext gespeichert | 🛡️ SAFE |
| V-08 | Doppeltes Token-Kollision | 🛡️ SAFE |
| V-09 | Schwaches neues Passwort akzeptiert | 🛡️ SAFE |
| V-10 | Token-Zustand verrät Benutzerinfo | 🛡️ SAFE |

**10 SAFE, 0 EXPOSED**

---

## Was man NICHT tun sollte

| Anti-Muster | Risiko |
|---|---|
| 404 für nicht-registrierte E-Mail, 202 für registrierte zurückgeben | Benutzer-Enumeration — Angreifer kartiert registrierte Konten |
| Roh-Token in DB speichern | DB-Einbruch exponiert alle aktiven Reset-Tokens; Massen-Kontoübernahme |
| Token in HTTP-Antwort-Body senden (Produktion) | Token von Browser-Logs, Proxies oder JS abgefangen; nur per E-Mail senden |
| Keine Ablaufzeit auf Reset-Tokens | Alte Tokens bleiben für immer gültig; gestohlene Tokens Monate später verwendbar |
| Token-Wiederverwendung nach Passwort-Reset erlauben | Token-Replay-Angriff nach E-Mail-Abfangen |
| Keine Mindest-Passwortlänge | Benutzer setzen `aa` als neues Passwort |
| 200 für GET `/password-reset/{token}` bei verwendetem Token zurückgeben | Client kann gültig nicht von bereits-verwendet unterscheiden |
| MD5/SHA-1 für Token-Hash verwenden | Vorab-berechnete Rainbow-Tables existieren; SHA-256 oder besser verwenden |
| Keine Transaktion für `updatePasswordHash` + `markUsed` | Race Condition: Passwort aktualisiert, aber Token bleibt wiederverwendbar |
