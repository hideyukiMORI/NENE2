# ハウツー: PATCH エンドポイントの実装

PATCH は**部分更新**のためのものです: クライアントが送信したフィールドのみが変更されるべきです。
これにはすべてのフィールドについて 3 つの状態を区別する必要があります:

| 状態 | 意味 |
|---|---|
| ボディにキーがない | このフィールドに触れない |
| キーがあり、値が非 null | 新しい値に更新する |
| キーがあり、値が `null` | フィールドをクリアする（null に設定） |

`isset()` は「存在しない」と「明示的な null」を区別できません — どちらも `false` を返します。
代わりに `array_key_exists()` を使用してください。

---

## 1. ボディをパースして存在するフィールドのみを抽出する

```php
$body   = JsonRequestBodyParser::parse($request);   // array<string, mixed>
$fields = [];

if (array_key_exists('title', $body)) {
    $fields['title'] = is_string($body['title']) ? trim($body['title']) : null;
}
if (array_key_exists('is_read', $body)) {
    $fields['is_read'] = (bool) $body['is_read'];
}
```

`$fields` を repository の `update()` メソッドに渡してください。`$fields` が空の場合でも呼び出しは有効です — リソースの現在の状態で応答してください。

---

## 2. ルート登録

```php
$router->patch(
    '/entries/{id}',
    static function (ServerRequestInterface $request) use ($entries, $json): ResponseInterface {
        /** @var array<string, string> $params */
        $params = $request->getAttribute(Router::PARAMETERS_ATTRIBUTE, []);
        $id     = (int) ($params['id'] ?? 0);

        $body   = JsonRequestBodyParser::parse($request);
        $fields = [];

        if (array_key_exists('title', $body)) {
            $fields['title'] = $body['title'];
        }
        if (array_key_exists('is_read', $body)) {
            $fields['is_read'] = (bool) $body['is_read'];
        }

        $entry = $entries->update($id, $fields) ?? throw new EntryNotFoundException($id);

        return $json->create(self::payload($entry));
    },
);
```

---

## 3. 空の PATCH ボディを送信する

フィールドなし（現在の状態を返すノーオペレーション）で PATCH を送信するには、配列ではなく JSON **オブジェクト**を送信する必要があります。

```php
// 誤り: json_encode([]) === "[]"  → 400 Bad Request（JSON 配列）
$request->withBody($stream->write(json_encode([])));

// 正しい: json_encode((object)[]) === "{}"  → 200 OK（JSON オブジェクト）
$request->withBody($stream->write(json_encode((object)[])));
```

テストヘルパーでは `new \stdClass()` をボディとして渡してください:

```php
// PHPUnit テストで
$response = $this->request('PATCH', "/entries/{$id}", new \stdClass());
```

これは `JsonRequestBodyParser` が JSON 配列を拒否するためです（詳細は `JsonBodyParseException` メッセージを参照）。空の PHP 配列 `[]` は JSON オブジェクト `{}` ではなく JSON 配列 `[]` にエンコードされます。

---

## 4. PATCH フィールドのバリデーション

**存在する**フィールドのみをバリデーションしてください。存在しないフィールドのバリデーションはスキップしてください — それらは変更されません。意図を明示的にするために repository のシグネチャで nullable パラメーターを使用してください:

```php
$body   = JsonRequestBodyParser::parse($request);
$errors = [];

// 存在するフィールドのみを抽出（array_key_exists、isset ではない）
$amount   = array_key_exists('amount', $body) ? $body['amount'] : null;
$category = array_key_exists('category', $body) ? $body['category'] : null;
$date     = array_key_exists('date', $body) ? $body['date'] : null;

// 送信されたフィールドのみをバリデーション
if ($amount !== null) {
    if (!is_int($amount) || $amount <= 0) {
        $errors[] = new ValidationError('amount', 'amount must be a positive integer.', 'out_of_range');
    }
}

if ($date !== null) {
    if (!is_string($date) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
        $errors[] = new ValidationError('date', 'date must be in YYYY-MM-DD format.', 'invalid_format');
    }
}

if ($errors !== []) {
    throw new ValidationException($errors);
}

// nullable 引数で repository を呼び出す — null のとき repository は既存の値を使用
$entity = $this->repository->update(
    id:       $id,
    amount:   is_int($amount) ? $amount : null,
    category: is_string($category) && $category !== '' ? $category : null,
    date:     is_string($date) && $date !== '' ? $date : null,
    now:      (new \DateTimeImmutable())->format('Y-m-d\TH:i:s\Z'),
);
```

repository では `??` を使って既存の値にフォールバックしてください:

```php
public function update(int $id, ?int $amount, ?string $category, ?string $date, string $now): Entity
{
    $existing    = $this->findById($id); // 見つからない場合は NotFoundException をスロー
    $newAmount   = $amount   ?? $existing->amount;
    $newCategory = $category ?? $existing->category;
    $newDate     = $date     ?? $existing->date;

    $this->executor->execute(
        'UPDATE entities SET amount = ?, category = ?, date = ?, updated_at = ? WHERE id = ?',
        [$newAmount, $newCategory, $newDate, $now, $id],
    );

    return new Entity($id, $newDate, $newAmount, $newCategory, $existing->createdAt, $now);
}
```

> **なぜ `array_key_exists` で `isset` でないか？** `isset($body['field'])` はキーが欠如している場合と `null` 値のキーが存在する場合の両方で `false` を返します。PATCH では、その区別が重要です: 「送信されていない」は「既存の値を保持する」を意味し、`null` は「このフィールドをクリアする」を意味する場合があります。PATCH フィールド検出には常に `array_key_exists` を使用してください。

---

## 5. Repository コントラクト

repository の `update()` は渡されたフィールドのみを受け付け、更新されたエンティティ（見つからない場合は `null`）を返すべきです:

```php
/** @param array<string, mixed> $fields */
public function update(int $id, array $fields): ?Entry
{
    if ($fields === []) {
        return $this->findById($id);   // ノーオペレーション: 現在の状態を返す
    }

    $setClauses = implode(', ', array_map(fn (string $k): string => "{$k} = ?", array_keys($fields)));
    $params     = [...array_values($fields), $id];

    $affected = $this->executor->execute(
        "UPDATE entries SET {$setClauses} WHERE id = ?",
        $params,
    );

    return $affected > 0 ? $this->findById($id) : null;
}
```

---

## 5. 関連ハウツー

- [`add-pagination.md`](add-pagination.md) — `PaginationQueryParser` による GET
- [`add-domain-exception-handler.md`](add-domain-exception-handler.md) — 欠如リソースの 404 ハンドラー
