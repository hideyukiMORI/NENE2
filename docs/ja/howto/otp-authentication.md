# ハウツー: OTP 認証システム

> **FT リファレンス**: FT290 (`NENE2-FT/otplog`) — OTP 認証: SHA-256 ハッシュ保存による 6 桁数値コード、ブルートフォースロックアウト（3 回試行 → 10 分）、OTP TTL（5 分）、`used_at` によるリプレイ攻撃防止、SHA-256 + 無効化によるセッショントークン、常に 202 のリクエストエンドポイントによるユーザー列挙防止、ATK-01〜12 PASS、35 テスト / 44 アサーション PASS。

このガイドでは、ユーザーが 6 桁コードを受け取りセッショントークンと交換するパスワードレス OTP（ワンタイムパスワード）認証システムの構築方法を解説します。

## スキーマ

```sql
CREATE TABLE users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    email TEXT NOT NULL UNIQUE,
    created_at TEXT NOT NULL
);

CREATE TABLE otp_codes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    code_hash TEXT NOT NULL,
    expires_at TEXT NOT NULL,
    used_at TEXT,
    attempt_count INTEGER NOT NULL DEFAULT 0,
    locked_until TEXT,
    created_at TEXT NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

CREATE TABLE otp_sessions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    session_token_hash TEXT NOT NULL UNIQUE,
    expires_at TEXT NOT NULL,
    revoked_at TEXT,
    created_at TEXT NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id)
);
```

主要な設計ポイント:
- `code_hash` は OTP の SHA-256 を保存します — 生のコードは保存しません。
- `attempt_count` + `locked_until` は OTP 行ごとのブルートフォースロックアウトを実装します。
- `used_at` はリプレイ攻撃を防ぎます（OTP は 1 回だけ使用できます）。
- `session_token_hash` はセッショントークンの SHA-256 を保存します; `UNIQUE` は衝突を防ぎます。
- `revoked_at` は行を削除せずに明示的なログアウトを可能にします。

## エンドポイント

| メソッド | パス | 認証 | 説明 |
|--------|------|------|-------------|
| `POST` | `/otp/request` | なし | OTP をリクエストする（必要に応じてユーザーを作成） |
| `POST` | `/otp/verify` | なし | OTP を検証し、セッショントークンを受け取る |
| `GET` | `/otp/session` | `Bearer <token>` | セッション情報を取得する |
| `DELETE` | `/otp/session` | `Bearer <token>` | ログアウト（セッションを無効化） |

## OTP 生成 — 生のコードを保存しない

```php
private const int MAX_ATTEMPTS = 3;
private const int OTP_TTL_MINUTES = 5;
private const int LOCK_MINUTES = 10;
private const int SESSION_TTL_HOURS = 24;

$rawCode = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
$codeHash = hash('sha256', $rawCode);
$this->repository->createOtp($userId, $codeHash, $now);
```

`str_pad` は先頭ゼロを保証します（例: `random_int(0, 999999)` が `42` を返す → `'000042'`）。生のコードはユーザーのメールに送信されます; ハッシュのみが保存されます。`random_int()` は暗号学的にセキュアです。

## ユーザー列挙防止 — 常に 202

```php
// 常に 202 — ユーザー列挙を防ぐ
// 本番では: メールを送信する。この FT ではテスト用にコードを返す。
return $this->responseFactory->create([
    'message' => 'OTP code sent',
    'code' => $rawCode,  // 本番では削除する
], 202);
```

メールが存在するかどうかに関係なく、レスポンスは常に `202 Accepted` です。攻撃者は「アカウントが存在する」と「アカウントが存在しない」を区別できません。

## 初回リクエスト時のユーザー自動作成

```php
public function findOrCreateUser(string $email, string $now): int
{
    $user = $this->findUserByEmail($email);
    if ($user !== null) {
        return (int) $user['id'];
    }
    return $this->executor->insert(
        'INSERT INTO users (email, created_at) VALUES (?, ?)',
        [$email, $now]
    );
}
```

ユーザーは最初の OTP リクエスト時に暗黙的に作成されます — 別の登録ステップは不要です。`UNIQUE(email)` 制約は並行インサートでの重複を防ぎます。

## OTP 検証 — 順序付きチェック

```php
// 1. ロックアウトチェック（最初 — コード比較の前）
if ($otp['locked_until'] !== null && $now < (string) $otp['locked_until']) {
    return $this->responseFactory->create(['error' => 'too many attempts, try again later'], 429);
}

// 2. 期限切れチェック
if ($now > (string) $otp['expires_at']) {
    return $this->responseFactory->create(['error' => 'code expired'], 401);
}

// 3. 既使用チェック
if ($otp['used_at'] !== null) {
    return $this->responseFactory->create(['error' => 'code already used'], 401);
}

// 4. hash_equals によるコードチェック（タイミングセーフ）
$codeHash = hash('sha256', $code);
if (!hash_equals((string) $otp['code_hash'], $codeHash)) {
    $this->repository->incrementAttempt((int) $otp['id'], $now);
    return $this->responseFactory->create(['error' => 'invalid code'], 401);
}
```

