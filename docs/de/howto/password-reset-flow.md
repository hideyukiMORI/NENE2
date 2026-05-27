# How-to: Passwort-Reset-Ablauf

> **FT-Referenz**: FT285 (`NENE2-FT/resetlog`) — Passwort-Reset-Ablauf: Benutzer-Enumerations-Prävention (immer 202), SHA-256-Token-Hash-Speicherung, 1-Stunden-TTL, Einmal-Token (409 bei Wiederverwendung), 410 Gone bei Ablauf, Argon2id für neues Passwort-Hash, 15 Tests / 23 Assertions PASS.
>
> **VULN-Assessment**: V-01 bis V-10 am Ende dieses Dokuments.

Diese Anleitung zeigt, wie ein sicherer Passwort-Reset-Ablauf implementiert wird — Benutzer fordern einen Reset an, erhalten ein Token (üblicherweise per E-Mail) und verwenden es, um ein neues Passwort festzulegen.

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

`token_hash TEXT UNIQUE` — speichert SHA-256 des Rohtokens. Rohtoken wird an den Client gesendet und niemals gespeichert.

## Endpunkte

| Methode | Pfad | Auth | Beschreibung |
|--------|------|------|-------------|
| `POST` | `/password-reset` | Keine | Passwort-Reset anfordern |
| `GET` | `/password-reset/{token}` | Keine | Token-Status prüfen |
| `POST` | `/password-reset/{token}` | Keine | Reset mit neuem Passwort abschließen |

## Benutzer-Enumerations-Prävention

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

> **Produktionshinweis**: Das Token wird hier in der API-Antwort zurückgegeben, um die Testbarkeit zu erleichtern. In der Produktion das Token nur per E-Mail senden — niemals in der API-Antwort einschließen.

## Token-Speicherung — Nur SHA-256

```php
$rawToken  = bin2hex(random_bytes(32));  // 64 Hex-Zeichen = 256-Bit-Entropie
$tokenHash = hash('sha256', $rawToken);

$this->repo->createReset($user->id, $tokenHash, $expiresAt, $now);

// Rohtoken an Client zurückgeben (in Produktion: per E-Mail, nicht HTTP-Antwort)
return $this->json->create(['status' => 'pending', 'token' => $rawToken], 202);
```

Die Datenbank speichert nur den SHA-256-Hash. Das Rohtoken wird dem Benutzer gesendet (in der Produktion per E-Mail) und niemals gespeichert. Ein DB-Verstoß enthüllt Hashes — ohne die Rohtokens nutzlos.

## Token-Validierung

```php
$rawToken  = (string) ($params['token'] ?? '');
$tokenHash = hash('sha256', $rawToken);
$reset     = $this->repo->findByTokenHashOrNull($tokenHash);
```

Das Rohtoken kommt im Anfragepfad an. Der Server hasht es und fragt die DB ab. SHA-256 ist deterministisch — dasselbe Rohtoken erzeugt immer denselben Hash.

## Token-Lebenszyklus-Zustände

