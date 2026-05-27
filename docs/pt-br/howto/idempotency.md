# Como Fazer: Padrão Idempotency-Key

> **Referência FT**: FT276 (`NENE2-FT/csrflog`) — Header Idempotency-Key para requisições que alteram estado: constraint UNIQUE no BD, replay retorna resultado original (200), mudanças no corpo em replay são ignoradas, condição de corrida tratada por DatabaseConstraintException, 15 testes / 30 asserções PASS.
>
> **ATK assessment**: ATK-01 a ATK-12 incluídos ao final deste documento.

Previna pedidos duplicados ou criação de recursos causados por retentativas de rede exigindo que clientes forneçam um header `Idempotency-Key` em toda requisição que altera estado.

## Por que é importante

Quando um cliente envia `POST /orders` e a rede cai antes de receber a resposta, ele vai retentar. Sem idempotência, essa retentativa cria um segundo pedido. Com um `Idempotency-Key`, o servidor pode detectar a retentativa e retornar o resultado original em vez de criar um duplicado.

Stripe, GitHub e muitas outras APIs em produção usam exatamente este padrão.

## Schema do banco de dados

Adicione uma constraint `UNIQUE` na coluna da idempotency key. Esta única constraint trata a condição de corrida descrita abaixo.

```sql
CREATE TABLE orders (
    id               INTEGER PRIMARY KEY AUTOINCREMENT,
    idempotency_key  TEXT    NOT NULL UNIQUE,
    item             TEXT    NOT NULL,
    quantity         INTEGER NOT NULL,
    total_price      REAL    NOT NULL,
    created_at       TEXT    NOT NULL
);
```

## Implementação do handler

```php
// 1. Ler e validar o header
$key = trim($request->getHeaderLine('Idempotency-Key'));
if ($key === '') {
    return $problems->create(
        $request,
        'missing-idempotency-key',
        'Idempotency-Key header is required for this endpoint.',
        [],
        422,
    );
}

// 2. Verificar entrada existente (caminho de replay)
$existing = $repo->findByIdempotencyKey($key);
if ($existing !== null) {
    return $json->create($existing->toArray(), 200); // replay — retorna original com 200
}

// 3. Validar o corpo da requisição
$body = json_decode((string) $request->getBody(), true);
// ... validar campos ...

// 4. Criar — constraint UNIQUE trata a condição de corrida
try {
    $order = $repo->create($key, $item, $quantity, $totalPrice);
    return $json->create($order->toArray(), 201);
} catch (DatabaseConstraintException) {
    // Outra requisição com a mesma chave ganhou a corrida — retorna o resultado dela
    $existing = $repo->findByIdempotencyKey($key);
    if ($existing !== null) {
        return $json->create($existing->toArray(), 200);
    }
    return $problems->create($request, 'conflict', 'Conflict.', [], 409);
}
```

## Repositório

```php
public function findByIdempotencyKey(string $key): ?Order
{
    $row = $this->executor->fetchOne(
        'SELECT * FROM orders WHERE idempotency_key = ?',
        [$key],
    );
    return $row !== null ? Order::fromRow($row) : null;
}

public function create(string $key, string $item, int $quantity, float $totalPrice): Order
{
    // Lança DatabaseConstraintException em violação UNIQUE (condição de corrida)
    $this->executor->insert(
        'INSERT INTO orders (idempotency_key, item, quantity, total_price, created_at) VALUES (?, ?, ?, ?, ?)',
        [$key, $item, $quantity, $totalPrice, (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM)],
    );
    // ...
}
```

## Decisões de design principais

### Replay retorna 200, não 201

A segunda requisição é um replay, não uma criação. Usar `200 OK` diz ao cliente "você já viu isso" sem criar confusão sobre o que foi criado.

### Replay ignora o corpo

Se o cliente enviar o mesmo `Idempotency-Key` com um corpo diferente, o resultado **original** é retornado. O servidor trata uma chave correspondente como prova de que a requisição já foi processada, independente do que o corpo diz.

```
POST /orders  Idempotency-Key: uuid-abc  body: {quantity: 1, price: 9.99}
→ 201 Created  {id: 1, quantity: 1}

POST /orders  Idempotency-Key: uuid-abc  body: {quantity: 99, price: 0.01}
→ 200 OK  {id: 1, quantity: 1}   ← pedido original, corpo ignorado
```

Isso é intencional. Se o cliente quiser criar um recurso genuinamente diferente, deve usar uma nova chave.

### Constraint UNIQUE como guarda de condição de corrida

Duas requisições concorrentes com a mesma chave vão disputar. A constraint `UNIQUE` do BD garante que apenas um insert tenha sucesso. O perdedor captura `DatabaseConstraintException` e busca a linha do vencedor.

## O que os clientes devem usar como chave

UUID v4 é a escolha mais comum. O cliente gera a chave antes de enviar a requisição e a armazena localmente para que possa retentar com a mesma chave se necessário.

```js
// Lado do cliente (JavaScript)
const key = crypto.randomUUID();
const response = await fetch('/orders', {
    method: 'POST',
    headers: {
        'Content-Type': 'application/json',
        'Idempotency-Key': key,
    },
    body: JSON.stringify({ item: 'Widget', quantity: 1, price: 9.99 }),
});
```

## Lendo o header

Nomes de headers PSR-7 não diferenciam maiúsculas de minúsculas. `getHeaderLine('Idempotency-Key')`, `getHeaderLine('idempotency-key')` e `getHeaderLine('IDEMPOTENCY-KEY')` retornam todos o mesmo valor. O NENE2 usa Nyholm/PSR-7 que implementa isso corretamente.

---

## ATK Assessment — Teste de Ataque com Mentalidade de Cracker

