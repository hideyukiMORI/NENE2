# Como Fazer: Usar Window Functions do SQLite

Window functions computam um valor sobre um conjunto de linhas *relacionadas à linha atual* sem colapsá-las em um único grupo (como o `GROUP BY` faz). Elas são a ferramenta certa para **ranking**, **totais acumulados** e **comparação período-a-período** — três padrões que são desajeitados e lentos de fazer em PHP depois do fato.

O SQLite suporta window functions desde a **3.25.0** (2018). O NENE2 vem com o SQLite empacotado do PHP, que está bem além disso; MySQL 8.0+ e PostgreSQL também suportam a mesma sintaxe, então essas queries são portáveis entre os três adaptadores que o NENE2 mira.

Você as executa através de `DatabaseQueryExecutorInterface::fetchAll()` como qualquer outra query de leitura — não há suporte especial do framework para conectar.

**Pré-requisito**: Você tem um repositório respaldado por `DatabaseQueryExecutorInterface`. Veja [Add a database-backed endpoint](add-database-endpoint.md).

---

## 1. A anatomia de uma window

```sql
ROW_NUMBER() OVER (PARTITION BY game ORDER BY points DESC)
```

- `PARTITION BY game` — reinicia a window para cada game (omita para tratar todas as linhas como uma única window).
- `ORDER BY points DESC` — ordena *dentro* da partição; isso define o que "primeiro" e "anterior" significam.

Quando várias colunas reusam a mesma window, nomeie-a uma vez com uma cláusula `WINDOW`:

```sql
SELECT player, game, points,
       ROW_NUMBER()  OVER w AS rn,
       RANK()        OVER w AS rnk,
       DENSE_RANK()  OVER w AS drnk
FROM scores
WINDOW w AS (PARTITION BY game ORDER BY points DESC)
ORDER BY game, points DESC;
```

---

## 2. Ranking: `ROW_NUMBER` vs `RANK` vs `DENSE_RANK`

Os três diferem apenas em como tratam empates. Dados dois jogadores empatados em 150 em `chess`:

| player | game  | points | `ROW_NUMBER` | `RANK` | `DENSE_RANK` |
|--------|-------|--------|--------------|--------|--------------|
| b      | chess | 150    | 1            | 1      | 1            |
| c      | chess | 150    | 2            | 1      | 1            |
| a      | chess | 100    | 3            | 3      | 2            |

- **`ROW_NUMBER`** — sempre único (1, 2, 3). Use para cursores de paginação estáveis ou "escolher exatamente um por grupo".
- **`RANK`** — empates compartilham um rank, depois pula (1, 1, 3). Use para placares onde "1º conjunto" é significativo.
- **`DENSE_RANK`** — empates compartilham um rank, sem lacuna (1, 1, 2). Use para baldes de "tier" / nota.

> Escolha a função de rank deliberadamente. Um placar que mostra dois jogadores "rank 2" e nenhum "rank 1" é quase sempre uma confusão entre `RANK`/`ROW_NUMBER`.

Em um método de repositório:

```php
/**
 * @return list<array{player: string, game: string, points: int, rank: int}>
 */
public function topRankedByGame(string $game): array
{
    return $this->executor->fetchAll(
        'SELECT player, game, points,
                RANK() OVER (PARTITION BY game ORDER BY points DESC) AS rank
         FROM scores
         WHERE game = :game
         ORDER BY points DESC',
        ['game' => $game],
    );
}
```

---

## 3. Total acumulado: um agregado como window

Qualquer agregado (`SUM`, `AVG`, `COUNT`, …) se torna um agregado *acumulado* quando recebe uma cláusula `OVER (...)` e um frame:

```sql
SELECT created_at, points,
       SUM(points) OVER (ORDER BY created_at ROWS UNBOUNDED PRECEDING) AS running_total
FROM scores
ORDER BY created_at;
```

| created_at | points | running_total |
|------------|--------|---------------|
| 2026-01-01 | 100    | 100           |
| 2026-01-02 | 150    | 250           |
| 2026-01-03 | 150    | 400           |
| 2026-01-04 | 90     | 490           |

