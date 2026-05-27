# Anleitung: Multi-Device-Session-Manager

> **Muster bewiesen durch FT186 sessionlog** — Multi-Device-Session-Tracking, IDOR-Prävention, Mass-Assignment-Schutz, timing-oracle-freie Widerrufung.

---

## Was abgedeckt wird

Ein Multi-Device-Session-Manager ermöglicht Benutzern:

1. **Sessions erstellen** beim Login (jedes Gerät erhält seinen eigenen Token)
2. **Aktive Sessions auflisten** auf ihre User-ID begrenzt
3. **Eine einzelne Session widerrufen** (Abmelden von einem Gerät)
4. **Alle außer der aktuellen widerrufen** (Abmelden von allen anderen Geräten)

Demonstrierte Sicherheitsgarantien:

| Bereich | Technik |
|---|---|
| IDOR-Prävention | Alle Mutationen beschränken `WHERE token = ? AND user_id = ?` |
| Mass Assignment | `token`, `user_id`, `created_at`, `revoked_at` nur serverseitig gesetzt |
| Timing-Oracle | Generisches 404 für alle Fehler — kein Eigentümerschafts-Leak |
| Integer-Überlauf | `V::queryInt()` 18-stelliger strlen-Schutz |
| Typ-Verwirrung | `V::str()` lehnt Nicht-String `device_name`/`ip_address` ab |
| Token-Entropie | `bin2hex(random_bytes(32))` — 256 Bit, 64 Hex-Zeichen |
| SQL-Injection | PDO-parametrisierte Abfragen + `/^[0-9a-f]{64}$/`-Gate |

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

`revoked_at IS NULL` ist das Prädikat für aktive Sessions. Soft Delete vermeidet den Verlust der Prüfhistorie.

---

## API-Design

| Methode | Pfad | Header | Beschreibung |
|---|---|---|---|
| `POST` | `/sessions` | `X-User-Id` | Session erstellen |
| `GET` | `/sessions` | `X-User-Id` | Eigene aktive Sessions auflisten |
| `DELETE` | `/sessions/{token}` | `X-User-Id` | Eine Session widerrufen |
| `DELETE` | `/sessions` | `X-User-Id` + `X-Current-Session` | Alle außer der aktuellen widerrufen |

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

`bin2hex(random_bytes(32))` erzeugt 64 Kleinbuchstaben-Hex-Zeichen aus einer kryptografisch sicheren Quelle. Tokens niemals aus Benutzereingaben akzeptieren.

---

## Kernmuster: IDOR-Prävention

```php
// FALSCH — erlaubt jedem authentifizierten Benutzer, jede Session zu widerrufen
UPDATE sessions SET revoked_at = ? WHERE token = ?

// RICHTIG — muss die Session besitzen
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

`rowCount() > 0` gibt `false` zurück, wenn der Token existiert, aber einem anderen Benutzer gehört — der Handler antwortet mit einem generischen 404 (siehe Timing-Oracle-Abschnitt).

---

## Kernmuster: Mass-Assignment-Schutz

```php
// POST /sessions Handler — Angreifer-Body: {"token": "custom", "user_id": 999, "revoked_at": "now"}
private function handleCreate(ServerRequestInterface $request): ResponseInterface
{
    // userId kommt vom X-User-Id-Header — nie vom Body
    $userId = V::userId($request->getHeaderLine('X-User-Id'));

    $body = $this->parseBody($request);

    // Nur sichere, validierte Felder werden weitergegeben
    $deviceName = V::str($body['device_name'] ?? null, 200);
    $ipAddress  = V::str($body['ip_address'] ?? null, 45);

    // token, user_id, created_at, revoked_at vom Repository gesetzt — nicht vom Body
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
        return $this->responseFactory->create(['error' => 'Session not found.'], 404);
    }

    // IDOR-Schutz: revokeForUser gibt false zurück wenn:
    //   - Token existiert nicht
    //   - Token gehört einem anderen Benutzer
    //   - Token ist bereits widerrufen
    // Alle Fälle geben DASSELBE 404 zurück — kein Eigentümerschafts-Oracle
    $revoked = $this->repository->revokeForUser($rawToken, $userId);

    if (!$revoked) {
        return $this->responseFactory->create(['error' => 'Session not found.'], 404);
    }

    return $this->responseFactory->create([], 204);
}
```

Niemals „nicht gefunden" von „falscher Benutzer" in der Antwort unterscheiden. Ein Angreifer, der den Token eines Opfers kennt, darf nicht erfahren, ob dieser aktiv ist oder dem Benutzer gehört.

---

## Kernmuster: Alle außer der aktuellen widerrufen

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
        ['error' => sprintf('limit must be between 1 and %d.', self::MAX_LIMIT)],
        422,
    );
}
```

`V::queryInt()` lehnt negative Zahlen, Floats, Hex-Strings (`0x10`) und Zahlen > 18 Stellen ab.

---

## Routen-Token-Validierung

Das Token-Format immer auf der Routen-Ebene validieren, bevor die DB abgefragt wird:

```php
private const TOKEN_PATTERN = '/^[0-9a-f]{64}$/';

// Im Handler:
if ($rawToken === null || !preg_match(self::TOKEN_PATTERN, $rawToken)) {
    return $this->responseFactory->create(['error' => 'Session not found.'], 404);
}
```

Dies blockiert SQL-Injection-Strings, Path-Traversal-Versuche und kurze/lange Tokens vor jeder Datenbankinteraktion.

---

## Testergebnisse (FT186)

```
54 Tests / 116 Assertions — alle BESTANDEN
PHPStan Level 8 — keine Fehler
PHP CS Fixer — sauber
```

VULN-A~L-Abdeckung:

| Vuln | Muster | Test |
|---|---|---|
| A | limit 19-stelliger Überlauf | `testVulnALimitOverflow19Digits` |
| B | device_name Typ-Verwirrung | `testVulnBDeviceNameAsInteger` |
| C | SQL-Injection im Token | `testVulnCSqlInjectionToken` |
| D | negatives/float/hex limit | `testVulnDNegativeLimitRejected` |
| E | IDOR-Widerrufung | `testVulnECannotRevokeOtherUsersSession` |
| F | ReDoS-artiges langes limit | `testVulnFVeryLongLimitRejected` |
| H | Timing-Oracle | `testVulnHSameResponseForAlreadyRevokedAndCrossUser` |
| I | leerer/kurzer/Traversal-Token | `testVulnIEmptyTokenSegmentNotMatched` |
| L | Mass Assignment | `testVulnLTokenFromBodyIsIgnored` |

Quelle: [`../NENE2-FT/sessionlog/`](https://github.com/hideyukiMORI/NENE2-examples/tree/main/sessionlog)
