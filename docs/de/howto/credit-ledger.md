# How-to: Credit-Ledger-API

> **FT-Referenz**: FT234 (`NENE2-FT/creditslog`) — Credit-Ledger-API

Demonstriert ein append-only-Credit-Ledger, bei dem Guthaben nie direkt gespeichert wird — es wird zum Abfragezeitpunkt als `SUM(amount * direction)` berechnet. Unterstützt Credits verdienen, Credits ausgeben mit Overdraft-Schutz, idempotentes Verdienen über einen Unique Key und einen filterbaren Transaktionsverlauf.

---

## Routen

| Methode | Pfad | Beschreibung |
|---------|------|--------------|
| `POST` | `/users/{userId}/credits/earn` | Credits verdienen (zum Guthaben hinzufügen) |
| `POST` | `/users/{userId}/credits/spend` | Credits ausgeben (vom Guthaben abziehen, 409 bei Überzug) |
| `GET` | `/users/{userId}/credits/balance` | Aktuelles Guthaben abrufen |
| `GET` | `/users/{userId}/credits/transactions` | Transaktionsverlauf auflisten (optional `?type=`) |

---

## Ledger-Modell: `direction` statt vorzeichenbehaftetem Betrag

Anstatt positive und negative Beträge zu speichern, speichert jede Transaktion einen positiven `amount` und eine vorzeichenbehaftete `direction` (`+1` für verdienen, `-1` für ausgeben):

```sql
CREATE TABLE IF NOT EXISTS credit_transactions (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id         TEXT    NOT NULL,
    type            TEXT    NOT NULL CHECK(type IN ('earn', 'spend', 'adjust')),
    amount          INTEGER NOT NULL CHECK(amount > 0),
    direction       INTEGER NOT NULL CHECK(direction IN (1, -1)),
    description     TEXT    NOT NULL DEFAULT '',
    idempotency_key TEXT    UNIQUE,
    created_at      TEXT    NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_credit_transactions_user ON credit_transactions (user_id);
```

Vorteile des `direction`-Spalten-Musters:
- `CHECK(amount > 0)` erzwingt, dass der Rohbetrag immer positiv ist — keine versehentlichen Doppel-Negations-Bugs beim Einfügen.
- `CHECK(direction IN (1, -1))` beschränkt den Multiplikator auf zwei gültige Werte.
- Die Guthabenformel ist einheitlich: `SUM(amount * direction)` — keine bedingte Verzweigung in der Aggregation.
- Ein `adjust`-Typ ist für manuelle Korrekturen verfügbar (z.B. Rückerstattungen, Admin-Zuschüsse) mit beiden Richtungen.

---

## Guthabenberechnung

Das Guthaben wird zum Lesezeitpunkt berechnet — keine `balance`-Spalte wird jemals aktualisiert:

```php
public function balance(string $userId): int
{
    $row = $this->db->fetchOne(
        'SELECT COALESCE(SUM(amount * direction), 0) AS bal FROM credit_transactions WHERE user_id = ?',
        [$userId],
    );

    return (int) ($row['bal'] ?? 0);
}
```

`COALESCE(..., 0)` behandelt den Fall, dass ein Benutzer keine Transaktionen hat — `SUM` einer leeren Menge gibt in SQL `NULL` zurück, das ohnehin zu `0` gecastet werden würde, aber `COALESCE` macht die Absicht explizit.

Der Index auf `user_id` stellt sicher, dass die `SUM`-Aggregation nur die Zeilen dieses Benutzers scannt. Für große Ledger ist eine gecachte Guthabenspalte mit optimistischem Sperren oder event-sourced-Snapshots der Überlegung wert (siehe `add-optimistic-locking.md`).

---

## Verdienen mit optionalem Idempotenzschlüssel

Die Angabe von `idempotency_key` macht die Verdienstoperation retry-sicher — ein doppelter Schlüssel gibt die ursprüngliche Transaktion zurück, anstatt eine neue einzufügen:

```php
public function earn(string $userId, int $amount, string $description, ?string $idempotencyKey): CreditTransaction
{
    $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');

    if ($idempotencyKey !== null) {
        try {
            $id = $this->db->insert(
                'INSERT INTO credit_transactions (user_id, type, amount, direction, description, idempotency_key, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$userId, 'earn', $amount, 1, $description, $idempotencyKey, $now],
            );
        } catch (DatabaseConstraintException) {
            // Schlüssel bereits verwendet — ursprüngliche Transaktion zurückgeben
            $row = $this->db->fetchOne(
                'SELECT * FROM credit_transactions WHERE idempotency_key = ?',
                [$idempotencyKey],
            );
            assert($row !== null);

            return $this->hydrate($row);
        }
    } else {
        $id = $this->db->insert(
            'INSERT INTO credit_transactions (user_id, type, amount, direction, description, idempotency_key, created_at) VALUES (?, ?, ?, ?, ?, NULL, ?)',
            [$userId, 'earn', $amount, 1, $description, $now],
        );
    }

    $row = $this->db->fetchOne('SELECT * FROM credit_transactions WHERE id = ?', [$id]);
    assert($row !== null);

    return $this->hydrate($row);
}
```

