# 操作指南：带部分成功的批量 API

> **FT 参考**：FT294（`NENE2-FT/batchlog`）——带部分成功的批量 INSERT：MAX_BATCH=50 限制，带索引追踪的逐项独立验证，混合 created/errors 响应（始终返回 200），数据库 CHECK 约束，通过 `is_int()` 的严格 JSON 类型验证，36 个测试 / 79 个断言全部通过。
>
> **FT 前身**：FT182（首次 batchlog 覆盖）。

当客户端在单个请求中提交一个项目数组时，有些项目可能有效，有些可能无效。任何错误都拒绝整个批次会浪费有效的项目；静默跳过错误会隐藏缺陷。_部分成功_模式接受能接受的内容，并逐项按索引报告不能接受的内容。

---

## 核心问题

JSON 数组请求体引入了两个验证层：

1. **批次级别**——整体请求结构是否有效？（键是否存在？是否是列表？数量是否在范围内？）
2. **项目级别**——每个单独的元素是否有效？（类型？范围？必填字段？）

对两层以相同方式处理会导致过度拒绝（一个坏项目导致整个批次失败）或过度接受（坏项目被静默忽略）。

---

## HTTP 惯例

| 场景 | 状态码 | 响应体 |
|---|---|---|
| 批次级别错误（键缺失、类型错误、空、超大） | `422` | `{"error": "..."}` |
| 仅项目级别错误/混合成功+错误 | `200` | `{created, errors, total_created, total_errors}` |
| 所有项目有效 | `200` | `{created: [...], errors: [], ...}` |
| 所有项目无效 | `200` | `{created: [], errors: [...], ...}` |

**为什么全部无效也返回 200？** 批量操作本身成功了——服务器处理了每个项目并对每个做出了决定。调用者通过检查 `total_created` 和 `errors` 知道发生了什么。对"某些项目无效"使用 422 会混淆两种不同类型的失败。

---

## V::bodyInt()——严格的 JSON 类型执行

`V::bodyInt()` 是捕获批量载荷中 JSON 类型混淆的关键工具。PHP 的 `json_decode` 保留 JSON 类型，但调用者可能会意外（或故意）发送错误的类型。

```php
// V::bodyInt(mixed $raw, int $min, int $max): ?int
V::bodyInt(5, 1, 999)         // → 5        ✓ PHP int
V::bodyInt("5", 1, 999)       // → null     ✗ JSON 类型混淆："5" 不是 5
V::bodyInt(5.5, 1, 999)       // → null     ✗ float
V::bodyInt(true, 1, 999)      // → null     ✗ bool
V::bodyInt(null, 1, 999)      // → null     ✗ null
V::bodyInt([5], 1, 999)       // → null     ✗ array
```

与查询字符串的关键区别：`V::queryInt()` 接受字符串 `"5"`（因为查询参数始终是字符串），而 `V::bodyInt()` 要求 PHP `int`（因为 JSON 区分 `5` 和 `"5"`）。

**ATK-07 类型混淆攻击**——发送 `{"quantity": "5"}` 而非 `{"quantity": 5}` 必须失败。`is_int()` 是唯一安全的检查方式。

---

## 批量验证逻辑

```php
// 1. 解析请求体（非对象 JSON 回退到 []）
$body = json_decode((string) $request->getBody(), true);
$body = is_array($body) ? $body : [];

// 2. 批次级别保护 → 422
if (!array_key_exists('items', $body)) {
    return 422; // 键缺失
}
$rawItems = $body['items'];
if (!is_array($rawItems)) {
    return 422; // 不是数组
}
if (count($rawItems) === 0) {
    return 422; // 空
}
if (count($rawItems) > MAX_BATCH) {
    return 422; // 超大
}

// 3. 逐项处理 → 200 含 errors[]
$created = [];
$errors  = [];

foreach ($rawItems as $index => $rawItem) {
    $intIndex = (int) $index;

    // 每个项目必须是 JSON 对象（关联数组），而非标量或列表
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

    // … 更多字段 …

    $item      = $repository->create(/* ... */);
    $created[] = $item->toArray();
}

// 4. 始终返回 200；调用者读取 total_created / total_errors
return 200 with [
    'created'       => $created,
    'errors'        => $errors,
    'total_created' => count($created),
    'total_errors'  => count($errors),
];
```

