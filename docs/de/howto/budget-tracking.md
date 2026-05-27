# How-to: Budget-Tracking-API

> **FT-Referenz**: FT244 (`NENE2-FT/budgetlog`) — Budget-Tracking-API
> **ATK**: FT244 — Cracker-Mindset-Angriffstest (ATK-01 bis ATK-12)

Demonstriert eine Multi-Konto-Budget-Tracking-API mit den Transaktionstypen `income`/`expense`/`transfer`,
`TransferFundsUseCase` mit Saldoprüfung innerhalb einer DB-Transaktion, Multi-Filter-Transaktionsauflistung
mit `QueryStringParser` und Kategorieaggregation.

---

## Routen

| Methode | Pfad | Beschreibung |
|--------|------|-------------|
| `GET`  | `/accounts` | Alle Konten auflisten |
| `POST` | `/accounts` | Ein Konto erstellen (optionaler Anfangssaldo) |
| `GET`  | `/accounts/{id}` | Ein einzelnes Konto abrufen |
| `POST` | `/accounts/{id}/transactions` | Einnahmen- oder Ausgabentransaktion erfassen |
| `GET`  | `/accounts/{id}/transactions` | Transaktionen auflisten (filterbar, paginiert) |
| `GET`  | `/accounts/{id}/summary` | Saldo + Einnahmen/Ausgaben nach Kategorie |
| `POST` | `/transfers` | Geld zwischen zwei Konten überweisen |

---

## Schema

```sql
CREATE TABLE IF NOT EXISTS accounts (
    id      INTEGER PRIMARY KEY AUTOINCREMENT,
    name    TEXT    NOT NULL,
    balance INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE IF NOT EXISTS transactions (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    account_id  INTEGER NOT NULL REFERENCES accounts(id) ON DELETE CASCADE,
    amount      INTEGER NOT NULL,
    type        TEXT    NOT NULL CHECK(type IN ('income','expense','transfer')),
    category    TEXT    NOT NULL,
    description TEXT    NOT NULL DEFAULT '',
    recurring   INTEGER NOT NULL DEFAULT 0,
    created_at  TEXT    NOT NULL
);
```

`balance` und `amount` werden als Integer gespeichert (kleinste Währungseinheit, z. B. Cent).
`type` wird auf DB-Ebene durch `CHECK(type IN ('income','expense','transfer'))` eingeschränkt.
`recurring` wird als `INTEGER` (`0`/`1`) gespeichert und auf ein PHP-`bool` abgebildet.

---

## Transaktionstyp-Allowlist

Der Controller validiert `type` gegen eine explizite Allowlist:

```php
if (!in_array($type, ['income', 'expense'], true)) {
    $errors[] = new ValidationError('type', 'Type must be income or expense.', 'invalid_value');
}
```

Über die API werden nur `income` und `expense` akzeptiert. Der Typ `transfer` wird intern von `TransferFundsUseCase` gesetzt — Aufrufer können ihn nicht direkt über `POST /accounts/{id}/transactions` einschleusen.

---

## Saldo-Update: Lesen-dann-Schreiben-Muster

`POST /accounts/{id}/transactions` aktualisiert den Kontosaldo nach der Erfassung der Transaktion:

```php
$delta = $type === 'income' ? $amount : -$amount;
$this->accounts->updateBalance($id, $account->balance + $delta);
```

Der Saldo wird zuerst gelesen (`findById`), das Delta in PHP berechnet, dann zurückgeschrieben (`updateBalance`). Dies ist **nicht atomar** — gleichzeitige Anfragen können eine Race Condition erzeugen (siehe ATK-09).

---

## TransferFundsUseCase: Saldoprüfung + DB-Transaktion

Überweisungen werden in einer DB-Transaktion durchgeführt, um Konsistenz zu gewährleisten:

```php
public function execute(int $fromId, int $toId, int $amount, string $description): void
{
    if ($amount <= 0) {
        throw new ValidationException([
            new ValidationError('amount', 'Amount must be greater than zero.', 'out_of_range'),
        ]);
    }

    $this->txManager->transactional(function (DatabaseQueryExecutorInterface $tx) use ($fromId, $toId, $amount, $description): void {
        // Repositories innerhalb des Callbacks mit dem Transaktions-Executor instanzieren
        $accounts     = new SqliteAccountRepository($tx);
        $transactions = new SqliteTransactionRepository($tx);

        $from = $accounts->findById($fromId);
        $to   = $accounts->findById($toId);

        if ($from === null) {
            throw new ValidationException([new ValidationError('from_account_id', 'Source account not found.', 'not_found')]);
        }
        if ($to === null) {
            throw new ValidationException([new ValidationError('to_account_id', 'Destination account not found.', 'not_found')]);
        }
        if ($from->balance < $amount) {
            throw new ValidationException([new ValidationError('amount', 'Insufficient balance.', 'insufficient_balance')]);
        }

        $accounts->updateBalance($fromId, $from->balance - $amount);
        $accounts->updateBalance($toId, $to->balance + $amount);

        $transactions->create($fromId, $amount, 'transfer', 'transfer', $description, false, $now);
        $transactions->create($toId, $amount, 'transfer', 'transfer', $description, false, $now);
    });
}
```

