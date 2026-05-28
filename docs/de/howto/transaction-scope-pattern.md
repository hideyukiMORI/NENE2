# Anleitung: Transaktions-Scope-Muster

> **FT-Referenz**: FT253 (`NENE2-FT/txlog`) — Datenbanktransaktionsgrenzen: atomare Bestellung-mit-Inventar

Demonstriert das korrekte Muster für `DatabaseTransactionManagerInterface::transactional()`
in NENE2. Repositories müssen **innerhalb** des Callbacks mit dem transaktionsbezogenen Executor
instanziiert werden — vorab injizierte Repositories operieren auf einer anderen Verbindung
und ihre Schreibvorgänge werden **nicht** rückgängig gemacht, wenn die Transaktion fehlschlägt.

---

## Routen

| Methode | Pfad      | Beschreibung                                            |
|---------|-----------|---------------------------------------------------------|
| `POST` | `/orders` | Bestellung aufgeben (atomarer Inventar-Abzug + Bestellung erstellen) |

---

## Schema

```sql
CREATE TABLE IF NOT EXISTS inventory (
    product_id   INTEGER PRIMARY KEY,
    product_name TEXT    NOT NULL,
    stock        INTEGER NOT NULL CHECK (stock >= 0)
);

CREATE TABLE IF NOT EXISTS orders (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    status     TEXT    NOT NULL DEFAULT 'placed',
    created_at TEXT    NOT NULL
);

CREATE TABLE IF NOT EXISTS order_items (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    order_id   INTEGER NOT NULL REFERENCES orders(id),
    product_id INTEGER NOT NULL,
    quantity   INTEGER NOT NULL
);
```

`CHECK(stock >= 0)` ist ein DB-Sicherheitsnetz, das verhindert, dass der Bestand negativ wird,
auch wenn die Anwendungslogik einen Fehler hat. Die Anwendung validiert den Bestand auch vor dem
Dekrementieren, sodass die Bedingung im Normalbetrieb nie ausgelöst wird.

---

## Die Executor-Scope-Falle

`DatabaseTransactionManagerInterface::transactional()` öffnet eine Transaktion und übergibt einen
**transaktionsbezogenen Executor** an den Callback. Schreibvorgänge über diesen Executor sind
Teil der Transaktion und werden bei einer Exception zurückgerollt.

**❌ Falsch: Repository vor der Transaktion injiziert**

```php
final class OrderService
{
    public function __construct(
        private readonly InventoryRepository $inventory, // verwendet äußeren Executor
        private readonly OrderRepository     $orders,    // verwendet äußeren Executor
        private readonly DatabaseTransactionManagerInterface $tx,
    ) {}

    public function placeOrder(array $items): int
    {
        return $this->tx->transactional(function ($txExecutor) use ($items): int {
            // FEHLER: $this->inventory und $this->orders verwenden den äußeren Executor,
            // nicht $txExecutor. Ihre Schreibvorgänge sind auf einer anderen Verbindung.
            // Wenn die Transaktion zurückrollt, werden ihre Änderungen NICHT rückgängig gemacht.
            foreach ($items as $item) {
                $this->inventory->decrement($item['product_id'], $item['quantity']);
            }
            return $this->orders->create($items);
        });
    }
}
```

**✅ Korrekt: Repositories innerhalb des Callbacks erstellt**

```php
final class OrderService
{
    public function __construct(
        private readonly DatabaseTransactionManagerInterface $tx,
        // Nur den Transaction Manager injizieren, nicht die Repositories.
    ) {}

    public function placeOrder(array $items): int
    {
        return $this->tx->transactional(function ($executor) use ($items): int {
            // Repositories werden mit dem transaktionsbezogenen Executor instanziiert.
            // Alle Lese- und Schreibvorgänge gehen durch dieselbe Verbindung, in derselben Transaktion.
            $inventory = new InventoryRepository($executor);
            $orders    = new OrderRepository($executor);

            foreach ($items as $item) {
                // Wirft InsufficientStockException → löst Rollback aus
                $inventory->decrement($item['product_id'], $item['quantity']);
            }

            return $orders->create($items);
        });
    }
}
```

Der transaktionsbezogene `$executor`, der von `transactional()` übergeben wird, teilt dieselbe PDO-
Verbindung und denselben Transaktionskontext. Das Erstellen von Repositories innerhalb des Callbacks
mit diesem Executor stellt sicher, dass alle ihre Schreibvorgänge an derselben atomaren Transaktion teilnehmen.

---

## Inventar-Dekrement mit Anwendungsebene-Prüfung

```php
public function decrement(int $productId, int $qty): void
{
    $current = $this->getStock($productId);
    if ($current < $qty) {
        throw new InsufficientStockException($productId, $qty, $current);
    }
    $this->executor->execute(
        'UPDATE inventory SET stock = stock - ? WHERE product_id = ?',
        [$qty, $productId],
    );
}
```