Der `UNIQUE`-Constraint auf `idempotency_key` macht die DB zur Autorität — die Anwendung fängt `DatabaseConstraintException` ab und holt die vorhandene Zeile erneut. Dies vermeidet eine SELECT-vor-INSERT-Race-Condition: zwei gleichzeitige Retries mit demselben Schlüssel führen zu genau einem erfolgreichen INSERT.

---

## Ausgeben mit Overdraft-Schutz

```php
public function spend(string $userId, int $amount, string $description): CreditTransaction
{
    $balance = $this->balance($userId);
    if ($balance < $amount) {
        throw new InsufficientCreditsException("Insufficient credits: balance={$balance}, requested={$amount}");
    }

    $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    $id  = $this->db->insert(
        'INSERT INTO credit_transactions (user_id, type, amount, direction, description, idempotency_key, created_at) VALUES (?, ?, ?, ?, ?, NULL, ?)',
        [$userId, 'spend', $amount, -1, $description, $now],
    );
    // ...
}
```

Der Controller ordnet `InsufficientCreditsException` zu `409 Conflict` zu:

```php
try {
    $tx = $this->repo->spend($userId, $amount, $description);
} catch (InsufficientCreditsException $e) {
    return $this->problems->create($request, 'insufficient-credits', 'Insufficient Credits', 409, $e->getMessage());
}
```

`409 Conflict` wird `422 Unprocessable Entity` vorgezogen, weil die Anfrage gültig ist — es ist der Guthabenzustand, der sie verhindert. Ein Aufrufer, der nach dem Verdienen weiterer Credits erneut versucht, wird Erfolg haben.

> **Gleichzeitigkeitshinweis**: Die Guthabenprüfung und das Einfügen sind nicht in eine Transaktion eingebettet. Zwei gleichzeitige Ausgabeanfragen können beide ein ausreichendes Guthaben lesen und beide einfügen, wodurch das Guthaben negativ wird. In eine Transaktion mit `SELECT ... FOR UPDATE` (MySQL/PostgreSQL) einwickeln oder SQLites serialisierte Schreibvorgänge für Korrektheit unter Gleichzeitigkeit verwenden.

---

## Betragsvalidierung

```php
$amount = isset($body['amount']) && is_int($body['amount']) ? $body['amount'] : 0;

$errors = [];
if ($amount <= 0) {
    $errors[] = ['field' => 'amount', 'code' => 'invalid', 'message' => 'amount must be a positive integer.'];
}
```

Strikter `is_int()`-Check lehnt JSON-Floats (`1.5`) und Strings (`"10"`) ab. Der DB-Level-`CHECK(amount > 0)` dient als Sicherheitsnetz, aber das Ablehnen auf Anwendungsebene gibt eine strukturierte Problem-Details-Antwort statt eines DB-Fehlers zurück.

---

## Transaktionsverlauf mit Typfilter

```php
private function transactions(ServerRequestInterface $request): ResponseInterface
{
    $params = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
    $userId = (string) ($params['userId'] ?? '');
    $q      = $request->getQueryParams();
    $type   = isset($q['type']) && is_string($q['type']) ? $q['type'] : null;

    $txs = $this->repo->listTransactions($userId, $type);

    return $this->json->create([
        'user_id'      => $userId,
        'transactions' => array_map(fn (CreditTransaction $t) => $t->toArray(), $txs),
    ]);
}
```

`?type=earn` oder `?type=spend` schränkt die Liste ein. Für den Typwert wird keine Validierung durchgeführt — ein unbekannter Typ (z.B. `?type=refund`) gibt eine leere Liste statt eines Fehlers zurück, was für einen Filterparameter akzeptabel ist.

---

## Schema-Design-Hinweise

| Spalte | Zweck |
|--------|-------|
| `amount` | Immer positiv; `CHECK(amount > 0)` erzwingt dies |
| `direction` | `+1` (verdienen) oder `-1` (ausgeben); `CHECK(direction IN (1, -1))` |
| `type` | Menschliche Bezeichnung: `earn`, `spend`, `adjust`; `CHECK`-Allowlist |
| `idempotency_key` | Optionaler `UNIQUE`-Schlüssel für retry-sichere Verdienstoperationen |
| `description` | Freitext-Memo für die Transaktion |

Keine `balance`-Spalte — das aktuelle Guthaben wird immer aus dem Ledger abgeleitet.

---

## Verwandte Anleitungen

- [`idempotency.md`](idempotency.md) — allgemeine Idempotenzschlüssel-Muster
- [`multi-currency-wallet.md`](multi-currency-wallet.md) — Multi-Währungs-Guthabenverwaltung
- [`point-loyalty-system.md`](point-loyalty-system.md) — Punkte verdienen/einlösen mit Stufenebenen
- [`add-optimistic-locking.md`](add-optimistic-locking.md) — Gecachtes Guthaben mit Versions-Schutz
- [`transactions.md`](transactions.md) — Guthabenprüfung und Einfügen in eine Transaktion einwickeln
