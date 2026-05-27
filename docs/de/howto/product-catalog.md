# Anleitung: Produktkatalog-API (ATK-01~12)

Diese Anleitung demonstriert eine Produktkatalog-API mit ausschließlich für Admins zugänglichen Schreiboperationen, Stichwortsuche und Soft Delete — und deckt die ATK-01~12-Cracker-Angriffsvektoren ab.

## Muster-Überblick

- Katalog-Lesevorgänge sind öffentlich; Schreibvorgänge (Erstellen, Löschen) erfordern Admin (`X-Admin-Key`).
- SKUs sind großgeschriebene alphanumerische Zeichen mit Bindestrichen (`/\A[A-Z0-9\-]{1,32}\z/`).
- Soft Delete (`active = 0`) versteckt Produkte, ohne die Historie zu verlieren.
- Die Stichwortsuche verwendet `LIKE` mit Längenbegrenzung, um Keyword-Bombs zu verhindern.

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

## ATK-01: SQL-Injection im Suchstichwort

```php
$kw   = '%' . $keyword . '%';
$stmt = $this->pdo->prepare(
    'SELECT * FROM products WHERE active = 1 AND (name LIKE :kw OR ...) LIMIT :lim OFFSET :off'
);
$stmt->bindValue(':kw', $kw, PDO::PARAM_STR);
```

Das `%`-Wildcard ist Teil des Literalwerts, der an eine parametrisierte Abfrage übergeben wird — es findet keine Interpolation statt.

## ATK-02: Admin Fail-Closed

```php
private function isAdmin(ServerRequestInterface $req): bool
{
    if ($this->adminKey === '') {
        return false;
    }
    return hash_equals($this->adminKey, $req->getHeaderLine('X-Admin-Key'));
}
```

Leerer Admin-Key → immer 403. Falscher Key → `hash_equals()` verhindert Timing-Leaks.

## ATK-03: Integer-Überlauf bei der Produkt-ID

```php
if (!ctype_digit($raw) || strlen($raw) > 18) {
    return null;  // → 404
}
```

Ein 20-stelliger ID-String überschreitet 18 Zeichen und wird vor jedem `(int)`-Cast oder DB-Abfrage abgelehnt.

## ATK-04: Negative ID

`ctype_digit()` schlägt bei `-1` fehl (Nicht-Ziffer-Zeichen) → 404.

## ATK-05: Fließkommazahl als Preis

```php
if (!is_int($priceCents) || $priceCents < 0) {
    return $this->problem(422, ...);
}
```

`is_int(9.99)` gibt `false` zurück — Fließkommazahl-Preise werden abgelehnt.

## ATK-06: SKU-Injection

Der SKU-Regex `/\A[A-Z0-9\-]{1,32}\z/` lehnt `; DROP TABLE`, Anführungszeichen, Leerzeichen und Kleinbuchstaben ab. Nur das exakte Format wird akzeptiert.

## ATK-07: Wildcard-Suche-Injection

`%` in einem Suchstichwort wird als SQL-LIKE-Wildcard behandelt — es trifft alles. Dies ist absichtlich (Benutzer können alles suchen). Das LIKE ist parametrisiert, sodass `%; DROP TABLE products; --` nicht als SQL ausgeführt wird:

```sql
WHERE name LIKE '%%; DROP TABLE products; --%'
```

Das Ergebnis ist nur ein breiterer LIKE-Treffer, keine Injection.

## ATK-08: Doppeltes Löschen

Die `delete()`-Methode des Repositorys prüft zuerst `findById()` (nur active=1). Ein soft-gelöschtes Produkt gibt null zurück → 404 beim zweiten Löschversuch.

## ATK-09: SKU zu lang

Der Regex-Quantor `{1,32}` lehnt SKUs mit mehr als 32 Zeichen ab, bevor die DB erreicht wird.

## ATK-10: Falscher Admin-Key

Der `hash_equals()`-Vergleich benötigt immer dieselbe Zeit, unabhängig davon, wie viele Zeichen übereinstimmen.

## Stichwort-Längenbegrenzung

```php
if ($keyword !== null && strlen($keyword) > 100) {
    return $this->problem(422, 'validation-failed', 'q too long (max 100).');
}
```

Verhindert das Senden eines 10 MB großen LIKE-Musters an die Datenbank.

## Soft Delete

```php
$this->pdo->prepare('UPDATE products SET active = 0 WHERE id = :id')->execute([':id' => $id]);
```

Alle Lesevorgänge enthalten `WHERE active = 1`. Gelöschte Produkte werden unsichtbar, ohne physisch entfernt zu werden.

## Routen

```
POST   /products      Produkt erstellen (nur Admin)
GET    /products      Produkte auflisten/suchen (öffentlich)
GET    /products/{id} Produkt abrufen (öffentlich)
DELETE /products/{id} Produkt soft-löschen (nur Admin)
```

## Siehe auch

- FT212-Quellcode: `../NENE2-FT/productlog/`
- Verwandt: `docs/howto/inventory-management.md` (FT203, SKU-basierter Lagerbestand)
- Verwandt: `docs/howto/session-token-management.md` (FT208, ebenfalls ATK)