チェック順序が重要です: ロックアウト → 期限切れ → 使用済み → コード。`attempt_count` をインクリメントするのはコードが間違っている場合のみです — ロックアウトや期限切れではインクリメントしません。

## ブルートフォースロックアウト

```php
public function incrementAttempt(int $otpId, string $now): void
{
    $otp = $this->executor->fetchOne('SELECT * FROM otp_codes WHERE id = ?', [$otpId]);
    if ($otp === null) {
        return;
    }
    $newCount = (int) $otp['attempt_count'] + 1;
    $lockedUntil = null;
    if ($newCount >= self::MAX_ATTEMPTS) {
        $lockedUntil = date('c', strtotime($now) + self::LOCK_MINUTES * 60);
    }
    $this->executor->execute(
        'UPDATE otp_codes SET attempt_count = ?, locked_until = ? WHERE id = ?',
        [$newCount, $lockedUntil, $otpId]
    );
}
```

`MAX_ATTEMPTS`（3 回）の間違ったコードの後、`locked_until` は将来の 10 分後に設定されます。ロックアウトチェックはコード比較の前に行われるため、ロックアウト中の試行はタイマーをリセットしません。

## 最新 OTP のみ — 新しいリクエストが古いものを上書き

```php
public function findLatestOtpForUser(int $userId): ?array
{
    return $this->executor->fetchOne(
        'SELECT * FROM otp_codes WHERE user_id = ? ORDER BY id DESC LIMIT 1',
        [$userId]
    );
}
```

複数の OTP リクエストは複数の行を作成しますが、検証に使われるのは最新のもののみです。古い OTP は事実上無効化されます — 送信すると 401 を返します。

## セッショントークン — SHA-256 + 無効化

```php
// セッショントークンを発行する
$rawToken = bin2hex(random_bytes(32));   // 256 ビットエントロピー、64 hex 文字
$tokenHash = hash('sha256', $rawToken);
$this->repository->createSession((int) $user['id'], $tokenHash, $now);

return $this->responseFactory->create([
    'session_token' => $rawToken,
    'user_id' => (int) $user['id'],
], 200);
```

SHA-256 ハッシュのみが保存されます。DB が侵害されても、生のトークンは公開されません。

## Bearer トークンの抽出

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

`Bearer ` の後の空文字列（例: `Authorization: Bearer `）は欠落として扱われます — 401 を返します。

## ログアウト — サイレントな成功

```php
$session = $this->repository->findSessionByTokenHash($tokenHash);
if ($session !== null && $session['revoked_at'] === null) {
    $this->repository->revokeSession($tokenHash, date('c'));
}

return $this->responseFactory->create(['message' => 'logged out'], 200);
```

ログアウトは常に 200 を返します — トークンが有効かどうかを明かしません。これにより攻撃者がログアウトエンドポイントを通じてトークンの有効性を調べることを防ぎます。

---

## ATK アセスメント — クラッカーマインド攻撃テスト

### ATK-01 — OTP ブルートフォース 🚫 BLOCKED

**Attack**: `000000`〜`999999` のすべての組み合わせを順に試す。
**Result**: BLOCKED — `MAX_ATTEMPTS`（3 回）の間違ったコードの後、`locked_until` は将来の 10 分後に設定されます。ロックアウトが切れるまで以降の試行は 429 を返します。

---

### ATK-02 — リプレイ攻撃（使用済み OTP の再使用） 🚫 BLOCKED

**Attack**: 有効な OTP をキャプチャし、既に使用された後に 2 回目として送信する。
**Result**: BLOCKED — 最初の検証成功時に `used_at` が設定されます。2 回目の試行は `used_at !== null` を見つけ → 401。

---

### ATK-03 — /otp/request を通じたユーザー列挙 🚫 BLOCKED

**Attack**: 既知と未知のメールで `/otp/request` を調査して、どのアカウントが存在するかを発見する。
**Result**: BLOCKED — 既存と非存在の両方のメールは常に同一のレスポンスボディで `202 Accepted` を返します。

---

### ATK-04 — 存在しないユーザーの検証 🚫 BLOCKED

**Attack**: アカウントのないメールで `/otp/verify` を呼び出す。
**Result**: BLOCKED — 404 や 500 ではなく 401（`invalid code`）を返します。レスポンスにスタックトレースやアカウント存在シグナルは含まれません。

---

### ATK-05 — メールフィールドへの SQL インジェクション 🚫 BLOCKED

**Attack**: `'; DROP TABLE users; --` をメールとして送信する。
**Result**: BLOCKED — `filter_var($email, FILTER_VALIDATE_EMAIL)` は DB クエリの前にインジェクション文字列を無効なメール形式として拒否します。すべてのクエリはパラメーター化ステートメントを使用します。

---

### ATK-06 — 5 桁のコード（短すぎる） 🚫 BLOCKED

**Attack**: OTP フォーマットチェックをバイパスするために 5 文字のコードを送信する。
**Result**: BLOCKED — `/^\d{6}$/` はちょうど 6 桁を要求します。422 を返します。

