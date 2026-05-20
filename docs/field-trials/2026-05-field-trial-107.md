# Field Trial 107 — Rate Limiting (ThrottleMiddleware)

**Date:** 2026-05-21
**Project:** `/home/xi/docker/NENE2-FT/throttlelog/`
**NENE2 version:** 1.5.40
**Theme:** レートリミット — `ThrottleMiddleware` による固定ウィンドウ制限、`X-RateLimit-Limit/Remaining/Reset` ヘッダー、429 Too Many Requests、IP ベース vs カスタム keyExtractor（認証ユーザー・API キー）、プロダクション要件（共有ストレージ）。

---

## What was built

ノート API（GET /notes・POST /notes）に `ThrottleMiddleware` を組み込み、レートリミットの挙動を検証した。

---

## Findings

### 1. `throttleMiddleware:` パラメータ名がドキュメントに明記されていない（摩擦あり）

`RuntimeApplicationFactory` には `$middlewares` ではなく `$throttleMiddleware` という専用パラメータがある。PHP の名前付き引数で渡すとき `middlewares:` と書くとエラーになる:

```php
// ❌ パラメータ名が違う — エラー
new RuntimeApplicationFactory($psr17, $psr17, middlewares: [$throttle], ...);

// ✅ 正しい専用パラメータ
new RuntimeApplicationFactory($psr17, $psr17, throttleMiddleware: $throttle, ...);
```

`composer install` して `vendor/hideyukimori/nene2/src/Http/RuntimeApplicationFactory.php` を確認するか、IDE の補完を使えば分かるが、howto や README に記載がなければ初見では気付けない。

---

### 2. `InMemoryRateLimitStorage` の「プロセス間共有なし」警告

PHPDoc に明記されているが、初心者がコピペすると本番環境でも `InMemoryRateLimitStorage` を使ってしまう:

```php
// ⚠️ 本番では使えない — PHP-FPM ワーカー間で状態が共有されない
$storage = new InMemoryRateLimitStorage();

// ✅ 本番では Redis / Memcached / DB 実装を注入する
$storage = new RedisRateLimitStorage($redisClient);
```

`RateLimitStorageInterface` を実装すれば任意のバックエンドを使える設計は正しい。ただし NENE2 は Redis 実装を同梱していないため、プロダクション利用者が自前で実装する必要がある。

---

### 3. リバースプロキシ背後では `REMOTE_ADDR` が信頼できない

デフォルトの keyExtractor は `REMOTE_ADDR` を使うが、リバースプロキシ背後ではプロキシの IP になる:

```php
// ❌ すべてのクライアントが同じバケットを共有してしまう
$throttle = new ThrottleMiddleware($problems, $storage, limit: 60);

// ✅ X-Forwarded-For を信頼する場合（プロキシが制御下にある場合のみ）
$throttle = new ThrottleMiddleware(
    $problems,
    $storage,
    keyExtractor: static fn ($r): string
        => $r->getHeaderLine('X-Forwarded-For') ?: $r->getServerParams()['REMOTE_ADDR'] ?? 'unknown',
);
```

PHPDoc にこの警告が書かれているが、実際に問題が起きるまで気付かないケースが多い。

---

### 4. カスタム keyExtractor で認証ユーザーベース制限

IP ではなく認証ユーザー（または API キー）単位で制限できる:

```php
$throttle = new ThrottleMiddleware(
    $problems,
    $storage,
    limit: 100,
    windowSeconds: 3600,
    keyExtractor: static fn ($r): string => 'user:' . ($r->getAttribute('user_id') ?? 'anonymous'),
);
```

これにより:
- 同一 IP から複数ユーザーが接続する環境（オフィス NAT）で公平な制限が可能
- 認証されていないリクエストは `anonymous` バケットに入り、より厳しく制限できる

---

### 5. 固定ウィンドウアルゴリズムの境界問題

固定ウィンドウは「ウィンドウ境界前後の集中リクエスト」に弱い:

```
制限: 100 req/分
ウィンドウ: :00〜:59

:59 に 100 req → 制限に達する
:00 に 100 req → 新しいウィンドウ、また 100 req 許可

→ 2秒間に 200 req が通ることになる
```

スライディングウィンドウや Token Bucket アルゴリズムならこの問題は発生しない。`ThrottleMiddleware` は固定ウィンドウのため、高精度な制限が必要な場合は注意が必要。

---

## Test results

9 tests, 33 assertions — all pass.

Key behaviors confirmed:
- 200 レスポンスに `X-RateLimit-Limit`/`Remaining`/`Reset` が付く
- リクエストごとに `X-RateLimit-Remaining` が減る
- 制限超過で 429 + `Retry-After` ヘッダー
- 429 レスポンスは Problem Details 形式（`too-many-requests` タイプ）
- 異なる IP は別バケット
- カスタム keyExtractor（API キーベース）で別バケット
- GET と POST は同じバケットからカウント
- `X-RateLimit-Reset` は Unix タイムスタンプ
- 制限内のリクエストは正常に通る

