# ハウツー: 部分成功を伴うバッチ API

> **FT リファレンス**: FT294 (`NENE2-FT/batchlog`) — 部分成功を伴うバッチ INSERT: MAX_BATCH=50 ガード、インデックス追跡付きのアイテムごとの独立したバリデーション、混合 created/errors レスポンス（常に 200）、DB CHECK 制約、`is_int()` による厳密な JSON 型バリデーション、36 テスト / 79 アサーション PASS。
>
> **FT 前身**: FT182（最初の batchlog カバレッジ）。

クライアントが 1 つのリクエストで配列のアイテムを送信する場合、一部のアイテムが有効で他が無効な場合があります。いずれかのエラーでバッチ全体を拒否すると有効なアイテムが無駄になり、エラーをサイレントにスキップするとバグが隠れます。_部分成功_ パターンは可能なものを受け入れ、できないものを報告します — アイテムごと、インデックスごとに。

---

## コアの問題

JSON 配列ボディには 2 つのバリデーション層があります:

1. **バッチレベル** — リクエスト全体の形式は有効か？（キーが存在するか？リストか？カウントは範囲内か？）
2. **アイテムレベル** — 各要素は有効か？（型？範囲？必須フィールド？）

両層を同じ方法で扱うと、過剰な拒否（1 つの悪いアイテムがバッチ全体を壊す）か、過剰な受け入れ（悪いアイテムがサイレントに無視される）になります。

---

## HTTP 規則

| シナリオ | ステータス | ボディ |
|---|---|---|
| バッチレベルエラー（キー欠落、型誤り、空、過大サイズ） | `422` | `{"error": "..."}` |
| アイテムレベルエラーのみ / 成功+エラーの混合 | `200` | `{created, errors, total_created, total_errors}` |
| すべてのアイテムが有効 | `200` | `{created: [...], errors: [], ...}` |
| すべてのアイテムが無効 | `200` | `{created: [], errors: [...], ...}` |

**全無効でも 200 な理由？** バッチ操作自体は成功しました — サーバーはすべてのアイテムを処理し、各アイテムについて決定を下しました。呼び出し元は `total_created` と `errors` を調べることで何が起きたかを知れます。「一部のアイテムが無効」に 422 を使うと 2 種類の異なる失敗が混同されます。

---

## V::bodyInt() — 厳密な JSON 型強制

`V::bodyInt()` はバッチペイロードでの JSON 型の混乱をキャッチするための主要なツールです。PHP の `json_decode` は JSON 型を保持しますが、呼び出し元が誤って（または意図的に）間違った型を送信する場合があります。

```php
// V::bodyInt(mixed $raw, int $min, int $max): ?int
V::bodyInt(5, 1, 999)         // → 5        ✓ PHP int
V::bodyInt("5", 1, 999)       // → null     ✗ JSON 型の混乱: "5" は 5 ではない
V::bodyInt(5.5, 1, 999)       // → null     ✗ float
V::bodyInt(true, 1, 999)      // → null     ✗ bool
V::bodyInt(null, 1, 999)      // → null     ✗ null
V::bodyInt([5], 1, 999)       // → null     ✗ array
```

クエリ文字列との重要な違い: `V::queryInt()` は文字列 `"5"` を受け入れます（クエリパラメーターは常に文字列なので）が、`V::bodyInt()` は PHP `int` を要求します（JSON は `5` と `"5"` を区別するため）。

**ATK-07 型混乱攻撃** — `{"quantity": 5}` の代わりに `{"quantity": "5"}` を送信するのは失敗しなければなりません。`is_int()` が唯一の安全なチェックです。

---

## バッチバリデーションロジック

```php
// 1. ボディを解析する（非オブジェクト JSON の場合は [] にフォールバック）
$body = json_decode((string) $request->getBody(), true);
$body = is_array($body) ? $body : [];

// 2. バッチレベルのガード → 422
if (!array_key_exists('items', $body)) {
    return 422; // キー欠落
}
$rawItems = $body['items'];
if (!is_array($rawItems)) {
    return 422; // 配列でない
}
if (count($rawItems) === 0) {
    return 422; // 空
}
if (count($rawItems) > MAX_BATCH) {
    return 422; // 過大サイズ
}

// 3. アイテムごとの処理 → errors[] を持つ 200
$created = [];
$errors  = [];

foreach ($rawItems as $index => $rawItem) {
    $intIndex = (int) $index;

    // 各アイテムは JSON オブジェクト（連想配列）でなければならない。スカラーやリストは不可
    if (!is_array($rawItem) || array_is_list($rawItem)) {
        $errors[] = ['index' => $intIndex, 'error' => 'Each item must be a JSON object.'];
        continue;
    }

    $name = V::str($rawItem['name'] ?? null, 100);
    if ($name === null || $name === '') {
        $errors[] = ['index' => $intIndex, 'error' => 'name is required (max 100 chars).'];
        continue;
    }

    $quantity = V::bodyInt($rawItem['quantity'] ?? null, 1, 999);
    if ($quantity === null) {
        $errors[] = ['index' => $intIndex, 'error' => 'quantity must be an integer between 1 and 999.'];
        continue;
    }

    // … 追加フィールド …

    $item      = $repository->create(/* ... */);
    $created[] = $item->toArray();
}

// 4. 常に 200；呼び出し元が total_created / total_errors を読む
return 200 with [
    'created'       => $created,
    'errors'        => $errors,
    'total_created' => count($created),
    'total_errors'  => count($errors),
];
```

