# Como Fazer: API de Histórico de Preços de Produto

> **Referência FT**: FT67 (`NENE2-FT/pricelog`) — API de Histórico de Preços de Produto
> **ATK**: FT228 — teste de ataque com mentalidade de cracker (ATK-01 a ATK-12)

Demonstra uma API de histórico de preços onde cada produto mantém uma linha do tempo de faixas de preço
(períodos de vigência). O preço atual e o preço em qualquer ponto no tempo podem ser
consultados. A seção ATK documenta doze vetores de ataque com veredictos de aprovação/reprovação.

---

## Rotas

| Método | Caminho | Descrição |
|--------|---------|-----------|
| `POST` | `/products` | Criar um produto |
| `GET`  | `/products` | Listar todos os produtos |
| `GET`  | `/products/{id}` | Obter um único produto |
| `POST` | `/products/{id}/prices` | Definir um novo preço (abre uma nova faixa) |
| `GET`  | `/products/{id}/prices` | Listar o histórico completo de preços |
| `GET`  | `/products/{id}/prices/current` | Preço ativo atual |
| `GET`  | `/products/{id}/prices/at` | Preço em uma data/hora específica (`?datetime=`) |

---

## Modelo de faixa de preço

Cada preço tem um timestamp `effective_from` e `effective_to`. Uma faixa é "ativa" quando:

```
effective_from <= now  E  (effective_to IS NULL  OU  effective_to > now)
```

`effective_to IS NULL` significa que a faixa ainda não tem data de término (intervalo aberto).

```sql
CREATE TABLE price_tiers (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    product_id     INTEGER NOT NULL REFERENCES products(id),
    amount         INTEGER NOT NULL,       -- centavos (não-negativo)
    currency       TEXT    NOT NULL DEFAULT 'USD',
    effective_from TEXT    NOT NULL,
    effective_to   TEXT,                  -- NULL = aberto (atual)
    created_at     TEXT    NOT NULL
);
```

---

## Definindo um preço: fechar a faixa antiga, abrir uma nova

```php
public function setPrice(int $productId, int $amount, string $currency, string $effectiveFrom): PriceTier
{
    // Fechar qualquer faixa aberta que começa antes do novo effective_from
    $this->db->execute(
        'UPDATE price_tiers
         SET effective_to = ?
         WHERE product_id = ? AND effective_to IS NULL AND effective_from <= ?',
        [$effectiveFrom, $productId, $effectiveFrom],
    );

    // Abrir uma nova faixa
    $id = $this->db->insert(
        'INSERT INTO price_tiers (product_id, amount, currency, effective_from, effective_to, created_at)
         VALUES (?, ?, ?, ?, NULL, ?)',
        [$productId, $amount, $currency, $effectiveFrom, $now],
    );
    // ...
}
```

O UPDATE fecha qualquer faixa aberta cujo `effective_from <= novoEffectiveFrom`. Isso trata corretamente
três cenários:
- **Novo effective_from no futuro**: fecha a faixa atual na data futura.
- **Novo effective_from no passado**: retrodata o fechamento da faixa antiga e abre uma nova faixa histórica.
- **effective_from duplicado**: fecha a faixa antiga no mesmo instante que começou (duração zero), depois abre a nova.

> **Advertência de concorrência**: o UPDATE e INSERT não estão encapsulados em uma transação. Duas
> chamadas concorrentes a `setPrice` com o mesmo `effective_from` podem ambas passar pela fase UPDATE
> e ambas fazer INSERT, deixando duas faixas abertas (`effective_to IS NULL`). As queries usam
> `ORDER BY effective_from DESC LIMIT 1`, então o último insert vence, mas o histórico fica corrompido.
> Encapsule em `transactional()` para corretude sob concorrência.

---

## Consultando preço em um ponto no tempo

