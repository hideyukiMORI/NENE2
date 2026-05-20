# Field Trial 101 — Nested JSON Input Validation

**Date:** 2026-05-21
**Project:** `/home/xi/docker/NENE2-FT/nestedlog/`
**NENE2 version:** 1.5.34
**Theme:** ネストした JSON バリデーション — 配列内オブジェクトのバリデーション・ドット記法エラーパス（`items.0.quantity`）・全エラー収集（fail-fast でない）

---

## What was built

注文（Order）に複数の明細（items）を持つ API を実装し、ネストした JSON のバリデーションを検証した。顧客名・明細配列・各明細内の product_id / quantity / unit_price を全て検証し、エラーを `items.0.quantity` のようなドット記法パスで返す。fail-fast ではなく全エラーを一括返却する。

---

## Findings

### 1. NENE2 にネスト JSON バリデーターは存在しない（高）

NENE2 は単純なフラットフィールドのバリデーションには何も提供しておらず、ネストした配列のバリデーションは完全に手実装が必要。

実装した `OrderValidator` の要点:

```php
// 配列の各要素を index 付きでバリデート
foreach ($body['items'] as $i => $item) {
    $prefix = "items.{$i}";

    if (!is_array($item)) {
        $errors[] = ['field' => $prefix, 'code' => 'must-be-object', ...];
        continue;
    }

    if (!isset($item['product_id']) || !is_int($item['product_id'])) {
        $errors[] = ['field' => "{$prefix}.product_id", 'code' => 'required', ...];
    } elseif ($item['product_id'] < 1) {
        $errors[] = ['field' => "{$prefix}.product_id", 'code' => 'min-value', ...];
    }
    // ... quantity, unit_price ...
}
```

**DX観点:** Symfony Validator や Laravel では `items.*.quantity` のような記法で一行で書けるが、NENE2 では `foreach` + 文字列連結で手書きする。慣れれば予測可能だが、初心者には「エラーパスをどう設計するか」が自明でない。

---

### 2. PHPStan level 8 — 判別共用体の絞り込みが必要（摩擦あり・中）

`OrderValidator::validate()` は成功時と失敗時で異なる shape を返す:

```php
// 成功: {errors: [], customer: string, note: string, items: list<...>}
// 失敗: {errors: list<error>}  ← customer/note/items なし
```

`if ($result['errors'] !== []) { return; }` で早期リターンした後も、PHPStan は `$result['customer']` が存在するか分からない（`offsetAccess.notFound`）。

**修正:** `@var` アノテーションで型を上書きする:

```php
if ($result['errors'] !== []) {
    return $this->problems->create(...);
}

/** @var array{customer: string, note: string, items: list<...>} $valid */
$valid = $result;
$order = $this->repo->create($valid['customer'], $valid['note'], $valid['items']);
```

**DX観点:** PHPStan の型推論は正しいが、この回避パターンを知っていないと詰まる。NENE2 が Validator インターフェースや DTO を提供すれば不要なボイラープレートが消える。

---

### 3. エラーを一括返却するパターン（fail-fast でない）

fail-fast（最初のエラーで即返す）は実装が簡単だが UX が悪い。ユーザーは何度もリクエストを送り直す必要がある。

全エラー収集パターン:

```php
$errors = [];
// ... 全フィールドをチェック ...
if ($errors !== []) {
    return $this->problems->create(..., ['errors' => $errors]);
}
```

NENE2 は `extensions` 配列に `errors` を入れるパターン（Problem Details RFC 9457 準拠）。これで全エラーが一括返却される:

```json
{
  "type": "https://nene2.dev/problems/validation-failed",
  "title": "Validation failed.",
  "status": 422,
  "errors": [
    {"field": "customer", "code": "required", "message": "Customer name is required."},
    {"field": "items.0.quantity", "code": "min-value", "message": "items.0.quantity must be ≥ 1."},
    {"field": "items.1.unit_price", "code": "min-value", "message": "items.1.unit_price must be > 0."}
  ]
}
```

---

### 4. `unit_price` の型 — JSON の数値は int/float 両方（摩擦なし）

JSON の `9.99` は PHP で `float`、`10` は `int` になる。どちらも許容するため `is_int($v) || is_float($v)` でチェックする:

```php
$rawPrice = $item['unit_price'] ?? null;
if (!is_int($rawPrice) && !is_float($rawPrice)) {
    $errors[] = [...]; // 文字列・null・bool はエラー
}
```

`0` (int) も `0.0` (float) も「価格なし」としてエラーにする。PHPStan の型推論が `is_int || is_float` パターンを正しく理解するので摩擦なし。

---

### 5. `array_is_list()` (PHP 8.1+) が便利

`$body['items']` が `{"0": ...}` のような連想配列でないことを確認するのに `array_is_list()` が使える:

```php
} elseif (!is_array($body['items']) || array_is_list($body['items']) === false) {
    $errors[] = ['field' => 'items', 'code' => 'must-be-array', ...];
```

PHP 8.1 以降で使えるが、NENE2 が PHP 8.4 要件なので問題なし。

---

## Test results

19 tests, 43 assertions — all pass.

Key behaviors confirmed:
- 有効な注文が 201 で作成される（複数明細・合計金額計算）
- GET /orders/{id} で明細付きで取得できる
- 顧客名: 必須・空白のみ・200字超過
- items: 必須・空配列
- items.0.product_id: 整数でない・0以下
- items.1.product_id: 負の値
- items.0.quantity: 0・欠損
- items.0/1.unit_price: 負・欠損・ゼロ
- 複数アイテムの複数エラーが一括返却される
- トップレベル + ネストエラーが一括返却される
- エラーコード (`min-value` 等) が含まれる

---

## Developer Experience (DX) Review

### 初心者・ロースキル観点での実装しやすさ

ネスト JSON バリデーションは概念自体は難しくないが、「エラーパスをどう設計するか」「fail-fast か全収集か」「型チェックをどう書くか」を全て自分で決める必要がある。NENE2 にガイドラインがなく、初心者は各自で異なる実装をする可能性が高い。

### 使ってみた印象

`foreach` + `$prefix = "items.{$i}"` のパターンは直感的で書きやすい。Problem Details の `extensions` に `errors` 配列を入れるのも一貫性があって良い。

### 楽しいか・気持ちいいか・快適か

「同じエラーフォーマットで複数階層のフィールドが返せる」のは気持ちいい。`items.0.product_id` のようなパスをテストで確認するのも明確で楽しい。

### 簡単か

フラットなバリデーションより複雑だが、パターン化されているので慣れれば速い。PHPStan の判別共用体エラーでは詰まった。

### また使いたいか

はい。このパターンをコピーして使い回せる。

### 初心者に勧めたいか

はい、ただし「エラーパス設計」と「全エラー収集パターン」のサンプルコードが必要。判別共用体の PHPStan 回避パターン（`@var` アノテーション）も文書化が必要。

---

## Issues / PRs

- Issue: `docs/howto/nested-json-validation.md` — ネスト JSON バリデーションガイド: エラーパスのドット記法・fail-fast vs 全収集・PHPStan の判別共用体絞り込み・unit_price の型チェック
