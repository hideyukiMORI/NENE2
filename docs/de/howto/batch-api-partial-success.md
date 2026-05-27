# How-to: Batch-API mit Teilerfolg

> **FT-Referenz**: FT294 (`NENE2-FT/batchlog`) — Batch-INSERT mit Teilerfolg: MAX_BATCH=50-Guard, unabhängige Validierung pro Element mit Index-Tracking, gemischte created/errors-Antwort (immer 200), DB-CHECK-Constraints, strikte JSON-Typvalidierung via `is_int()`, 36 Tests / 79 Assertions bestanden.
>
> **FT-Vorgänger**: FT182 (erste batchlog-Abdeckung).

Wenn Clients ein Array von Elementen in einer einzigen Anfrage übermitteln, können einige Elemente
gültig und andere ungültig sein. Die gesamte Batch-Anfrage bei einem einzigen Fehler abzulehnen,
verschwendet die gültigen Elemente; ungültige Elemente stillschweigend zu überspringen, verbirgt Fehler.
Das _Partial-Success_-Muster akzeptiert, was es kann, und meldet, was es nicht kann — pro Element, nach Index.

---

## Das Kernproblem

JSON-Array-Bodies führen zwei Validierungsebenen ein:

1. **Batch-Ebene** — Ist die Gesamtform der Anfrage gültig? (Schlüssel vorhanden? Ist es eine Liste? Liegt die Anzahl im Bereich?)
2. **Element-Ebene** — Ist jedes einzelne Element gültig? (Typ? Bereich? Pflichtfelder?)

Beide Ebenen gleich zu behandeln führt entweder zu Über-Ablehnung (ein schlechtes Element tötet den gesamten Batch)
oder Über-Akzeptanz (schlechte Elemente werden stillschweigend ignoriert).

---

## HTTP-Konventionen

| Szenario | Status | Body |
|---|---|---|
| Batch-Ebene-Fehler (fehlender Schlüssel, falscher Typ, leer, überdimensioniert) | `422` | `{"error": "..."}` |
| Nur Element-Ebene-Fehler / gemischter Erfolg+Fehler | `200` | `{created, errors, total_created, total_errors}` |
| Alle Elemente gültig | `200` | `{created: [...], errors: [], ...}` |
| Alle Elemente ungültig | `200` | `{created: [], errors: [...], ...}` |

**Warum 200 bei allen-ungültig?** Die Batch-Operation selbst war erfolgreich — der Server hat jedes Element
verarbeitet und eine Entscheidung getroffen. Der Aufrufer erkennt das Ergebnis durch Prüfung von `total_created`
und `errors`. 422 für "einige Elemente ungültig" zu verwenden, würde zwei verschiedene Arten von Fehlern vermischen.

---

## V::bodyInt() — Strikte JSON-Typprüfung

`V::bodyInt()` ist das wichtigste Werkzeug zum Erkennen von JSON-Typkonfusionen in Batch-Payloads.
PHP's `json_decode` bewahrt JSON-Typen, aber Clients können versehentlich (oder absichtlich) falsche Typen senden.

```php
// V::bodyInt(mixed $raw, int $min, int $max): ?int
V::bodyInt(5, 1, 999)         // → 5        ✓ PHP int
V::bodyInt("5", 1, 999)       // → null     ✗ JSON-Typkonfusion: "5" ist nicht 5
V::bodyInt(5.5, 1, 999)       // → null     ✗ float
V::bodyInt(true, 1, 999)      // → null     ✗ bool
V::bodyInt(null, 1, 999)      // → null     ✗ null
V::bodyInt([5], 1, 999)       // → null     ✗ array
```

Der entscheidende Unterschied zu Query-Strings: `V::queryInt()` akzeptiert den String `"5"`
(weil Query-Parameter immer Strings sind), während `V::bodyInt()` ein PHP-`int` verlangt
(weil JSON zwischen `5` und `"5"` unterscheidet).

**ATK-07-Typkonfusionsangriff** — `{"quantity": "5"}` statt `{"quantity": 5}` senden muss fehlschlagen. `is_int()` ist die einzig sichere Prüfung.

---

## Batch-Validierungslogik

```php
// 1. Body parsen (Fallback auf [] bei Nicht-Objekt-JSON)
$body = json_decode((string) $request->getBody(), true);
$body = is_array($body) ? $body : [];

// 2. Batch-Ebene-Guards → 422
if (!array_key_exists('items', $body)) {
    return 422; // Schlüssel fehlt
}
$rawItems = $body['items'];
if (!is_array($rawItems)) {
    return 422; // kein Array
}
if (count($rawItems) === 0) {
    return 422; // leer
}
if (count($rawItems) > MAX_BATCH) {
    return 422; // überdimensioniert
}

// 3. Verarbeitung pro Element → 200 mit errors[]
$created = [];
$errors  = [];

foreach ($rawItems as $index => $rawItem) {
    $intIndex = (int) $index;

    // Jedes Element muss ein JSON-Objekt (assoz. Array) sein, kein Skalar oder Liste
    if (!is_array($rawItem) || array_is_list($rawItem)) {
        $errors[] = ['index' => $intIndex, 'error' => 'Each item must be a JSON object.'];
        continue;
    }

    $name = V::str($rawItem['name'] ?? null, 100);
    if ($name === null || $name === '') {
        $errors[] = ['index' => $intIndex, 'error' => 'name is required (max 100 chars).'];
        continue;
    }

    $quantity = V::bodyInt($rawItem['quantity'] ?? null, 1, 999);
    if ($quantity === null) {
        $errors[] = ['index' => $intIndex, 'error' => 'quantity must be an integer between 1 and 999.'];
        continue;
    }

    // … weitere Felder …

    $item      = $repository->create(/* ... */);
    $created[] = $item->toArray();
}

// 4. Immer 200; Aufrufer liest total_created / total_errors
return 200 with [
    'created'       => $created,
    'errors'        => $errors,
    'total_created' => count($created),
    'total_errors'  => count($errors),
];
```

