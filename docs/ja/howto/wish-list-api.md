# ハウツー: ウィッシュリスト API（VULN-A〜L セキュリティアセスメント）

このガイドでは、フル CRUD、管理者オーバーライド、VULN-A から VULN-L をカバーするセキュリティ強化を持つパーソナルウィッシュリスト API を実演します。

## パターン概要

- ユーザーは `POST /wishes`、`GET /wishes/{id}`、`PATCH /wishes/{id}`、`DELETE /wishes/{id}` を通じてプライベートウィッシュリストを管理します。
- `GET /users/{userId}/wishes` はユーザーのウィッシュを一覧表示します（オーナーまたは管理者のみ）。
- IDOR: 非オーナーはリソースの存在を明かさないために常に 404（403 ではなく）を受け取ります。
- 管理者は `X-Admin-Key` ヘッダーで識別されます。キーが空の場合はフェイルクローズドです。

## スキーマ

```sql
CREATE TABLE IF NOT EXISTS wishes (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    title      TEXT    NOT NULL,
    url        TEXT    NOT NULL DEFAULT '',
    priority   INTEGER NOT NULL DEFAULT 0,
    fulfilled  INTEGER NOT NULL DEFAULT 0,
    created_at TEXT    NOT NULL,
    updated_at TEXT    NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_wishes_user ON wishes (user_id, priority DESC, id DESC);
```

## VULN-A: SQL インジェクション

すべてのクエリは名前付きプレースホルダーを持つ PDO プリペアドステートメントを使用します。タイトル `'; DROP TABLE wishes; --` は損害なくそのまま保存されます:

```php
$this->pdo->prepare(
    'INSERT INTO wishes (user_id, title, ...) VALUES (:uid, :title, ...)'
)->execute([':uid' => $userId, ':title' => $title, ...]);
```

## VULN-B: マスアサインメント

`update()` ハンドラーは明示的なフィールド allowlist を維持します。クライアントが送信した `user_id`、`created_at`、`id` などのフィールドはサイレントに無視されます:

```php
$allowed = ['title', 'url', 'priority', 'fulfilled'];
foreach ($allowed as $field) {
    if (array_key_exists($field, $fields)) { ... }
}
```

## VULN-C: IDOR

非オーナーによる読み取りと削除はリソースの存在を隠すために 404（403 ではなく）を返します:

```php
if (!$isAdmin && (int) $wish['user_id'] !== $uid) {
    return $this->problem(404, 'not-found', 'Wish not found.');
}
```

一覧エンドポイントも同様に他のユーザーのリストを隠します:

```php
if (!$isAdmin && $callerUid !== $targetUid) {
    return $this->problem(404, 'not-found', 'User not found.');
}
```

## VULN-D: 管理者フェイルクローズド

空の `adminKey` は管理者権限を付与しません。このガードがないと、未設定のデプロイメントはすべての `X-Admin-Key: ` ヘッダーを有効として扱います:

```php
private function isAdmin(ServerRequestInterface $req): bool
{
    if ($this->adminKey === '') {
        return false;
    }
    return hash_equals($this->adminKey, $req->getHeaderLine('X-Admin-Key'));
}
```

## VULN-G: ReDoS

パスパラメーターの ID は ReDoS に対象となる可能性があるパターンではなく `ctype_digit()` でバリデーションされます:

```php
if (!ctype_digit($raw) || strlen($raw) > 18) {
    return $this->problem(404, 'not-found', 'Wish not found.');
}
```

## VULN-I: 負の値

priority は 0〜100 でなければなりません。負の値と 100 を超える値は 422 を返します:

```php
if (!is_int($priorityRaw) || $priorityRaw < 0 || $priorityRaw > 100) {
    return $this->problem(422, 'validation-failed', 'priority must be an integer 0–100.');
}
```

## VULN-J: JSON 型の混乱

`is_int()` は `priority` フィールドの文字列エンコードされた数字（`"5"`）や浮動小数点数（`1.5`）を拒否します。`is_bool()` は `fulfilled` の整数 `1`/`0` を拒否します:

```php
$p = $body['priority'];
if (!is_int($p) || $p < 0 || $p > 100) { return 422; }

$f = $body['fulfilled'];
if (!is_bool($f)) { return 422; }
```

## ルート

```
POST   /wishes                 ウィッシュを作成する（X-User-Id 必須）
GET    /wishes/{id}            ID でウィッシュを取得する（オーナーまたは管理者）
PATCH  /wishes/{id}            ウィッシュフィールドを更新する（オーナーのみ）
DELETE /wishes/{id}            ウィッシュを削除する（オーナーまたは管理者）
GET    /users/{userId}/wishes  ユーザーのウィッシュを一覧表示する（オーナーまたは管理者）
```

## バリデーションサマリー

| フィールド | ルール |
|---|---|
| `X-User-Id` | POST/PATCH で必須; `ctype_digit`、>0 |
| `title` | 空でない、最大 200 文字 |
| `url` | オプション、最大 500 文字 |
| `priority` | 整数 0〜100（文字列/浮動小数点数ではない）; デフォルト 0 |
| `fulfilled` | PATCH での真偽値のみ（1/0 ではない） |
| `{id}` パス | `ctype_digit`、最大 18 文字、>0; そうでなければ 404 |

## 参照

- FT207 ソース: `../NENE2-FT/wishlistlog/`
- 関連: `docs/howto/booking-resource.md`（FT201、VULN も含む）
- 関連: `docs/howto/coupon-redemption.md`（FT204、VULN + ATK）
