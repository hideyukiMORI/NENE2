# So bauen Sie ein Gastbestellungssystem (Warenkorb → Bestellung → Bestellpositionen) mit NENE2

Diese Anleitung führt durch den Aufbau eines E-Commerce-Bestellablaufs, bei dem Benutzer Produkte in einen Warenkorb legen, den Bestand prüfen und eine Bestellung aufgeben, die Preisschnappschüsse in Bestellpositionen erfasst.

**Field Trial**: FT139  
**NENE2-Version**: ^1.5  
**Behandelte Themen**: Multi-Table-Joins, Bestandsvalidierung, Preisschnappschuss in order_items, Warenkorb-Isolation, `array_sum`-Gesamtberechnung

---

## Was wir bauen

- `POST /products` — ein Produkt erstellen (Name, Preis, Bestand)
- `POST /cart` — ein Produkt zum Warenkorb hinzufügen (akkumuliert Menge, wenn bereits vorhanden)
- `GET /cart` — Warenkorb-Inhalt mit Gesamtbetrag anzeigen (X-User-Id identifiziert den Benutzer)
- `DELETE /cart/{productId}` — ein Element aus dem Warenkorb entfernen
- `POST /orders` — eine Bestellung aufgeben (validiert Bestand, dekrementiert Bestand, leert Warenkorb)
- `GET /orders/{orderId}` — Bestelldetails mit Positionen anzeigen (nur Eigentümer)

---

## Datenbankschema

```sql
CREATE TABLE products (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT    NOT NULL,
    price      INTEGER NOT NULL,
    stock      INTEGER NOT NULL DEFAULT 0,
    created_at TEXT    NOT NULL
);

CREATE TABLE cart_items (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    product_id INTEGER NOT NULL,
    quantity   INTEGER NOT NULL DEFAULT 1,
    added_at   TEXT    NOT NULL,
    UNIQUE (user_id, product_id),
    FOREIGN KEY (user_id)    REFERENCES users(id),
    FOREIGN KEY (product_id) REFERENCES products(id)
);

CREATE TABLE orders (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    total      INTEGER NOT NULL,
    created_at TEXT    NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

CREATE TABLE order_items (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    order_id   INTEGER NOT NULL,
    product_id INTEGER NOT NULL,
    name       TEXT    NOT NULL,
    price      INTEGER NOT NULL,
    quantity   INTEGER NOT NULL,
    FOREIGN KEY (order_id)   REFERENCES orders(id),
    FOREIGN KEY (product_id) REFERENCES products(id)
);
```

`UNIQUE (user_id, product_id)` auf `cart_items` verhindert doppelte Zeilen — das erneute Hinzufügen desselben Produkts akkumuliert die Menge.

---

## Preisschnappschuss in order_items

Wenn eine Bestellung aufgegeben wird, werden der aktuelle Produkt-`name` und `price` in `order_items` kopiert. Dies schützt historische Bestellungen vor zukünftigen Preisänderungen.

```php
/** @param array<int, array{product_id: int, name: string, price: int, quantity: int}> $items */
public function createOrder(int $userId, array $items, int $total, string $now): int
{
    $this->executor->execute(
        'INSERT INTO orders (user_id, total, created_at) VALUES (?, ?, ?)',
        [$userId, $total, $now],
    );

    $orderId = (int) $this->executor->lastInsertId();

    foreach ($items as $item) {
        $this->executor->execute(
            'INSERT INTO order_items (order_id, product_id, name, price, quantity) VALUES (?, ?, ?, ?, ?)',
            [$orderId, $item['product_id'], $item['name'], $item['price'], $item['quantity']],
        );
    }

    return $orderId;
}
```

---

## Warenkorb-Mengen-Akkumulation

`UNIQUE (user_id, product_id)` bedeutet, dass ein zweiter `POST /cart` für dasselbe Produkt UPDATE und nicht INSERT verwenden muss:

```php
public function addToCart(int $userId, int $productId, int $quantity, string $now): void
{
    $existing = $this->findCartItem($userId, $productId);

    if ($existing !== null) {
        $this->executor->execute(
            'UPDATE cart_items SET quantity = quantity + ? WHERE user_id = ? AND product_id = ?',
            [$quantity, $userId, $productId],
        );
        return;
    }

    $this->executor->execute(
        'INSERT INTO cart_items (user_id, product_id, quantity, added_at) VALUES (?, ?, ?, ?)',
        [$userId, $productId, $quantity, $now],
    );
}
```

---

## Bestandsvalidierung vor Bestellaufgabe

Alle Positionen vor dem Dekrementieren des Bestands prüfen. Teilweises Dekrementierungs-Rollback ist komplex — zuerst validieren, dann handeln:

```php
// Alle Positionen validieren
foreach ($items as $item) {
    $product = $this->repository->findProductById($item['product_id']);

    if ($product === null || $product['stock'] < $item['quantity']) {
        return $this->responseFactory->create([
            'error'      => 'insufficient stock',
            'product_id' => $item['product_id'],
        ], 422);
    }
}

// Dekrementieren und Bestellung erstellen
foreach ($items as $item) {
    $this->repository->decrementStock($item['product_id'], $item['quantity']);
}

$orderId = $this->repository->createOrder($actorId, $items, $total, date('c'));
$this->repository->clearCart($actorId);
```

---

## Warenkorb-Gesamtberechnung

```php
$total = array_sum(array_map(fn(array $i) => $i['price'] * $i['quantity'], $items));
```

Wird in PHP aus dem Join-Abfrage-Ergebnis berechnet, nicht in SQL. Dieselbe Berechnung wird für die Warenkorb-Vorschau und den gespeicherten Bestellbetrag verwendet.

---

## Warenkorb-Isolation pro Benutzer

Warenkorbelemente werden immer nach `user_id` gefiltert. Jeder Benutzer sieht und modifiziert nur seinen eigenen Warenkorb. Der `GET /cart`-Handler gibt eine leere Liste für Benutzer ohne Elemente zurück — niemals den Warenkorb eines anderen Benutzers.

---

## Häufige Fallstricke

| Fallstrick | Lösung |
|------------|--------|
| Gleiches Produkt zweimal hinzufügen erzeugt doppelte Zeilen | `UNIQUE (user_id, product_id)` + UPDATE bei Konflikt |
| Preisänderungen nach Bestellaufgabe korrumpieren Geschichte | `name` und `price` zur Bestellzeit in `order_items` kopieren |
| Teilweises Bestandsdekrement bei Multi-Position-Fehler | Alle Positionen zuerst validieren, dann alle dekrementieren |
| Live-Produktpreis in Bestelldetails zurückgeben | `order_items.price` abfragen, nicht `products.price` |
| Warenkorb für alle Benutzer sichtbar | `cart_items` immer nach `user_id` filtern |
