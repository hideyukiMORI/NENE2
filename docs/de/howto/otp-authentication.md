# How-to: OTP-Authentifizierungssystem

> **FT-Referenz**: FT290 (`NENE2-FT/otplog`) — OTP-Authentifizierung: 6-stelliger numerischer Code mit SHA-256-Hash-Speicherung, Brute-Force-Sperrung (3 Versuche → 10 Min), OTP-TTL (5 Min), Replay-Angriffs-Prävention via `used_at`, Session-Token mit SHA-256 + Widerruf, Benutzer-Enumerationsprävention via always-202-Anfrage-Endpunkt, ATK-01~12 PASS, 35 Tests / 44 Assertions PASS.

Diese Anleitung zeigt, wie ein passwortloses OTP-Authentifizierungssystem aufgebaut wird, bei dem Benutzer einen 6-stelligen Code erhalten und ihn gegen ein Session-Token eintauschen.

## Schema

```sql
CREATE TABLE users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    email TEXT NOT NULL UNIQUE,
    created_at TEXT NOT NULL
);

CREATE TABLE otp_codes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    code_hash TEXT NOT NULL,
    expires_at TEXT NOT NULL,
    used_at TEXT,
    attempt_count INTEGER NOT NULL DEFAULT 0,
    locked_until TEXT,
    created_at TEXT NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

CREATE TABLE otp_sessions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    session_token_hash TEXT NOT NULL UNIQUE,
    expires_at TEXT NOT NULL,
    revoked_at TEXT,
    created_at TEXT NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id)
);
```

Wichtige Design-Punkte:
- `code_hash` speichert SHA-256 des OTP, niemals den Rohcode.
- `attempt_count` + `locked_until` implementieren Brute-Force-Sperrung pro OTP-Zeile.
- `used_at` verhindert Replay-Angriffe (OTP kann nur einmal verwendet werden).
- `session_token_hash` speichert SHA-256 des Session-Tokens; `UNIQUE` verhindert Kollisionen.
- `revoked_at` ermöglicht explizites Ausloggen ohne Löschen der Zeile.

## Endpunkte

| Methode | Pfad | Auth | Beschreibung |
|---------|------|------|-------------|
| `POST` | `/otp/request` | keine | OTP anfordern (erstellt Benutzer falls nötig) |
| `POST` | `/otp/verify` | keine | OTP verifizieren, Session-Token erhalten |
| `GET` | `/otp/session` | `Bearer <token>` | Session-Informationen abrufen |
| `DELETE` | `/otp/session` | `Bearer <token>` | Ausloggen (Session widerrufen) |

## OTP-Generierung — Rohen Code niemals speichern

```php
private const int MAX_ATTEMPTS = 3;
private const int OTP_TTL_MINUTES = 5;
private const int LOCK_MINUTES = 10;
private const int SESSION_TTL_HOURS = 24;

$rawCode = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
$codeHash = hash('sha256', $rawCode);
$this->repository->createOtp($userId, $codeHash, $now);
```

`str_pad` stellt führende Nullen sicher (z.B. `random_int(0, 999999)` gibt `42` zurück → `'000042'`). Der Rohcode wird an die E-Mail des Benutzers gesendet; nur der Hash wird gespeichert. `random_int()` ist kryptografisch sicher.

## Benutzer-Enumerationsprävention — Immer 202

```php
// Immer 202 — verhindert Benutzer-Enumeration
// In der Produktion: E-Mail senden. In diesem FT geben wir den Code zum Testen zurück.
return $this->responseFactory->create([
    'message' => 'OTP code sent',
    'code' => $rawCode,  // In der Produktion entfernen
], 202);
```

Ob die E-Mail existiert oder nicht, die Antwort ist immer `202 Accepted`. Ein Angreifer kann "Konto existiert" von "Konto existiert nicht" nicht unterscheiden.

## Benutzer bei erster Anfrage automatisch erstellen

