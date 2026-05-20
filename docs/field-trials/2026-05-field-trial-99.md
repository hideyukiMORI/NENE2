# Field Trial 99 — CSRF-like Patterns (Idempotency Key)

**Date:** 2026-05-21
**Project:** `/home/xi/docker/NENE2-FT/csrflog/`
**NENE2 version:** 1.5.32
**Theme:** CSRF 的なパターン — 冪等性トークン（Idempotency-Key）・二重送信防止・CORS ≠ CSRF 保護の検証

---

## What was built

注文作成 API に `Idempotency-Key` ヘッダーを導入し、二重送信（ネットワークリトライによる重複注文）を防止するパターンを検証。NENE2 の CorsMiddleware が CSRF 保護として機能するかも確認した。

---

## Findings

### 1. Idempotency-Key による二重送信防止は全て手実装が必要（中）

NENE2 に冪等性サポートは組み込まれていない。`Idempotency-Key` ヘッダーの検証・ストレージ照合・リプレイ応答を全てアプリ層で実装する必要がある。

実装パターン:

```php
// 1. ヘッダー取得と必須チェック
$key = trim($request->getHeaderLine('Idempotency-Key'));
if ($key === '') {
    return $problems->create($request, 'missing-idempotency-key', ..., 422, ...);
}

// 2. 既存エントリ検索（DB の UNIQUE 制約と組み合わせ）
$existing = $repo->findByIdempotencyKey($key);
if ($existing !== null) {
    return $json->create($existing->toArray(), 200); // リプレイ → 200
}

// 3. 作成（UNIQUE 違反でレースコンディション対応）
try {
    $order = $repo->create($key, ...);
    return $json->create($order->toArray(), 201);
} catch (DatabaseConstraintException) {
    $existing = $repo->findByIdempotencyKey($key);
    return $json->create($existing->toArray(), 200);
}
```

**DX観点:** Stripe や GitHub など有名 API が採用するパターンだが、NENE2 はドキュメント・ヘルパーともに存在しない。「同じリクエストを2回送ったとき最初の結果が返る」という挙動を初心者が自力で設計するのは難しい。

---

### 2. リプレイ時はボディの内容を無視する（設計上の重要な決定）

同一の `Idempotency-Key` で異なるボディ（量・価格変更）を送っても、最初の注文内容が返る。

```
POST /orders  Idempotency-Key: uuid-123  body: {item: "Widget", quantity: 1, price: 9.99}
→ 201 Created  {id: 1, quantity: 1, ...}

POST /orders  Idempotency-Key: uuid-123  body: {item: "Widget", quantity: 99, price: 0.01}
→ 200 OK  {id: 1, quantity: 1, ...}  ← 元の注文が返る
```

これは意図した設計（キーが一致すれば同一リクエストとみなす）だが、ドキュメントなしでは初心者が「なぜ quantity が変わらないのか」と混乱する可能性がある。

---

### 3. CORS ≠ CSRF 保護（重要な誤解ポイント）（高）

**「NENE2 に CORS の設定があるから CSRF は防げている」は誤り。**

テスト確認:
- `Origin: https://evil.example.com` を付けたリクエスト → **201 Created**（ブロックされない）
- `Origin` ヘッダーなしのリクエスト（curl 等）→ **201 Created**（ブロックされない）

NENE2 の `CorsMiddleware` は「ブラウザがレスポンスを読み取れるかどうか」を制御するだけ。攻撃者が `fetch()` や curl で `Origin` ヘッダーを偽装すれば、CORS は突破される。

**JSON API が CSRF に比較的強い理由（設計上の保護）:**
- `Content-Type: application/json` を要求するリクエストはブラウザの「シンプルリクエスト」にならない
- プリフライト（OPTIONS）が必要になり、CORS allowlist で制御可能
- しかし curl や fetch は `Content-Type: application/json` を自由に付けられる

**真の CSRF 保護が必要な場合:**
1. Bearer JWT（`Authorization` ヘッダー）— Cookie を使わないため CSRF 対象外
2. `SameSite=Strict` Cookie + Origin ヘッダー検証
3. アプリ層の Origin 強制ミドルウェア

NENE2 は Bearer JWT 認証を標準提供しており、Cookie を使わない設計なら CSRF は本質的に問題にならない。ただし Cookie ベースの認証を使う場合は別途対策が必要。

**DX観点 (初心者目線):** 「CORS を設定した = CSRF 対策が完了した」という誤解は非常によくある。NENE2 の howto に「JSON API と CSRF のリスクモデル」を明記することで、初心者が誤った安心感を持たずに済む。

---

### 4. `Idempotency-Key` ヘッダー名の大文字小文字（摩擦なし）

PSR-7 はヘッダー名を case-insensitive で扱う。`getHeaderLine('Idempotency-Key')` は `idempotency-key` も `IDEMPOTENCY-KEY` も同じ値を返す。NENE2 の PSR-7 実装（Nyholm）も同様。

---

## Test results

15 tests, 30 assertions — all pass.

Key behaviors confirmed:
- `Idempotency-Key` なし → 422
- 同じキーを2回送信 → 1回目 201、2回目 200（同じ order ID）
- 同じキーで body 変更 → 1回目の内容が保持される
- 異なるキー → 別々の注文が作成される
- `Origin: evil.example.com` → **ブロックされない**（CORS ≠ CSRF 保護を文書化）
- `Origin` なし → ブロックされない（curl 等のアクセスも通る）

---

## Developer Experience (DX) Review

### 初心者・ロースキル観点での実装しやすさ

冪等性の概念自体は難しくないが、NENE2 にヘルパーがないため「どのヘッダーを使うか」「リプレイ時に何を返すか」「レースコンディションをどう扱うか」を全て自分で設計する必要がある。

### 使ってみた印象

`$request->getHeaderLine('Idempotency-Key')` でヘッダーが取れるのは直感的で良い。DB に UNIQUE 制約を付けてレースコンディション対応を `DatabaseConstraintException` でキャッチするパターンも綺麗にはまった。

### 楽しいか・気持ちいいか・快適か

冪等性トークンの動作を「同じキーを2回送ると同じ結果」というテストで確認できるのは面白い。CORS と CSRF の誤解を実験で確認できたのも学びになった。

### 簡単か

冪等性の実装は比較的単純。CSRF の考え方は CORS と混同しやすく、概念の整理が必要。

### また使いたいか

はい。冪等性トークンパターンは NENE2 の JSON API 設計と相性が良い。

### 初心者に勧めたいか

はい、ただし「CORS ≠ CSRF 保護」の誤解を解くドキュメントが必要。状態変更 API には `Idempotency-Key` を推奨するガイドがあれば、初心者でも安全な API を設計できる。

---

## Issues / PRs

- Issue: `docs/howto/idempotency.md` — Idempotency-Key パターンの完全実装例（二重送信防止・リレースコンディション対応）
- Issue: `docs/howto/csrf-and-json-api.md` — CORS ≠ CSRF 保護の明記・JSON API のリスクモデル・Bearer JWT の CSRF 免疫性