`ROWS UNBOUNDED PRECEDING` significa "toda linha do início da partição até a linha atual". Sem um frame explícito, o padrão (`RANGE UNBOUNDED PRECEDING`) soma **todas as linhas que empatam no valor do `ORDER BY`** no mesmo passo — uma fonte sutil de totais errados quando timestamps colidem. Seja explícito com `ROWS` quando quiser um verdadeiro total acumulado linha a linha.

---

## 4. Período-a-período: `LAG` e `LEAD`

`LAG` lê uma coluna da linha *anterior* na window; `LEAD` lê a *próxima*. Isso computa um delta sem um self-join:

```sql
SELECT created_at, points,
       points - LAG(points) OVER (ORDER BY created_at) AS delta
FROM scores
ORDER BY created_at;
```

| created_at | points | delta |
|------------|--------|-------|
| 2026-01-01 | 100    | *null* |
| 2026-01-02 | 150    | 50    |
| 2026-01-03 | 150    | 0     |
| 2026-01-04 | 90     | −60   |

O `delta` da primeira linha é `NULL` porque não há linha anterior. Forneça um padrão para evitar tratamento de null a jusante: `LAG(points, 1, 0)` retorna `0` em vez de `NULL` para a primeira linha. Mapeie `NULL` para um valor tipado no seu DTO em vez de vazá-lo para a resposta JSON.

---

## 5. Filtrar sobre o resultado de uma window

Você **não pode** colocar uma window function em uma cláusula `WHERE` — windows são avaliadas *depois* do `WHERE`. Envolva a query em uma subquery (ou CTE) e filtre pelo alias:

```sql
WITH ranked AS (
    SELECT player, game, points,
           ROW_NUMBER() OVER (PARTITION BY game ORDER BY points DESC) AS rn
    FROM scores
)
SELECT player, game, points
FROM ranked
WHERE rn <= 3            -- top 3 per game
ORDER BY game, points DESC;
```

Esta forma "top-N-por-grupo" é o uso mais comum no mundo real; recorra a ela em vez de `N` queries `LIMIT` separadas.

---

## 6. Retornando como uma resposta tipada

Mantenha o SQL no repositório e mapeie para um DTO readonly antes que ele alcance o controller — não passe o `array` raw através da fronteira:

```php
final readonly class GameRanking
{
    public function __construct(
        public int $rank,
        public string $player,
        public int $points,
    ) {}
}
```

```php
/** @return list<GameRanking> */
public function topRankedByGame(string $game): array
{
    $rows = $this->executor->fetchAll(
        'SELECT player, points,
                RANK() OVER (PARTITION BY game ORDER BY points DESC) AS rank
         FROM scores WHERE game = :game ORDER BY points DESC',
        ['game' => $game],
    );

    return array_map(
        static fn (array $r): GameRanking => new GameRanking(
            rank: (int) $r['rank'],
            player: (string) $r['player'],
            points: (int) $r['points'],
        ),
        $rows,
    );
}
```

O SQLite retorna todos os valores de coluna como strings via PDO, então faça cast (`(int)`, `(float)`) dentro do mapper — o resultado da window function (`rank`, `running_total`) não é exceção.

---

## Armadilhas

- **`WHERE` não enxerga aliases de window** — filtre em uma query externa/CTE (§5).
- **O frame padrão é `RANGE`, não `ROWS`** — seja explícito com `ROWS UNBOUNDED PRECEDING` para totais acumulados (§3).
- **`LAG`/`LEAD` retornam `NULL` nas bordas** — passe um padrão ou mapeie para um valor tipado (§4).
- **Portabilidade** — a sintaxe acima é padrão e roda em SQLite 3.25+, MySQL 8.0+ e PostgreSQL. Se você mira um MySQL mais antigo (5.7), window functions não estão disponíveis; recorra a um self-join ou compute em PHP.
- **Indexe as colunas do `ORDER BY`** — o `PARTITION BY` / `ORDER BY` de uma window se beneficia dos mesmos índices que uma ordenação comum.

---

## Relacionados

- [Use database transactions](use-transactions.md) — escritas atômicas multi-passo
- [Leaderboard ranking](leaderboard-ranking.md) — uma receita de produto construída sobre ranking
- [Add a database-backed endpoint](add-database-endpoint.md) — conexão de repositório + executor
