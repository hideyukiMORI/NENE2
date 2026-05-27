# Anleitung: Warenkorb-API

> **FT-Referenz**: FT269 (`NENE2-FT/cartlog`) — Warenkorb: UNIQUE (user_id, product_id) Pro-Benutzer-Warenkorb, Upsert-Artikel-Hinzufügen (Mengenkumulierung), quantity=0 Auto-Entfernen-Semantik, Integer-Preis/Zwischensumme, X-User-Id-Header-Identifikation
>
> Auch validiert in FT155 (`NENE2-FT/cartlog` Vorläufer) — gleiches Warenkorbmuster, SQLite, PHP 8.4.

Demonstriert einen zustandsbehafteten Pro-Benutzer-Warenkorb: Artikel hinzufügen (mit Mengenkumulierung bei erneutem Hinzufügen), Mengen aktualisieren, Artikel entfernen und eine laufende Summe anzeigen. Alle Preise werden als Integer (Cent oder Basiseinheiten) gespeichert — niemals als Floats.

---

## Routen

| Methode   | Pfad                        | Beschreibung                              |
|-----------|-----------------------------|------------------------------------------|
| `GET`    | `/cart`                     | Warenkorbinhalt mit Zwischensummen und Gesamtsumme auflisten |
| `POST`   | `/cart/items`               | Produkt hinzufügen (Menge kumuliert sich, wenn bereits im Warenkorb) |
| `PUT`    | `/cart/items/{productId}`   | Menge setzen (0 = Artikel entfernen)     |
| `DELETE` | `/cart/items/{productId}`   | Einen bestimmten Artikel entfernen       |
| `DELETE` | `/cart`                     | Gesamten Warenkorb leeren                |

---

## Schema

```sql
CREATE TABLE users (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT    NOT NULL,
    created_at TEXT    NOT NULL
);

CREATE TABLE products (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT    NOT NULL,
    price      INTEGER NOT NULL CHECK (price >= 0),
    stock      INTEGER NOT NULL DEFAULT 0 CHECK (stock >= 0),
    created_at TEXT    NOT NULL
);

CREATE TABLE cart_items (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    product_id INTEGER NOT NULL,
    quantity   INTEGER NOT NULL CHECK (quantity > 0),
    added_at   TEXT    NOT NULL,
    updated_at TEXT    NOT NULL,
    UNIQUE (user_id, product_id),
    FOREIGN KEY (user_id)    REFERENCES users(id),
    FOREIGN KEY (product_id) REFERENCES products(id)
);
```

Wichtige Designentscheidungen:
- `UNIQUE (user_id, product_id)` — eine Zeile pro (Benutzer, Produkt)-Paar. Das erneute Hinzufügen desselben Produkts kumuliert die Menge statt einer Duplikatzeile einzufügen.
- `price INTEGER` — gespeichert in kleinster Währungseinheit (z. B. Cent). Niemals `FLOAT` für Geld verwenden.
- `quantity INTEGER CHECK (quantity > 0)` — Zeilen mit Menge null werden gelöscht, nicht gespeichert.
- Kein FK für `cart_items.price` — der Preis wird zum Abfragezeitpunkt aus `products.price` gelesen (JOIN), nicht im Warenkorb gespeichert. Wenn sich der Produktpreis ändert, spiegelt der Warenkorb den neuen Preis wider.

---

## Upsert-Artikel-Hinzufügen-Muster

Das Hinzufügen eines Artikels, der bereits im Warenkorb ist, kumuliert die Menge:

```php
public function addItem(int $userId, int $productId, int $quantity, string $now): void
{
    $existing = $this->db->fetchOne(
        'SELECT id, quantity FROM cart_items WHERE user_id = ? AND product_id = ?',
        [$userId, $productId],
    );

    if ($existing !== null) {
        $newQty = (int) $existing['quantity'] + $quantity;
        $this->db->execute(
            'UPDATE cart_items SET quantity = ?, updated_at = ? WHERE id = ?',
            [$newQty, $now, $existing['id']],
        );
    } else {
        $this->db->execute(
            'INSERT INTO cart_items (user_id, product_id, quantity, added_at, updated_at)
             VALUES (?, ?, ?, ?, ?)',
            [$userId, $productId, $quantity, $now, $now],
        );
    }
}
```

