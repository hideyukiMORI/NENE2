# How-to: PATCH-Endpunkt implementieren

PATCH ist für **partielle Aktualisierungen**: nur die Felder, die der Client sendet, sollen sich ändern.
Dies erfordert die Unterscheidung von drei Zuständen für jedes Feld:

| Zustand | Bedeutung |
|---|---|
| Key im Body fehlt | Dieses Feld nicht anfassen |
| Key vorhanden, Wert nicht null | Auf neuen Wert aktualisieren |
| Key vorhanden, Wert `null` | Feld leeren (auf null setzen) |

`isset()` kann "fehlend" und "explizites null" nicht unterscheiden — beide geben `false` zurück.
Stattdessen `array_key_exists()` verwenden.

---

## 1. Body parsen und nur die vorhandenen Felder extrahieren

```php
$body   = JsonRequestBodyParser::parse($request);   // array<string, mixed>
$fields = [];

if (array_key_exists('title', $body)) {
    $fields['title'] = is_string($body['title']) ? trim($body['title']) : null;
}
if (array_key_exists('is_read', $body)) {
    $fields['is_read'] = (bool) $body['is_read'];
}
```

`$fields` an die `update()`-Methode des Repositorys übergeben. Wenn `$fields` leer ist, ist der Aufruf trotzdem gültig — mit dem aktuellen Zustand der Ressource antworten.

---

## 2. Routen-Registrierung

```php
$router->patch(
    '/entries/{id}',
    static function (ServerRequestInterface $request) use ($entries, $json): ResponseInterface {
        /** @var array<string, string> $params */
        $params = $request->getAttribute(Router::PARAMETERS_ATTRIBUTE, []);
        $id     = (int) ($params['id'] ?? 0);

        $body   = JsonRequestBodyParser::parse($request);
        $fields = [];

        if (array_key_exists('title', $body)) {
            $fields['title'] = $body['title'];
        }
        if (array_key_exists('is_read', $body)) {
            $fields['is_read'] = (bool) $body['is_read'];
        }

        $entry = $entries->update($id, $fields) ?? throw new EntryNotFoundException($id);

        return $json->create(self::payload($entry));
    },
);
```

---

## 3. Leeren PATCH-Body senden

Um ein PATCH ohne Felder zu senden (ein No-op, das den aktuellen Zustand zurückgibt), muss ein JSON-**Objekt** gesendet werden, kein Array.

```php
// FALSCH: json_encode([]) === "[]"  → 400 Bad Request (JSON-Array)
$request->withBody($stream->write(json_encode([])));

// KORREKT: json_encode((object)[]) === "{}"  → 200 OK (JSON-Objekt)
$request->withBody($stream->write(json_encode((object)[])));
```

In Test-Helpern `new \stdClass()` als Body übergeben:

```php
// In PHPUnit-Tests
$response = $this->request('PATCH', "/entries/{$id}", new \stdClass());
```

Das liegt daran, dass `JsonRequestBodyParser` JSON-Arrays ablehnt (Details in der `JsonBodyParseException`-Meldung). Ein leeres PHP-Array `[]` kodiert zum JSON-Array `[]`, nicht zum JSON-Objekt `{}`.

---

## 4. PATCH-Felder validieren

Nur die **vorhandenen** Felder validieren. Fehlende Felder überspringen — sie werden nicht angefasst. Nullable-Parameter in der Repository-Signatur verwenden, um die Absicht explizit zu machen:

```php
$body   = JsonRequestBodyParser::parse($request);
$errors = [];

// Nur vorhandene Felder extrahieren (array_key_exists, nicht isset)
$amount   = array_key_exists('amount', $body) ? $body['amount'] : null;
$category = array_key_exists('category', $body) ? $body['category'] : null;
$date     = array_key_exists('date', $body) ? $body['date'] : null;

// Nur die gesendeten Felder validieren
if ($amount !== null) {
    if (!is_int($amount) || $amount <= 0) {
        $errors[] = new ValidationError('amount', 'amount must be a positive integer.', 'out_of_range');
    }
}

if ($date !== null) {
    if (!is_string($date) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
        $errors[] = new ValidationError('date', 'date must be in YYYY-MM-DD format.', 'invalid_format');
    }
}

if ($errors !== []) {
    throw new ValidationException($errors);
}

// Repository mit nullable Argumenten aufrufen — Repository verwendet vorhandenen Wert bei null
$entity = $this->repository->update(
    id:       $id,
    amount:   is_int($amount) ? $amount : null,
    category: is_string($category) && $category !== '' ? $category : null,
    date:     is_string($date) && $date !== '' ? $date : null,
    now:      (new \DateTimeImmutable())->format('Y-m-d\TH:i:s\Z'),
);
```

Im Repository `??` verwenden, um auf den vorhandenen Wert zurückzufallen:

```php
public function update(int $id, ?int $amount, ?string $category, ?string $date, string $now): Entity
{
    $existing    = $this->findById($id); // wirft NotFoundException wenn nicht gefunden
    $newAmount   = $amount   ?? $existing->amount;
    $newCategory = $category ?? $existing->category;
    $newDate     = $date     ?? $existing->date;

    $this->executor->execute(
        'UPDATE entities SET amount = ?, category = ?, date = ?, updated_at = ? WHERE id = ?',
        [$newAmount, $newCategory, $newDate, $now, $id],
    );

    return new Entity($id, $newDate, $newAmount, $newCategory, $existing->createdAt, $now);
}
```

> **Warum `array_key_exists` statt `isset`?** `isset($body['field'])` gibt `false` zurück, sowohl für fehlende Keys als auch für Keys mit dem Wert `null`. Bei PATCH ist dieser Unterschied wichtig: "nicht gesendet" bedeutet "vorhandenen Wert behalten", während `null` möglicherweise "dieses Feld leeren" bedeutet. Für die PATCH-Felderkennung immer `array_key_exists` verwenden.

---

## 5. Repository-Vertrag

Die `update()`-Methode des Repositorys sollte nur die übergebenen Felder akzeptieren und die aktualisierte Entität zurückgeben (oder `null` wenn nicht gefunden):

```php
/** @param array<string, mixed> $fields */
public function update(int $id, array $fields): ?Entry
{
    if ($fields === []) {
        return $this->findById($id);   // No-op: aktuellen Zustand zurückgeben
    }

    $setClauses = implode(', ', array_map(fn (string $k): string => "{$k} = ?", array_keys($fields)));
    $params     = [...array_values($fields), $id];

    $affected = $this->executor->execute(
        "UPDATE entries SET {$setClauses} WHERE id = ?",
        $params,
    );

    return $affected > 0 ? $this->findById($id) : null;
}
```

---

## 6. Verwandte Anleitungen

- [`add-pagination.md`](add-pagination.md) — GET mit `PaginationQueryParser`
- [`add-domain-exception-handler.md`](add-domain-exception-handler.md) — 404-Handler für fehlende Ressourcen
