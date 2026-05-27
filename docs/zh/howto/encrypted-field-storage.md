# 操作指南：加密字段存储

> **FT 参考**：FT267（`NENE2-FT/encryptlog`）——AES-256-GCM 字段级加密：写入时加密/读取时解密，可搜索密文的盲索引，加密密钥与索引密钥分离
>
> **VULN 评估**：本文末尾包含 V-01 至 V-10。
>
> **模式同样在 FT187 encryptlog 中得到验证**——AES-256-GCM 每字段加密，配合 HMAC-SHA256 盲索引实现可搜索 PII 存储。

---

## 涵盖内容

将敏感字段（姓名、邮箱、SSN、信用卡）在静态存储时加密，同时保持可搜索：

1. **AES-256-GCM**——认证加密；每条记录使用独立的 nonce
2. **盲索引**——字段值的 HMAC-SHA256 可在不解密的情况下实现 `WHERE email_idx = ?` 查询
3. **AEAD 篡改检测**——标签不匹配抛出 `\RuntimeException`，而非返回 400
4. **密文不出现在 API 响应中**——VO / toArray() 层始终返回明文
5. **IDOR 防护**——所有读写操作都限定 `WHERE id AND user_id`

---

## 密文格式

```
base64( nonce ‖ ciphertext ‖ tag )
```

| 组件 | 大小 | 用途 |
|------|------|------|
| `nonce` | 12 字节 | 每次加密随机生成的 IV（GCM 标准） |
| `ciphertext` | 可变 | AES-256-GCM 加密的明文 |
| `tag` | 16 字节 | 认证标签——检测篡改 |

以单个 `TEXT` 列存储。相同明文 → 每次生成不同密文（不同 nonce）。

---

## 数据库结构

```sql
CREATE TABLE vault_records (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    name_enc   TEXT    NOT NULL,   -- base64(nonce || ciphertext || tag)
    email_enc  TEXT    NOT NULL,
    email_idx  TEXT    NOT NULL,   -- HMAC-SHA256 盲索引，用于搜索
    notes_enc  TEXT,               -- 可空的加密字段
    created_at TEXT    NOT NULL,
    updated_at TEXT    NOT NULL
);
CREATE INDEX idx_vault_email ON vault_records(email_idx);
```

`email_idx` 有索引——`WHERE email_idx = ?` 查询速度快。`email_enc` 密文从不用于搜索。

---

## FieldCrypto 辅助类

```php
final readonly class FieldCrypto
{
    private const string ALGO      = 'aes-256-gcm';
    private const int    TAG_LEN   = 16;
    private const int    NONCE_LEN = 12;

    public function __construct(
        private string $encKey,   // 必须是 32 字节
        private string $indexKey, // 必须是 32 字节
    ) {
        if (strlen($this->encKey) !== 32) {
            throw new \InvalidArgumentException('encKey must be exactly 32 bytes.');
        }
    }

    public function encrypt(string $plaintext): string
    {
        $nonce = random_bytes(self::NONCE_LEN); // 每个值使用全新 IV
        $tag   = '';
        $ct    = openssl_encrypt(
            $plaintext, self::ALGO, $this->encKey,
            OPENSSL_RAW_DATA, $nonce, $tag, '', self::TAG_LEN,
        );

        return base64_encode($nonce . $ct . $tag);
    }

    public function decrypt(string $encoded): string
    {
        $raw  = base64_decode($encoded, strict: true);
        $nonce = substr($raw, 0, self::NONCE_LEN);
        $tag   = substr($raw, -self::TAG_LEN);
        $ct    = substr($raw, self::NONCE_LEN, strlen($raw) - self::NONCE_LEN - self::TAG_LEN);

        $pt = openssl_decrypt($ct, self::ALGO, $this->encKey, OPENSSL_RAW_DATA, $nonce, $tag);

        if ($pt === false) {
            throw new \RuntimeException('Decryption failed — tag mismatch or corrupt ciphertext.');
        }

        return $pt;
    }

    /**
     * 确定性——相同输入始终产生相同输出。
     * 允许 WHERE email_idx = ? 查询而无需解密存储的密文。
     */
    public function blindIndex(string $plaintext): string
    {
        return hash_hmac('sha256', $plaintext, $this->indexKey);
    }
}
```

