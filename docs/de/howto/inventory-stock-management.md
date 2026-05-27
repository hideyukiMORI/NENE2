# How-to: Inventory-Bestandsverwaltung

## Übersicht

Diese Anleitung behandelt den Aufbau einer Inventarverwaltungs-API mit NENE2. Zu den Funktionen gehören SKU-basierte Artikelregistrierung, Einlagerungs-/Auslagerungsvorgänge, Verhinderung negativer Bestände und Transaktionsverlauf.

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
| `POST` | `/inventory/items/{id}/in` | Einlagern (Eingang) |
| `POST` | `/inventory/items/{id}/out` | Auslagern (Versand) |
| `GET` | `/inventory/items/{id}/history` | Transaktionsverlauf |

---

## SKU-Validierung

SKU-Format einschränken, um Injection zu verhindern und kanonische Form sicherzustellen:

```php
if (!preg_match('/\A[A-Z0-9_-]{1,32}\z/', $sku)) {
    return $this->problem(422, 'validation-failed', 'sku must be uppercase alphanumeric (max 32).');
}
```

---

## Bestandsvorgänge

### Einlagern

Immer sicher — einfach inkrementieren:

```php
$this->pdo->prepare('UPDATE items SET stock = stock + :qty, updated_at = :now WHERE id = :id')
    ->execute([':qty' => $quantity, ':now' => $now, ':id' => $itemId]);
```

### Auslagern (mit Unzureichend-Bestand-Guard)

```php
if ((int) $item['stock'] < $quantity) {
    return 'insufficient_stock';   // → 409 Conflict
}
$this->pdo->prepare('UPDATE items SET stock = stock - :qty, updated_at = :now WHERE id = :id')
    ->execute([':qty' => $quantity, ':now' => $now, ':id' => $itemId]);
```

---

## Mengenvalidierung

Nicht-Integer und nicht-positive Mengen ablehnen:

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
| Bestand hinzugefügt / reduziert | 200 |
| Artikel / Verlauf gefunden | 200 |
| Fehlendes oder leeres Feld | 422 |
| Ungültiges SKU-Format | 422 |
| Nicht-Integer oder negative Menge | 422 |
| Artikel nicht gefunden | 404 |
| Doppelte SKU | 409 |
| Unzureichender Bestand | 409 |

---

## Hinweise

- **Atomische Updates**: `stock = stock + :qty` und `stock = stock - :qty` in SQL verwenden, um den Bestand auch bei gleichzeitigem Zugriff konsistent zu halten.
- **Prüfpfad**: Jede Bestandsänderung schreibt eine `stock_history`-Zeile für die Rückverfolgbarkeit.
- **Soft-Constraint**: Die Anwendung prüft den Bestand vor dem Dekrementieren. Für strikte Korrektheit unter Nebenläufigkeit einen `CHECK (stock >= 0)`-Spalten-Constraint in der DB hinzufügen oder Transaktionen mit Zeilensperrung verwenden.
