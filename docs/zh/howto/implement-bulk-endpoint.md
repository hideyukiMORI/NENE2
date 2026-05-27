# 操作指南：实现批量创建端点

批量端点在单个请求中接受多个资源——减少批量导入、分数提交等工作流的往返次数。本指南涵盖完整模式：解析、带索引错误字段的逐条校验、大小限制以及路由注册。

---

## 1. 数据结构

请求体将条目包装在命名数组键中，使得信封可携带元数据：

```json
{
  "scores": [
    { "player": "Alice", "game": "tetris", "score": 1000, "played_at": "2026-01-15" },
    { "player": "Bob",   "game": "tetris", "score": 2000, "played_at": "2026-01-16" }
  ]
}
```

响应返回创建数量和已创建的条目：

```json
{ "created": 2, "scores": [ /* ... */ ] }
```

---

## 2. 路由

在带参数的单资源路由**之前**注册批量路由，以避免遮蔽（参见 [add-custom-route.md](add-custom-route.md)）：

```php
$router->post('/scores/bulk', $this->bulkSubmit(...)); // 静态路由优先
$router->post('/scores/{id}', $this->show(...));        // 带参数路由在后
```

---

## 3. 处理器

```php
private function bulkSubmit(ServerRequestInterface $request): ResponseInterface
{
    $body = JsonRequestBodyParser::parse($request);

    // 1. 校验信封
    if (!isset($body['scores']) || !is_array($body['scores'])) {
        throw new ValidationException([
            new ValidationError('scores', 'scores must be a non-empty array.', 'required'),
        ]);
    }

    /** @var array<mixed> $entriesRaw */
    $entriesRaw = $body['scores'];

    if (count($entriesRaw) === 0) {
        throw new ValidationException([
            new ValidationError('scores', 'scores must contain at least one entry.', 'required'),
        ]);
    }

    // 2. 在迭代前强制执行大小限制
    if (count($entriesRaw) > 100) {
        throw new ValidationException([
            new ValidationError('scores', 'scores may contain at most 100 entries per request.', 'out_of_range'),
        ]);
    }

    // 3. 校验每个条目，用索引作为字段名前缀
    $allErrors = [];
    $entries   = [];

    foreach ($entriesRaw as $i => $entry) {
        if (!is_array($entry)) {
            $allErrors[] = new ValidationError("scores[{$i}]", 'Each entry must be an object.', 'invalid_type');
            continue;
        }

        /** @var array<string, mixed> $entry */
        $entryErrors = $this->validateEntry($entry, "scores[{$i}].");
        if ($entryErrors !== []) {
            $allErrors = [...$allErrors, ...$entryErrors];
        } else {
            $entries[] = $entry;
        }
    }

    // 4. 如果任何条目无效则整个请求失败
    if ($allErrors !== []) {
        throw new ValidationException($allErrors);
    }

    // 5. 持久化所有条目并返回
    $now     = (new \DateTimeImmutable())->format('Y-m-d\TH:i:s\Z');
    $created = $this->repository->bulkCreate($entries, $now);

    return $this->json->create([
        'created' => count($created),
        'scores'  => array_map(fn ($s) => $this->serialize($s), $created),
    ], 201);
}
```

---

## 4. 带索引字段名的逐条校验

使用一个接受 `string $prefix` 参数的私有辅助方法。前缀为 `"scores[{$i}]."`：

```php
/**
 * @param array<string, mixed> $body
 * @return list<ValidationError>
 */
private function validateEntry(array $body, string $prefix = ''): array
{
    $errors = [];

    if (!isset($body['player']) || !is_string($body['player']) || $body['player'] === '') {
        $errors[] = new ValidationError($prefix . 'player', 'player is required.', 'required');
    }

    if (!isset($body['score']) || !is_int($body['score'])) {
        $errors[] = new ValidationError($prefix . 'score', 'score is required (integer).', 'required');
    } elseif ($body['score'] < 0) {
        $errors[] = new ValidationError($prefix . 'score', 'score must be 0 or greater.', 'out_of_range');
    }

    return $errors;
}
```

**为什么使用 `$prefix`？** `ValidationError` 接受任意字符串作为字段名。传入 `"scores[0]."` 作为前缀会产生 `"scores[0].player"` 等错误字段——让人一目了然地知道是哪个条目的哪个字段失败了。只需一个前缀参数，无需任何框架改动。

产生的 422 响应体：

```json
{
  "type": "https://nene2.dev/problems/validation-failed",
  "errors": [
    { "field": "scores[1].player", "message": "player is required.", "code": "required" }
  ]
}
```

---

## 5. 数据仓库契约

接受预校验的条目列表并返回已创建的实体：

```php
/**
 * @param list<array{player: string, game: string, score: int, played_at: string}> $entries
 * @return list<Score>
 */
public function bulkCreate(array $entries, string $now): array
{
    $results = [];
    foreach ($entries as $entry) {
        $results[] = $this->create($entry['player'], $entry['game'], $entry['score'], $entry['played_at'], $now);
    }
    return $results;
}
```

> **原子性**：上述循环逐行插入。如需全有或全无的行为，请用 `DatabaseTransactionManagerInterface::transactional()` 包裹——参见 [use-transactions.md](use-transactions.md)。

---

## 6. 相关操作指南

- [`add-pagination.md`](add-pagination.md) — 列表端点模式
- [`use-transactions.md`](use-transactions.md) — 在事务中包裹批量插入
- [`add-domain-exception-handler.md`](add-domain-exception-handler.md) — 领域特定的 404/409
