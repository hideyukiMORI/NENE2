# How-To: Asset-Ausleihe / -Rückgabe-Verwaltung

Demonstriert die exklusive Asset-Haltungsverfolgung mit einem Append-only-Prüfprotokoll.
Field Trial: FT194 (`../NENE2-FT/assetlog/`).

---

## Muster-Zusammenfassung

| Aspekt | Ansatz |
|---|---|
| Exklusives Halten | `holder_id INTEGER` — NULL = verfügbar, nicht-null = gehalten |
| Ausleih-Konflikt | 409 wenn `holder_id IS NOT NULL` vor dem Aktualisieren |
| Falsche-Halter-Rückgabe | 403 wenn `holder_id != userId` |
| Prüfprotokoll | Append-only `asset_history`-Zeilen bei jeder Zustandsänderung |
| IDOR-Verhinderung | Öffentliche API verbirgt `holder_id`; Admin-Schlüssel erforderlich zum Anzeigen |
| Admin-Schlüssel | `hash_equals()`-Vergleich in konstanter Zeit, fail-closed bei leerem Schlüssel |
| Benutzeridentität | `X-User-Id`-Header; `ctype_digit()` + Längenschutz, kein Regex |

---

## Routen

| Methode | Pfad | Auth | Beschreibung |
|---|---|---|---|
| `POST` | `/assets` | `X-Admin-Key` | Asset erstellen |
| `GET` | `/assets` | — | Alle Assets auflisten |
| `GET` | `/assets/{id}` | — | Einzelnes Asset abrufen |
| `POST` | `/assets/{id}/checkout` | `X-User-Id` | Asset ausleihen |
| `POST` | `/assets/{id}/checkin` | `X-User-Id` | Asset zurückgeben |
| `GET` | `/assets/{id}/history` | — | Prüfprotokoll |

---

## Datenbankschema

```sql
CREATE TABLE assets (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT    NOT NULL,
    holder_id  INTEGER,           -- NULL = verfügbar
    created_at TEXT    NOT NULL,
    updated_at TEXT    NOT NULL
);

CREATE TABLE asset_history (
    id       INTEGER PRIMARY KEY AUTOINCREMENT,
    asset_id INTEGER NOT NULL,
    user_id  INTEGER NOT NULL,
    action   TEXT    NOT NULL,   -- 'checkout' | 'checkin'
    acted_at TEXT    NOT NULL,
    FOREIGN KEY (asset_id) REFERENCES assets(id) ON DELETE CASCADE
);
```

---

## Exklusives Ausleih-Muster

```php
public function checkout(int $assetId, int $userId): string
{
    $asset = $this->findById($assetId);
    if ($asset === null) return 'not_found';
    if (!$asset->isAvailable()) return 'unavailable';   // 409

    $now = $this->now();
    $this->pdo->prepare(
        'UPDATE assets SET holder_id = :uid, updated_at = :now WHERE id = :id AND holder_id IS NULL'
    )->execute([...]);

    $this->appendHistory($assetId, $userId, 'checkout', $now);
    return 'success';
}
```

Der `WHERE holder_id IS NULL`-Guard verhindert Doppelausleihen selbst bei gleichzeitigen Anfragen
(SQLite serialisiert Schreibvorgänge; MySQL/PgSQL benötigen eine Transaktion oder `SELECT FOR UPDATE`).

---

## IDOR-Verhinderung

```php
// Öffentliche Antwort — kein holder_id
public function toPublicArray(): array
{
    return ['id' => $this->id, 'name' => $this->name, 'available' => $this->isAvailable(), ...];
}

// Admin-Antwort — enthält holder_id
public function toAdminArray(): array
{
    return [..., 'holder_id' => $this->holderId];
}
```

Der Handler prüft `isAdmin()` und wählt die richtige Projektion:

```php
fn (Asset $a) => $isAdmin ? $a->toAdminArray() : $a->toPublicArray()
```

---

## Admin-Schlüssel (fail-closed)

```php
private function isAdmin(ServerRequestInterface $request): bool
{
    if ($this->adminKey === '') return false;   // kein Schlüssel konfiguriert → verweigern
    $provided = $request->getHeaderLine('X-Admin-Key');
    return $provided !== '' && hash_equals($this->adminKey, $provided);
}
```

---

## Benutzer-ID-Validierung

```php
private function resolveUserId(ServerRequestInterface $request): ?int
{
    $raw = $request->getHeaderLine('X-User-Id');
    if ($raw === '' || !ctype_digit($raw) || strlen($raw) > 18) return null;
    $id = (int) $raw;
    return $id > 0 ? $id : null;
}
```

`ctype_digit()` ist O(n) und ReDoS-sicher. Längenbegrenzung verhindert Integer-Überlauf.

---

## Fehler-Mapping

| Repository-Ergebnis | HTTP-Status |
|---|---|
| `success` | 200 / 201 |
| `not_found` | 404 |
| `unavailable` | 409 Conflict |
| `not_holder` | 403 Forbidden |
| `already_available` | 409 Conflict |

---

## Test-Hinweise

- `AppFactory::create(?PDO, ?string)` akzeptiert In-Memory-SQLite für Unit-Tests.
- `withParsedBody($body)` muss für Test-Anfragen aufgerufen werden — Nyholm PSR-7 parst JSON nicht automatisch.
- Öffentliche Listen-/Abruf-Assertions prüfen, dass der `holder_id`-Schlüssel fehlt (`assertArrayNotHasKey`).
- Lebenszyklustest: Ausleihen → Konflikt → Rückgabe → Erneutes Ausleihen durch anderen Benutzer.
