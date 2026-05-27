# Zugriffstoken-Verwaltung mit NENE2 implementieren

Diese Anleitung beschreibt den Aufbau eines Personal-Access-Token-Systems (PAT) — Benutzer erstellen, listen und widerrufen ihre eigenen API-Tokens, jeweils mit einem Berechtigungsbereich (`read`/`write`/`admin`). Tokens werden niemals im Klartext gespeichert; nur ihr SHA-256-Hash wird aufbewahrt.

**Field Trial**: FT136  
**NENE2-Version**: ^1.5  
**Behandelte Themen**: Token-Hashing, Bereichs-Enums, Besitz-Durchsetzung, Widerrufs-Idempotenz, Verifizierungs-Endpunkt

---

## Was wir bauen

- `POST /users/{id}/tokens` — Token ausstellen (nur Besitzer, gibt rohen Token einmalig zurück)
- `GET /users/{id}/tokens` — Tokens auflisten (nur Besitzer, kein roher Token in der Antwort)
- `DELETE /users/{id}/tokens/{tokenId}` — Token widerrufen (nur Besitzer, 409 wenn bereits widerrufen)
- `POST /tokens/verify` — Rohen Token verifizieren (gibt valid/invalid + Bereich zurück)

---

## Datenbankschema

```sql
CREATE TABLE tokens (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    token_hash TEXT    NOT NULL UNIQUE,
    scope      TEXT    NOT NULL DEFAULT 'read',
    label      TEXT    NOT NULL DEFAULT '',
    created_at TEXT    NOT NULL,
    revoked_at TEXT,
    FOREIGN KEY (user_id) REFERENCES users(id),
    CHECK (scope IN ('read', 'write', 'admin'))
);
```

- `token_hash` — SHA-256 des rohen Tokens; den rohen Token niemals speichern
- `revoked_at` — nullbarer Zeitstempel; `NULL` = aktiv, nicht-null = widerrufen
- `CHECK (scope IN (...))` — Bereichs-Constraint auf DB-Ebene als Defense-in-Depth

---

## Token-Bereichs-Enum

```php
enum TokenScope: string
{
    case Read  = 'read';
    case Write = 'write';
    case Admin = 'admin';
}
```

`TokenScope::tryFrom($value)` gibt `null` für unbekannte Bereiche zurück — verwenden Sie dies zur Eingabevalidierung vor dem Speichern.

---

## Tokens ausstellen

```php
public function issueToken(int $userId, TokenScope $scope, string $label, string $now): string
{
    $raw  = bin2hex(random_bytes(32)); // 64-stelliger Hex-String
    $hash = hash('sha256', $raw);

    $this->executor->execute(
        'INSERT INTO tokens (user_id, token_hash, scope, label, created_at) VALUES (?, ?, ?, ?, ?)',
        [$userId, $hash, $scope->value, $label, $now],
    );

    return $raw; // einmalig zurückgegeben, niemals gespeichert
}
```

Der rohe Token wird dem Aufrufer genau einmal zurückgegeben. Danach befindet sich nur noch der Hash in der Datenbank — der rohe Token ist nicht wiederherstellbar.

---

## Tokens verifizieren

```php
public function verifyToken(string $rawToken): ?array
{
    $hash = hash('sha256', $rawToken);
    $row  = $this->executor->fetchOne(
        'SELECT id, user_id, scope, revoked_at FROM tokens WHERE token_hash = ?',
        [$hash],
    );

    if ($row === null) {
        return null;
    }

    $arr = (array) $row;

    return [
        'valid'   => !isset($arr['revoked_at']),
        'user_id' => isset($arr['user_id']) ? (int) $arr['user_id'] : 0,
        'scope'   => isset($arr['scope']) && is_string($arr['scope']) ? $arr['scope'] : 'read',
    ];
}
```

**Warum `!isset($arr['revoked_at'])` und nicht `=== null`?** Nachdem `isset()` true zurückgibt, eliminiert PHPStan `null` aus dem Typ — ein Vergleich mit `null` wäre `identical.alwaysFalse`. Verwenden Sie `isset()` allein, um auf null zu prüfen.

Der Verifizierungs-Endpunkt gibt immer 200 mit `{ "valid": false }` für unbekannte oder widerrufene Tokens zurück — niemals 404. Dies verhindert Token-Enumeration.

