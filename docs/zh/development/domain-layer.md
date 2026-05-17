# 域层策略

NENE2 将框架基础设施（HTTP 运行时、DI、配置、数据库适配器）与应用逻辑（用例、仓库、域规则）分离。本文档定义了位于 HTTP 处理器和数据库适配器之间的应用层的约定。

## 定位

域层是表达应用做什么的一组用例和仓库接口，独立于请求到达方式或数据存储方式。

```text
HTTP 处理器（薄）
  → UseCase（应用逻辑、业务不变量）
    → RepositoryInterface（数据访问契约）
      → PdoRepositoryAdapter（持久化细节）
```

框架基础设施位于 `src/`。应用特定的用例和仓库接口应位于客户端项目控制的命名空间中。NENE2 提供约定和最小工作示例；不强制对应用代码使用命名空间。

## UseCase 约定

用例表达一个应用操作。它接收 readonly 输入 DTO，执行业务不变量检查，并返回类型化输出。

### 接口形式

```php
interface CreateItemUseCaseInterface
{
    public function execute(CreateItemInput $input): CreateItemOutput;
}
```

规则：

- 每个用例接口一个方法，始终命名为 `execute`。
- 输入和输出是类型化的 readonly DTO，而非原始数组或 PSR-7 对象。
- 接口与适配器相邻或在其上方，不在框架目录内。
- 用例可以为调用者必须处理的不变量违反抛出域特定异常。
- 用例不了解 HTTP、会话、模板或队列。
- 用例不直接调用 PSR-11 容器。

### 输入 DTO

```php
final readonly class CreateItemInput
{
    public function __construct(
        public string $name,
        public int    $year,
    ) {
    }
}
```

- 默认为 `readonly` 和 `final`。
- 构造函数接收已验证的值；格式验证在调用用例之前在处理器中进行。
- 业务不变量（唯一性、状态规则）在用例内检查，而非在此处。

### 输出 DTO

```php
final readonly class CreateItemOutput
{
    public function __construct(
        public int    $id,
        public string $name,
        public int    $year,
    ) {
    }
}
```

- 仅携带调用者需要的内容。
- 即使对于有副作用的操作也返回类型化输出。

### 实现

```php
final class CreateItemUseCase implements CreateItemUseCaseInterface
{
    public function __construct(
        private readonly ItemRepositoryInterface $items,
    ) {
    }

    public function execute(CreateItemInput $input): CreateItemOutput
    {
        if ($this->items->existsByName($input->name)) {
            throw new ItemAlreadyExistsException($input->name);
        }

        $id = $this->items->save(new Item(name: $input->name, year: $input->year));

        return new CreateItemOutput(id: $id, name: $input->name, year: $input->year);
    }
}
```

- 仅使用构造函数注入。
- 不对需要测试的依赖使用 `new`。
- 数据库事务属于适配器或事务管理器服务。

## 仓库接口约定

仓库接口描述一个聚合或域概念的数据访问契约。适配器实现它。

### 接口形式

```php
interface ItemRepositoryInterface
{
    public function findById(int $id): ?Item;
    public function existsByName(string $name): bool;
    public function save(Item $item): int;
}
```

规则：

- 方法使用域术语，而非 SQL 动词。`findById`，而非 `selectById`。
- 返回类型使用域对象或基本类型，而非 PDO 结果行或原始数组。
- 当缺失是有效情况时，使用可空返回（`?Item`）而非抛出异常。
- 接口位于应用命名空间，而非 `src/Database/`。

### 域对象

```php
final readonly class Item
{
    public function __construct(
        public string $name,
        public int    $year,
        public ?int   $id = null,
    ) {
    }
}
```

- `id` 在持久化之前为 nullable。
- 保持域对象不含 ORM 注解或数据库耦合。

### PDO 适配器

```php
final class PdoItemRepository implements ItemRepositoryInterface
{
    public function __construct(
        private readonly DatabaseQueryExecutorInterface $query,
    ) {
    }

    public function findById(int $id): ?Item
    {
        $row = $this->query->fetchOne('SELECT id, name, year FROM items WHERE id = ?', [$id]);

        return $row !== null
            ? new Item(name: $row['name'], year: (int) $row['year'], id: (int) $row['id'])
            : null;
    }

    public function existsByName(string $name): bool
    {
        return $this->query->fetchOne('SELECT 1 FROM items WHERE name = ?', [$name]) !== null;
    }

    public function save(Item $item): int
    {
        return $this->query->insert('INSERT INTO items (name, year) VALUES (?, ?)', [$item->name, $item->year]);
    }
}
```

