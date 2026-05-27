# Como Fazer: Razão de Contabilidade Multi-Moeda com Centavos Inteiros

> **Referência FT**: FT262 (`NENE2-FT/moneylog`) — API de razão multi-moeda usando unidades menores inteiras (centavos) e um objeto de valor `Money`

Demonstra uma API de razão no estilo de contabilidade dupla que armazena valores monetários como unidades menores inteiras
(centavos, yen, pence) para evitar erros de precisão de ponto flutuante. Um objeto de valor `Money` aplica
as invariantes: valor positivo e código de moeda ISO 4217 de 3 caracteres. O saldo por moeda é
calculado com `SUM(CASE WHEN type = 'credit' ...)` em uma única query SQL.

---

## Rotas

| Método | Caminho           | Descrição                                   |
|--------|-------------------|---------------------------------------------|
| `POST` | `/entries`        | Criar uma entrada no razão (crédito ou débito) |
| `GET`  | `/entries`        | Listar entradas (paginadas)                 |
| `GET`  | `/entries/{id}`   | Obter uma única entrada                     |
| `GET`  | `/balance`        | Saldo por moeda (crédito − débito)          |

---

## Schema

```sql
CREATE TABLE IF NOT EXISTS entries (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    description  TEXT    NOT NULL,
    amount_cents INTEGER NOT NULL CHECK(amount_cents > 0),
    currency     TEXT    NOT NULL CHECK(length(currency) = 3),
    type         TEXT    NOT NULL CHECK(type IN ('credit', 'debit')),
    created_at   TEXT    NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_entries_currency ON entries(currency);
CREATE INDEX IF NOT EXISTS idx_entries_created  ON entries(created_at);
```

`CHECK(amount_cents > 0)` aplica valores positivos na camada do banco de dados — uma rede de segurança para bugs
ou acesso direto ao banco. `CHECK(length(currency) = 3)` aplica o formato ISO 4217.
`CHECK(type IN ('credit', 'debit'))` previne estado inválido.

---

## Por que centavos inteiros, não float?

```php
// ❌ Aritmética float perde precisão
var_dump(0.1 + 0.2);  // float(0.30000000000000004)

// ✅ Aritmética inteira é exata
$total = 10 + 20;     // int(30) — sempre exato
```

Valores monetários armazenados como `FLOAT` acumulam erros de arredondamento em somas e não podem ser
comparados de forma confiável com `===`. Unidades menores inteiras (centavos para USD/EUR, yen para JPY) são sempre
exatas. A conversão de exibição (`$cents / 100.0`) só acontece na serialização, não na lógica de negócio.

**Ressalva**: `JPY` e moedas similares sem decimais armazenam unidades inteiras como "centavos"
(ou seja, ¥1000 = 1000 centavos). `formatDecimal()` neste FT usa um padrão fixo de 2 decimais;
uma implementação de produção deve verificar as casas decimais da moeda.

---

## Objeto de valor `Money`

```php
final readonly class Money
{
    public function __construct(
        public int    $amountCents,
        public string $currency,
    ) {
        if ($amountCents <= 0) {
            throw new \InvalidArgumentException("amountCents must be positive, got {$amountCents}.");
        }
        if (strlen($currency) !== 3) {
            throw new \InvalidArgumentException("currency must be a 3-character ISO 4217 code, got '{$currency}'.");
        }
    }

    public function formatDecimal(): string
    {
        return number_format($this->amountCents / 100, 2, '.', '');
    }
}
```

O construtor valida suas próprias invariantes. Um objeto `Money` que existe é sempre válido —
os chamadores nunca precisam reverificar os valores. `readonly` previne mutação após a construção.

`formatDecimal()` é apenas para exibição. Nunca armazene ou compare a string formatada; sempre
compare inteiros `amountCents`.

---

## Enum backed `EntryType`

```php
enum EntryType: string
{
    case Credit = 'credit';
    case Debit  = 'debit';
}
```

`EntryType::from('credit')` na hidratação converte a string do banco de dados para o enum. Se o banco de dados
de alguma forma contiver um valor inesperado, `from()` lança uma exceção — sem corrupção silenciosa.

`EntryType::tryFrom($value)` no controller retorna `null` para valores desconhecidos, que
a verificação de erro de validação então captura:

```php
$type = $typeValue !== null ? EntryType::tryFrom($typeValue) : null;
if ($type === null) {
    $errors[] = new ValidationError('type', "type must be 'credit' or 'debit'.", 'invalid');
}
```

---

## Saldo por moeda: `SUM(CASE WHEN ...)`

```php
public function balanceByCurrency(): array
{
    $rows = $this->executor->fetchAll(
        "SELECT currency,
            SUM(CASE WHEN type = 'credit' THEN amount_cents ELSE 0 END) AS credit_cents,
            SUM(CASE WHEN type = 'debit'  THEN amount_cents ELSE 0 END) AS debit_cents,
            SUM(CASE WHEN type = 'credit' THEN amount_cents ELSE -amount_cents END) AS balance_cents
         FROM entries
         GROUP BY currency
         ORDER BY currency ASC",
        [],
    );
    // ...
}
```

Uma única query calcula três agregados por moeda:
- `credit_cents`: total de créditos
- `debit_cents`: total de débitos
- `balance_cents`: saldo líquido (`crédito − débito`)

`CASE WHEN type = 'credit' THEN amount_cents ELSE -amount_cents END` usa uma inversão de sinal para
calcular o líquido em uma única passagem. Um `balance_cents` negativo significa que os débitos excedem os créditos.

**Alternativa**: duas queries (`SELECT SUM WHERE type = 'credit'` e `SELECT SUM WHERE type = 'debit'`),
mescladas no PHP. A abordagem de query única é mais eficiente e mantém a subtração no SQL.

---

## Controller: normalização de moeda

```php
$money = new Money(
    (int) $body['amount_cents'],
    strtoupper((string) $body['currency']),  // ← normalizar para maiúsculas
);
```

`strtoupper()` normaliza o código de moeda para que `usd`, `USD` e `Usd` sejam todos armazenados como `USD`.
Sem normalização, `USD` e `usd` apareceriam como moedas separadas na query de saldo.

---

## Serialização: tanto centavos quanto decimal

```php
private function serialize(Entry $entry): array
{
    return [
        'id'           => $entry->id,
        'description'  => $entry->description,
        'amount_cents' => $entry->money->amountCents,   // legível por máquina: inteiro exato
        'amount'       => $entry->money->formatDecimal(), // legível por humanos: "10.50"
        'currency'     => $entry->money->currency,
        'type'         => $entry->type->value,
        'created_at'   => $entry->createdAt,
    ];
}
```

Tanto `amount_cents` (inteiro) quanto `amount` (decimal formatado) são retornados. Clientes realizando
cálculos devem usar `amount_cents`; UIs de exibição podem usar `amount`.

---

## Exemplo: resposta de saldo

**Requisição**: `GET /balance`

```json
{
  "balances": [
    {"currency": "EUR", "credit_cents": 50000, "debit_cents": 20000, "balance_cents": 30000},
    {"currency": "JPY", "credit_cents": 100000, "debit_cents": 0, "balance_cents": 100000},
    {"currency": "USD", "credit_cents": 150000, "debit_cents": 75000, "balance_cents": 75000}
  ]
}
```

Saldo EUR: 500,00 − 200,00 = 300,00 EUR. Saldo USD: 1500,00 − 750,00 = 750,00 USD.

---

## Comparação de design

| Abordagem de armazenamento | Precisão | Trade-offs |
|---|---|---|
| `INTEGER` centavos | Exata | Requer conversão de exibição; a moeda deve especificar casas decimais |
| `DECIMAL(19,4)` | Exata | Nativo do banco de dados; não disponível no SQLite; formatar para exibição |
| `FLOAT`/`REAL` | Com perda | Nunca use para dinheiro — erros de arredondamento se acumulam |
| `TEXT` ("10.50") | N/A | Ordenação e soma requerem casting; sem aritmética no SQL |

`INTEGER` do SQLite com centavos é a abordagem segura mais simples para APIs com SQLite.
Para MySQL/PostgreSQL, `DECIMAL(19,4)` é mais convencional.

---

## Howtos relacionados

- [`transaction-scope-pattern.md`](transaction-scope-pattern.md) — escrita atômica múltipla para transferências de fundos
- [`bulk-operations-partial-success.md`](bulk-operations-partial-success.md) — importação de entradas em massa com sucesso parcial
- [`leaderboard-ranking-api.md`](leaderboard-ranking-api.md) — queries de agregação com funções de janela SQL
