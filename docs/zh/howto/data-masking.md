# 操作指南：数据脱敏

默认对 API 响应中的 PII 字段（邮箱、电话、姓名）进行脱敏处理，并提供带审计追踪的管理员明文查看路径。

## 数据库结构

```sql
CREATE TABLE customers (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT NOT NULL,
    email      TEXT NOT NULL,
    phone      TEXT NOT NULL,
    created_at TEXT NOT NULL
);

CREATE TABLE mask_audit_log (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    customer_id INTEGER NOT NULL REFERENCES customers(id),
    accessor    TEXT NOT NULL,
    accessed_at TEXT NOT NULL
);
```

## 路由

| 方法 | 路径 | 描述 |
|------|------|------|
| `POST` | `/customers` | 创建客户（响应已脱敏） |
| `GET` | `/customers/{id}` | 获取客户（默认脱敏，管理员可查明文） |
| `GET` | `/customers/{id}/audit` | 查看审计日志（仅限管理员） |

## 脱敏规则

```php
class MaskService
{
    public function maskEmail(string $email): string
    {
        $at     = strpos($email, '@');
        $local  = substr($email, 0, $at);
        $domain = substr($email, $at + 1);
        return substr($local, 0, 1) . '***@' . $domain;
    }

    public function maskPhone(string $phone): string
    {
        // 保留最后 4 位数字，其余逐字符替换为星号
        $digits  = preg_replace('/\D/', '', $phone) ?? '';
        $keepFrom = strlen($digits) - 4;
        $replaced = 0;
        $result   = '';
        for ($i = 0; $i < strlen($phone); $i++) {
            $ch = $phone[$i];
            if (ctype_digit($ch)) {
                $result .= ($replaced < $keepFrom) ? '*' : $ch;
                if (ctype_digit($ch)) { $replaced++; }
            } else {
                $result .= $ch;
            }
        }
        return $result;
    }

    public function maskName(string $name): string
    {
        return implode(' ', array_map(
            fn($w) => mb_substr($w, 0, 1) . '***',
            array_filter(explode(' ', $name))
        ));
    }
}
```

示例：
- `john@example.com` → `j***@example.com`
- `555-123-4567` → `***-***-4567`
- `John Doe` → `J*** D***`

## 基于角色的明文访问

处理器读取 `X-Role` 头。管理员访问时必须提供 `X-Accessor` 以强制执行审计追踪：

```php
$role     = $request->getHeaderLine('X-Role');
$accessor = trim($request->getHeaderLine('X-Accessor'));

if ($role === 'admin') {
    if ($accessor === '') {
        return $this->json->create(['error' => 'X-Accessor header required'], 403);
    }
    $this->repo->logAccess($id, $accessor, $this->now());
    return $this->json->create($customer);        // 原始 PII
}

return $this->json->create($this->masker->applyMask($customer));  // 已脱敏
```

## 审计日志

每次管理员查看明文都会写入 `mask_audit_log`。审计日志没有 DELETE 或 UPDATE 路由——设计上为仅追加（append-only）。

```php
public function logAccess(int $customerId, string $accessor, string $now): void
{
    $stmt = $this->pdo->prepare(
        'INSERT INTO mask_audit_log (customer_id, accessor, accessed_at) VALUES (?, ?, ?)'
    );
    $stmt->execute([$customerId, $accessor, $now]);
}
```

## 安全特性

- **默认脱敏**：所有 GET 响应均脱敏 PII，除非携带 `X-Role: admin`。
- **强制提供访问者标识**：管理员明文访问必须提供 `X-Accessor`；缺失时返回 403，杜绝匿名管理员访问。
- **不可篡改的审计记录**：没有任何路由可删除或更新审计条目。
- **参数化存储**：PII 通过预处理语句存储——SQL 注入尝试将作为字面值存储。
- **精确角色判断**：只有精确值 `admin` 才能解除脱敏；`ADMIN`、`superuser` 等均视为普通用户。
