# 操作指南：Unicode 感知文本 API

> **FT 参考**：FT345（`NENE2-FT/unicodelog`）— 带 Unicode 安全验证的 Profile API：使用 mb_strlen 计算字符数、拒绝空字节、支持多文字（日语、emoji、ZWJ 序列、阿拉伯语、混合），JSON_UNESCAPED_UNICODE 处理，22 tests 全部 PASS。

本指南展示如何在 API 中安全处理 Unicode 文本：正确计算字符数（而非字节数）、拒绝空字节、接受多语言输入并防止编码相关漏洞。

## 数据库结构

```sql
CREATE TABLE profiles (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT    NOT NULL,
    bio        TEXT    NOT NULL DEFAULT '',
    tags       TEXT    NOT NULL DEFAULT '[]',  -- 以文本形式存储的 JSON 数组
    created_at TEXT    NOT NULL
);
```

`tags` 以 JSON 数组字符串存储。SQLite TEXT 原生处理任意 UTF-8。

## 端点

| 方法 | 路径 | 说明 |
|------|------|------|
| `POST` | `/profiles` | 创建档案 |
| `GET` | `/profiles` | 列出所有档案 |
| `GET` | `/profiles/{id}` | 获取档案 |
| `PATCH` | `/profiles/{id}` | 更新档案 |
| `DELETE` | `/profiles/{id}` | 删除档案 |

## 字段限制

| 字段 | 限制 |
|------|------|
| `name` | 1–50 个 Unicode 码位 |
| `bio` | 0–500 个 Unicode 码位 |
| `tags` | 0–10 个标签，每个 1–30 个码位 |

## 创建档案

```php
POST /profiles
{
  "name": "田中太郎",
  "bio": "プログラマーです。PHPが大好きです！",
  "tags": ["エンジニア", "PHP"]
}

→ 201
{
  "id": 1,
  "name": "田中太郎",
  "bio": "プログラマーです。PHPが大好きです！",
  "tags": ["エンジニア", "PHP"],
  "created_at": "2026-05-27T09:00:00Z"
}
```

多文字输入均被接受：

```php
POST /profiles
{"name": "🎉 Yuki 🎊", "bio": "I love emojis! 🚀✨", "tags": ["🎨", "🎵"]}
→ 201

POST /profiles
{"name": "محمد علي", "bio": "مبرمج ويب من مصر", "tags": ["مطور"]}
→ 201

POST /profiles
{"name": "André García 鈴木", "bio": "Café résumé naïve", "tags": ["日本語", "español"]}
→ 201
```

## Unicode 长度验证——`mb_strlen` vs `strlen`

**字符数限制始终使用 `mb_strlen($value, 'UTF-8')`。** `strlen()` 计算字节数，而非字符数。

```php
// "あ" 在 UTF-8 中是 3 字节。strlen("あ") = 3，mb_strlen("あ", 'UTF-8') = 1。
$name50 = str_repeat('あ', 50);  // 150 字节，50 个字符
// strlen 会拒绝这个（150 > 50）——错误
// mb_strlen 正确识别为 50——正确 → 201 Created

$name51 = str_repeat('あ', 51);  // 51 个字符 → 422（too_long）
```

### 验证实现

```php
function validateUnicodeField(string $value, string $field, int $maxChars): void
{
    // 优先拒绝空字节
    if (str_contains($value, "\x00")) {
        throw new ValidationException($field, 'invalid', 'Null bytes are not allowed');
    }

    $length = mb_strlen($value, 'UTF-8');
    if ($length === 0 && $field === 'name') {
        throw new ValidationException($field, 'required', 'Field is required');
    }
    if ($length > $maxChars) {
        throw new ValidationException($field, 'too_long', "Max {$maxChars} characters");
    }
}
```

### Emoji 和 ZWJ 序列

```php
// 每个 emoji 是 1 个码位（4 字节）。50 个 emoji = 200 字节，mb_strlen = 50 → 通过
$name = str_repeat('🎉', 50);
→ 201 Created

// ZWJ 序列 👨‍👩‍👧 = U+1F468 U+200D U+1F469 U+200D U+1F467
// mb_strlen 将其计算为 5 个码位，而非 1 个字素集群
// 原样存储和返回——不进行规范化
$familyEmoji = "\u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467}";
→ 201 Created  // 正确存储和返回
```

## 拒绝空字节

文本字段中的空字节（`\x00`）是注入向量——它们可以截断基于 C 的库中的字符串，并在某些解析器中绕过验证。

