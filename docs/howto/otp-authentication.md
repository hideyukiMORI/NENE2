# OTP 認証システム

ワンタイムパスワード（OTP）認証の実装ガイド。
6桁コード生成・SHA-256ハッシュ保存・試行回数制限・セッション管理を解説する。

## 概要

- 6桁数字コードを生成（`random_int`）
- コードは SHA-256 ハッシュで保存（生コード非保存）
- 5分間有効・使用後無効化
- 3回失敗でロックアウト（10分）
- 検証成功でセッショントークン発行（24時間有効）
- `/otp/request` は常に 202（ユーザー列挙防止）

## エンドポイント

| Method | Path | 説明 |
|---|---|---|
| `POST` | `/otp/request` | OTP コードを生成・発行（常に 202） |
| `POST` | `/otp/verify` | OTP を検証してセッション発行 |
| `GET` | `/otp/session` | セッション有効性確認 |
| `DELETE` | `/otp/session` | ログアウト（セッション無効化） |

## データベース設計

```sql
CREATE TABLE otp_codes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    code_hash TEXT NOT NULL,        -- SHA-256 ハッシュ
    expires_at TEXT NOT NULL,       -- 5分後
    used_at TEXT,                   -- 使用済みフラグ
    attempt_count INTEGER NOT NULL DEFAULT 0,
    locked_until TEXT,              -- ロックアウト期限
    created_at TEXT NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

CREATE TABLE otp_sessions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    session_token_hash TEXT NOT NULL UNIQUE,  -- SHA-256 ハッシュ
    expires_at TEXT NOT NULL,                 -- 24時間後
    revoked_at TEXT,                          -- ログアウトフラグ
    created_at TEXT NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id)
);
```

## OTP コード生成

```php
$rawCode = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
$codeHash = hash('sha256', $rawCode);
$this->repository->createOtp($userId, $codeHash, $now);

// Always 202 — prevents user enumeration
// In production: send email. In testing: return code in response.
return $this->responseFactory->create([
    'message' => 'OTP code sent',
    'code' => $rawCode,  // テスト用。本番では除去
], 202);
```

`str_pad` で必ず 6 桁にする（例: `random_int(0, 999999)` が `42` → `'000042'`）。

## 検証ロジックの順序

```php
// 1. ロックアウトチェック（最優先）
if ($otp['locked_until'] !== null && $now < (string) $otp['locked_until']) {
    return $this->responseFactory->create(['error' => 'too many attempts, try again later'], 429);
}

// 2. 有効期限チェック（used_at より前）
if ($now > (string) $otp['expires_at']) {
    return $this->responseFactory->create(['error' => 'code expired'], 401);
}

// 3. 使用済みチェック
if ($otp['used_at'] !== null) {
    return $this->responseFactory->create(['error' => 'code already used'], 401);
}

// 4. コードハッシュ比較（タイミング攻撃防止）
$codeHash = hash('sha256', $code);
if (!hash_equals((string) $otp['code_hash'], $codeHash)) {
    $this->repository->incrementAttempt((int) $otp['id'], $now);
    return $this->responseFactory->create(['error' => 'invalid code'], 401);
}
```

ロックアウトチェックを最初に行うことで、ロック中に有効な OTP が送られてきても試行を拒否する。

## 試行回数制限（ロックアウト）

```php
public function incrementAttempt(int $otpId, string $now): void
{
    $otp = $this->executor->fetchOne('SELECT * FROM otp_codes WHERE id = ?', [$otpId]);
    $newCount = (int) $otp['attempt_count'] + 1;
    $lockedUntil = null;
    if ($newCount >= self::MAX_ATTEMPTS) {  // 3回
        $lockedUntil = date('c', strtotime($now) + self::LOCK_MINUTES * 60);  // 10分
    }
    $this->executor->execute(
        'UPDATE otp_codes SET attempt_count = ?, locked_until = ? WHERE id = ?',
        [$newCount, $lockedUntil, $otpId]
    );
}
```

`attempt_count >= MAX_ATTEMPTS` に達したら `locked_until` を設定し、それ以降のリクエストを 429 で拒否する。

## 最新の OTP を使用する設計

```php
public function findLatestOtpForUser(int $userId): ?array
{
    return $this->executor->fetchOne(
        'SELECT * FROM otp_codes WHERE user_id = ? ORDER BY id DESC LIMIT 1',
        [$userId]
    );
}
```

複数回 OTP をリクエストしても、常に最新（最後に生成した）コードのみが有効。
古いコードは自動的に無効化される（ATK-12 対策）。

## セッショントークン

```php
$rawToken = bin2hex(random_bytes(32));     // 256-bit ランダム
$tokenHash = hash('sha256', $rawToken);   // SHA-256 で保存
$this->repository->createSession((int) $user['id'], $tokenHash, $now);

return $this->responseFactory->create([
    'session_token' => $rawToken,          // 生トークンをクライアントに返す
    'user_id' => (int) $user['id'],
], 200);
```

セッション確認は `Authorization: Bearer <token>` ヘッダーで行う。

## Bearer トークン抽出

```php
private function extractBearerToken(ServerRequestInterface $request): string
{
    $header = $request->getHeaderLine('Authorization');
    if (!str_starts_with($header, 'Bearer ')) {
        return '';
    }
    return trim(substr($header, 7));
}
```

## MySQL 対応

MySQL スキーマは `database/schema.mysql.sql` を用意する（`INT AUTO_INCREMENT`・`ENGINE=InnoDB`）。
`AppFactory::createMysql()` で `DatabaseConfig` + `PdoConnectionFactory` 経由で接続する。

```php
public static function createMysql(string $host, int $port, string $name, string $user, string $password): Router
{
    $dbConfig = new DatabaseConfig(
        url: null, environment: 'test', adapter: 'mysql',
        host: $host, port: $port, name: $name, user: $user,
        password: $password, charset: 'utf8mb4',
    );
    $factory = new PdoConnectionFactory($dbConfig);
    return self::create($factory);
}
```

## .php-cs-fixer.php の注意点

`declare_strict_types` はリスキーフィクサーのため `setRiskyAllowed(true)` が必要:

```php
return (new PhpCsFixer\Config())
    ->setFinder($finder)
    ->setRiskyAllowed(true)
    ->setRules(['@PSR12' => true, 'declare_strict_types' => true]);
```
