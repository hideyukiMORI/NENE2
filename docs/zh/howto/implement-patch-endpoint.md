# 操作指南：实现 PATCH 端点

PATCH 用于**部分更新**：只有客户端发送的字段才应变更。这需要区分每个字段的三种状态：

| 状态 | 含义 |
|------|------|
| 请求体中缺少该键 | 不触碰此字段 |
| 键存在，值非 null | 更新为新值 |
| 键存在，值为 `null` | 清空字段（设为 null） |

`isset()` 无法区分"缺失"和"显式 null"——两者都返回 `false`。请改用 `array_key_exists()`。

---

## 1. 解析请求体并仅提取存在的字段

```php
$body   = JsonRequestBodyParser::parse($request);   // array<string, mixed>
$fields = [];

if (array_key_exists('title', $body)) {
    $fields['title'] = is_string($body['title']) ? trim($body['title']) : null;
}
if (array_key_exists('is_read', $body)) {
    $fields['is_read'] = (bool) $body['is_read'];
}
```

将 `$fields` 传给数据仓库的 `update()` 方法。如果 `$fields` 为空，调用仍然有效——返回资源的当前状态即可。

---

## 2. 路由注册

```php
$router->patch(
    '/entries/{id}',
    static function (ServerRequestInterface $request) use ($entries, $json): ResponseInterface {
        /** @var array<string, string> $params */
        $params = $request->getAttribute(Router::PARAMETERS_ATTRIBUTE, []);
        $id     = (int) ($params['id'] ?? 0);

        $body   = JsonRequestBodyParser::parse($request);
        $fields = [];

        if (array_key_exists('title', $body)) {
            $fields['title'] = $body['title'];
        }
        if (array_key_exists('is_read', $body)) {
            $fields['is_read'] = (bool) $body['is_read'];
        }

        $entry = $entries->update($id, $fields) ?? throw new EntryNotFoundException($id);

        return $json->create(self::payload($entry));
    },
);
```

---

## 3. 发送空 PATCH 请求体

要发送没有字段的 PATCH（不操作，返回当前状态），必须发送 JSON **对象**，而非数组。

```php
// 错误：json_encode([]) === "[]"  → 400 Bad Request（JSON 数组）
$request->withBody($stream->write(json_encode([])));

// 正确：json_encode((object)[]) === "{}"  → 200 OK（JSON 对象）
$request->withBody($stream->write(json_encode((object)[])));
```

在测试辅助方法中，传入 `new \stdClass()` 作为请求体：

```php
// 在 PHPUnit 测试中
$response = $this->request('PATCH', "/entries/{$id}", new \stdClass());
```

这是因为 `JsonRequestBodyParser` 拒绝 JSON 数组（详见 `JsonBodyParseException` 消息）。PHP 空数组 `[]` 编码为 JSON 数组 `[]`，而非 JSON 对象 `{}`。

---

## 4. 校验 PATCH 字段

只校验**存在的**字段。跳过不存在字段的校验——它们不会被修改。在数据仓库签名中使用可为 null 的参数以明确意图：

```php
$body   = JsonRequestBodyParser::parse($request);
$errors = [];

// 仅提取存在的字段（使用 array_key_exists，不用 isset）
$amount   = array_key_exists('amount', $body) ? $body['amount'] : null;
$category = array_key_exists('category', $body) ? $body['category'] : null;
$date     = array_key_exists('date', $body) ? $body['date'] : null;

// 仅校验已发送的字段
if ($amount !== null) {
    if (!is_int($amount) || $amount <= 0) {
        $errors[] = new ValidationError('amount', 'amount must be a positive integer.', 'out_of_range');
    }
}

if ($date !== null) {
    if (!is_string($date) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
        $errors[] = new ValidationError('date', 'date must be in YYYY-MM-DD format.', 'invalid_format');
    }
}

if ($errors !== []) {
    throw new ValidationException($errors);
}

// 用可为 null 的参数调用数据仓库——null 时数据仓库使用现有值
$entity = $this->repository->update(
    id:       $id,
    amount:   is_int($amount) ? $amount : null,
    category: is_string($category) && $category !== '' ? $category : null,
    date:     is_string($date) && $date !== '' ? $date : null,
    now:      (new \DateTimeImmutable())->format('Y-m-d\TH:i:s\Z'),
);
```

在数据仓库中，使用 `??` 回退到现有值：

```php
public function update(int $id, ?int $amount, ?string $category, ?string $date, string $now): Entity
{
    $existing    = $this->findById($id); // 缺失时抛出 NotFoundException
    $newAmount   = $amount   ?? $existing->amount;
    $newCategory = $category ?? $existing->category;
    $newDate     = $date     ?? $existing->date;

    $this->executor->execute(
        'UPDATE entities SET amount = ?, category = ?, date = ?, updated_at = ? WHERE id = ?',
        [$newAmount, $newCategory, $newDate, $now, $id],
    );

    return new Entity($id, $newDate, $newAmount, $newCategory, $existing->createdAt, $now);
}
```

> **为什么用 `array_key_exists` 而非 `isset`？** `isset($body['field'])` 对缺失的键和值为 `null` 的键都返回 `false`。对于 PATCH，这个区别很重要：
> "未发送"意味着"保留现有值"，而 `null` 可能意味着"清空此字段"。
> PATCH 字段检测务必使用 `array_key_exists`。

---

## 5. 数据仓库契约

数据仓库的 `update()` 应只接受传入的字段，并返回更新后的实体（未找到时返回 `null`）：

```php
/** @param array<string, mixed> $fields */
public function update(int $id, array $fields): ?Entry
{
    if ($fields === []) {
        return $this->findById($id);   // 无操作：返回当前状态
    }

    $setClauses = implode(', ', array_map(fn (string $k): string => "{$k} = ?", array_keys($fields)));
    $params     = [...array_values($fields), $id];

    $affected = $this->executor->execute(
        "UPDATE entries SET {$setClauses} WHERE id = ?",
        $params,
    );

    return $affected > 0 ? $this->findById($id) : null;
}
```

---

## 5. 相关操作指南

- [`add-pagination.md`](add-pagination.md) — 带 `PaginationQueryParser` 的 GET 端点
- [`add-domain-exception-handler.md`](add-domain-exception-handler.md) — 缺失资源的 404 处理器
