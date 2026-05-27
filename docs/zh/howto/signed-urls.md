# 签名 URL

签名 URL 提供对受保护资源的有时间限制、资源作用域的访问，无需调用者使用账号认证。该模式用于文件下载、预签名上传槽，以及任何需要与第三方共享临时访问权限的场景。

## 核心概念

签名 URL 包含授权访问所需的一切：资源 ID、过期时间，以及证明 URL 由受信任服务器生成的 HMAC 签名。服务器只需其密钥即可进行验证——无需数据库查询。

## 令牌格式

```
base64url({resource_id}|{expires_at}|{hmac-sha256(resource_id|expires_at, secret)})
```

HMAC 覆盖 `resource_id|expires_at` 整体。修改任何一部分都会使签名无效。这将令牌绑定到确切的一个资源和一个过期窗口。

## 签名器实现

```php
final readonly class HmacSigner
{
    private const string ALGO = 'sha256';

    public function __construct(
        private string $secret,
    ) {}

    public function sign(int $resourceId, string $expiresAt): string
    {
        $payload = $resourceId . '|' . $expiresAt;
        $mac     = hash_hmac(self::ALGO, $payload, $this->secret);

        return $this->base64UrlEncode($payload . '|' . $mac);
    }

    public function verify(string $token, string $now): ?int
    {
        $decoded = $this->base64UrlDecode($token);
        if ($decoded === null) {
            return null;
        }

        $parts = explode('|', $decoded, 3);
        if (count($parts) !== 3) {
            return null;
        }

        [$resourceId, $expiresAt, $storedMac] = $parts;

        $expectedMac = hash_hmac(self::ALGO, $resourceId . '|' . $expiresAt, $this->secret);

        // hash_equals() 是强制要求——使用 === 会泄露时序信息
        if (!hash_equals($expectedMac, $storedMac)) {
            return null;
        }

        if ($expiresAt < $now) {
            return null;
        }

        return (int) $resourceId;
    }
}
```

`hash_equals()` 不可妥协。字符串相等比较在第一次不匹配时退出，泄露 HMAC 有多少字符匹配。攻击者可以利用这一点逐字节伪造签名。`hash_equals()` 始终比较所有字符。

## 已过期令牌：410 Gone vs 401 Unauthorized

用户应该知道他们的链接是已过期（应该请求新链接）还是从未有效。签名器先验证 HMAC，再检查过期时间。为了在 HTTP 响应中区分两者：

```php
$resourceId = $this->signer->verify($token, $now);

if ($resourceId === null) {
    // 不验证 HMAC 地提取过期时间
    $expiresAt = $this->signer->extractExpiresAt($token);
    if ($expiresAt !== null && $expiresAt < $now) {
        return $problems->create($request, 'gone', 'This link has expired.', 410, '');
    }
    return $problems->create($request, 'unauthorized', 'Invalid or expired token.', 401, '');
}
```

`extractExpiresAt()` 只解码 base64 并按 `|` 分割——它**不**验证 HMAC。这是安全的，因为：
1. 过期时间不是秘密（它本来就在签名 URL 中可见）。
2. 攻击者无法伪造带有被篡改过期时间的有效令牌，因为 `verify()` 会拒绝它。
3. 410 响应没有提供任何有助于伪造令牌的信息。

不要为"HMAC 不匹配"和"过期"返回不同的错误消息——那会允许攻击者先为任意过期值构造有效签名，然后用来探测时序。

## 生成签名 URL

```php
// POST /files/{id}/sign
$expiresAt = (new \DateTimeImmutable())
    ->add(new \DateInterval("PT{$ttlSeconds}S"))
    ->format('Y-m-d H:i:s');

$token = $this->signer->sign($file->id, $expiresAt);

return $json->create([
    'token'       => $token,
    'expires_at'  => $expiresAt,
    'ttl_seconds' => $ttlSeconds,
    'url'         => '/download?token=' . urlencode($token),
]);
```

在嵌入 URL 之前始终对令牌进行 `urlencode()`——base64url 字符是 URL 安全的，但 `=` 填充（如果存在）不是，而且解码载荷中的 `|` 分隔符不应出现在编码形式中。

## 密钥管理

- 从环境变量注入密钥——永远不要硬编码。
- 使用至少 32 字节的随机数据（`random_bytes(32)` → 十六进制或 base64）。
- 密钥轮换时，支持同时对多个密钥进行验证（依次尝试直到成功），然后逐步淘汰旧密钥。

```php
// 轮换期间的多密钥支持
public function verifyWithRotation(string $token, string $now, array $secrets): ?int
{
    foreach ($secrets as $secret) {
        $signer = new HmacSigner($secret);
        $id = $signer->verify($token, $now);
        if ($id !== null) {
            return $id;
        }
    }
    return null;
}
```

## 无状态与有状态签名 URL

此模式是**无状态**的——服务器不跟踪已签发的令牌。这是主要优势（每次下载无需 DB 查询），但这意味着：

- 无法在令牌过期前吊销签名 URL。
- 密钥轮换时，所有之前签发的令牌会立即失效。

对于可吊销的令牌，维护一个黑名单表（`revoked_tokens`）并在验证时检查。这以无状态优势换取可吊销性。

## 不应做的事

| 反模式 | 风险 |
|---|---|
| HMAC 比较用 `===` 或 `strcmp()` | 时序攻击——允许伪造签名 |
| 只签名 `resource_id` 而不包含过期时间 | 令牌是永久的——无法过期 |
| 只签名 `expires_at` 而不包含 resource_id | 一个令牌可授予所有资源的访问权限 |
| 用过期时间区分"被篡改"和"已过期" | 允许对 HMAC 的预言机攻击 |
| 在令牌中嵌入原始密钥 | 适得其反——令牌必须是不透明的 |
| 长 TTL（天/周） | 如果令牌泄露，暴露窗口期增加 |