---

## Besitz-Durchsetzung

Jeder mutierende Endpunkt prüft, ob der authentifizierte Akteur mit dem Ressourcenbesitzer übereinstimmt:

```php
$actorId = $this->resolveActorId($request); // aus X-User-Id-Header

if ($actorId !== $userId) {
    return $this->responseFactory->create(['error' => 'forbidden'], 403);
}
```

Beim Widerrufen gibt es eine zweite Besitzprüfung auf dem Token selbst:

```php
$token = $this->repo->findTokenById($tokenId);

if ($token['user_id'] !== $userId) {
    return $this->responseFactory->create(['error' => 'forbidden'], 403);
}
```

Dies verhindert ATK-04 — Bob nutzt seinen eigenen Benutzerpfad, widerruft aber Alices Token-ID.

---

## Widerrufen — 409 für bereits widerrufene Tokens

```php
public function revokeToken(int $tokenId, string $now): bool
{
    $count = $this->executor->execute(
        'UPDATE tokens SET revoked_at = ? WHERE id = ? AND revoked_at IS NULL',
        [$now, $tokenId],
    );

    return $count > 0;
}
```

Der `WHERE revoked_at IS NULL`-Guard bewirkt, dass das UPDATE keine Auswirkung hat, wenn der Token bereits widerrufen wurde. Der Handler mappt `$count === 0` auf 409 Conflict.

---

## Tokens auflisten — rohen Token niemals einschließen

Die Listenantwort enthält `id`, `scope`, `label`, `created_at`, `revoked` (bool). Der rohe Token wird nach dem initialen Ausstellen nie zurückgegeben.

---

## PHPStan Level 8 Fallstrick: isset + null-Vergleich

```php
// FALSCH — PHPStan meldet `notIdentical.alwaysTrue`
'revoked' => isset($arr['revoked_at']) && $arr['revoked_at'] !== null,

// RICHTIG — isset() impliziert bereits non-null
'revoked' => isset($arr['revoked_at']),

// FALSCH — PHPStan meldet `identical.alwaysFalse`
'valid' => !isset($arr['revoked_at']) || $arr['revoked_at'] === null,

// RICHTIG
'valid' => !isset($arr['revoked_at']),
```

---

## Cracker-Angriffstestergebnisse (FT136)

| Angriff | Erwartet | Ergebnis |
|--------|----------|--------|
| ATK-01: Token für anderen Benutzer ausstellen (IDOR) | 403 | Bestanden |
| ATK-02: Tokens eines anderen Benutzers auflisten (IDOR) | 403 | Bestanden |
| ATK-03: Token eines anderen Benutzers über dessen Pfad widerrufen | 403 | Bestanden |
| ATK-04: Token eines anderen Benutzers über eigenen Pfad widerrufen | 403 | Bestanden |
| ATK-05: Ungültiger Bereich (`superuser`) | 422 | Bestanden |
| ATK-06: Widerrufenen Token für Verifizierung verwenden | valid=false | Bestanden |
| ATK-07: Zufälligen Token brute-forcen | valid=false | Bestanden |
| ATK-08: SQL-Injection im Verifizierungs-Body | valid=false | Bestanden |
| ATK-09: Nicht-numerische X-User-Id (`admin`) | nicht 201 | Bestanden |
| ATK-10: Negative Benutzer-ID | 404 | Bestanden |
| ATK-11: 10KB-Bereichs-String | 422 | Bestanden |
| ATK-12: Leerer/nur Leerzeichen enthaltender Token | 422 | Bestanden |

Alle 12 Angriffstests bestanden.

---

## Häufige Fallstricke

| Fallstrick | Lösung |
|---------|-----|
| `isset($x) && $x !== null` | Nur `isset($x)` verwenden — PHPStan Level 8 lehnt die redundante Prüfung ab |
| Rohen Token in DB speichern | Nur `hash('sha256', $raw)` speichern |
| Rohen Token in Listenantwort zurückgeben | Rohen Token nur in der Ausstellungsantwort zurückgeben |
| Token-Besitz beim Widerrufen nicht prüfen | `token['user_id'] === userId` nach dem Auffinden des Tokens prüfen |
| 404 für ungültigen Token in der Verifizierung zurückgeben | Immer 200 mit `valid: false` zurückgeben — verhindert Enumeration |