```php
public function findOrCreateUser(string $email, string $now): int
{
    $user = $this->findUserByEmail($email);
    if ($user !== null) {
        return (int) $user['id'];
    }
    return $this->executor->insert(
        'INSERT INTO users (email, created_at) VALUES (?, ?)',
        [$email, $now]
    );
}
```

Benutzer werden implizit bei der ersten OTP-Anfrage erstellt — kein separater Registrierungsschritt erforderlich. Der `UNIQUE(email)`-Constraint verhindert Duplikate bei gleichzeitigen Inserts.

## OTP-Verifizierung — Geordnete Prüfungen

```php
// 1. Sperrungsprüfung (zuerst — vor jedem Code-Vergleich)
if ($otp['locked_until'] !== null && $now < (string) $otp['locked_until']) {
    return $this->responseFactory->create(['error' => 'too many attempts, try again later'], 429);
}

// 2. Ablaufprüfung
if ($now > (string) $otp['expires_at']) {
    return $this->responseFactory->create(['error' => 'code expired'], 401);
}

// 3. Bereits-verwendet-Prüfung
if ($otp['used_at'] !== null) {
    return $this->responseFactory->create(['error' => 'code already used'], 401);
}

// 4. Code-Prüfung mit hash_equals (timing-sicher)
$codeHash = hash('sha256', $code);
if (!hash_equals((string) $otp['code_hash'], $codeHash)) {
    $this->repository->incrementAttempt((int) $otp['id'], $now);
    return $this->responseFactory->create(['error' => 'invalid code'], 401);
}
```

Prüfreihenfolge ist wichtig: Sperrung → Ablauf → verwendet → Code. `attempt_count` nur bei falschem Code inkrementieren — nicht bei Sperrung oder Ablauf.

## Brute-Force-Sperrung

```php
public function incrementAttempt(int $otpId, string $now): void
{
    $otp = $this->executor->fetchOne('SELECT * FROM otp_codes WHERE id = ?', [$otpId]);
    if ($otp === null) {
        return;
    }
    $newCount = (int) $otp['attempt_count'] + 1;
    $lockedUntil = null;
    if ($newCount >= self::MAX_ATTEMPTS) {
        $lockedUntil = date('c', strtotime($now) + self::LOCK_MINUTES * 60);
    }
    $this->executor->execute(
        'UPDATE otp_codes SET attempt_count = ?, locked_until = ? WHERE id = ?',
        [$newCount, $lockedUntil, $otpId]
    );
}
```

Nach `MAX_ATTEMPTS` (3) falschen Codes wird `locked_until` 10 Minuten in die Zukunft gesetzt. Die Sperrungsprüfung geschieht vor jedem Code-Vergleich, sodass Versuche während der Sperrung den Timer nicht zurücksetzen.

## Nur neuestes OTP — Neue Anfrage macht altes ungültig

```php
public function findLatestOtpForUser(int $userId): ?array
{
    return $this->executor->fetchOne(
        'SELECT * FROM otp_codes WHERE user_id = ? ORDER BY id DESC LIMIT 1',
        [$userId]
    );
}
```

Mehrere OTP-Anfragen erstellen mehrere Zeilen, aber nur das neueste wird zur Verifizierung verwendet. Alte OTPs werden effektiv ungültig — ihr Einreichen gibt 401 zurück.

## Session-Token — SHA-256 + Widerruf

```php
// Session-Token ausstellen
$rawToken = bin2hex(random_bytes(32));   // 256-Bit-Entropie, 64 Hex-Zeichen
$tokenHash = hash('sha256', $rawToken);
$this->repository->createSession((int) $user['id'], $tokenHash, $now);

return $this->responseFactory->create([
    'session_token' => $rawToken,
    'user_id' => (int) $user['id'],
], 200);
```

Nur der SHA-256-Hash wird gespeichert. Falls die DB kompromittiert wird, werden Roh-Tokens niemals exponiert.

## Bearer-Token-Extraktion

```php
private function extractBearerToken(ServerRequestInterface $request): string
{
    $header = $request->getHeaderLine('Authorization');
    if (!str_starts_with($header, 'Bearer ')) {
        return '';
    }
    return trim(substr($header, 7));
}
```

