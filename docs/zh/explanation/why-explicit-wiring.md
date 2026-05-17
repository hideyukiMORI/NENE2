# 为什么使用显式依赖注入？

NENE2 使用显式手写的依赖注入，而非自动装配或基于约定的容器魔法。本页解释原因。

## 显式注入的含义

```php
// RuntimeServiceProvider.php — 每个依赖都明确写出
$container->bind(NoteRepositoryInterface::class, function (ContainerInterface $c) {
    return new PdoNoteRepository($c->get(DatabaseQueryExecutorInterface::class));
});
```

## 选择显式注入的理由

### 1. 注入关系总是可以找到

使用显式注入时，在服务提供者文件中运行 `grep -r 'NoteRepository'` 即可得到完整答案。

### 2. 错误在启动时失败，而非运行时

引用缺失类的显式绑定在容器构建时就会失败。自动装配的错误可能只在生产环境中特定代码路径被执行时才会出现。

### 3. AI 代理和静态分析可以追踪依赖图

显式注入产生的依赖图可以被 grep、PHPStan 和 LLM 代理在不运行容器的情况下遍历。

### 4. 无注解或属性耦合

NENE2 领域类不携带任何容器注解，避免了与容器库的耦合。

## 权衡

| 显式注入 | 自动装配 |
|---------|---------|
| 始终可读 | 样板代码更少 |
| 启动时快速失败 | 快速脚手架很方便 |
| 无魔法 | 需要学习容器规则 |
| 类多时冗长 | 自动扩展 |
