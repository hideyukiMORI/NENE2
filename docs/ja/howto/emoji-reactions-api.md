# ハウツー: 絵文字リアクション API

> **FT リファレンス**: FT306 (`NENE2-FT/emojilog`) — 絵文字リアクション: UNIQUE(post_id, user_id, emoji) により複数ユーザーが同じ絵文字でリアクションできるが、1 人のユーザーが同じ絵文字で 2 回リアクションすることを防ぐ。mb_strlen 最大 8 文字、DELETE パスの絵文字に urldecode()、user_reactions で現在のアクターのリアクションを表示、リアクションはカウント DESC 順、18 テスト / 28 アサーション PASS。

このガイドでは、複数のユーザーが任意の絵文字で投稿にリアクションできるが、各ユーザーは投稿ごとに特定の絵文字を一度しか使えない絵文字リアクションシステムの実装方法を示します。

## スキーマ

```sql
CREATE TABLE reactions (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    post_id    INTEGER NOT NULL,
    user_id    INTEGER NOT NULL,
    emoji      TEXT    NOT NULL,
    created_at TEXT    NOT NULL,
    UNIQUE (post_id, user_id, emoji),
    FOREIGN KEY (post_id) REFERENCES posts(id),
    FOREIGN KEY (user_id) REFERENCES users(id)
);
```

`UNIQUE(post_id, user_id, emoji)` は以下を許可します:
- 複数ユーザーによる同じ絵文字: Alice と Bob が両方とも `👍` でリアクションできる
- 同じユーザーによる異なる絵文字: Alice は `👍` と `❤️` を両方使える

しかし以下を防止します:
- 同じユーザーが同じ絵文字を 2 回: Alice は同じ投稿に `👍` を 2 回使えない

## エンドポイント

| メソッド | パス | 認証 | 説明 |
|--------|------|------|-------------|
| `GET` | `/posts/{id}/reactions` | `X-User-Id`（オプション） | リアクションカウントとアクターのリアクションを取得 |
| `POST` | `/posts/{id}/reactions` | `X-User-Id` | リアクションを追加 |
| `DELETE` | `/posts/{id}/reactions/{emoji}` | `X-User-Id` | リアクションを削除 |

## リアクション追加 — 厳格なバリデーション

```php
if (!isset($body['emoji']) || !is_string($body['emoji']) || trim($body['emoji']) === '') {
    return $this->responseFactory->create(['error' => 'emoji is required'], 422);
}
$emoji = trim($body['emoji']);
if (mb_strlen($emoji) > 8) {
    return $this->responseFactory->create(['error' => 'emoji too long'], 422);
}

$added = $this->repository->addReaction($postId, $actorId, $emoji, date('c'));
if (!$added) {
    return $this->responseFactory->create(['error' => 'already reacted with this emoji'], 409);
}
```

- `is_string()` チェックで非文字列型を拒否
- 空チェック前の `trim()` でスペースのみの絵文字を防止
- 正確なマルチバイト文字数のために `strlen()` ではなく `mb_strlen()` を使用
- 重複追加 → 409 Conflict（422 ではなく）

## リアクション削除 — パスの絵文字に URL デコード

```php
$emoji = isset($params['emoji']) && is_string($params['emoji']) ? urldecode($params['emoji']) : '';
if ($emoji === '') {
    return $this->responseFactory->create(['error' => 'invalid emoji'], 404);
}
```

URL パスセグメントの絵文字文字はクライアントが URL エンコードする必要があります。`urldecode()` で DB 検索のために元の絵文字を復元します。例: `DELETE /posts/1/reactions/%F0%9F%91%8D` → `👍` を検索します。

## リアクションカウントレスポンス

```php
// 絵文字でグループ化してカウント、カウント DESC 順
$counts = $this->repository->getReactionCounts($postId);

// アクターが提供されていれば、使用した絵文字を表示
$userReactions = [];
if ($actorId !== null) {
    $userReactions = $this->repository->getUserReactions($postId, $actorId);
}

return $this->responseFactory->create([
    'post_id'        => $postId,
    'reactions'      => $counts,        // [{emoji, count}, ...] カウント DESC 順
    'user_reactions' => $userReactions, // ['👍', '❤️', ...] 現在のアクターのリアクション
]);
```

`user_reactions` は `X-User-Id` ヘッダーが未提供の場合は空です — このフィールドはフロントエンドがアクティブなリアクションをハイライトするために、現在の閲覧者のリアクションを示します。

---

## やってはいけないこと

| アンチパターン | リスク |
|---|---|
| `UNIQUE(post_id, user_id)`（絵文字カラムなし） | ユーザーは投稿ごとに 1 つの絵文字しか使えない |
| 絵文字の長さチェックに `strlen()` を使用 | `🎉` のようなマルチバイト絵文字（4 バイト）が正しくカウントされない |
| パスの絵文字に `urldecode()` なし | `%F0%9F%91%8D` の `👍` が格納された `👍` と一致しない |
| 重複リアクションに 404 を返す | 409 のセマンティクスを隠す — 重複リアクションはコンフリクトであり、リソース不在ではない |
| 絵文字の長さ制限なし | 任意の長さの文字列が絵文字カラムに格納される |
| アクターなしで `user_reactions` を空にするが、キーは含める | 省略するか `[]` を返す — 両方問題ないが、動作をドキュメント化すること |
| 空チェック後に `trim()` | スペースのみの `"  "` 絵文字が有効として通過する |
