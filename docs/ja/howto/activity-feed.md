# ハウツー: アクティビティフィード / タイムライン API

> **FT リファレンス**: FT277 (`NENE2-FT/feedlog`) — アクティビティフィード: 型許可リスト付きイベント（9 種類）、イベントごとの JSON ペイロード、IDOR → 404 によるユーザースコープフィード、ページネーションクランプ（最大 100）、管理者フェイルクローズ、24 テスト / 37 アサーション PASS。
>
> FT219 (`NENE2-FT/feedlog` の前身) でも検証済み — 同じパターンへの VULN アセスメント。

このガイドでは、型付きイベント、ユーザースコープ、ページネーションを備えたアクティビティフィードシステムを NENE2 で構築する方法を説明します。

## 機能

- 型付きアクティビティイベントを投稿する（厳密な許可リスト）
- JSON ペイロードストレージ（イベント型ごとの任意のメタデータ）
- IDOR 保護付きユーザースコープフィード（未認可アクセスには 404 を返す）
- クエリパラメーターによるイベント型フィルタリング
- タイムスタンプ降順ページネーション（新しいものから順に）
- 管理者がユーザーの代わりにイベントを投稿できる

## スキーマ

```sql
CREATE TABLE IF NOT EXISTS events (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    type       TEXT    NOT NULL,
    payload    TEXT    NOT NULL DEFAULT '{}',
    created_at TEXT    NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_events_user ON events (user_id, id DESC);
CREATE INDEX IF NOT EXISTS idx_events_type ON events (type, id DESC);
```

## エンドポイント

| メソッド | パス | 認証 | 説明 |
|--------|------|------|-------------|
| `POST` | `/events` | ユーザー | アクティビティイベントを投稿する |
| `GET` | `/users/{userId}/feed` | ユーザー（自分または管理者） | オプション型フィルター付きフィードを取得する |

## イベント型許可リスト（VULN-B）

イベント型を厳密に許可リスト化することで、マスアサインメントと任意イベントのインジェクションを防止します:

```php
private const array ALLOWED_TYPES = [
    'post_created', 'post_liked', 'post_commented',
    'user_followed', 'user_unfollowed',
    'item_purchased', 'item_reviewed',
    'badge_earned', 'level_up',
];

$type = trim((string) ($body['type'] ?? ''));
if (!in_array($type, self::ALLOWED_TYPES, true)) {
    return $this->problem(422, 'validation-failed', 'type must be one of: ...');
}
```

## ペイロードストレージ

ペイロードは JSON 文字列として保存され、取得時にデコードされます:

```php
public function create(int $userId, string $type, array $payload): array
{
    $payloadJson = (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    // INSERT ... payload = :payloadJson
}

private function decode(array $row): array
{
    $decoded = json_decode((string) $row['payload'], true);
    $row['payload'] = is_array($decoded) ? $decoded : [];
    return $row;
}
```

## IDOR 保護（VULN-C）

未認可ユーザーが別のユーザーのフィードを表示しようとした場合、フィードアクセスは 403 ではなく 404 を返します:

```php
$callerUid = $this->uid($req);
$isAdmin   = $this->isAdmin($req);
if (!$isAdmin && $callerUid !== $targetUid) {
    return $this->problem(404, 'not-found', 'User not found.');
}
```

## 型フィルタリング付きページネーション

```php
$type   = isset($qs['type']) && in_array($qs['type'], self::ALLOWED_TYPES, true) ? $qs['type'] : null;
$limit  = $this->clampInt((string) ($qs['limit'] ?? ''), self::DEFAULT_LIMIT, 1, self::MAX_LIMIT);
$offset = $this->clampInt((string) ($qs['offset'] ?? ''), 0, 0, PHP_INT_MAX);
```

`?type=` パラメーターの未知の型はサイレントに無視されます（null = フィルターなし）。

## VULN アセスメント結果（FT219）

- **VULN-B**: `in_array(..., strict: true)` でリスト外のイベント型を防止
- **VULN-C**: IDOR は未認可呼び出し元からフィードの存在を隠すために 404 を返す
- **VULN-D**: 管理者フェイルクローズ — 空の管理者キーは常に false を返す
- **VULN-F**: `is_array($payload)` でペイロードが常に JSON オブジェクトであることを確保（スカラーでない）
- **VULN-G**: `ctype_digit()` で `userId` パスパラメーターをガード
- **VULN-I**: `clampInt()` で `limit`（1〜100）と `offset`（0〜MAX_INT）を制限

## セキュリティパターン

- **`ctype_digit()`**: パスパラメーターの ReDoS セーフな整数検証
- **`is_array()`**: ペイロードは JSON オブジェクト（PHP の array）でなければならない — 文字列、数値、null は不可
- **パラメータ化クエリ**: すべての SQL は `:named` パラメーターを使用 — 文字列結合なし
- **`in_array(..., true)`**: 型強制バイパスを防ぐ厳密な比較

---

## やってはいけないこと

| アンチパターン | リスク |
|---|---|
| 自由形式のイベント型文字列を受け入れる | 制御されていない型がフィードを汚染する。型固有のクエリを構築しにくくなる |
| JSON 検証なしで TEXT としてペイロードを保存する | `is_array($payload)` で JSON オブジェクトを確保。スカラー/配列は下流のコンシューマーを壊す |
| クエリ文字列から生の `limit` を信頼する | 上限なし → 大規模データセットでのフルテーブルスキャン |
| `in_array($type, TYPES)` を `true` なしで使用する | 緩い比較。一部の PHP バージョンで `0 == 'post_created'` |
| 間違ったユーザーのフィードアクセスに 403 を返す | ユーザーが存在することを明かす。ユーザー列挙を隠すために 404 を使用する |
| `user_id` のみにインデックスを張る | 複合インデックスに `id DESC` がないと大きなフィードで ORDER BY が遅くなる |
