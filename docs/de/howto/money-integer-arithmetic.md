# How-to: Geld und Integer-Arithmetik

> **Verwandte Szenarien**: DX Szenario 10, 23, 32, 36, 40, 43, 44, 50 — die am häufigsten genannte Quelle stiller Präzisionsfehler in Finanzszenarien.

Als Fließkommazahl (`REAL` / `float`) gespeicherte Geldbeträge akkumulieren Rundungsfehler.
`1001 * 0.05` in IEEE 754 ergibt `50.049999999999997`, nicht `50.05`.
Der korrekte Ansatz ist, Beträge als **Integer in der kleinsten Währungseinheit** zu speichern und zu berechnen
(Yen für JPY, Cent für USD/EUR).

---

## Die Regel: immer als Integer speichern

```php
// ❌ Falsch — REAL/float akkumuliert Fehler
$fee = $amount * 0.05;           // 1001 * 0.05 = 50.04999...
$tax = $price * 1.10;            // 1000 * 1.10 = 1100.0000000000002

// ✅ Korrekt — Integer-Arithmetik
$fee = intdiv($amount * 5, 100); // 1001 * 5 / 100 = 50 (abgeschnitten)
$tax = intdiv($amount * 110, 100); // 1000 * 110 / 100 = 1100
```

Schema:

```sql
CREATE TABLE orders (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    amount_yen   INTEGER NOT NULL CHECK(amount_yen > 0),  -- ✅ INTEGER, nicht REAL
    fee_yen      INTEGER NOT NULL CHECK(fee_yen >= 0),
    tax_yen      INTEGER NOT NULL CHECK(tax_yen >= 0),
    total_yen    INTEGER NOT NULL CHECK(total_yen > 0)
);
```

`CHECK`-Constraints verwenden, um nicht-negative Werte auf DB-Ebene zu erzwingen.

---

## Die Rundungsfunktion wählen

Bei der Division von Integers muss entschieden werden, wie mit dem Rest umgegangen wird.
**Diese Richtlinie vor dem Schreiben von Code festlegen und dokumentieren** — eine spätere Änderung betrifft jeden historischen Datensatz.

| Funktion | Verhalten | Beispiel: `intdiv(1, 3)` | Wann verwenden |
|----------|-----------|--------------------------|----------------|
| `intdiv($a, $b)` | Richtung null abschneiden | `0` | Plattformgebühren (Zahler behält Rest) |
| `(int) round($a / $b)` | Halbes aufrunden | `0` (rundet auf 0) | Rechnungssplitting, generisches Runden |
| `(int) ceil($a / $b)` | Aufrunden (Decke) | `1` | Steuerberechnung (immer für Behörden aufrunden) |
| `(int) floor($a / $b)` | Abrunden (Boden) | `0` | Gleich wie intdiv für positive Werte |

### Plattformgebühr (5%) — wer behält den Rest?

```php
// Option A: Plattform nimmt Boden (Zahler begünstigt)
$fee = intdiv($amount * 5, 100);     // 1001 Yen → Gebühr = 50, Verkäufer erhält 951

// Option B: Plattform nimmt Decke (Plattform begünstigt)
$fee = (int) ceil($amount * 5 / 100); // 1001 Yen → Gebühr = 51, Verkäufer erhält 950

// Option C: halbes aufrunden (neutral)
$fee = (int) round($amount * 5 / 100); // 1001 Yen → Gebühr = 50, Verkäufer erhält 951
```

Es gibt keine universell richtige Antwort. **Die Wahl in der API-Spezifikation dokumentieren.**

---

## Steuerberechnung (japanische Verbrauchssteuer: 10%)

Die japanische Verbrauchssteuer erfordert **Abrunden** pro Transaktion (nicht pro Einzelposten):

```php
// ✅ Auf Transaktionsebene abschneiden
$taxIncluded  = intdiv($priceExcl * 110, 100);  // 1000 → 1100
$taxAmount    = intdiv($priceExcl * 10, 100);   // 1000 → 100

// ❌ NICHT pro Einzelposten runden und dann summieren — Rundungsfehler akkumulieren sich
$items = [100, 100, 100]; // 3 Artikel × 100 Yen
$total = array_sum(array_map(fn($p) => (int)round($p * 1.1), $items)); // kann von intdiv(300 * 110, 100) abweichen
```

Wenn ein `tax_rate` gespeichert wird, als **Basispunkte** speichern (Integer, 1/10000):
`10% = 1000 bps`. Vermeidet Fließkomma in der Raten-Speicherung selbst.

```sql
tax_rate_bps INTEGER NOT NULL DEFAULT 1000  -- 10,00%
```

```php
$taxAmount = intdiv($amount * $taxRateBps, 10000);
```

---

## Aufteilen: Rest-Verteilung

Beim Aufteilen eines Gesamtbetrags auf N Teilnehmer:

