# How-to: Magic-Link-Authentifizierung

> **FT-Referenz**: FT309 (`NENE2-FT/magiclog`) — Magic-Link-Authentifizierung: Token als SHA-256-Hash gespeichert (niemals im Klartext), 15-Minuten-TTL, used_at verhindert Wiederverwendung, Ablauf vor used_at geprüft, Session-Token 64+ Hex-Zeichen SHA-256 gespeichert, widerrufene/abgelaufene Sessions abgelehnt, 202 immer bei /auth/request (Benutzer-Enumerations-Prävention), Bearer-Token erforderlich (X-User-Id-Header ignoriert), VULN-A〜L alle SAFE, 43 Tests / 91 Assertions PASS.

Diese Anleitung zeigt, wie ein passwortloses Magic-Link-Authentifizierungssystem aufgebaut wird, bei dem die Sicherheit auf Token-Entropie, Hash-Speicherung, kurzer TTL und Einmalnutzungs-Erzwingung basiert.

## Schema

```sql
CREATE TABLE users (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    email      TEXT    NOT NULL UNIQUE,
    created_at TEXT    NOT NULL
);

CREATE TABLE magic_links (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    token_hash TEXT    NOT NULL UNIQUE,   -- SHA-256(raw_token)
    expires_at TEXT    NOT NULL,          -- jetzt + 15 Minuten
    used_at    TEXT,                      -- gesetzt bei erster erfolgreicher Verifizierung
    created_at TEXT    NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

CREATE TABLE auth_sessions (
    id                 INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id            INTEGER NOT NULL,
    session_token_hash TEXT    NOT NULL UNIQUE,   -- SHA-256(raw_token)
    expires_at         TEXT    NOT NULL,
    revoked_at         TEXT,
    created_at         TEXT    NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id)
);
```

Sowohl `magic_links.token_hash` als auch `auth_sessions.session_token_hash` speichern SHA-256-Hashes. Rohe Token werden niemals gespeichert.

## Endpunkte

| Methode | Pfad | Auth | Beschreibung |
|--------|------|------|-------------|
| `POST` | `/auth/request` | — | Magic-Link anfordern (immer 202) |
| `POST` | `/auth/verify` | — | Token verifizieren → Session |
| `POST` | `/auth/logout` | `Bearer` | Session widerrufen |
| `GET` | `/me` | `Bearer` | Aktuellen Benutzer abrufen |

## Token-Generierung und Hashing

```php
// 64 Hex-Zeichen generieren (256-Bit-Entropie)
$rawToken   = bin2hex(random_bytes(32));
$tokenHash  = hash('sha256', $rawToken);
$expiresAt  = (new \DateTimeImmutable())->modify('+15 minutes')->format('c');

$this->repo->createMagicLink($userId, $tokenHash, $expiresAt);

// Rohes Token an Aufrufer zurückgeben (per E-Mail als URL-Parameter an Benutzer gesendet)
return ['token' => $rawToken];
```

Das rohe Token wird in der Antwort zurückgegeben (als URL-Parameter in der E-Mail zu senden). Nur der SHA-256-Hash wird gespeichert. `UNIQUE(token_hash)` verhindert Hash-Kollisionen.

## Session-Token

```php
$rawSessionToken  = bin2hex(random_bytes(32)); // 64 Hex-Zeichen
$sessionTokenHash = hash('sha256', $rawSessionToken);
$sessionExpiry    = (new \DateTimeImmutable())->modify('+24 hours')->format('c');

$this->repo->createSession($userId, $sessionTokenHash, $sessionExpiry);

return ['session_token' => $rawSessionToken]; // einmal zurückgegeben, dann nur noch Hash
```

Session-Token: 64 Hex-Zeichen = 256 Bit Entropie. Als SHA-256-Hash gespeichert. Mindestens 64 Zeichen durch Entropiequelle erzwungen (`bin2hex(random_bytes(32))`).

## Verifizierung — Reihenfolge der Prüfungen ist wichtig

```php
// 1. Nach Hash suchen
$magicLink = $this->repo->findByTokenHash(hash('sha256', $token));
if ($magicLink === null) {
    return 401; // nicht gefunden
}

// 2. Ablauf ZUERST prüfen
if ($magicLink['expires_at'] < date('c')) {
    return 401; // 'expired' in Fehlermeldung
}

// 3. used_at DANACH prüfen
if ($magicLink['used_at'] !== null) {
    return 401; // 'already been used' in Fehlermeldung
}

// 4. Als genutzt markieren
$this->repo->markUsed($magicLink['id'], date('c'));

// 5. Session erstellen
```