Das SELECT-then-INSERT/UPDATE-Muster vermeidet `INSERT OR REPLACE` (das `id` und `added_at` ändert) und vermeidet `ON CONFLICT DO UPDATE` (nicht portabel über alle DB-Engines). Die `UNIQUE (user_id, product_id)`-Bedingung schützt weiterhin vor einem Race-Condition-Duplikat-INSERT.

Antwortstatus: `201 Created` wenn der Artikel neu war; `200 OK` wenn die Menge bei einem vorhandenen Artikel kumuliert wurde.

---

## Menge=0 Auto-Entfernen-Semantik

`PUT /cart/items/{productId}` mit `quantity: 0` entfernt den Artikel statt einer Null-Menge-Zeile zu speichern:

```php
if ($quantity === 0) {
    $this->repo->removeItem($userId, $productId);
    return $this->json->createEmpty(204);
}

$this->repo->updateQuantity($userId, $productId, $quantity, $now);
```

Dies entspricht gängiger Warenkorb-UX: das Stepper auf null ziehen entfernt den Artikel. Das DB-`CHECK (quantity > 0)` erzwingt dies auch auf Speicherebene.

---

## Warenkorb-Gesamtsumme: JOIN + Schleifenberechnung

Die Warenkorbantwort enthält eine Echtzeit-Gesamtsumme, die aus dem JOIN-Ergebnis berechnet wird:

```php
public function getCart(int $userId): array
{
    return $this->db->fetchAll(
        'SELECT ci.id, ci.product_id, ci.quantity, ci.added_at, ci.updated_at,
                p.name AS product_name, p.price
         FROM cart_items ci
         JOIN products p ON p.id = ci.product_id
         WHERE ci.user_id = ?
         ORDER BY ci.added_at ASC, ci.id ASC',
        [$userId],
    );
}
```

```php
$items = $this->repo->getCart($userId);
$total = 0;
$formatted = [];

foreach ($items as $item) {
    $subtotal = (int) $item['price'] * (int) $item['quantity'];
    $total   += $subtotal;
    $formatted[] = $this->formatItem($item, $subtotal);
}

return $this->json->create([
    'items' => $formatted,
    'total' => $total,
    'count' => count($formatted),
]);
```

Sowohl `price` als auch `subtotal` sind Integer. Der API-Consumer dividiert durch 100 zur Anzeige (z. B. `1999` → `19,99 €`).

---

## Benutzeridentifikation über X-User-Id-Header

Der FT verwendet einen einfachen `X-User-Id`-Header (kein JWT) zur Identifikation des Warenkorbeigentümers:

```php
private function requireUserId(ServerRequestInterface $request): ?int
{
    $header = $request->getHeaderLine('X-User-Id');
    if ($header === '') {
        return null;
    }
    $id = (int) $header;
    return $id > 0 ? $id : null;
}
```

Der Handler überprüft, dass der Benutzer in der `users`-Tabelle existiert, bevor er fortfährt:
```php
if ($this->repo->findUserById($userId) === null) {
    return $this->json->create(['error' => 'User not found'], 404);
}
```

**Produktionshinweis**: `X-User-Id` durch einen verifizierten JWT oder Session-Token ersetzen. Der Header ist trivial fälschbar — jeder Aufrufer kann jede `user_id` beanspruchen. `X-User-Id` nur in vertrauenswürdigen internen Service-to-Service-Kontexten verwenden, niemals für öffentliche APIs.

---

## Validierung

```php
// POST /cart/items Body-Validierung
private function parseAddBody(array $body): array
{
    $errors = [];

    if (!isset($body['product_id']) || !is_int($body['product_id'])) {
        $errors[] = new ValidationError('product_id', 'product_id must be an integer', 'invalid_type');
    }

    $productId = isset($body['product_id']) && is_int($body['product_id']) ? $body['product_id'] : 0;
    if ($productId <= 0 && $errors === []) {
        $errors[] = new ValidationError('product_id', 'product_id must be positive', 'invalid_value');
    }

    if (!isset($body['quantity']) || !is_int($body['quantity'])) {
        $errors[] = new ValidationError('quantity', 'quantity must be an integer', 'invalid_type');
    }

    $quantity = isset($body['quantity']) && is_int($body['quantity']) ? $body['quantity'] : 0;
    if ($quantity <= 0 && !isset($errors[1])) {
        $errors[] = new ValidationError('quantity', 'quantity must be positive', 'invalid_value');
    }

    return [$productId, $quantity, $errors];
}
```

