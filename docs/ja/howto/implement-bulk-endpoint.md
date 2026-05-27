# ハウツー: 一括作成エンドポイントの実装

一括エンドポイントは単一のリクエストで複数のリソースを受け付けます — バッチインポート、スコア送信、類似ワークフローのラウンドトリップを削減します。このガイドでは完全なパターンを解説します: パース、インデックス付きエラーフィールドによるアイテムごとのバリデーション、サイズ制限、ルート。

---

## 1. スキーマ

リクエストボディはアイテムを名前付き配列キーでラップし、エンベロープがメタデータを運べるようにします:

```json
{
  "scores": [
    { "player": "Alice", "game": "tetris", "score": 1000, "played_at": "2026-01-15" },
    { "player": "Bob",   "game": "tetris", "score": 2000, "played_at": "2026-01-16" }
  ]
}
```

レスポンスは作成数と作成されたアイテムを返します:

```json
{ "created": 2, "scores": [ /* ... */ ] }
```

---

## 2. ルート

シャドーイングを避けるため、一括ルートをパラメーター化された単一リソースルートより**先に**登録してください（[add-custom-route.md](add-custom-route.md) 参照）:

```php
$router->post('/scores/bulk', $this->bulkSubmit(...)); // 静的を先に
$router->post('/scores/{id}', $this->show(...));        // パラメーター化を後に
```

---

## 3. ハンドラー

```php
private function bulkSubmit(ServerRequestInterface $request): ResponseInterface
{
    $body = JsonRequestBodyParser::parse($request);

    // 1. エンベロープをバリデーション
    if (!isset($body['scores']) || !is_array($body['scores'])) {
        throw new ValidationException([
            new ValidationError('scores', 'scores must be a non-empty array.', 'required'),
        ]);
    }

    /** @var array<mixed> $entriesRaw */
    $entriesRaw = $body['scores'];

    if (count($entriesRaw) === 0) {
        throw new ValidationException([
            new ValidationError('scores', 'scores must contain at least one entry.', 'required'),
        ]);
    }

    // 2. イテレーション前にサイズ制限を強制
    if (count($entriesRaw) > 100) {
        throw new ValidationException([
            new ValidationError('scores', 'scores may contain at most 100 entries per request.', 'out_of_range'),
        ]);
    }

    // 3. 各エントリをバリデーション。フィールド名にインデックスをプレフィックス
    $allErrors = [];
    $entries   = [];

    foreach ($entriesRaw as $i => $entry) {
        if (!is_array($entry)) {
            $allErrors[] = new ValidationError("scores[{$i}]", 'Each entry must be an object.', 'invalid_type');
            continue;
        }

        /** @var array<string, mixed> $entry */
        $entryErrors = $this->validateEntry($entry, "scores[{$i}].");
        if ($entryErrors !== []) {
            $allErrors = [...$allErrors, ...$entryErrors];
        } else {
            $entries[] = $entry;
        }
    }

    // 4. いずれかのエントリが無効であればリクエスト全体を失敗させる
    if ($allErrors !== []) {
        throw new ValidationException($allErrors);
    }

    // 5. すべてのエントリを永続化して返す
    $now     = (new \DateTimeImmutable())->format('Y-m-d\TH:i:s\Z');
    $created = $this->repository->bulkCreate($entries, $now);

    return $this->json->create([
        'created' => count($created),
        'scores'  => array_map(fn ($s) => $this->serialize($s), $created),
    ], 201);
}
```

---

## 4. インデックス付きフィールド名によるアイテムごとのバリデーション

`string $prefix` 引数を受け付けるプライベートヘルパーを使用してください。プレフィックスは `"scores[{$i}]."` です:

```php
/**
 * @param array<string, mixed> $body
 * @return list<ValidationError>
 */
private function validateEntry(array $body, string $prefix = ''): array
{
    $errors = [];

    if (!isset($body['player']) || !is_string($body['player']) || $body['player'] === '') {
        $errors[] = new ValidationError($prefix . 'player', 'player is required.', 'required');
    }

    if (!isset($body['score']) || !is_int($body['score'])) {
        $errors[] = new ValidationError($prefix . 'score', 'score is required (integer).', 'required');
    } elseif ($body['score'] < 0) {
        $errors[] = new ValidationError($prefix . 'score', 'score must be 0 or greater.', 'out_of_range');
    }

    return $errors;
}
```

**なぜ `$prefix` を使うか？** `ValidationError` はフィールド名として任意の文字列を受け付けます。`"scores[0]."` をプレフィックスとして渡すと `"scores[0].player"` のようなエラーフィールドが生成されます — どのエントリとフィールドが失敗したかが即座に明確になります。プレフィックス引数 1 つで十分で、フレームワークの変更は不要です。

結果の 422 レスポンスボディ:

```json
{
  "type": "https://nene2.dev/problems/validation-failed",
  "errors": [
    { "field": "scores[1].player", "message": "player is required.", "code": "required" }
  ]
}
```

---

## 5. Repository コントラクト

事前バリデーション済みのエントリのリストを受け付けて、作成されたエンティティを返します:

```php
/**
 * @param list<array{player: string, game: string, score: int, played_at: string}> $entries
 * @return list<Score>
 */
public function bulkCreate(array $entries, string $now): array
{
    $results = [];
    foreach ($entries as $entry) {
        $results[] = $this->create($entry['player'], $entry['game'], $entry['score'], $entry['played_at'], $now);
    }
    return $results;
}
```

> **アトミック性**: 上記のループは 1 行ずつ挿入します。全件成功か全件失敗の動作が必要な場合は `DatabaseTransactionManagerInterface::transactional()` でラップしてください — [use-transactions.md](use-transactions.md) 参照。

---

## 6. 関連ハウツー

- [`add-pagination.md`](add-pagination.md) — リストエンドポイントパターン
- [`use-transactions.md`](use-transactions.md) — 一括挿入をトランザクションでラップする
- [`add-domain-exception-handler.md`](add-domain-exception-handler.md) — ドメイン固有の 404/409