---

## 核心模式：写入时加密，读取时解密

```php
// 创建——INSERT 前加密所有敏感字段
public function create(int $userId, string $name, string $email, ?string $notes): VaultRecord
{
    $stmt->execute([
        'name_enc'  => $this->crypto->encrypt($name),
        'email_enc' => $this->crypto->encrypt($email),
        'email_idx' => $this->crypto->blindIndex($email), // 确定性，用于搜索
        'notes_enc' => $notes !== null ? $this->crypto->encrypt($notes) : null,
        // ...
    ]);
}

// 读取——在 hydration 中透明解密
private function hydrateRow(array $row): VaultRecord
{
    return new VaultRecord(
        name:  $this->crypto->decrypt((string) $row['name_enc']),
        email: $this->crypto->decrypt((string) $row['email_enc']),
        notes: $row['notes_enc'] !== null
            ? $this->crypto->decrypt((string) $row['notes_enc'])
            : null,
        // ...
    );
}
```

---

## 核心模式：盲索引搜索

```php
// 搜索——从查询参数计算盲索引，搜索期间绝不解密行
public function findByEmail(int $userId, string $email): array
{
    $idx  = $this->crypto->blindIndex($email); // 相同密钥 → 相同索引
    $stmt = $this->pdo->prepare(
        'SELECT * FROM vault_records WHERE user_id = :user_id AND email_idx = :idx',
    );
    $stmt->execute(['user_id' => $userId, 'idx' => $idx]);
    // 行随后在 hydrateRow() 中解密
}
```

**更新邮箱时，必须同步更新索引：**

```php
$stmt->execute([
    'email_enc' => $this->crypto->encrypt($newEmail),
    'email_idx' => $this->crypto->blindIndex($newEmail), // ← 必须同时更新
]);
```

---

## 核心模式：密文不出现在响应中

```php
// VaultRecord::toArray()——只返回解密后的明文
public function toArray(): array
{
    return [
        'id'         => $this->id,
        'name'       => $this->name,  // 明文
        'email'      => $this->email, // 明文
        'notes'      => $this->notes, // 明文或 null
        'created_at' => $this->createdAt,
        'updated_at' => $this->updatedAt,
        // name_enc、email_enc、email_idx、notes_enc——从不暴露
    ];
}
```

读取 API 响应的攻击者无法恢复密文以执行离线攻击。

---

## 核心模式：篡改检测返回 500

```php
$pt = openssl_decrypt($ct, self::ALGO, $this->encKey, OPENSSL_RAW_DATA, $nonce, $tag);

if ($pt === false) {
    // 标签不匹配 = DB 行被篡改 OR 密钥错误
    // 抛出异常——让全局错误处理器返回 500
    // 不要返回 400——400 是客户端错误；这是内部完整性故障
    throw new \RuntimeException('Decryption failed.');
}
```

返回 400 会暗示客户端发送了错误数据。500 正确地表示"服务端完整性问题"，且不泄露哪个字段失败或原因。

---

## 密钥管理指南

```php
// 生产环境：从 KMS 或密钥管理器派生密钥
$encKey   = random_bytes(32); // 32 字节 = AES-256
$indexKey = random_bytes(32); // 独立密钥——不同的 HMAC 域

// 绝不在源码中硬编码密钥；使用环境变量或密钥派生：
$encKey   = hex2bin(getenv('VAULT_ENC_KEY'));   // 64 位十六进制 → 32 字节
$indexKey = hex2bin(getenv('VAULT_INDEX_KEY')); // 64 位十六进制 → 32 字节
```

**两个独立密钥：**
- `encKey`——AES-256-GCM。可轮换：用新密钥重新加密行，更新版本前缀。
- `indexKey`——HMAC-SHA256。轮换时需要重新哈希所有索引，无法单独轮换。

