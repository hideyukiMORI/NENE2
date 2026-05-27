# Como Fazer: API em Lote com Sucesso Parcial

> **Referência FT**: FT294 (`NENE2-FT/batchlog`) — INSERT em lote com sucesso parcial: guarda MAX_BATCH=50, validação independente por item com rastreamento de índice, resposta mista created/errors (sempre 200), restrições DB CHECK, validação estrita de tipo JSON via `is_int()`, 36 testes / 79 asserções PASS.
>
> **Precursor FT**: FT182 (primeira cobertura do batchlog).

Quando os clientes enviam um array de itens em uma única requisição, alguns itens podem ser
válidos e outros inválidos. Rejeitar o lote inteiro por qualquer erro desperdiça os
itens válidos; ignorar silenciosamente erros esconde bugs. O padrão de _sucesso parcial_
aceita o que pode e reporta o que não pode — por item, por índice.

---

## O Problema Central

Corpos de array JSON introduzem duas camadas de validação:

1. **Nível de lote** — o formato geral da requisição é válido? (chave presente? é uma
   lista? a contagem está no intervalo?)
2. **Nível de item** — cada elemento individual é válido? (tipo? intervalo? campos
   obrigatórios?)

Tratar ambas as camadas da mesma forma leva a super-rejeição (um item ruim mata o lote inteiro)
ou super-aceitação (itens ruins ignorados silenciosamente).

---

## Convenções HTTP

| Cenário | Status | Corpo |
|---|---|---|
| Erro de nível de lote (chave ausente, tipo errado, vazio, excessivo) | `422` | `{"error": "..."}` |
| Somente erros de nível de item / sucesso+erro mistos | `200` | `{created, errors, total_created, total_errors}` |
| Todos os itens válidos | `200` | `{created: [...], errors: [], ...}` |
| Todos os itens inválidos | `200` | `{created: [], errors: [...], ...}` |

**Por que 200 para todos inválidos?** A operação de lote em si foi bem-sucedida — o servidor
processou cada item e tomou uma decisão sobre cada um. O chamador sabe o que aconteceu
inspecionando `total_created` e `errors`. Usar 422 para "alguns itens inválidos"
confundiria dois tipos diferentes de falha.

---

## V::bodyInt() — Aplicação Estrita de Tipo JSON

`V::bodyInt()` é a ferramenta chave para detectar confusão de tipo JSON em payloads
de lote. O `json_decode` do PHP preserva os tipos JSON, mas os chamadores podem enviar
tipos errados por acidente (ou intencionalmente).

```php
// V::bodyInt(mixed $raw, int $min, int $max): ?int
V::bodyInt(5, 1, 999)         // → 5        ✓ PHP int
V::bodyInt("5", 1, 999)       // → null     ✗ confusão de tipo JSON: "5" não é 5
V::bodyInt(5.5, 1, 999)       // → null     ✗ float
V::bodyInt(true, 1, 999)      // → null     ✗ bool
V::bodyInt(null, 1, 999)      // → null     ✗ null
V::bodyInt([5], 1, 999)       // → null     ✗ array
```

A diferença crítica em relação a query strings: `V::queryInt()` aceita a string
`"5"` (porque os parâmetros de query são sempre strings), enquanto `V::bodyInt()`
exige um `int` PHP (porque JSON distingue `5` de `"5"`).

**Ataque de confusão de tipo ATK-07** — enviar `{"quantity": "5"}` em vez de
`{"quantity": 5}` deve falhar. `is_int()` é a única verificação segura.

---

## Lógica de Validação em Lote

```php
// 1. Analisar corpo (fallback para [] em JSON não-objeto)
$body = json_decode((string) $request->getBody(), true);
$body = is_array($body) ? $body : [];

// 2. Guardas de nível de lote → 422
if (!array_key_exists('items', $body)) {
    return 422; // chave ausente
}
$rawItems = $body['items'];
if (!is_array($rawItems)) {
    return 422; // não é um array
}
if (count($rawItems) === 0) {
    return 422; // vazio
}
if (count($rawItems) > MAX_BATCH) {
    return 422; // excessivo
}

// 3. Processamento por item → 200 com errors[]
$created = [];
$errors  = [];

foreach ($rawItems as $index => $rawItem) {
    $intIndex = (int) $index;

    // Cada item deve ser um objeto JSON (array assoc), não escalar ou lista
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

    // … mais campos …

    $item      = $repository->create(/* ... */);
    $created[] = $item->toArray();
}

// 4. Sempre 200; chamador lê total_created / total_errors
return 200 with [
    'created'       => $created,
    'errors'        => $errors,
    'total_created' => count($created),
    'total_errors'  => count($errors),
];
```

