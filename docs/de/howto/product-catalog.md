# How-to: Produktkatalog-API (ATK-01 bis 12)

Diese Anleitung demonstriert eine Produktkatalog-API mit reinen Admin-Schreiboperationen, Stichwortsuche und Soft Delete — mit Abdeckung der ATK-01 bis 12 Cracker-Angriffsvektoren.

## Musterübersicht

- Kataloglesen ist öffentlich; Schreibvorgänge (erstellen, löschen) erfordern Admin (`X-Admin-Key`).
- SKUs sind alphanumerische Großbuchstaben mit Bindestrichen (`/\A[A-Z0-9\-]{1,32}\z/`).
- Soft Delete (`active = 0`) verbirgt Produkte ohne Verlust der Historie.
- Stichwortsuche verwendet `LIKE` mit Längenbegrenzung, um Keyword-Bomben zu verhindern.

## Schema

```sql
CREATE TABLE IF NOT EXISTS products (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    sku         TEXT    NOT NULL UNIQUE,
    name        TEXT    NOT NULL,
    description TEXT    NOT NULL DEFAULT '',
    price_cents INTEGER NOT NULL,
    active      INTEGER NOT NULL DEFAULT 1,
    created_at  TEXT    NOT NULL
);
```

## ATK-01: SQL-Injection in der Such-Schlüsselwort

```php
$kw   = '%' . $keyword . '%';
$stmt = $this->pdo->prepare(
    'SELECT * FROM products WHERE active = 1 AND (name LIKE :kw OR ...) LIMIT :lim OFFSET :off'
);
$stmt->bindValue(':kw', $kw, PDO::PARAM_STR);
```

Der `%`-Platzhalter ist Teil des Literalwerts, der an eine parametrisierte Abfrage übergeben wird — keine Interpolation findet statt.

## ATK-02: Admin Fail-Closed

```php
private function isAdmin(ServerRequestInterface $req): bool
{
    if ($this->adminKey === '') {
        return false;  // leerer Admin-Key → immer 403
    }
    return hash_equals($this->adminKey, $req->getHeaderLine('X-Admin-Key'));
}
```

Leerer Admin-Key → immer 403. Falscher Key → `hash_equals()` verhindert Timing-Lecks.

## ATK-03: Integer-Überlauf bei Produkt-ID

```php
if (!ctype_digit($raw) || strlen($raw) > 18) {
    return null;  // → 404
}
```

Ein 20-stelliger ID-String überschreitet 18 Zeichen und wird abgelehnt, bevor ein `(int)`-Cast oder eine DB-Abfrage erfolgt.

## ATK-04: Negative ID

`ctype_digit()` auf `-1` schlägt fehl (Nicht-Ziffer-Zeichen) → 404.

## ATK-05: Float-Preis

```php
if (!is_int($priceCents) || $priceCents < 0) {
    return $this->problem(422, ...);
}
```

`is_int(9.99)` gibt `false` zurück — Float-Preise werden abgelehnt.

## ATK-06: SKU-Injection

Der SKU-Regex `/\A[A-Z0-9\-]{1,32}\z/` lehnt `; DROP TABLE`, Anführungszeichen, Leerzeichen und Kleinbuchstaben ab. Nur das exakte Format wird akzeptiert.

## ATK-07: Wildcard-Suchinjection

`%` in einem Suchwort wird als SQL-LIKE-Platzhalter behandelt — es passt auf alles. Das ist beabsichtigt (Benutzer können alles suchen). Das LIKE ist parametrisiert, sodass `%; DROP TABLE products; --` nicht als SQL ausgeführt wird:

```sql
WHERE name LIKE '%%; DROP TABLE products; --%'
```

Das Ergebnis ist nur ein breiteres LIKE-Match, keine Injection.

## ATK-08: Doppeltes Löschen

Das `delete()` des Repository prüft zunächst `findById()` (nur active=1). Ein soft-gelöschtes Produkt gibt null zurück → 404 beim zweiten Löschversuch.

## ATK-09: SKU zu lang

Der Regex-Quantor `{1,32}` lehnt SKUs mit mehr als 32 Zeichen ab, bevor die DB erreicht wird.

## ATK-10: Falscher Admin-Key

Der `hash_equals()`-Vergleich benötigt immer gleich viel Zeit, unabhängig davon, wie viele Zeichen übereinstimmen.

## Schlüsselwort-Längenguard

```php
if ($keyword !== null && strlen($keyword) > 100) {
    return $this->problem(422, 'validation-failed', 'q zu lang (max 100).');
}
```

Verhindert das Senden eines 10 MB großen LIKE-Musters an die Datenbank.

## Soft Delete

```php
$this->pdo->prepare('UPDATE products SET active = 0 WHERE id = :id')->execute([':id' => $id]);
```

Alle Lesevorgänge schließen `WHERE active = 1` ein. Gelöschte Produkte werden unsichtbar, ohne physisch entfernt zu werden.

## Routen

```
POST   /products      Produkt erstellen (nur Admin)
GET    /products      Produkte auflisten/suchen (öffentlich)
GET    /products/{id} Produkt abrufen (öffentlich)
DELETE /products/{id} Produkt soft-löschen (nur Admin)
```

## Siehe auch

- FT212-Quelle: `../NENE2-FT/productlog/`
- Verwandt: `docs/howto/inventory-management.md` (FT203, SKU-basierter Bestand)
- Verwandt: `docs/howto/session-token-management.md` (FT208, auch ATK)
