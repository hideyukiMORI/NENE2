# TOTP-Zwei-Faktor-Authentifizierung Implementierungsanleitung

## Übersicht

Diese Anleitung erklärt, wie RFC 6238 TOTP (Time-based One-Time Password) Zwei-Faktor-Authentifizierung mit NENE2 implementiert wird. Es werden Google Authenticator/Authy-kompatible Geheimnis-Generierung, Code-Verifizierung, Replay-Angriff-Prävention und Brute-Force-Lockout bereitgestellt.

---

## DB-Schema

```sql
CREATE TABLE totp_secrets (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id         INTEGER NOT NULL UNIQUE,
    secret          TEXT    NOT NULL,
    is_enabled      INTEGER NOT NULL DEFAULT 0,
    failed_attempts INTEGER NOT NULL DEFAULT 0,
    locked_until    TEXT,
    created_at      TEXT    NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

CREATE TABLE used_totp_steps (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    time_step  INTEGER NOT NULL,
    used_at    TEXT    NOT NULL,
    UNIQUE (user_id, time_step),
    FOREIGN KEY (user_id) REFERENCES users(id)
);
```

Die `used_totp_steps`-Tabelle ist der Kern der **Replay-Angriff-Prävention**. Sie zeichnet verwendete Zeitschritte auf.

---

## Endpunkt-Design

| Methode | Pfad | Beschreibung |
|---------|------|--------------|
| POST | `/users/{id}/totp/setup` | TOTP-Geheimnis generieren (nach Rückgabe in App registrieren) |
| POST | `/users/{id}/totp/enable` | Code verifizieren und 2FA aktivieren |
| POST | `/users/{id}/totp/verify` | Code verifizieren (Login-Flow) |
| DELETE | `/users/{id}/totp` | 2FA deaktivieren (gültiger Code erforderlich) |
| GET | `/users/{id}/totp` | 2FA-Status abrufen |

---

## RFC 6238 TOTP-Implementierung

```php
class TotpGenerator
{
    private const int DIGITS = 6;
    private const int PERIOD = 30; // Sekunden

    public function computeCode(string $base32Secret, int $timeStep): string
    {
        $secret = $this->base32Decode($base32Secret);

        // Zeitschritt als 8-Byte big-endian verpacken
        $msg = pack('N*', 0) . pack('N*', $timeStep);
        $hash = hash_hmac('sha1', $msg, $secret, true);

        // Dynamisches Trunkieren (RFC 4226 §5.4)
        $offset = ord($hash[19]) & 0x0F;
        $code = ((ord($hash[$offset]) & 0x7F) << 24)
              | ((ord($hash[$offset + 1]) & 0xFF) << 16)
              | ((ord($hash[$offset + 2]) & 0xFF) << 8)
              | (ord($hash[$offset + 3]) & 0xFF);

        return str_pad((string) ($code % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    public function verify(string $base32Secret, string $code, int $window = 1): ?int
    {
        $t = (int) floor(time() / self::PERIOD);
        for ($offset = -$window; $offset <= $window; $offset++) {
            $step = $t + $offset;
            $expected = $this->computeCode($base32Secret, $step);
            if (hash_equals($expected, $code)) {   // Timing-Angriff-Prävention
                return $step;
            }
        }
        return null;
    }
}
```

---

## Designpunkte

### Replay-Angriff-Prävention

TOTP-Codes sind 30 Sekunden lang gültig. Wenn derselbe Code zweimal verwendet wird, ist Identitätsbetrug möglich.
Die `used_totp_steps`-Tabelle zeichnet verwendete time_steps auf und verweigert die Wiederverwendung.

```php
$matchedStep = $this->totp->verify($secret, $code);
if ($matchedStep === null) {
    // Code ungültig
    return 401;
}
if ($this->repo->isStepUsed($userId, $matchedStep)) {
    // Code für denselben time_step bereits verwendet → Replay-Angriff
    return 401;
}
// Als verwendet markieren
$this->repo->markStepUsed($userId, $matchedStep, $now);
```

### Timing-Angriff-Prävention

