# 操作指南：批量重排序（拖放排序）API

拖放式 UI 在一个请求中发送列表的*整个*新顺序：`[itemC, itemA, itemD, itemB]`。朴素的服务器对每个条目执行一次 `UPDATE`——这会带来 N 次往返，并且如果其中一次失败就会留下一个只应用了一半的顺序。

正确的形态是**一个事务**，用服务器分配的值重写每一个 position，并限定在所属者的看板范围内。如何编写取决于一件事：**`position` 是否带有 `UNIQUE (board_id, position)` 约束。**

> **已验证的坑（FT352）。** SQLite 在 `UPDATE` 应用过程中**逐行**检查 `UNIQUE`。因此*任何*交换 position 的语句——哪怕是对所有行的单条 `CASE WHEN`——都会在中途使两行处于相同的 position 而失败，报错 `UNIQUE constraint failed: items.board_id, items.position`。**只有在 `position` 没有 `UNIQUE` 约束时**，单条语句才足够（§1）。带约束时，你需要在一个事务内进行两阶段写入（§1.1）。可运行的证明见 [`NENE2-examples/reorderlog`](https://github.com/hideyukiMORI/NENE2-examples/tree/main/reorderlog)。

**前提条件**：一张带有限定到父级（`board_id`、`list_id`……）的整数 `position` 列的表。单条目场景请参见[内容置顶](content-pinning.md)。

---

## 1. 单条语句（`position` 上无 `UNIQUE` 约束）

客户端只发送*有序的 id 列表*。服务器从数组下标推导 position——它从不信任客户端提供的 position 数值。当 `position` 只是一个带索引的列（无 `UNIQUE`）时，单条语句就足够：

```php
/**
 * @param list<int> $orderedIds  ids in their new display order
 * @return int  number of rows actually updated
 */
public function reorder(int $boardId, array $orderedIds): int
{
    $cases  = '';
    $params = [];
    foreach (array_values($orderedIds) as $position => $id) {
        $cases   .= ' WHEN id = ? THEN ?';
        $params[] = $id;
        $params[] = $position;          // position = array index, not client input
    }

    $placeholders = implode(',', array_fill(0, count($orderedIds), '?'));
    $sql = "UPDATE items
            SET position = CASE{$cases} END
            WHERE board_id = ? AND id IN ({$placeholders})";

    return $this->executor->execute(
        $sql,
        [...$params, $boardId, ...$orderedIds],
    );
}
```

已针对 SQLite 验证——在一条语句中将 `[1,2,3,4]` 重排为 id `[3,1,4,2]`：

```
affected = 4
position 0 -> item 3
position 1 -> item 1
position 2 -> item 4
position 3 -> item 2
```

position 从数组下标被重新分配为 `0..n-1`，因此无论客户端发送了什么，结果总是连续的。

---

## 1.1. 当 `position` 为 `UNIQUE` 时的两阶段写入

如果用 `UNIQUE (board_id, position)` 守护你的排序（推荐——它在数据库层面阻止重复的 position），那么上面的单条语句在交换两行的那一刻就会失败。先把每个 position 移到一个无冲突的范围，然后再分配最终值——两个步骤都在**一个事务**中进行，这样中间状态永远不可观察：

```php
public function reorder(int $boardId, array $orderedIds): void
{
    $this->tx->transactional(function ($executor) use ($boardId, $orderedIds): void {
        // Phase 1: move every position to a unique negative value (no collisions).
        $executor->execute(
            'UPDATE items SET position = -1 - position WHERE board_id = ?',
            [$boardId],
        );

        // Phase 2: assign final positions from the array index.
        $cases = '';
        $params = [];
        foreach ($orderedIds as $position => $id) {
            $cases   .= ' WHEN id = ? THEN ?';
            $params[] = $id;
            $params[] = $position;
        }
        $placeholders = implode(',', array_fill(0, count($orderedIds), '?'));
        $executor->execute(
            "UPDATE items SET position = CASE{$cases} END WHERE board_id = ? AND id IN ({$placeholders})",
            [...$params, $boardId, ...$orderedIds],
        );
    });
}
```

`-1 - position` 将 `0,1,2,…` 映射为 `-1,-2,-3,…`——这些值各不相同，不可能与最终的 `0..n-1` 冲突。关于 `transactional()` 规则（在回调*内部*实例化 repository），请参见[使用事务](use-transactions.md)。`reorderlog` 的 `testReorderAdjacentSwapDoesNotCollide` 恰好演练了会让单条语句失败的那种交换。

---

## 2. 受影响行数就是你的完整性检查

`execute()` 返回与 `WHERE board_id = ? AND id IN (...)` 匹配的行数。将它与请求规模比较：

```php
$updated = $this->reorder($boardId, $orderedIds);
if ($updated !== count($orderedIds)) {
    // The client referenced ids that are not in this board (or do not exist).
    throw new ValidationException(/* 'ids' => 'contains items not in this board' */);
}
```

这一项检查就击败了下方的大部分攻击面：任何属于另一个看板的 id，或者根本不存在的 id，都不会匹配 `WHERE`，因此计数会偏少，整个重排序都会被拒绝。

> 如果你还要变更相关的行，请将计数检查和 `UPDATE` 包裹在 `transactional()` 中；单条 `UPDATE` 本身已经是原子的。参见[使用事务](use-transactions.md)。

---

## ATK 评估 — 攻击者思维攻击测试

目标：`PUT /boards/{boardId}/order`，请求体为 `{ "ids": [...] }`，已认证，`board_id` 限定到调用方。

### ATK-01 — 重排序一个你并不拥有的看板（IDOR）🚫 BLOCKED

**攻击**：发送一个有效的 `ids` 数组，但 `boardId` 属于另一个用户。
**结果**：BLOCKED — 在查询之前检查所有权（`board.owner_id === caller`），返回 `404`；即便跳过了该检查，`WHERE board_id = ?` 也匹配不到调用方 id 所属的任何行，因此受影响计数为 0，请求被拒绝。

---

### ATK-02 — 把外部条目走私进顺序中 🚫 BLOCKED

**攻击**：包含一个来自不同看板的 `id`，以移动/泄露它。
**结果**：BLOCKED — `WHERE board_id = ? AND id IN (...)` 排除了外部 id；受影响计数 < 请求规模 → `422`，无部分写入。

---

### ATK-03 — 部分顺序（省略 id 以制造空缺）🚫 BLOCKED

**攻击**：只发送看板一半的 id，以使其余条目停留在过时的 position 上。
**结果**：BLOCKED — 处理器要求提交的集合等于看板当前的 id 集合（数量 + 成员），拒绝不完整的载荷。

---

### ATK-04 — 注入显式的 position 数值 🚫 BLOCKED

**攻击**：发送 `{ "ids": [...], "positions": [99, -1, ...] }`，期望服务器遵从它们。
**结果**：BLOCKED — 服务器忽略任何客户端 position；`position` 就是数组下标。多余的请求体字段被 readonly DTO 丢弃。

---

### ATK-05 — 通过 id / position 的 SQL 注入 🚫 BLOCKED

**攻击**：`ids: ["1); DROP TABLE items;--", ...]`。
**结果**：BLOCKED — 每个 id 和 position 都是绑定参数；`CASE`/`IN` 占位符由数量生成，从不通过字符串拼接。

---

### ATK-06 — 用重复 id 破坏 position 🚫 BLOCKED

**攻击**：`ids: [5, 5, 5]`，使一行获得多条 `CASE` 分支。
**结果**：BLOCKED — DTO 校验 id 唯一性；无论如何 SQLite 都会应用最后一个匹配的 `WHEN`，并且计数检查（`distinct ids` 对比看板规模）会先失败。

---

### ATK-07 — 超大载荷（DoS）🚫 BLOCKED

**攻击**：提交 1,000,000 个 id 以构建一个巨大的 `CASE`。
**结果**：BLOCKED — `RequestSizeLimitMiddleware` 限制请求体，且处理器拒绝大于看板行数的数组。

---

### ATK-08 — 非整数 / 负数 id 🚫 BLOCKED

**攻击**：`ids: ["abc", -1, 1.5]`。
**结果**：BLOCKED — DTO 校验在任何 SQL 运行之前将每一项强制转换/校验为正整数（失败返回 `422`）。

---

### ATK-09 — 并发重排序竞态 🚫 BLOCKED

**攻击**：同时发起两次重排序以交错 position。
**结果**：BLOCKED — 每次重排序在一个事务中运行；最后的写入者胜出，得到完全一致的 `0..n-1` 排序，绝不会是交错的混合。两阶段写入（§1.1）将中间状态保留在事务内，因此并发读取者永远看不到部分或冲突的顺序。

---

### ATK-10 — position 溢出 / 非连续结果 🚫 BLOCKED

**攻击**：期望反复重排序使 position 漂移到巨大或稀疏的值。
**结果**：BLOCKED — 每次重排序都从 `0` 重写 position，因此该列始终密集且以行数为界。

---

### ATK-11 — 用空顺序抹除 position 🚫 BLOCKED

**攻击**：`ids: []`。
**结果**：BLOCKED — 空数组校验失败（`min 1`），且空的 `IN ()` 会是一个永不执行的语法错误。

---

### ATK-12 — 跨租户看板 id 枚举 🚫 BLOCKED

**攻击**：遍历 `boardId`，通过不同的响应来发现哪些存在。
**结果**：BLOCKED — 未知和不属于自己的看板都返回完全相同的 `404`；没有计数或计时预言机能区分它们。

---

### ATK 汇总

| ID | 攻击 | 结果 |
|----|--------|--------|
| ATK-01 | 重排序不属于自己的看板（IDOR） | 🚫 BLOCKED |
| ATK-02 | 走私外部条目 | 🚫 BLOCKED |
| ATK-03 | 部分顺序 / 空缺 | 🚫 BLOCKED |
| ATK-04 | 注入显式 position | 🚫 BLOCKED |
| ATK-05 | SQL 注入 | 🚫 BLOCKED |
| ATK-06 | 重复 id | 🚫 BLOCKED |
| ATK-07 | 超大载荷 | 🚫 BLOCKED |
| ATK-08 | 非整数 / 负数 id | 🚫 BLOCKED |
| ATK-09 | 并发重排序竞态 | 🚫 BLOCKED |
| ATK-10 | position 溢出 / 稀疏 | 🚫 BLOCKED |
| ATK-11 | 空顺序 | 🚫 BLOCKED |
| ATK-12 | 看板 id 枚举 | 🚫 BLOCKED |

**12 BLOCKED，0 EXPOSED。** 无重大发现。*服务器分配的 position*（数组下标，从不用客户端输入）与针对限定看板范围的 `WHERE` 的*受影响计数 / id 集合完整性检查*相结合，封闭了重排序攻击面。唯一的*正确性*陷阱（并非安全发现）是 `UNIQUE (board_id, position)` 约束：它使单条 `CASE` 语句在任何交换时失败，因此请使用 §1.1 的两阶段事务写入——已在 [`NENE2-examples/reorderlog`](https://github.com/hideyukiMORI/NENE2-examples/tree/main/reorderlog) 中验证。

---

## 相关

- [内容置顶](content-pinning.md) — 单条目 position 管理
- [置顶 / 书签排序](pin-bookmark-ordering.md) — 按用户的排序
- [使用事务](use-transactions.md) — 原子化地包裹多表重排序
