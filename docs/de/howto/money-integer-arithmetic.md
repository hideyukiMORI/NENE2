# How-to: Geldbeträge und Integer-Arithmetik

> **Verwandte Szenarien**: DX Szenario 10, 23, 32, 36, 40, 43, 44, 50 — die am häufigsten genannte Quelle stiller Präzisionsfehler in Finanzszenarien.

Geldbeträge, die als Fließkommazahlen (`REAL` / `float`) gespeichert werden, akkumulieren Rundungsfehler. `1001 * 0.05` in IEEE 754 ergibt `50.049999999999997`, nicht `50.05`. Der korrekte Ansatz ist, Beträge als **Integer in der kleinsten Währungseinheit** zu speichern und zu berechnen (Yen für JPY, Cent für USD/EUR).

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

## Auswahl der Rundungsfunktion

Beim Teilen von Integern muss entschieden werden, wie mit dem Rest umgegangen wird. **Diese Richtlinie vor dem Schreiben des Codes festlegen und dokumentieren** — eine spätere Änderung beeinflusst jeden historischen Datensatz.

| Funktion | Verhalten | Beispiel: `intdiv(1, 3)` | Wann verwenden |
|----------|----------|------------------------|-------------|
| `intdiv($a, $b)` | Abschneiden gegen null | `0` | Plattformgebühren (Zahler behält Rest) |
| `(int) round($a / $b)` | Halbrunden | `0` (rundet auf 0) | Rechnungsaufteilung, generisches Runden |
| `(int) ceil($a / $b)` | Aufrunden (Decke) | `1` | Steuerberechnung (immer aufrunden für den Staat) |
| `(int) floor($a / $b)` | Abrunden (Boden) | `0` | Gleich wie intdiv für positive Werte |

### Plattformgebühr (5 %) — wer behält den Rest?

```php
// Option A: Plattform nimmt Floor (Zahler bevorzugt)
$fee = intdiv($amount * 5, 100);     // 1001 Yen → Gebühr = 50, Verkäufer erhält 951

// Option B: Plattform nimmt Ceil (Plattform bevorzugt)
$fee = (int) ceil($amount * 5 / 100); // 1001 Yen → Gebühr = 51, Verkäufer erhält 950

// Option C: Halbrunden (neutral)
$fee = (int) round($amount * 5 / 100); // 1001 Yen → Gebühr = 50, Verkäufer erhält 951
```

Es gibt keine universell korrekte Antwort. **Die Entscheidung in der API-Spec dokumentieren.**

---

## Steuerberechnung (japanische Verbrauchssteuer: 10 %)

Die japanische Verbrauchssteuer erfordert **Abrunden** pro Transaktion (nicht pro Einzelposten):

```php
// ✅ Abschneiden auf Transaktionsebene
$taxIncluded  = intdiv($priceExcl * 110, 100);  // 1000 → 1100
$taxAmount    = intdiv($priceExcl * 10, 100);   // 1000 → 100

// ❌ NICHT pro Einzelposten runden und dann summieren — Rundungsfehler akkumulieren sich
$items = [100, 100, 100]; // 3 Artikel × 100 Yen
$total = array_sum(array_map(fn($p) => (int)round($p * 1.1), $items)); // kann von intdiv(300 * 110, 100) abweichen
```

Beim Speichern eines `tax_rate`-Werts diesen als **Basispunkte** (Integer, 1/10000) speichern: `10% = 1000 bps`. Vermeidet Fließkomma bei der Ratenspeicherung selbst.

```sql
tax_rate_bps INTEGER NOT NULL DEFAULT 1000  -- 10,00%
```

```php
$taxAmount = intdiv($amount * $taxRateBps, 10000);
```

---

## Aufteilung: Restverteilung

Beim Aufteilen eines Gesamtbetrags auf N Teilnehmer:

```php
function splitEvenly(int $totalYen, int $n): array
{
    $base      = intdiv($totalYen, $n);       // Anteil jeder Person (abgeschnitten)
    $remainder = $totalYen % $n;              // verbleibende Yen (0 bis n-1)

    $shares = array_fill(0, $n, $base);

    // Rest 1 Yen auf einmal an die ersten Teilnehmer verteilen
    for ($i = 0; $i < $remainder; $i++) {
        $shares[$i]++;
    }

    // Verifizieren: Summe muss dem Originalbetrag entsprechen
    assert(array_sum($shares) === $totalYen);

    return $shares;
}

// splitEvenly(1000, 3) → [334, 333, 333]  (Summe = 1000) ✅
// splitEvenly(100,  3) → [34,  33,  33]   (Summe = 100)  ✅
```

Niemals `round($total / $n)` für jeden Teilnehmer verwenden — die Summe wird oft um 1 Yen abweichen.

---

## SQLite Integer-Divisions-Falle

In SQLite führt die Division zweier Integer eine Integer-Division durch:

```sql
SELECT 5 / 100;     -- → 0  (Integer-Division: abschneiden)
SELECT 5.0 / 100;   -- → 0.05 (reelle Division)
SELECT 5 * 100 / 100;  -- → 5 (erst multiplizieren, dann dividieren — OK)
```

**In PHP** mit PDO werden alle gebundenen Werte als Strings gesendet. SQLite konvertiert sie, aber:

```php
// Sicher: erst multiplizieren, um Abschneiden zu vermeiden
$fee = $this->db->fetchOne(
    'SELECT amount_yen * 5 / 100 AS fee FROM orders WHERE id = ?',
    [$id],
);
// → amount_yen * 5 zuerst (Integer * Integer = Integer), dann / 100

// Riskant: wenn PDO '5' und '100' als Strings sendet, könnte SQLite reelle Division wählen
// Dies testen, wenn die SQLite-Version oder das PDO-Verhalten unklar ist.
```

Der sicherste Ansatz: **Arithmetik in PHP mit `intdiv()` durchführen**, das Ergebnis speichern und SQL-Arithmetik nur für Summierungen (`SUM`, `COUNT`) verwenden, nicht für Berechnungen pro Zeile.

---

## Lineare Abschreibung

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

`intdiv` schneidet die jährliche Abschreibung ab, was bedeutet, dass das Anlagegut leicht weniger pro Jahr abschreibt und der Rest im letzten Jahr als zusätzliche Abschreibung erscheint. Das ist das Standardverhalten für die japanische lineare Abschreibung.

---

## Anzeige für den Benutzer

Nur in der Antwortschicht in ein menschenlesbares Format konvertieren, niemals in der Domain:

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

1. **Einheit**: Yen (kein Dezimal), Cent (1/100) oder Micropenny (1/1000)?
   → Als Integer in dieser Einheit speichern; die Einheit im Spaltennamen dokumentieren (`amount_yen`, `price_cents`).

2. **Rundungsrichtung**: `intdiv` (abschneiden), `ceil`, `floor` oder `round`?
   → Eine wählen; einen Kommentar im Code hinzufügen, der erklärt warum.

3. **Wer erhält den Rest**: Wer absorbiert bei der Aufteilung den Rundungsunterschied?
   → Den Rest explizit verteilen (siehe `splitEvenly` oben).

4. **Steuersatz-Speicherung**: Basispunkte (`INTEGER`) nicht Prozent (`REAL`)?
   → `1000` für 10 %, `800` für 8 %, niemals `0.10` oder `0.08`.

5. **Kumulativ oder pro Transaktion**: Steuer pro Einzelposten oder pro Rechnungsgesamt akkumulieren?
   → Pro Transaktion (einzelnes `intdiv`) ist Standard für JPY-Rechnungen.

---

## Verwandte Anleitungen

- [`multi-currency-money-ledger.md`](multi-currency-money-ledger.md) — doppeltes Buchführungssystem mit `Money`-Value-Object
- [`point-ledger-api.md`](point-ledger-api.md) — Punkte-/Kreditsystem mit Integer-Beträgen
- [`expense-tracking-api.md`](expense-tracking-api.md) — Ausgabenerfassung mit Integer-Yen
