# How-to: Bestellverwaltungs-API

> **FT-Referenz**: FT274 (`NENE2-FT/orderlog`) — Bestellverwaltung: SKU-validierte Positionen, automatische Berechnung von total_cents, Status-Lebenszyklus (pending→confirmed→shipped→delivered→cancelled), IDOR → 404, Admin-Override, Stornierungskonflikt-Erkennung, 36 Tests PASS.
>
> Ebenfalls validiert in FT215 (`NENE2-FT/orderlog`-Vorläufer) — gleiches Muster, frühere Implementierung.

Diese Anleitung zeigt, wie eine Multi-Artikel-Bestellverwaltungs-API mit NENE2 erstellt wird.

## Funktionen

- Bestellungen mit Positionen erstellen (SKU + Menge + Stückpreis)
- Automatische Gesamtberechnung aus Positionen
- Status-Lebenszyklus: `pending → confirmed → shipped → delivered → cancelled`
- Benutzerbezogener IDOR-Schutz (gibt 404 zurück, nicht 403, um Existenz zu verbergen)
- Admin-Override für benutzerübergreifende Operationen
- Atomare Stornierung mit Konflikt-Erkennung (kann `cancelled` oder `delivered` nicht stornieren)

## Schema

```sql
CREATE TABLE IF NOT EXISTS orders (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id     INTEGER NOT NULL,
    status      TEXT    NOT NULL DEFAULT 'pending',
    total_cents INTEGER NOT NULL,
    note        TEXT    NOT NULL DEFAULT '',
    created_at  TEXT    NOT NULL,
    updated_at  TEXT    NOT NULL
);
CREATE TABLE IF NOT EXISTS order_items (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    order_id    INTEGER NOT NULL,
    sku         TEXT    NOT NULL,
    quantity    INTEGER NOT NULL,
    unit_cents  INTEGER NOT NULL,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_orders_user ON orders (user_id, id DESC);
```

## Endpunkte

| Methode | Pfad | Beschreibung |
|--------|------|-------------|
| `POST` | `/orders` | Bestellung mit Positionen erstellen |
| `GET` | `/orders/{id}` | Bestellung mit Positionen abrufen (Eigentümer oder Admin) |
| `POST` | `/orders/{id}/cancel` | Bestellung stornieren (Eigentümer oder Admin) |
| `GET` | `/users/{userId}/orders` | Bestellungen für einen Benutzer auflisten (selbst oder Admin) |

## Positionsvalidierung

```php
/** SKU: Großbuchstaben, alphanumerisch und Bindestriche, 1–32 Zeichen */
private const string SKU_PATTERN = '/\A[A-Z0-9\-]{1,32}\z/';

// Pro Position:
// - sku: muss SKU_PATTERN entsprechen
// - quantity: Integer 1–9999
// - unit_cents: nicht-negativer Integer
// Maximal 50 Positionen pro Bestellung
```

## Repository-Muster

```php
/**
 * @param list<array{sku: string, quantity: int, unit_cents: int}> $items
 * @return array<string, mixed>
 */
public function create(int $userId, string $note, array $items): array
{
    $totalCents = 0;
    foreach ($items as $item) {
        $totalCents += $item['quantity'] * $item['unit_cents'];
    }
    // Bestellung INSERT, dann Positionen INSERT, findById() zurückgeben
}

/** @return 'not_found'|'not_cancellable'|'ok' */
public function cancel(int $id, int $userId, bool $isAdmin): string
{
    // Gibt 'not_found' für falschen Benutzer zurück (IDOR-Schutz)
    // Gibt 'not_cancellable' für stornierte/gelieferte Bestellungen zurück
}
```

## IDOR-Schutz

Benutzerbezogene Endpunkte geben `404` zurück (nicht `403`), wenn ein Benutzer auf die Ressource eines anderen Benutzers zugreift:

```php
// GET /orders/{id}
if (!$isAdmin && (int) $order['user_id'] !== $uid) {
    return $this->problem(404, 'not-found', 'Order not found.');
}

// GET /users/{userId}/orders
if (!$isAdmin && $callerUid !== $targetUid) {
    return $this->problem(404, 'not-found', 'User not found.');
}
```

## Stornierung mit Match-Ausdruck

```php
$result = $this->repo->cancel($id, $uid ?? 0, $isAdmin);

return match ($result) {
    'not_found'       => $this->problem(404, 'not-found', 'Order not found.'),
    'not_cancellable' => $this->problem(409, 'conflict', 'Order cannot be cancelled.'),
    default           => $this->json(['message' => 'Order cancelled.']),
};
```

## Sicherheitsmuster

- **Admin fail-closed**: `if ($this->adminKey === '') return false;` vor `hash_equals()`
- **`ctype_digit()`**: ReDoS-sichere Integer-Validierung für Pfad- und Header-IDs
- **`is_int()`**: Strenge Typprüfung — lehnt Floats ab (z.B. `1.5` als JSON)
- **Max-Positionen-Schutz**: Begrenzt auf 50 Positionen, um überdimensionierte Payloads zu verhindern

---

## Was man NICHT tun sollte

| Anti-Muster | Risiko |
|---|---|
| Preis als FLOAT speichern | Gleitkomma-Rundungsfehler in Gesamtbeträgen (Integer-Cent verwenden) |
| Freiform-SKU-Strings akzeptieren | Injection-Angriffsfläche; mit Regex in der Allowlist (z.B. `[A-Z0-9\-]{1,32}`) |
| Kein Max-Positionen-Limit | Angreifer sendet 10.000-Positions-Array, das eine langsame INSERT-Schleife verursacht |
| Gesamtbetrag Client-seitig berechnen | Client kann beliebigen Gesamtbetrag senden; immer aus `quantity × unit_cents` ableiten |
| 403 beim Zugriff auf die Bestellung eines anderen Benutzers zurückgeben | Offenbart, dass die Bestellung existiert; 404 verwenden, um Eigentümerschaft zu verbergen |
| Stornierung gelieferter Bestellungen erlauben | Erfüllte Bestellungen sollten unveränderlich sein; Zustandsautomat verwenden |
| `ON DELETE CASCADE` bei order_items weglassen | Beim Löschen einer Bestellung bleiben verwaiste Positionen zurück |
