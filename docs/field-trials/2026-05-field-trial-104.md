# Field Trial 104 — Webhook Signature Verification (hmaclog)

**Date:** 2026-05-21
**Project:** `/home/xi/docker/NENE2-FT/hmaclog/`
**NENE2 version:** 1.5.37
**Theme:** Webhook シグネチャ検証 — HMAC-SHA256 署名付き Webhook の受信パターン。`hash_equals()` によるタイミングセーフ比較、タイムスタンプ窓によるリプレイ攻撃防止、ペイロード改ざん検出を検証。

---

## What was built

外部サービス（Stripe/GitHub 風）から送られる Webhook を受信する API を実装した。

- シグネチャヘッダー形式: `X-Webhook-Signature: t=<timestamp>,v1=<hmac-hex>`
- 署名対象: `"<timestamp>.<rawBody>"` — タイムスタンプとボディを連結して署名
- アルゴリズム: HMAC-SHA256
- タイムスタンプ窓: ±300秒（5分）でリプレイ攻撃を防止
- 比較: `hash_equals()` によるタイミングセーフな文字列比較

---

## Findings

### 1. `hash_equals()` vs `===` — タイミング攻撃の罠（最重要・高）

最も重要な発見はシグネチャ比較にある。

**誤ったパターン（通常の文字列比較）:**

```php
// ❌ タイミング攻撃に脆弱
if ($expectedSig === $receivedSig) { ... }
```

PHP の `===` は文字列の先頭から順に比較し、最初の不一致で即座に終了する。攻撃者はレスポンスタイムを測定することで「何文字目まで正しいか」を推測でき、1バイトずつシークレットをブルートフォースできる（タイミング攻撃）。

**正しいパターン（定数時間比較）:**

```php
// ✅ hash_equals() は常に全文字を比較 — 比較時間が一致文字数に依存しない
if (!hash_equals($expectedSig, $receivedSig)) {
    throw new SignatureException('Signature mismatch.');
}
```

`hash_equals()` は PHP 5.6 から組み込みで、2つの文字列が異なる場合でも同じ時間で比較を完了する。

**DX観点:** この差はドキュメントや型エラーでは気づけない。`===` で書いても PHPStan はエラーを出さず、テストも通過する。「セキュリティの知識がない場合に絶対に踏む罠」。

---

### 2. タイムスタンプをペイロードに含める設計（摩擦なし）

署名対象を `"<timestamp>.<rawBody>"` とすることで、タイムスタンプ改ざんを防ぐ:

```php
private function computeSignature(int $timestamp, string $rawBody): string
{
    return hash_hmac('sha256', "{$timestamp}.{$rawBody}", $this->secret);
}
```

もしタイムスタンプとシグネチャを別々に検証するなら:

```php
// ❌ 脆弱なパターン: 攻撃者がタイムスタンプを変更してリプレイできる
$sig = hash_hmac('sha256', $rawBody, $secret);
if ($receivedSig === $sig && abs(time() - $timestamp) < 300) { ... }
// → 攻撃者は古いシグネチャを使いつつタイムスタンプを現在時刻に変更できる
```

タイムスタンプを署名対象に含めることで、タイムスタンプを変更するとシグネチャが無効になる。これは Stripe の実際の実装と同じアプローチ。

---

### 3. ペイロード改ざん検出（摩擦なし）

HMAC がカバーするのはボディ全体なので、1バイトでも変更するとシグネチャが無効になる:

```php
// 送信者が正規ボディで署名
$originalBody = '{"event_type":"order.created","amount":100}';
$sigHeader = $verifier->sign($originalBody, $timestamp);

// 攻撃者がボディを改ざん（金額を増やす）
$tamperedBody = '{"event_type":"order.created","amount":9999}';
// → シグネチャが一致しないため 401 が返る
```

テストで確認済み。

---

### 4. `WebhookVerifier::sign()` を同じクラスに持つ設計（DX 向上）

検証（`verify()`）と署名生成（`sign()`）を同じクラスに持つことで、テストでの署名付きリクエスト構築が簡単になる:

```php
// テストヘルパーで署名済みリクエストを構築
private function signedPost(array $payload, int $timestamp, string $secret = self::SECRET): ResponseInterface
{
    $rawBody   = (string) json_encode($payload, JSON_THROW_ON_ERROR);
    $verifier  = new WebhookVerifier($secret);
    $sigHeader = $verifier->sign($rawBody, $timestamp);
    // ...
}
```

本番コードでは送信者側も同じ `WebhookVerifier` を使って Webhook を署名できる。

---

### 5. リプレイ攻撃防止のテスト戦略（摩擦なし）

タイムスタンプが古い場合のテストは、`time() - 301` を使う:

```php
public function testExpiredTimestampReturns401(): void
{
    $oldTimestamp = $this->now() - 301; // 5分1秒前
    $res = $this->signedPost(['event_type' => 'order.created'], $oldTimestamp);
    $this->assertSame(401, $res->getStatusCode());
}
```

`abs(time() - $timestamp)` を使うことで未来のタイムスタンプ（クロックスキュー）も同じロジックで扱える。

---

## Test results

13 tests, 23 assertions — all pass.

Key behaviors confirmed:
- 正規署名で 202 Accepted・イベント保存
- 複数 Webhook の蓄積
- シグネチャヘッダーなし → 401
- 間違ったシークレットで署名 → 401
- ペイロード改ざん → 401
- 300秒超の古いタイムスタンプ → 401（リプレイ防止）
- 300秒超の未来タイムスタンプ → 401
- 300秒以内の未来タイムスタンプ（クロックスキュー許容）→ 202
- ヘッダー形式不正 → 401
- 非数値タイムスタンプ → 401
- event_type なし → 400
- イベント一覧取得

