# リクエスト重複排除の追加方法

`Idempotency-Key` ヘッダーを使用して、ネットワークリトライやダブルクリックによる重複処理を防ぎます。サーバーはキーごとにレスポンスをキャッシュし、その後の同一リクエストでそれを再生します。

## スキーマ

```sql
CREATE TABLE idempotency_keys (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    idempotency_key TEXT NOT NULL UNIQUE,
    method          TEXT NOT NULL,
    path            TEXT NOT NULL,
    status_code     INTEGER NOT NULL,
    response_body   TEXT NOT NULL,
    created_at      TEXT NOT NULL,
    expires_at      TEXT NOT NULL
);
```

## ルート

| メソッド | パス | 説明 |
|--------|------|-------------|
| `POST` | `/payments` | 支払いを処理する（idempotency-key 必須） |
| `POST` | `/orders` | 注文を作成する（idempotency-key 必須） |

## ハンドラーパターン

冪等であるべきすべてのミューテーションエンドポイントは同じ 3 ステップパターンに従います:

```php
// 1. Idempotency-Key ヘッダーを要求する
$key = trim($request->getHeaderLine('Idempotency-Key'));
if ($key === '') {
    return $this->json->create(['error' => 'Idempotency-Key header is required'], 400);
}

// 2. キーが既に使用されていればキャッシュされたレスポンスを返す
$cached = $this->repo->find($key);
if ($cached !== null && $cached['expires_at'] >= $this->now()) {
    $body = json_decode($cached['response_body'], true);
    return $this->json->create(
        array_merge($body, ['replayed' => true]),
        (int) $cached['status_code']
    );
}

// 3. 処理してキャッシュする
$result = $this->doWork($body);
$this->repo->store($key, 'POST', '/payments', 201, json_encode($result), $now, $expiresAt);
return $this->json->create($result, 201);
```

`replayed: true` フィールドはレスポンスがキャッシュから提供されたことをクライアントに通知します。

## 厳密な金額バリデーション

境界で非整数の入力を拒否してください — PHP の `(int)` キャストは `"100; DROP TABLE …"` のような文字列を黙って `100` に切り捨てます。明示的な型チェックを使用してください:

```php
$rawAmount = $body['amount'] ?? null;
if (!is_int($rawAmount) && !(is_string($rawAmount) && ctype_digit($rawAmount))) {
    $errors[] = new ValidationError('amount', 'amount must be a positive integer', 'invalid');
} else {
    $amount = (int) $rawAmount;
    if ($amount <= 0) {
        $errors[] = new ValidationError('amount', 'amount must be a positive integer', 'invalid');
    }
}
```

## TTL と有効期限

キーは 24 時間（86400 秒）後に有効期限が切れます。期限切れのエントリは新鮮として扱われます — 有効期限後に同じキーを再利用できます:

```php
private const int TTL_SECONDS = 86400;

$expiresAt = (new \DateTimeImmutable($now, new \DateTimeZone('UTC')))
    ->modify('+' . self::TTL_SECONDS . ' seconds')
    ->format('Y-m-d\TH:i:s\Z');
```

## セキュリティプロパティ

- **キーヘッダーへの SQL インジェクション**: パラメーター化クエリが悪意のあるキーをリテラルとして保存します。
- **リプレイフラッド**: 10 回の同一リクエストがビジネステーブルに正確に 1 件のレコードを作成します。
- **空白のみのキー**: 空チェックの前に `trim()` することで `"   "` が有効なキーとして扱われることを防ぎます。
- **数値フィールドの型インジェクション**: `ctype_digit()` チェックが部分的な整数文字列を拒否します。
- **内部情報の漏洩なし**: 400/422 レスポンスには `error` または `errors` フィールドのみが含まれます — パス、スタックトレース、エンジンの詳細は含まれません。