---

## array_is_list()——项目级别的 JSON 对象与 JSON 数组

PHP `json_decode` 将 JSON 对象映射为关联数组，将 JSON 数组映射为列表数组。在项目级别使用 `array_is_list()` 加以区分：

```php
// JSON 请求体：{"items": [{"name": "foo"}, "bar", 42, [1,2]]}
is_array(["name" => "foo"])   // true——有效的 JSON 对象
array_is_list(["name" => "foo"]) // false——关联数组 → 对象 ✓

is_array("bar")                  // false → 被 is_array 检查捕获
is_array(42)                     // false → 被捕获
is_array([1, 2])                 // true
array_is_list([1, 2])            // true → 拒绝：列表 ≠ 对象 ✗
```

`!is_array($rawItem) || array_is_list($rawItem)` 条件捕获标量、JSON 数组以及任何不是普通 JSON 对象的内容。

---

## MAX_BATCH 大小限制

没有上限时，调用者可能在一个请求中发送数千个项目，消耗无边界的内存和 CPU。

```php
const MAX_BATCH = 50; // 根据使用场景调整

if (count($rawItems) > self::MAX_BATCH) {
    return $this->responseFactory->create(
        ['error' => sprintf('"items" must contain at most %d entries.', self::MAX_BATCH)],
        422,
    );
}
```

在迭代之前就在批次级别拒绝（422）——不要为超大批次逐项计算错误。

---

## 错误索引保留

在每个错误中报告原始输入索引，以便客户端即使在数组索引非顺序时（例如，客户端过滤后）也能将错误与提交的项目关联起来：

```php
// 输入：[valid, invalid, valid, invalid]
// 输出 errors：[{index: 1, error: "..."}, {index: 3, error: "..."}]
```

始终明确将索引转换为 `int`——当 PHP 数组从非顺序 JSON 构建时，`foreach` 键可能是 `string`：

```php
$intIndex = (int) $index;
```

---

## 响应结构

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

## 幂等性考虑

部分成功会产生写入后出错的场景。如果客户端在网络故障后重试整个批次，之前已创建的项目可能会重复。解决方案：

- **幂等性键**：每个批次包含客户端生成的 UUID；服务器存储它并去重。
- **客户端去重**：客户端追踪哪些索引已成功并只重新提交失败的项目。
- **自然唯一性**：使用唯一约束（例如，外部 ID）并将重复键错误视为成功。

`batchlog` FT 为简洁起见使用最简单的方法（无幂等性键）。生产批量 API 应实现上述策略之一。

---

## 安全说明

- **所有数字字段使用 `V::bodyInt()`**——拒绝 JSON 请求体中的字符串、浮点数、布尔值、null。
- **字符串字段使用 `V::str()`**——拒绝非字符串，修剪，检查长度；修剪后对必填字段检查 `=== ''`。
- **用户范围**——每个项目绑定到来自请求头（`V::userId()`）的已认证用户 ID，绝不来自请求体。
- **MAX_BATCH 保护**——迭代前返回 422，防止超大批次的 DoS。

---

## 关键要点

| 模式 | 规则 |
|---|---|
| 批次级别错误 | 422——整个请求被拒绝 |
| 项目级别错误 | 200——在 `errors[]` 中报告索引+消息 |
| JSON 类型混淆 | `V::bodyInt()` / `is_int()`——而非 `is_numeric()` |
| JSON 对象与数组 | `!is_array() \|\| array_is_list()`——两者都拒绝 |
| 大小 DoS | `count($items) > MAX_BATCH` → 422，在迭代前 |
| 错误关联 | 在错误响应中保留原始 `$index` |
