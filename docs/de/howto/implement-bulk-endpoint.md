# How-to: Bulk-Create-Endpunkt implementieren

Ein Bulk-Endpunkt akzeptiert mehrere Ressourcen in einer einzigen Anfrage — dadurch werden Round-Trips für Batch-Importe, Score-Einreichungen und ähnliche Workflows reduziert. Diese Anleitung behandelt das vollständige Muster: Parsing, Pro-Item-Validierung mit indizierten Fehlerfeldern, Größenbeschränkung und die Route.

---

## 1. Schema

Der Request-Body wickelt Items in einem benannten Array-Key ein, damit der Envelope Metadaten tragen kann:

```json
{
  "scores": [
    { "player": "Alice", "game": "tetris", "score": 1000, "played_at": "2026-01-15" },
    { "player": "Bob",   "game": "tetris", "score": 2000, "played_at": "2026-01-16" }
  ]
}
```

Die Antwort gibt die Anzahl der erstellten Elemente und die erstellten Items zurück:

```json
{ "created": 2, "scores": [ /* ... */ ] }
```

---

## 2. Route

Die Bulk-Route **vor** der parametrisierten Einzelressource-Route registrieren, um Überschattung zu vermeiden (siehe [add-custom-route.md](add-custom-route.md)):

```php
$router->post('/scores/bulk', $this->bulkSubmit(...)); // statisch zuerst
$router->post('/scores/{id}', $this->show(...));        // parametrisiert danach
```

---

## 3. Handler

```php
private function bulkSubmit(ServerRequestInterface $request): ResponseInterface
{
    $body = JsonRequestBodyParser::parse($request);

    // 1. Envelope validieren
    if (!isset($body['scores']) || !is_array($body['scores'])) {
        throw new ValidationException([
            new ValidationError('scores', 'scores must be a non-empty array.', 'required'),
        ]);
    }

    /** @var array<mixed> $entriesRaw */
    $entriesRaw = $body['scores'];

    if (count($entriesRaw) === 0) {
        throw new ValidationException([
            new ValidationError('scores', 'scores must contain at least one entry.', 'required'),
        ]);
    }

    // 2. Größenbeschränkung vor dem Iterieren erzwingen
    if (count($entriesRaw) > 100) {
        throw new ValidationException([
            new ValidationError('scores', 'scores may contain at most 100 entries per request.', 'out_of_range'),
        ]);
    }

    // 3. Jeden Eintrag validieren, Feldnamen mit dem Index präfixieren
    $allErrors = [];
    $entries   = [];

    foreach ($entriesRaw as $i => $entry) {
        if (!is_array($entry)) {
            $allErrors[] = new ValidationError("scores[{$i}]", 'Each entry must be an object.', 'invalid_type');
            continue;
        }

        /** @var array<string, mixed> $entry */
        $entryErrors = $this->validateEntry($entry, "scores[{$i}].");
        if ($entryErrors !== []) {
            $allErrors = [...$allErrors, ...$entryErrors];
        } else {
            $entries[] = $entry;
        }
    }

    // 4. Gesamte Anfrage ablehnen, wenn ein Eintrag ungültig ist
    if ($allErrors !== []) {
        throw new ValidationException($allErrors);
    }

    // 5. Alle Einträge speichern und zurückgeben
    $now     = (new \DateTimeImmutable())->format('Y-m-d\TH:i:s\Z');
    $created = $this->repository->bulkCreate($entries, $now);

    return $this->json->create([
        'created' => count($created),
        'scores'  => array_map(fn ($s) => $this->serialize($s), $created),
    ], 201);
}
```

---

## 4. Pro-Item-Validierung mit indizierten Feldnamen

Einen privaten Helfer verwenden, der ein `string $prefix`-Argument akzeptiert. Das Präfix ist `"scores[{$i}]."`:

```php
/**
 * @param array<string, mixed> $body
 * @return list<ValidationError>
 */
private function validateEntry(array $body, string $prefix = ''): array
{
    $errors = [];

    if (!isset($body['player']) || !is_string($body['player']) || $body['player'] === '') {
        $errors[] = new ValidationError($prefix . 'player', 'player is required.', 'required');
    }

    if (!isset($body['score']) || !is_int($body['score'])) {
        $errors[] = new ValidationError($prefix . 'score', 'score is required (integer).', 'required');
    } elseif ($body['score'] < 0) {
        $errors[] = new ValidationError($prefix . 'score', 'score must be 0 or greater.', 'out_of_range');
    }

    return $errors;
}
```

**Warum `$prefix`?** `ValidationError` akzeptiert beliebige Strings als Feldname. Die Übergabe von `"scores[0]."` als Präfix erzeugt Fehlerfelder wie `"scores[0].player"` — so ist sofort klar, welcher Eintrag und welches Feld fehlgeschlagen ist. Ein einzelnes Präfix-Argument reicht; keine Framework-Änderung notwendig.

Der resultierende 422-Response-Body:

```json
{
  "type": "https://nene2.dev/problems/validation-failed",
  "errors": [
    { "field": "scores[1].player", "message": "player is required.", "code": "required" }
  ]
}
```

---

## 5. Repository-Vertrag

Eine Liste von vorvalidierten Einträgen entgegennehmen und die erstellten Entitäten zurückgeben:

```php
/**
 * @param list<array{player: string, game: string, score: int, played_at: string}> $entries
 * @return list<Score>
 */
public function bulkCreate(array $entries, string $now): array
{
    $results = [];
    foreach ($entries as $entry) {
        $results[] = $this->create($entry['player'], $entry['game'], $entry['score'], $entry['played_at'], $now);
    }
    return $results;
}
```

> **Atomizität**: Die obige Schleife fügt eine Zeile nach der anderen ein. In `DatabaseTransactionManagerInterface::transactional()` einwickeln, wenn Alles-oder-Nichts-Verhalten benötigt wird — siehe [use-transactions.md](use-transactions.md).

---

## 6. Verwandte Anleitungen

- [`add-pagination.md`](add-pagination.md) — Listenendpunkt-Muster
- [`use-transactions.md`](use-transactions.md) — Bulk-Inserts in einer Transaktion einwickeln
- [`add-domain-exception-handler.md`](add-domain-exception-handler.md) — domänenspezifische 404/409
