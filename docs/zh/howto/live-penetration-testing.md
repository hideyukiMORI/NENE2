# 操作指南：实时容器渗透测试

本指南记录如何对一个 NENE2 应用运行一次对抗式的实时容器渗透测试——从搭建开始，贯穿全部 30 个攻击阶段——并记录来自 v1.5.329 测试会话（2026-05-31，150+ 个用例）的正准结果。

该测试采用**攻击者思维**：假设攻击者拥有源代码的完全访问权（白盒），已阅读所有公开文档，并且会在放弃之前尝试每一个已知的攻击类别。

---

## 前提条件

- 可用的 Docker Compose（`docker compose version`）
- 主机上已安装 `curl`、`nc`（netcat）、`openssl`、`python3`
- 一个带有测试凭据的运行中 NENE2 容器

---

## 1. 容器搭建

启动一个隔离的测试目标。使用专用端口（绝不用生产端口）并注入测试凭据：

```bash
# PHP built-in server target — fastest to spin up, tests raw NENE2 behaviour
NENE2_MACHINE_API_KEY=pentest-key docker compose run -d --rm \
  -e NENE2_LOCAL_JWT_SECRET=pentest-jwt-secret-32chars-min!! \
  -e APP_ENV=local \
  -e APP_DEBUG=false \
  -p 8299:80 \
  app php -S 0.0.0.0:80 -t public_html/

# Apache target — tests full stack including Apache config hardening
NENE2_MACHINE_API_KEY=pentest-key docker compose up -d app
# Available on :8200 (see port registry in CLAUDE.md §8)
```

基线冒烟检查：

```bash
curl -si http://localhost:8299/
# Expected: 200 OK, security headers present, no Server/X-Powered-By
```

从 OpenAPI 枚举攻击面：

```bash
curl -s http://localhost:8299/openapi.php | grep -E "^  /"
# → /, /health, /machine/health, /examples/protected,
#   /examples/notes, /examples/notes/{id}, /examples/tags, /examples/tags/{id}
```

在容器内生成测试凭据：

```bash
CID=$(docker ps --filter "publish=8299" --format "{{.ID}}")
VALID_JWT=$(docker exec $CID php -r "
  require 'vendor/autoload.php';
  \$v = new Nene2\Auth\LocalBearerTokenVerifier('pentest-jwt-secret-32chars-min!!');
  echo \$v->issue(['sub'=>'tester','exp'=>time()+86400]);
")
```

---

## 2. 攻击阶段

### 阶段 1 — JWT 算法混淆

| ID | 攻击 | 预期 | v1.5.329 |
|----|--------|----------|----------|
| J-01 | `alg:none`（空签名） | 401 | ✅ BLOCKED |
| J-02 | `alg:NONE`（大写） | 401 | ✅ BLOCKED |
| J-03 | `alg:None`（混合大小写） | 401 | ✅ BLOCKED |
| J-04 | `alg:hs256`（小写） | 401 | ✅ BLOCKED |
| J-05 | `alg:RS256`（密钥混淆） | 401 | ✅ BLOCKED |
| J-06 | 无 `alg` 字段 | 401 | ✅ BLOCKED |
| J-07 | `kid: ../../etc/passwd` | 200（有效签名） | ✅ SAFE — 额外的头部字段被忽略 |
| J-08 | `jku: http://evil.com` | 200（有效签名） | ✅ SAFE — 不获取 JWK |

```bash
# J-01: alg:none
H=$(echo -n '{"typ":"JWT","alg":"none"}' | base64 -w0 | tr '+/' '-_' | tr -d '=')
P=$(echo -n '{"sub":"admin","exp":9999999999}' | base64 -w0 | tr '+/' '-_' | tr -d '=')
curl -si -H "Authorization: Bearer $H.$P." http://localhost:8299/examples/protected
# → 401  detail: "Token algorithm must be HS256."
```

### 阶段 1b — JWT 载荷篡改

