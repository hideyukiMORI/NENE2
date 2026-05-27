# How-to: Multi-Gerät-Sitzungsverwaltung

> **Muster erprobt durch FT186 sessionlog** — Multi-Gerät-Sitzungsverfolgung, IDOR-Prävention, Mass-Assignment-Guard, Widerruf ohne Timing-Oracle.

---

## Was behandelt wird

Ein Multi-Gerät-Sitzungsmanager ermöglicht Benutzern:

1. **Sitzungen erstellen** beim Login (jedes Gerät erhält sein eigenes Token)
2. **Aktive Sitzungen auflisten**, begrenzt auf ihre Benutzer-ID
3. **Eine einzelne Sitzung widerrufen** (von einem Gerät abmelden)
4. **Alle außer der aktuellen widerrufen** (von allen anderen Geräten abmelden)

Demonstrierte Sicherheitsgarantien:

| Problem | Technik |
|---|---|
| IDOR-Prävention | Alle Mutationen begrenzen auf `WHERE token = ? AND user_id = ?` |
| Mass Assignment | `token`, `user_id`, `created_at`, `revoked_at` nur serverseitig gesetzt |
| Timing-Oracle | Generisches 404 für alle Fehler — kein Eigentümerschaftsleck |
| Integer-Überlauf | `V::queryInt()` 18-Ziffer-strlen-Guard |
| Typverwechslung | `V::str()` lehnt nicht-String `device_name`/`ip_address` ab |
| Token-Entropie | `bin2hex(random_bytes(32))` — 256-Bit, 64 Hex-Zeichen |
| SQL-Injection | PDO parametrisierte Abfragen + `/^[0-9a-f]{64}$/`-Gate |

---

## Schema

```sql
CREATE TABLE sessions (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id     INTEGER NOT NULL,
    token       TEXT    NOT NULL UNIQUE,
    device_name TEXT,
    ip_address  TEXT,
    last_active TEXT    NOT NULL,
    created_at  TEXT    NOT NULL,
    revoked_at  TEXT    -- NULL = aktiv
);
```

`revoked_at IS NULL` ist das Aktiv-Sitzungs-Prädikat. Soft Delete vermeidet den Verlust der Prüfhistorie.

---

## API-Design

| Methode | Pfad | Header | Beschreibung |
|---|---|---|---|
| `POST` | `/sessions` | `X-User-Id` | Sitzung erstellen |
| `GET` | `/sessions` | `X-User-Id` | Eigene aktive Sitzungen auflisten |
| `DELETE` | `/sessions/{token}` | `X-User-Id` | Eine Sitzung widerrufen |
| `DELETE` | `/sessions` | `X-User-Id` + `X-Current-Session` | Alle außer aktueller widerrufen |

---

## Kernmuster: 256-Bit-Token-Generierung

```php
public function create(int $userId, ?string $deviceName, ?string $ipAddress): Session
{
    $token = bin2hex(random_bytes(32)); // 256-Bit-Entropie, 64 Hex-Zeichen
    $now   = $this->now();

    $stmt = $this->pdo->prepare(
        'INSERT INTO sessions (user_id, token, device_name, ip_address, last_active, created_at)
         VALUES (:user_id, :token, :device_name, :ip_address, :now, :now2)',
    );
    $stmt->execute([...]);
    // ...
}
```

`bin2hex(random_bytes(32))` erzeugt 64 Kleinbuchstaben-Hex-Zeichen aus einer kryptographisch sicheren Quelle. Niemals Tokens aus Benutzereingaben akzeptieren.

---

## Kernmuster: IDOR-Prävention

```php
// FALSCH — lässt jeden authentifizierten Benutzer jede Sitzung widerrufen
UPDATE sessions SET revoked_at = ? WHERE token = ?

// RICHTIG — muss die Sitzung besitzen
public function revokeForUser(string $token, int $userId): bool
{
    $stmt = $this->pdo->prepare(
        'UPDATE sessions SET revoked_at = :now
         WHERE token = :token AND user_id = :user_id AND revoked_at IS NULL',
    );
    $stmt->execute(['now' => $this->now(), 'token' => $token, 'user_id' => $userId]);

    return $stmt->rowCount() > 0;
}
```

`rowCount() > 0` gibt `false` zurück, wenn das Token existiert, aber einem anderen Benutzer gehört — der Handler antwortet mit einem generischen 404 (siehe Timing-Oracle-Abschnitt).

---

## Kernmuster: Mass-Assignment-Guard

```php
// POST /sessions Handler — Angreifer-Body: {"token": "custom", "user_id": 999, "revoked_at": "now"}
private function handleCreate(ServerRequestInterface $request): ResponseInterface
{
    // userId kommt aus X-User-Id-Header — niemals aus Body
    $userId = V::userId($request->getHeaderLine('X-User-Id'));

    $body = $this->parseBody($request);

    // Nur sichere, validierte Felder werden weitergegeben
    $deviceName = V::str($body['device_name'] ?? null, 200);
    $ipAddress  = V::str($body['ip_address'] ?? null, 45);

    // token, user_id, created_at, revoked_at werden vom Repository gesetzt — nicht aus Body
    $session = $this->repository->create($userId, $deviceName, $ipAddress);

    return $this->responseFactory->create($session->toArray(), 201);
}
```