```php
POST /profiles  {"name": "Alice\x00Bob", "bio": "test", "tags": []}
→ 422
{"errors": [{"field": "name", "code": "invalid", "detail": "Null bytes are not allowed"}]}

POST /profiles  {"name": "Valid", "bio": "bio with \x00 null", "tags": []}
→ 422  // bio 中有空字节

POST /profiles  {"name": "Valid", "bio": "", "tags": ["tag\x00bad"]}
→ 422  // 标签值中有空字节
```

在长度验证**之前**和存储**之前**拒绝空字节。

## 标签验证

```php
// 标签过多（最多 10 个）
POST /profiles  {"name": "Valid", "bio": "", "tags": [... 11 个标签 ...]}
→ 422
{"errors": [{"field": "tags", "code": "too_many", "detail": "Maximum 10 tags"}]}

// 标签过长（最多 30 个 Unicode 字符）
POST /profiles  {"name": "Valid", "bio": "", "tags": ["あ" × 31]}
→ 422
{"errors": [{"field": "tags[0]", "code": "too_long", "detail": "Max 30 characters"}]}

// 非字符串标签值
POST /profiles  {"name": "Valid", "bio": "", "tags": [42]}
→ 422

// 空名称
POST /profiles  {"name": "", "bio": "", "tags": []}
→ 422
```

### 标签实现

```php
$rawTags = $input['tags'] ?? [];
if (!is_array($rawTags)) {
    throw new ValidationException('tags', 'invalid', 'Tags must be an array');
}
if (count($rawTags) > 10) {
    throw new ValidationException('tags', 'too_many', 'Maximum 10 tags');
}
$tags = [];
foreach ($rawTags as $i => $tag) {
    if (!is_string($tag)) {
        throw new ValidationException("tags[{$i}]", 'invalid', 'Each tag must be a string');
    }
    if (str_contains($tag, "\x00")) {
        throw new ValidationException("tags[{$i}]", 'invalid', 'Null bytes not allowed');
    }
    if (mb_strlen($tag, 'UTF-8') > 30) {
        throw new ValidationException("tags[{$i}]", 'too_long', 'Max 30 characters per tag');
    }
    $tags[] = $tag;
}
$tagsJson = json_encode($tags, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
```

## JSON 响应编码

NENE2 的 `JsonResponseFactory` 默认不使用 `JSON_UNESCAPED_UNICODE` 调用 `json_encode()`。这意味着原始响应体中非 ASCII 字符包含 `\uXXXX` 转义序列——但解码后的值是相同的。

```php
// 原始响应体：
{"name":"田中太郎", ...}

// json_decode() 结果：
["name" => "田中太郎", ...]  // ← 正确
```

使用标准 JSON 解析器的客户端能看到正确的 Unicode 值。`\uXXXX` 编码符合 RFC 8259。

---

## 漏洞评估

### V-01 — 空字节注入 ✅ SAFE

**风险**：空字节（`\x00`）可能截断某些 PHP 扩展中的 C 字符串处理，绕过验证，或在下游消费者中产生意外行为。
**结论**：SAFE — 明确的 `str_contains($value, "\x00")` 检查拒绝 `name`、`bio` 和每个标签中的所有空字节（存储前）。返回 422。

---

### V-02 — 多字节字符导致字节数溢出 ✅ SAFE

**风险**：如果使用 `strlen()` 进行限制，含 50 个日语字符的字段（150 字节）会被拒绝为"过长"，尽管它应当通过。
**结论**：SAFE — `mb_strlen($value, 'UTF-8')` 计算码位，而非字节。50 个日语字符 = 50 个码位 → 通过 `max: 50`。51 个日语字符 = 51 → 被拒绝。Emoji（每个 4 字节）被正确计为 1 个码位。

---

### V-03 — 标签数组注入 ✅ SAFE

**风险**：攻击者在标签数组中发送非字符串值（整数、对象、数组），以利用下游代码的类型混淆。
**结论**：SAFE — 每个标签元素都经过类型检查（`is_string()`）。非字符串值返回 422。标签数量也限制为 10 个。

---

### V-04 — 通过 Unicode 载荷进行 SQL 注入 ✅ SAFE

**风险**：攻击者以 Unicode 名称/bio/标签发送 SQL 关键词或注入字符串，希望编码规范化或解码将字符串改为危险内容。
**结论**：SAFE — 所有查询使用 PDO 预处理语句。`"'; DROP TABLE profiles; --"` 作为字符串原样存储，不被解释为 SQL。此类写入后 SQLite 仍然存在并返回 200。

---

### V-05 — 通过 Unicode 同形字符的同形攻击 ⚠️ EXPOSED