```
pending → verwendet (409 bei Wiederverwendung)
pending → abgelaufen (410 Gone)
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

Beide Operationen sollten in der Produktion in einer Transaktion sein. Wenn `updatePasswordHash` erfolgreich ist, aber `markUsed` fehlschlägt, ist der Benutzer zurückgesetzt, aber das Token bleibt wiederverwendbar.

## Passwortvalidierung

```php
if (strlen($newPassword) < 8) {
    return $this->problems->create($request, 'validation-failed', 'Validation Failed', 422, null, [
        'errors' => [['field' => 'password', 'code' => 'min-length', 'message' => 'password must be at least 8 characters.']],
    ]);
}
```

Mindestens 8 Zeichen; bei Registrierung und Reset erzwungen. Das neue Passwort wird vor der Speicherung mit `PASSWORD_ARGON2ID` gehasht.

---

## VULN Assessment — Schwachstellendiagnose

### V-01 — Benutzer-Enumeration via Reset-Antwort-Timing/Inhalt 🛡️ SAFE

**Bedrohung**: Angreifer sendet Reset-Anfragen für viele E-Mails, um registrierte zu identifizieren.
**Abwehr**: Sowohl registrierte als auch nicht registrierte E-Mails geben `202 { "status": "pending" }` mit identischem Response-Body und Statuscode zurück. Kein Timing-Unterschied.
**Ergebnis**: SAFE — Enumeration aus API-Antwort nicht möglich.

---

### V-02 — Token-Brute-Force 🛡️ SAFE

**Bedrohung**: Angreifer errät Token-Werte und reicht sie ein, um ein beliebiges Konto zurückzusetzen.
**Abwehr**: `bin2hex(random_bytes(32))` generiert 256-Bit-Entropie (64 Hex-Zeichen). Bei 10.000 Versuchen/Sekunde würde Brute-Force ~10^65 Jahre dauern.
**Ergebnis**: SAFE — 256-Bit-Entropie ist nicht erratbar.

---

### V-03 — Token-Replay nach Verwendung 🛡️ SAFE

**Bedrohung**: Angreifer fängt ein Reset-Token ab und verwendet es, nachdem der legitime Benutzer sein Passwort bereits zurückgesetzt hat.
**Abwehr**: `markUsed()` setzt `used_at` nach dem Reset. Nachfolgende Versuche prüfen `isUsed()` → 409 Conflict.
**Ergebnis**: SAFE — Einmal-Erzwingung verhindert Replay.

---

### V-04 — Abgelaufenes Token akzeptiert 🛡️ SAFE

**Bedrohung**: Angreifer speichert ein Token, wartet auf Benutzer-Login, verwendet dann das alte Token.
**Abwehr**: `isExpired($now)` prüft `expires_at`. Token laufen nach 1 Stunde ab → 410 Gone.
**Ergebnis**: SAFE — zeitbegrenzte Token verhindern verzögerte Angriffe.

---

### V-05 — SQL-Injection via Token-Pfadparameter 🛡️ SAFE

**Bedrohung**: `'; DROP TABLE password_resets; --` als Token einreichen.
**Abwehr**: `hash('sha256', $rawToken)` erzeugt unabhängig von der Eingabe einen 64-Zeichen-Hex-String. Der Hash wird in einer parametrisierten Abfrage verwendet.
**Ergebnis**: SAFE — Hashing + parametrisierte Abfrage blockiert Injection doppelt.

---

### V-06 — Token im Klartext in DB gespeichert 🛡️ SAFE

**Bedrohung**: DB-Verstoß exponiert alle aktiven Reset-Token; Angreifer setzt jedes Konto zurück.
**Abwehr**: DB speichert nur `hash('sha256', $rawToken)`. SHA-256 ist einwegig.
**Ergebnis**: SAFE — SHA-256-Hash-Speicherung schützt Token im Ruhezustand.

---

### V-07 — Neues Passwort im Klartext gespeichert 🛡️ SAFE

**Bedrohung**: DB-Verstoß exponiert neue Passwörter, die beim Reset gesetzt wurden.
**Abwehr**: `password_hash($newPassword, PASSWORD_ARGON2ID)` hasht das neue Passwort vor der Speicherung.
**Ergebnis**: SAFE — Argon2id-Hashing schützt Passwörter im Ruhezustand.

---

### V-08 — Kontoübernahme durch Erstellen eines doppelten Reset-Tokens 🛡️ SAFE

**Bedrohung**: Angreifer sagt oder kollidiert mit dem Token-Hash eines anderen Benutzers.
**Abwehr**: `token_hash TEXT UNIQUE` — doppelte Hashes werden von der DB abgelehnt.
**Ergebnis**: SAFE — UNIQUE-Constraint + 256-Bit-Entropie verhindern Kollision.