```php
public function priceAt(int $productId, string $datetime): ?PriceTier
{
    $row = $this->db->fetchOne(
        'SELECT * FROM price_tiers
         WHERE product_id = ? AND effective_from <= ?
           AND (effective_to IS NULL OR effective_to > ?)
         ORDER BY effective_from DESC
         LIMIT 1',
        [$productId, $datetime, $datetime],
    );

    return $row !== null ? $this->hydrateTier($row) : null;
}
```

A comparação é lexicográfica em datetimes ISO 8601 armazenados como TEXT.
Isso funciona corretamente **apenas quando todos os datetimes usam o mesmo formato e fuso horário** (ex.:
todos UTC `2026-05-27 09:00:00`). Misturar formatos ou offsets de fuso horário produz resultados errados.

**Exemplo**: Se `effective_from` for armazenado como `"2026-05-27T09:00:00+09:00"` (JST) e
`?datetime=2026-05-27T00:30:00Z` (UTC, mesmo instante), a comparação de string os vê como
diferentes e pode retornar a faixa errada. Normalize todos os datetimes para UTC no momento da escrita.

---

## Valor em centavos (inteiro)

Valores monetários são armazenados como inteiros (centavos) para evitar arredondamento em ponto flutuante:

```php
// POST /products/{id}/prices
$amount = isset($body['amount']) && is_int($body['amount']) ? $body['amount'] : null;

if ($amount === null || $amount < 0) {
    $errors[] = ['field' => 'amount', 'code' => 'required', 'message' => 'amount must be a non-negative integer (cents).'];
}
```

- `is_int()` rejeita floats JSON (`9.99` → float PHP) e strings.
- `$amount < 0` rejeita preços negativos.
- `$amount === 0` é **permitido** (produtos gratuitos / promoções).

---

## ATK — Teste de ataque de cracker (FT228)

### ATK-01 — Sem autenticação

**Ataque**: Definir um preço em qualquer produto sem credenciais.

```http
POST /products/1/prices
{"amount": 1, "currency": "USD", "effective_from": "2026-01-01T00:00:00Z"}
```

**Observado**: `201 Created` — nenhum token necessário.

**Veredicto**: **EXPOSED** (por design para demo FT67).
Proteja mutações de preço por uma função admin ou chave de API em produção.

---

### ATK-02 — Manipulação de preço retroativo

**Ataque**: Definir `effective_from` para uma data passada para alterar o histórico de preços.

```json
{"amount": 0, "currency": "USD", "effective_from": "2020-01-01T00:00:00Z"}
```

**Observado**: `201 Created`. O UPDATE fecha qualquer faixa aberta em `2020-01-01`,
e uma nova faixa de preço zero spanning de 2020 em diante é inserida. Queries históricas
(`priceAt`) agora retornam o preço retroativo para datas passadas.

**Veredicto**: **EXPOSED** — sem autenticação não há proprietário para autorizar retroação.
Com auth, exija que `effective_from >= now()` a menos que o chamador seja admin.

---

### ATK-03 — Injeção SQL via `?datetime=`

**Ataque**: Injetar SQL através do parâmetro de query `datetime`.

```http
GET /products/1/prices/at?datetime=2026-01-01' OR '1'='1
```

**Observado**: `404 Not Found` — a string injetada é usada como valor parametrizado,
então a string literal é comparada contra `effective_from`, que não corresponde a nada.

**Veredicto**: **BLOCKED** — declarações parametrizadas PDO previnem injeção SQL.

---

### ATK-04 — Preço com valor zero

**Ataque**: Definir um preço de produto como zero (gratuito).

```json
{"amount": 0, "currency": "USD", "effective_from": "2026-05-27T00:00:00Z"}
```

**Observado**: `201 Created`.

**Veredicto**: **ACCEPTED BY DESIGN** — `amount === 0` é intencionalmente permitido
(planos de teste, promoções). Documente que `amount` significa centavos e 0 significa gratuito.
Se preço zero não for válido para seu domínio, mude `$amount < 0` para `$amount <= 0`.