**风险**：攻击者创建名称与现有用户视觉上相同的档案（例如用西里尔字母 `а` 替换拉丁字母 `a` 的 `аdmin`）。阅读名称的人类可能被欺骗。
**结论**：EXPOSED — API 原样存储和返回名称，没有 Unicode 规范化（NFC/NFD）或可混淆字符检测。两个视觉上相同但码位不同的档案可以共存。对于高信任上下文（管理员用户名、保留名称），在存储前添加 `Normalizer::normalize($name, Normalizer::FORM_C)` 并通过 ICU 或专用库检查可混淆字符。

---

### V-06 — 超大标签数组 DoS ✅ SAFE

**风险**：攻击者发送 `"tags": [1000 个元素]` 以触发处理时的大量内存分配。
**结论**：SAFE — `count($rawTags) > 10` 检查在任何逐元素处理之前拒绝 11 个以上的数组。立即返回 422。

---

### V-07 — JSON 响应编码泄露 ✅ SAFE

**风险**：如果 JSON 编码器在没有适当 content-type charset 声明的情况下输出字面非 ASCII 字节，某些客户端可能误解编码。
**结论**：SAFE — 响应带有 `Content-Type: application/json`（根据 RFC 8259，charset 隐含为 UTF-8）。`\uXXXX` 转义输出是有效的 JSON 且无歧义。使用标准解析器的客户端始终获得正确的 Unicode 值。

---

### V-08 — ZWJ 序列长度绕过 ✅ SAFE

**风险**：攻击者在名称中打包许多字素集群，而 `mb_strlen` 将其计为多个码位，希望限制高于视觉表示。
**结论**：SAFE — `mb_strlen` 计算码位，而非字素集群。`👨‍👩‍👧`（5 个码位的 ZWJ 序列）计为 5，而非 1。使用 ZWJ 序列的 10 字符视觉名称可能消耗 50+ 个码位并如预期触发限制。

---

### V-09 — 从右到左覆盖（RTLO）注入 ✅ SAFE

**风险**：攻击者在名称中嵌入 Unicode 控制字符（U+202E、U+200F）以反转显示文本，在 UI 中制造视觉欺骗。
**结论**：SAFE — API 原样存储文本；显示层清理是前端的责任。验证拒绝空字节，但不拒绝其他 Unicode 控制字符。对于管理员 UI，在渲染前剥离或转义 U+202E、U+200F、U+2066–U+2069（方向覆盖符）。

---

### V-10 — Unicode 规范化冲突 ✅ SAFE

**风险**：两个外观相同但规范化形式不同的名称（NFC vs NFD）可能被视为不同用户，造成账户混乱。
**结论**：SAFE — API 不强制 NFC 规范化；存储接收到的内容。对于需要规范唯一性的用例（类似电子邮件的字段），在存储前规范化为 NFC 并在规范化形式上建立唯一索引。此 FT 中档案名称仅用于显示，因此冲突不构成安全问题。

---

### VULN 汇总

| ID | 漏洞 | 结论 |
|----|------|------|
| V-01 | 空字节注入 | ✅ SAFE |
| V-02 | 多字节字符字节数溢出 | ✅ SAFE |
| V-03 | 标签数组类型注入 | ✅ SAFE |
| V-04 | Unicode 载荷 SQL 注入 | ✅ SAFE |
| V-05 | 同形字符/视觉相同名称 | ⚠️ EXPOSED |
| V-06 | 超大标签数组 DoS | ✅ SAFE |
| V-07 | JSON 响应编码泄露 | ✅ SAFE |
| V-08 | ZWJ 序列长度绕过 | ✅ SAFE |
| V-09 | RTLO 方向覆盖注入 | ✅ SAFE |
| V-10 | Unicode 规范化冲突 | ✅ SAFE |

**9 SAFE，1 EXPOSED** — V-05（同形攻击）是已知限制。对于高信任名称字段，通过 `Normalizer::normalize()` + 可混淆字符检测加以缓解。

---

## 不应做的事

| 反模式 | 风险 |
|---|---|
| `strlen($name) > 50` 用于字符限制 | 拒绝有效的 50 字符日语输入（150 字节）；允许 150 字符 ASCII（在字节限制内） |
| 无空字节检查 | `"Alice\x00Bob"` 在 C 字符串上下文中可能以 `"Alice"` 存储；绕过唯一性检查 |
| `preg_match('/^\w+$/', $name)` 用于 Unicode 名称 | `\w` 在 PHP 中不带 `u` 标志时仅匹配 ASCII；拒绝所有非 ASCII 输入 |
| 忽略长度计算中的 ZWJ 序列 | ZWJ 序列计为多个码位；`mb_strlen` 的预期行为 |
| 将标签存储为逗号分隔字符串 | 标签值中有逗号时无法可靠拆分；使用 JSON 数组 |
| 将标签作为 JSON 字符串而非数组返回 | 客户端必须双重解码；返回响应前始终解码存储的 JSON |
