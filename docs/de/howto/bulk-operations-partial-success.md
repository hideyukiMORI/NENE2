# How-to: Bulk-Operationen mit Teilerfolg-Semantik

> **FT-Referenz**: FT258 (`NENE2-FT/bulklog`) — Bulk-Create / Bulk-Delete mit Teilerfolg-Semantik und HTTP 207 Multi-Status

Demonstriert die Behandlung von Bulk-API-Operationen, bei denen einige Elemente erfolgreich sein können und andere nicht.
Jedes Element wird unabhängig verarbeitet — ein Validierungsfehler bei Element N bricht die Verarbeitung von Element N+1 nicht ab.
Die Antwort enthält zwei Arrays: `created` (erfolgreich) und `errors` (fehlgeschlagen mit Gründen).
HTTP 207 Multi-Status wird zurückgegeben, wenn es eine Mischung gibt; 201 Created, wenn alle erfolgreich sind.

---

## Routen

| Methode | Pfad | Beschreibung |
|----------|---------------|-----------------------------------------------|
| `POST`   | `/items`      | Ein einzelnes Element erstellen |
| `GET`    | `/items/{id}` | Ein einzelnes Element abrufen |
| `POST`   | `/items/bulk` | Bulk-Elemente erstellen (Teilerfolg) |
| `DELETE` | `/items/bulk` | Bulk-Elemente nach ID löschen (Teilerfolg) |

> **Routenreihenfolge**: `/items/bulk` muss vor `/items/{id}` registriert werden, damit das Literal-Segment `bulk` nicht als Pfadparameter aufgefangen wird.

---

## Schema

```sql
CREATE TABLE IF NOT EXISTS items (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    sku        TEXT NOT NULL UNIQUE,
    name       TEXT NOT NULL,
    price      INTEGER NOT NULL,
    created_at TEXT NOT NULL
);
```

`sku TEXT NOT NULL UNIQUE` verhindert doppelte SKUs auf DB-Ebene. `price INTEGER` speichert den Preis in der kleinsten Währungseinheit (Cent/Yen), um Gleitkomma-Rundungsfehler zu vermeiden.

---

## BulkResult-DTO

```php
final readonly class BulkResult
{
    /**
     * @param list<array<string, mixed>> $created
     * @param list<array<string, mixed>> $errors
     */
    public function __construct(
        public array $created,
        public array $errors,
    ) {}

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }
}
```

`created` enthält die erfolgreich erstellten Einträge. `errors` enthält pro-Element-Fehlerbeschreibungen. `hasErrors()` ist ein einfaches Prädikat, das der Controller verwendet, um den HTTP-Statuscode zu wählen.

---

## Bulk-Create: Validierung pro Element

```php
public function bulkCreate(array $inputs, string $now): BulkResult
{
    $created = [];
    $errors  = [];

    foreach ($inputs as $index => $input) {
        $sku   = isset($input['sku'])   && is_string($input['sku'])   ? trim($input['sku'])   : '';
        $name  = isset($input['name'])  && is_string($input['name'])  ? trim($input['name'])  : '';
        $price = isset($input['price']) && is_int($input['price'])    ? $input['price']       : -1;

        $itemErrors = [];
        if ($sku === '') {
            $itemErrors[] = 'sku is required';
        } elseif ($this->skuExists($sku)) {
            $itemErrors[] = "sku \"{$sku}\" already exists";
        }
        if ($name === '') {
            $itemErrors[] = 'name is required';
        }
        if ($price < 0) {
            $itemErrors[] = 'price must be a non-negative integer';
        }

        if ($itemErrors !== []) {
            $errors[] = ['index' => $index, 'sku' => $sku, 'errors' => $itemErrors];
            continue;   // Einfügen überspringen, zum nächsten Element fortfahren
        }

        $item      = $this->create($sku, $name, $price, $now);
        $created[] = $item->toArray();
    }

    return new BulkResult($created, $errors);
}
```

**Wichtige Entscheidungen**:
- `continue` bei Validierungsfehler: fehlgeschlagene Elemente brechen die Schleife nicht ab.
- `$index` wird in den Fehlereintrag aufgenommen: Clients wissen, welche Position in ihrem Eingabe-Array fehlgeschlagen ist.
- SKU-Eindeutigkeit wird in PHP (`skuExists()`) vor INSERT geprüft, nicht aus DB-Exceptions abgefangen. Das liefert eine klarere, anwendungsseitige Fehlermeldung statt einer rohen Constraint-Verletzung.
- Alle erfolgreichen INSERTs teilen denselben `$now`-Zeitstempel: der Batch wird als ein einziger Zeitpunkt behandelt.

---

## Bulk-Delete: Not-Found-Tracking

```php
public function bulkDelete(array $ids): array
{
    $deleted  = [];
    $notFound = [];

    foreach ($ids as $id) {
        $item = $this->findById($id);
        if ($item === null) {
            $notFound[] = $id;
            continue;
        }
        $this->executor->execute('DELETE FROM items WHERE id = ?', [$id]);
        $deleted[] = $id;
    }

    return ['deleted' => $deleted, 'not_found' => $notFound];
}
```

Nicht-gefundene IDs werden verfolgt, brechen aber die Operation nicht ab. Die Antwort ermöglicht es dem Aufrufer zu prüfen, welche IDs tatsächlich gelöscht wurden und welche bereits fehlten. 200 zurückzugeben (nicht 207) ist hier sinnvoll, da alle angeforderten Löschvorgänge entweder erfolgreich waren oder bereits nicht vorhanden waren — es gibt keinen "Fehler"-Zustand.