---

## array_is_list() — アイテムレベルでの JSON オブジェクト vs JSON 配列

PHP `json_decode` は JSON オブジェクトを連想配列に、JSON 配列をリスト配列にマップします。アイテムレベルで区別するには `array_is_list()` を使用します:

```php
// JSON ボディ: {"items": [{"name": "foo"}, "bar", 42, [1,2]]}
is_array(["name" => "foo"])   // true — 有効な JSON オブジェクト
array_is_list(["name" => "foo"]) // false — 連想配列 → オブジェクト ✓

is_array("bar")                  // false → is_array チェックでキャッチ
is_array(42)                     // false → キャッチ
is_array([1, 2])                 // true
array_is_list([1, 2])            // true → 拒否: リスト ≠ オブジェクト ✗
```

ガード `!is_array($rawItem) || array_is_list($rawItem)` はスカラー、JSON 配列、プレーン JSON オブジェクトでないものすべてをキャッチします。

---

## MAX_BATCH サイズガード

上限がないと、呼び出し元が 1 つのリクエストで何千ものアイテムを送信し、際限なくメモリと CPU を消費する可能性があります。

```php
const MAX_BATCH = 50; // ユースケースに合わせて調整する

if (count($rawItems) > self::MAX_BATCH) {
    return $this->responseFactory->create(
        ['error' => sprintf('"items" must contain at most %d entries.', self::MAX_BATCH)],
        422,
    );
}
```

繰り返し前にバッチレベルで拒否（422）します — 過大サイズのバッチに対してアイテムごとのエラーをカウントしません。

---

## エラーインデックスの保持

クライアントが（クライアントサイドのフィルタリング後などで）非連続のインデックスを持つ配列を送信した場合でも、クライアントがエラーと送信したアイテムを関連付けられるよう、各エラーに元の入力インデックスを報告します:

```php
// 入力:  [有効, 無効, 有効, 無効]
// 出力エラー: [{index: 1, error: "..."}, {index: 3, error: "..."}]
```

インデックスを常に `int` に明示的にキャストします — PHP 配列が非連続 JSON から構築された場合、`foreach` のキーは `string` になる可能性があります:

```php
$intIndex = (int) $index;
```

---

## レスポンススキーマ

```json
{
  "created": [
    {"id": 1, "user_id": 1, "name": "Widget A", "quantity": 3, "price_cents": 999, "created_at": "..."},
    {"id": 2, "user_id": 1, "name": "Widget B", "quantity": 1, "price_cents": 4999, "created_at": "..."}
  ],
  "errors": [
    {"index": 1, "error": "quantity must be an integer between 1 and 999."},
    {"index": 3, "error": "name is required (max 100 chars)."}
  ],
  "total_created": 2,
  "total_errors": 2
}
```

---

## 冪等性の考慮事項

部分成功は書き込み後エラーのシナリオを生み出します。ネットワーク障害後にクライアントがバッチ全体を再試行すると、以前に作成されたアイテムが重複する可能性があります。オプション:

- **冪等性キー**: バッチごとにクライアント生成の UUID を含める；サーバーが保存して重複を排除する。
- **クライアント重複排除**: クライアントがどのインデックスが成功したかを追跡し、失敗したアイテムのみを再送信する。
- **自然な一意性**: 一意制約（例: 外部 ID）を使用し、重複キーエラーを成功として扱う。

`batchlog` FT は明瞭さのために最もシンプルなアプローチ（冪等性キーなし）を使用しています。本番のバッチ API では上記の戦略のいずれかを実装してください。

---

## セキュリティ注意事項

- **すべての数値フィールドに `V::bodyInt()`** — JSON ボディの文字列、float、bool、null を拒否する。
- **文字列フィールドに `V::str()`** — 非文字列を拒否し、トリムし、長さをチェックする；トリム後に必須フィールドの `=== ''` をチェックする。
- **ユーザースコープ** — 各アイテムはヘッダーからの認証済みユーザー ID（`V::userId()`）にバインドされ、リクエストボディからは取得しない。
- **MAX_BATCH ガード** — 過大サイズのバッチによる DoS を防ぐために繰り返し前に 422 を返す。

---

## 重要なポイント

| パターン | ルール |
|---|---|
| バッチレベルエラー | 422 — リクエスト全体を拒否 |
| アイテムレベルエラー | 200 — `errors[]` にインデックス + メッセージを報告 |
| JSON での型混乱 | `V::bodyInt()` / `is_int()` — `is_numeric()` は使わない |
| JSON オブジェクト vs 配列 | `!is_array() \|\| array_is_list()` — 両方を拒否 |
| サイズ DoS | `count($items) > MAX_BATCH` → 繰り返し前に 422 |
| エラー関連付け | エラーレスポンスに元の `$index` を保持する |
