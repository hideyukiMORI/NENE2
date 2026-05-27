# Como Fazer: Dinheiro e Aritmética com Inteiros

> **Cenários relacionados**: Cenário DX 10, 23, 32, 36, 40, 43, 44, 50 — a fonte mais frequentemente citada de bugs silenciosos de precisão em cenários financeiros.

Valores monetários armazenados como ponto flutuante (`REAL` / `float`) acumulam erros de arredondamento.
`1001 * 0.05` em IEEE 754 produz `50.049999999999997`, não `50.05`.
A abordagem correta é armazenar e calcular valores como **inteiros na menor unidade de moeda**
(yen para JPY, centavos para USD/EUR).

---

## A regra: sempre armazene como inteiro

```php
// ❌ Errado — REAL/float acumula erros
$fee = $amount * 0.05;           // 1001 * 0.05 = 50.04999...
$tax = $price * 1.10;            // 1000 * 1.10 = 1100.0000000000002

// ✅ Correto — aritmética com inteiros
$fee = intdiv($amount * 5, 100); // 1001 * 5 / 100 = 50 (truncado)
$tax = intdiv($amount * 110, 100); // 1000 * 110 / 100 = 1100
```

Schema:

```sql
CREATE TABLE orders (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    amount_yen   INTEGER NOT NULL CHECK(amount_yen > 0),  -- ✅ INTEGER, não REAL
    fee_yen      INTEGER NOT NULL CHECK(fee_yen >= 0),
    tax_yen      INTEGER NOT NULL CHECK(tax_yen >= 0),
    total_yen    INTEGER NOT NULL CHECK(total_yen > 0)
);
```

Use constraints `CHECK` para aplicar valores não-negativos na camada do banco de dados.

---

## Escolhendo a função de arredondamento

Ao dividir inteiros, você deve decidir como lidar com o resto.
**Decida e documente esta política antes de escrever o código** — mudá-la depois afeta
cada registro histórico.

| Função | Comportamento | Exemplo: `intdiv(1, 3)` | Quando usar |
|--------|--------------|------------------------|-------------|
| `intdiv($a, $b)` | Truncar em direção a zero | `0` | Taxas de plataforma (pagador fica com o resto) |
| `(int) round($a / $b)` | Arredondar metade para cima | `0` (arredonda para 0) | Divisão de contas, arredondamento genérico |
| `(int) ceil($a / $b)` | Arredondar para cima (teto) | `1` | Cálculo de imposto (sempre arredondar para cima para o governo) |
| `(int) floor($a / $b)` | Arredondar para baixo (chão) | `0` | Igual a intdiv para valores positivos |

### Taxa de plataforma (5%) — quem fica com o resto?

```php
// Opção A: plataforma fica com floor (favorece o pagador)
$fee = intdiv($amount * 5, 100);     // 1001 yen → taxa = 50, vendedor recebe 951

// Opção B: plataforma fica com ceil (favorece a plataforma)
$fee = (int) ceil($amount * 5 / 100); // 1001 yen → taxa = 51, vendedor recebe 950

// Opção C: arredondar metade para cima (neutro)
$fee = (int) round($amount * 5 / 100); // 1001 yen → taxa = 50, vendedor recebe 951
```

Não há uma resposta universalmente correta. **Documente a escolha na especificação da API.**

---

## Cálculo de imposto (imposto de consumo japonês: 10%)

O imposto de consumo japonês requer **arredondamento para baixo** por transação (não por item de linha):

```php
// ✅ Truncar no nível da transação
$taxIncluded  = intdiv($priceExcl * 110, 100);  // 1000 → 1100
$taxAmount    = intdiv($priceExcl * 10, 100);   // 1000 → 100

// ❌ NÃO arredonde por item de linha e depois some — erros de arredondamento se acumulam
$items = [100, 100, 100]; // 3 itens × 100 yen
$total = array_sum(array_map(fn($p) => (int)round($p * 1.1), $items)); // pode diferir de intdiv(300 * 110, 100)
```

Se armazenar um `tax_rate`, armazene-o como **pontos base** (inteiro, 1/10000):
`10% = 1000 bps`. Evita ponto flutuante no próprio armazenamento da taxa.

```sql
tax_rate_bps INTEGER NOT NULL DEFAULT 1000  -- 10.00%
```

```php
$taxAmount = intdiv($amount * $taxRateBps, 10000);
```

---

## Divisão/repartição: distribuição do resto

Ao dividir um total entre N participantes:

```php
function splitEvenly(int $totalYen, int $n): array
{
    $base      = intdiv($totalYen, $n);       // parcela de cada pessoa (truncada)
    $remainder = $totalYen % $n;              // yen restante (0 a n-1)

    $shares = array_fill(0, $n, $base);

    // Distribuir o resto 1 yen de cada vez para os primeiros participantes
    for ($i = 0; $i < $remainder; $i++) {
        $shares[$i]++;
    }

    // Verificar: a soma deve ser igual ao total original
    assert(array_sum($shares) === $totalYen);

    return $shares;
}

// splitEvenly(1000, 3) → [334, 333, 333]  (soma = 1000) ✅
// splitEvenly(100,  3) → [34,  33,  33]   (soma = 100)  ✅
```

Nunca use `round($total / $n)` para cada participante e considere encerrado —
a soma frequentemente ficará fora por 1 yen.

---

## Armadilha de divisão inteira do SQLite

No SQLite, dividir dois inteiros realiza divisão inteira:

```sql
SELECT 5 / 100;     -- → 0  (divisão inteira: trunca)
SELECT 5.0 / 100;   -- → 0.05 (divisão real)
SELECT 5 * 100 / 100;  -- → 5 (multiplicar primeiro, depois dividir — OK)
```

**No PHP** com PDO, todos os valores vinculados são enviados como strings. O SQLite os coerce, mas:

```php
// Seguro: multiplicar primeiro para evitar truncamento
$fee = $this->db->fetchOne(
    'SELECT amount_yen * 5 / 100 AS fee FROM orders WHERE id = ?',
    [$id],
);
// → amount_yen * 5 primeiro (inteiro * inteiro = inteiro), depois / 100

// Arriscado: se o PDO enviar '5' e '100' como strings, o SQLite pode escolher divisão real
// Teste isso se a versão do SQLite ou o comportamento do PDO for incerto.
```

A abordagem mais segura: **faça a aritmética no PHP com `intdiv()`**, armazene o resultado,
e use aritmética SQL apenas para soma (`SUM`, `COUNT`), não para cálculo por linha.

---

## Depreciação (método linear)

```php
// Depreciação anual (método linear)
$annualDepr = intdiv($purchasePrice - $salvageValue, $usefulLifeYears);

// Valor contábil atual
$yearsElapsed = (int) floor(
    (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->diff(
        new \DateTimeImmutable($purchaseDateUtc)
    )->days / 365
);
$currentValue = max($salvageValue, $purchasePrice - $annualDepr * $yearsElapsed);
```

`intdiv` trunca a depreciação anual, o que significa que o ativo deprecia ligeiramente
menos por ano e o resto aparece como depreciação extra no último ano.
Este é o comportamento padrão para depreciação linear japonesa.

---

## Exibindo para o usuário

Converta para um formato legível apenas na camada de resposta, nunca no domínio:

```php
final readonly class MoneyResponse
{
    public function __construct(
        public int    $amountYen,
        public string $displayAmount,  // "¥1,234"
    ) {}

    public static function fromYen(int $yen): self
    {
        return new self(
            amountYen:     $yen,
            displayAmount: '¥' . number_format($yen),
        );
    }
}
```

Armazene `amountYen` (inteiro) para cálculo posterior; `displayAmount` (string) para a UI.
Nunca armazene strings formatadas — elas não podem ser somadas.

---

## Resumo: checklist de decisão

Antes de escrever qualquer cálculo monetário, responda a estas perguntas:

1. **Unidade**: yen (sem decimal), centavos (1/100), ou micropennies (1/1000)?
   → Armazene como inteiro nessa unidade; documente a unidade no nome da coluna (`amount_yen`, `price_cents`).

2. **Direção do arredondamento**: `intdiv` (truncar), `ceil`, `floor` ou `round`?
   → Escolha uma; adicione um comentário no código explicando o motivo.

3. **Quem fica com o resto**: ao dividir, quem absorve a diferença de arredondamento?
   → Distribua o resto explicitamente (veja `splitEvenly` acima).

4. **Armazenamento da taxa de imposto**: pontos base (`INTEGER`) não porcentagem (`REAL`)?
   → `1000` para 10%, `800` para 8%, nunca `0.10` ou `0.08`.

5. **Cumulativo ou por transação**: acumule imposto por item de linha ou por total de fatura?
   → Por transação (único `intdiv`) é padrão para faturas em JPY.

---

## Howtos relacionados

- [`multi-currency-money-ledger.md`](multi-currency-money-ledger.md) — razão de contabilidade dupla com objeto de valor `Money`
- [`point-ledger-api.md`](point-ledger-api.md) — sistema de pontos/créditos com valores inteiros
- [`expense-tracking-api.md`](expense-tracking-api.md) — registro de despesas com yen inteiro
