# So bauen Sie ein Flash-Sale-System mit NENE2

Diese Anleitung führt durch den Aufbau eines zeitlich begrenzten Flash-Sale-Systems mit Mengenbeschränkung, bei dem Benutzer ein Produkt zu einem reduzierten Preis innerhalb eines Sale-Fensters kaufen können.

**Field Trial**: FT140  
**NENE2-Version**: ^1.5  
**Behandelte Themen**: Zeitfenster-Validierung, Lagerbestand-Zählung mit COUNT(*), UNIQUE-Constraint für ein-Kauf-pro-Benutzer, `match`-Ausdruck für Status, Cracker-Mindset-Angriffstests

---

## Was wir bauen

- `POST /products` — ein Produkt erstellen
- `POST /sales` — einen Flash Sale erstellen (product_id, price, quantity, starts_at, ends_at)
- `GET /sales/{saleId}` — Sale-Details mit verbleibender Menge und Status anzeigen
- `POST /sales/{saleId}/purchase` — innerhalb des aktiven Fensters kaufen (ein Kauf pro Benutzer)
- `GET /sales/{saleId}/purchases` — alle Käufer auflisten

---

## Datenbankschema

```sql
CREATE TABLE flash_sales (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    product_id INTEGER NOT NULL,
    price      INTEGER NOT NULL,
    quantity   INTEGER NOT NULL,
    starts_at  TEXT    NOT NULL,
    ends_at    TEXT    NOT NULL,
    created_at TEXT    NOT NULL,
    CHECK (quantity > 0),
    CHECK (price >= 0),
    FOREIGN KEY (product_id) REFERENCES products(id)
);

CREATE TABLE purchases (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    sale_id      INTEGER NOT NULL,
    user_id      INTEGER NOT NULL,
    purchased_at TEXT    NOT NULL,
    UNIQUE (sale_id, user_id),
    FOREIGN KEY (sale_id) REFERENCES flash_sales(id),
    FOREIGN KEY (user_id) REFERENCES users(id)
);
```

`UNIQUE (sale_id, user_id)` verhindert, dass ein Benutzer denselben Sale zweimal kauft, auch bei gleichzeitigen Anfragen.

---

## Zeitfenster-Validierung

```php
$now = date('c');

if ($now < $sale['starts_at']) {
    return $this->responseFactory->create(['error' => 'sale has not started yet'], 422);
}

if ($now > $sale['ends_at']) {
    return $this->responseFactory->create(['error' => 'sale has ended'], 422);
}
```

`starts_at` / `ends_at` als ISO-8601-Strings speichern. String-Vergleich funktioniert korrekt für ISO 8601, da das Format lexikografisch geordnet ist.

---

## Lagerbestand-Zählung mit COUNT(*)

Statt eine veränderliche `remaining`-Spalte zu pflegen, tatsächliche Käufe zählen:

```php
public function countPurchases(int $saleId): int
{
    $row = $this->executor->fetchOne(
        'SELECT COUNT(*) as cnt FROM purchases WHERE sale_id = ?',
        [$saleId],
    );

    return isset($row['cnt']) ? (int) $row['cnt'] : 0;
}
```

Dann prüfen:

```php
$purchased = $this->repository->countPurchases($saleId);

if ($purchased >= $sale['quantity']) {
    return $this->responseFactory->create(['error' => 'sold out'], 422);
}
```

`remaining` wird zur Lesezeit berechnet: `$sale['quantity'] - $purchased`. Auf `max(0, $remaining)` begrenzen, um negative Anzeige zu vermeiden.

---

## Ein Kauf pro Benutzer — UNIQUE-Constraint

`UNIQUE (sale_id, user_id)` verhindert Duplikate auf DB-Ebene. `DatabaseConstraintException` mappt auf 409:

```php
public function purchase(int $saleId, int $userId, string $now): bool
{
    try {
        $this->executor->execute(
            'INSERT INTO purchases (sale_id, user_id, purchased_at) VALUES (?, ?, ?)',
            [$saleId, $userId, $now],
        );

        return true;
    } catch (DatabaseConstraintException) {
        return false;
    }
}
```

Der Handler gibt 409 zurück, wenn `purchase()` `false` zurückgibt.

---

## Sale-Status mit match-Ausdruck

```php
$status = match (true) {
    $now < $sale['starts_at'] => 'upcoming',
    $now > $sale['ends_at']   => 'ended',
    default                   => 'active',
};
```

Drei Zustände: `upcoming`, `active`, `ended`. Der `match`-Ausdruck ist erschöpfend, da `default` alle anderen Fälle abdeckt.

---

## Cracker-Angriffstestergebnisse (FT140)

| ID | Angriff | Erwartet | Ergebnis |
|----|---------|----------|----------|
| ATK-01 | SQL-Injection im Produktnamen | 201 (wörtlich gespeichert) | Pass |
| ATK-02 | Kauf ohne X-User-Id | 400 | Pass |
| ATK-03 | Nicht-numerischer X-User-Id | nicht 201 | Pass |
| ATK-04 | Negative saleId in URL | nicht 201 | Pass |
| ATK-05 | Kaufen vor Sale-Start | 422 | Pass |
| ATK-06 | Kaufen nach Sale-Ende | 422 | Pass |
| ATK-07 | Doppelkauf desselben Sales | 409 beim zweiten | Pass |
| ATK-08 | Lagerbestand erschöpfen, dann kaufen | 422 sold out | Pass |
| ATK-09 | Sale mit quantity=0 erstellen | 422 | Pass |
| ATK-10 | Sale mit negativem Preis erstellen | 422 | Pass |
| ATK-11 | Kauf als nicht-existierender Benutzer | 404 | Pass |
| ATK-12 | ends_at vor starts_at | 422 | Pass |

Alle 12 Angriffstests bestanden.

---

## Häufige Fallstricke

| Fallstrick | Lösung |
|------------|--------|
| Veränderliche `remaining`-Spalte driftet unter Nebenläufigkeit | Aus `purchases`-Tabelle zählen, `remaining` zur Lesezeit ableiten |
| Null-Menge über API erlauben | `$quantity > 0` im Handler validieren; auch `CHECK (quantity > 0)` im Schema |
| Negativer Preis entweicht | `$price >= 0` validieren; auch `CHECK (price >= 0)` im Schema |
| Benutzer kauft denselben Sale zweimal | `UNIQUE (sale_id, user_id)` + `DatabaseConstraintException` → 409 |
| Zeitvergleich auf Nicht-ISO-Strings | ISO 8601 verwenden (z. B. `date('c')`) — lexikografische Ordnung ist korrekt |
| `ends_at` mit `starts_at` invertiert | `$starts_at < $ends_at` vor INSERT validieren |
