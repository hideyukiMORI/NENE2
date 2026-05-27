# How-to: CQRS-Muster

> **FT-Referenz**: FT233 (`NENE2-FT/cqrslog`) — CQRS-Muster-API

Demonstriert Command Query Responsibility Segregation (CQRS): Die Schreib-Seite akzeptiert Commands und mutiert das Schreibmodell; die Lese-Seite akzeptiert Queries und liest aus einem denormalisierten Lesemodell (SQL VIEW). Beide Seiten teilen sich dieselbe SQLite-Datenbank, haben aber separate Handler-Klassen, separate Modellobjekte und keinen gemeinsamen Zustand.

---

## CQRS-Kernkonzepte

| Konzept | Beschreibung |
|---------|--------------|
| **Command** | Eine Zustandsänderungs-Absicht — `PlaceOrderCommand`, `UpdateOrderStatusCommand` |
| **Query** | Eine Datenanfrage — `GetOrderSummaryQuery`, `ListOrderSummariesQuery` |
| **CommandHandler** | Führt ein Command gegen das Schreibmodell aus (normalisierte Tabellen) |
| **QueryHandler** | Führt eine Query gegen das Lesemodell aus (denormalisierte View) |
| **Schreibmodell** | Normalisierte Tabellen, optimiert für transaktionale Schreibvorgänge |
| **Lesemodell** | Denormalisierte View, optimiert für Query-Ausgabeform |

---

## Routen

| Methode | Pfad | Seite | Beschreibung |
|---------|------|-------|--------------|
| `POST` | `/orders` | Schreib | Neue Bestellung aufgeben (Command) |
| `PATCH` | `/orders/{id}/status` | Schreib | Bestellstatus aktualisieren (Command) |
| `GET` | `/orders` | Lese | Bestellzusammenfassungen auflisten (Query) |
| `GET` | `/orders/{id}` | Lese | Einzelne Bestellzusammenfassung abrufen (Query) |

---

## Command-Objekte (Schreib-Seite)

Commands sind unveränderliche Value-Objekte, die validierte Daten in den Handler tragen:

```php
final readonly class PlaceOrderCommand
{
    /**
     * @param list<array{product: string, quantity: int, unit_price: int}> $items
     */
    public function __construct(
        public string $customer,
        public array  $items,
    ) {
    }
}

final readonly class UpdateOrderStatusCommand
{
    public function __construct(
        public int    $orderId,
        public string $newStatus,
    ) {
    }
}
```

Commands enthalten keine Geschäftslogik — sie sind typisierte Container für die validierte Eingabe des Controllers. Die Verwendung von `readonly` verhindert Mutation nach der Konstruktion.

---

## Command-Handler (Schreibmodell)

`OrderCommandHandler` besitzt alle Mutationen. Er schreibt in normalisierte Tabellen:

```php
final readonly class OrderCommandHandler
{
    public function __construct(private DatabaseQueryExecutorInterface $executor) {}

    public function place(PlaceOrderCommand $command, string $now): int
    {
        $orderId = $this->executor->insert(
            'INSERT INTO orders (customer, status, created_at, updated_at) VALUES (?, ?, ?, ?)',
            [$command->customer, 'pending', $now, $now],
        );

        foreach ($command->items as $item) {
            $this->executor->insert(
                'INSERT INTO order_items (order_id, product, quantity, unit_price) VALUES (?, ?, ?, ?)',
                [$orderId, $item['product'], $item['quantity'], $item['unit_price']],
            );
        }

        return $orderId;
    }

    public function updateStatus(UpdateOrderStatusCommand $command, string $now): bool
    {
        $affected = $this->executor->execute(
            'UPDATE orders SET status = ?, updated_at = ? WHERE id = ?',
            [$command->newStatus, $now, $command->orderId],
        );

        return $affected > 0;
    }
}
```

Der Handler gibt primitive Werte zurück (`int` orderId, `bool` success) — keine Lesemodell-Objekte. Nach einer erfolgreichen Command re-fragt der Controller die Lese-Seite, um die Antwortform zu erhalten.

---

## Query-Objekte (Lese-Seite)

Queries sind typisierte Wrapper für Query-Parameter:

```php
final readonly class GetOrderSummaryQuery
{
    public function __construct(public int $orderId) {}
}

final readonly class ListOrderSummariesQuery
{
    public function __construct(public ?string $status) {}
}
```

Das Einwickeln von Query-Parametern in Objekte macht den Vertrag des Query-Handlers explizit und vermeidet Primitive-Obsession über Handler-Signaturen hinweg.

---

## Query-Handler (Lesemodell)

`OrderQueryHandler` liest aus `order_summary`, einer SQL-VIEW, die den Join auf DB-Ebene denormalisiert:

```php
final readonly class OrderQueryHandler
{
    public function __construct(private DatabaseQueryExecutorInterface $executor) {}

    public function get(GetOrderSummaryQuery $query): ?OrderSummary
    {
        $rows = $this->executor->fetchAll(
            'SELECT * FROM order_summary WHERE id = ?',
            [$query->orderId],
        );

        return $rows !== [] ? $this->hydrate($rows[0]) : null;
    }

    /** @return list<OrderSummary> */
    public function list(ListOrderSummariesQuery $query): array
    {
        if ($query->status !== null) {
            $rows = $this->executor->fetchAll(
                'SELECT * FROM order_summary WHERE status = ? ORDER BY created_at DESC',
                [$query->status],
            );
        } else {
            $rows = $this->executor->fetchAll(
                'SELECT * FROM order_summary ORDER BY created_at DESC',
                [],
            );
        }

        return array_map($this->hydrate(...), $rows);
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): OrderSummary
    {
        return new OrderSummary(
            id:         (int) $row['id'],
            customer:   (string) $row['customer'],
            status:     (string) $row['status'],
            createdAt:  (string) $row['created_at'],
            itemCount:  (int) $row['item_count'],
            totalCents: (int) ($row['total_cents'] ?? 0),
        );
    }
}
```