| ID | 攻击 | 预期 | v1.5.329 |
|----|--------|----------|----------|
| J-09 | `exp: 0`（纪元 1970） | 401 已过期 | ✅ BLOCKED |
| J-10 | `exp: null` | 401 必须为数字 | ✅ BLOCKED |
| J-11 | `exp: "never"` | 401 必须为数字 | ✅ BLOCKED |
| J-12 | `exp: 9999999999.9`（浮点） | 401 必须为数字 | ✅ BLOCKED |
| J-13 | 载荷是 JSON 数组 | 401 必须为数字 | ✅ BLOCKED |
| J-14 | Bearer 值中的双空格 | 401 | ✅ BLOCKED |
| J-15 | 无 Bearer 方案 | 401 | ✅ BLOCKED |
| J-16 | 4 段 token（多一个点） | 401 格式无效 | ✅ BLOCKED |
| J-17 | 仅头部 + 载荷（无签名） | 401 | ✅ BLOCKED |

> **关键不变式**：`exp` 必须是一个存在的整数——缺失或类型错误都会被拒绝（在 v1.5.329 中修复）。

### 阶段 2 — SQL 注入

所有 repository 都使用 `?` 占位符参数化查询。没有原始字符串插值。

| ID | 攻击 | 预期 | v1.5.329 |
|----|--------|----------|----------|
| S-01 | 标题中的经典 `' OR 1=1--` | 201（作为字面量存储） | ✅ SAFE |
| S-02 | `UNION SELECT 1,2,3--` | 201（作为字面量存储） | ✅ SAFE |
| S-03 | 布尔盲注 `AND 1=1--` | 201（作为字面量存储） | ✅ SAFE |
| S-04 | 基于时间 `AND SLEEP(2)--` | 201，<50ms | ✅ SAFE — SLEEP 未执行 |
| S-05 | 路径参数 SQLi `/notes/1' OR '1'='1` | 200（int 转换 → 1） | ✅ SAFE |
| S-06 | 空字节 `\0' OR '1'='1` | 201（字面量） | ✅ SAFE |
| S-07 | 二阶注入：存储载荷，然后读取 | 200（字面量重读） | ✅ SAFE |
| S-08 | 请求体字段中的 SLEEP(5) | 201，<50ms | ✅ SAFE |
| S-10 | 查询串中的 `limit=UNION SELECT...` | 422（校验） | ✅ SAFE |

```bash
# Verify parameterized queries: SLEEP is not executed
time curl -si -X POST -H "Content-Type: application/json" \
  -d '{"title":"x'\'' AND SLEEP(3)--","body":"x"}' \
  http://localhost:8299/examples/notes
# → 201 in < 100ms  (SLEEP never ran)
```

### 阶段 3 — 路径遍历 / LFI / PHP 包装器

| ID | 攻击 | 预期 | v1.5.329 |
|----|--------|----------|----------|
| P-01 | `../../etc/passwd` | 404 | ✅ BLOCKED |
| P-02 | URL 编码的 `%2e%2e%2f` 变体（5 种形式） | 404 | ✅ BLOCKED |
| P-03 | 双重编码 `%252e%252e` | 404 | ✅ BLOCKED |
| P-04 | UTF-8 超长 `%c0%ae` | 404 | ✅ BLOCKED |
| P-05 | `php://input` / `php://filter` / `data://` | 404 | ✅ BLOCKED |
| P-06 | 通过 `{id}` 参数的 LFI | 404 | ✅ BLOCKED |
| P-07 | 空字节 `1%00.html` | 200（int 转换 → 1） | ✅ SAFE — 返回 id=1 的 DB 记录 |
| P-08 | Apache 上的 `.htaccess` | 403 | ✅ BLOCKED |
| P-08b | PHP 内置服务器上的 `.htaccess` | **200** | ⚠️ EXPOSED（见 VULN-01） |
| P-09 | `.git/HEAD` | 404 | ✅ BLOCKED |
| P-10 | 备份文件（`.bak`、`.swp`、`~` 等） | 404 | ✅ BLOCKED |

### 阶段 4 — HTTP 协议攻击

| ID | 攻击 | 预期 | v1.5.329 |
|----|--------|----------|----------|
| H-01 | CL.TE 请求走私 | 无响应（PHP 内置阻止） | ✅ |
| H-02 | TE.CL 走私 | 405（根方法不匹配） | ✅ |
| H-03 | TE.TE 混淆的 Transfer-Encoding | 无响应 | ✅ |
| H-04 | HTTP/1.0 降级 | 200（正确的请求体） | ✅ |
| H-05 | 绝对 URI 代理滥用 | 404 | ✅ |
| H-06 | HTTP 头部折叠 | 500（PHP 内置 bug） | ⚠️ VULN-02 |
| H-07 | HTTP 流水线 | 响应交错 | ✅ SAFE |
| H-08 | 100 个同时的自定义头部 | 200 | ✅ SAFE |
| H-10 | WebSocket 升级 | 200（升级被忽略） | ✅ SAFE |
| H-12 | 无效的 HTTP 版本（`HTTP/9.9`） | 200（PHP 内置接受） | ✅ SAFE |

