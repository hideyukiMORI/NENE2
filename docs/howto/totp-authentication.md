---
title: "TOTP 二要素認証の実装ガイド"
category: auth
tags: [totp, two-factor, otp, authentication]
difficulty: advanced
related: [otp-authentication, numeric-verification-code]
---

# TOTP 二要素認証の実装ガイド

## 概要

このガイドでは NENE2 を使って RFC 6238 TOTP（Time-based One-Time Password）二要素認証を実装する方法を説明します。
Google Authenticator・Authy 互換のシークレット生成・コード検証・リプレイ攻撃防止・ブルートフォースロックアウトを提供します。

---

## DB スキーマ

```sql
CREATE TABLE totp_secrets (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id         INTEGER NOT NULL UNIQUE,
    secret          TEXT    NOT NULL,
    is_enabled      INTEGER NOT NULL DEFAULT 0,
    failed_attempts INTEGER NOT NULL DEFAULT 0,
    locked_until    TEXT,
    created_at      TEXT    NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

CREATE TABLE used_totp_steps (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    time_step  INTEGER NOT NULL,
    used_at    TEXT    NOT NULL,
    UNIQUE (user_id, time_step),
    FOREIGN KEY (user_id) REFERENCES users(id)
);
```

`used_totp_steps` テーブルが**リプレイ攻撃防止**の核心。使用済みの時間ステップを記録する。

---

## エンドポイント設計

| メソッド | パス | 説明 |
|---|---|---|
| POST | `/users/{id}/totp/setup` | TOTP シークレット生成（返却後にアプリへ登録） |
| POST | `/users/{id}/totp/enable` | コード検証して 2FA 有効化 |
| POST | `/users/{id}/totp/verify` | コード検証（ログインフロー） |
| DELETE | `/users/{id}/totp` | 2FA 無効化（有効コード必須） |
| GET | `/users/{id}/totp` | 2FA ステータス取得 |

---

## フレームワーク組み込みプリミティブ（推奨）

v1.5 以降、危険な暗号部分は framework が提供する `Nene2\Auth\TotpAuthenticator` と
`Nene2\Auth\RecoveryCodes` をそのまま使える（ADR 0009 の安定 public API）。
RFC 6238 の計算・Base32・定数時間比較を各アプリで再実装する必要はない。
`SecureTokenHelper` と同じく「危険な暗号は framework の1実装、enroll/challenge の
HTTP フロー・enforce ポリシー・シークレットの at-rest 暗号化・リプレイ防止の永続化は
アプリの責務」という分担。

```php
use Nene2\Auth\TotpAuthenticator;
use Nene2\Auth\RecoveryCodes;

$totp = new TotpAuthenticator();          // digits=6 / period=30 / sha1 / window=1

// 登録: シークレット生成 → QR 用 otpauth URI
$secret = $totp->generateSecret();
$uri    = $totp->provisioningUri($secret, 'alice@example.com', 'NENE2');

// 検証: 一致した time_step が返る（null なら無効）。リプレイ防止は step を永続化して判定。
$step = $totp->verify($secret, $submittedCode);
if ($step === null || $repo->isStepUsed($userId, $step)) {
    // 無効 or リプレイ
} else {
    $repo->markStepUsed($userId, $step);  // 使用済み step を記録（1回限り）
}

// リカバリコード: 生成して一度だけ表示、hash のみ保存、redeem 時に verify → consume
$codes = RecoveryCodes::generate();       // 例: ["3f9ac-1b0e7", ...]
$repo->storeRecoveryHash($userId, RecoveryCodes::hash($codes[0]));
```

次節以降は、このプリミティブが内部で行っている計算とセキュリティ設計の解説。
自前実装や理解のための参考として残す。

---

## RFC 6238 TOTP の実装

```php
class TotpGenerator
{
    private const int DIGITS = 6;
    private const int PERIOD = 30; // 秒

    public function computeCode(string $base32Secret, int $timeStep): string
    {
        $secret = $this->base32Decode($base32Secret);

        // 時間ステップを 8 バイト big-endian にパック
        $msg = pack('N*', 0) . pack('N*', $timeStep);
        $hash = hash_hmac('sha1', $msg, $secret, true);

        // Dynamic truncation (RFC 4226 §5.4)
        $offset = ord($hash[19]) & 0x0F;
        $code = ((ord($hash[$offset]) & 0x7F) << 24)
              | ((ord($hash[$offset + 1]) & 0xFF) << 16)
              | ((ord($hash[$offset + 2]) & 0xFF) << 8)
              | (ord($hash[$offset + 3]) & 0xFF);

        return str_pad((string) ($code % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    public function verify(string $base32Secret, string $code, int $window = 1): ?int
    {
        $t = (int) floor(time() / self::PERIOD);
        for ($offset = -$window; $offset <= $window; $offset++) {
            $step = $t + $offset;
            $expected = $this->computeCode($base32Secret, $step);
            if (hash_equals($expected, $code)) {   // タイミング攻撃防止
                return $step;
            }
        }
        return null;
    }
}
```

