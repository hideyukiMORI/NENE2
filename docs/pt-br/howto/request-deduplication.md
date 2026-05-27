# Como Adicionar Deduplicação de Requisições

Evite processamento duplicado de retransmissões de rede ou cliques duplos usando um header `Idempotency-Key`. O servidor armazena em cache as respostas por chave e as reproduz em requisições idênticas subsequentes.

## Schema

```sql
CREATE TABLE idempotency_keys (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    idempotency_key TEXT NOT NULL UNIQUE,
    method          TEXT NOT NULL,
    path            TEXT NOT NULL,
    status_code     INTEGER NOT NULL,
    response_body   TEXT NOT NULL,
    created_at      TEXT NOT NULL,
    expires_at      TEXT NOT NULL
);
```

## Rotas

| Método | Caminho | Descrição |
|--------|---------|-----------|
| `POST` | `/payments` | Processar pagamento (Idempotency-Key obrigatório) |
| `POST` | `/orders` | Criar pedido (Idempotency-Key obrigatório) |

## Padrão do Handler

Cada endpoint mutante que deve ser idempotente segue o mesmo padrão de três etapas:

```php
// 1. Exigir o header Idempotency-Key
$key = trim($request->getHeaderLine('Idempotency-Key'));
if ($key === '') {
    return $this->json->create(['error' => 'Idempotency-Key header is required'], 400);
}

// 2. Retornar resposta em cache se a chave já foi usada
$cached = $this->repo->find($key);
if ($cached !== null && $cached['expires_at'] >= $this->now()) {
    $body = json_decode($cached['response_body'], true);
    return $this->json->create(
        array_merge($body, ['replayed' => true]),
        (int) $cached['status_code']
    );
}

// 3. Processar e armazenar em cache
$result = $this->doWork($body);
$this->repo->store($key, 'POST', '/payments', 201, json_encode($result), $now, $expiresAt);
return $this->json->create($result, 201);
```

O campo `replayed: true` sinaliza aos clientes que a resposta foi servida do cache.

## Validação Estrita de Valor

Rejeite entradas não inteiras na fronteira — o cast `(int)` do PHP silenciosamente trunca strings como `"100; DROP TABLE …"` para `100`. Use uma verificação de tipo explícita:

```php
$rawAmount = $body['amount'] ?? null;
if (!is_int($rawAmount) && !(is_string($rawAmount) && ctype_digit($rawAmount))) {
    $errors[] = new ValidationError('amount', 'amount must be a positive integer', 'invalid');
} else {
    $amount = (int) $rawAmount;
    if ($amount <= 0) {
        $errors[] = new ValidationError('amount', 'amount must be a positive integer', 'invalid');
    }
}
```

## TTL e Expiração

As chaves expiram após 24 horas (86400 segundos). Entradas expiradas são tratadas como novas — a mesma chave pode ser reutilizada após a expiração:

```php
private const int TTL_SECONDS = 86400;

$expiresAt = (new \DateTimeImmutable($now, new \DateTimeZone('UTC')))
    ->modify('+' . self::TTL_SECONDS . ' seconds')
    ->format('Y-m-d\TH:i:s\Z');
```

## Propriedades de Segurança

- **Injeção SQL via header de chave**: queries parametrizadas armazenam chaves maliciosas como literais.
- **Flood de replay**: 10 requisições idênticas criam exatamente 1 registro na tabela de negócios.
- **Chave somente com espaços**: `trim()` antes da verificação de vazio impede `"   "` como chave válida.
- **Injeção de tipo em campos numéricos**: a verificação `ctype_digit()` rejeita strings de inteiro parcial.
- **Sem vazamentos internos**: respostas 400/422 contêm apenas os campos `error` ou `errors` — sem caminhos, stack traces ou detalhes do motor.
