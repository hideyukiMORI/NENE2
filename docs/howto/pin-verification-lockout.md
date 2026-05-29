---
title: "PIN 認証・ロックアウト"
category: auth
tags: [pin, lockout, brute-force-protection, verification]
difficulty: intermediate
related: [numeric-verification-code, otp-authentication, account-lockout]
---

# PIN 認証・ロックアウト

> **FT reference**: FT252 (`NENE2-FT/pinverifylog`) — PIN Verification with Lockout
> **ATK**: FT252 — cracker-mindset attack test (ATK-01 through ATK-12)

6桁 PIN のブルートフォース防止・タイミング攻撃対策・管理者ロック解除の実装ガイド。
HMAC-SHA256 ハッシュ保存・定数時間比較・試行回数ロックアウトを解説する。

**FT192 セキュリティ実証済み**: VULN-A〜L 全 Pass / ATK-01〜12 全 Pass。

## 概要

- 管理者が PIN を作成（HMAC-SHA256 ハッシュ保存 — 平文は保存しない）
- ユーザーが PIN を検証（失敗回数上限でロックアウト）
- 管理者がロックを解除
- 試行履歴を監査ログとして記録

## エンドポイント

| Method | Path | 認証 | 説明 |
|---|---|---|---|
| `POST` | `/pins` | `X-Admin-Key` | PIN 作成 |
| `POST` | `/pins/{id}/verify` | — | PIN 検証 |
| `GET` | `/pins/{id}` | `X-Admin-Key` | 状態確認（残試行数・ロック期限） |
| `POST` | `/pins/{id}/unlock` | `X-Admin-Key` | ロック解除 |
| `DELETE` | `/pins/{id}` | `X-Admin-Key` | PIN 削除 |

## データベース設計

```sql
CREATE TABLE IF NOT EXISTS pins (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    label        TEXT    NOT NULL,
    pin_hash     TEXT    NOT NULL,        -- HMAC-SHA256(pin, secret)
    attempts     INTEGER NOT NULL DEFAULT 0,
    max_attempts INTEGER NOT NULL DEFAULT 5,
    locked_until TEXT,                    -- ISO 8601 UTC, NULL = unlocked
    created_at   TEXT    NOT NULL,
    updated_at   TEXT    NOT NULL
);

CREATE TABLE IF NOT EXISTS pin_attempts (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    pin_id       INTEGER NOT NULL,
    success      INTEGER NOT NULL DEFAULT 0,
    attempted_at TEXT    NOT NULL
);
```

ポジション: `locked_until` を ISO 8601 文字列として保存し、現在時刻と文字列比較（`$lockedUntil > $now`）でロック状態を判定する。変換コストなし。

## HMAC-SHA256 PIN ハッシュ

PIN は平文で保存せず、HMAC-SHA256 でハッシュ化する。サーバーサイドの秘密鍵（`$hmacSecret`）を混ぜることで、DB 漏洩時にブルートフォースを困難にする:

```php
private function hashPin(string $pin): string
{
    return hash_hmac('sha256', $pin, $this->hmacSecret);
}
```

## 定数時間比較（VULN-E / ATK-02）

`===` はバイト比較の途中で短絡するため、タイミング攻撃で正しいハッシュを推測できる。`hash_equals()` は必ず全バイトを比較する:

```php
// ❌ 危険: タイミング攻撃で推測可能
if ($stored === $provided) { ... }

// ✅ 安全: 定数時間比較
$provided = $this->hashPin($pin);
$success  = hash_equals($pin1->pinHash, $provided);
```

## ブルートフォース防止（ATK-01）

失敗回数が `max_attempts` に達したら `locked_until` をセットし、以後すべての試行（正しい PIN を含む）を 423 で拒否する:

```php
public function verify(int $id, string $pin): string
{
    $now  = $this->now();
    $pin1 = $this->findById($id);

    // 1. ロック確認を試行の前に行う
    if ($pin1->isLocked($now)) {
        return 'locked'; // → 423
    }

    // 2. 定数時間比較
    $provided = $this->hashPin($pin);
    $success  = hash_equals($pin1->pinHash, $provided);

    if ($success) {
        // 成功したら試行回数をリセット
        $this->resetAttempts($id, $now);
        return 'success'; // → 200
    }

    // 3. 失敗: カウントアップ → 上限でロック
    $newAttempts = $pin1->attempts + 1;
    $lockedUntil = null;

    if ($newAttempts >= $pin1->maxAttempts) {
        $lockedUntil = $this->lockUntil($now); // 5分後
    }

    $this->incrementAttempts($id, $newAttempts, $lockedUntil, $now);

    return $newAttempts >= $pin1->maxAttempts ? 'locked' : 'wrong'; // → 423 or 401
}
```

**重要**: ロック確認は試行の前に行う。試行後に確認すると、ロック状態に到達する最後の試行が通ってしまう可能性がある。

## 管理者キー fail-closed（VULN-H / ATK-03）

```php
private function isAdmin(ServerRequestInterface $request): bool
{
    if ($this->adminKey === '') {
        return false; // 空の adminKey は常に拒否
    }

    $provided = $request->getHeaderLine('X-Admin-Key');

    return $provided !== '' && hash_equals($this->adminKey, $provided);
}
```

