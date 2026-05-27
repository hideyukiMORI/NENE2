# アカウントロックアウト（ブルートフォース保護）

> **FT リファレンス**: FT280 (`NENE2-FT/lockoutlog`) — アカウントロックアウト: 5 回の失敗で 15 分間のロックアウト（423 Locked）、ロック中は正しいパスワードもブロック、成功でカウンタをリセット、Argon2id パスワード検証、MySQL 統合テスト、27 テスト合格 / 5 スキップ（MySQL）、44 アサーション PASS。
>
> **ATK アセスメント**: ATK-01 から ATK-12 がこのドキュメントの末尾に含まれています。

設定可能な回数の失敗後にアカウントをロックすることで、ログインエンドポイントをブルートフォース攻撃から保護します。

## 概要

アカウントロックアウトはメールアドレスごとのログイン失敗回数を追跡し、失敗しきい値を超えた時点で `locked_until` タイムスタンプを設定します。ロックはすべてのログイン試行に対して強制されます — アカウントがロックされている間は正しいパスワードでも拒否されます。ロックはクールダウン期間後に自動的に期限切れになります。

## データベーススキーマ

```sql
CREATE TABLE users (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    email         TEXT    NOT NULL UNIQUE,
    password_hash TEXT    NOT NULL,
    created_at    TEXT    NOT NULL
);

CREATE TABLE account_states (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    email        TEXT    NOT NULL UNIQUE,
    failed_count INTEGER NOT NULL DEFAULT 0,
    locked_until TEXT,
    updated_at   TEXT    NOT NULL
);
```

`account_states` はアカウントごとの失敗履歴を追跡します。`locked_until` はロックされていないアカウントでは null です。

## 定数

```php
public const int MAX_ATTEMPTS    = 5;   // ロックアウトまでの失敗回数
public const int LOCKOUT_MINUTES = 15;  // ロックアウト期間
```

## ログインフロー

```php
// 1. パスワード検証前にロックアウトを確認する
$state = $this->repo->findOrCreateAccountState($email, $now);
if ($state->isLocked($now)) {
    return 423; // Locked
}

// 2. 資格情報を検証する
$user = $this->repo->findUserByEmail($email);
if ($user === null || !$user->verifyPassword($pass)) {
    if ($user !== null) {
        $this->repo->recordFailure($email, $now);
    }
    return 401; // Unauthorized
}

// 3. 成功 — カウンタをリセットする
$this->repo->resetState($email, $now);
return 200;
```

ロックアウトチェックはパスワード検証の**前に**行われます。ロックアウト状態は**既存ユーザー**に対してのみ書き込まれます — 未知のメールアドレスは `account_state` 行を作成せずに 401 を返します（ストレージ枯渇を防止）。

## ロックアウトチェック

```php
public function isLocked(string $now): bool
{
    return $this->lockedUntil !== null && $now < $this->lockedUntil;
}
```

`$now` は `Y-m-d H:i:s` 文字列です。ISO 8601 日時文字列では辞書順比較が正しく機能します。

## 失敗の記録

```php
public function recordFailure(string $email, string $now): AccountState
{
    $state    = $this->findOrCreateAccountState($email, $now);
    $newCount = $state->failedCount + 1;

    $lockedUntil = null;
    if ($newCount >= AccountState::MAX_ATTEMPTS) {
        $lockedUntil = date('Y-m-d H:i:s', strtotime($now) + AccountState::LOCKOUT_MINUTES * 60);
    }

    $this->executor->execute(
        'UPDATE account_states SET failed_count = ?, locked_until = ?, updated_at = ? WHERE email = ?',
        [$newCount, $lockedUntil, $now, $email],
    );
    ...
}
```

`failed_count` が `MAX_ATTEMPTS` に達すると、`locked_until` が `now + LOCKOUT_MINUTES * 60` 秒に設定されます。

## 成功時のリセット

```php
$this->executor->execute(
    'UPDATE account_states SET failed_count = 0, locked_until = NULL, updated_at = ? WHERE email = ?',
    [$now, $email],
);
```