Ein leerer String nach `Bearer ` (z.B. `Authorization: Bearer `) wird als fehlend behandelt — gibt 401 zurück.

## Logout — Stilles Erfolgsmeldung

```php
$session = $this->repository->findSessionByTokenHash($tokenHash);
if ($session !== null && $session['revoked_at'] === null) {
    $this->repository->revokeSession($tokenHash, date('c'));
}

return $this->responseFactory->create(['message' => 'logged out'], 200);
```

Logout gibt immer 200 zurück — es verrät nicht, ob das Token gültig war. Dies verhindert, dass Angreifer Token-Gültigkeit via Logout-Endpunkt sondieren.

---

## ATK-Bewertung — Cracker-Mindset-Angriffstest

### ATK-01 — OTP Brute-Force 🚫 BLOCKED

**Angriff**: Alle `000000`–`999999`-Kombinationen sequentiell ausprobieren.
**Ergebnis**: BLOCKED — nach `MAX_ATTEMPTS` (3) falschen Codes wird `locked_until` 10 Minuten in die Zukunft gesetzt.

---

### ATK-02 — Replay-Angriff (verwendetes OTP erneut benutzen) 🚫 BLOCKED

**Angriff**: Gültiges OTP erfassen und ein zweites Mal einreichen.
**Ergebnis**: BLOCKED — `used_at` wird bei erster erfolgreicher Verifizierung gesetzt. Zweiter Versuch findet `used_at !== null` → 401.

---

### ATK-03 — Benutzer-Enumeration via /otp/request 🚫 BLOCKED

**Angriff**: `/otp/request` mit bekannten und unbekannten E-Mails sondieren.
**Ergebnis**: BLOCKED — sowohl bestehende als auch nicht-existente E-Mails geben immer `202 Accepted` mit identischen Antwort-Bodies zurück.

---

### ATK-04 — Verifizieren für nicht-existenten Benutzer 🚫 BLOCKED

**Angriff**: `/otp/verify` mit einer E-Mail aufrufen, die kein Konto hat.
**Ergebnis**: BLOCKED — gibt 401 (`invalid code`), nicht 404 oder 500 zurück.

---

### ATK-05 — SQL-Injection im E-Mail-Feld 🚫 BLOCKED

**Angriff**: `'; DROP TABLE users; --` als E-Mail einreichen.
**Ergebnis**: BLOCKED — `filter_var($email, FILTER_VALIDATE_EMAIL)` lehnt Injektions-Strings als ungültiges E-Mail-Format ab, bevor irgendeine DB-Abfrage ausgeführt wird.

---

### ATK-06 — 5-stelliger Code (zu kurz) 🚫 BLOCKED

**Angriff**: Einen 5-Zeichen-Code einreichen.
**Ergebnis**: BLOCKED — `/^\d{6}$/` erfordert genau 6 Ziffern. Gibt 422 zurück.

---

### ATK-07 — 7-stelliger Code (zu lang) 🚫 BLOCKED

**Angriff**: Einen 7-stelligen Code einreichen.
**Ergebnis**: BLOCKED — dasselbe Regex lehnt Codes ab, die nicht genau 6 Ziffern sind. Gibt 422 zurück.

---

### ATK-08 — Session-Token-Wiederverwendung nach Logout 🚫 BLOCKED

**Angriff**: Token nach dem Ausloggen verwenden.
**Ergebnis**: BLOCKED — `revokeSession()` setzt `revoked_at`. GET-Handler prüft `$session['revoked_at'] !== null` → 401.

---

### ATK-09 — Zufälliges Token-Erraten 🚫 BLOCKED

**Angriff**: Einen zufälligen 64-Hex-String als Bearer-Token einreichen.
**Ergebnis**: BLOCKED — SHA-256-Hash des zufälligen Tokens entspricht keinem `session_token_hash`. Gibt 401 zurück. Token-Raum ist 2^256.

---

