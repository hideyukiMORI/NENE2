# How-to: Inventarverwaltungs-API

Diese Anleitung zeigt, wie eine Inventar-/Lagerverwaltungs-API mit Lagerbestandsanpassungen und Verlaufsverfolgung mit NENE2 aufgebaut wird.
Muster demonstriert durch den **inventorylog**-Feldversuch (FT220, ATK-Cracker-Angriffstest).

## Funktionen

- Inventarobjekte mit SKU, Name, Preis und Anfangsmenge erstellen (nur Admin)
- Objektdetails abrufen (öffentlich)
- Lagerbestand mit vorzeichenbehaftetem Delta anpassen (positiv = Auffüllen, negativ = Verbrauch)
- Unzureichender Lagerbestand → 409 Conflict
- Vollständiges Anpassungsverlaufsprotokoll

## Schema

```sql
CREATE TABLE IF NOT EXISTS items (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    sku          TEXT    NOT NULL UNIQUE,
    name         TEXT    NOT NULL,
    quantity     INTEGER NOT NULL DEFAULT 0,
    price_cents  INTEGER NOT NULL DEFAULT 0,
    created_at   TEXT    NOT NULL,
    updated_at   TEXT    NOT NULL
);

CREATE TABLE IF NOT EXISTS stock_logs (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    item_id        INTEGER NOT NULL,
    delta          INTEGER NOT NULL,
    reason         TEXT    NOT NULL DEFAULT '',
    quantity_after INTEGER NOT NULL,
    created_at     TEXT    NOT NULL,
    FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE CASCADE
);
```

## Endpunkte

| Methode | Pfad | Auth | Beschreibung |
|--------|------|------|-------------|
| `POST` | `/items` | Admin | Inventarobjekt erstellen |
| `GET` | `/items/{id}` | Öffentlich | Objekt mit aktuellem Lagerbestand abrufen |
| `POST` | `/items/{id}/adjust` | Admin | Lagerbestand anpassen (Delta ± N) |
| `GET` | `/items/{id}/history` | Öffentlich | Anpassungsverlauf abrufen |

## Lagerbestandsanpassungs-Muster

```php
/** @return 'ok'|'not_found'|'insufficient_stock' */
public function adjust(int $id, int $delta, string $reason): string
{
    $item = $this->findById($id);
    if ($item === null) return 'not_found';

    $newQty = (int) $item['quantity'] + $delta;
    if ($newQty < 0) return 'insufficient_stock'; // → 409

    // Atomares Update + Protokolleintrag
    $this->pdo->prepare(
        'UPDATE items SET quantity = :qty, updated_at = :now WHERE id = :id'
    )->execute([':qty' => $newQty, ':now' => $now, ':id' => $id]);

    $this->pdo->prepare(
        'INSERT INTO stock_logs (item_id, delta, reason, quantity_after, created_at) VALUES ...'
    )->execute([...]);

    return 'ok';
}
```

## Delta-Validierung

```php
$delta = $body['delta'] ?? null;
if (!is_int($delta) || $delta === 0 || abs($delta) > self::MAX_QUANTITY) {
    return $this->problem(422, 'validation-failed', 'delta must be a non-zero integer with |delta| ≤ 1000000.');
}
```

## ATK-Cracker-Testergebnisse (FT220)

- **ATK-01**: SQL-Injection in SKU → blockiert durch `/\A[A-Z0-9\-]{1,32}\z/`-Muster (422)
- **ATK-01**: SQL-Injection in Pfad-ID → blockiert durch `ctype_digit()` (404)
- **ATK-02**: Integer-Überlauf in `price_cents` → Float durch `is_int()` abgelehnt (422)
- **ATK-03**: Überdimensionierte Pfad-ID → `strlen > 18`-Schutz (404)
- **ATK-04**: Drain-to-zero Grenzwert → erlaubt (Menge = 0 ist gültig)
- **ATK-05**: Überdimensionierte `quantity` (> 1.000.000) → abgelehnt (422)
- **ATK-06**: Falscher/leerer Admin-Key → 403 (fail-closed)
- **ATK-09**: Over-drain-Angriff → `insufficient_stock` → 409, Lagerbestand unverändert
- **ATK-10**: Float `delta` → durch `is_int()` abgelehnt (422)
- **ATK-11**: Anfrage ohne Body → 400 (JSON-Body erforderlich)
- **ATK-12**: Fehlerantworten enthalten keine SQLSTATE/Stack-Traces/internen Pfade

## Sicherheitsmuster

- **Admin fail-closed**: `if ($this->adminKey === '') return false;` vor `hash_equals()`
- **`is_int()`-strenge Prüfungen**: price_cents, quantity, delta — lehnt Floats aus JSON ab
- **`ctype_digit()`**: ReDoS-sichere Integer-Validierung für Pfad-IDs
- **SKU-Muster**: `/\A[A-Z0-9\-]{1,32}\z/` blockiert SQL-Injection-Versuche
- **Atomare Operationen**: Update + Protokolleintrag-Insert in Sequenz (innerhalb einer einzelnen Verbindung)
