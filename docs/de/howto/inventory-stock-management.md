# How-to: Lagerbestandsverwaltung

## Überblick

Diese Anleitung behandelt den Aufbau einer Inventarverwaltungs-API mit NENE2. Zu den Funktionen gehören SKU-basierte Artikelregistrierung, Ein-/Auslagerungsvorgänge, Verhinderung negativer Lagerbestände und Transaktionshistorie.

**Referenzimplementierung**: `../NENE2-FT/inventorylog/`

---

## Schema-Design

```sql
CREATE TABLE IF NOT EXISTS items (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    sku        TEXT    NOT NULL UNIQUE,
    name       TEXT    NOT NULL,
    stock      INTEGER NOT NULL DEFAULT 0,
    created_at TEXT    NOT NULL,
    updated_at TEXT    NOT NULL
);

CREATE TABLE IF NOT EXISTS stock_history (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    item_id    INTEGER NOT NULL,
    type       TEXT    NOT NULL,   -- 'in' | 'out'
    quantity   INTEGER NOT NULL,
    note       TEXT,
    created_at TEXT    NOT NULL,
    FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE CASCADE
);
```

---

## Routentabelle

| Methode | Pfad | Beschreibung |
|--------|------|-------------|
| `POST` | `/inventory/items` | Artikel registrieren (SKU + Name) |
| `GET` | `/inventory/items` | Alle Artikel auflisten |
| `GET` | `/inventory/items/{id}` | Artikel nach ID abrufen |
| `POST` | `/inventory/items/{id}/in` | Einlagerung (Wareneingang) |
| `POST` | `/inventory/items/{id}/out` | Auslagerung (Versand) |
| `GET` | `/inventory/items/{id}/history` | Transaktionshistorie |

---

## SKU-Validierung

SKU-Format einschränken, um Injections zu verhindern und kanonische Form zu gewährleisten:

```php
if (!preg_match('/\A[A-Z0-9_-]{1,32}\z/', $sku)) {
    return $this->problem(422, 'validation-failed', 'sku must be uppercase alphanumeric (max 32).');
}
```

---

## Lagervorgänge

### Einlagerung

Immer sicher — einfach inkrementieren:

```php
$this->pdo->prepare('UPDATE items SET stock = stock + :qty, updated_at = :now WHERE id = :id')
    ->execute([':qty' => $quantity, ':now' => $now, ':id' => $itemId]);
```

### Auslagerung (mit Schutz vor unzureichendem Lagerbestand)

```php
if ((int) $item['stock'] < $quantity) {
    return 'insufficient_stock';   // → 409 Conflict
}
$this->pdo->prepare('UPDATE items SET stock = stock - :qty, updated_at = :now WHERE id = :id')
    ->execute([':qty' => $quantity, ':now' => $now, ':id' => $itemId]);
```

---

## Mengenvalidierung

Nicht-Integer- und nicht-positive Mengen ablehnen:

```php
$qty = $body['quantity'] ?? null;
if (!is_int($qty) || $qty <= 0) {
    return [0, null, 'quantity must be a positive integer.'];
}
```

Dies fängt sowohl `"50"` (String) als auch `-1` (negativ) ab.

---

## HTTP-Statuscodes

| Situation | Status |
|-----------|--------|
| Artikel erstellt | 201 |
| Lagerbestand hinzugefügt / reduziert | 200 |
| Artikel / Historie gefunden | 200 |
| Fehlendes oder leeres Feld | 422 |
| Ungültiges SKU-Format | 422 |
| Nicht-Integer- oder negative Menge | 422 |
| Artikel nicht gefunden | 404 |
| Doppeltes SKU | 409 |
| Unzureichender Lagerbestand | 409 |

---

## Hinweise

- **Atomare Updates**: `stock = stock + :qty` und `stock = stock - :qty` in SQL verwenden, um den Saldo auch bei gleichzeitigem Zugriff konsistent zu halten.
- **Audit-Trail**: Jede Lagerbestandsänderung schreibt eine `stock_history`-Zeile für die Nachverfolgbarkeit.
- **Soft Constraint**: Die Anwendung prüft den Lagerbestand vor dem Dekrementieren. Für strenge Korrektheit unter Nebenläufigkeit einen `CHECK (stock >= 0)`-Spalten-Constraint in der DB hinzufügen oder Transaktionen mit Zeilensperrung verwenden.