### 阶段 5 — 大量赋值 / IDOR / 业务逻辑

| ID | 攻击 | 预期 | v1.5.329 |
|----|--------|----------|----------|
| B-01 | 大量赋值（请求体中的 `id`、`__proto__`） | 201（额外字段被忽略） | ✅ SAFE |
| B-02 | IDOR：DELETE 另一用户的 note | 204 | ℹ️ 符合预期（示例没有所有权） |
| B-04 | 负数 / 零 ID | 404 | ✅ SAFE |
| B-05 | 整数溢出 ID | 404 | ✅ SAFE |
| B-06 | DELETE 后再访问同一 ID | 404 | ✅ SAFE |
| B-07 | 并发 DELETE 竞态 | 全部 404（幂等） | ✅ SAFE |
| B-08 | 处于 1MB 限制的请求体 | 413 | ✅ BLOCKED |

### 阶段 6 — API 密钥绕过

| ID | 攻击 | 预期 | v1.5.329 |
|----|--------|----------|----------|
| A-01 | 无密钥 | 401 | ✅ BLOCKED |
| A-02 | 查询串中的密钥（`?key=`、`?api_key=`） | 401 | ✅ BLOCKED |
| A-03 | 请求体中的密钥 | 401 | ✅ BLOCKED |
| A-04 | 头部名称大小写变体 | 200（PSR-7 规范化） | ✅ SAFE |
| A-05 | 值中的前导/尾随空白 | 200（PSR-7 修剪） | ✅ SAFE |
| A-06 | `//machine/health` 双斜杠 | 无密钥 401，有密钥 200 | ✅ SAFE |
| A-07 | `X-Original-URL` / `X-Rewrite-URL` | 200（头部被忽略） | ✅ SAFE |
| A-08 | OPTIONS 预检绕过 | 405 | ✅ BLOCKED |
| A-09 | HEAD 方法 | 401 | ✅ BLOCKED |
| A-10 | 常见密码暴力破解 | 全部 401 | ✅ BLOCKED |
| A-11 | URL 编码路径（`%6Dachine`） | 404 | ✅ BLOCKED |

```bash
# Timing attack: hash_equals used → constant-time comparison
time (for i in $(seq 1 10); do
  curl -so /dev/null -H "X-NENE2-API-Key: a" http://localhost:8299/machine/health
done)
time (for i in $(seq 1 10); do
  curl -so /dev/null -H "X-NENE2-API-Key: pentest-key" http://localhost:8299/machine/health
done)
# → timing difference < 5ms over 10 requests: SAFE
```

### 阶段 7 — 注入 / XSS / SSTI / 代码执行

| ID | 攻击 | 预期 | v1.5.329 |
|----|--------|----------|----------|
| I-01 | 存储型 XSS `<script>alert(1)</script>` | 201，作为 JSON 字符串返回 | ✅ SAFE — JSON 编码中和 |
| I-02 | SSTI `{{7*7}}` / `${7*7}` | 201，原样存储 | ✅ SAFE — 无模板引擎 |
| I-03 | PHP `<?php system("id"); ?>` | 201，作为字面量存储 | ✅ SAFE |
| I-04 | Log4Shell `${jndi:ldap://...}` | 200（头部被忽略） | ✅ SAFE — 是 PHP，不是 Java |
| I-05 | 1000 层嵌套 JSON | 400（PHP 解析限制） | ✅ BLOCKED |
| I-06 | Unicode BiDi 控制字符 | 201（已存储） | ✅ SAFE — 仅显示层风险 |
| I-07 | 重复的 JSON 键 | 最后一个值胜出（PHP 行为） | ℹ️ INFO-01 |

> **存储型 XSS 说明**：XSS 载荷被存储并在 JSON 响应中原样返回。由于该 API 仅为 JSON（`Content-Type: application/json` + `X-Content-Type-Options: nosniff`），浏览器不会执行该脚本。仅当另一个应用在不转义的情况下把这些数据渲染到 HTML 上下文中时，风险才会显现。

### 阶段 8 — 反序列化 / PHP 对象注入

