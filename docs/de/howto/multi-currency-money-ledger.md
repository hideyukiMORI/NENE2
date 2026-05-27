# How-to: Mehrwährungs-Geldkonto mit Integer-Cent

> **FT-Referenz**: FT262 (`NENE2-FT/moneylog`) — Mehrwährungs-Kontobuch-API mit Integer-Kleinsteinheiten (Cent) und einem `Money`-Value-Object

Demonstriert eine Doppelbuchführungs-Kontobuch-API, die Geldbeträge als Integer-Kleinsteinheiten (Cent, Yen, Pence) speichert, um Fließkomma-Präzisionsfehler zu vermeiden. Ein `Money`-Value-Object erzwingt die Invarianten: positiver Betrag und dreistelliger ISO-4217-Währungscode. Der Saldo pro Währung wird mit `SUM(CASE WHEN type = 'credit' ...)` in einer einzelnen SQL-Abfrage berechnet.

---

## Routen

| Methode | Pfad | Beschreibung |
|--------|-----------------|---------------------------------------------|
| `POST` | `/entries`      | Kontoeintrag erstellen (Kredit oder Debit)  |
| `GET`  | `/entries`      | Einträge auflisten (paginiert)              |
| `GET`  | `/entries/{id}` | Einzelnen Eintrag abrufen                   |
| `GET`  | `/balance`      | Saldo pro Währung (Kredit − Debit)          |

---

## Schema

```sql
CREATE TABLE IF NOT EXISTS entries (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    description  TEXT    NOT NULL,
    amount_cents INTEGER NOT NULL CHECK(amount_cents > 0),
    currency     TEXT    NOT NULL CHECK(length(currency) = 3),
    type         TEXT    NOT NULL CHECK(type IN ('credit', 'debit')),
    created_at   TEXT    NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_entries_currency ON entries(currency);
CREATE INDEX IF NOT EXISTS idx_entries_created  ON entries(created_at);
```

`CHECK(amount_cents > 0)` erzwingt positive Beträge auf DB-Ebene — ein Sicherheitsnetz für Fehler oder direkten DB-Zugriff. `CHECK(length(currency) = 3)` erzwingt das ISO-4217-Format. `CHECK(type IN ('credit', 'debit'))` verhindert ungültige Zustände.

---

## Warum Integer-Cent, nicht Float?

```php
// ❌ Float-Arithmetik verliert Präzision
var_dump(0.1 + 0.2);  // float(0.30000000000000004)

// ✅ Integer-Arithmetik ist exakt
$total = 10 + 20;     // int(30) — immer exakt
```

Geldbeträge, die als `FLOAT` gespeichert werden, akkumulieren Rundungsfehler bei Summierungen und können nicht zuverlässig mit `===` verglichen werden. Integer-Kleinsteinheiten (Cent für USD/EUR, Yen für JPY) sind immer exakt. Die Anzeigekonvertierung (`$cents / 100.0`) erfolgt nur bei der Serialisierung, nicht in der Geschäftslogik.

**Achtung**: `JPY` und ähnliche Währungen ohne Dezimalstellen speichern ganze Einheiten als "Cent" (d.h. ¥1000 = 1000 Cent). `formatDecimal()` in diesem FT verwendet standardmäßig 2 Dezimalstellen; eine Produktionsimplementierung sollte die Dezimalstellen der Währung nachschlagen.

---

## `Money`-Value-Object

```php
final readonly class Money
{
    public function __construct(
        public int    $amountCents,
        public string $currency,
    ) {
        if ($amountCents <= 0) {
            throw new \InvalidArgumentException("amountCents must be positive, got {$amountCents}.");
        }
        if (strlen($currency) !== 3) {
            throw new \InvalidArgumentException("currency must be a 3-character ISO 4217 code, got '{$currency}'.");
        }
    }

    public function formatDecimal(): string
    {
        return number_format($this->amountCents / 100, 2, '.', '');
    }
}
```

Der Konstruktor validiert seine eigenen Invarianten. Ein `Money`-Objekt, das existiert, ist immer gültig — Aufrufer müssen die Werte nie erneut prüfen. `readonly` verhindert Mutation nach der Konstruktion.

`formatDecimal()` dient nur der Anzeige. Den formatierten String niemals speichern oder vergleichen; immer `amountCents`-Integer vergleichen.

---

## `EntryType`-Backed-Enum

```php
enum EntryType: string
{
    case Credit = 'credit';
    case Debit  = 'debit';
}
```

`EntryType::from('credit')` konvertiert bei der Hydration den DB-String in das Enum. Wenn die DB unerwarteterweise einen unbekannten Wert enthält, wirft `from()` — keine stille Korruption.

`EntryType::tryFrom($value)` im Controller gibt `null` für unbekannte Werte zurück, was die Validierungsfehlerprüfung dann abfängt:

```php
$type = $typeValue !== null ? EntryType::tryFrom($typeValue) : null;
if ($type === null) {
    $errors[] = new ValidationError('type', "type must be 'credit' or 'debit'.", 'invalid');
}
```

---

## Saldo pro Währung: `SUM(CASE WHEN ...)`

```php
public function balanceByCurrency(): array
{
    $rows = $this->executor->fetchAll(
        "SELECT currency,
            SUM(CASE WHEN type = 'credit' THEN amount_cents ELSE 0 END) AS credit_cents,
            SUM(CASE WHEN type = 'debit'  THEN amount_cents ELSE 0 END) AS debit_cents,
            SUM(CASE WHEN type = 'credit' THEN amount_cents ELSE -amount_cents END) AS balance_cents
         FROM entries
         GROUP BY currency
         ORDER BY currency ASC",
        [],
    );
    // ...
}
```

Eine einzelne Abfrage berechnet drei Aggregate pro Währung:
- `credit_cents`: Gesamtkredit
- `debit_cents`: Gesamtdebit
- `balance_cents`: Nettosaldo (`Kredit − Debit`)

`CASE WHEN type = 'credit' THEN amount_cents ELSE -amount_cents END` verwendet eine Vorzeichenumkehrung, um den Nettobetrag in einem Durchlauf zu berechnen. Ein negativer `balance_cents` bedeutet, dass Debits die Credits übersteigen.

**Alternative**: zwei Abfragen (`SELECT SUM WHERE type = 'credit'` und `SELECT SUM WHERE type = 'debit'`), in PHP zusammengeführt. Der Einzelabfrage-Ansatz ist effizienter und hält die Subtraktion in SQL.

---

## Controller: Währungsnormalisierung

```php
$money = new Money(
    (int) $body['amount_cents'],
    strtoupper((string) $body['currency']),  // ← auf Großbuchstaben normalisieren
);
```

`strtoupper()` normalisiert den Währungscode, sodass `usd`, `USD` und `Usd` alle als `USD` gespeichert werden. Ohne Normalisierung würden `USD` und `usd` als separate Währungen in der Saldoabfrage erscheinen.

---

## Serialisierung: sowohl Cent als auch Dezimal

```php
private function serialize(Entry $entry): array
{
    return [
        'id'           => $entry->id,
        'description'  => $entry->description,
        'amount_cents' => $entry->money->amountCents,   // maschinenlesbar: exakter Integer
        'amount'       => $entry->money->formatDecimal(), // menschenlesbar: "10.50"
        'currency'     => $entry->money->currency,
        'type'         => $entry->type->value,
        'created_at'   => $entry->createdAt,
    ];
}
```

Sowohl `amount_cents` (Integer) als auch `amount` (formatiertes Dezimal) werden zurückgegeben. Clients, die Berechnungen durchführen, sollten `amount_cents` verwenden; Anzeige-UIs können `amount` verwenden.

---

## Beispiel: Saldoantwort

**Anfrage**: `GET /balance`

```json
{
  "balances": [
    {"currency": "EUR", "credit_cents": 50000, "debit_cents": 20000, "balance_cents": 30000},
    {"currency": "JPY", "credit_cents": 100000, "debit_cents": 0, "balance_cents": 100000},
    {"currency": "USD", "credit_cents": 150000, "debit_cents": 75000, "balance_cents": 75000}
  ]
}
```

EUR-Saldo: 500,00 − 200,00 = 300,00 EUR. USD-Saldo: 1500,00 − 750,00 = 750,00 USD.

---

## Design-Vergleich

| Speicheransatz | Präzision | Kompromisse |
|---|---|---|
| `INTEGER`-Cent | Exakt | Erfordert Anzeigekonvertierung; Währung muss Dezimalstellen angeben |
| `DECIMAL(19,4)` | Exakt | DB-nativ; nicht in SQLite verfügbar; Format für Anzeige |
| `FLOAT`/`REAL` | Verlustbehaftet | Niemals für Geld verwenden — Rundungsfehler akkumulieren sich |
| `TEXT` ("10.50") | Entf. | Sortierung und Summierung erfordern Casting; keine Arithmetik in SQL |

SQLites `INTEGER` mit Cent ist der einfachste sichere Ansatz für SQLite-basierte APIs. Für MySQL/PostgreSQL ist `DECIMAL(19,4)` konventioneller.

---

## Verwandte Anleitungen

- [`transaction-scope-pattern.md`](transaction-scope-pattern.md) — atomare Mehrfachschreibvorgänge für Überweisungen
- [`bulk-operations-partial-success.md`](bulk-operations-partial-success.md) — Massenimport mit Teilerfolg
- [`leaderboard-ranking-api.md`](leaderboard-ranking-api.md) — Aggregatabfragen mit SQL-Fensterfunktionen