---

## 测试结果（FT187）

```
51 个测试 / 110 个断言——全部通过
PHPStan level 8——无错误
PHP CS Fixer——干净
```

| 测试领域 | 覆盖范围 |
|---------|---------|
| FieldCrypto 单元 | 加密/解密往返，nonce 唯一性，盲索引确定性，篡改检测，短密钥拒绝 |
| 正常路径 | 创建/获取/列表/更新/删除/搜索 |
| 密文隔离 | `name_enc`、`email_enc`、`email_idx`、`notes_enc` 不出现在响应中 |
| IDOR 防护 | 跨用户的获取/更新/删除全部返回 404 |
| 批量赋值 | 请求体中的 `name_enc`、`email_idx`、`user_id` 被忽略 |
| 校验 | 姓名、邮箱、备注、limit 的缺失/过长/类型错误 |
| 盲索引重建 | 邮箱更新时保持索引同步 |

---

## VULN 评估（FT267）

对 `NENE2-FT/encryptlog` 在字段加密威胁模型下的安全评估。

### V-01 — 密钥管理：环境变量加载 ✅ SAFE

**威胁**：加密密钥提交到版本控制系统或硬编码在源码中。
**缓解**：密钥通过 `ConfigLoader` 中的 `getenv()` 加载，启动时验证长度。`.env` 文件已添加到 git 忽略列表。源码中不存在密钥材料。
**残余风险**：密钥轮换（替换两个密钥、重新加密所有行）未实现。本 FT 范围内可接受；生产系统需要轮换计划。

---

### V-02 — Nonce 重用（GCM） ✅ SAFE

**威胁**：如果相同 nonce 在同一密钥下被使用两次，GCM 会失去所有机密性和真实性保证。
**缓解**：每次 `encrypt()` 调用内都调用 `random_bytes(12)`。96 位 nonce 空间和 `random_bytes()` 使碰撞概率在任何实际使用量下都可忽略不计（每密钥生命周期少于 2^32 次加密是安全上限）。
**结论**：安全。

---

### V-03 — 认证标签验证 ✅ SAFE

**威胁**：密文篡改未被检测；攻击者翻转位来操纵解密后的明文。
**缓解**：`openssl_decrypt()` 在返回明文前验证 16 字节 GCM 认证标签。任何单比特修改都返回 `false`，`FieldCrypto::decrypt()` 将其转换为抛出 `\RuntimeException`。应用捕获并返回 `500`；不暴露任何部分明文。
**结论**：安全。

---

### V-04 — API 响应泄露解密错误详情 ⚠️ EXPOSED

**威胁**：错误处理器将 `\RuntimeException::getMessage()`（"Decryption failed — tag mismatch or corrupt ciphertext."）序列化到 API 响应中，向攻击者泄露完整性信号。
**发现**：在 `APP_DEBUG=true` 模式下，完整消息和堆栈跟踪可能暴露。在 `APP_DEBUG=false` 模式下，默认处理器仍可能暴露异常类名。
**建议**：添加专门的 `DecryptionFailedExceptionHandler`，无论调试模式如何，都将其映射到 `500` 并使用通用 `"internal-error"` Problem Details 响应体。标签验证失败只应在服务端记录日志。

---

### V-05 — 盲索引碰撞 / 离线字典攻击 ✅ SAFE

**威胁**：攻击者离线构建 `blindIndex(candidate)` 值的字典，并与 `email_idx` 列进行比较。
**缓解**：使用 256 位密钥的 HMAC-SHA256。没有 `VAULT_INDEX_KEY`，预计算任何索引值在计算上不可行。盲索引仅支持精确匹配（`WHERE email_idx = ?`）；不支持通配符或子字符串搜索。
**残余风险**：如果 `VAULT_INDEX_KEY` 泄露，有限的已知邮箱列表的所有邮箱盲索引都可被暴力破解。密钥保密性至关重要。

---

### V-06 — 端点无认证/授权 ⚠️ EXPOSED