| ID | 攻击 | 预期 | v1.5.329 |
|----|--------|----------|----------|
| D-01 | 路径参数中的 `phar://` 包装器 | 404 | ✅ BLOCKED |
| D-02 | PHP `O:8:"stdClass":...` serialize 载荷 | 400（无效请求体） | ✅ BLOCKED |
| D-03 | 带 serialize 载荷的 URL 编码表单 | 400（错误的 Content-Type） | ✅ BLOCKED |

### 阶段 9 — 头部注入 / 响应拆分

| ID | 攻击 | 预期 | v1.5.329 |
|----|--------|----------|----------|
| R-01 | 通过创建的 note id 进行 Location 头部注入 | `/examples/notes/<int>` | ✅ SAFE — 仅整数 |
| R-02 | 通过 JWT 错误在 WWW-Authenticate 中注入 CRLF | 已净化的固定消息 | ✅ SAFE |
| R-03 | Content-Type 嗅探 | `X-Content-Type-Options: nosniff` | ✅ SAFE |
| R-04 | 点击劫持 | `X-Frame-Options: SAMEORIGIN` | ✅ SAFE |

### 阶段 10 — CORS / SOP 绕过

| ID | 攻击 | 预期 | v1.5.329 |
|----|--------|----------|----------|
| C-01 | `Origin: null`（沙箱化 iframe） | Vary: Origin，无 ACAO 头部 | ✅ SAFE |
| C-02 | Origin 头部中的 CRLF | 由 curl/http 层净化 | ✅ SAFE |
| C-03 | Vary 头部缓存投毒 | 存在 `Vary: Origin` | ✅ SAFE |
| C-04 | 带注入方法的预检 | 方法被 PHP 忽略 | ✅ SAFE |
| C-05 | `Access-Control-Allow-Origin: *` | 头部缺失（allowlist 为空） | ✅ SAFE |

### 阶段 11-20 — 编码 / 协议 / 计时

| ID | 攻击 | 结果 |
|----|--------|--------|
| E-01 | JSON 中的表情符号 / 高位 Unicode | ✅ 201（正确存储） |
| E-02 | BiDi RTL 覆盖（仿冒风险） | ✅ 201（仅显示层） |
| E-05 | 通过查询参数的分页 SQLi | ✅ 422（作为整数校验） |
| H-06b | 折叠的 Authorization 头部 | ⚠️ 500（PHP 内置 bug） |
| 20 | X-Request-Id 129 字符被拒绝 | ✅ 服务器生成新的随机 ID |
| 21 | 通过 X-Request-Id `%0a` 的日志注入 | ✅ 被拒绝（无效字符） |
| 22 | Apache ServerTokens/ServerSignature | ✅ 仅 `Server: Apache` |
| 23 | JWT sub=admin 提权 | ✅ Claims 不用于授权 |
| 26 | JWT 重放（2 秒前过期） | ✅ 401 `Token has expired.` |
| 27 | 500 堆栈跟踪泄露 | ✅ 仅通用消息 |
| 28 | Problem Details `instance` 中的 XSS | ✅ URL 编码（安全） |
| 29 | 通过健康检查端点的 SSRF | ✅ 不接受任何 URL |
| 15 | API 密钥计时预言机 | ✅ `hash_equals` — < 5ms 差异 |

---

## 3. 发现

### VULN-01 — `.htaccess` 可从 PHP 内置服务器读取 ⚠️ MEDIUM

**触发**：`curl http://localhost:8299/.htaccess`  
**响应**：200 + 完整文件内容（Apache 重写规则）  
**根因**：PHP 的内置服务器（`php -S`）不强制执行 `.htaccess` 访问限制——它把 `.htaccess` 当作静态文件对待。  
**影响**：泄露 URL 重写规则。内容非机密（无密码/token），但确认了重写到 index.php 的模式。  
**缓解**：进行安全敏感测试时，使用 Apache 容器（`docker compose up -d app`）而不是 `php -S`。Apache 正确地返回 403。

```bash
# Apache (correct): 403 Forbidden
curl -si http://localhost:8200/.htaccess | head -1

# PHP built-in server (exposed): 200 OK
curl -si http://localhost:8299/.htaccess | head -1
```

### VULN-02 — HTTP 头部折叠使 PHP 内置服务器崩溃 ⚠️ LOW

