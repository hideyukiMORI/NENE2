# Implementierungsleitfaden: OAuth2 Social Login

## Übersicht

Diese Anleitung erklärt, wie mit NENE2 ein Social Login über den OAuth2 Authorization Code Flow implementiert wird. Enthält CSRF-Schutz (state-Parameter), Code-Replay-Prävention, Session-Invalidierung und Cracker-Angriffstests (ATK-01~12).

---

## DB-Schema

```sql
CREATE TABLE users (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    provider   TEXT    NOT NULL,
    subject    TEXT    NOT NULL,  -- Vom OAuth-Provider ausgegebener Benutzeridentifier
    name       TEXT    NOT NULL,
    email      TEXT,
    created_at TEXT    NOT NULL,
    UNIQUE (provider, subject)
);

CREATE TABLE oauth_states (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    state      TEXT    NOT NULL UNIQUE,
    created_at TEXT    NOT NULL,
    expires_at TEXT    NOT NULL,
    used_at    TEXT    -- NULL = unbenutzt, NOT NULL = benutzt (nicht wiederverwendbar)
);

CREATE TABLE sessions (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    token      TEXT    NOT NULL UNIQUE,
    created_at TEXT    NOT NULL,
    expires_at TEXT    NOT NULL,
    revoked_at TEXT,   -- NULL = gültig, NOT NULL = ausgeloggt
    FOREIGN KEY (user_id) REFERENCES users(id)
);

CREATE TABLE used_oauth_codes (
    id       INTEGER PRIMARY KEY AUTOINCREMENT,
    code     TEXT    NOT NULL UNIQUE,
    used_at  TEXT    NOT NULL
);
```

`oauth_states.used_at` und `used_oauth_codes` sind der Kern der **CSRF- und Code-Replay-Angriffsprävention**.

---

## Endpunkt-Design

| Methode | Pfad | Beschreibung |
|---|---|---|
| POST | `/auth/oauth/start` | State generieren, Autorisierungs-URL zurückgeben |
| POST | `/auth/oauth/callback` | State/Code verifizieren, Benutzer erstellen, Session ausgeben |
| POST | `/auth/logout` | Session invalidieren |
| GET | `/me` | Authentifizierten Benutzer abrufen |

---

## Authorization Code Flow

```
Client                 Server                   OAuth-Provider
  |                      |                            |
  |-- POST /start -----→ |                            |
  |← {state, auth_url} --|                            |
  |                      |                            |
  |-- Benutzer ruft auth_url auf →→→→→→→→→→→→→→→→→ |
  |←←←←←←←←←←←←←←←←←←← Weiterleitung mit ?code=XXX&state=YYY |
  |                      |                            |
  |-- POST /callback ──→ |                            |
  |   {state, code}      |-- Code-Austausch →→→→→→ |
  |                      |← {subject, name, email} ---|
  |← {token, user} -----.|                            |
  |                      |                            |
  |-- GET /me ─────────→ |                            |
  |   Authorization: Bearer <token>                   |
  |← {id, name, email} - |                            |
```

---

## Designpunkte

### CSRF-Schutz (state-Parameter)

OAuth2-Callbacks kommen via URL-Parameter an, daher kann ein Angreifer das Opfer zu einer schädlichen Callback-URL leiten (CSRF). Mit `state` verhindern:

1. Bei `/auth/oauth/start` einen zufälligen State in der DB speichern
2. Im Callback den State abgleichen
3. **Verwendeten State als nicht-wiederverwendbar markieren** (`used_at` aufzeichnen)

```php
if (!$this->repo->isStateValid($state, $now)) {
    return $this->json->create(['error' => 'Invalid, expired, or already used state'], 400);
}
```

### Code-Replay-Prävention

Authorization Codes sind nur einmal verwendbar (RFC 6749 §4.1.2). Verwendete Codes in der `used_oauth_codes`-Tabelle aufzeichnen und Wiederverwendung ablehnen:

```php
if ($this->repo->isCodeUsed($code)) {
    return $this->json->create(['error' => 'Authorization code already used'], 400);
}
// ... Provider-Verifizierung ...
$this->repo->markCodeUsed($code, $now);
```

### Reihenfolge der State- und Code-Verarbeitung

State-Verifizierung → Code-Verifizierung → **Provider anfragen → State und Code gleichzeitig als verwendet markieren**. Wenn der Provider fehlschlägt, werden weder State noch Code verbraucht (Wiederholung ist möglich).

### Bearer-Token-Authentifizierung

```php
private function bearerToken(ServerRequestInterface $request): ?string
{
    $header = $request->getHeaderLine('Authorization');
    if (!str_starts_with($header, 'Bearer ')) {
        return null;
    }
    return substr($header, 7) ?: null;
}
```

### Benutzer-Upsert

Wenn sich derselbe Subject beim gleichen Provider erneut anmeldet, vorhandenen Benutzer aktualisieren:

```php
public function upsertUser(array $info, string $now): int
{
    $row = $this->db->fetchOne(
        'SELECT id FROM users WHERE provider = ? AND subject = ?',
        [$info['provider'], $info['subject']],
    );
    if ($row !== null) {
        // Name und E-Mail auf neueste aktualisieren
        $this->db->insert('UPDATE users SET name = ?, email = ? WHERE id = ?', [...]);
        return (int) $row['id'];
    }
    return $this->db->insert('INSERT INTO users ...', [...]);
}
```

### State-Ablaufzeit

State ist 5 Minuten gültig. Abgelaufene States werden durch `expires_at > $now`-Prüfung abgelehnt:

```php
public function isStateValid(string $state, string $now): bool
{
    $row = $this->findState($state);
    if ($row === null || $row['used_at'] !== null) return false;
    return (string) $row['expires_at'] > $now;
}
```

---

## Cracker-Angriffstests ATK-01~12 (alle Pass)

| # | Angriffsszenario | Gegenmaßnahme | Erwarteter Status |
|---|---|---|---|
| ATK-01 | CSRF: state-Parameter fehlt | Required-Validierung | 422 |
| ATK-02 | CSRF: Gefälschter State-Wert | DB-Abgleich → unbekannter State abgelehnt | 400 |
| ATK-03 | Wiederverwendung eines verwendeten States | Nach `used_at`-Aufzeichnung nicht wiederverwendbar | 400 |
| ATK-04 | Übernahme eines legitimen States zur Wiederverwendung | Sofort ungültig nach einmaliger Verwendung | 400 |
| ATK-05 | Replay des Authorization Codes | Aufzeichnung in `used_oauth_codes` | 400 |
| ATK-06 | Ungültiger Authorization Code | Mock-Provider gibt null zurück | 401 |
| ATK-07 | Open-Redirect-Injection | Start akzeptiert keine redirect_uri | evil-Domain nicht in auth_url |
| ATK-08 | Session-Wiederverwendung nach Logout | `revoked_at` gesetzt → findSession schlägt fehl | 401 |
| ATK-09 | Ungültiges Session-Token | DB-Abgleich → unregistriertes Token abgelehnt | 401 |
| ATK-10 | /me ohne Authentifizierung aufrufen | Bearer nicht gesetzt → 401 | 401 |
| ATK-11 | SQL-Injection im state-Parameter | Prepared Statement macht ihn unschädlich | 400/422 |
| ATK-12 | /me mit Session eines anderen Benutzers | Token ist an user_id gebunden | Andere user.id |

---

## Test-Aufbau

```
tests/
  OAuth/
    OAuthTest.php   — 10 Funktionaltests
    AttackTest.php  — 12 Cracker-Angriffstests (ATK-01~12)
```

Insgesamt 22 Tests / 36 Assertions.

---

## Referenzimplementierung

`../NENE2-FT/oauthlog/` — FT160 Field Trial (22 Tests + 12 Cracker-Angriffstests)