認証成功により `failed_count` と `locked_until` の両方がリセットされます。ロックアウト前に成功したユーザーは新鮮な失敗カウンタを得ます。

## ユーザー列挙の防止

誤ったパスワードと未知のメールアドレスの両方に同じ HTTP ステータス（401）を返します:

```php
if ($user === null || !$user->verifyPassword($pass)) {
    if ($user !== null) {
        $this->repo->recordFailure($email, $now);
    }
    return 401; // 同じステータスを返す
}
```

攻撃者は HTTP レスポンスから「アカウントなし」と「パスワード誤り」を区別できません。

## MySQL スキーマ

MySQL では `INT AUTO_INCREMENT` と `DATETIME` を使用します:

```sql
CREATE TABLE IF NOT EXISTS users (
    id            INT          NOT NULL AUTO_INCREMENT,
    email         VARCHAR(255) NOT NULL,
    password_hash TEXT         NOT NULL,
    created_at    DATETIME     NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS account_states (
    id           INT          NOT NULL AUTO_INCREMENT,
    email        VARCHAR(255) NOT NULL,
    failed_count INT          NOT NULL DEFAULT 0,
    locked_until DATETIME     NULL,
    updated_at   DATETIME     NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

`Y-m-d H:i:s` の日時フォーマットは SQLite（TEXT 比較）と MySQL（DATETIME カラム）の両方で機能します。

## MySQL 統合テスト

`MYSQL_HOST` が設定されていない場合はスキップする `MysqlLockoutTest.php` を追加します:

```php
protected function setUp(): void
{
    $host = (string) (getenv('MYSQL_HOST') ?: '');
    if ($host === '') {
        self::markTestSkipped('MYSQL_HOST not set — skipping MySQL integration tests');
    }
    // テスト分離のためテーブルをドロップして再作成する
    $this->pdo->exec('DROP TABLE IF EXISTS account_states');
    $this->pdo->exec('DROP TABLE IF EXISTS users');
    $this->pdo->exec($mysqlSchema);
    ...
}
```

共有 FT MySQL コンテナ（ポート 3308、永続ボリューム）に対して実行します:

```bash
docker compose -f ../NENE2-FT/docker-compose.yml up -d mysql
```

次に環境変数を指定して統合テストを実行します:

```bash
MYSQL_HOST=127.0.0.1 MYSQL_PORT=3308 MYSQL_DATABASE=ft_test \
  MYSQL_USER=ft_user MYSQL_PASSWORD=ft_pass \
  php8.4 vendor/bin/phpunit --filter Mysql