---

## Developer Experience (DX) Review

### ペルソナ1: 初心者（プログラミング歴1.5年・PHP 独学・女性・バックエンド志望）

**タイミング攻撃の概念理解:** `===` と `hash_equals()` の違いを「タイミング攻撃」として説明されても、具体的な脅威モデルが想像しにくい。「攻撃者がミリ秒単位のレスポンスタイムを測定する」というシナリオは実感しにくく、「そんなことができるの？」と半信半疑になる。

**事故リスク:** 高。`===` で書いてもコードは動く。テストも通る。PHPStan もエラーを出さない。「正しいコードを書いた」と思い込んでしまう。`hash_equals()` の存在を知っていて初めて防御できる。

**ドキュメントの必要性:** `hash_equals()` を使うべき理由を「なぜ === はだめなのか」から説明する howto が必要。

---

### ペルソナ2: ロースキル経験者（PHP 歴4年・受託 Web 開発・男性・SES）

**コピペ可能性:** `WebhookVerifier` パターンをそのままコピーして使える。`hash_equals()` を使っているコードをコピーするので、コピー先では自動的に安全になる。ただし「なぜ `hash_equals()` なのか」を理解しないと、将来コードを触ったときに `===` に「最適化」してしまう危険がある。

**Stripe ドキュメントとの類似:** Stripe の Webhook 検証ドキュメントと同じパターンなので、Stripe 経験者は違和感なく使える。

---

### ペルソナ3: フロントエンド寄り経験者（React/TS 歴4年・フルスタック転向中・ノンバイナリ）

**エラーレスポンスの質:** `401 invalid-signature` は適切。ただし「なぜ 401 か（認証失敗）」と「なぜ 403 でないか（認可失敗）」の使い分けが気になる。Webhook 検証は「送信者の身元確認」なので 401 が正しい。Problem Details の `detail` に「Missing header」「Signature mismatch」「Expired timestamp」の具体的な理由が入るのは良い。

**クライアント側の実装:** JavaScript で Webhook を送信する場合は `crypto.subtle.sign()` で HMAC-SHA256 を計算する。Node.js なら `crypto.createHmac('sha256', secret).update(payload).digest('hex')` — これと NENE2 受信側の互換性確認テストがあると良い。

---

### ペルソナ4: バックエンド経験者（Laravel 歴6年・男性・リードエンジニア）

**他フレームワークとの差異:** Laravel には Webhook シグネチャ検証の組み込みはなく、`Illuminate\Http\Request::validateSignature()` は URL 署名専用。Webhook 検証は Stripe SDK やカスタムミドルウェアで実装するのが一般的。NENE2 の `WebhookVerifier` クラスはシンプルで採用しやすい設計。

**ミドルウェアとして切り出すべきか:** Webhook 専用エンドポイントが1つなら RouteRegistrar で検証して問題ない。複数エンドポイントが Webhook を受ける場合はミドルウェアに切り出すのが良い。今回の実装は単一エンドポイントなのでベストプラクティス通り。

**シークレットのローテーション:** シークレットがローテーションされる場合、複数バージョンのシグネチャを同時に受け入れる必要がある（移行期間）。`v1=<sig>` のバージョンプレフィックスはこの拡張を見越した設計。

---

### ペルソナ5: シニアエンジニア（設計・コードレビュー担当・女性・12年）

**セキュリティレビューポイント:**
1. `hash_equals()` が使われているか — `===` との差は PHPStan では検出不能、コードレビュー必須
2. 署名対象にタイムスタンプが含まれているか — 含まれていないとタイムスタンプ改ざんでリプレイ可能
3. エラーレスポンスが「何が間違っているか」を漏らしすぎていないか — 攻撃者に情報を与えない
4. ログに `rawBody` や `secret` が含まれていないか

**ログへの露出リスク:** `$this->verifier->verify($request, $rawBody)` で rawBody を渡すが、エラーログに rawBody を含めてはいけない（顧客データが入っている可能性）。今回の実装は例外メッセージにのみ情報を含め、rawBody はログに出力しない設計。

**IP 制限との組み合わせ:** シグネチャ検証 + Stripe/GitHub の IP レンジからのリクエストのみ許可、という多層防御が本番では推奨される。NENE2 の今の設計はシグネチャ検証のみ。

---

### ペルソナ6: 設計者・ポリシー照合（NENE2 設計ポリシー目線）

**ポリシー整合:**
- CLAUDE.md「フレームワークマジックでコントロールフローを隠さない」方針と整合: `WebhookVerifier` は明示的で、「どこで何をしているか」が読み取りやすい
- CLAUDE.md「境界で array のままにせず DTO / value object を使う」: `WebhookEvent` readonly class が適切
- エラーハンドリングは Problem Details (RFC 9457) に従っている
- `hash_equals()` を使う根拠がクラスコメントに書かれている（PHPDoc が役立っている）

**設計上のギャップ:**
1. `WebhookVerifier` は NENE2 コアに含まれていないため、利用者が自前実装するか、howto のコードをコピーする。よく使うパターンなので NENE2 が提供する価値がある
2. シークレットの管理（env var からの注入）のサンプルが howto にあると良い
3. 複数シークレット（ローテーション移行期）のサポートパターンが howto にあると良い

**howto: `docs/howto/webhook-signature.md`** — HMAC-SHA256 パターン・`hash_equals()` の必要性・タイムスタンプ窓・テストの書き方・シークレットローテーション

---

## Issues / PRs

- Issue: `docs/howto/webhook-signature.md` — Webhook シグネチャ検証パターン・`hash_equals()` vs `===`・タイムスタンプ窓・リプレイ防止・シークレット管理
