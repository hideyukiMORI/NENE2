---
title: "ウィッシュリスト管理"
category: product
tags: [wishlist, priority, visibility, idor]
difficulty: intermediate
related: [wish-list-api, media-watchlist]
---

# ウィッシュリスト管理

優先度・メモ付きウィッシュリストの実装ガイド。
存在非公開パターン・冪等追加・複数パスパラメータを解説する。

## 概要

- ユーザーが名前付きウィッシュリストを作成（公開/非公開）
- 各ウィッシュリストに商品を追加（`priority`: high/medium/low、任意の `note`）
- 非公開ウィッシュリストは非オーナーに 404（存在非公開パターン）
- 商品追加は冪等（既存なら 200、新規なら 201）
- 順序なし（position 管理なし）— コンテンツコレクション（FT149）との主な違い

## エンドポイント

| Method | Path | 説明 |
|---|---|---|
| `POST` | `/wishlists` | ウィッシュリスト作成 |
| `GET` | `/wishlists/{id}` | ウィッシュリスト取得（公開 or 自分） |
| `PUT` | `/wishlists/{id}` | 名前・公開設定変更 |
| `DELETE` | `/wishlists/{id}` | ウィッシュリスト削除 |
| `POST` | `/wishlists/{id}/items` | 商品を追加（冪等） |
| `DELETE` | `/wishlists/{id}/items/{productId}` | 商品を削除 |

## データベース設計

```sql
CREATE TABLE wishlists (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    name TEXT NOT NULL,
    is_public INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

CREATE TABLE wishlist_items (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    wishlist_id INTEGER NOT NULL,
    product_id INTEGER NOT NULL,
    priority TEXT NOT NULL DEFAULT 'medium',
    note TEXT,
    added_at TEXT NOT NULL,
    UNIQUE (wishlist_id, product_id),
    CHECK (priority IN ('high', 'medium', 'low')),
    FOREIGN KEY (wishlist_id) REFERENCES wishlists(id),
    FOREIGN KEY (product_id) REFERENCES products(id)
);
```

コンテンツコレクション（FT149）と異なり `position` カラムがない。
`UNIQUE (wishlist_id, product_id)` は冪等追加の DB レベル防御。

## 存在非公開パターン

```php
$isOwner = $actorId !== null && (int) $wishlist['user_id'] === $actorId;
$isPublic = (bool) $wishlist['is_public'];

if (!$isOwner && !$isPublic) {
    return $this->responseFactory->create(['error' => 'wishlist not found'], 404);
}
```

GET のみ 404 を返す。PUT/DELETE/POST items は 403 を返してオーナーに権限不足を明示。

## 冪等アイテム追加

```php
$existing = $this->repository->findItem($id, $productId);
if ($existing !== null) {
    return $this->responseFactory->create([
        'message' => 'already in wishlist',
        'product_id' => $productId,
        'priority' => $existing['priority'],
        'note' => $existing['note'],
    ], 200);
}
$now = date('c');
$this->repository->addItem($id, $productId, $priority, $note, $now);
return $this->responseFactory->create([...], 201);
```

## priority バリデーション（無効値はデフォルト値にフォールバック）

```php
private const array VALID_PRIORITIES = ['high', 'medium', 'low'];

$priority = isset($body['priority']) && is_string($body['priority'])
    && in_array($body['priority'], self::VALID_PRIORITIES, true)
    ? $body['priority']
    : 'medium';
```

無効な priority 値はエラーではなく `'medium'` にフォールバックする設計。
クライアントが前方互換性のために送信した未知の priority を安全に処理できる。

## GET /wishlists/{id} レスポンス例

```json
{
  "id": 1,
  "user_id": 1,
  "name": "Birthday Wishlist",
  "is_public": true,
  "item_count": 2,
  "items": [
    {
      "product_id": 3,
      "product_name": "Wireless Headphones",
      "priority": "high",
      "note": "Black color preferred",
      "added_at": "2026-05-21T..."
    },
    {
      "product_id": 1,
      "product_name": "Coffee Mug",
      "priority": "low",
      "note": null,
      "added_at": "2026-05-21T..."
    }
  ],
  "created_at": "2026-05-21T...",
  "updated_at": "2026-05-21T..."
}
```

## コレクションとウィッシュリストの違い

| 観点 | コレクション（FT149） | ウィッシュリスト（FT151） |
|---|---|---|
| 順序 | position 管理あり | なし（追加順） |
| アイテムメタデータ | なし | priority + note |
| 上限 | 50件 | なし |
| ユースケース | 読書リスト・キュレーション | 欲しいものリスト・ギフト登録 |

## 所有権チェックパターン

```php
if ((int) $wishlist['user_id'] !== $actorId) {
    return $this->responseFactory->create(['error' => 'access denied'], 403);
}
```

PUT/DELETE/POST items すべてで同じパターンを使用。