Für den Vergleich von TOTP-Codes `hash_equals()` verwenden. `===` oder `strcmp()` beenden den String-Vergleich vorzeitig, sodass aus der Antwortzeit auf die Anzahl übereinstimmender Stellen geschlossen werden kann.

```php
// Nicht korrekt: anfällig für Timing-Angriffe
if ($expected === $inputCode) { ... }

// Korrekt: Konstantzeit-Vergleich
if (hash_equals($expected, $inputCode)) { ... }
```

### Fenstergröße (Zeitversatz-Toleranz)

`window = 1` erlaubt aktuellen Schritt ± 1 (= ±30 Sekunden).
Zeitversätze bei Smartphones liegen fast immer in diesem Bereich.
Das Fenster zu vergrößern verringert die Sicherheit; 1 wird empfohlen.

### Brute-Force-Lockout

Nach 3 Fehlversuchen 15 Minuten sperren (423 Locked).
Während der Sperre wird auch ein korrekter Code abgelehnt (Timing-Oracle-Prävention):

```php
if ($this->repo->isLocked($userId, $now)) {
    return 423; // Gesperrt — korrekten Code nicht prüfen
}
```

### Setup-Flow

1. `POST /users/{id}/totp/setup` zum Geheimnis generieren
2. Das `secret` (Base32) oder `otpauth_uri` aus der Antwort in der Authenticator-App registrieren
3. `POST /users/{id}/totp/enable` mit dem ersten Code zum Aktivieren aufrufen
4. Vor der Aktivierung ist das Geheimnis in der DB gespeichert, aber `is_enabled = false`

```
otpauth://totp/NENE2:alice?secret=JBSWY3DPEHPK3PXP&issuer=NENE2&algorithm=SHA1&digits=6&period=30
```

### Re-Setup macht altes Geheimnis ungültig

Ein erneuter Aufruf von `POST /users/{id}/totp/setup` überschreibt das alte Geheimnis,
und `used_totp_steps` wird ebenfalls gelöscht. Codes des alten Geheimnisses können nicht mehr authentifiziert werden.

---

## Sicherheits-Checkliste (12 Schwachstellen-Diagnosen alle bestanden)

| # | Prüfpunkt | Gegenmaßnahme |
|---|-----------|---------------|
| A | Replay-Angriff | Verwendete time_steps in `used_totp_steps` aufzeichnen |
| B | Brute-Force | Nach 3 Fehlversuchen 15 Minuten sperren (423) |
| C | Korrekter Code während Sperre | Zuerst Sperrprüfung durchführen, Code überhaupt nicht verifizieren |
| D | Unbefugte 2FA-Deaktivierung | DELETE erfordert ebenfalls gültigen Code |
| E | Unbefugte 2FA-Aktivierung | Code-Verifizierung bei enable erforderlich |
| F | Missbrauch alten Geheimnisses | Bei Re-Setup altes Geheimnis und verwendete Schritte löschen |
| G | IDOR | Codes werden pro Benutzer mit unabhängigem secret verifiziert |
| H | Geheimnis-Exposure | secret nicht in verify/enable-Antworten einschließen |
| I | Fehlerhafter Code-Format | Nicht übereinstimmend → 401 (Format-Validierung optional) |
| J | Leerer Code | Required-Validierung gibt 422 zurück |
| K | verify ohne Aktivierung | `is_enabled`-Prüfung gibt 409 zurück |
| L | Nicht existierender Benutzer | findUser() → null → 404 |

---

## Hinweise zum Testen

Da TOTP-Codes zeitabhängig sind, wird derselbe Code nacheinander als Replay behandelt.
In Tests `TotpGenerator::computeCode($secret, $gen->currentTimeStep() + N)` verwenden, um Codes verschiedener Schritte zu generieren:

```php
$enableCode  = $gen->computeCode($secret, $gen->currentTimeStep());     // für enable verwenden
$verifyCode  = $gen->computeCode($secret, $gen->currentTimeStep() + 1); // für verify verwenden
$disableCode = $gen->computeCode($secret, $gen->currentTimeStep() + 2); // für disable verwenden
```

---

## Referenzimplementierung

`../NENE2-FT/totplog/` — FT159 Field Trial (21 Tests + 12 Schwachstellen-Diagnosen = 32 Tests)