```php
function splitEvenly(int $totalYen, int $n): array
{
    $base      = intdiv($totalYen, $n);       // Anteil jeder Person (abgeschnitten)
    $remainder = $totalYen % $n;              // verbleibende Yen (0 bis n-1)

    $shares = array_fill(0, $n, $base);

    // Rest 1 Yen nach dem anderen an die ersten Teilnehmer verteilen
    for ($i = 0; $i < $remainder; $i++) {
        $shares[$i]++;
    }

    // Verifizieren: Summe muss dem ursprünglichen Gesamtbetrag entsprechen
    assert(array_sum($shares) === $totalYen);

    return $shares;
}

// splitEvenly(1000, 3) → [334, 333, 333]  (Summe = 1000) ✅
// splitEvenly(100,  3) → [34,  33,  33]   (Summe = 100)  ✅
```

Niemals `round($total / $n)` für jeden Teilnehmer verwenden und es dabei belassen — die Summe weicht oft um 1 Yen ab.

---

## SQLite-Integer-Divisions-Fallstrick

In SQLite führt die Division zweier Integer zu einer Integer-Division:

```sql
SELECT 5 / 100;     -- → 0  (Integer-Division: abgeschnitten)
SELECT 5.0 / 100;   -- → 0.05 (Real-Division)
SELECT 5 * 100 / 100;  -- → 5 (zuerst multiplizieren, dann dividieren — OK)
```

**In PHP** mit PDO werden alle gebundenen Werte als Strings gesendet. SQLite erzwingt sie, aber:

```php
// Sicher: zuerst multiplizieren, um Abschneidung zu vermeiden
$fee = $this->db->fetchOne(
    'SELECT amount_yen * 5 / 100 AS fee FROM orders WHERE id = ?',
    [$id],
);
// → amount_yen * 5 zuerst (Integer * Integer = Integer), dann / 100

// Riskant: wenn PDO '5' und '100' als Strings sendet, wählt SQLite möglicherweise Real-Division
// Das testen, wenn die SQLite-Version oder das PDO-Verhalten unklar ist.
```

Der sicherste Ansatz: **die Arithmetik in PHP mit `intdiv()` durchführen**, das Ergebnis speichern und SQL-Arithmetik nur für Summierungen (`SUM`, `COUNT`) verwenden, nicht für Pro-Zeile-Berechnungen.

---

## Abschreibung (lineare Methode)

```php
// Jährliche Abschreibung (lineare Methode)
$annualDepr = intdiv($purchasePrice - $salvageValue, $usefulLifeYears);

// Aktueller Buchwert
$yearsElapsed = (int) floor(
    (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->diff(
        new \DateTimeImmutable($purchaseDateUtc)
    )->days / 365
);
$currentValue = max($salvageValue, $purchasePrice - $annualDepr * $yearsElapsed);
```

`intdiv` schneidet die jährliche Abschreibung ab, was bedeutet, dass der Vermögenswert pro Jahr etwas weniger abschreibt und der Rest als zusätzliche Abschreibung im letzten Jahr erscheint. Das ist das Standardverhalten für die japanische lineare Abschreibung.

---

## Anzeige für den Benutzer

Nur in der Antwortschicht in ein lesbares Format konvertieren, niemals in der Domäne:

```php
final readonly class MoneyResponse
{
    public function __construct(
        public int    $amountYen,
        public string $displayAmount,  // "¥1.234"
    ) {}

    public static function fromYen(int $yen): self
    {
        return new self(
            amountYen:     $yen,
            displayAmount: '¥' . number_format($yen),
        );
    }
}
```

`amountYen` (Integer) für weitere Berechnungen speichern; `displayAmount` (String) für die UI. Niemals formatierte Strings speichern — sie können nicht summiert werden.

---

## Zusammenfassung: Entscheidungs-Checkliste

Vor dem Schreiben einer Geldberechnung diese Fragen beantworten:

1. **Einheit**: Yen (kein Dezimal), Cent (1/100) oder Mikropfennig (1/1000)?
   → Als Integer in dieser Einheit speichern; die Einheit im Spaltennamen dokumentieren (`amount_yen`, `price_cents`).

2. **Rundungsrichtung**: `intdiv` (abschneiden), `ceil`, `floor` oder `round`?
   → Eine wählen; einen Kommentar im Code hinzufügen, der erklärt, warum.

3. **Wer bekommt den Rest**: beim Aufteilen, wer absorbiert den Rundungsunterschied?
   → Den Rest explizit verteilen (siehe `splitEvenly` oben).

4. **Steuersatzspeicherung**: Basispunkte (`INTEGER`) statt Prozent (`REAL`)?
   → `1000` für 10%, `800` für 8%, niemals `0.10` oder `0.08`.

5. **Kumulativ oder pro Transaktion**: Steuer pro Einzelposten oder pro Rechnungsgesamtbetrag akkumulieren?
   → Pro Transaktion (einzelnes `intdiv`) ist Standard für JPY-Rechnungen.

---

## Verwandte Anleitungen

- [`multi-currency-money-ledger.md`](multi-currency-money-ledger.md) — Doppelbuchhaltung mit `Money`-Value-Object
- [`point-ledger-api.md`](point-ledger-api.md) — Punkte-/Guthaben-System mit Integer-Beträgen
- [`expense-tracking-api.md`](expense-tracking-api.md) — Ausgabenerfassung mit Integer-Yen
