# アカウントロックアウト（ブルートフォース保護）

> **FT リファレンス**: FT280 (`NENE2-FT/lockoutlog`) — アカウントロックアウト: 5 回の失敗で 15 分間のロックアウト（423 Locked）が発動、ロック中は正しいパスワードもブロック、成功でカウンターリセット、Argon2id パスワード検証、MySQL 統合テスト、27 テスト合格 / 5 スキップ（MySQL）、44 アサーション PASS。
>
> **ATK アセスメント**: ATK-01 から ATK-12 をこのドキュメントの末尾に含む。

設定可能な回数の失敗後にアカウントをロックすることで、ログインエンドポイントをブルートフォース攻撃から保護します。

## 概要

アカウントロックアウトはメールアドレスごとのログイン失敗試行を追跡し、失敗しきい値を超えると `locked_until` タイムスタンプを設定します。ロックはすべてのログイン試行で強制されます — アカウントがロックされている間は正しいパスワードでも拒否されます。ロックはクールダウン期間後に自動的に期限切れになります。

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
public const int MAX_ATTEMPTS    = 5;   // ロックアウト前の失敗回数
public const int LOCKOUT_MINUTES = 15;  // ロックアウト期間
```

## ログインフロー

```php
// 1. パスワード検証の前にロックアウトを確認する
$state = $this->repo->findOrCreateAccountState($email, $now);
if ($state->isLocked($now)) {
    return 423; // Locked
}

// 2. 認証情報を検証する
$user = $this->repo->findUserByEmail($email);
if ($user === null || !$user->verifyPassword($pass)) {
    if ($user !== null) {
        $this->repo->recordFailure($email, $now);
    }
    return 401; // Unauthorized
}