---

### ATK-05 — Valor negativo

**Ataque**: Definir um preço negativo (ataque de reembolso?).

```json
{"amount": -100, "currency": "USD", "effective_from": "2026-05-27T00:00:00Z"}
```

**Observado**: `422 Unprocessable Entity` — a verificação `$amount < 0` retorna false.

**Veredicto**: **BLOCKED** — valores negativos rejeitados na camada de aplicação.

---

### ATK-06 — Injeção de código de moeda (sem allowlist)

**Ataque**: Definir um preço com uma string de moeda arbitrária ou maliciosa.

```json
{"amount": 100, "currency": "NOTCURRENCY", "effective_from": "2026-05-27T00:00:00Z"}
{"amount": 100, "currency": "<script>alert(1)</script>", "effective_from": "..."}
{"amount": 100, "currency": "'; DROP TABLE price_tiers; --", "effective_from": "..."}
```

**Observado**: Todos retornam `201 Created`. A string de moeda é armazenada literalmente.
A string de injeção SQL é segura (parametrizada), mas `"NOTCURRENCY"` e o payload XSS são armazenados.

**Veredicto**: **EXPOSED** — valide `currency` contra uma allowlist ISO 4217:
```php
$validCurrencies = ['USD', 'EUR', 'GBP', 'JPY', 'CAD', 'AUD'];
if (!in_array($currency, $validCurrencies, true)) {
    $errors[] = ['field' => 'currency', 'code' => 'invalid_value', 'message' => 'Unsupported currency code.'];
}
```

---

### ATK-07 — Valor extremamente grande

**Ataque**: Submeter um valor maior do que PHP/SQLite pode lidar.

```json
{"amount": 9999999999999999999, "currency": "USD", "effective_from": "..."}
```

**Observado**: O PHP analisa grandes inteiros JSON como floats quando excedem `PHP_INT_MAX`
(2^63 - 1 em 64 bits). `is_int($body['amount'])` retorna false para um float → 422.

**Veredicto**: **BLOCKED** — `is_int()` rejeita corretamente inteiros JSON que transbordam
para float PHP. Valores dentro de `PHP_INT_MAX` são armazenados corretamente como inteiros SQLite.

---

### ATK-08 — Formato de datetime inválido em `?datetime=`

**Ataque**: Passar uma string não-data para o endpoint `priceAt`.

```http
GET /products/1/prices/at?datetime=nao-e-data
GET /products/1/prices/at?datetime=2026-02-30T00:00:00Z
```

**Observado**: Ambos retornam `404 Not Found` — as strings são comparadas lexicograficamente
contra valores `effective_from` armazenados e não correspondem a nada. Nenhuma exceção é lançada.

**Veredicto**: **PARTIALLY EXPOSED** — o endpoint aceita silenciosamente datas inválidas e
retorna 404, o que pode confundir chamadores esperando um 422. Adicione validação de formato:
```php
$dt = DateTimeImmutable::createFromFormat(DateTimeInterface::ATOM, $datetime);
if ($dt === false) {
    return $this->problems->create($request, 'validation-failed', 'Validation Failed', 422, null, [
        'errors' => [['field' => 'datetime', 'code' => 'invalid_format', 'message' => 'datetime must be ISO 8601.']],
    ]);
}
```

---

### ATK-09 — effective_from no futuro (preço agendado)

**Ataque**: Definir `effective_from` para uma data futura para agendar uma mudança de preço.

```json
{"amount": 999, "currency": "USD", "effective_from": "2099-12-31T00:00:00Z"}
```

**Observado**: `201 Created`. `currentPrice()` ainda retorna o preço anterior (o
`effective_from` da faixa futura > now), mas `priceAt("2099-12-31T01:00:00Z")` retorna
a nova faixa.

**Veredicto**: **ACCEPTED BY DESIGN** — precificação agendada é um caso de uso legítimo.
Documente na spec da API. Se o agendamento deve ser restrito a admins, exija
auth e verifique `effective_from <= now + 30 dias` para chamadores não-admin.

