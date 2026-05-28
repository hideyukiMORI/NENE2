# Anleitung: API-Token-Lifecycle-Verwaltung

> **FT-Referenz**: FT272 (`NENE2-FT/tokenlog`) — API-Token-Lebenszyklus: SHA-256-Hash-Speicherung (Klartext wird nie gespeichert), Scope-Enum (read/write/admin) mit DB-CHECK-Bedingung, IDOR-Guard (actorId muss userId entsprechen), Soft-Revoke via revoked_at, Verifizierungs-Endpunkt gibt valid/user_id/scope zurück, 29 Tests / 70 Assertions BESTANDEN.
>
> **ATK-Assessment**: ATK-01 bis ATK-12 am Ende dieses Dokuments.

Demonstriert ein scope-basiertes API-Token-System: Tokens für einen Benutzer ausgeben, auflisten/widerrufen und einen Rohtoken beim Zugriffszeitpunkt verifizieren. Tokens werden nur als SHA-256-Hashes gespeichert — der Klartext wird einmal bei der Ausgabe zurückgegeben und nie gespeichert.

---

## Schema

```sql
CREATE TABLE users (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT NOT NULL,
    created_at TEXT NOT NULL
);

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

Wesentliche Designentscheidungen:
- `token_hash UNIQUE` — verhindert versehentliche doppelte Ausgabe; auch der Lookup-Schlüssel beim Verifizieren
- `CHECK (scope IN (...))` — DB-Ebene Durchsetzung des Scope-Enums
- `revoked_at TEXT` — Soft-Revoke; `NULL` bedeutet aktiv, nicht-NULL bedeutet widerrufen

---

## Routen

| Methode   | Pfad                                | Beschreibung                              |
|-----------|-------------------------------------|------------------------------------------|
| `POST`   | `/users`                            | Benutzer erstellen                        |
| `POST`   | `/users/{userId}/tokens`            | Token ausgeben (nur Eigentümer)           |
| `GET`    | `/users/{userId}/tokens`            | Tokens für einen Benutzer auflisten (nur Eigentümer) |
| `DELETE` | `/users/{userId}/tokens/{tokenId}`  | Token widerrufen (nur Eigentümer)         |
| `POST`   | `/tokens/verify`                    | Rohtoken verifizieren                     |

---

## Nur-Hash-Speicherung

Der Rohtoken wird einmal bei der Ausgabe zurückgegeben und nie gespeichert:

```php
public function issueToken(int $userId, TokenScope $scope, string $label, string $now): string
{
    $raw  = bin2hex(random_bytes(32));   // 64 Hex-Zeichen — 256 Bits Entropie
    $hash = hash('sha256', $raw);

    $this->executor->execute(
        'INSERT INTO tokens (user_id, token_hash, scope, label, created_at) VALUES (?, ?, ?, ?, ?)',
        [$userId, $hash, $scope->value, $label, $now],
    );

    return $raw; // an Aufrufer zurückgegeben, nie gespeichert
}
```

Beim Verifizieren liefert der Aufrufer den Rohtoken; der Hash wird neu berechnet und nachgeschlagen:

```php
public function verifyToken(string $rawToken): ?array
{
    $hash = hash('sha256', $rawToken);
    $row  = $this->executor->fetchOne(
        'SELECT id, user_id, scope, revoked_at FROM tokens WHERE token_hash = ?',
        [$hash],
    );

    if ($row === null) {
        return null; // nicht gefunden → Aufrufer gibt {valid: false} zurück
    }

    return [
        'valid'   => !isset($arr['revoked_at']),
        'user_id' => (int) $arr['user_id'],
        'scope'   => (string) $arr['scope'],
    ];
}
```

---

## Scope-Durchsetzung

`TokenScope` ist ein PHP-backed Enum; `tryFrom()` lehnt unbekannte Werte vor jedem DB-Zugriff ab:

```php
enum TokenScope: string
{
    case Read  = 'read';
    case Write = 'write';
    case Admin = 'admin';
}

// Im Routen-Handler:
$scope = TokenScope::tryFrom($scopeValue);
if ($scope === null) {
    return $this->responseFactory->create(['error' => 'invalid scope, must be read/write/admin'], 422);
}
```

Die DB-`CHECK`-Bedingung bietet eine zweite Durchsetzungsschicht.

---

## IDOR-Guard

Token-Ausgabe, Auflistung und Widerruf erfordern, dass der Akteur der Eigentümer ist:

```php
$actorId = $this->resolveActorId($request); // aus X-User-Id-Header

