# NENE2 でリーダーボード（ランキングシステム）を構築する方法

このガイドでは、ユーザーがスコアを送信し、ランキングを確認し、自己ランクをチェックできるリーダーボードの構築手順を解説します。リーダーボードごと・ユーザーごとに最高スコアのみ保持します。

**フィールドトライアル**: FT141
**NENE2 バージョン**: ^1.5
**対象トピック**: ベストスコード UPDATE パターン、`COUNT(*)` によるランク計算、スコア所有権チェック、クエリパラメーターのクランプ、脆弱性評価

---

## 構築するもの

- `POST /leaderboards` — リーダーボードを作成する
- `POST /leaderboards/{id}/scores` — スコアを送信する（新しい個人ベストの場合のみ保持）
- `GET /leaderboards/{id}/rankings` — 上位 N 件のランキング（スコア降順、`?limit=N`）
- `GET /leaderboards/{id}/rankings/me` — 呼び出し元の自己ランクとスコア
- `DELETE /leaderboards/{id}/scores/{userId}` — 自己スコードを削除する（オーナーのみ）

---

## データベーススキーマ

```sql
CREATE TABLE leaderboards (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT NOT NULL,
    created_at TEXT NOT NULL
);

CREATE TABLE scores (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    leaderboard_id INTEGER NOT NULL,
    user_id        INTEGER NOT NULL,
    score          INTEGER NOT NULL,
    submitted_at   TEXT    NOT NULL,
    UNIQUE (leaderboard_id, user_id),
    FOREIGN KEY (leaderboard_id) REFERENCES leaderboards(id),
    FOREIGN KEY (user_id)        REFERENCES users(id)
);
```

`UNIQUE (leaderboard_id, user_id)` — リーダーボードごとにユーザーあたり 1 スコア行。更新時は置き換え。

---

## ベストスコアの UPDATE パターン

```php
public function submitScore(int $leaderboardId, int $userId, int $score, string $now): bool
{
    $existing = $this->findScore($leaderboardId, $userId);

    if ($existing === null) {
        $this->executor->execute(
            'INSERT INTO scores (leaderboard_id, user_id, score, submitted_at) VALUES (?, ?, ?, ?)',
            [$leaderboardId, $userId, $score, $now],
        );
        return true;
    }

    if ($score > $existing['score']) {
        $this->executor->execute(
            'UPDATE scores SET score = ?, submitted_at = ? WHERE leaderboard_id = ? AND user_id = ?',
            [$score, $now, $leaderboardId, $userId],
        );
        return true;
    }

    return false;  // 新しい個人ベストではない
}
```

スコードが新しい個人ベストのとき `true` を返します（UI フィードバックに便利）。無視された場合は `false`。

---

## `COUNT(*)` によるランク計算

ウィンドウ関数（`RANK()` はすべての SQLite バージョンでは利用できない）の代わりに、何件のスコアが高いかをカウントします:

```php
public function getUserRank(int $leaderboardId, int $userId): ?int
{
    $score = $this->findScore($leaderboardId, $userId);

    if ($score === null) {
        return null;
    }

    $row   = $this->executor->fetchOne(
        'SELECT COUNT(*) as cnt FROM scores WHERE leaderboard_id = ? AND score > ?',
        [$leaderboardId, $score['score']],
    );
    $ahead = isset($row['cnt']) ? (int) $row['cnt'] : 0;

    return $ahead + 1;
}
```

0 ユーザーが高いスコアなら、ランクは 1。5 ユーザーが高ければ、ランクは 6。同点は同じランクになります。

---

## スコア所有権チェック（IDOR 防止）

```php
if ($actorId !== $userId) {
    return $this->responseFactory->create(['error' => 'cannot delete another user\'s score'], 403);
}
```

DELETE の前に呼び出し元のアイデンティティをターゲットユーザーに対して常にチェックしてください。このチェックなしでは、認証済みの任意のユーザーが任意のスコアを削除できます。

---

## クエリパラメーターのクランプ

```php
$limit = isset($query['limit']) && is_numeric($query['limit']) ? (int) $query['limit'] : 10;

if ($limit <= 0 || $limit > 100) {
    $limit = 10;
}
```

`?limit=99999` でテーブル全体をスキャンされないよう制限を上限に設定してください。

---

## 脆弱性評価（FT141）

| ID | 攻撃 | 期待値 | 結果 |
|----|------|--------|------|
| VULN-A | IDOR: 他のユーザーのスコードを削除 | 403 | Pass |
| VULN-B | 他のユーザーのためにスコードを送信 | 200（許可） | Pass |
| VULN-C | リーダーボード名への SQL インジェクション | 201（そのまま） | Pass |
| VULN-D | /rankings/me で X-User-Id が欠落 | 400 | Pass |
| VULN-E | 非数値の X-User-Id | 200 以外 | Pass |
| VULN-F | 負のリーダーボード ID | 200 以外 | Pass |
| VULN-G | PHP_INT_MAX をスコードとして使用 | 200（有効な int） | Pass |
| VULN-H | 浮動小数点スコード（型混乱） | 422 | Pass |
| VULN-I | 文字列スコード（型混乱） | 422 | Pass |
| VULN-J | DELETE で X-User-Id が欠落 | 400 | Pass |
| VULN-K | スコード送信で user_id=0 | 422 | Pass |
| VULN-L | `?limit=99999`（大きな limit） | 200 + クランプ済み | Pass |

12 件の脆弱性テストがすべて Pass。脆弱性は見つかりませんでした。

---

## よくある落とし穴

| 落とし穴 | 修正 |
|---------|------|
| ベストのみでなく全送信スコードを保存する | INSERT の前に `findScore()` チェック; 高い場合は UPDATE |
| SQLite に存在しない可能性がある RANK() を使用する | `COUNT(*) WHERE score > ?` が同等のランクを返す |
| スコード削除での IDOR | `$actorId !== $userId` チェック → 403 |
| 制限のない limit パラメーターがテーブルスキャンを引き起こす | `limit` を 1〜100 の範囲にクランプする |
| Float/文字列スコードが `is_int()` をバイパスする | `!is_int($score)` が PHP 8 の JSON デコードで float と文字列を拒否する |