### ATK-10 — Leeres Bearer-Token 🚫 BLOCKED

**Angriff**: `Authorization: Bearer ` senden (leer nach Bearer-Präfix).
**Ergebnis**: BLOCKED — `trim(substr($header, 7))` gibt leeren String zurück → 401.

---

### ATK-11 — Alphabetischer Code (nicht-numerisch) 🚫 BLOCKED

**Angriff**: `abcdef` als OTP-Code einreichen.
**Ergebnis**: BLOCKED — `/^\d{6}$/` erfordert nur Dezimalziffern. Gibt 422 zurück.

---

### ATK-12 — Neue OTP-Anfrage macht alten Code ungültig 🚫 BLOCKED (by design)

**Angriff**: Gültiges OTP holen, Opfer lässt neues anfordern, dann ursprünglichen Code einreichen.
**Ergebnis**: BLOCKED — `findLatestOtpForUser()` ruft nur `ORDER BY id DESC LIMIT 1` ab.

---

### ATK-Zusammenfassung

| ID | Angriff | Ergebnis |
|----|---------|---------|
| ATK-01 | OTP Brute-Force | 🚫 BLOCKED |
| ATK-02 | Replay-Angriff (verwendetes OTP) | 🚫 BLOCKED |
| ATK-03 | Benutzer-Enumeration via /otp/request | 🚫 BLOCKED |
| ATK-04 | Nicht-existenten Benutzer verifizieren | 🚫 BLOCKED |
| ATK-05 | SQL-Injection in E-Mail | 🚫 BLOCKED |
| ATK-06 | 5-stelliger Code (zu kurz) | 🚫 BLOCKED |
| ATK-07 | 7-stelliger Code (zu lang) | 🚫 BLOCKED |
| ATK-08 | Session-Wiederverwendung nach Logout | 🚫 BLOCKED |
| ATK-09 | Zufälliges Token-Erraten | 🚫 BLOCKED |
| ATK-10 | Leeres Bearer-Token | 🚫 BLOCKED |
| ATK-11 | Alphabetischer Code | 🚫 BLOCKED |
| ATK-12 | Alter OTP durch neue Anfrage ungültig | 🚫 BLOCKED |

**12 BLOCKED, 0 EXPOSED**

---

## Was man NICHT tun sollte

| Anti-Muster | Risiko |
|---|---|
| Roh-OTP-Code in DB speichern | DB-Kompromittierung exponiert alle aktiven OTPs; immer SHA-256-Hash |
| Keine Brute-Force-Sperrung | 6-stelliges OTP hat 10^6 Kombinationen — ohne Sperrung in Sekunden brutforcebar |
| 404 für unbekannte E-Mail bei verify zurückgeben | Verrät, welche E-Mails Konten haben (Benutzer-Enumeration) |
| Unterschiedlichen Status für bekannte vs. unbekannte E-Mail bei /request | Dasselbe Enumerationsrisiko; immer 202 zurückgeben |
| Kein `used_at`-Flag | OTP kann bis zum Ablauf beliebig oft wiederholt werden |
| Alphabetische oder nicht-6-stellige Codes akzeptieren | Umgeht Format-Vertrag; `/^\d{6}$/`-Prüfung hinzufügen |
| Roh-Session-Token in DB speichern | DB-Verletzung exponiert alle Sessions; nur SHA-256-Hash speichern |
| Session-Zeile bei Logout löschen | Widerrufene Tokens können nicht erkannt werden; `revoked_at` für weiches Widerrufen verwenden |
| Logout-Erfolg/-Fehler basierend auf Token-Gültigkeit verraten | Angreifer sondieren Token-Gültigkeit via Logout; immer 200 zurückgeben |
| `findAllOtpsForUser()` verwenden und gültiges auswählen | Mehrere aktive OTPs verwirren Zustand; `ORDER BY id DESC LIMIT 1` verwenden |
| Kein E-Mail-Längenlimit | RFC 5321 Max ist 254 Zeichen; überdimensionierte Eingabe verursacht DB/E-Mail-Probleme |