`OrderSummary` ist ein Lesemodell-DTO — es wird nie beschrieben; es repräsentiert nur ein Query-Ergebnis. Es von einer schreib-seitigen `Order`-Entität zu trennen, verhindert, dass Lese-seitige Belange ins Schreibmodell lecken.

---

## Lesemodell: SQL VIEW als denormalisierte Projektion

Das Lesemodell ist eine SQLite-`VIEW`, die den Join und die Aggregation vorab berechnet:

```sql
CREATE VIEW IF NOT EXISTS order_summary AS
SELECT
    o.id,
    o.customer,
    o.status,
    o.created_at,
    COUNT(oi.id)                     AS item_count,
    SUM(oi.quantity * oi.unit_price) AS total_cents
FROM orders o
LEFT JOIN order_items oi ON oi.order_id = o.id
GROUP BY o.id;
```

Die View bietet eine stabile Query-Oberfläche — der Query-Handler muss den normalisierten `orders`/`order_items`-Join nicht kennen. Wenn das Schreibmodell seine Tabellenstruktur ändert, muss nur die View-Definition aktualisiert werden, nicht der Query-Handler.

`total_cents` speichert Geldbeträge als Integer-Cent (keine Fließkomma-Rundungsfehler). `?? 0` schützt vor `NULL`, wenn keine Elemente existieren.

---

## Schreibmodell-Schema

```sql
CREATE TABLE IF NOT EXISTS orders (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    customer   TEXT    NOT NULL,
    status     TEXT    NOT NULL DEFAULT 'pending',
    created_at TEXT    NOT NULL,
    updated_at TEXT    NOT NULL
);

CREATE TABLE IF NOT EXISTS order_items (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    order_id   INTEGER NOT NULL REFERENCES orders(id),
    product    TEXT    NOT NULL,
    quantity   INTEGER NOT NULL,
    unit_price INTEGER NOT NULL
);
```

Schreibmodell ist normalisiert: `orders` + `order_items` in einer 1:N-Beziehung. Keine berechneten Spalten — die Leseprojektion befindet sich in der View.

---

## Controller: Commands und Queries verdrahten

Nach Erfolg eines Write-Commands verwendet der Controller die Lese-Seite, um die Antwort zu erstellen:

```php
private function placeOrder(ServerRequestInterface $request): ResponseInterface
{
    // ... Eingabe validieren ...
    $orderId = $this->commands->place(new PlaceOrderCommand($customer, $items), $now);

    // Via Lesemodell re-abfragen, um die Antwortform zu erhalten
    $summary = $this->queries->get(new GetOrderSummaryQuery($orderId));

    return $this->json->create($summary->toArray(), 201);
}
```

Dieses "Command dann Query"-Muster hält die Schreib-Seite unwissend über die Antwortform und stellt sicher, dass die Antwort immer die Projektion der View widerspiegelt (einschließlich berechneter Felder wie `total_cents`).

Element-Validierung vor dem Command:

```php
foreach ($rawItems as $item) {
    if (!is_array($item)) continue;

    $product   = isset($item['product']) && is_string($item['product']) ? trim($item['product']) : '';
    $quantity  = isset($item['quantity']) && is_int($item['quantity']) && $item['quantity'] > 0 ? $item['quantity'] : 0;
    $unitPrice = isset($item['unit_price']) && is_int($item['unit_price']) && $item['unit_price'] >= 0 ? $item['unit_price'] : -1;

    if ($product === '' || $quantity === 0 || $unitPrice < 0) {
        return $this->json->create(['error' => 'each item needs product, quantity>0, unit_price>=0'], 422);
    }
    $items[] = ['product' => $product, 'quantity' => $quantity, 'unit_price' => $unitPrice];
}
```

Strikter `is_int()`-Check bei `quantity` und `unit_price` lehnt Floats und Strings aus JSON ab. `unit_price >= 0` erlaubt Null (kostenlose Artikel); `quantity > 0` erfordert mindestens eines.

---

## Wann CQRS verwenden

CQRS fügt strukturellen Overhead hinzu. Es verwenden, wenn:

- Die Lese- und Schreib-Datenformen signifikant divergieren (z.B. Liste benötigt Aggregate, die das Schreibmodell nicht speichert)
- Lese-Last die Schreib-Last weit übersteigt und diese unabhängig skaliert werden sollen
- Die Domäne komplexe Schreib-Invarianten hat (Transaktionen, Validierung, Domain-Events), die von Lese-Optimierungen isoliert werden sollen
- In Richtung Event-Sourcing aufgebaut wird (CQRS passt natürlich zu event-sourced Schreibmodellen)

CQRS vermeiden, wenn:
- Die Lese- und Schreib-Formen identisch sind (ein einfacher CRUD-Endpunkt)
- Die Codebasis klein ist und die Indirektion den Klarheitsvorteil überwiegt
- Das Team mit dem Muster nicht vertraut ist (führt kognitiven Overhead ein)

---

## Verwandte Anleitungen

- [`event-sourcing.md`](event-sourcing.md) — CQRS-Schreib-Seite durch einen Event-Store gesichert
- [`approval-workflow.md`](approval-workflow.md) — Zustandsmaschine für Bestellstatusübergänge
- [`transactions.md`](transactions.md) — Command-Schreibvorgänge in einer Transaktion einwickeln
- [`batch-api-partial-success.md`](batch-api-partial-success.md) — Bulk-Commands mit Pro-Element-Ergebnissen
