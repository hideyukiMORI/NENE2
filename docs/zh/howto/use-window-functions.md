# 操作指南：使用 SQLite 窗口函数

窗口函数在*与当前行相关的*一组行上计算一个值，而不会像 `GROUP BY` 那样把它们折叠成单个分组。它们是处理**排名**、**累计求和**和**同比/环比对比**的正确工具——这三种模式在事后用 PHP 来做既别扭又慢。

SQLite 从 **3.25.0**（2018）起支持窗口函数。NENE2 随附 PHP 内置的 SQLite，远在该版本之后；MySQL 8.0+ 和 PostgreSQL 也支持相同的语法，因此这些查询可以在 NENE2 所面向的三种适配器之间移植。

你像运行任何其他读查询一样，通过 `DatabaseQueryExecutorInterface::fetchAll()` 来运行它们——无需接入任何特殊的框架支持。

**前提条件**：你有一个由 `DatabaseQueryExecutorInterface` 支撑的 repository。参见[添加数据库支撑的端点](add-database-endpoint.md)。

---

## 1. 窗口的剖析

```sql
ROW_NUMBER() OVER (PARTITION BY game ORDER BY points DESC)
```

- `PARTITION BY game` — 为每个 game 重启窗口（省略则把所有行当作一个窗口）。
- `ORDER BY points DESC` — 在分区*内部*排序；这定义了"第一"和"上一个"的含义。

当多个列复用同一个窗口时，用 `WINDOW` 子句给它命名一次：

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

## 2. 排名：`ROW_NUMBER` vs `RANK` vs `DENSE_RANK`

三者只在如何处理并列上有区别。给定 `chess` 中两名并列 150 分的玩家：

| player | game  | points | `ROW_NUMBER` | `RANK` | `DENSE_RANK` |
|--------|-------|--------|--------------|--------|--------------|
| b      | chess | 150    | 1            | 1      | 1            |
| c      | chess | 150    | 2            | 1      | 1            |
| a      | chess | 100    | 3            | 3      | 2            |

- **`ROW_NUMBER`** — 始终唯一（1、2、3）。用于稳定的分页游标或"每组恰好挑选一个"。
- **`RANK`** — 并列共享一个排名，然后跳过（1、1、3）。用于"并列第 1"有意义的排行榜。
- **`DENSE_RANK`** — 并列共享一个排名，无间隙（1、1、2）。用于"层级" / 等级分桶。

> 刻意地选择排名函数。一个显示两个"排名 2"玩家而没有"排名 1"的排行榜，几乎总是 `RANK`/`ROW_NUMBER` 用混了。

在一个 repository 方法中：

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

## 3. 累计求和：作为窗口的聚合

任何聚合（`SUM`、`AVG`、`COUNT`……）在被赋予 `OVER (...)` 子句和一个帧时，都会变成*累计*聚合：

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

`ROWS UNBOUNDED PRECEDING` 的意思是"从分区起始到当前行的每一行"。如果没有显式的帧，默认值（`RANGE UNBOUNDED PRECEDING`）会把**所有在 `ORDER BY` 值上并列的行**汇入同一步——这是当时间戳冲突时一个微妙的错误总计来源。当你想要真正的逐行累计求和时，请明确使用 `ROWS`。

---

## 4. 同比/环比：`LAG` 和 `LEAD`

`LAG` 读取窗口中*上一行*的某一列；`LEAD` 读取*下一行*的。这无需自连接就能计算差值：

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

第一行的 `delta` 是 `NULL`，因为没有上一行。提供一个默认值以避免下游处理 null：`LAG(points, 1, 0)` 对第一行返回 `0` 而不是 `NULL`。在你的 DTO 中把 `NULL` 映射为一个有类型的值，而不是让它泄漏到 JSON 响应中。

---

## 5. 在窗口结果上过滤

你**不能**把窗口函数放进 `WHERE` 子句——窗口在 `WHERE` *之后*求值。把查询包进一个子查询（或 CTE），然后在别名上过滤：

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

这种"每组前 N"的形态是最常见的实际用法；用它来代替 `N` 个独立的 `LIMIT` 查询。

---

## 6. 把它作为有类型的响应返回

把 SQL 保留在 repository 中，并在到达控制器之前映射为一个 readonly DTO——不要把原始 `array` 跨边界传递：

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

SQLite 通过 PDO 把所有列值返回为字符串，因此要在映射器内部转换类型（`(int)`、`(float)`）——窗口函数的结果（`rank`、`running_total`）也不例外。

---

## 常见陷阱

- **`WHERE` 看不到窗口别名** — 在外层查询/CTE 中过滤（§5）。
- **默认帧是 `RANGE`，不是 `ROWS`** — 对累计求和要明确使用 `ROWS UNBOUNDED PRECEDING`（§3）。
- **`LAG`/`LEAD` 在边缘返回 `NULL`** — 传入一个默认值或映射为有类型的值（§4）。
- **可移植性** — 上面的语法是标准的，可在 SQLite 3.25+、MySQL 8.0+ 和 PostgreSQL 上运行。如果你面向较旧的 MySQL（5.7），窗口函数不可用；回退到自连接或在 PHP 中计算。
- **为 `ORDER BY` 的列建索引** — 窗口的 `PARTITION BY` / `ORDER BY` 与普通排序受益于相同的索引。

---

## 相关

- [使用数据库事务](use-transactions.md) — 原子的多步写入
- [排行榜排名](leaderboard-ranking.md) — 基于排名构建的产品方案
- [添加数据库支撑的端点](add-database-endpoint.md) — repository + executor 接线