---

## array_is_list() — Objeto JSON vs Array JSON no Nível do Item

O `json_decode` do PHP mapeia objetos JSON para arrays associativos e arrays JSON para
arrays de lista. Use `array_is_list()` para distingui-los no nível do item:

```php
// Corpo JSON: {"items": [{"name": "foo"}, "bar", 42, [1,2]]}
is_array(["name" => "foo"])   // true — objeto JSON válido
array_is_list(["name" => "foo"]) // false — associativo → objeto ✓

is_array("bar")                  // false → capturado pela verificação is_array
is_array(42)                     // false → capturado
is_array([1, 2])                 // true
array_is_list([1, 2])            // true → rejeitado: lista ≠ objeto ✗
```

A guarda `!is_array($rawItem) || array_is_list($rawItem)` captura escalares,
arrays JSON e qualquer outra coisa que não seja um objeto JSON simples.

---

## Guarda de Tamanho MAX_BATCH

Sem um limite superior, um chamador poderia enviar milhares de itens em uma requisição,
consumindo memória e CPU ilimitadas.

```php
const MAX_BATCH = 50; // ajuste para seu caso de uso

if (count($rawItems) > self::MAX_BATCH) {
    return $this->responseFactory->create(
        ['error' => sprintf('"items" must contain at most %d entries.', self::MAX_BATCH)],
        422,
    );
}
```

Rejeite no nível do lote (422) antes de iterar — não conte erros por item
para um lote excessivo.

---

## Preservação do Índice de Erro

Reporte o índice original de entrada em cada erro para que os clientes possam correlacionar erros
com os itens que enviaram, mesmo quando os índices do array são não sequenciais
(ex.: após filtragem no lado do cliente):

```php
// Entrada:  [válido, inválido, válido, inválido]
// Saída errors: [{index: 1, error: "..."}, {index: 3, error: "..."}]
```

Sempre converta o índice para `int` explicitamente — as chaves do `foreach` podem ser `string` quando
o array PHP foi construído a partir de JSON não sequencial:

```php
$intIndex = (int) $index;
```

---

## Schema de Resposta

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

## Consideração de Idempotência

O sucesso parcial cria um cenário de escrita-então-erro. Se o cliente tentar novamente o
lote completo após uma falha de rede, os itens criados anteriormente podem ser duplicados.
Opções:

- **Chave de idempotência**: inclua um UUID gerado pelo cliente por lote; o servidor armazena
  e deduplica.
- **Deduplicação no cliente**: o cliente rastreia quais índices tiveram sucesso e reenvia apenas
  os itens com falha.
- **Unicidade natural**: use uma restrição de unicidade (ex.: ID externo) e trate
  erros de chave duplicada como sucesso.

O FT `batchlog` usa a abordagem mais simples (sem chave de idempotência) para clareza.
APIs de lote em produção devem implementar uma das estratégias acima.

---

## Notas de Segurança

- **V::bodyInt() para todos os campos numéricos** — rejeite strings, floats, bools, null
  no corpo JSON.
- **V::str() para campos string** — rejeita não-string, apara, verifica comprimento;
  verifique `=== ''` para campos obrigatórios após aparar.
- **Escopo por usuário** — cada item é vinculado ao ID do usuário autenticado do
  cabeçalho (`V::userId()`), nunca do corpo da requisição.
- **Guarda MAX_BATCH** — 422 antes de iterar para prevenir DoS via lotes excessivos.

---

## Pontos Principais

| Padrão | Regra |
|---|---|
| Erro de nível de lote | 422 — requisição inteira rejeitada |
| Erro de nível de item | 200 — reportar índice + mensagem em `errors[]` |
| Confusão de tipo em JSON | `V::bodyInt()` / `is_int()` — não `is_numeric()` |
| Objeto JSON vs array | `!is_array() \|\| array_is_list()` — rejeitar ambos |
| DoS por tamanho | `count($items) > MAX_BATCH` → 422 antes da iteração |
| Correlação de erro | Preservar `$index` original na resposta de erro |