### ATK-01 — Omitir Idempotency-Key para contornar verificação de duplicata 🚫 BLOCKED

**Ataque**: Enviar `POST /orders` sem o header `Idempotency-Key`.
**Resultado**: BLOCKED — `trim($request->getHeaderLine('Idempotency-Key')) === ''` → 422 com problem detail `missing-idempotency-key`. Nenhum pedido é criado.

---

### ATK-02 — Enviar Idempotency-Key vazio 🚫 BLOCKED

**Ataque**: Enviar `Idempotency-Key: ` (somente espaços).
**Resultado**: BLOCKED — `trim()` reduz strings somente com espaços para `''` → mesmo 422 que ATK-01.

---

### ATK-03 — Replay com corpo modificado para alterar conteúdo do pedido 🚫 BLOCKED

**Ataque**: Enviar `POST /orders` com chave `uuid-abc` e `{quantity: 1}`. No replay, usar a mesma chave com `{quantity: 99}`.
**Resultado**: BLOCKED — o servidor encontra a linha existente por `idempotency_key` e a retorna imediatamente, antes de ler o corpo. O novo corpo nunca é processado.

---

### ATK-04 — Criar dois pedidos com chaves diferentes 🚫 BLOCKED (intencional)

**Ataque**: Usar dois valores diferentes de `Idempotency-Key` para criar legitimamente dois pedidos.
**Resultado**: SAFE (por design) — chaves diferentes são requisições diferentes. Ambos os pedidos são criados. Este é o comportamento pretendido: idempotência é por chave, não por corpo.

---

### ATK-05 — Condição de corrida: duas requisições concorrentes com a mesma chave 🚫 BLOCKED

**Ataque**: Enviar duas requisições idênticas concorrentemente antes que qualquer uma complete.
**Resultado**: BLOCKED — ambas as requisições passam pela verificação `findByIdempotencyKey` (nenhuma linha existente ainda), mas apenas um INSERT tem sucesso. O perdedor captura `DatabaseConstraintException`, busca a linha do vencedor e a retorna com 200. A constraint UNIQUE é a guarda de corrida.

---

### ATK-06 — Injeção de quantidade negativa 🚫 BLOCKED

**Ataque**: Enviar `{item: "widget", quantity: -1, price: 9.99}` com uma chave válida.
**Resultado**: BLOCKED — `if ($quantity <= 0)` → erro de validação 422. Nenhum pedido é criado.

---

### ATK-07 — Injeção de quantidade zero 🚫 BLOCKED

**Ataque**: Enviar `{item: "widget", quantity: 0, price: 9.99}`.
**Resultado**: BLOCKED — mesmo guard `quantity <= 0` → 422.

---

### ATK-08 — Campos de corpo obrigatórios ausentes 🚫 BLOCKED

**Ataque**: Enviar `{quantity: 1}` sem o campo `item`.
**Resultado**: BLOCKED — `if ($item === '')` → erro de validação 422.

---

### ATK-09 — CSRF via requisição de navegador cross-origin 🚫 BLOCKED (design)

**Ataque**: Website malicioso faz uma requisição `POST /orders` cross-origin de um navegador.
**Resultado**: BLOCKED (por design) — APIs JSON requerem `Content-Type: application/json`. Ataques CSRF de navegador só podem enviar corpos form-encoded ou plain-text via `<form>` sem um preflight. Um corpo JSON aciona um preflight CORS; a política CORS do servidor determina se escritas cross-origin são permitidas. Adicionalmente, exigir `Idempotency-Key` fornece proteção secundária já que requisições forjadas não podem prever uma chave única.

---

### ATK-10 — Injeção de preço negativo 🚫 BLOCKED

**Ataque**: Enviar `{item: "widget", quantity: 1, price: -100.0}`.
**Resultado**: BLOCKED — `if ($price < 0)` → erro de validação 422.

---

### ATK-11 — Coerção de quantidade float/string 🚫 BLOCKED

**Ataque**: Enviar `{quantity: "1"}` ou `{quantity: 1.5}` (string ou float).
**Resultado**: BLOCKED — `is_int($body['quantity'])` rejeita strings e floats; `1.5` é float → 422.

---

### ATK-12 — SQL injection via Idempotency-Key 🚫 BLOCKED

**Ataque**: Enviar `Idempotency-Key: '; DROP TABLE orders; --`.
**Resultado**: BLOCKED — a chave é usada apenas em queries parametrizadas (`WHERE idempotency_key = ?`). SQL injection via valor de header não é possível.

---

### Resumo ATK

| ID | Ataque | Resultado |
|----|--------|--------|
| ATK-01 | Idempotency-Key ausente | 🚫 BLOCKED |
| ATK-02 | Chave vazia/somente com espaços | 🚫 BLOCKED |
| ATK-03 | Replay com corpo modificado | 🚫 BLOCKED |
| ATK-04 | Chaves diferentes = pedidos diferentes | ✅ SAFE (intencional) |
| ATK-05 | Condição de corrida na mesma chave | 🚫 BLOCKED |
| ATK-06 | Quantidade negativa | 🚫 BLOCKED |
| ATK-07 | Quantidade zero | 🚫 BLOCKED |
| ATK-08 | Campos de corpo ausentes | 🚫 BLOCKED |
| ATK-09 | CSRF via POST cross-origin | 🚫 BLOCKED |
| ATK-10 | Preço negativo | 🚫 BLOCKED |
| ATK-11 | Coerção de quantidade float/string | 🚫 BLOCKED |
| ATK-12 | SQL injection via header de chave | 🚫 BLOCKED |

**12 BLOCKED / SAFE, 0 EXPOSED**
O padrão Idempotency-Key, queries parametrizadas e validação estrita com `is_int()` previnem todos os vetores de ataque testados.
