# Datenbanktransaktionen

`DatabaseTransactionManagerInterface::transactional()` für atomare mehrstufige Operationen verwenden.
Wenn ein Schritt wirft, werden alle Änderungen im Callback automatisch zurückgerollt.

## Die kritische Regel: Repositories innerhalb des Callbacks instanziieren

> **Warnung:** Repositories, die bei der Initialisierung injiziert werden, verwenden eine *andere* PDO-Verbindung und werden **außerhalb der Transaktion** ausgeführt. Rollbacks machen ihre Änderungen nicht rückgängig.

`PdoConnectionFactory` erstellt bei jedem Aufruf eine neue Verbindung. Wenn `transactional()` eine Transaktion öffnet, öffnet es sie auf einer *neuen* Verbindung. Jedes Repository, das vor diesem Aufruf erstellt wurde (z. B. über Konstruktor-DI injiziert), hält eine andere Verbindung — eine, die nicht Teil der Transaktion ist.

### Korrektes Muster

```php
final class OrderService
{
    public function __construct(
        private readonly DatabaseTransactionManagerInterface $tx,
    ) {}

    public function placeOrder(array $items): int
    {
        return $this->tx->transactional(function ($executor) use ($items): int {
            // ✅ Repositories INNERHALB des Callbacks mit dem tx-scoped Executor instanziieren
            $inventory = new InventoryRepository($executor);
            $orders    = new OrderRepository($executor);

            foreach ($items as $item) {
                // wirft InsufficientStockException → Rollback automatisch ausgelöst
                $inventory->decrement($item['product_id'], $item['quantity']);
            }

            return $orders->create($items);
        });
    }
}
```

### Falsches Muster (Änderungen werden NICHT zurückgerollt)

```php
// ❌ Diese Repos halten eine andere Verbindung — nicht Teil der Transaktion
public function __construct(
    private readonly InventoryRepository $inventory,
    private readonly OrderRepository $orders,
    private readonly DatabaseTransactionManagerInterface $tx,
) {}

public function placeOrder(array $items): int
{
    return $this->tx->transactional(function ($executor) use ($items): int {
        foreach ($items as $item) {
            $this->inventory->decrement(...); // ← andere Verbindung, außerhalb der Transaktion!
        }
        return $this->orders->create($items); // ← gleiches Problem
        // Wenn eine Exception geworfen wird, werden $this->inventory-Änderungen NICHT rückgängig gemacht
    });
}
```

Dies ist ein stiller Fehler: Der Code kompiliert, PHPStan kann ihn nicht erkennen, und Tests könnten bestehen, außer sie verifizieren das Rollback-Verhalten explizit.

---

## Rollback-Verhalten

`transactional()` fängt `Throwable`, rollt zurück, dann wirft es erneut. Der Aufrufer erhält die ursprüngliche Exception.

```php
try {
    $this->orderService->placeOrder($items);
} catch (InsufficientStockException $e) {
    // Alle Inventar-Decrements aus diesem Aufruf sind rückgängig gemacht
    // Keine Bestellung wurde erstellt
}
```

---

## Vor-Validierung + Atomarer-Operations-Muster

Das schnell-scheiternde Muster oben stoppt beim ersten nicht vorrätigen Element und lässt den Client über andere Fehler im Unklaren.
Wenn UX wichtig ist, zuerst alle Fehler sammeln, dann atomar ausführen:

```php
public function placeOrder(array $items): int
{
    // Phase 1: alle Elemente außerhalb der Transaktion validieren (schreibgeschützt)
    $errors = [];
    foreach ($items as $item) {
        $stock = $this->getStockSnapshot($item['product_id']);
        if ($stock < $item['quantity']) {
            $errors[] = [
                'product_id' => $item['product_id'],
                'requested'  => $item['quantity'],
                'available'  => $stock,
            ];
        }
    }

    if ($errors !== []) {
        throw new InsufficientStockException($errors);
    }

    // Phase 2: atomares Dekrement — innerhalb immer noch den tx-scoped Executor verwenden
    return $this->tx->transactional(function ($executor) use ($items): int {
        $inventory = new InventoryRepository($executor);
        $orders    = new OrderRepository($executor);

        foreach ($items as $item) {
            // DB-CHECK-Bedingung ist das letzte Sicherheitsnetz für Races
            $inventory->decrement($item['product_id'], $item['quantity']);
        }

        return $orders->create($items);
    });
}
```

**Kompromisse:**
- Das Lesen in Phase 1 ist nicht Teil der Transaktion, sodass eine gleichzeitige Anfrage den Bestand zwischen Phase 1 und Phase 2 erschöpfen kann. Die Datenbankbedingung `CHECK (stock >= 0)` (oder `decrement()` wirft bei negativem Bestand) fängt diesen Race auf.
- Für die meisten Anwendungen ist dies akzeptabel. Für strikte Korrektheit `SELECT ... FOR UPDATE` oder ein serialisierbares Isolationsniveau verwenden (nicht in SQLite verfügbar).

---

## Rollback-Korrektheit testen

Immer verifizieren, dass das Inventar nach einer fehlgeschlagenen Bestellung unverändert ist:

```php
$this->inventory->seed(1, 'Widget', 10);
$this->inventory->seed(2, 'Gadget', 1);

// Widget-Dekrement würde erfolgreich sein, aber Gadget schlägt fehl → beide müssen zurückrollen
$res = $this->post('/orders', ['items' => [
    ['product_id' => 1, 'quantity' => 3],
    ['product_id' => 2, 'quantity' => 5], // nur 1 auf Lager
]]);

assertSame(422, $res->getStatusCode());
assertSame(10, $this->inventory->getStock(1), 'Widget muss auf 10 zurückgerollt werden');
assertSame(0, $this->orders->count(), 'Keine Bestellung erstellt');
```

Unit-Tests, die Repositories mocken, können diese Klasse von Fehler nicht erfassen — nur Integrationstests, die dieselbe SQLite/MySQL-Verbindung teilen, können die Rollback-Korrektheit verifizieren.

---

## Laravel vs. NENE2

Laravels `DB::transaction()` funktioniert mit injizierten Modellen, weil es transparent eine Verbindung über die Anfrage hinweg teilt. NENE2s `PdoConnectionFactory` gibt bei jedem Aufruf eine neue Verbindung zurück — eine bewusste Designentscheidung für Testbarkeit und explizite Verbindungskontrolle. Die Konsequenz ist, dass das Callback-scoped Executor-Muster in NENE2 **erforderlich** ist.