**威胁**：任何未认证的调用者都可以创建、读取、更新和删除任意 `user_id` 的保险库记录。
**发现**：本 FT 暴露的 `/vault/{userId}/records` 没有 API 密钥、JWT 或会话检查。`user_id` 路径参数由调用者提供。
**建议**：要求认证（API 密钥或 JWT），并从验证令牌中派生 `$userId`——绝不信任调用者提供的 `user_id`。添加 `requireScope()` 或等效的认证中间件。
**FT 说明**：本 FT 有意限定范围。生产使用需要认证。

---

### V-07 — 更新/删除时的 IDOR ✅ SAFE

**威胁**：已认证但错误的用户修改另一用户的加密记录。
**缓解**：所有写查询都包含 `AND user_id = :user_id`。如果记录属于不同用户，`rowCount()` 返回 0，控制器返回 404。攻击者只知道记录（对他们而言）不存在。
**结论**：安全（假设存在认证，见 V-06）。

---

### V-08 — 密钥轮换 / 重新加密缺口 ⚠️ EXPOSED

**威胁**：轮换 `VAULT_ENC_KEY` 时，在旧密钥下加密的旧密文无法解密。没有重新加密迁移策略。
**发现**：没有密钥版本控制、重新加密工具，也没有迁移文档。
**建议**：为每个加密 blob 添加密钥版本字节前缀（例如 `v1:<base64>`）。解密时读取版本，选择密钥。提供迁移脚本，在事务中用旧密钥解密，再用新密钥重新加密。

---

### V-09 — 盲索引时序比较 ✅ SAFE

**威胁**：用 `===` 比较来自不受信来源的 `email_idx` 与存储值时，逐字符泄露时序信息。
**缓解**：`findByEmail()` 将计算的盲索引作为 SQL 参数传递。比较在 SQLite 的 B 树索引查找内部进行，从 PHP 层面看不是时序预言机。PHP 端不发生盲索引值的字符串比较。
**结论**：安全。

---

### V-10 — 解密数据出现在内存/日志中 ⚠️ EXPOSED

**威胁**：解密后的明文（姓名、邮箱、备注）出现在：PHP 异常跟踪、请求日志中间件（如果记录请求体）、错误输出、APM 追踪。
**发现**：请求体日志中间件在加密发生前记录 POST 请求体——明文字段存在于日志中。如果 `VaultRecord` 包含在异常上下文中，解密字段会出现在堆栈跟踪中。
**建议**：
1. 从请求体日志中排除明文保险库载荷（屏蔽或跳过 `/vault` 路由）。
2. 在 `VaultRecord` 上实现 `__debugInfo()`，从 var_dump / 异常序列化中编辑敏感字段。
3. 确保错误追踪集成（Sentry 等）在传输前清洗明文字段。

---

### VULN 总结

| ID | 威胁 | 状态 |
|----|------|------|
| V-01 | 密钥提交到版本控制 | ✅ SAFE |
| V-02 | Nonce 重用（GCM） | ✅ SAFE |
| V-03 | 接受被篡改的密文 | ✅ SAFE |
| V-04 | 响应中泄露解密错误详情 | ⚠️ EXPOSED |
| V-05 | 盲索引离线字典攻击 | ✅ SAFE |
| V-06 | 端点无认证 | ⚠️ EXPOSED |
| V-07 | 更新/删除时的 IDOR | ✅ SAFE |
| V-08 | 密钥轮换 / 重新加密缺口 | ⚠️ EXPOSED |
| V-09 | 盲索引时序比较 | ✅ SAFE |
| V-10 | 解密数据出现在日志/异常中 | ⚠️ EXPOSED |

**得分**：6 SAFE，4 EXPOSED。

四项暴露分别涉及密钥轮换策略（V-08）、认证（V-06，有意限定 FT 范围）、错误详情泄露（V-04）和日志卫生（V-10）。这些都不是 AES-256-GCM 或盲索引密码设计的缺陷——而是在生产使用前必须解决的运营和集成缺口。