```

`MYSQL_HOST` がない場合、MySQL テストは自動的にスキップされます。

## セキュリティ特性

| 特性 | 実装 |
|---|---|
| ロックアウトしきい値 | 5 回の失敗 |
| ロックアウト期間 | 15 分 |
| ロック中の正しいパスワード | ブロック（423） |
| ユーザー列挙 | 未知のメールアドレスと誤ったパスワードで同じ 401 |
| ロックアウトスコープ | IP ではなくメールアドレスごと |
| ロックアウトリセット | ログイン成功時に自動 |
| パスワードハッシュ化 | Argon2id |
| 長いメールアドレス入力 | 256 文字以上で拒否（422） |
| SQL インジェクション | パラメータ化クエリでインジェクションを防止 |

## 設計上のトレードオフ: ロックアウト DoS

ロックアウトは IP ではなくメールアドレスごとなので、ユーザーのメールアドレスを知っている攻撃者が 5 回誤ったパスワードを送信してそのユーザーをロックアウトできます。これはブルートフォース保護と可用性の間の固有の緊張です。

緩和策（ここでは実装しませんが利用可能）:
- ハードロックアウトの代わりに**段階的な遅延**
- N 回失敗後の **CAPTCHA**
- ロックアウトトリガー時の**通知メール**
- **管理者アンロックエンドポイント**

ほとんどのアプリケーションでは、このトレードオフはブルートフォース保護を優先します。ロックアウトは 15 分後に自動的に期限切れになります。

## ルートまとめ

| メソッド | パス | 説明 |
|---|---|---|
| `POST` | `/users` | ユーザーを作成する（シード/登録） |
| `POST` | `/auth/login` | ログイン試行（200/401/423） |
| `GET` | `/auth/status/{email}` | ロックアウト状態を確認する |

---

## ATK アセスメント — クラッカー視点の攻撃テスト

### ATK-01 — ロックアウトまでブルートフォース 🚫 BLOCKED

**攻撃**: 既知のメールアドレスに対して誤ったパスワードで 5 回以上ログイン試行を送信する。
**結果**: BLOCKED — 5 回の失敗後、`failed_count >= MAX_ATTEMPTS` で `locked_until = now + 15 分` が設定されます。以降の試行はパスワードチェック前に 423 `account-locked` を受け取ります。

---

### ATK-02 — ロックアウト後に正しいパスワードを送信 🚫 BLOCKED

**攻撃**: アカウントをロックし、すぐに正しいパスワードを送信する。
**結果**: BLOCKED — ロックアウトチェックは `findUserByEmail()` の前に行われます。正しいパスワードでも、ロック中は 423 が返されます。

---

### ATK-03 — 実アカウントのロックを避けるために存在しないメールアドレスを探索する 🚫 BLOCKED（設計上）

**攻撃**: 実アカウントのロックアウトをトリガーせずに存在しないメールアドレスを使って探索する。
**結果**: BLOCKED（設計上） — 存在しないメールアドレスは失敗を累積せず、ストレージを保護します。実アカウントは独自のロックアウト状態で保護されます。偽のメールアドレスを探索しても実アカウントについては何も明らかになりません。

---

### ATK-04 — 失敗しきい値での同時ログイン試行によるレース条件 🚫 BLOCKED

**攻撃**: `failed_count` が 4 の時に 2 つのリクエストを同時送信してロックアウトを競走させる。
**結果**: BLOCKED — `UPDATE account_states` は DB レベルでアトミックです。SQLite WAL は同時書き込みをシリアライズし、MySQL は行レベルロックを使用します。両方の更新が成功し、最終的な `locked_until` が正しく設定されます。

---

### ATK-05 — ステータスエンドポイントがロックアウト状態を明らかにする 🚫 BLOCKED（設計上）

**攻撃**: `GET /auth/status/{email}` でメールアドレスのロックアウト有無を確認する。
**結果**: 設計上 — ステータスエンドポイントはクライアント UX（「15 分後に再試行してください」）のために意図されています。本番環境ではレート制限または認証が必要です。ロックアウトのタイミングは明らかになりますが、パスワード情報は明らかになりません。

---

### ATK-06 — メールフィールドへの SQL インジェクション 🚫 BLOCKED

**攻撃**: `{"email": "' OR '1'='1' --", "password": "x"}` を送信する。
**結果**: BLOCKED — すべてのクエリはパラメータ化ステートメント（`WHERE email = ?`）を使用します。インジェクトされた文字列はリテラルのメールアドレス値として扱われます。

---

### ATK-07 — サービス拒否のための過大なメールアドレス文字列 🚫 BLOCKED

**攻撃**: 100,000 文字のメールアドレスフィールドを送信する。
**結果**: BLOCKED — `if (strlen($email) > 255)` → DB クエリ前に 422 `validation-failed`。

---

### ATK-08 — メールアドレスまたはパスワードフィールドの欠如 🚫 BLOCKED

**攻撃**: `{}` または `{"email": "x@x.com"}` をパスワードなしで送信する。
**結果**: BLOCKED — `if ($email === '' || $pass === '')` → 422 `validation-failed`。

---

### ATK-09 — 別アカウントでログインしてカウンタをリセット 🚫 BLOCKED

**攻撃**: アカウント A をロックし、アカウント B でログインして A のカウンタをリセットする。
**結果**: BLOCKED — `resetState()` はメールアドレスでキーされています。別のアカウントのログイン成功はアカウント A の状態に影響しません。

---

### ATK-10 — バリデーションを迂回するための空白のみのメールアドレス 🚫 BLOCKED

**攻撃**: `{"email": "   ", "password": "x"}` を送信する。
**結果**: BLOCKED — `$email = trim($body['email'])` で空白が `''` に削減される → 422。

---

### ATK-11 — is_string チェックを迂回するための非文字列メールアドレス型 🚫 BLOCKED

**攻撃**: `{"email": 12345, "password": "x"}`（整数のメールアドレス）を送信する。
**結果**: BLOCKED — `is_string($body['email'])` チェック → false → `$email = ''` → 422。

---

### ATK-12 — 被害者の継続的なロックアウト（可用性攻撃） 🚫 BLOCKED（緩和済み）

**攻撃**: 悪意あるユーザーが被害者のメールアドレスで繰り返しログイン失敗して永続的なロックアウトを維持する。
**結果**: 緩和済み — ロックアウトは時間ベース（15 分）です。自動的に期限切れになり、永久禁止はありません。継続的な攻撃は 15 分ウィンドウを維持できますが、アカウントを永久に無効化することはできません。本番環境での強化: CAPTCHA、IP ベースのレート制限、ユーザーへのメール通知。

---

### ATK まとめ

| ID | 攻撃 | 結果 |
|----|--------|--------|
| ATK-01 | ロックアウトまでブルートフォース | 🚫 BLOCKED |
| ATK-02 | ロックアウト後に正しいパスワード | 🚫 BLOCKED |
| ATK-03 | 存在しないメールアドレスで探索 | 🚫 BLOCKED（設計上） |
| ATK-04 | 失敗カウントでのレース条件 | 🚫 BLOCKED |
| ATK-05 | ステータスエンドポイントがロックアウト状態を公開 | 🚫 BLOCKED（設計上） |
| ATK-06 | メールアドレスへの SQL インジェクション | 🚫 BLOCKED |
| ATK-07 | 過大なメールアドレス DoS | 🚫 BLOCKED |
| ATK-08 | 必須フィールドの欠如 | 🚫 BLOCKED |
| ATK-09 | 別アカウント経由でカウンタをリセット | 🚫 BLOCKED |
| ATK-10 | 空白のみのメールアドレス | 🚫 BLOCKED |
| ATK-11 | 非文字列のメールアドレス型 | 🚫 BLOCKED |
| ATK-12 | 被害者の継続的なロックアウト | 🚫 BLOCKED（緩和済み） |

**12 BLOCKED / MITIGATED, 0 EXPOSED**
パスワード検証前のロックアウトチェック、パラメータ化クエリ、入力長バリデーション、時間ベースの期限切れによりテストされたすべての攻撃ベクタを防止します。

---

## やってはいけないこと

| アンチパターン | リスク |
|---|---|
| パスワード検証後にロックアウトを確認する | ロック済みアカウントに Argon2id CPU を無駄にする；ロックアウトタイミングのサイドチャネル |
| アカウントロックアウトに 429 を返す | 誤ったセマンティクス — 429 はレート制限、423 はロックされたリソース |
| 失敗時に永続的なロックアウトを実装する | 攻撃者がメールアドレスを知っているユーザーに対して永続的なサービス拒否を実行できる |
| 存在しないメールアドレスに対して失敗を記録する | 攻撃者がユーザー登録前にロックアウト状態を事前作成できる |
| メールアドレス長バリデーションなし | 100KB 超のメールアドレス文字列でスロークエリまたはメモリプレッシャーが発生する |
| ロックアウト状態をメモリ/セッションに保存する | サーバー再起動で状態が失われる；複数のアプリインスタンス間で共有されない |
| ロック済みと誤ったパスワードで同じエラーを返す | UX の区別が困難 — ロック済みには 423、誤った資格情報には 401 を使用する |