---

### ATK-07 — 7 桁のコード（長すぎる） 🚫 BLOCKED

**Attack**: フォーマットバリデーションをバイパスするために 7 桁のコードを送信する。
**Result**: BLOCKED — 同じ正規表現がちょうど 6 桁ではないコードを拒否します。422 を返します。

---

### ATK-08 — ログアウト後のセッショントークン再使用 🚫 BLOCKED

**Attack**: ログアウト後にトークンを使ってアクセスを維持する。
**Result**: BLOCKED — `revokeSession()` が `revoked_at` を設定します。GET ハンドラーが `$session['revoked_at'] !== null` をチェック → 401。

---

### ATK-09 — ランダムなトークン推測 🚫 BLOCKED

**Attack**: ランダムな 64 hex 文字列を Bearer トークンとして送信する。
**Result**: BLOCKED — ランダムなトークンの SHA-256 ハッシュはどの `session_token_hash` にも一致しません。401 を返します。トークン空間は 2^256 です。

---

### ATK-10 — 空の Bearer トークン 🚫 BLOCKED

**Attack**: `Authorization: Bearer `（Bearer プレフィックスの後が空）を送信する。
**Result**: BLOCKED — `trim(substr($header, 7))` が空文字列を返す → `if ($token === '') return 401`。

---

### ATK-11 — アルファベットコード（非数値） 🚫 BLOCKED

**Attack**: OTP コードとして `abcdef` を送信する。
**Result**: BLOCKED — `/^\d{6}$/` は 10 進数のみを要求します。DB インタラクションの前に 422 を返します。

---

### ATK-12 — 新しい OTP リクエストが古いコードを無効化 🚫 BLOCKED（設計による）

**Attack**: 有効な OTP を取得し、被害者に新しいものをリクエストさせ、元のコードを送信する。
**Result**: BLOCKED — `findLatestOtpForUser()` は `ORDER BY id DESC LIMIT 1` のみを取得します。古い OTP は上書きされます; 送信すると 401 を返します（最新 OTP の間違ったコードハッシュ）。

---

### ATK サマリー

| ID | 攻撃 | 結果 |
|----|--------|--------|
| ATK-01 | OTP ブルートフォース | 🚫 BLOCKED |
| ATK-02 | リプレイ攻撃（使用済み OTP） | 🚫 BLOCKED |
| ATK-03 | /otp/request を通じたユーザー列挙 | 🚫 BLOCKED |
| ATK-04 | 存在しないユーザーの検証 | 🚫 BLOCKED |
| ATK-05 | メールへの SQL インジェクション | 🚫 BLOCKED |
| ATK-06 | 5 桁のコード（短すぎる） | 🚫 BLOCKED |
| ATK-07 | 7 桁のコード（長すぎる） | 🚫 BLOCKED |
| ATK-08 | ログアウト後のセッション再使用 | 🚫 BLOCKED |
| ATK-09 | ランダムなトークン推測 | 🚫 BLOCKED |
| ATK-10 | 空の Bearer トークン | 🚫 BLOCKED |
| ATK-11 | アルファベットコード | 🚫 BLOCKED |
| ATK-12 | 新しいリクエストで古い OTP が無効化 | 🚫 BLOCKED |

**12 BLOCKED, 0 EXPOSED**
ハッシュベースの保存、ブルートフォースロックアウト、`used_at` リプレイガード、フォーマットバリデーション、常に 202 の列挙防止がすべての重大な OTP 攻撃ベクターをカバーします。

---

## してはいけないこと

| アンチパターン | リスク |
|---|---|
| DB に生の OTP コードを保存する | DB 侵害ですべてのアクティブな OTP が公開される; 常に SHA-256 ハッシュ |
| ブルートフォースロックアウトなし | 6 桁の OTP には 10^6 の組み合わせがある — ロックアウトなしでは数秒でブルートフォース可能 |
| 検証で不明なメールに 404 を返す | どのメールにアカウントがあるかを明かす（ユーザー列挙） |
| /request で既知と未知のメールに異なるステータスを返す | 同じ列挙リスク; 常に 202 を返す |
| `used_at` フラグなし | OTP は期限切れになるまで無期限にリプレイできる |
| アルファベットや 6 桁以外のコードを受け付ける | フォーマット契約をバイパスする; `/^\d{6}$/` チェックを追加する |
| DB に生のセッショントークンを保存する | DB 漏洩ですべてのセッションが公開される; SHA-256 ハッシュのみ保存する |
| ログアウト時にセッション行を削除する | 無効化されたトークンを検出できない; `revoked_at` でソフト無効化する |
| トークンの有効性に基づいてログアウトの成功/失敗を明かす | 攻撃者がログアウトを通じてトークンの有効性を調べる; 常に 200 を返す |
| `findAllOtpsForUser()` を使って有効なものを選ぶ | 複数のアクティブな OTP が状態を混乱させる; `ORDER BY id DESC LIMIT 1` を使う |
| メールの長さ制限なし | RFC 5321 の最大は 254 文字; 過大な入力が DB/メール問題を引き起こす |
