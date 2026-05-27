# Como Fazer: Implementar um Endpoint de Criação em Lote

Um endpoint de lote aceita múltiplos recursos em uma única requisição — reduzindo round trips para
importações em batch, envios de pontuação e fluxos similares. Este guia cobre o padrão completo:
parsing, validação por item com campos de erro indexados, limitação de tamanho e a rota.

---

## 1. Schema

O corpo da requisição envolve itens em uma chave de array nomeada para que o envelope possa carregar metadados:

```json
{
  "scores": [
    { "player": "Alice", "game": "tetris", "score": 1000, "played_at": "2026-01-15" },
    { "player": "Bob",   "game": "tetris", "score": 2000, "played_at": "2026-01-16" }
  ]
}
```

A resposta retorna o total criado e os itens criados:

```json
{ "created": 2, "scores": [ /* ... */ ] }
```

---

## 2. Rota

Registre a rota de lote **antes** da rota de recurso único parametrizada para evitar sobreposição
(veja [add-custom-route.md](add-custom-route.md)):

```php
$router->post('/scores/bulk', $this->bulkSubmit(...)); // estática primeiro
$router->post('/scores/{id}', $this->show(...));        // parametrizada depois
```

---

## 3. Handler

```php
private function bulkSubmit(ServerRequestInterface $request): ResponseInterface
{
    $body = JsonRequestBodyParser::parse($request);

    // 1. Validar o envelope
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

    // 2. Aplicar limite de tamanho antes de iterar
    if (count($entriesRaw) > 100) {
        throw new ValidationException([
            new ValidationError('scores', 'scores may contain at most 100 entries per request.', 'out_of_range'),
        ]);
    }

    // 3. Validar cada entrada, prefixando nomes de campo com o índice
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

    // 4. Falhar toda a requisição se alguma entrada for inválida
    if ($allErrors !== []) {
        throw new ValidationException($allErrors);
    }

    // 5. Persistir todas as entradas e retornar
    $now     = (new \DateTimeImmutable())->format('Y-m-d\TH:i:s\Z');
    $created = $this->repository->bulkCreate($entries, $now);

    return $this->json->create([
        'created' => count($created),
        'scores'  => array_map(fn ($s) => $this->serialize($s), $created),
    ], 201);
}
```

---

## 4. Validação por item com nomes de campo indexados

Use um helper privado que aceita um argumento `string $prefix`. O prefixo é `"scores[{$i}]."`:

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

**Por que `$prefix`?** `ValidationError` aceita qualquer string como nome de campo. Passar
`"scores[0]."` como prefixo produz campos de erro como `"scores[0].player"` — deixando imediatamente
claro qual entrada e campo falhou. Um único argumento de prefixo é suficiente; nenhuma mudança no
framework é necessária.

O corpo da resposta 422 resultante:

```json
{
  "type": "https://nene2.dev/problems/validation-failed",
  "errors": [
    { "field": "scores[1].player", "message": "player is required.", "code": "required" }
  ]
}
```

---

## 5. Contrato do repositório

Aceite uma lista de entradas pré-validadas e retorne as entidades criadas:

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

> **Atomicidade**: O loop acima insere uma linha por vez. Envolva em
> `DatabaseTransactionManagerInterface::transactional()` se precisar de comportamento tudo-ou-nada
> — veja [use-transactions.md](use-transactions.md).

---

## 6. Howtos relacionados

- [`add-pagination.md`](add-pagination.md) — padrão de endpoint de listagem
- [`use-transactions.md`](use-transactions.md) — envolver inserts em lote em uma transação
- [`add-domain-exception-handler.md`](add-domain-exception-handler.md) — 404/409 específico de domínio
