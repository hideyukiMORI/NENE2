# API-Schlüsselverwaltung

> **FT-Referenz**: FT266 (`NENE2-FT/apikeylog`) — API-Schlüssel-Lebenszyklus: Generierung, SHA-256-Hash-Speicherung, Präfix-basierte Suche, Bereichsdurchsetzung, Rotation

Diese Anleitung beschreibt die Implementierung der API-Schlüsselverwaltung in NENE2-Anwendungen: Schlüsselgenerierung, sichere Speicherung, bereichsbasierte Autorisierung, Widerruf und Rotation.

## Kerndesignprinzipien

1. **Niemals rohe Schlüssel speichern** — nur SHA-256-Hashes in der Datenbank.
2. **Den rohen Schlüssel einmal zurückgeben** — nur zum Erstellungszeitpunkt, nie wieder.
3. **Präfix-basierte Suche, Hash-basierte Verifizierung** — das Präfix schränkt die DB-Abfrage ein; hash_equals() führt die eigentliche Authentifizierung durch.
4. **Bereichshierarchie** — admin ⊃ write ⊃ read; pro Endpunkt geprüft.
5. **Sicher rotieren** — neuen Schlüssel erstellen, bevor der alte widerrufen wird, um Aussperrungen zu verhindern.

## Schlüsselformat

```
nk_Vf3aB2cX9dJkQmHpNrTsUvWxYzAeBfCg
^   ^----- 43 Zeichen base64url(32 zufällige Bytes) -----^
|
Typ-Präfix (in Logs erkennbar)
```

`random_bytes(32)` liefert 256 Bit Entropie. Dies ist rechnerisch nicht per Brute-Force angreifbar, unabhängig von der Hash-Geschwindigkeit, weshalb SHA-256 (schnell, einzweckig) geeignet ist — im Gegensatz zu Passwörtern sind API-Schlüssel nicht per Wörterbuchangriff angreifbar.

## Schema

```sql
CREATE TABLE IF NOT EXISTS api_keys (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    owner_id    INTEGER NOT NULL,
    prefix      TEXT    NOT NULL,     -- erste 16 Zeichen des rohen Schlüssels (Suchindex)
    key_hash    TEXT    NOT NULL UNIQUE,
    scope       TEXT    NOT NULL DEFAULT 'read',
    description TEXT    NOT NULL DEFAULT '',
    expires_at  TEXT,
    revoked_at  TEXT,
    created_at  TEXT    NOT NULL,
    updated_at  TEXT    NOT NULL
);
```

Die `prefix`-Spalte speichert die **ersten 16 Zeichen des rohen Schlüssels** (nicht das Typ-Präfix `nk`). Dies bietet ~78 Bit Differenzierung, sodass jedes Präfix effektiv eindeutig ist und eine O(1)-Index-Suche ermöglicht.

**Wichtig**: NICHT das Typ-Präfix (`nk`) als DB-Suchpräfix verwenden. Alle Schlüssel haben dasselbe Typ-Präfix, sodass `WHERE prefix = 'nk'` die gesamte Tabelle scannt — O(n)-Suche und ein Timing-Kanal proportional zur Anzahl der Schlüssel.

## Schlüsselgenerierung

```php
final class ApiKeyGenerator
{
    private const string PREFIX = 'nk';
    private const int    BYTES  = 32;

    public function generate(): string
    {
        $raw = random_bytes(self::BYTES);
        return self::PREFIX . '_' . rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    public function hash(string $rawKey): string
    {
        return hash('sha256', $rawKey);
    }

    public function extractPrefix(string $rawKey): string
    {
        // Erste 16 Zeichen des vollständigen Schlüssels — eindeutig pro Schlüssel, sicher zu indizieren
        return substr($rawKey, 0, 16);
    }

    public function verify(string $rawKey, string $storedHash): bool
    {
        return hash_equals($storedHash, $this->hash($rawKey));
    }
}
```

`hash_equals()` ist obligatorisch. Die Verwendung von `===` oder `==` für Hash-Vergleiche gibt Timing-Informationen preis: Ein 64-Zeichen-Hex-String, der mit `===` verglichen wird, wird beim ersten Nichtübereinstimmen beendet und verrät, wie viele führende Zeichen übereinstimmen.

## Authentifizierungsablauf

```php
public function authenticate(string $rawKey, string $now): ?ApiKey
{
    $prefix = $this->generator->extractPrefix($rawKey);

    $rows = $this->executor->fetchAll(
        'SELECT * FROM api_keys WHERE prefix = ?',
        [$prefix],
    );

    foreach ($rows as $row) {
        $key = $this->hydrate($row);
        if ($this->generator->verify($rawKey, $key->keyHash) && $key->isActive($now)) {
            return $key;
        }
    }

    return null;
}
```

Der zweistufige Ansatz:
1. Index-Suche nach Präfix (schnelle DB-Abfrage)
2. `hash_equals()`-Verifizierung gegen gespeicherten Hash

