# Wunschlisten-API (VULN-A~L-Sicherheitsbewertung)

Dieser Leitfaden demonstriert eine persönliche Wunschlisten-API mit vollständigem CRUD, Admin-Override und Sicherheitshärtung, die VULN-A bis VULN-L abdeckt.

## Muster-Überblick

- Benutzer verwalten private Wunschlisten über `POST /wishes`, `GET /wishes/{id}`, `PATCH /wishes/{id}`, `DELETE /wishes/{id}`.
- `GET /users/{userId}/wishes` listet die Wünsche eines Benutzers auf (nur Eigentümer oder Admin).
- IDOR: Nicht-Eigentümer erhalten immer 404 (nicht 403), um die Ressourcenexistenz nicht zu offenbaren.
- Admins werden durch den `X-Admin-Key`-Header identifiziert; fail-closed wenn Schlüssel leer ist.

## Schema

```sql
CREATE TABLE IF NOT EXISTS wishes (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    title      TEXT    NOT NULL,
    url        TEXT    NOT NULL DEFAULT '',
    priority   INTEGER NOT NULL DEFAULT 0,
    fulfilled  INTEGER NOT NULL DEFAULT 0,
    created_at TEXT    NOT NULL,
    updated_at TEXT    NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_wishes_user ON wishes (user_id, priority DESC, id DESC);
```

## VULN-A: SQL-Injection

Alle Abfragen verwenden PDO-Prepared Statements mit benannten Platzhaltern. Der Titel `'; DROP TABLE wishes; --` wird unverändert gespeichert, ohne Schaden anzurichten:

```php
$this->pdo->prepare(
    'INSERT INTO wishes (user_id, title, ...) VALUES (:uid, :title, ...)'
)->execute([':uid' => $userId, ':title' => $title, ...]);
```

## VULN-B: Mass Assignment

Der `update()`-Handler pflegt eine explizite Feld-Allowlist. Felder wie `user_id`, `created_at` oder `id`, die vom Client gesendet werden, werden stillschweigend ignoriert:

```php
$allowed = ['title', 'url', 'priority', 'fulfilled'];
foreach ($allowed as $field) {
    if (array_key_exists($field, $fields)) { ... }
}
```

## VULN-C: IDOR

Lese- und Löschvorgänge durch Nicht-Eigentümer geben 404 (nicht 403) zurück, um die Ressourcenexistenz zu verbergen:

```php
if (!$isAdmin && (int) $wish['user_id'] !== $uid) {
    return $this->problem(404, 'not-found', 'Wish not found.');
}
```

Der Listen-Endpunkt verbirgt ebenfalls die Listen anderer Benutzer:

```php
if (!$isAdmin && $callerUid !== $targetUid) {
    return $this->problem(404, 'not-found', 'User not found.');
}
```

## VULN-D: Admin-Fail-Closed

Ein leerer `adminKey` gewährt niemals Admin-Rechte. Ohne diesen Guard würde ein unkonfiguriertes Deployment jeden `X-Admin-Key: `-Header als gültig behandeln:

```php
private function isAdmin(ServerRequestInterface $req): bool
{
    if ($this->adminKey === '') {
        return false;
    }
    return hash_equals($this->adminKey, $req->getHeaderLine('X-Admin-Key'));
}
```

## VULN-G: ReDoS

Pfad-Parameter-IDs werden mit `ctype_digit()` statt Regex-Mustern validiert, die anfällig für ReDoS sein könnten:

```php
if (!ctype_digit($raw) || strlen($raw) > 18) {
    return $this->problem(404, 'not-found', 'Wish not found.');
}
```

## VULN-I: Negative Werte

Priorität muss 0–100 sein. Negative Werte und Werte über 100 geben 422 zurück:

```php
if (!is_int($priorityRaw) || $priorityRaw < 0 || $priorityRaw > 100) {
    return $this->problem(422, 'validation-failed', 'priority must be an integer 0–100.');
}
```

## VULN-J: JSON-Typverwechslung

`is_int()` lehnt string-kodierte Zahlen (`"5"`) und Floats (`1.5`) für das `priority`-Feld ab. `is_bool()` lehnt Integer `1`/`0` für `fulfilled` ab:

```php
$p = $body['priority'];
if (!is_int($p) || $p < 0 || $p > 100) { return 422; }

$f = $body['fulfilled'];
if (!is_bool($f)) { return 422; }
```

## Routen

```
POST   /wishes                 Wunsch erstellen (X-User-Id erforderlich)
GET    /wishes/{id}            Wunsch nach ID abrufen (Eigentümer oder Admin)
PATCH  /wishes/{id}            Wunschfelder aktualisieren (nur Eigentümer)
DELETE /wishes/{id}            Wunsch löschen (Eigentümer oder Admin)
GET    /users/{userId}/wishes  Wünsche des Benutzers auflisten (Eigentümer oder Admin)
```

## Validierungsübersicht

| Feld | Regel |
|---|---|
| `X-User-Id` | Erforderlich für POST/PATCH; `ctype_digit`, >0 |
| `title` | Nicht leer, max. 200 Zeichen |
| `url` | Optional, max. 500 Zeichen |
| `priority` | Integer 0–100 (nicht String/Float); Standard 0 |
| `fulfilled` | Nur Boolean (nicht 1/0) bei PATCH |
| `{id}` Pfad | `ctype_digit`, max. 18 Zeichen, >0; sonst 404 |

## Siehe auch

- FT207-Quelle: `../NENE2-FT/wishlistlog/`
- Verwandt: `docs/howto/booking-resource.md` (FT201, auch VULN)
- Verwandt: `docs/howto/coupon-redemption.md` (FT204, VULN + ATK)
