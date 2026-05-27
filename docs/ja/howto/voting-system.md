# 投票システム（アップボート / ダウンボート）

ユーザーがアイテムをアップボートまたはダウンボートできるようにします。各ユーザーはアイテムごとに最大 1 票を投じることができます。同じ方向に 2 回投票するとトグルオフになります。逆方向に投票すると投票が切り替わります。

## 概要

投票システムには以下が含まれます:
- **投票する**: アイテムをアップボートまたはダウンボードする
- **トグル**: 同じ方向に 2 回投票すると投票が削除される
- **切り替え**: 逆方向に投票すると現在の投票が置き換わる
- **スコア**: アップボート − ダウンボート、すべての投票レスポンスで返される
- **現在の投票**: アイテムに対するユーザーの現在の投票を取得する（UI のハイライト用）

## データベーススキーマ

```sql
CREATE TABLE votes (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    item_id    INTEGER NOT NULL,
    direction  TEXT    NOT NULL CHECK (direction IN ('up', 'down')),
    created_at TEXT    NOT NULL,
    UNIQUE (user_id, item_id),
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (item_id) REFERENCES items(id)
);
```

`UNIQUE (user_id, item_id)` 制約はデータベースレベルでユーザーごとアイテムごとに 1 票を強制します。`CHECK (direction IN ('up', 'down'))` はアプリケーションレベルのバリデーションがバイパスされた場合でも無効な値を防ぎます。

## 方向の Enum

無効な direction 値がリポジトリに到達するのを防ぐために backed enum を使用してください:

```php
enum VoteDirection: string
{
    case Up   = 'up';
    case Down = 'down';
}
```

`VoteDirection::tryFrom($dirStr)` でパースします — 無効な入力に対して `null` を返し、match/switch なしでクリーンな 422 処理を可能にします。

## トグルと切り替えのロジック

3 つのケース（トグルオフ、方向切り替え、新規投票）すべてをリポジトリで処理します:

```php
public function castVote(int $userId, int $itemId, VoteDirection $direction, string $now): ?VoteDirection
{
    $current = $this->getCurrentVote($userId, $itemId);

    if ($current === $direction) {
        // 同じ方向 → トグルオフ
        $this->executor->execute(
            'DELETE FROM votes WHERE user_id = ? AND item_id = ?',
            [$userId, $itemId],
        );
        return null;
    }

    if ($current !== null) {
        // 異なる方向 → 切り替え
        $this->executor->execute(
            'UPDATE votes SET direction = ?, created_at = ? WHERE user_id = ? AND item_id = ?',
            [$direction->value, $now, $userId, $itemId],
        );
    } else {
        // 既存の投票なし → 挿入
        $this->executor->execute(
            'INSERT INTO votes (user_id, item_id, direction, created_at) VALUES (?, ?, ?, ?)',
            [$userId, $itemId, $direction->value, $now],
        );
    }

    return $direction;
}
```

戻り値 `?VoteDirection` はハンドラーに投票が設定されている（`'up'`/`'down'`）か削除された（`null`）かを知らせます。

## すべての投票でスコアを返す

クライアントが別の GET なしにカウンターを更新できるように、投票レスポンスに更新されたスコアを含めてください:

```php
$result = $this->repo->castVote($userId, $itemId, $direction, $now);
$score  = $this->repo->getScore($itemId);

return $this->responseFactory->create([
    'user_id' => $userId,
    'item_id' => $itemId,
    'vote'    => $result !== null ? $result->value : null,
    'score'   => $score->toArray(),
]);
```

## スコア計算

方向ごとに個別の COUNT クエリを使う方が、単一の GROUP BY よりもシンプルで読みやすいです:

```php
public function getScore(int $itemId): ItemScore
{
    $upRow = $this->executor->fetchOne(
        "SELECT COUNT(*) as cnt FROM votes WHERE item_id = ? AND direction = 'up'",
        [$itemId],
    );
    $downRow = $this->executor->fetchOne(
        "SELECT COUNT(*) as cnt FROM votes WHERE item_id = ? AND direction = 'down'",
        [$itemId],
    );
    ...
}
```

`score = アップボート - ダウンボート`。投票が行われる前の初期状態はゼロです。

## ユーザーの投票状態

UI が現在のユーザーがどの方向に投票したかを表示できるように（ボタンのハイライト用）、別のエンドポイントを設けます:

```php
// GET /items/{itemId}/vote/{userId}
$current = $this->repo->getCurrentVote($userId, $itemId);
return ['vote' => $current !== null ? $current->value : null];
```

ユーザーが投票していない場合（またはトグルオフした場合）は `null` を返します。

## セキュリティ特性

| 特性 | 実装 |
|---|---|
| ユーザーごとアイテムごとに 1 票 | `UNIQUE (user_id, item_id)` DB 制約 |
| 無効な方向を拒否 | `CHECK (direction IN ('up', 'down'))` + `VoteDirection::tryFrom()` |
| 未知のユーザー/アイテム | 404 を返す — リソースの存在を漏洩しない |
| トグルの安全性 | DELETE/UPDATE の前に現在の投票を確認する |

## ルートサマリー

| メソッド | パス | 説明 |
|---|---|---|
| `POST` | `/users`                          | ユーザーを作成する                     |
| `POST` | `/items`                          | アイテムを作成する                     |
| `POST` | `/items/{itemId}/vote`            | 投票する、切り替える、またはトグルする |
| `GET`  | `/items/{itemId}/score`           | アップボート、ダウンボート、スコアを取得する |
| `GET`  | `/items/{itemId}/vote/{userId}`   | アイテムに対するユーザーの現在の投票を取得する |
