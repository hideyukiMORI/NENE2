# ハウツー: マルチデバイスセッションマネージャーの構築

> **FT186 sessionlog で実証されたパターン** — マルチデバイスセッショントラッキング、IDOR 防止、マスアサインメントガード、タイミングオラクルフリーの失効。

---

## このガイドのカバー範囲

マルチデバイスセッションマネージャーにより、ユーザーは以下が可能になります:

1. ログイン時に**セッションを作成する**（各デバイスが独自のトークンを持つ）
2. ユーザー ID にスコープされた**アクティブセッションを一覧表示する**
3. **単一セッションを失効させる**（1 台のデバイスからログアウト）
4. **現在以外をすべて失効させる**（他のすべてのデバイスからログアウト）

実証されたセキュリティ保証:

| 懸念事項 | 技術 |
|---|---|
| IDOR 防止 | すべてのミューテーションが `WHERE token = ? AND user_id = ?` でスコープ |
| マスアサインメント | `token`、`user_id`、`created_at`、`revoked_at` はサーバー側のみで設定 |
| タイミングオラクル | すべての失敗に汎用 404 — オーナーシップ漏洩なし |
| 整数オーバーフロー | `V::queryInt()` の 18 桁 strlen ガード |
| 型混乱 | `V::str()` が非文字列の `device_name`/`ip_address` を拒否 |
| トークンエントロピー | `bin2hex(random_bytes(32))` — 256 ビット、64 の 16 進文字 |
| SQL インジェクション | PDO パラメーター化クエリ + `/^[0-9a-f]{64}$/` ゲート |

---

## スキーマ

```sql
CREATE TABLE sessions (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id     INTEGER NOT NULL,
    token       TEXT    NOT NULL UNIQUE,
    device_name TEXT,
    ip_address  TEXT,
    last_active TEXT    NOT NULL,
    created_at  TEXT    NOT NULL,
    revoked_at  TEXT    -- NULL = アクティブ
);
```

`revoked_at IS NULL` がアクティブセッションの条件です。ソフト削除により監査履歴が失われません。

---

## API 設計

| メソッド | パス | ヘッダー | 説明 |
|---|---|---|---|
| `POST` | `/sessions` | `X-User-Id` | セッションを作成する |
| `GET` | `/sessions` | `X-User-Id` | 自分のアクティブセッションを一覧表示する |
| `DELETE` | `/sessions/{token}` | `X-User-Id` | 1 つのセッションを失効させる |
| `DELETE` | `/sessions` | `X-User-Id` + `X-Current-Session` | 現在以外をすべて失効させる |

---

## コアパターン: 256 ビットトークン生成

```php
public function create(int $userId, ?string $deviceName, ?string $ipAddress): Session
{
    $token = bin2hex(random_bytes(32)); // 256 ビットエントロピー、64 の 16 進文字
    $now   = $this->now();

    $stmt = $this->pdo->prepare(
        'INSERT INTO sessions (user_id, token, device_name, ip_address, last_active, created_at)
         VALUES (:user_id, :token, :device_name, :ip_address, :now, :now2)',
    );
    $stmt->execute([...]);
    // ...
}
```

`bin2hex(random_bytes(32))` は暗号学的に安全なソースから 64 の小文字 16 進文字を生成します。ユーザー入力からトークンを受け入れないでください。

---

## コアパターン: IDOR 防止

```php
// 間違い — 認証済みユーザーが任意のセッションを失効できる
UPDATE sessions SET revoked_at = ? WHERE token = ?

// 正解 — セッションを所有していなければならない
public function revokeForUser(string $token, int $userId): bool
{
    $stmt = $this->pdo->prepare(
        'UPDATE sessions SET revoked_at = :now
         WHERE token = :token AND user_id = :user_id AND revoked_at IS NULL',
    );
    $stmt->execute(['now' => $this->now(), 'token' => $token, 'user_id' => $userId]);

    return $stmt->rowCount() > 0;
}
```

トークンが存在するが別のユーザーに属している場合、`rowCount() > 0` は `false` を返します — ハンドラーは汎用 404 で応答します（タイミングオラクルセクション参照）。

---

## コアパターン: マスアサインメントガード

```php
// POST /sessions ハンドラー — 攻撃者のボディ: {"token": "custom", "user_id": 999, "revoked_at": "now"}
private function handleCreate(ServerRequestInterface $request): ResponseInterface
{
    // userId は X-User-Id ヘッダーから取得 — ボディからは取得しない
    $userId = V::userId($request->getHeaderLine('X-User-Id'));

    $body = $this->parseBody($request);

    // 安全で検証済みのフィールドのみを渡す
    $deviceName = V::str($body['device_name'] ?? null, 200);
    $ipAddress  = V::str($body['ip_address'] ?? null, 45);

    // token, user_id, created_at, revoked_at はリポジトリが設定 — ボディからは取得しない
    $session = $this->repository->create($userId, $deviceName, $ipAddress);

    return $this->responseFactory->create($session->toArray(), 201);
}
```