Ablauf wird **vor** `used_at` geprüft. Wenn ein Token sowohl abgelaufen als auch verwendet ist, sagt der Fehler "expired" — nicht "already been used". Das verhindert Timing-Angriffe, bei denen ein Angreifer sondiert, ob ein Token verwendet wurde.

## Benutzer-Enumerations-Prävention — Immer 202

```php
public function handleRequest(ServerRequestInterface $request): ResponseInterface
{
    $email = $body['email'] ?? '';
    
    $user = $this->repo->findUserByEmail($email);
    if ($user !== null) {
        // Magic-Link erstellen und (in der Produktion) E-Mail senden
        $rawToken = bin2hex(random_bytes(32));
        $this->repo->createMagicLink($user['id'], hash('sha256', $rawToken), ...);
    }
    // Immer 202 zurückgeben, unabhängig davon, ob E-Mail existiert
    return $this->json->create(['message' => 'If registered, a magic link has been sent.'], 202);
}
```

Nicht-existierende E-Mail-Adressen geben dieselbe 202-Antwort wie gültige zurück. Es wird niemals eine "E-Mail nicht gefunden"-Meldung zurückgegeben.

## Session-Validierung

```php
$token = substr($authHeader, 7); // 'Bearer ' entfernen
$tokenHash = hash('sha256', $token);
$session = $this->repo->findSessionByHash($tokenHash);

if ($session === null) {
    return 401; // Session nicht gefunden
}
if ($session['revoked_at'] !== null) {
    return 401; // 'revoked'
}
if ($session['expires_at'] < date('c')) {
    return 401; // 'expired'
}
```

Drei Session-Prüfungen: Existenz → widerrufen → abgelaufen. Widerrufene Sessions vom Logout geben "revoked" zurück — distinct von "expired" für klare Fehlermeldungen.

## X-User-Id-Header für Auth ignoriert

Der `/me`-Endpunkt erfordert ein gültiges `Bearer`-Session-Token. Der `X-User-Id`-Header (von anderen Endpunkten als Convenience-Auth verwendet) wird hier explizit ignoriert:

```php
// Nur Bearer-Token-Authentifizierung — X-User-Id wird nicht akzeptiert
$authHeader = $request->getHeaderLine('Authorization');
if (!str_starts_with($authHeader, 'Bearer ')) {
    return 401;
}
```

---

## Schwachstellenanalyse

### V-01 — Abgelaufenes Token vor used_at-Prüfung abgelehnt ✅ SAFE

**Risiko**: Token abgelaufen aber noch nicht verwendet; Angreifer versucht es zu verwenden und erhält "already used"-Fehler, der Reihenfolge der Prüfungen enthüllt.
**Befund**: SAFE — Ablauf wird zuerst geprüft. Sowohl abgelaufene+verwendete Token geben "expired" zurück.

---

### V-02 — Session-Token als Hash gespeichert ✅ SAFE

**Risiko**: DB-Verletzung enthüllt Session-Token.
**Befund**: SAFE — `session_token_hash = SHA-256(raw_token)`. Rohes Token nicht in DB.

---

### V-03 — Verwendeter Magic-Link kann nicht wiederverwendet werden ✅ SAFE

**Risiko**: Angreifer fängt Magic-Link-URL ab und verwendet sie nach dem beabsichtigten Benutzer.
**Befund**: SAFE — `used_at` wird bei erster Verwendung gesetzt; zweiter Versuch gibt 401 "already been used" zurück.

---

### V-04 — Logout macht Session ungültig ✅ SAFE

**Risiko**: Session-Cookie/Token funktioniert nach dem Logout noch.
**Befund**: SAFE — Logout setzt `revoked_at`; nachfolgendes `/me` mit dem Token gibt 401 "revoked" zurück.

---

### V-05 — Nicht-existierende E-Mail gibt 202 zurück ✅ SAFE

**Risiko**: Angreifer prüft, welche E-Mails registriert sind, indem er verschiedene Fehlerantworten beobachtet.
**Befund**: SAFE — `/auth/request` gibt immer 202 mit demselben Body zurück. Kein "nicht gefunden"-Leck.

---

