# Como Fazer: Implementar um Endpoint PATCH

PATCH é para **atualizações parciais**: apenas os campos que o cliente envia devem mudar.
Isso exige distinguir três estados para cada campo:

| Estado | Significado |
|---|---|
| Chave ausente do corpo | Não toque neste campo |
| Chave presente, valor não-null | Atualizar para o novo valor |
| Chave presente, valor `null` | Limpar o campo (definir como null) |

`isset()` não consegue distinguir "ausente" e "null explícito" — ambos retornam `false`.
Use `array_key_exists()` em vez disso.

---

## 1. Fazer parse do corpo e extrair apenas os campos presentes

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

Passe `$fields` para o método `update()` do seu repositório. Se `$fields` estiver vazio, a
chamada ainda é válida — responda com o estado atual do recurso.

---

## 2. Registro de rota

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

## 3. Enviando um corpo PATCH vazio

Para enviar um PATCH sem campos (uma operação sem efeito que retorna o estado atual), você deve
enviar um **objeto** JSON, não um array.

```php
// ERRADO: json_encode([]) === "[]"  → 400 Bad Request (array JSON)
$request->withBody($stream->write(json_encode([])));

// CORRETO: json_encode((object)[]) === "{}"  → 200 OK (objeto JSON)
$request->withBody($stream->write(json_encode((object)[])));
```

Em helpers de teste, passe `new \stdClass()` como o corpo:

```php
// Em testes PHPUnit
$response = $this->request('PATCH', "/entries/{$id}", new \stdClass());
```

Isso ocorre porque `JsonRequestBodyParser` rejeita arrays JSON (veja a mensagem `JsonBodyParseException`
para detalhes). Um array PHP vazio `[]` codifica para o array JSON `[]`, não para o objeto
JSON `{}`.

---

## 4. Validando campos PATCH

Valide apenas os campos que estão **presentes**. Pule a validação para campos ausentes — eles não serão
tocados. Use parâmetros nullable na assinatura do repositório para deixar a intenção explícita:

```php
$body   = JsonRequestBodyParser::parse($request);
$errors = [];

// Extrair apenas campos presentes (array_key_exists, não isset)
$amount   = array_key_exists('amount', $body) ? $body['amount'] : null;
$category = array_key_exists('category', $body) ? $body['category'] : null;
$date     = array_key_exists('date', $body) ? $body['date'] : null;

// Validar apenas os campos que foram enviados
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

// Chamar repositório com args nullable — repositório usa valor existente quando null
$entity = $this->repository->update(
    id:       $id,
    amount:   is_int($amount) ? $amount : null,
    category: is_string($category) && $category !== '' ? $category : null,
    date:     is_string($date) && $date !== '' ? $date : null,
    now:      (new \DateTimeImmutable())->format('Y-m-d\TH:i:s\Z'),
);
```

No repositório, use `??` para fazer fallback ao valor existente:

```php
public function update(int $id, ?int $amount, ?string $category, ?string $date, string $now): Entity
{
    $existing    = $this->findById($id); // lança NotFoundException quando ausente
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

> **Por que `array_key_exists` e não `isset`?** `isset($body['field'])` retorna `false` tanto para
> uma chave ausente quanto para uma chave presente com valor `null`. Para PATCH, essa distinção importa:
> "não enviado" significa "manter o valor existente", enquanto `null` pode significar "limpar este campo".
> Sempre use `array_key_exists` para detecção de campos PATCH.

---

## 5. Contrato do repositório

O `update()` do seu repositório deve aceitar apenas os campos passados e retornar
a entidade atualizada (ou `null` quando não encontrada):

```php
/** @param array<string, mixed> $fields */
public function update(int $id, array $fields): ?Entry
{
    if ($fields === []) {
        return $this->findById($id);   // sem efeito: retorna estado atual
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

## 5. Howtos relacionados

- [`add-pagination.md`](add-pagination.md) — GET com `PaginationQueryParser`
- [`add-domain-exception-handler.md`](add-domain-exception-handler.md) — handler 404 para recursos ausentes