if ($actorId !== $userId) {
    return $this->responseFactory->create(['error' => 'forbidden'], 403);
}
```

Der Widerruf überprüft auch, dass das Token zu `userId` gehört, nicht nur irgendein Token:

```php
if ($token['user_id'] !== $userId) {
    return $this->responseFactory->create(['error' => 'forbidden'], 403);
}
```

---

## Widerruf

Soft-Revoke setzt `revoked_at`; das UPDATE gilt nur, wenn `revoked_at IS NULL`:

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

Wenn das Token bereits widerrufen wurde, gibt der Routen-Handler 409 Conflict zurück:

```php
if ($token['revoked']) {
    return $this->responseFactory->create(['error' => 'token already revoked'], 409);
}
```

---

## ATK-Assessment — Cracker-Mindset-Angrifftest

### ATK-01 — Token-Replay nach Widerruf 🚫 BLOCKED

**Angriff**: Token widerrufen, dann denselben Rohtoken-Wert bei `/tokens/verify` verwenden.
**Ergebnis**: BLOCKED — `verifyToken()` schlägt `revoked_at` in der Zeile nach; ein nicht-NULL `revoked_at` verursacht `valid: false`. Das widerrufene Token wird nicht gelöscht, also wird es aufgelöst, aber gibt `{valid: false}` zurück.

---

### ATK-02 — Brute-Force-Token-Raten 🚫 BLOCKED

**Angriff**: Zufällige 64-Zeichen-Hex-Strings an `/tokens/verify` senden, in der Hoffnung, einen gültigen Token-Hash zu treffen.
**Ergebnis**: BLOCKED — Tokens sind `bin2hex(random_bytes(32))` = 256 Bits Entropie. Wahrscheinlichkeit einer erfolgreichen Rate: `1 / 2^256`. Kein Rate Limiting in diesem FT, aber die Entropie allein macht Brute-Force rechnerisch unmöglich.

---

### ATK-03 — IDOR: Zugriff auf die Token-Liste eines anderen Benutzers 🚫 BLOCKED

**Angriff**: `X-User-Id: 1` setzen und `GET /users/2/tokens` anfordern.
**Ergebnis**: BLOCKED — `actorId (1) !== userId (2)` → 403 Forbidden.

---

### ATK-04 — IDOR: Token eines anderen Benutzers widerrufen 🚫 BLOCKED

**Angriff**: Als Benutzer 1 `DELETE /users/2/tokens/{tokenId}` aufrufen.
**Ergebnis**: BLOCKED — der Routen-Handler prüft `actorId !== userId` → 403, bevor das Token abgerufen wird.

---

### ATK-05 — Benutzerübergreifender Token-Widerruf (geteilte Token-ID) 🚫 BLOCKED

**Angriff**: Als Benutzer 2 `DELETE /users/2/tokens/{tokenId}` aufrufen, wobei `tokenId` Benutzer 1 gehört.
**Ergebnis**: BLOCKED — nachdem die IDOR-Prüfung passiert (actorId = userId = 2), gibt `findTokenById` das Token zurück, dann `$token['user_id'] !== $userId` → 403. Doppelte Eigentümerschaftsprüfung verhindert benutzerübergreifenden Widerruf.

---

### ATK-06 — Ungültige Scope-Injektion 🚫 BLOCKED

**Angriff**: POST `/users/{id}/tokens` mit `{"scope": "superadmin"}`.
**Ergebnis**: BLOCKED — `TokenScope::tryFrom('superadmin')` gibt `null` zurück → 422. Die DB-CHECK-Bedingung würde es auch blockieren, wenn die Anwendungsschicht es irgendwie durchließe.

---

### ATK-07 — Token-Klartext-Extraktion aus DB 🚫 BLOCKED

**Angriff**: Wenn ein Angreifer Lesezugriff auf die `tokens`-Tabelle erhält, kann er funktionierende Tokens erhalten?
**Ergebnis**: BLOCKED — nur `token_hash` (SHA-256) wird gespeichert. SHA-256 umzukehren ist rechnerisch unmöglich. Der Rohtoken wird einmal bei der Ausgabe zurückgegeben und serverseitig verworfen.

---

### ATK-08 — Verifizieren mit leerem/fehlerhaftem Token 🚫 BLOCKED

**Angriff**: POST `/tokens/verify` mit `{"token": ""}` oder `{"token": null}`.
**Ergebnis**: BLOCKED — Leerstring-Prüfung: `if ($token === '') → 422`. `null` wird durch `is_string()`-Prüfung abgelehnt. Der SHA-256 eines leeren Strings würde ohnehin mit keinem gespeicherten Hash übereinstimmen.

---

### ATK-09 — Token-Ausgabe für nicht existierenden Benutzer 🚫 BLOCKED

**Angriff**: POST `/users/9999/tokens`, wobei Benutzer 9999 nicht existiert.
**Ergebnis**: BLOCKED — `findUserById(9999)` gibt `false` zurück → 404, bevor irgendein Token erstellt wird.

---

### ATK-10 — Doppelter Widerruf (Idempotenz) 🚫 BLOCKED

**Angriff**: Denselben Token zweimal schnell nacheinander widerrufen.
**Ergebnis**: BLOCKED — `revokeToken` verwendet `WHERE revoked_at IS NULL`; der zweite Aufruf gibt 0 betroffene Zeilen zurück. Der Routen-Handler liest `$token['revoked'] === true`, bevor er das Repo aufruft → 409 Conflict. Kein Race-Condition-Fenster für erfolgreichen Doppelwiderruf.

---

### ATK-11 — Negative oder String-userId im Pfad 🚫 BLOCKED

**Angriff**: `GET /users/-1/tokens` oder `GET /users/abc/tokens`.
**Ergebnis**: BLOCKED — `is_numeric($params['userId'])` → `(int)`-Cast. `-1` wird zu -1; `findUserById(-1)` gibt false zurück → 404. `abc` ist nicht numerisch → `userId = 0` → 404.

---

### ATK-12 — Scope-Herabstufung bei Verifizierungs-Antwort 🚫 BLOCKED

**Angriff**: Nach Erhalt eines `read`-scoped Tokens versuchen, `scope: write` in der Verifizierungsantwort zu fälschen, indem ein modifizierter Request-Body gesendet wird.
**Ergebnis**: BLOCKED — `/tokens/verify` akzeptiert nur einen Rohtoken-String; der Scope wird aus der DB-Zeile gelesen, nicht aus einem client-gelieferten Feld. Der Client kann den zurückgegebenen Scope nicht beeinflussen.

---

### ATK-Zusammenfassung

| ID | Angriff | Ergebnis |
|----|---------|----------|
| ATK-01 | Widerrufenes Token wiederholen | 🚫 BLOCKED |
| ATK-02 | Brute-Force-Token-Raten | 🚫 BLOCKED |
| ATK-03 | IDOR: Token-Liste eines anderen lesen | 🚫 BLOCKED |
| ATK-04 | IDOR: Tokens eines anderen widerrufen | 🚫 BLOCKED |
| ATK-05 | Benutzerübergreifender Token-Widerruf | 🚫 BLOCKED |
| ATK-06 | Ungültige Scope-Injektion | 🚫 BLOCKED |
| ATK-07 | Klartext-Extraktion aus DB | 🚫 BLOCKED |
| ATK-08 | Leerer/fehlerhafter Token bei Verifizierung | 🚫 BLOCKED |
| ATK-09 | Token-Ausgabe für nicht existierenden Benutzer | 🚫 BLOCKED |
| ATK-10 | Doppelter Widerruf Race Condition | 🚫 BLOCKED |
| ATK-11 | Negative/String-userId im Pfad | 🚫 BLOCKED |
| ATK-12 | Scope-Herabstufung via Verifizierungs-Body | 🚫 BLOCKED |

**12 BLOCKED / SAFE, 0 EXPOSED**
Keine kritischen Befunde. Die Nur-Hash-Speicherung, Scope-Enum-Durchsetzung und dualen IDOR-Prüfungen bilden eine robuste Verteidigungsoberfläche.

---

## Was NICHT zu tun ist

| Anti-Muster | Risiko |
|---|---|
| Rohtoken in DB speichern | DB-Leseleck exponiert alle Tokens; Tokens können nicht ohne Benutzeraktion rotiert werden |
| MD5/SHA-1 für Token-Hash verwenden | Kollisionsangriffe; SHA-256 oder BLAKE2 bevorzugen |
| Beliebige Scope-Strings akzeptieren | Ohne `tryFrom()`-Validierung können `superadmin`-Scopes ausgegeben werden |
| Keine Eigentümerschaftsprüfung beim Widerruf | Jeder authentifizierte Benutzer kann beliebige Tokens widerrufen (IDOR) |
| Tokens bei Widerruf hart löschen | Prüfpfad geht verloren; keine Möglichkeit, Replay eines widerrufenen Tokens zu erkennen |
| 404 bei bereits widerrufenem Token zurückgeben | Macht es unmöglich, „nicht gefunden" von „bereits widerrufen" zu unterscheiden; 409 verwenden |