Repositories werden **innerhalb** des Transaktions-Closures mit dem `$tx`-Executor instanziert — dies stellt sicher, dass alle Lese- und Schreibvorgänge dieselbe Verbindung und Transaktionsgrenze teilen. Wenn ein Schritt eine Exception wirft, wird die gesamte Transaktion zurückgerollt.

Die Gleich-Konto-Prüfung befindet sich im Controller:
```php
if ($fromId === $toId && $fromId > 0) {
    $errors[] = new ValidationError('to_account_id', 'Cannot transfer to the same account.', 'invalid_value');
}
```

---

## Multi-Filter-Transaktionsauflistung

`GET /accounts/{id}/transactions` unterstützt mehrere gleichzeitige Filter:

```php
$category  = QueryStringParser::string($req, 'category');
$minAmount = QueryStringParser::int($req, 'min_amount');
$maxAmount = QueryStringParser::int($req, 'max_amount');
$recurring = QueryStringParser::bool($req, 'recurring');
```

`QueryStringParser::int()` gibt `null` zurück, wenn der Parameter fehlt — kein Filter.
`QueryStringParser::bool()` gibt `null` zurück bei Fehlen, `true` für `"true"/"1"`, `false` für `"false"/"0"`.

Das Repository erstellt die `WHERE`-Klausel dynamisch:

```php
if ($category !== null)  { $where[] = 'category = ?'; $params[] = $category; }
if ($minAmount !== null) { $where[] = 'amount >= ?';  $params[] = $minAmount; }
if ($maxAmount !== null) { $where[] = 'amount <= ?';  $params[] = $maxAmount; }
if ($recurring !== null) { $where[] = 'recurring = ?'; $params[] = (int) $recurring; }
```

---

## Kategorie-Zusammenfassungsaggregation

`GET /accounts/{id}/summary` gibt Saldo und Summen gruppiert nach Kategorie zurück:

```php
return $this->json->create([
    'balance'             => $account->balance,
    'income_by_category'  => $incomeByCategory,
    'expense_by_category' => $expenseByCategory,
]);
```

Das Repository verwendet `GROUP BY category` mit `SUM(amount)`:

```sql
SELECT category, SUM(amount) AS total
FROM transactions
WHERE account_id = ? AND type = ?
GROUP BY category
ORDER BY total DESC
```

---

## ATK — Cracker-Mindset-Angriffstest (FT244)

### ATK-01 — Keine Authentifizierung: Konten und Transaktionen sind öffentlich

**Angriff**: Alle Konten ohne Anmeldeinformationen auflisten.

```bash
curl -s http://localhost:8080/accounts
curl -s http://localhost:8080/accounts/1/transactions
```

**Beobachtet**: Beide Endpunkte geben Daten ohne Authentifizierung zurück. Jeder Aufrufer kann alle Konten und ihre Salden enumerieren.

**Urteil**: **EXPOSED** — Authentifizierung (API-Key, JWT oder Session) zu allen Endpunkten hinzufügen. Konten sollten benutzerbezogen sein.

---

### ATK-02 — Konto mit negativem Anfangssaldo erstellen

**Angriff**: Negativsaldo-Prüfung umgehen.

```json
{"name": "Attack", "initial_balance": -99999}
```

**Beobachtet**: `$initialBalance < 0`-Prüfung greift → `422 Unprocessable Entity` mit `out_of_range`-Fehler.

**Urteil**: **BLOCKED** — expliziter Guard lehnt negative Anfangssalden ab.

---

### ATK-03 — Ausgabe treibt Kontosaldo negativ

**Angriff**: Eine Ausgabe größer als der Kontosaldo über eine direkte Transaktion erfassen.

```bash
# Konto hat Saldo 100
curl -X POST /accounts/1/transactions \
  -d '{"amount": 99999, "type": "expense", "category": "food"}'
```

**Beobachtet**: Der `createTransaction`-Handler liest den Saldo und subtrahiert ohne Ausreichlichkeitsprüfung. `100 - 99999 = -99899` — der Saldo wird als negative Ganzzahl geschrieben.