---

## 設計のポイント

### リプレイ攻撃防止

TOTP コードは 30 秒間有効。同じコードを 2 回使われるとなりすましが可能。
`used_totp_steps` テーブルで使用済み time_step を記録し、再利用を拒否する。

```php
$matchedStep = $this->totp->verify($secret, $code);
if ($matchedStep === null) {
    // コードが無効
    return 401;
}
if ($this->repo->isStepUsed($userId, $matchedStep)) {
    // 同じ time_step のコードが既に使用済み → リプレイ攻撃
    return 401;
}
// 使用済みとして記録
$this->repo->markStepUsed($userId, $matchedStep, $now);
```

### タイミング攻撃防止

TOTP コードの比較には `hash_equals()` を使う。`===` や `strcmp()` は文字列比較を早期終了するため、応答時間から一致桁数が推測できる。

```php
// NG: タイミング攻撃に脆弱
if ($expected === $inputCode) { ... }

// OK: 定時間比較
if (hash_equals($expected, $inputCode)) { ... }
```

### window 幅（時刻ずれ対応）

`window = 1` で現在ステップ ± 1（= ±30 秒）を許容する。
スマートフォンの時刻ずれはほぼこの範囲に収まる。
window を広げるとセキュリティが下がるため 1 を推奨。

### ブルートフォースロックアウト

3 回失敗で 15 分ロック（423 Locked）。
ロック中は正しいコードでも拒否する（timing oracle 防止）:

```php
if ($this->repo->isLocked($userId, $now)) {
    return 423; // ロック中 — 正しいコードか確認しない
}
```

### セットアップフロー

1. `POST /users/{id}/totp/setup` でシークレット生成
2. レスポンスの `secret`（Base32）または `otpauth_uri` を Authenticator アプリに登録
3. `POST /users/{id}/totp/enable` で初回コードを検証して有効化
4. 有効化前はシークレットが DB に保存されるが `is_enabled = false`

```
otpauth://totp/NENE2:alice?secret=JBSWY3DPEHPK3PXP&issuer=NENE2&algorithm=SHA1&digits=6&period=30
```

### 再セットアップで旧シークレット失効

`POST /users/{id}/totp/setup` を再度呼ぶと旧シークレットが上書きされ、
`used_totp_steps` も削除される。旧シークレットのコードは認証不可になる。

---

## セキュリティチェックリスト（脆弱性診断 12 件全 Pass）

| # | 確認項目 | 対策 |
|---|---|---|
| A | リプレイ攻撃 | `used_totp_steps` で使用済み time_step を記録 |
| B | ブルートフォース | 3 回失敗で 15 分ロック（423） |
| C | ロック中の正規コード | ロック判定を先に行い、コード検証を一切しない |
| D | 不正な 2FA 無効化 | DELETE にも有効コードを要求 |
| E | 不正な 2FA 有効化 | enable にコード検証必須 |
| F | 旧シークレット悪用 | 再セットアップで旧シークレット・使用済みステップを削除 |
| G | IDOR | コードはユーザーごとに独立した secret で検証 |
| H | シークレット露出 | verify/enable レスポンスに secret を含めない |
| I | 不正形式コード | 非一致 → 401（format バリデーションは任意） |
| J | 空コード | required バリデーションで 422 |
| K | 未有効化での verify | `is_enabled` チェックで 409 |
| L | 存在しないユーザー | findUser() → null → 404 |

---

## テスト上の注意

TOTP コードは時刻依存のため、同じ time_step のコードを続けて使うとリプレイ扱いになる。
テストでは `TotpGenerator::computeCode($secret, $gen->currentTimeStep() + N)` で異なるステップのコードを生成して使い分ける:

```php
$enableCode  = $gen->computeCode($secret, $gen->currentTimeStep());     // enable に使用
$verifyCode  = $gen->computeCode($secret, $gen->currentTimeStep() + 1); // verify に使用
$disableCode = $gen->computeCode($secret, $gen->currentTimeStep() + 2); // disable に使用
```

---

## 参照実装

`../NENE2-FT/totplog/` — FT159 フィールドトライアル（21 テスト + 脆弱性診断 12 件 = 32 テスト）
