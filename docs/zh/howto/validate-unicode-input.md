# 如何验证 Unicode 输入

NENE2 以 UTF-8 存储和返回字符串。本指南涵盖 Unicode 感知验证的陷阱及其处理方法。

## 使用 `mb_strlen` 进行字符数限制

`strlen` 计算字节数，而非字符数。日语、阿拉伯语和 emoji 每个字符使用多个字节。

```php
strlen('あ')              // 3（字节）
mb_strlen('あ', 'UTF-8') // 1（字符）

strlen('🎉')              // 4（字节）
mb_strlen('🎉', 'UTF-8') // 1（字符——一个码位）
```

在强制字符限制时始终使用 `mb_strlen($value, 'UTF-8')`：

```php
private const int NAME_MAX_CHARS = 50;

if (mb_strlen($name, 'UTF-8') > self::NAME_MAX_CHARS) {
    $errors[] = ['field' => 'name', 'code' => 'too_long',
                 'message' => 'name must be at most ' . self::NAME_MAX_CHARS . ' characters.'];
}
```

**为什么 `strlen` 会出错：** 一个 50 字符的日文名字是 150 字节。`strlen(...) > 50` 会拒绝它。

## 显式拒绝空字节

SQLite TEXT 列接受空字节（`\x00`）。PHP 字符串操作也能处理它们——但用户输入中的空字节几乎总是注入尝试或编码错误。尽早拒绝它们：

```php
if (str_contains($name, "\x00")) {
    $errors[] = ['field' => 'name', 'code' => 'invalid', 'message' => 'name must not contain null bytes.'];
}
```

在其他验证（长度、格式等）之前对每个字符串字段应用此检查。

## 字形簇 vs 码位

`mb_strlen` 计算 Unicode _码位_。一个可见字形（字形簇）可以由多个码位组成：

| 输入 | 码位数 | `mb_strlen` | 字形数 |
|---|---|---|---|
| `é`（预组合） | 1 | 1 | 1 |
| `é`（e + 组合重音） | 2 | 2 | 1 |
| 👨‍👩‍👧（ZWJ 家庭） | 5 | 5 | 1 |

对于大多数用例（用户名、个人简介），码位计数已足够。如果需要计算可见字符数，使用 `intl` 扩展的 `grapheme_strlen()`：

```php
grapheme_strlen('👨‍👩‍👧') // 1
mb_strlen('👨‍👩‍👧', 'UTF-8') // 5
```

根据字段的用户预期选择合适的计数方法。

## JSON 响应中的非 ASCII 字符

`JsonResponseFactory` 使用 `JSON_UNESCAPED_UNICODE` 编码响应，因此非 ASCII 字符在响应体中以字面 UTF-8 出现：

```json
{ "name": "田中太郎" }
```

如果你在其他地方构建自定义 `json_encode` 调用（例如，将标签以 JSON 存储在 TEXT 列中），添加相同的标志：

```php
$tagsJson = json_encode($tags, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
```

不加 `JSON_UNESCAPED_UNICODE`，存储值将是 `["タグ"]` 而非 `["タグ"]`。

## 完整验证示例

```php
private const int NAME_MAX_CHARS = 50;

private function validateName(string $raw): ?string
{
    if ($raw === '') {
        return 'name is required.';
    }
    if (str_contains($raw, "\x00")) {
        return 'name must not contain null bytes.';
    }
    if (mb_strlen($raw, 'UTF-8') > self::NAME_MAX_CHARS) {
        return 'name must be at most ' . self::NAME_MAX_CHARS . ' characters.';
    }
    return null; // 有效
}
```

## 测试边界值

始终为以下情况编写测试：

- 恰好 `MAX` 个字符（应通过）——使用 Unicode 字符验证字节/字符差异：

  ```php
  $name50 = str_repeat('あ', 50); // 150 字节，50 个字符——应通过
  ```

- `MAX + 1` 个字符（应失败）：

  ```php
  $name51 = str_repeat('あ', 51); // 应返回 422，错误码 too_long
  ```

- 空字节拒绝：

  ```php
  "Valid\x00Name" // 应返回 422，错误码 invalid
  ```