---

## Kernmuster: Timing-Oracle-Prävention

```php
private function handleRevokeOne(ServerRequestInterface $request): ResponseInterface
{
    $userId   = V::userId($request->getHeaderLine('X-User-Id'));
    $rawToken = Router::param($request, 'token');

    // VULN-I: ungültiges Format → sofort 404 (keine DB-Abfrage)
    if ($rawToken === null || !preg_match('/^[0-9a-f]{64}$/', $rawToken)) {
        return $this->responseFactory->create(['error' => 'Sitzung nicht gefunden.'], 404);
    }

    // IDOR-Guard: revokeForUser gibt false zurück wenn:
    //   - Token existiert nicht
    //   - Token gehört einem anderen Benutzer
    //   - Token ist bereits widerrufen
    // Alle Fälle geben dasselbe 404 zurück — kein Eigentümerschafts-Oracle
    $revoked = $this->repository->revokeForUser($rawToken, $userId);

    if (!$revoked) {
        return $this->responseFactory->create(['error' => 'Sitzung nicht gefunden.'], 404);
    }

    return $this->responseFactory->create([], 204);
}
```

Niemals "nicht gefunden" von "falscher Benutzer" in der Antwort unterscheiden. Ein Angreifer, der das Token eines Opfers kennt, darf nicht erfahren, ob es aktiv ist oder diesem Benutzer gehört.

---

## Kernmuster: Alle außer aktueller widerrufen

```php
public function revokeAllExcept(int $userId, string $currentToken): int
{
    $stmt = $this->pdo->prepare(
        'UPDATE sessions SET revoked_at = :now
         WHERE user_id = :user_id AND token != :current AND revoked_at IS NULL',
    );
    $stmt->execute(['now' => $this->now(), 'user_id' => $userId, 'current' => $currentToken]);

    return $stmt->rowCount();
}
```

Der Aufrufer übergibt den `X-Current-Session`-Header. Sowohl `user_id` als auch die Ausschlussbedingung werden in der einzigen Abfrage durchgesetzt.

---

## Kernmuster: Überlaufsichere Limit-Validierung

```php
// VULN-A: V::queryInt lehnt >18 Ziffern ab — verhindert stillen PHP-Int-Überlauf
// VULN-F: ctype_digit ist O(n) — kein Regex-Backtracking-Risiko
$limit = V::queryInt($params, 'limit', 1, self::MAX_LIMIT, self::DEFAULT_LIMIT);

if ($limit === null) {
    return $this->responseFactory->create(
        ['error' => sprintf('limit muss zwischen 1 und %d liegen.', self::MAX_LIMIT)],
        422,
    );
}
```

`V::queryInt()` lehnt negative Zahlen, Floats, Hex-Strings (`0x10`) und Zahlen > 18 Ziffern ab.

---

## Route-Token-Validierung

Das Token-Format immer auf der Route-Ebene validieren, bevor die DB abgefragt wird:

```php
private const TOKEN_PATTERN = '/^[0-9a-f]{64}$/';

// Im Handler:
if ($rawToken === null || !preg_match(self::TOKEN_PATTERN, $rawToken)) {
    return $this->responseFactory->create(['error' => 'Sitzung nicht gefunden.'], 404);
}
```

Das blockiert SQL-Injection-Strings, Path-Traversal-Versuche und kurze/lange Tokens vor jeder Datenbankinteraktion.

---

## Testergebnisse (FT186)

```
54 Tests / 116 Assertions — alle bestanden
PHPStan Level 8 — keine Fehler
PHP CS Fixer — sauber
```

VULN-A bis L-Abdeckung:

| Vuln | Muster | Test |
|---|---|---|
| A | limit 19-Ziffer-Überlauf | `testVulnALimitOverflow19Digits` |
| B | device_name Typverwechslung | `testVulnBDeviceNameAsInteger` |
| C | SQL-Injection im Token | `testVulnCSqlInjectionToken` |
| D | negatives/float/hex limit | `testVulnDNegativeLimitRejected` |
| E | IDOR-Widerruf | `testVulnECannotRevokeOtherUsersSession` |
| F | ReDoS-ähnliches langes limit | `testVulnFVeryLongLimitRejected` |
| H | Timing-Oracle | `testVulnHSameResponseForAlreadyRevokedAndCrossUser` |
| I | leeres/kurzes/traversal Token | `testVulnIEmptyTokenSegmentNotMatched` |
| L | Mass Assignment | `testVulnLTokenFromBodyIsIgnored` |

Quelle: [`../NENE2-FT/sessionlog/`](https://github.com/hideyukiMORI/NENE2-examples/tree/main/sessionlog)