---

## Developer Experience (DX) Review

### ペルソナ1: 初心者（プログラミング歴1.5年・PHP 独学・女性・バックエンド志望）

**概念理解:** レートリミットの目的（ブルートフォース防止・DDoS 軽減・公平利用）は理解しやすい。「1分間に何回まで」という制限は直感的。

**`InMemoryRateLimitStorage` の落とし穴:** 「動いた！」で満足してしまい、本番環境でも使い続けるリスクが高い。PHP-FPM の複数プロセスで状態が共有されない問題は、開発環境では再現しにくい。「本番では Redis が必要」という注記を howto に大きく書く必要がある。

**`throttleMiddleware:` パラメータ名:** IDE 補完なしでは判断できない。howto にコピペできるサンプルコードがあれば迷わない。

**事故リスク:** 高。`InMemoryRateLimitStorage` を本番で使うのは「一見動く」サイレントバグ。

---

### ペルソナ2: ロースキル経験者（PHP 歴4年・受託 Web 開発・男性・SES）

**コピペ可能性:** `ThrottleMiddleware` の基本使用法は引数が明確でコピペしやすい。`$storage = new InMemoryRateLimitStorage()` を本番でそのまま使うパターンには要注意。

**リバースプロキシ問題:** Nginx 背後でのデプロイが多いため、`REMOTE_ADDR` がプロキシの IP になる問題に直面する可能性が高い。「なぜ全員が同じ制限を共有するのか」のデバッグが難しい。

**事故リスク:** 中〜高。リバースプロキシ設定ミスはプロダクションの問題として表面化する。

---

### ペルソナ3: フロントエンド寄り経験者（React/TS 歴4年・フルスタック転向中・ノンバイナリ）

**クライアントサイドの対応:** 429 レスポンスを受け取った場合の `Retry-After` ヘッダーを使った再試行ロジックは実装しやすい。`X-RateLimit-Remaining` を監視してリクエストを間引くパターンも理解できる。

**API キーベース制限:** カスタム keyExtractor の存在は嬉しい設計。フロントからは `X-Api-Key` ヘッダーを送るだけでよい。

**事故リスク:** 低。クライアント側の 429 対応は一般的なパターン。

---

### ペルソナ4: バックエンド経験者（Laravel 歴6年・男性・リードエンジニア）

**他フレームワークとの差異:** Laravel の `throttle` ミドルウェアは Redis をデフォルトで使い、キャッシュドライバーを通じて設定する。NENE2 の `RateLimitStorageInterface` は明示的な DI だが、Redis 実装を自分で書く必要がある。

**固定ウィンドウの弱点:** Laravel の ThrottleRequests は固定ウィンドウ + プロバイダー差し替えで Redis Sliding Window も選べる。NENE2 は固定ウィンドウのみ。

**`keyExtractor` の型安全性:** `\Closure(ServerRequestInterface): string` という型付きクロージャは正確で良い。PHPStan でも問題なく解析される。

**事故リスク:** 低。ただしプロダクション用の Redis 実装が未同梱なのは不便。

---

### ペルソナ5: シニアエンジニア（設計・コードレビュー担当・女性・12年）

**コードレビューポイント:**
1. `InMemoryRateLimitStorage` を本番コードで使っていないか
2. `REMOTE_ADDR` がリバースプロキシ背後で正しいか
3. `keyExtractor` が認証済みユーザー単位でキーを生成しているか（IP 単位だと公平性の問題がある）
4. `windowSeconds` と `limit` の値がユースケースに合っているか（ログインは厳しく、GET は緩く、など）
5. `X-Forwarded-For` を信頼する場合、プロキシが信頼できる経路にあるか

**スケール時の問題:** 固定ウィンドウのバースト問題は高トラフィック環境で顕在化する。必要なら Token Bucket/Sliding Window の実装を注入する設計にすることを検討。

---

### ペルソナ6: 設計者・ポリシー照合（NENE2 設計ポリシー目線）

**ポリシー整合:**
- `RateLimitStorageInterface` を使った DI は「フレームワークマジックでコントロールフローを隠さない」方針と整合
- `ThrottleMiddleware` を `RuntimeApplicationFactory` の専用パラメータで受け取る設計は明示的

**設計上のギャップ:**
1. `throttleMiddleware:` パラメータ名が howto に未記載 → サンプルコードで補完
2. プロダクション用 Redis 実装が未同梱 → `RateLimitStorageInterface` の実装例を howto に示す
3. 固定ウィンドウアルゴリズムのバースト問題の説明が不足

---

## Issues / PRs

- Issue: `docs/howto/rate-limiting.md` — ThrottleMiddleware の使い方・InMemoryRateLimitStorage の本番制限・リバースプロキシ設定・カスタム keyExtractor・固定ウィンドウの弱点
