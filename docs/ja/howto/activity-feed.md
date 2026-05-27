# ハウツー: アクティビティフィード / タイムライン API

> **FT リファレンス**: FT277 (`NENE2-FT/feedlog`) — アクティビティフィード: 型許可リストによるイベント（9 種類）、イベントごとの JSON ペイロード、IDOR → 404 によるユーザースコープのフィード、ページネーションのクランプ（最大 100）、管理者はフェイルクローズ、24 テスト / 37 アサーション PASS。
>
> FT219 (`NENE2-FT/feedlog` の前身）でも検証済み — 同じパターンへの VULN アセスメント。

このガイドでは、NENE2 を使って型付きイベント、ユーザースコープ、ページネーションを備えたアクティビティフィードシステムを構築する方法を説明します。

## 機能

- 型付きアクティビティイベントを投稿する（厳密な許可リスト型）
- JSON ペイロードの保存（イベント種別ごとの任意のメタデータ）
- IDOR 保護付きのユーザースコープフィード（未認可アクセスには 404 を返す）
- クエリパラメーターによるイベント種別フィルタリング
- タイムスタンプの降順ページネーション（最新順）
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
| `GET` | `/users/{userId}/feed` | ユーザー（本人または管理者） | オプションの種別フィルター付きフィードを取得する |

## イベント種別許可リスト（VULN-B）

イベント種別を厳密に許可リスト化することで、マスアサインメントと任意のイベントインジェクションを防止します:

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

## ペイロードの保存

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

未認可ユーザーが別ユーザーのフィードを閲覧しようとした場合、フィードアクセスは 403 ではなく 404 を返します:

```php
$callerUid = $this->uid($req);
$isAdmin   = $this->isAdmin($req);
if (!$isAdmin && $callerUid !== $targetUid) {
    return $this->problem(404, 'not-found', 'User not found.');
}
```

## 種別フィルタリング付きページネーション

```php
$type   = isset($qs['type']) && in_array($qs['type'], self::ALLOWED_TYPES, true) ? $qs['type'] : null;
$limit  = $this->clampInt((string) ($qs['limit'] ?? ''), self::DEFAULT_LIMIT, 1, self::MAX_LIMIT);
$offset = $this->clampInt((string) ($qs['offset'] ?? ''), 0, 0, PHP_INT_MAX);
```

`?type=` パラメーターの未知の種別はサイレントに無視されます（null = フィルター適用なし）。

## VULN アセスメント結果（FT219）

- **VULN-B**: `in_array(..., strict: true)` により許可リスト外のイベント種別をすべて防止
- **VULN-C**: IDOR は 404 を返し、未認可の呼び出し元からフィードの存在を隠す
- **VULN-D**: 管理者フェイルクローズ — 空の管理者キーは常に false を返す
- **VULN-F**: `is_array($payload)` でペイロードが常に JSON オブジェクトであることを保証（スカラーではない）
- **VULN-G**: `ctype_digit()` で `userId` パスパラメーターをガード
- **VULN-I**: `clampInt()` で `limit`（1〜100）と `offset`（0〜MAX_INT）を制限

## セキュリティパターン

- **`ctype_digit()`**: パスパラメーターの ReDoS セーフな整数バリデーション
- **`is_array()`**: ペイロードは JSON オブジェクト（PHP の配列）でなければならない — 文字列、数値、null は不可
- **パラメータ化クエリ**: すべての SQL は `:named` パラメーターを使用 — 文字列連結なし
- **`in_array(..., true)`**: 厳密な比較により型強制バイパスを防止

---

## やってはいけないこと

| アンチパターン | リスク |
|---|---|
| 自由形式のイベント種別文字列を受け入れる | 制御されていない種別がフィードを汚染する；種別固有のクエリを構築しにくくなる |
| JSON バリデーションなしで TEXT としてペイロードを保存する | `is_array($payload)` で JSON オブジェクトを保証；スカラー/配列は下流コンシューマーを壊す |
| クエリ文字列の生の `limit` を信頼する | 上限なしで大きなデータセットでフルテーブルスキャンが発生する |
| `true` なしで `in_array($type, TYPES)` を使う | 緩い比較；一部の PHP バージョンでは `0 == 'post_created'` |
| 誤ったユーザーのフィードアクセスに 403 を返す | ユーザーの存在を明らかにする；ユーザー列挙を隠すには 404 を使用する |
| `user_id` のみのインデックス | 複合インデックスに `id DESC` がないと大きなフィードで ORDER BY が遅くなる |