- 使用 `src/Database/` 中的 `DatabaseQueryExecutorInterface`，而非原始 PDO。
- 所有 SQL 保留在适配器内。用例和域对象没有 SQL。
- 输出时将数据库行值转换为类型化 PHP 值。
- 适配器类名前缀：`Pdo`（例如 `PdoItemRepository`）。

## 处理器（控制器）边界

处理器保持薄。其工作是将 HTTP 请求映射为用例输入，调用用例，并返回响应。

```php
final class CreateItemHandler
{
    public function __construct(
        private readonly CreateItemUseCaseInterface $useCase,
        private readonly JsonResponseFactory        $response,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array) json_decode((string) $request->getBody(), associative: true);

        $input = new CreateItemInput(
            name: (string) ($body['name'] ?? ''),
            year: (int) ($body['year'] ?? 0),
        );

        $output = $this->useCase->execute($input);

        return $this->response->ok(['id' => $output->id, 'name' => $output->name, 'year' => $output->year]);
    }
}
```

规则：

- 处理器不包含业务逻辑。
- 格式验证和 DTO 构建在此处进行。业务不变量保留在用例中。
- 处理器不直接调用仓库。
- 处理器通过构造函数注入接收用例，类型为接口。

## 代码布局

```
src/
  Item/
    CreateItemInput.php
    CreateItemOutput.php
    CreateItemUseCaseInterface.php
    CreateItemUseCase.php
    Item.php
    ItemRepositoryInterface.php
    ItemAlreadyExistsException.php
    PdoItemRepository.php
    CreateItemHandler.php
```

按域概念分组，而非按层类型分组。

## PSR-11 容器连线

```php
final class ItemServiceProvider implements ServiceProviderInterface
{
    public function register(ContainerBuilder $builder): void
    {
        $builder->bind(ItemRepositoryInterface::class, static function (ContainerInterface $c): ItemRepositoryInterface {
            return new PdoItemRepository($c->get(DatabaseQueryExecutorInterface::class));
        });

        $builder->bind(CreateItemUseCaseInterface::class, static function (ContainerInterface $c): CreateItemUseCaseInterface {
            return new CreateItemUseCase($c->get(ItemRepositoryInterface::class));
        });

        $builder->bind(CreateItemHandler::class, static function (ContainerInterface $c): CreateItemHandler {
            return new CreateItemHandler(
                $c->get(CreateItemUseCaseInterface::class),
                $c->get(JsonResponseFactory::class),
            );
        });
    }
}
```

## 测试

### 用例单元测试

```php
final class CreateItemUseCaseTest extends TestCase
{
    public function test_throws_when_item_name_already_exists(): void
    {
        $items = new InMemoryItemRepository([new Item(name: 'duplicate', year: 2026, id: 1)]);
        $useCase = new CreateItemUseCase($items);

        $this->expectException(ItemAlreadyExistsException::class);

        $useCase->execute(new CreateItemInput(name: 'duplicate', year: 2026));
    }
}
```

### 仓库适配器集成测试

```bash
docker compose run --rm app composer test:database
docker compose up -d mysql
docker compose run --rm app composer test:database:mysql
```

## 错误处理

- 为业务不变量违反抛出命名域异常。
- 在 HTTP 错误边界将域异常映射为 Problem Details，而非在用例内。
- 不在错误响应中暴露 SQL 错误、堆栈跟踪或内部标识符。

## 非目标

- Active record 或 Eloquent 风格的模型。
- 从 OpenAPI 或数据库模式自动生成代码。
- 首次实现时的 CQRS、事件溯源或 saga 模式。
- 通过反射或注解进行依赖注入。
- 在用例或域对象内使用服务定位器调用。

## 相关文档

- 编码标准：`docs/development/coding-standards.md`
- 测试数据库策略：`docs/development/test-database-strategy.md`
- 端点脚手架工作流：`docs/development/endpoint-scaffold.md`
- 客户端项目启动指南：`docs/development/client-project-start.md`
