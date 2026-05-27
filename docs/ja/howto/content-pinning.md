# Content Pinning

コンテンツ（記事）のピン留め機能の実装ガイド。
順序付きピン留め・上限管理・冪等追加・位置の自動整合を解説する。

## 概要

- ユーザーが記事をピン留め（最大 10 件）
- `position` カラムで順序管理（1 始まり）
- 冪等追加（既存なら 200、新規なら 201）
- unpin 後に position が自動的に詰まる
- 順序は `PUT /pins/order` で任意に変更可能

## エンドポイント

| Method | Path | 説明 |
|---|---|---|
| `POST` | `/pins` | 記事をピン留め（冪等） |
| `DELETE` | `/pins/{articleId}` | ピン解除 |
| `GET` | `/pins` | ピン留め一覧（順序付き） |
| `PUT` | `/pins/order` | ピン留め順序変更 |

## データベース設計

```sql
CREATE TABLE pins (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    article_id INTEGER NOT NULL,
    position INTEGER NOT NULL,    -- 1 始まり、連続整数
    pinned_at TEXT NOT NULL,
    UNIQUE (user_id, article_id), -- ユーザーごとに記事を一度だけピン
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (article_id) REFERENCES articles(id)
);
```

## 冪等ピン追加

```php
public function pin(int $userId, int $articleId, string $now): bool
{
    $existing = $this->findPin($userId, $articleId);
    if ($existing !== null) {
        return false;  // 既存: false = 200
    }
    $nextPosition = $this->maxPosition($userId) + 1;
    $this->executor->execute('INSERT INTO pins ...', [$userId, $articleId, $nextPosition, $now]);
    return true;  // 新規: true = 201
}
```

戻り値 `true` → 201 Created、`false` → 200 OK（呼び出し元がステータスを決定）。

## Unpin 後の位置詰め

```php
public function unpin(int $userId, int $articleId): bool
{
    $removedPosition = (int) $existing['position'];
    $this->executor->execute('DELETE FROM pins WHERE user_id = ? AND article_id = ?', [...]);
    // 削除した位置より後ろを1つ前にシフト
    $this->executor->execute(
        'UPDATE pins SET position = position - 1 WHERE user_id = ? AND position > ?',
        [$userId, $removedPosition]
    );
    return true;
}
```

位置にギャップが生まれないよう自動整合する。

## 上限チェック

```php
if ($this->repository->countPins($actorId) >= $this->repository->maxPins()) {
    $existing = $this->repository->findPin($actorId, $articleId);
    if ($existing === null) {
        return $this->responseFactory->create([
            'error' => 'pin limit reached',
            'max' => $this->repository->maxPins()
        ], 422);
    }
}
```

既存の記事を再ピン（冪等）する場合は上限チェックをスキップする。

## 順序変更（reorder）

```php
public function reorder(int $userId, array $orderedArticleIds): bool
{
    $currentPins = $this->listPins($userId);
    $currentIds = array_map(fn (array $p) => (int) $p['article_id'], $currentPins);
    sort($currentIds);
    $sortedInput = $orderedArticleIds;
    sort($sortedInput);
    if ($currentIds !== $sortedInput) {
        return false;  // 現在のピン一覧と一致しない
    }
    foreach ($orderedArticleIds as $position => $articleId) {
        $this->executor->execute(
            'UPDATE pins SET position = ? WHERE user_id = ? AND article_id = ?',
            [$position + 1, $userId, $articleId]
        );
    }
    return true;
}
```

`article_ids` が現在のピン一覧（順不同）と完全一致しないと 422。
追加・削除なしで順序変更のみを受け付ける。

## GET /pins レスポンス

```json
{
  "pins": [
    {"article_id": 3, "title": "Article 3", "position": 1, "pinned_at": "2026-05-21T10:00:00+00:00"},
    {"article_id": 1, "title": "Article 1", "position": 2, "pinned_at": "2026-05-21T09:00:00+00:00"}
  ],
  "count": 2
}
```

`position` 昇順でソート。`ORDER BY p.position ASC` で JOIN して取得。

## 上限解放（unpin して新規ピン可能に）

```
10件ピン → DELETE /pins/{id} → 9件 → POST /pins で追加可能
```

上限は現在のカウントで動的に判定するため、unpin 直後に新規ピンできる。