Das Lesen-dann-Schreiben-Muster (`getStock()` + `UPDATE`) ist von sich aus nicht atomar —
eine gleichzeitige Anfrage könnte denselben Bestand lesen und beide erfolgreich sein. Für den
Produktionseinsatz in einem `SELECT ... FOR UPDATE` (MySQL) einwickeln oder `UPDATE ... WHERE stock >= ?`
als optimistische Prüfung verwenden:

```sql
UPDATE inventory SET stock = stock - ? WHERE product_id = ? AND stock >= ?
-- dann betroffene Zeilen == 1 prüfen; wenn 0, InsufficientStockException werfen
```

SQLite unterstützt kein `SELECT FOR UPDATE`, daher fängt die `CHECK(stock >= 0)`-Bedingung
gleichzeitige Races auf DB-Ebene auf — das zweite Update würde eine Constraint-Verletzung werfen,
die als Exception propagiert und Rollback auslöst.

---

## Multi-Element-Atomizität: Teilfehler löst vollständigen Rollback aus

```php
foreach ($items as $item) {
    $inventory->decrement($item['product_id'], $item['quantity']); // kann werfen
}
return $orders->create($items); // nur erreicht, wenn alle Decrements erfolgreich sind
```

Wenn das **erste** Element fehlschlägt, werden keine Inventaränderungen vorgenommen. Wenn das **letzte**
Element fehlschlägt, werden alle vorherigen Decrements zurückgerollt. Die Test-Suite verifiziert beide Fälle:

```php
// Widget: 10 Bestand; Gadget: 1 Bestand. Bestellung [Widget×3, Gadget×5] schlägt bei Gadget fehl.
$this->assertSame(10, $inventory->getStock(1)); // Widget auf 10 wiederhergestellt
$this->assertSame(0, $orders->count());          // Keine Bestellung erstellt
```

---

## Exception → Rollback → 422-Mapping

Die Exception, die innerhalb von `transactional()` geworfen wird, propagiert zum Aufrufer:

```php
try {
    $orderId = $this->service->placeOrder($items);
    return $this->json->create(['order_id' => $orderId, 'status' => 'placed'], 201);
} catch (InsufficientStockException $e) {
    return $this->problems->create(
        $request,
        'insufficient-stock',
        'Insufficient stock.',
        422,
        $e->getMessage(),
    );
}
```

`transactional()` fängt die Exception ab, ruft `ROLLBACK` auf, dann wirft es erneut. Der Aufrufer
fängt die erneut geworfene Exception ab und ordnet sie einer Problem-Details-Antwort zu.

---

## Eingabe-Validierung: Striktes `is_int` für Mengen

```php
foreach ($body['items'] as $i => $item) {
    if (
        !is_array($item) ||
        !isset($item['product_id'], $item['quantity']) ||
        !is_int($item['product_id']) ||
        !is_int($item['quantity']) ||
        $item['quantity'] < 1
    ) {
        return $this->problems->create(
            $request,
            'validation-failed',
            'Validation failed.',
            422,
            null,
            ['errors' => [['field' => "items.{$i}", 'code' => 'invalid', ...]]],
        );
    }
}
```

`is_int()` lehnt JSON-Floats (`1.0`) und Strings (`"3"`) ab. PHPs JSON-Decoder konvertiert
JSON-Integer-Werte zu PHP `int`, daher funktioniert `is_int()` korrekt für JSON-Eingaben.
`is_numeric()` nur für Query-String-Parameter verwenden (die immer Strings sind).

---

## `transactional()`-Verwendungs-Zusammenfassung

| Tun | Nicht tun |
|-----|-----------|
| Repositories innerhalb des Callbacks erstellen | Repositories bei der Klasseninitialisierung injizieren |
| `$executor` aus dem Callback an neue Repositories weitergeben | `$this->executor` verwenden, das im Konstruktor injiziert wurde |
| Exceptions propagieren lassen, um Rollback auszulösen | Exceptions stillschweigend innerhalb des Callbacks fangen |
| Einen Wert aus dem Callback zurückgeben | `void` zurückgeben, wenn die eingefügte ID benötigt wird |

---

## Verwandte Anleitungen

- [`transactions.md`](transactions.md) — `DatabaseTransactionManagerInterface`-API-Referenz
- [`use-transactions.md`](use-transactions.md) — Transaktionen zu einem bestehenden Endpunkt hinzufügen
- [`budget-tracking.md`](budget-tracking.md) — Geldmittel mit Transaktion übertragen (Zwei-Konto-Bilanznaktualisierung)
- [`order-management.md`](order-management.md) — Vollständiger Bestell-Lebenszyklus (erstellen, Status, stornieren)
- [`optimistic-locking.md`](optimistic-locking.md) — Race-Condition-Prävention ohne Transaktionen