Für alle Fehlerfälle (nicht gefunden, falscher Hash, abgelaufen, widerrufen) das gleiche `null` und `401` zurückgeben — Aufrufer dürfen nicht zwischen ihnen unterscheiden.

## Bereichshierarchie

```php
enum ApiKeyScope: string
{
    case Read  = 'read';
    case Write = 'write';
    case Admin = 'admin';

    public function allows(self $required): bool
    {
        return match ($required) {
            self::Read  => true,
            self::Write => $this === self::Write || $this === self::Admin,
            self::Admin => $this === self::Admin,
        };
    }
}
```

Bereich auf Endpunkt-Ebene durchsetzen:

```php
private function requireScope(ServerRequestInterface $request, ApiKeyScope $required): ApiKey|ResponseInterface
{
    $rawKey = $request->getHeaderLine('X-Api-Key');
    if ($rawKey === '') {
        return $this->problems->create($request, 'unauthorized', 'Missing X-Api-Key header.', 401, '');
    }

    $key = $this->repo->authenticate($rawKey, $now);
    if ($key === null) {
        return $this->problems->create($request, 'unauthorized', 'Invalid or expired API key.', 401, '');
    }

    if (!$key->scope->allows($required)) {
        return $this->problems->create($request, 'forbidden', 'Insufficient scope.', 403, '');
    }

    return $key;
}
```

`401` für nicht authentifiziert, `403` für authentifiziert aber unzureichenden Bereich zurückgeben — niemals preisgeben, ob der Schlüssel existiert.

## Antwortfilterung

Die `toArray()`-Methode auf `ApiKey` darf `key_hash` **nicht** einschließen. Der rohe Schlüssel ist nur über `ApiKeyCreateResult::toArray()` direkt nach der Erstellung verfügbar.

```php
// ApiKey::toArray() — sicher von jedem Endpunkt zurückzugeben
public function toArray(): array
{
    return [
        'id', 'owner_id', 'prefix', 'scope', 'description',
        'expires_at', 'revoked_at', 'created_at', 'updated_at',
        // key_hash ist absichtlich nicht vorhanden
    ];
}

// ApiKeyCreateResult::toArray() — nur für den Erstellungs-Endpunkt
public function toArray(): array
{
    return array_merge($this->key->toArray(), ['key' => $this->rawKey]);
}
```

## Schlüsselrotation — sichere Reihenfolge

**Immer zuerst den neuen Schlüssel erstellen, bevor der alte widerrufen wird.**

```php
public function rotate(int $oldId, int $ownerId, string $now): ?ApiKeyCreateResult
{
    $old = $this->findById($oldId);
    if ($old === null || $old->ownerId !== $ownerId || $old->isRevoked()) {
        return null;
    }

    // Zuerst erstellen — wenn dies fehlschlägt, bleibt der alte Schlüssel aktiv (keine Aussperrung)
    $result = $this->create($ownerId, $old->scope, $old->description, $now, $old->expiresAt);

    // Danach widerrufen — wenn dies fehlschlägt, existieren beide Schlüssel vorübergehend (über Liste wiederherstellbar)
    $this->executor->execute(
        'UPDATE api_keys SET revoked_at = ?, updated_at = ? WHERE id = ?',
        [$now, $now, $oldId],
    );

    return $result;
}
```

Widerrufen-dann-Erstellen ist gefährlich: Wenn CREATE nach REVOKE fehlschlägt, ist der Besitzer dauerhaft ausgesperrt. Das Umgekehrte (Erstellen-dann-Widerrufen) bedeutet, dass im schlimmsten Fall vorübergehend zwei aktive Schlüssel existieren — beobachtbar und wiederherstellbar.

## Ablauf

`expires_at` als ISO-Datetime-String speichern. In `isActive()` prüfen:

```php
public function isActive(string $now): bool
{
    return !$this->isRevoked() && !$this->isExpired($now);
}

public function isExpired(string $now): bool
{
    return $this->expiresAt !== null && $this->expiresAt < $now;
}
```

Der Authentifizierungsablauf übergibt `$now` als Parameter, wodurch die Logik mit festen Zeitstempeln testbar wird.

## Was Sie NICHT tun sollten

| Anti-Pattern | Risiko |
|---|---|
| Rohen Schlüssel in DB speichern | Vollständige Offenlegung bei DB-Verletzung |
| `===` für Hash-Vergleich verwenden | Timing-Angriff gibt Hash-Präfix-Länge preis |
| Typ-Präfix (`nk`) als DB-Suchindex verwenden | O(n)-Tabellenscan; Timing-Kanal |
| `key_hash` in Listen-/Detail-Antworten zurückgeben | Offline-Wörterbuchangriff auf Hashes |
| Alten Schlüssel vor dem neuen bei der Rotation widerrufen | Besitzeraussperrung bei DB-Fehler |
| Verschiedene Fehler für "Schlüssel nicht gefunden" vs. "Schlüssel abgelaufen" zurückgeben | Oracle für Schlüsselexistenz |
| `X-Api-Key`-Header protokollieren | Schlüssel gelangt in Log-Speicher |
