# Anleitung: Session-/Token-Verwaltungs-API (ATK-01~12)

Diese Anleitung demonstriert eine sichere Session-Token-API, die alle ATK-01~12-Angriffsvektoren des Cracker-Mindset-Tests abdeckt.

## Musterzusammenfassung

- `POST /sessions` — Einen neuen opaken Token für einen Benutzer ausstellen (`X-User-Id` erforderlich).
- `GET /sessions/{token}` — Einen Token validieren (404 wenn widerrufen oder abgelaufen).
- `DELETE /sessions/{token}` — Einen Token widerrufen (Eigentümer oder Admin).
- `GET /users/{userId}/sessions` — Aktive Sessions auflisten (Eigentümer oder Admin).

Tokens sind `bin2hex(random_bytes(32))` — 64 Kleinbuchstaben-Hex-Zeichen.

## Schema

```sql
CREATE TABLE IF NOT EXISTS sessions (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    token      TEXT    NOT NULL UNIQUE,
    label      TEXT    NOT NULL DEFAULT '',
    revoked    INTEGER NOT NULL DEFAULT 0,
    expires_at TEXT    NOT NULL,
    created_at TEXT    NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_sessions_token ON sessions (token);
CREATE INDEX IF NOT EXISTS idx_sessions_user  ON sessions (user_id, revoked);
```

## Token-Generierung

```php
$token = bin2hex(random_bytes(32));  // 64 Kleinbuchstaben-Hex-Zeichen
```

`random_bytes()` verwendet einen CSPRNG; Tokens sind nicht erratbar und nicht sequenziell.

## Token-Format-Validierung

Vor jedem DB-Lookup das Token-Format mit einem strengen Regex validieren:

```php
private const string TOKEN_PATTERN = '/\A[0-9a-f]{64}\z/';

private function pathToken(ServerRequestInterface $req): ?string
{
    $token = $params['token'] ?? '';
    if (!preg_match(self::TOKEN_PATTERN, $token)) {
        return null;  // 404 — trifft die DB nie
    }
    return $token;
}
```

Dies lehnt SQL-Injection-Payloads, übergroße Eingaben, Großbuchstaben-Hex und Nicht-Hex-Strings vor jeder DB-Abfrage ab.

## ATK-01: SQL-Injection im Token-Pfad

Das Token-Format-Regex lehnt `' OR '1'='1` sofort ab. Selbst wenn es passieren würde, verwendet die DB-Abfrage ein Prepared Statement:

```php
$stmt = $this->pdo->prepare('SELECT * FROM sessions WHERE token = :token');
$stmt->execute([':token' => $token]);
```

## ATK-02~04: Token-Format-Angriffe

Alle vom Regex `/\A[0-9a-f]{64}\z/` abgelehnt:
- Leerer String (Länge 0 ≠ 64)
- Überlanger String (256 Zeichen ≠ 64)
- Nicht-Hex-Zeichen (`g`, `A`–`F` Großbuchstaben, Sonderzeichen)
- Falsche Länge (63 oder 65 Zeichen)

## ATK-05: Integer-Überlauf in X-User-Id

```php
if ($raw === '' || !ctype_digit($raw) || strlen($raw) > 18) {
    return null;
}
```

Ein 19-stelliger Integer überschreitet das 18-Zeichen-Limit und wird vor jedem `(int)`-Cast abgelehnt.

## ATK-06: Negative / Null-User-ID

```php
$id = (int) $raw;
return $id > 0 ? $id : null;
```

`0` und negative Werte geben `null` zurück, was 400 auslöst.

## ATK-07: Admin-Key Fail-Closed

Ein leerer `adminKey` gewährt nie Admin-Zugriff:

```php
private function isAdmin(ServerRequestInterface $req): bool
{
    if ($this->adminKey === '') {
        return false;
    }
    return hash_equals($this->adminKey, $req->getHeaderLine('X-Admin-Key'));
}
```

`hash_equals()` verhindert Timing-Angriffe beim Vergleich des Keys.

## ATK-08: Auth-Bypass via X-User-Id: 0

`uid()` gibt `null` für ID=0 zurück → 400, nicht 200.

## ATK-09: Nicht-numerische User-ID im Listen-Pfad

`ctype_digit()` lehnt `abc`, `1.5`, `-1` ab:

```php
if (!ctype_digit($raw) || strlen($raw) > 18) {
    return $this->problem(404, 'not-found', 'User not found.');
}
```

## ATK-10: Float-TTL

`is_int()` ist PHPs strikter Typcheck — `60.5` gibt `false` zurück:

```php
if (!is_int($ttlRaw) || $ttlRaw < 1 || $ttlRaw > self::MAX_TTL) {
    return $this->problem(422, ...);
}
```

## ATK-11: Doppelter Widerruf gibt 404 zurück

Das Repository prüft `revoked === 1` vor dem Aktualisieren:

```php
if ($session === null || (int) $session['revoked'] === 1) {
    return false;
}
```

Bereits widerrufene Sessions können nicht durch erneutes Senden eines DELETE „un-widerrufen" werden.

## ATK-12: Brute-Force-Token-Format-Ablehnung

Jeder Token, der nicht exakt 64 Kleinbuchstaben-Hex-Zeichen entspricht, wird mit 404 abgelehnt, bevor die Datenbank berührt wird. Brute-Force-Versuche treffen die Regex-Mauer, nicht die DB.

## IDOR: Eigentümer vs. Admin

- Nicht-Eigentümer, die die Sessions eines anderen Benutzers widerrufen oder auflisten wollen, erhalten 404 (nicht 403).
- Admins verwenden den `X-Admin-Key`-Header; fail-closed wenn der Key nicht konfiguriert ist.

## Siehe auch

- FT208-Quelle: `../NENE2-FT/sessionlog/`
- Verwandt: `docs/howto/rate-limiting.md` (FT200, ATK)
- Verwandt: `docs/howto/coupon-redemption.md` (FT204, VULN + ATK)