---

## Controller: HTTP 207 Multi-Status

```php
private function bulkCreate(ServerRequestInterface $request): ResponseInterface
{
    $body = JsonRequestBodyParser::parse($request);

    if (!isset($body['items']) || !is_array($body['items'])) {
        return $this->problems->create($request, 'validation-failed', 'Validation Failed', 422, null, [
            'errors' => [['field' => 'items', 'code' => 'required', 'message' => 'items array is required.']],
        ]);
    }

    $inputs = array_values($body['items']);
    $now    = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
    $result = $this->repo->bulkCreate($inputs, $now);

    $status = $result->hasErrors() ? 207 : 201;   // ← 207 bei Mischung aus Erfolg + Fehler

    return $this->json->create($result->toArray(), $status);
}
```

**HTTP-Statusauswahl**:

| Ergebnis | Status | Bedeutung |
|---|---|---|
| Alle erstellt | `201 Created` | Vollständiger Erfolg |
| Einige erstellt, einige fehlgeschlagen | `207 Multi-Status` | Teilerfolg — Client muss den Body prüfen |
| Alle fehlgeschlagen | `207 Multi-Status` | Vollständiger Fehler — `created`-Array ist leer |
| Kein `items`-Array | `422 Unprocessable Entity` | Fehlerhafte Anfrage |

`207` signalisiert dem Client: _kein Erfolg annehmen — Body prüfen_. Ein Client, der `201` sieht, kann davon ausgehen, dass alle Elemente verarbeitet wurden; ein Client, der `207` sieht, muss `errors` prüfen.

**Warum kein 422 bei Teilerfolg?** `422` bedeutet, dass die gesamte Anfrage abgelehnt wird. Partial-Success-Bulk-Endpunkte verarbeiten einige Eingaben erfolgreich, daher wäre `422` irreführend.

---

## Bulk-Delete-Controller

```php
private function bulkDelete(ServerRequestInterface $request): ResponseInterface
{
    $body = JsonRequestBodyParser::parse($request);

    if (!isset($body['ids']) || !is_array($body['ids'])) {
        return $this->problems->create($request, 'validation-failed', 'Validation Failed', 422, null, [
            'errors' => [['field' => 'ids', 'code' => 'required', 'message' => 'ids array is required.']],
        ]);
    }

    $ids    = array_values(array_filter($body['ids'], 'is_int'));
    $result = $this->repo->bulkDelete($ids);

    return $this->json->create($result);   // immer 200
}
```

`array_filter($body['ids'], 'is_int')` entfernt stillschweigend Nicht-Integer-Werte aus dem IDs-Array. Dies ist eine Designentscheidung: fehlerhafte IDs werden ignoriert, anstatt eine 422 zu verursachen. Ein alternativer Ansatz ist es, die gesamte Anfrage abzulehnen, wenn eine ID kein Integer ist.

---

## Beispielanfrage und -antwort

### Bulk-Create — Teilerfolg

**Anfrage** `POST /items/bulk`:
```json
{
  "items": [
    {"sku": "A001", "name": "Widget A", "price": 1000},
    {"sku": "",     "name": "Bad Item",  "price": 500},
    {"sku": "A001", "name": "Duplicate", "price": 200}
  ]
}
```

**Antwort** `207 Multi-Status`:
```json
{
  "created": [
    {"id": 1, "sku": "A001", "name": "Widget A", "price": 1000, "created_at": "2026-01-01 00:00:00"}
  ],
  "errors": [
    {"index": 1, "sku": "", "errors": ["sku is required"]},
    {"index": 2, "sku": "A001", "errors": ["sku \"A001\" already exists"]}
  ]
}
```

`index` bezieht sich auf die Position im Eingabe-`items`-Array (0-basiert). Der Client kann jeden Fehler mit der ursprünglichen Eingabe korrelieren, ohne den Payload zu scannen.

### Bulk-Delete — Teilerfolg

**Anfrage** `DELETE /items/bulk`:
```json
{"ids": [1, 999, 2]}
```

**Antwort** `200 OK`:
```json
{
  "deleted": [1, 2],
  "not_found": [999]
}
```

---

## Design-Kompromisse

| Ansatz | Verhalten | Wann verwenden |
|---|---|---|
| Alles-oder-nichts | Rollback aller, wenn einer fehlschlägt | Finanzen, Inventar — Konsistenz erforderlich |
| Teilerfolg (dieses Muster) | Jedes Element unabhängig verarbeiten | Import/Export, Dateneingabe |
| Fire-and-forget-Queue | Asynchrone Verarbeitung, aufgeschobene Ergebnisse | Große Batches, Hintergrundaufgaben |

Teilerfolg ist geeignet, wenn Elemente voneinander unabhängig sind. Wenn der Erfolg von Element A vom Erfolg von Element B abhängt (z. B. Lagerbestände zwischen Elementen übertragen), stattdessen eine Alles-oder-nichts-Transaktion verwenden.

---

## Verwandte Anleitungen

- [`transaction-scope-pattern.md`](transaction-scope-pattern.md) — atomares Alles-oder-nichts-Multi-Write
- [`job-queue-with-retry.md`](job-queue-with-retry.md) — asynchrone Bulk-Verarbeitung via Job-Queue
- [`mass-assignment-defence.md`](mass-assignment-defence.md) — explizites DTO-Whitelisting für jedes Element