Typprüfungen (`is_int`) lehnen Float- oder String-Mengen ab — `"3"` und `3.0` sind beide ungültig.

---

## Beispielantworten

**GET /cart**:
```json
{
    "items": [
        {
            "id": 1,
            "product_id": 5,
            "product_name": "Widget",
            "price": 999,
            "quantity": 2,
            "subtotal": 1998,
            "added_at": "2026-01-01T10:00:00Z",
            "updated_at": "2026-01-01T10:00:00Z"
        }
    ],
    "total": 1998,
    "count": 1
}
```

---

## AppFactory-Verdrahtungsbeispiel

Die App für Tests oder einen leichtgewichtigen Einstiegspunkt bootstrap:

```php
class AppFactory
{
    public static function createSqlite(string $dbFile): RequestHandlerInterface
    {
        $dbConfig = new DatabaseConfig(
            url: null,
            environment: 'test',
            adapter: 'sqlite',
            host: '',
            port: 1,
            name: $dbFile,
            user: '',
            password: '',
            charset: '',
        );
        return self::build($dbConfig);
    }

    private static function build(DatabaseConfig $dbConfig): RequestHandlerInterface
    {
        $factory    = new PdoConnectionFactory($dbConfig);
        $executor   = new PdoDatabaseQueryExecutor($factory);
        $psr17      = new Psr17Factory();
        $repo       = new CartRepository($executor);
        $json       = new JsonResponseFactory($psr17, $psr17);
        $registrar  = new RouteRegistrar($repo, $json);

        return (new RuntimeApplicationFactory(
            $psr17,
            $psr17,
            routeRegistrars: [static fn (Router $router) => $registrar->register($router)],
        ))->create();
    }
}
```

Die Verwendung von `RuntimeApplicationFactory` stellt automatisch bereit: ValidationException → 422-Zuordnung, Fehlerbehandlung und Sicherheits-Header.

---

## Testmuster

```php
// Dasselbe Produkt erneut hinzufügen kumuliert die Menge
$this->req('POST', '/cart/items', ['X-User-Id' => '1'], ['product_id' => 1, 'quantity' => 2]);
$res = $this->req('POST', '/cart/items', ['X-User-Id' => '1'], ['product_id' => 1, 'quantity' => 3]);
$this->assertSame(200, $res->getStatusCode());
$data = $this->json($res);
$this->assertSame(5, $data['quantity']);

// Jeder Benutzer-Warenkorb ist isoliert
$this->req('POST', '/cart/items', ['X-User-Id' => '1'], ['product_id' => 1, 'quantity' => 3]);
$res = $this->req('GET', '/cart', ['X-User-Id' => '2']);
$this->assertSame(0, $this->json($res)['count']);
```

> **SQLite-FK-Einschränkung**: `PdoConnectionFactory` setzt `PRAGMA foreign_keys = ON`. Wenn Testdaten über eine separate PDO-Instanz geseedet werden, dasselbe Pragma auf dieser Verbindung setzen — sonst lassen JOINs stillschweigend Zeilen fallen, deren FK-Ziele über ein anderes Verbindungs-Handle eingefügt wurden.

---

## Was man nicht tun sollte

| Anti-Muster | Risiko |
|---|---|
| `price` beim Hinzufügen in `cart_items` speichern | Veralteter Preis bei Produktpreisänderung; Rückerstattungs-/Überzahlungsstreitigkeiten |
| `FLOAT` für Preise verwenden | Gleitkommafehler in finanziellen Gesamtsummen |
| `X-User-Id` in einer öffentlichen API verwenden | Trivial fälschbar; stattdessen JWT/Session verwenden |
| `quantity: 0` als Null-Zeile speichern lassen | Verstößt gegen `CHECK (quantity > 0)`; verwirrende Semantik |
| `INSERT OR REPLACE` für Upsert verwenden | Setzt `id` und `added_at` zurück; bricht die reihenfolgeerhaltende Sortierung |
| Keine `UNIQUE (user_id, product_id)`-Bedingung | Race Condition erstellt Duplikat-Warenkorbzeilen |