**Urteil**: **EXPOSED** — `POST /accounts/{id}/transactions` erzwingt keine nicht-negative Saldobeschränkung. Nur `POST /transfers` (via `TransferFundsUseCase`) prüft `if ($from->balance < $amount)`. Eine Saldo-Ausreichlichkeitsprüfung in `createTransaction` für Ausgabentransaktionen hinzufügen.

---

### ATK-04 — SQL-Injection via Kategorie oder Beschreibung

**Angriff**: SQL-Metazeichen in `category` oder `description` einbetten.

```json
{"amount": 1, "type": "income", "category": "'; DROP TABLE transactions; --"}
```

**Beobachtet**: Alle Werte werden als parametrisierte `?`-Werte gebunden. Keine String-Verkettung mit SQL. Der Injection-Payload wird als Literal-Text gespeichert.

**Urteil**: **BLOCKED** — parametrisierte Abfragen verhindern SQL-Injection.

---

### ATK-05 — Float-Betrag: `(int)`-Cast-Abschneidung

**Angriff**: Einen Gleitkomma-Betrag senden.

```json
{"amount": 1.9, "type": "income", "category": "x"}
```

**Beobachtet**: `(int) $body['amount']` schneidet `1.9` auf `1` ab. Der Betrag `1.9` wird stillschweigend akzeptiert und als `1` gespeichert. Ein Aufrufer, der erwartet, dass `1.9` abgelehnt (oder auf `2` gerundet) wird, wäre überrascht.

**Urteil**: **TEILWEISE BLOCKED** — Nicht-Integer-Floats werden akzeptiert und stillschweigend abgeschnitten. `is_int($body['amount'])` verwenden, um Nicht-Integer-Typen explizit abzulehnen und `422` für `1.9` zurückzugeben.

---

### ATK-06 — Null- oder negativer Betrag

**Angriff**: `amount: 0` oder `amount: -100` übermitteln.

```json
{"amount": 0, "type": "income", "category": "x"}
{"amount": -100, "type": "income", "category": "x"}
```

**Beobachtet**: `$amount <= 0`-Prüfung greift für beide → `422 Unprocessable Entity`.

**Urteil**: **BLOCKED** — expliziter Guard lehnt Null- und Negativbeträge ab.

---

### ATK-07 — Überweisung auf dasselbe Konto

**Angriff**: Geld von einem Konto auf dasselbe Konto überweisen.

```json
{"from_account_id": 1, "to_account_id": 1, "amount": 100}
```

**Beobachtet**: `$fromId === $toId && $fromId > 0` greift → `422 Unprocessable Entity` mit `invalid_value`-Fehler auf `to_account_id`.

**Urteil**: **BLOCKED** — Gleich-Konto-Überweisung wird explizit abgelehnt.

---

### ATK-08 — Überweisung mit unzureichendem Saldo

**Angriff**: Mehr als den Saldo des Quellkontos überweisen.

```json
{"from_account_id": 1, "to_account_id": 2, "amount": 99999}
```

**Beobachtet**: Innerhalb der Transaktion greift `$from->balance < $amount` → `ValidationException` mit `insufficient_balance` → Transaktion wird zurückgerollt → `422`. Kein Saldo ändert sich.

**Urteil**: **BLOCKED** — `TransferFundsUseCase` prüft den Saldo innerhalb der DB-Transaktion. Rollback stellt Atomarität sicher.

---

### ATK-09 — Race Condition bei direkter Ausgabentransaktion

**Angriff**: Zwei gleichzeitige Ausgaben-Anfragen senden, die beide die Saldoprüfung bestehen (es gibt keine), aber zusammen den Saldo überschreiten.

**Beobachtet**: `createTransaction` verwendet ein Lesen-dann-Schreiben-Muster ohne Transaktion:
1. Thread A liest `balance = 100`
2. Thread B liest `balance = 100`
3. Thread A erfasst Ausgabe von 80 → schreibt `balance = 20`
4. Thread B erfasst Ausgabe von 80 → schreibt `balance = 20` (sollte -60 sein)

Die `balance`-Spalte endet bei `20` statt dem korrekten `-60` — aber noch kritischer: die Geschäftsbeschränkung (nicht-negativer Saldo) wird für direkte Transaktionen überhaupt nicht erzwungen.

**Urteil**: **EXPOSED** — der `createTransaction`-Pfad hat keinen Saldo-Guard und kein Transaktions-Wrapping. Beheben durch: (1) Hinzufügen von `if ($type === 'expense' && $account->balance < $amount) → 422`, und (2) das Lesen-dann-Schreiben in einer DB-Transaktion einschließen.

---

### ATK-10 — Zugriff auf Transaktionen eines anderen Kontos (keine Eigentumsrechte)

**Angriff**: Transaktionen lesen, die einem anderen Benutzerkonto gehören.