---

### ATK-10 — Definição de preço concorrente (condição de corrida)

**Ataque**: Enviar dois `POST /products/1/prices` simultâneos com o mesmo `effective_from`.

**Observado**: Sem uma transação encapsulando o UPDATE + INSERT, ambas as requisições podem
passar pela fase UPDATE e ambas fazer INSERT, criando duas faixas abertas (`effective_to IS NULL`).
Queries usam `ORDER BY effective_from DESC LIMIT 1`, então resultados são não-determinísticos.

**Veredicto**: **EXPOSED** — encapsule `setPrice` em `transactional()`:
```php
return $this->txManager->transactional(function ($tx) use (...) {
    // UPDATE depois INSERT dentro da transação
});
```

---

### ATK-11 — product_id inexistente

**Ataque**: Definir um preço para um produto que não existe.

```http
POST /products/99999/prices
{"amount": 100, "currency": "USD", "effective_from": "2026-05-27T00:00:00Z"}
```

**Observado**: `404 Not Found` — `findProduct(99999)` retorna `null` e o controller
retorna uma resposta Problem Details de não-encontrado antes de chamar `setPrice`.

**Veredicto**: **BLOCKED** — verificação de existência antes da mutação.

---

### ATK-12 — IDs de caminho não numéricos

**Ataque**: Passar strings não-dígito como `{id}`.

```http
GET /products/abc
GET /products/-1
POST /products/0/prices
```

**Observado**: Todos retornam `404 Not Found`. `(int) "abc"` = `0`; `findProduct(0)` retorna
`null` (nenhum produto com ID 0); controller retorna 404.

**Veredicto**: **BLOCKED** na prática. Nota: `(int) "9abc"` = `9` — um produto com
ID 9 corresponderia. Use `ctype_digit()` para validação estrita de caminho quando necessário.

---

## Resumo ATK

| # | Vetor de ataque | Veredicto |
|---|----------------|-----------|
| ATK-01 | Sem autenticação | EXPOSED (por design) |
| ATK-02 | Manipulação de preço retroativo | EXPOSED |
| ATK-03 | Injeção SQL via `?datetime=` | BLOCKED |
| ATK-04 | Preço com valor zero | ACCEPTED BY DESIGN |
| ATK-05 | Valor negativo | BLOCKED |
| ATK-06 | Injeção de código de moeda (sem allowlist) | EXPOSED |
| ATK-07 | Valor extremamente grande | BLOCKED |
| ATK-08 | Formato de datetime inválido | PARTIALLY EXPOSED |
| ATK-09 | `effective_from` no futuro (preço agendado) | ACCEPTED BY DESIGN |
| ATK-10 | Condição de corrida em setPrice concorrente | EXPOSED |
| ATK-11 | Produto inexistente | BLOCKED |
| ATK-12 | IDs de caminho não numéricos | BLOCKED |

**Vulnerabilidades reais a corrigir antes de produção**:
1. **ATK-01** — Adicionar autenticação/autorização
2. **ATK-02** — Restringir retroação a chamadores admin (ou desabilitar completamente)
3. **ATK-06** — Validar `currency` contra allowlist ISO 4217
4. **ATK-08** — Validar formato de `?datetime=` antes da query no banco
5. **ATK-10** — Encapsular UPDATE+INSERT de `setPrice` em uma transação

---

## Howtos relacionados

- [`expense-tracker.md`](expense-tracker.md) — validação de valor com `is_int()` e round-trip de data ISO 8601
- [`habit-tracker.md`](habit-tracker.md) — padrão ATK-01~12 (ciclo ATK anterior)
- [`prevent-double-booking.md`](prevent-double-booking.md) — leitura-verificação-escrita transacional
- [`iso-datetime-validation.md`](iso-datetime-validation.md) — validação estrita ISO 8601
