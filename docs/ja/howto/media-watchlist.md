# ハウツー: メディアウォッチリスト API

> **FT リファレンス**: FT59 (`NENE2-FT/watchlog`) — メディアウォッチリスト API

ステータスとタイプに backed 文字列 enum、`array_key_exists` を使ったオプションの nullable フィールド、POST アクションエンドポイントによるアーカイブ/リストア、1〜5 の整数評価を備えたパーソナルメディアウォッチリストを実証します。すべてのステータスとタイプのバリデーションは PHP の `BackedEnum::tryFrom()` を使って既知の値のみが受け入れられることを保証します。

---

## ルート

| メソッド | パス | 説明 |
|--------|------|-------------|
| `GET` | `/watch` | エントリーを一覧表示する（フィルタリングとページネーション） |
| `POST` | `/watch` | ウォッチリストにエントリーを追加する |
| `GET` | `/watch/{id}` | 単一エントリーを取得する |
| `PATCH` | `/watch/{id}/status` | ステータスを更新する（オプションで評価/ノート） |
| `POST` | `/watch/{id}/archive` | エントリーをアーカイブに移動する |
| `POST` | `/watch/{id}/restore` | アーカイブされたエントリーをリストアする |
| `DELETE` | `/watch/{id}` | エントリーを永続的に削除する |

---

## Backed enum バリデーション

ステータスとメディアタイプは `BackedEnum::tryFrom()` でバリデーションされます。enum はシリアライゼーションの型としても機能するため、DB に書き込まれた文字列値と JSON レスポンスの文字列値は自動的に同期します。

```php
enum WatchStatus: string
{
    case WantToWatch = 'want-to-watch';
    case Watching    = 'watching';
    case Completed   = 'completed';
    case Dropped     = 'dropped';
}

enum MediaType: string
{
    case Movie = 'movie';
    case Tv    = 'tv';
}
```

コントローラーでは、`tryFrom()` が不明な値に対して `null` を返し、422 にマップされます:

```php
$statusRaw = isset($body['status']) && is_string($body['status']) ? $body['status'] : null;
$status    = $statusRaw !== null ? WatchStatus::tryFrom($statusRaw) : null;

if ($statusRaw === null) {
    $errors[] = new ValidationError('status', 'status is required.', 'required');
} elseif ($status === null) {
    $errors[] = new ValidationError('status', 'Invalid status value.', 'invalid_value');
}
```

2 段階チェックは「フィールドなし」（required）と「フィールドあるが無効」（invalid_value）を区別し、より良いエラーメッセージを生成します。

---

## enum 型フィルタによる一覧表示

クエリパラメーターは `QueryStringParser` でパースされ、`tryFrom()` でバリデーションされます:

```php
$statusRaw = QueryStringParser::string($request, 'status');   // 不在の場合は null
$status    = $statusRaw !== null ? WatchStatus::tryFrom($statusRaw) : null;

if ($statusRaw !== null && $status === null) {
    $errors[] = new ValidationError('status', 'Invalid status value.', 'invalid_value');
}
```

このパターン（パース、enum 変換を試みる、バリデーション）はルーティングロジックをドメインコードから外に出します。リポジトリは `?WatchStatus` と `?MediaType` を受け取り、それに応じてフィルタリングします。

**サポートされるフィルター**:
- `?status=watching` — ステータスでフィルタリング
- `?media_type=movie` — メディアタイプでフィルタリング
- `?include_archived=1` — アーカイブ済みエントリーを含める（デフォルトは除外）
- `?limit=20&offset=0` — ページネーション

---

## `array_key_exists` を使った nullable フィールド

`rating` と `note` は nullable です — 呼び出し元は明示的に `null` を設定してクリアできます。`isset()` を使うと明示的に送信された `null` を見逃します。`array_key_exists()` を使ってください:

```php
// ✓ 正しい: 不在と明示的 null を区別する
$rating = array_key_exists('rating', $body) ? $body['rating'] : null;

// ✗ 間違い: array_key_exists($body, 'rating') が意図的な null を飲み込む
if ($rating !== null) {
    if (!is_int($rating) || $rating < 1 || $rating > 5) {
        $errors[] = new ValidationError('rating', 'rating must be an integer from 1 to 5.', 'out_of_range');
    }
}
```

`is_int($rating)` は JSON の float（`4.0` → PHP `float`）と文字列（`"4"`）を拒否します。JSON の整数リテラル（`4`）のみが厳格な型チェックを通過します。

---

## POST アクションエンドポイントによるアーカイブ/リストア

アーカイブとリストアは変異操作（状態を変更してタイムスタンプを記録）なので、`DELETE` や `PATCH` ではなく `POST` を使います。これはアクションエンドポイントパターンに従っています:

```php
// POST /watch/{id}/archive
private function archive(ServerRequestInterface $request): ResponseInterface
{
    $id    = (int) ($request->getAttribute(Router::PARAMETERS_ATTRIBUTE)['id'] ?? 0);
    $entry = $this->repository->archive($id, (new \DateTimeImmutable())->format('Y-m-d\TH:i:s\Z'));

    return $this->json->create($this->serialize($entry));
}

// POST /watch/{id}/restore
private function restore(ServerRequestInterface $request): ResponseInterface
{
    $id    = (int) ($request->getAttribute(Router::PARAMETERS_ATTRIBUTE)['id'] ?? 0);
    $entry = $this->repository->restore($id, (new \DateTimeImmutable())->format('Y-m-d\TH:i:s\Z'));

    return $this->json->create($this->serialize($entry));
}
```

`archive()` は `archived_at` を現在のタイムスタンプに設定します。`restore()` は `null` に戻します。一覧エンドポイントはデフォルトでアーカイブ済みエントリーを隠します（`include_archived=false`）。

なぜアーカイブに `DELETE` ではなく `POST` なのか？`DELETE` は永続的な削除を意味します。アーカイブはソフトな状態変化 — エントリーは DB に残り、回復可能です。エンドポイントをアクション後に名付ける（`/archive`、`/restore`）ことで意図が明確になります。

---

## スキーマ: CHECK 制約が enum の値と一致する

```sql
CREATE TABLE watch_entries (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    title       TEXT NOT NULL,
    media_type  TEXT NOT NULL CHECK(media_type IN ('movie', 'tv')),
    status      TEXT NOT NULL DEFAULT 'want-to-watch'
                              CHECK(status IN ('want-to-watch', 'watching', 'completed', 'dropped')),
    rating      INTEGER CHECK(rating IS NULL OR (rating >= 1 AND rating <= 5)),
    note        TEXT NOT NULL DEFAULT '',
    created_at  TEXT NOT NULL,
    updated_at  TEXT NOT NULL,
    archived_at TEXT
);
```

DB の `CHECK` 制約は enum の case を反映しています — `CHECK` を更新せずに enum に新しいステータスを追加すると、DB 層で挿入が失敗します。両方を同期して保ってください: enum に新しい case を追加し、`CHECK` と任意のマイグレーションにも追加してください。

`rating CHECK(rating IS NULL OR ...)` は、値が存在する場合に 1〜5 の範囲を強制しつつ、カラムが `NULL` になることを正しく許可します。

`archived_at TEXT`（nullable）がアーカイブフラグとして機能します: `NULL` = アクティブ、非 null = アーカイブ済み。これは最小限のソフトアーカイブパターンです — 別の `is_archived BOOLEAN` カラムは不要です。

---

## 一覧パフォーマンスのためのインデックス

```sql
CREATE INDEX idx_watch_status      ON watch_entries (status);
CREATE INDEX idx_watch_archived_at ON watch_entries (archived_at);
```

`idx_watch_archived_at` は一般的な `WHERE archived_at IS NULL` フィルター（アクティブエントリー）をサポートします。SQLite はパーシャルインデックスパターンで `IS NULL` 条件にこのインデックスを使用できますが、ほとんどのウォッチリストにはプレーンインデックスで十分です。

---

## シリアライゼーション

```php
/** @return array<string, mixed> */
private function serialize(WatchEntry $entry): array
{
    return [
        'id'          => $entry->id,
        'title'       => $entry->title,
        'media_type'  => $entry->mediaType->value,  // enum → string
        'status'      => $entry->status->value,      // enum → string
        'rating'      => $entry->rating,             // int|null
        'note'        => $entry->note,
        'created_at'  => $entry->createdAt,
        'updated_at'  => $entry->updatedAt,
        'archived_at' => $entry->archivedAt,         // string|null
    ];
}
```

backed enum の `->value` は文字列ケース値を返します（例: `'want-to-watch'`）。`->name` を呼び出さずにこの方法で enum をシリアライズしてください — name は PHP 識別子（`WantToWatch`）であり、API 契約の値ではありません。

---

## 関連 howto

- [`content-draft-lifecycle.md`](content-draft-lifecycle.md) — ステータス遷移を伴うステートマシン
- [`soft-delete.md`](soft-delete.md) — `deleted_at` タイムスタンプによるソフトデリート
- [`implement-patch-endpoint.md`](implement-patch-endpoint.md) — `array_key_exists` を使った部分更新
- [`add-custom-route.md`](add-custom-route.md) — POST アクションエンドポイントパターン（`/archive`、`/restore`、`/publish`）