```bash
curl -s http://localhost:8080/accounts/2/transactions
```

**Beobachtet**: Der Endpunkt gibt alle Transaktionen für Konto 2 ohne Eigentümerprüfung zurück. Da keine Authentifizierung vorhanden ist, kann jeder Aufrufer jedes Konto lesen.

**Urteil**: **EXPOSED** (gleiche Grundursache wie ATK-01). Konten müssen auf einen authentifizierten Benutzer begrenzt sein — `WHERE account_id = ? AND owner_id = ?`.

---

### ATK-11 — `recurring`-Feld: Wahrheitswert-Zwang

**Angriff**: Nicht-boolesche Werte für `recurring` senden.

```json
{"amount": 1, "type": "income", "category": "x", "recurring": "yes"}
{"amount": 1, "type": "income", "category": "x", "recurring": 1}
{"amount": 1, "type": "income", "category": "x", "recurring": 0}
```

**Beobachtet**: `(bool) $body['recurring']` wandelt `"yes"` → `true`, `1` → `true`, `0` → `false` um. Jeder truthy-String-Wert setzt `recurring = true`. Es gibt keine strikte `is_bool()`-Prüfung.

**Urteil**: **TEILWEISE BLOCKED** — Nicht-boolesche Typen werden stillschweigend umgewandelt. `is_bool($body['recurring'])` für strikte Typprüfung verwenden und `422` für nicht-booleschen Input zurückgeben.

---

### ATK-12 — Nicht-numerische Konto-ID im Pfad

**Angriff**: Einen String als ID im Pfadparameter übergeben.

```
GET /accounts/abc/transactions
GET /accounts/1.5/transactions
```

**Beobachtet**: `(int) 'abc'` = `0`, `(int) '1.5'` = `1`.
- `abc` → `findById(0)` → gibt `null` zurück → `404 Not Found`.
- `1.5` → `findById(1)` → wenn Konto 1 existiert, wird es stillschweigend zurückgegeben.

**Urteil**: **TEILWEISE BLOCKED** — nicht-numerische Strings werden auf 404 abgebildet. Float-Strings werden stillschweigend abgeschnitten. `ctype_digit()`-Validierung für strenge Pfadparameter-Prüfung hinzufügen.

---

## ATK-Zusammenfassung

| # | Angriffsvektor | Urteil |
|---|----------------|--------|
| ATK-01 | Keine Authentifizierung (alle Endpunkte öffentlich) | EXPOSED |
| ATK-02 | Negativer Anfangssaldo | BLOCKED |
| ATK-03 | Ausgabe treibt Saldo negativ | EXPOSED |
| ATK-04 | SQL-Injection via Kategorie/Beschreibung | BLOCKED |
| ATK-05 | Float-Betrag stillschweigend abgeschnitten | TEILWEISE BLOCKED |
| ATK-06 | Null- oder negativer Betrag | BLOCKED |
| ATK-07 | Überweisung auf dasselbe Konto | BLOCKED |
| ATK-08 | Überweisung mit unzureichendem Saldo | BLOCKED |
| ATK-09 | Race Condition bei direkter Ausgabe | EXPOSED |
| ATK-10 | Kontoübergreifender Datenzugriff (keine Eigentumsrechte) | EXPOSED |
| ATK-11 | `recurring`-Nicht-Boolean-Zwang | TEILWEISE BLOCKED |
| ATK-12 | Nicht-numerische Konto-ID | TEILWEISE BLOCKED |

**Echte Schwachstellen, die vor der Produktion behoben werden müssen**:
1. **ATK-01 / ATK-10** — Authentifizierung und Pro-Benutzer-Kontoeigentümerschaft hinzufügen
2. **ATK-03 / ATK-09** — Saldo-Ausreichlichkeitsprüfung + DB-Transaktion in `createTransaction` hinzufügen
3. **ATK-05** — `(int)`-Cast durch `is_int()`-Prüfung für strikte Typprüfung ersetzen
4. **ATK-11** — `(bool)`-Cast durch `is_bool()`-Prüfung ersetzen
5. **ATK-12** — `ctype_digit()`-Guard für ID-Pfadparameter hinzufügen

---

## Verwandte Anleitungen

- [`credit-ledger.md`](credit-ledger.md) — Append-only-Ledger mit Richtung ±1 und InsufficientCreditsException
- [`multi-currency-wallet.md`](multi-currency-wallet.md) — Multi-Währungs-Saldoverwaltung
- [`transactions.md`](transactions.md) — DatabaseTransactionManagerInterface-Muster
- [`note-management-ownership.md`](note-management-ownership.md) — Pro-Benutzer-Ressourceneigentümerschaft mit IDOR-Prävention