---

## array_is_list() — JSON-Objekt vs. JSON-Array auf Element-Ebene

PHP's `json_decode` bildet JSON-Objekte auf assoziative Arrays und JSON-Arrays auf Listen-Arrays ab.
Verwenden Sie `array_is_list()`, um sie auf Element-Ebene zu unterscheiden:

```php
// JSON-Body: {"items": [{"name": "foo"}, "bar", 42, [1,2]]}
is_array(["name" => "foo"])   // true — gültiges JSON-Objekt
array_is_list(["name" => "foo"]) // false — assoziativ → Objekt ✓

is_array("bar")                  // false → durch is_array-Prüfung abgefangen
is_array(42)                     // false → abgefangen
is_array([1, 2])                 // true
array_is_list([1, 2])            // true → abgelehnt: Liste ≠ Objekt ✗
```

Der Guard `!is_array($rawItem) || array_is_list($rawItem)` fängt Skalare,
JSON-Arrays und alles andere ab, das kein einfaches JSON-Objekt ist.

---

## MAX_BATCH-Größenguard

Ohne eine Obergrenze könnte ein Client Tausende von Elementen in einer Anfrage senden
und unbegrenzt Speicher und CPU verbrauchen.

```php
const MAX_BATCH = 50; // für Ihren Anwendungsfall anpassen

if (count($rawItems) > self::MAX_BATCH) {
    return $this->responseFactory->create(
        ['error' => sprintf('"items" must contain at most %d entries.', self::MAX_BATCH)],
        422,
    );
}
```

Auf Batch-Ebene ablehnen (422), bevor iteriert wird — Fehler nicht pro Element
bei einem überdimensionierten Batch zählen.

---

## Fehlerindex-Beibehaltung

Den ursprünglichen Eingabeindex in jedem Fehler melden, damit Clients Fehler
mit den übermittelten Elementen korrelieren können, auch wenn die Array-Indizes nicht sequenziell sind
(z. B. nach clientseitiger Filterung):

```php
// Eingabe:  [gültig, ungültig, gültig, ungültig]
// Ausgabe errors: [{index: 1, error: "..."}, {index: 3, error: "..."}]
```

Den Index immer explizit in `int` umwandeln — `foreach`-Schlüssel können `string` sein, wenn
das PHP-Array aus nicht-sequenziellem JSON aufgebaut wurde:

```php
$intIndex = (int) $index;
```

---

## Antwortschema

```json
{
  "created": [
    {"id": 1, "user_id": 1, "name": "Widget A", "quantity": 3, "price_cents": 999, "created_at": "..."},
    {"id": 2, "user_id": 1, "name": "Widget B", "quantity": 1, "price_cents": 4999, "created_at": "..."}
  ],
  "errors": [
    {"index": 1, "error": "quantity must be an integer between 1 and 999."},
    {"index": 3, "error": "name is required (max 100 chars)."}
  ],
  "total_created": 2,
  "total_errors": 2
}
```

---

## Idempotenz-Überlegungen

Teilerfolg erzeugt ein Schreibe-dann-Fehler-Szenario. Wenn der Client den gesamten Batch
nach einem Netzwerkfehler erneut sendet, könnten bereits erstellte Elemente dupliziert werden.
Optionen:

- **Idempotenzschlüssel**: Pro Batch eine vom Client generierte UUID einschließen; der Server speichert sie und dedupliziert.
- **Clientseitige Deduplizierung**: Client verfolgt, welche Indizes erfolgreich waren, und sendet nur fehlgeschlagene Elemente erneut.
- **Natürliche Eindeutigkeit**: Eindeutige Einschränkung verwenden (z. B. externe ID) und Duplicate-Key-Fehler als Erfolg behandeln.

Das `batchlog`-FT verwendet aus Gründen der Übersichtlichkeit den einfachsten Ansatz (kein Idempotenzschlüssel).
Produktions-Batch-APIs sollten eine der oben genannten Strategien implementieren.

---

## Sicherheitshinweise

- **V::bodyInt() für alle numerischen Felder** — Strings, Floats, Bools, null im JSON-Body ablehnen.
- **V::str() für String-Felder** — lehnt Nicht-Strings ab, trimmt, prüft Länge; nach dem Trim auf `=== ''` für Pflichtfelder prüfen.
- **User-Scoping** — Jedes Element wird an die authentifizierte Benutzer-ID aus dem Header (`V::userId()`) gebunden, nie aus dem Request-Body.
- **MAX_BATCH-Guard** — 422 vor dem Iterieren, um DoS durch überdimensionierte Batches zu verhindern.

---

## Wichtigste Erkenntnisse

| Muster | Regel |
|---|---|
| Batch-Ebene-Fehler | 422 — gesamte Anfrage abgelehnt |
| Element-Ebene-Fehler | 200 — Index + Meldung in `errors[]` |
| Typkonfusion in JSON | `V::bodyInt()` / `is_int()` — nicht `is_numeric()` |
| JSON-Objekt vs. Array | `!is_array() \|\| array_is_list()` — beide ablehnen |
| Größen-DoS | `count($items) > MAX_BATCH` → 422 vor der Iteration |
| Fehlerkorrelation | Ursprünglichen `$index` in der Fehlerantwort bewahren |
