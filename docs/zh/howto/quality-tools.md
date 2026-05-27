# 质量工具

本指南介绍 PHPStan、PHP-CS-Fixer，以及在基于 NENE2 的项目中运行它们时的常见问题。

---

## PHPStan

NENE2 目标是 PHPStan level 8。通过 Composer 安装 `hideyukimori/nene2` 的消费者项目可以在 `phpstan.neon` 文件中为自己的源码配置 PHPStan。

### 最小配置

```neon
# phpstan.neon
parameters:
    level: 8
    paths:
        - src
        - tests
```

### 内存限制

在分析一个导入了大量 vendor 包的项目时（nene2 + psr/* + nyholm/psr7 + phpunit stubs 加起来很多），PHPStan 可能耗尽内存。PHP 默认内存限制为 128 MB，通常不够用。

**在 PHPStan 2.x 中，`memory_limit` 是仅限 CLI 的选项——无法在 `phpstan.neon` 中设置。**

改为在命令行传递：

```bash
phpstan analyse --level=8 --memory-limit=512M src tests
```

或在 `composer.json` 的 `scripts` 部分设置，以便统一应用：

```json
{
    "scripts": {
        "analyse": "phpstan analyse --level=8 --memory-limit=512M src tests",
        "check": ["@test", "@analyse", "@cs"]
    }
}
```

> **注意**：在 `phpstan.neon` 的 `parameters:` 下添加 `memory_limit: 512M` 会在 PHPStan 2.x 中导致 `Invalid configuration: Unexpected item 'parameters › memory_limit'.`。请改用 CLI 标志。

### 抑制误报

如果 PHPStan 产生无法修复的误报（例如 vendor 类中的 PHPDoc 不匹配），使用内联忽略：

```php
/** @phpstan-ignore-next-line */
$value = $externalLib->getSomething();
```

或添加基线文件：

```bash
phpstan analyse --generate-baseline
```

---

## PHP-CS-Fixer

NENE2 使用 `@PSR12` 作为基础规则集，加上 `strict_param` 和 `declare_strict_types`。

### 最小配置

在项目根目录创建 `.php-cs-fixer.php`：

```php
<?php

$finder = PhpCsFixer\Finder::create()->in([__DIR__ . '/src', __DIR__ . '/tests']);

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PSR12'               => true,
        'strict_param'         => true,
        'declare_strict_types' => true,
    ])
    ->setFinder($finder);
```

### 常见格式问题

**构造函数主体展开**：`@PSR12` 要求构造函数主体即使为空也要跨多行。CS-Fixer 会自动展开：

```php
// 修复前（手写）
public function __construct(private Foo $foo) {}

// CS-Fixer 修复后
public function __construct(private Foo $foo)
{
}
```

编写新类后运行 `composer cs:fix` 以避免手动修正。

**`declare(strict_types=1)` 放置**：`declare_strict_types` 规则会自动添加此声明。如果手动编写，CS-Fixer 会规范其周围的空白。

### 干运行与修复

```bash
composer cs        # 干运行，显示将要更改的内容
composer cs:fix    # 应用修复
```

---

## 组合检查

添加一个按顺序运行所有工具的 `check` composer 脚本：

```json
{
    "scripts": {
        "test":     "phpunit",
        "analyse":  "phpstan analyse --level=8 --memory-limit=512M src tests",
        "cs":       "php-cs-fixer fix --dry-run --diff",
        "cs:fix":   "php-cs-fixer fix",
        "check":    ["@test", "@analyse", "@cs"]
    }
}
```

在每次提交前运行 `composer check` 以尽早发现问题。CI 应运行相同的命令。