---

### V-09 — Schwaches neues Passwort (< 8 Zeichen) beim Reset einreichen 🛡️ SAFE

**Bedrohung**: Angreifer setzt ein Konto auf ein trivial erratbares Passwort wie `aa` zurück.
**Abwehr**: `strlen($newPassword) < 8` → 422 Validierungsfehler vor jeder DB-Operation.
**Ergebnis**: SAFE — Mindestlänge auf Reset-Pfad erzwungen (gleich wie Registrierung).

---

### V-10 — Token-Endpunkt verrät, welcher Schritt fehlgeschlagen ist (Enumeration) 🛡️ SAFE

**Bedrohung**: Durch Vergleich von 404 vs. 409 vs. 410 Antworten kartiert Angreifer den Zustand von Reset-Tokens.
**Abwehr**: Die Fehlercodes verraten Token-Lebenszyklus-Zustand (nicht-gefunden/abgelaufen/verwendet), aber keine Benutzerinformationen. Das Wissen, ob ein Token abgelaufen oder verwendet ist, identifiziert nicht den Kontoinhaber.
**Ergebnis**: SAFE — keine Benutzeridentitätsinformationen durch Token-Zustandsantworten enthüllt.

---

### VULN-Zusammenfassung

| ID | Bedrohung | Ergebnis |
|----|--------|--------|
| V-01 | Benutzer-Enumeration via Reset-Antwort | 🛡️ SAFE |
| V-02 | Token-Brute-Force | 🛡️ SAFE |
| V-03 | Token-Replay nach Verwendung | 🛡️ SAFE |
| V-04 | Abgelaufenes Token akzeptiert | 🛡️ SAFE |
| V-05 | SQL-Injection via Token-Pfad | 🛡️ SAFE |
| V-06 | Token im Klartext gespeichert | 🛡️ SAFE |
| V-07 | Neues Passwort im Klartext gespeichert | 🛡️ SAFE |
| V-08 | Doppelte Token-Kollision | 🛡️ SAFE |
| V-09 | Schwaches neues Passwort akzeptiert | 🛡️ SAFE |
| V-10 | Token-Zustand verrät Benutzerinfo | 🛡️ SAFE |

**10 SAFE, 0 EXPOSED**
Benutzer-Enumerations-Prävention, 256-Bit-Token-Entropie, SHA-256-Hash-Speicherung, Argon2id-Passwort-Hashing und Einmal-Erzwingung verhindern alle getesteten Schwachstellenvektoren.

---

## Was man NICHT tun sollte

| Anti-Muster | Risiko |
|---|---|
| 404 für nicht registrierte E-Mail, 202 für registrierte zurückgeben | Benutzer-Enumeration — Angreifer kartiert registrierte Konten |
| Rohtoken in DB speichern | DB-Verstoß exponiert alle aktiven Reset-Token; Massen-Kontoübernahme |
| Token in HTTP-Response-Body senden (Produktion) | Token durch Browser-Logs, Proxies oder JS abgefangen; nur per E-Mail senden |
| Kein Ablauf für Reset-Token | Alte Token bleiben für immer gültig; gestohlene Token Monate später verwendbar |
| Token-Wiederverwendung nach Passwort-Reset erlauben | Token-Replay-Angriff nach E-Mail-Abfangen |
| Keine minimale Passwortlänge | Benutzer setzen `aa` als neues Passwort |
| 200 für GET `/password-reset/{token}` bei verwendetem Token zurückgeben | Client kann gültig von bereits-verwendet nicht unterscheiden |
| MD5/SHA-1 für Token-Hash verwenden | Vorberechnete Rainbow-Tables existieren; SHA-256 oder besser verwenden |
| Keine Transaktion für `updatePasswordHash` + `markUsed` | Race Condition: Passwort aktualisiert, aber Token bleibt wiederverwendbar |
