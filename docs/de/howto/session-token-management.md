# How-to: Sitzungs- und Token-Management-API (ATK-01 bis 12)

Diese Anleitung demonstriert eine sichere Sitzungstoken-API, die alle ATK-01 bis 12 Cracker-Mindset-Angriffsvektoren abdeckt.

## Musterübersicht

- `POST /sessions` — Neues opakes Token für einen Benutzer ausstellen (`X-User-Id` erforderlich).
- `GET /sessions/{token}` — Token validieren (404 wenn widerrufen oder abgelaufen).
- `DELETE /sessions/{token}` — Token widerrufen (Eigentümer oder Admin).
- `GET /users/{userId}/sessions` — Aktive Sitzungen auflisten (Eigentümer oder Admin).

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

Vor jeder DB-Suche das Token-Format mit einem engen Regex validieren:

```php
private const string TOKEN_PATTERN = '/\A[0-9a-f]{64}\z/';

private function pathToken(ServerRequestInterface $req): ?string
{
    $token = $params['token'] ?? '';
    if (!preg_match(self::TOKEN_PATTERN, $token)) {
        return null;  // 404 — trifft niemals die DB
    }
    return $token;
}
```

Das lehnt SQL-Injection-Payloads, überdimensionierte Eingaben, Großbuchstaben-Hex und Nicht-Hex-Strings vor jeder DB-Abfrage ab.

## ATK-01: SQL-Injection im Token-Pfad

Der Token-Format-Regex lehnt `' OR '1'='1` sofort ab. Selbst wenn er durchkäme, verwendet die DB-Abfrage eine vorbereitete Anweisung:

```php
$stmt = $this->pdo->prepare('SELECT * FROM sessions WHERE token = :token');
$stmt->execute([':token' => $token]);
```

## ATK-02 bis 04: Token-Format-Angriffe

Alle werden durch den Regex `/\A[0-9a-f]{64}\z/` abgelehnt:
- Leerer String (Länge 0 ≠ 64)
- Überdimensionierter String (256 Zeichen ≠ 64)
- Nicht-Hex-Zeichen (`g`, `A`–`F` Großbuchstaben, Sonderzeichen)
- Falsche Länge (63 oder 65 Zeichen)

## ATK-05: Integer-Überlauf in X-User-Id

```php
if ($raw === '' || !ctype_digit($raw) || strlen($raw) > 18) {
    return null;
}
```

Eine 19-stellige Ganzzahl überschreitet das 18-Zeichen-Limit und wird vor einem `(int)`-Cast abgelehnt.

## ATK-06: Negative / Null-Benutzer-ID

```php
$id = (int) $raw;
return $id > 0 ? $id : null;
```

`0` und negative Werte geben `null` zurück, was 400 auslöst.

## ATK-07: Admin-Key Fail-Closed

Ein leerer `adminKey` gewährt niemals Admin-Zugang:

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

## ATK-09: Nicht-Numerische Benutzer-ID im Listen-Pfad

`ctype_digit()` lehnt `abc`, `1.5`, `-1` ab:

```php
if (!ctype_digit($raw) || strlen($raw) > 18) {
    return $this->problem(404, 'not-found', 'Benutzer nicht gefunden.');
}
```

## ATK-10: Float-TTL

`is_int()` ist PHPs strikte Typprüfung — `60.5` gibt `false` zurück:

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

Bereits widerrufene Sitzungen können nicht durch erneutes Senden eines DELETE "nicht-widerrufen" werden.

## ATK-12: Brute-Force-Token-Format-Ablehnung

Jedes Token, das nicht genau 64 Kleinbuchstaben-Hex-Zeichen entspricht, wird mit 404 abgelehnt, bevor die Datenbank berührt wird. Brute-Force-Versuche treffen die Regex-Mauer, nicht die DB.

## IDOR: Eigentümer vs. Admin

- Nicht-Eigentümer, die die Sitzungen eines anderen Benutzers widerrufen oder auflisten, erhalten 404 (nicht 403).
- Admins verwenden den `X-Admin-Key`-Header; fail-closed wenn Key nicht konfiguriert.

## Siehe auch

- FT208-Quelle: `../NENE2-FT/sessionlog/`
- Verwandt: `docs/howto/rate-limiting.md` (FT200, ATK)
- Verwandt: `docs/howto/coupon-redemption.md` (FT204, VULN + ATK)
