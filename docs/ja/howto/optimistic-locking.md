# 楽観的ロック

楽観的ロックは**ロストアップデート問題**を防ぎます — 2 つの並行ライターが同じレコードを読み取り、独立した変更を行い、2 番目のライターが最初のライターの変更をサイレントに上書きする問題です。

楽観的ロックを使う場面:
- 競合がまれな場合（ほとんどの更新が成功する）
- 非ブロッキングな読み取りが必要な場合（SELECT FOR UPDATE なし）
- レコードに状態を追跡する `version` または `updated_at` フィールドがある場合

## ロストアップデート問題

ロックなしの場合:

```
時刻 | ライター A              | ライター B
-----|----------------------|-------------------
  1  | GET /articles/1      | GET /articles/1
     | ← version: 1         | ← version: 1
  2  | [タイトルを編集]       | [本文を編集]
  3  | PATCH /articles/1    |
     | title = "A's title"  |
     | ← version: 1, 200 OK |
  4  |                      | PATCH /articles/1
     |                      | body = "B's body"
     |                      | ← version: 1, 200 OK  ← A のタイトルが失われる
```

どちらも並行変更をチェックしなかったため、ライター B がライター A のタイトル変更を上書きします。

## スキーマ

更新のたびにインクリメントされる `version` カラムを追加します:

```sql
CREATE TABLE articles (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    title      TEXT NOT NULL,
    body       TEXT NOT NULL,
    version    INTEGER NOT NULL DEFAULT 1,
    updated_at TEXT NOT NULL
);
```

## リポジトリの実装

```php
/**
 * @throws ConflictException 別のライターが先にレコードを更新した場合
 * @throws \RuntimeException 記事が存在しない場合
 */
public function update(int $id, string $title, string $body, int $expectedVersion): Article
{
    $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

    // WHERE version = $expectedVersion が楽観的ロックチェックです。
    // 別のライターが既にバージョンをインクリメントした場合、この UPDATE は 0 行にマッチします。
    $affected = $this->executor->execute(
        'UPDATE articles SET title = ?, body = ?, version = version + 1, updated_at = ? WHERE id = ? AND version = ?',
        [$title, $body, $now, $id, $expectedVersion],
    );

    if ($affected === 0) {
        // 0 行更新: 見つからない OR バージョン競合 — 区別する
        $current = $this->findById($id);
        if ($current === null) {
            throw new \RuntimeException("Article {$id} does not exist.");
        }
        throw new ConflictException($id, $expectedVersion);
    }

    return new Article(id: $id, title: $title, body: $body, version: $expectedVersion + 1, updatedAt: $now);
}
```

### なぜ SQL で `version = version + 1` するのか（PHP ではなく）

```php
// ❌ 競合状態: 2 つのライターが両方 version=1 を読み取り、両方 version=2 を計算する
$newVersion = $article->version + 1;
$this->executor->execute('UPDATE ... SET version = ? ...', [$newVersion, $id, $expectedVersion]);

// ✅ アトミック: データベースがインクリメント — バージョンは常に正確
$this->executor->execute('UPDATE ... SET version = version + 1 ...', [$id, $expectedVersion]);
```

`WHERE version = $expectedVersion` チェックがガードです; `version = version + 1` は新しい値がガードを通過したものよりちょうど 1 多いことを保証します。

## コントローラーとの統合

クライアントは現在の `version` を読み取り、すべての更新に含めて送り返す必要があります:

```php
private function update(ServerRequestInterface $request): ResponseInterface
{
    $id   = (int) Router::param($request, 'id');
    $body = json_decode((string) $request->getBody(), true);

    if (!is_array($body) || !is_int($body['version'] ?? null)) {
        return $this->problems->create($request, 'invalid-body', 'version (int) is required.', 400);
    }

    try {
        $article = $this->repo->update($id, $body['title'], $body['body'], $body['version']);
        return $this->json->create($this->serialize($article));
    } catch (ConflictException $e) {
        $current = $this->repo->findById($id);
        return $this->problems->create(
            $request,
            'conflict',
            'Optimistic lock conflict.',
            409,
            $e->getMessage(),
            $current !== null ? ['current_version' => $current->version] : [],
        );
    } catch (\RuntimeException) {
        return $this->problems->create($request, 'not-found', 'Article not found.', 404);
    }
}
```

## クライアントフロー

```
POST /articles            → 201 { id: 1, version: 1, ... }
GET /articles/1           → 200 { id: 1, version: 1, ... }

PATCH /articles/1         → 200 { id: 1, version: 2, ... }
  { title: "...", version: 1 }

PATCH /articles/1         → 409 { type: "conflict", current_version: 2 }
  { title: "...", version: 1 }   （古いバージョン — 競合！）

PATCH /articles/1         → 200 { id: 1, version: 3, ... }
  { title: "...", version: 2 }   （再取得するか 409 の current_version を使う）
```

409 レスポンスに `current_version` を含めることで、クライアントは追加の GET なしに再試行できます。

## レスポンスペイロード

クライアントが常に最新の値を持てるように、すべてのレスポンスに `version` を含めてください:

```php
/** @return array<string, mixed> */
private function serialize(Article $article): array
{
    return [
        'id'         => $article->id,
        'title'      => $article->title,
        'body'       => $article->body,
        'version'    => $article->version,  // ← クライアントは送り返すためにこれが必要
        'updated_at' => $article->updatedAt,
    ];
}
```

## 楽観的ロック vs 悲観的ロック

| | 楽観的 | 悲観的 |
|---|---|---|
| メカニズム | `WHERE version = ?` + 0 行チェック | `SELECT ... FOR UPDATE` |
| 読み取りブロック | なし | 他の読み取り者をブロック |
| 競合率 | 低い（ほとんどの更新が成功） | 高い競合も OK |
| 再試行コスト | クライアントが 409 で再試行 | ロック解放を待つ |
| SQLite サポート | ✅ | ❌（非対応） |
| 最適な用途 | まれな競合、UX 駆動の再試行 | 高い競合、必ず成功させる操作 |

## コードレビューチェックリスト

- [ ] UPDATE が WHERE 句に `AND version = ?` を含む
- [ ] `execute()` の戻り値（影響行数）がチェックされている — 0 は競合または未発見を意味する
- [ ] 0 行ケースで「見つからない」と「バージョン競合」を区別する（競合パスで追加の `findById`）
- [ ] `version = version + 1` が PHP アプリケーションコードではなく SQL で計算される
- [ ] すべてのレスポンスペイロードに `version` が含まれ、クライアントが常に最新を持てる
- [ ] 409 レスポンスに追加の GET なしのクライアント再試行用 `current_version` が含まれる
- [ ] リクエストボディの `version` が `string` ではなく `int` としてバリデーションされる（`is_int()` チェック）
- [ ] テストがカバーするもの: 成功した更新、連続した更新、並行競合、競合後の再試行、404、バージョンなし