`adminKey` が空文字の場合は無条件で `false`（環境変数未設定でオープン管理者になるのを防ぐ）。

## ID バリデーション（VULN-A / ATK-07）

```php
private function resolveId(ServerRequestInterface $request): ?int
{
    $raw = Router::param($request, 'id');

    if ($raw === null || !ctype_digit($raw) || strlen($raw) > 18) {
        return null; // → 422
    }

    $id = (int) $raw;

    return $id > 0 ? $id : null;
}
```

`strlen($raw) > 18` で 64-bit 整数オーバーフローを防ぐ（`PHP_INT_MAX` は 19桁だが安全マージン）。

## PIN バリデーション（VULN-D）

`ctype_digit()` を使う。正規表現（`/^[0-9]+$/`）は ReDoS の可能性があり O(n²) になりうるが、`ctype_digit()` は O(n) で安全:

```php
private function validatePin(mixed $pin): ?string
{
    if (!is_string($pin)) {
        return 'pin must be a string.'; // VULN-G: 型混乱防止
    }

    $len = strlen($pin);
    if ($len < self::MIN_PIN_LEN || $len > self::MAX_PIN_LEN) {
        return 'pin must be between 4 and 8 digits.';
    }

    if (!ctype_digit($pin)) { // O(n)、ReDoS なし
        return 'pin must contain only digits.';
    }

    return null;
}
```

## レスポンス設計

**PIN ハッシュは絶対にレスポンスに含めない。** 管理者向けレスポンスでも同様:

```php
public function toAdminArray(): array
{
    return [
        'id'                 => $this->id,
        'label'              => $this->label,
        'attempts'           => $this->attempts,
        'max_attempts'       => $this->maxAttempts,
        'locked_until'       => $this->lockedUntil,
        'remaining_attempts' => $this->remainingAttempts(),
        'created_at'         => $this->createdAt,
        // pin_hash は含めない
        // updated_at は内部情報として含めない
    ];
}
```

## レスポンス例

```json
// POST /pins (201)
{
    "pin": {
        "id": 1,
        "label": "vault",
        "attempts": 0,
        "max_attempts": 5,
        "locked_until": null,
        "remaining_attempts": 5,
        "created_at": "2026-05-26T10:00:00+00:00"
    }
}

// POST /pins/1/verify — 成功 (200)
{ "success": true, "locked": false }

// POST /pins/1/verify — 失敗 (401)
{ "success": false, "locked": false }

// POST /pins/1/verify — ロック済 (423)
{ "success": false, "locked": true, "error": "PIN is locked due to too many failed attempts." }

// POST /pins/1/unlock (200)
{ "unlocked": true }
```

## セキュリティポイント（VULN-A〜L / ATK-01〜12 全 Pass）

| 脅威 | カテゴリ | 対策 |
|---|---|---|
| ブルートフォース | ATK-01 | `max_attempts` 上限 → `locked_until` で 5分ロック |
| タイミング攻撃（PIN） | ATK-02 / VULN-E | `hash_equals()` 定数時間比較 |
| 管理者キー bypass | ATK-03 / VULN-H | `adminKey = ''` → false（fail-closed） |
| ID 列挙 | ATK-04 | 存在しない ID は 404（情報漏洩なし） |
| SQL インジェクション（PIN 値） | ATK-05 / VULN-B | `ctype_digit` で数字のみ通過 → PDO prepared statement |
| SQL インジェクション（ID） | ATK-06 / VULN-B | `ctype_digit + strlen > 18` ガード → 422 |
| 整数オーバーフロー | ATK-07 / VULN-A / VULN-J | `strlen > 18` ガード |
| ロックアウト bypass | ATK-08 | ロック確認は試行前・DB 永続化 |
| アンロック後再攻撃 | ATK-09 | unlock 後 attempts = 0 リセット（正常動作） |
| ボディインジェクション | ATK-10 / VULN-I | 明示的フィールドのみ受け付け |
| 管理者キータイミング | ATK-11 | `hash_equals()` 定数時間比較 |
| BIDI/Unicode ラベル | ATK-12 / VULN-L | `mb_strlen` で長さチェック、保存は PDO で安全 |
| ReDoS | VULN-D | `ctype_digit()` で O(n)、正規表現なし |
| 型混乱 | VULN-G | `!is_string($pin)` チェック |
| max_attempts オーバーフロー | VULN-F | 1〜20 の範囲チェック |
| SSRF | VULN-K | 外部 HTTP 通信なし（N/A） |
| パストラバーサル | VULN-C | ファイル操作なし（N/A） |

## 関連ガイド

- [アカウントロックアウト](account-lockout.md) — per-account 失敗カウント・423 設計
- [OTP 認証システム](otp-authentication.md) — 同様のロックアウトパターン（最新 OTP のみ有効）
- [Webhook シグネチャ検証](webhook-signature.md) — `hash_equals()` パターン
- [数値認証コード](numeric-verification-code.md) — 6桁コードの生成・検証フロー