---

## コアパターン: タイミングオラクル防止

```php
private function handleRevokeOne(ServerRequestInterface $request): ResponseInterface
{
    $userId   = V::userId($request->getHeaderLine('X-User-Id'));
    $rawToken = Router::param($request, 'token');

    // VULN-I: 無効なフォーマット → 即座に 404（DB クエリなし）
    if ($rawToken === null || !preg_match('/^[0-9a-f]{64}$/', $rawToken)) {
        return $this->responseFactory->create(['error' => 'Session not found.'], 404);
    }

    // IDOR ガード: revokeForUser は以下の場合に false を返す:
    //   - トークンが存在しない
    //   - トークンが別のユーザーに属している
    //   - トークンがすでに失効している
    // すべてのケースで同じ 404 を返す — オーナーシップオラクルなし
    $revoked = $this->repository->revokeForUser($rawToken, $userId);

    if (!$revoked) {
        return $this->responseFactory->create(['error' => 'Session not found.'], 404);
    }

    return $this->responseFactory->create([], 204);
}
```

レスポンスで「見つからない」と「間違ったユーザー」を区別しないでください。被害者のトークンを知っている攻撃者は、それがアクティブかそのユーザーに属するかを知ってはいけません。

---

## コアパターン: 現在以外をすべて失効させる

```php
public function revokeAllExcept(int $userId, string $currentToken): int
{
    $stmt = $this->pdo->prepare(
        'UPDATE sessions SET revoked_at = :now
         WHERE user_id = :user_id AND token != :current AND revoked_at IS NULL',
    );
    $stmt->execute(['now' => $this->now(), 'user_id' => $userId, 'current' => $currentToken]);

    return $stmt->rowCount();
}
```

呼び出し元は `X-Current-Session` ヘッダーを渡します。`user_id` と除外条件の両方が 1 つのクエリで強制されます。

---

## コアパターン: オーバーフローセーフな limit バリデーション

```php
// VULN-A: V::queryInt は 18 桁を超えるものを拒否 — PHP の整数サイレントオーバーフローを防ぐ
// VULN-F: ctype_digit は O(n) — 正規表現バックトラッキングリスクなし
$limit = V::queryInt($params, 'limit', 1, self::MAX_LIMIT, self::DEFAULT_LIMIT);

if ($limit === null) {
    return $this->responseFactory->create(
        ['error' => sprintf('limit must be between 1 and %d.', self::MAX_LIMIT)],
        422,
    );
}
```

`V::queryInt()` は負の数、浮動小数点、16 進文字列（`0x10`）、18 桁を超える数を拒否します。

---

## ルートトークンバリデーション

DB をクエリする前に、ルートレイヤーで常にトークンフォーマットを検証してください:

```php
private const TOKEN_PATTERN = '/^[0-9a-f]{64}$/';

// ハンドラー内:
if ($rawToken === null || !preg_match(self::TOKEN_PATTERN, $rawToken)) {
    return $this->responseFactory->create(['error' => 'Session not found.'], 404);
}
```

これにより、SQL インジェクション文字列、パストラバーサル試行、短すぎる/長すぎるトークンがデータベースとのやり取りの前にブロックされます。

---

## テスト結果（FT186）

```
54 テスト / 116 アサーション — すべて PASS
PHPStan level 8 — エラーなし
PHP CS Fixer — クリーン
```

VULN-A〜L カバレッジ:

| 脆弱性 | パターン | テスト |
|---|---|---|
| A | limit の 19 桁オーバーフロー | `testVulnALimitOverflow19Digits` |
| B | device_name の型混乱 | `testVulnBDeviceNameAsInteger` |
| C | トークンへの SQL インジェクション | `testVulnCSqlInjectionToken` |
| D | 負/浮動小数点/16 進 limit | `testVulnDNegativeLimitRejected` |
| E | IDOR 失効 | `testVulnECannotRevokeOtherUsersSession` |
| F | ReDoS スタイルの長い limit | `testVulnFVeryLongLimitRejected` |
| H | タイミングオラクル | `testVulnHSameResponseForAlreadyRevokedAndCrossUser` |
| I | 空/短い/トラバーサルトークン | `testVulnIEmptyTokenSegmentNotMatched` |
| L | マスアサインメント | `testVulnLTokenFromBodyIsIgnored` |

ソース: [`../NENE2-FT/sessionlog/`](https://github.com/hideyukiMORI/NENE2-examples/tree/main/sessionlog)