### V-06 — Widerrufene Session abgelehnt ✅ SAFE

**Risiko**: Manuell widerrufene Session gewährt noch Zugriff.
**Befund**: SAFE — `revoked_at`-Prüfung verweigert Zugriff; Fehlermeldung lautet "revoked".

---

### V-07 — Abgelaufene Session abgelehnt ✅ SAFE

**Risiko**: Alte Session von vor langer Zeit funktioniert noch.
**Befund**: SAFE — `expires_at`-Prüfung verweigert Zugriff; Fehlermeldung lautet "expired".

---

### V-08 — Magic-Link-Token als Hash in DB gespeichert ✅ SAFE

**Risiko**: DB-Verletzung enthüllt Magic-Link-Token; Angreifer authentifiziert sich als beliebiger Benutzer.
**Befund**: SAFE — `token_hash = SHA-256(raw_token)`. Rohes Token nicht in DB.

---

### V-09 — Magic-Link läuft innerhalb von 15 Minuten ab ✅ SAFE

**Risiko**: Langlebiger Magic-Link ermöglicht verzögerte Abfangung und Wiederholung.
**Befund**: SAFE — TTL ≤ 900 Sekunden (15 Minuten) durch Test bestätigt.

---

### V-10 — Session hat Ablaufdatum ✅ SAFE

**Risiko**: Session läuft nie ab; alte Token bleiben für immer gültig.
**Befund**: SAFE — `expires_at` wird in der Zukunft bei Session-Erstellung gesetzt; als nicht-null bestätigt.

---

### V-11 — Session-Token hat ausreichende Entropie ✅ SAFE

**Risiko**: Kurzes Session-Token per Brute-Force knackbar.
**Befund**: SAFE — `bin2hex(random_bytes(32))` = 64 Hex-Zeichen = 256-Bit-Entropie.

---

### V-12 — X-User-Id-Header kann Auth nicht umgehen ✅ SAFE

**Risiko**: `X-User-Id: 1`-Header gewährt Zugriff auf `/me` ohne gültige Session.
**Befund**: SAFE — `/me` erfordert `Authorization: Bearer <token>`. X-User-Id wird ignoriert.

---

### VULN-Zusammenfassung

| ID | Schwachstelle | Befund |
|----|---------------|--------|
| V-01 | Ablauf vs. used_at-Prüfreihenfolge | ✅ SAFE |
| V-02 | Session-Token im Klartext in DB | ✅ SAFE |
| V-03 | Wiederverwendung genutzter Magic-Links | ✅ SAFE |
| V-04 | Session gültig nach Logout | ✅ SAFE |
| V-05 | E-Mail-Enumeration | ✅ SAFE |
| V-06 | Widerrufener Sessionzugriff | ✅ SAFE |
| V-07 | Abgelaufener Sessionzugriff | ✅ SAFE |
| V-08 | Magic-Link-Token in DB | ✅ SAFE |
| V-09 | Magic-Link-TTL > 15 Min | ✅ SAFE |
| V-10 | Kein Session-Ablauf | ✅ SAFE |
| V-11 | Niedrige Entropie beim Session-Token | ✅ SAFE |
| V-12 | X-User-Id-Bypass | ✅ SAFE |

**12 SAFE, 0 EXPOSED**

---

## Was man NICHT tun sollte

| Anti-Muster | Risiko |
|---|---|
| Rohes Magic-Link-Token in DB speichern | DB-Verletzung lässt Angreifer sich als beliebiger Benutzer authentifizieren |
| Rohes Session-Token in DB speichern | DB-Verletzung macht alle Sessions ungültig |
| used_at vor expires_at prüfen | Timing-Leck enthüllt, ob Token verwendet wurde |
| Fehler für nicht-existierende E-Mail zurückgeben | Angreifer enumeriert registrierte E-Mails |
| Kein TTL für Magic-Links | Unbegrenzt gültige Token; verzögerter Abfangangriff |
| Kein Session-Ablauf | Sessions bleiben für immer gültig |
| X-User-Id für Bearer-Auth akzeptieren | Header-basierter Auth-Bypass ohne Token |
| Token mit niedriger Entropie (`rand()` oder 8 Zeichen) | Per Brute-Force knackbare Token |
| Denselben Magic-Link für mehrere Sessions wiederverwenden | Einzelne Token-Exposition gewährt alle nachfolgenden Sessions |