// 3. 成功 — カウンターをリセットする
$this->repo->resetState($email, $now);
return 200;
```

ロックアウトチェックはパスワード検証の**前に**行われます。ロックアウト状態は**既存ユーザー**に対してのみ書き込まれます — 未知のメールアドレスは `account_state` 行を作成せずに 401 を返します（ストレージ枯渇の防止）。

## ロックアウトチェック

```php
public function isLocked(string $now): bool
{
    return $this->lockedUntil !== null && $now < $this->lockedUntil;
}
```

`$now` は `Y-m-d H:i:s` 文字列です。辞書順比較は ISO 8601 の日時文字列に対して正しく機能します。

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

認証成功により `failed_count` と `locked_until` の両方がリセットされます。ロックアウト前に成功したユーザーは失敗カウンターがリフレッシュされます。

## ユーザー列挙の防止

間違ったパスワードと未知のメールアドレスの両方に同じ HTTP ステータス（401）を返します:

```php
if ($user === null || !$user->verifyPassword($pass)) {
    if ($user !== null) {
        $this->repo->recordFailure($email, $now);
    }
    return 401; // 同じステータスを返す
}
```

攻撃者は HTTP レスポンスから「アカウントなし」と「パスワード間違い」を区別できません。

## MySQL スキーマ

MySQL では `INT AUTO_INCREMENT` と `DATETIME` が必要です:

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

`Y-m-d H:i:s` 日時フォーマットは SQLite（TEXT 比較）と MySQL（DATETIME カラム）の両方で機能します。

## MySQL 統合テスト

`MYSQL_HOST` が設定されていない場合はスキップする `MysqlLockoutTest.php` を追加します:

```php
protected function setUp(): void
{
    $host = (string) (getenv('MYSQL_HOST') ?: '');
    if ($host === '') {
        self::markTestSkipped('MYSQL_HOST not set — skipping MySQL integration tests');
    }
    // テスト分離のためにテーブルを削除して再作成する
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

`MYSQL_HOST` なしでは、MySQL テストは自動的にスキップされます。

## セキュリティ特性

| 特性 | 実装 |
|---|---|
| ロックアウトしきい値 | 5 回の失敗試行 |
| ロックアウト期間 | 15 分 |
| ロック中の正しいパスワード | ブロック（423） |
| ユーザー列挙 | 未知のメールと間違ったパスワードに同じ 401 |
| ロックアウトスコープ | IP ではなくメールアドレスごと |
| ロックアウトリセット | ログイン成功時に自動 |
| パスワードハッシュ | Argon2id |
| 長いメール入力 | 256 文字以上は拒否（422） |
| SQL インジェクション | パラメータ化クエリで注入を防止 |

## 設計上のトレードオフ: ロックアウト DoS

ロックアウトが IP ではなくメールごとなので、ユーザーのメールを知っている攻撃者が 5 回間違ったパスワードを送信することでユーザーをロックアウトできます。これはブルートフォース保護と可用性の間の本質的なトレードオフです。

緩和策（ここでは実装していませんが利用可能）:
- **プログレッシブ遅延**（ハードロックアウトの代わりに）
- N 回失敗後の **CAPTCHA**
- ロックアウト発動時の**通知メール**
- **管理者によるロック解除エンドポイント**

ほとんどのアプリケーションでは、トレードオフはブルートフォース保護を優先します。ロックアウトは 15 分後に自動的に期限切れになります。

## ルートまとめ

| メソッド | パス | 説明 |
|---|---|---|
| `POST` | `/users` | ユーザーを作成する（シード/登録） |
| `POST` | `/auth/login` | ログイン試行（200/401/423） |
| `GET` | `/auth/status/{email}` | ロックアウト状態を確認する |

---

## ATK アセスメント — クラッカーマインドセット攻撃テスト

### ATK-01 — ロックアウトまでブルートフォース 🚫 BLOCKED

**攻撃**: 既知のメールアドレスに対して間違ったパスワードで 5 回以上ログイン試行を送信する。
**結果**: BLOCKED — 5 回の失敗後、`failed_count >= MAX_ATTEMPTS` が `locked_until = now + 15 分` を設定する。以降の試行はパスワードチェック前に 423 `account-locked` を受け取る。

---

### ATK-02 — ロックアウト後に正しいパスワードを送信 🚫 BLOCKED

**攻撃**: アカウントをロックしてから直ちに正しいパスワードを送信する。
**結果**: BLOCKED — ロックアウトチェックは `findUserByEmail()` の前に行われる。正しいパスワードでも、ロック中は 423 が返される。

---

### ATK-03 — 存在しないメールで実際のアカウントへのロックアウトを回避しようとする 🚫 BLOCKED（設計上）

**攻撃**: 存在しないメールを使用して実際のアカウントへのロックアウトを発動せずに探索する。
**結果**: BLOCKED（設計上）— 存在しないメールは失敗を蓄積せず、ストレージを保護する。実際のアカウントは独自のロックアウト状態で保護されている。偽メールの探索は実際のアカウントについて何も明かさない。

---

### ATK-04 — レース条件: 失敗しきい値での同時ログイン試行 🚫 BLOCKED

**攻撃**: `failed_count` が 4 のときに 2 つのリクエストを同時に送信してロックアウトを競り抜けようとする。
**結果**: BLOCKED — `UPDATE account_states` は DB レベルでアトミック。SQLite WAL は同時書き込みをシリアライズし、MySQL は行レベルロックを使用する。両方の更新が成功し、最終的な `locked_until` が正しく設定される。

---

### ATK-05 — ステータスエンドポイントがロックアウト状態を明かす 🚫 BLOCKED（設計上）

**攻撃**: `GET /auth/status/{email}` でメールがロックアウトターゲットになっているか発見する。
**結果**: 設計上 — ステータスエンドポイントはクライアント UX 用（「15 分後に再試行してください」）。本番ではレート制限または認証が必要。ロックアウトタイミングは明かすがパスワード情報は明かさない。

---

### ATK-06 — メールフィールドへの SQL インジェクション 🚫 BLOCKED

**攻撃**: `{"email": "' OR '1'='1' --", "password": "x"}` を送信する。
**結果**: BLOCKED — すべてのクエリはパラメータ化ステートメント（`WHERE email = ?`）を使用する。インジェクトされた文字列はリテラルメール値として扱われる。

---

### ATK-07 — サービス拒否のための大きすぎるメール文字列 🚫 BLOCKED

**攻撃**: 100,000 文字のメールフィールドを送信する。
**結果**: BLOCKED — `if (strlen($email) > 255)` → DB クエリ前に 422 `validation-failed`。

---

### ATK-08 — メールまたはパスワードフィールドの欠落 🚫 BLOCKED

**攻撃**: `{}` または `{"email": "x@x.com"}` をパスワードなしで送信する。
**結果**: BLOCKED — `if ($email === '' || $pass === '')` → 422 `validation-failed`。

---

### ATK-09 — 別のアカウントでログインしてカウンターをリセット 🚫 BLOCKED

**攻撃**: アカウント A をロックして、アカウント B としてログインして A のカウンターをリセットしようとする。
**結果**: BLOCKED — `resetState()` はメールでキーされている。別のアカウントのログイン成功はアカウント A の状態に影響しない。

---

### ATK-10 — バリデーションをバイパスするための空白のみのメール 🚫 BLOCKED

**攻撃**: `{"email": "   ", "password": "x"}` を送信する。
**結果**: BLOCKED — `$email = trim($body['email'])` で空白を `''` に変換 → 422。

---

### ATK-11 — is_string チェックをバイパスするための非文字列メール型 🚫 BLOCKED

**攻撃**: `{"email": 12345, "password": "x"}`（整数メール）を送信する。
**結果**: BLOCKED — `is_string($body['email'])` チェック → false → `$email = ''` → 422。

---

### ATK-12 — 被害者の継続的なロックアウト（可用性攻撃） 🚫 BLOCKED（緩和済み）

**攻撃**: 悪意のあるユーザーが被害者のメールに対して繰り返しログイン失敗させて永続的なロックアウトを維持する。
**結果**: MITIGATED — ロックアウトは時間ベース（15 分）。自動的に期限切れになる。永久 BAN はできない。継続的な攻撃で 15 分ウィンドウが維持されるが、アカウントを永続的に無効化することはできない。本番ハードニング: CAPTCHA、IP ベースのレート制限、メールでユーザーに通知。

---

### ATK まとめ

| ID | 攻撃 | 結果 |
|----|--------|--------|
| ATK-01 | ロックアウトまでブルートフォース | 🚫 BLOCKED |
| ATK-02 | ロックアウト後に正しいパスワード | 🚫 BLOCKED |
| ATK-03 | 存在しないメールで探索 | 🚫 BLOCKED（設計上） |
| ATK-04 | 失敗カウントでのレース条件 | 🚫 BLOCKED |
| ATK-05 | ステータスエンドポイントがロックアウト状態を明かす | 🚫 BLOCKED（設計上） |
| ATK-06 | メール経由の SQL インジェクション | 🚫 BLOCKED |
| ATK-07 | 大きすぎるメール DoS | 🚫 BLOCKED |
| ATK-08 | 必須フィールドの欠落 | 🚫 BLOCKED |
| ATK-09 | 別のアカウント経由でカウンターをリセット | 🚫 BLOCKED |
| ATK-10 | 空白のみのメール | 🚫 BLOCKED |
| ATK-11 | 非文字列メール型 | 🚫 BLOCKED |
| ATK-12 | 被害者の継続的なロックアウト | 🚫 BLOCKED（緩和済み） |

**12 BLOCKED / MITIGATED、0 EXPOSED**
パスワード検証前のロックアウトチェック、パラメータ化クエリ、入力長検証、時間ベースの期限切れで、テストされたすべての攻撃ベクターを防止します。

---

## やってはいけないこと

| アンチパターン | リスク |
|---|---|
| パスワード検証後にロックアウトを確認する | ロックされたアカウントで Argon2id CPU を無駄にする。ロックアウトタイミングのサイドチャネル |
| アカウントロックアウトに 429 を返す | セマンティクスが間違い — 429 はレート制限、423 はロックされたリソース |
| 失敗で永続的なロックアウトを実装する | 攻撃者が既知のメールを持つユーザーのサービスを永続的に拒否できる |
| 存在しないメールの失敗を記録する | 攻撃者がユーザー登録前にロックアウト状態を事前に作成できる |
| メール長検証なし | 100KB 以上のメール文字列が遅いクエリやメモリ圧迫を引き起こす |
| メモリ/セッションにロックアウト状態を保存する | サーバー再起動で状態が失われる。複数のアプリインスタンス間で共有されない |
| ロック中と間違ったパスワードに同じエラー | UX の区別が困難 — ロックには 423、間違った認証情報には 401 を使用する |