**触发**：
```
GET / HTTP/1.1\r\nHost: localhost\r\nX-NENE2-API-Key:\r\n <key>\r\n\r\n
```
**响应**：`HTTP/1.0 500 Internal Server Error`（空请求体）  
**根因**：PHP 的内置 HTTP 服务器不支持 RFC 7230 头部折叠（已弃用但在 HTTP/1.1 中仍然有效）。NENE2 框架代码并不参与其中。  
**影响**：仅限开发环境（PHP 内置服务器）。Apache 正确处理折叠的头部。

### INFO-01 — 重复的 JSON 键：最后一个值胜出

`{"title":"first","title":"INJECTED"}` → `title = "INJECTED"`  
标准的 PHP `json_decode` 行为。校验应用于最终（最后一个）值，因此不存在校验绕过路径。在此记录以备知悉。

---

## 4. 已验证的安全不变式

这些保证在所有 150+ 个测试用例中均成立：

| 不变式 | 验证 |
|-----------|-------------|
| 所有 SQL 查询参数化 | SLEEP 未执行；注入载荷作为字面量存储 |
| JWT 必须为 HS256 + 有效签名 + 整数 exp | 全部 17 个 JWT 攻击变体被阻止 |
| API 密钥用 `hash_equals` 检查 | 10 次迭代中计时差异 < 5ms |
| `Content-Length` 溢出已处理 | 413 配正确头部，无 PHP 警告泄露 |
| 每个响应都带安全头部 | CSP / XCTO / XFO / Referrer-Policy / Permissions-Policy 已确认 |
| `Server:` / `X-Powered-By:` 已移除 | Apache 响应中两个头部均不存在 |
| 堆栈跟踪绝不出现在 500 请求体中 | 仅通用 `"The server encountered an unexpected condition."` |
| 路径遍历被阻止 | 全部 15 种编码变体返回 404 |
| `.env` / `.git` / 备份文件 | 在文档根中全部 404 |
| CORS 默认：无允许的来源 | 任意来源的 `Access-Control-Allow-Origin` 缺失 |

---

## 5. 运行测试套件

关键检查的最小可行复现（< 5 分钟）：

```bash
TARGET=http://localhost:8299
APIKEY=pentest-key
SECRET=pentest-jwt-secret-32chars-min!!
CID=$(docker ps --filter "publish=8299" --format "{{.ID}}")

# 1. JWT alg:none
H=$(echo -n '{"typ":"JWT","alg":"none"}' | base64 -w0 | tr '+/' '-_' | tr -d '=')
P=$(echo -n '{"sub":"admin","exp":9999999999}' | base64 -w0 | tr '+/' '-_' | tr -d '=')
curl -si -H "Authorization: Bearer $H.$P." $TARGET/examples/protected | grep "HTTP/"
# expected: 401

# 2. SQL injection time-based
time curl -so /dev/null -X POST -H "Content-Type: application/json" \
  -d '{"title":"x'\'' AND SLEEP(3)--","body":"x"}' $TARGET/examples/notes
# expected: < 500ms total

# 3. Path traversal
curl -si "$TARGET/%2e%2e/%2e%2e/etc/passwd" | grep "HTTP/"
# expected: 404

# 4. Content-Length overflow
curl -si -X POST -H "Content-Length: 9999999999999" $TARGET/ | head -3
# expected: 413 Request Entity Too Large (not 200 + PHP warning)

# 5. API key timing
time (for i in $(seq 1 10); do
  curl -so /dev/null -H "X-NENE2-API-Key: a" $TARGET/machine/health
done)
# expected: similar timing to correct key (hash_equals)

# 6. .htaccess exposure (Apache only)
curl -si http://localhost:8200/.htaccess | grep "HTTP/"
# expected: 403

# 7. JWT exp required
NEXP=$(docker exec $CID php -r "
  require 'vendor/autoload.php';
  \$v = new Nene2\Auth\LocalBearerTokenVerifier('$SECRET');
  echo \$v->issue(['sub'=>'user1']);
")
curl -si -H "Authorization: Bearer $NEXP" $TARGET/examples/protected | grep "detail"
# expected: "Token must contain a numeric exp claim."
```

---

## 相关

- [分页边界与限制注入](pagination-boundary-attack.md)
- [Webhook 签名验证](webhook-signature-verification.md)
- [添加 JWT 认证](add-jwt-authentication.md)
- ADR 0011：安全审查策略
