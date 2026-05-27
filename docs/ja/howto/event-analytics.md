# ハウツー: イベントアナリティクス API

> **FT リファレンス**: FT51 (`NENE2-FT/statslog`) — JSON プロパティフィルタリングと集計クエリを持つイベントアナリティクス API

任意の JSON プロパティを持つアナリティクスイベントを保存し、日ごとのカウント、タイプごとの内訳、ユニークユーザー指標の集計エンドポイントを公開するイベント追跡 API を実演します。主なパターン: `json_extract()` プロパティフィルタリング、`strftime()` 日付バケット化、パラメーター化ルートの前に静的ルート、文字列型ユーザー ID。

---

## ルート

| メソッド | パス | 説明 |
|--------|---------------------------|-----------------------------------------------------|
| `POST` | `/events`                 | イベントを記録する |
| `GET`  | `/events`                 | イベントを一覧表示する（ページネーション） |
| `GET`  | `/events/by-property`     | JSON プロパティのキー/値でフィルタリングする |
| `GET`  | `/events/{id}`            | 単一イベントを取得する |
| `GET`  | `/stats/per-day`          | カレンダー日ごとのイベントカウント（`?from=&to=`） |
| `GET`  | `/stats/per-type`         | イベントタイプごとのイベントカウント（`?from=&to=`） |
| `GET`  | `/stats/unique-users`     | 日ごとのユニークユーザーカウント（`?from=&to=`） |

---

## イベントの記録

```php
// POST /events
$body = [
    'event_type'  => 'page_view',          // 必須、空でない文字列
    'user_id'     => 'usr_abc123',          // 必須、文字列（UUID または不透明 ID）
    'session_id'  => 'sess_xyz789',         // オプション
    'properties'  => ['path' => '/pricing', 'referrer' => 'google'],  // オプションオブジェクト
    'occurred_at' => '2026-05-27T09:00:00Z', // オプション、ISO 8601（デフォルトはサーバー時刻）
];
```

`properties` は JSON 文字列として保存されます。出力時にオブジェクトにデコードされます:

```php
'properties' => json_decode($event->properties, true, 512, JSON_THROW_ON_ERROR),
```

`occurred_at` が省略された場合、サーバーが現在の UTC 時刻で埋めます:

```php
$occurredAt = isset($body['occurred_at']) && is_string($body['occurred_at'])
    ? $body['occurred_at']
    : (new \DateTimeImmutable())->format('Y-m-d\TH:i:s\Z');
```

---

## ルート順序: パラメーター化の前に静的ルート

ルーターは登録順序でルートをマッチングします。`/events/by-property` のような静的パスは、パラメーター化された `/events/{id}` の**前に**登録する必要があります。さもないと、`by-property` セグメントが `{id}` としてキャプチャされます:

```php
public function register(Router $router): void
{
    $router->post('/events', $this->createEvent(...));
    $router->get('/events', $this->listEvents(...));

    // ✓ 静的ルートを先に — さもないと "by-property" が {id} に飲み込まれる
    $router->get('/events/by-property', $this->eventsByProperty(...));
    $router->get('/events/{id}', $this->showEvent(...));

    $router->get('/stats/per-day', $this->statsPerDay(...));
    $router->get('/stats/per-type', $this->statsPerType(...));
    $router->get('/stats/unique-users', $this->statsUniqueUsers(...));
}
```

**ルール**: 同じ深さレベルにあるワイルドカードセグメントの前に、具体的なパスセグメントを常に登録してください。

---

## `json_extract()` を使った JSON プロパティフィルタリング

SQLite（≥ 3.38）と MySQL は保存された JSON カラム内をクエリするための `json_extract()` をサポートしています。キーはパラメーター化された JSONPath 式として渡されます:

```php
$rows = $this->executor->fetchAll(
    'SELECT * FROM events WHERE json_extract(properties, ?) = ? ORDER BY occurred_at DESC LIMIT ? OFFSET ?',
    ['$.' . $propertyKey, $propertyValue, $limit, $offset],
);
```

JSONPath プレフィックス `$.` は PHP で追加されるため、`key = "path"` は `json_extract(properties, '$.path')` になります。両方の引数がパラメーター化されているため、`$propertyKey` に特殊文字が含まれていても SQL インジェクションのリスクはありません。

> **深さの制限**: `$.path` はトップレベルにアクセスします。ネストされたアクセス（`$.browser.name`）の場合、呼び出し元はキーとして `browser.name` を渡します。深いパスは驚きをもたらす可能性があります — サポートされるキーの形式を OpenAPI スペックにドキュメント化してください。

---

## `strftime()` を使った日付集計

```sql
SELECT strftime('%Y-%m-%d', occurred_at) AS day,
       COUNT(*) AS count
FROM events
WHERE occurred_at >= ? AND occurred_at < ?
GROUP BY strftime('%Y-%m-%d', occurred_at)
ORDER BY day ASC
```

`strftime('%Y-%m-%d', ...)` は ISO 8601 datetime 文字列を日付コンポーネントに切り捨てます。`occurred_at` が UTC として保存されている場合（例: `2026-05-27T09:00:00Z`）、SQLite で動作します。非 UTC オフセットで保存された時刻は、現地時刻に変換されずに生文字列でバケット化されます — 日付境界のセマンティクスが重要な場合は書き込み時に UTC に正規化してください。

---

## 日ごとのユニークユーザーカウント

```sql
SELECT strftime('%Y-%m-%d', occurred_at) AS day,
       COUNT(DISTINCT user_id) AS unique_users
FROM events
WHERE occurred_at >= ? AND occurred_at < ?
GROUP BY strftime('%Y-%m-%d', occurred_at)
ORDER BY day ASC
```

`COUNT(DISTINCT user_id)` は各バケットに出現する個別の `user_id` 値の数を返します。`user_id` が安定した外部識別子（UUID、ハッシュ化されたデバイス ID など）の場合、これはデイリーアクティブユーザー（DAU）の近似値です。

---

## 文字列型 user_id

`user_id` は整数の外部キーではなく `TEXT NOT NULL` として保存されます。この設計は以下に対応します:

- UUID（`usr_01HQ...`）
- アイデンティティプロバイダーからの不透明な文字列識別子
- アカウント作成前の匿名セッショントークン

フィールドが自由形式のテキストであるため、アナリティクスレイヤーはユーザーデータモデルに結合されません。`REFERENCES users(id)` 外部キーはありません — ユーザーアカウントの作成前後にイベントを記録できます。

---

## デフォルトの日付範囲フォールバック

集計エンドポイントは `?from=` と `?to=` クエリパラメーターを受け付けます。省略された場合、デフォルトは非常に広い範囲になります:

```php
$from = QueryStringParser::string($request, 'from') ?? '2000-01-01T00:00:00Z';
$to   = QueryStringParser::string($request, 'to')   ?? '2100-01-01T00:00:00Z';
```

デモ用途には便利ですが、大規模な本番データセットでは高コストになる可能性があります。本番では明示的な日付範囲を要求し、最大スパンを制限してください（制限パターンについては [`shift-management.md`](shift-management.md) 参照）。

---

## スキーマとインデックス

```sql
CREATE TABLE events (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    event_type  TEXT    NOT NULL,
    user_id     TEXT    NOT NULL,
    session_id  TEXT    NOT NULL DEFAULT '',
    properties  TEXT    NOT NULL DEFAULT '{}',
    occurred_at TEXT    NOT NULL
);

CREATE INDEX idx_events_type     ON events(event_type);
CREATE INDEX idx_events_occurred ON events(occurred_at);
CREATE INDEX idx_events_user     ON events(user_id);
```

3 つのインデックスが 3 つの主要なクエリ形状をカバーします:
- `idx_events_occurred` — 日付範囲集計（`WHERE occurred_at >= ? AND < ?`）
- `idx_events_type` — タイプフィルター（`WHERE event_type = ?`）
- `idx_events_user` — ユーザー履歴検索（`WHERE user_id = ?`）

`properties` の `json_extract()` クエリは生成カラムなしでは SQLite のインデックスサポートを受けられません。大量のプロパティフィルタリングには、生成カラムの追加を検討してください:

```sql
ALTER TABLE events ADD COLUMN prop_path TEXT GENERATED ALWAYS AS (json_extract(properties, '$.path')) STORED;
CREATE INDEX idx_events_prop_path ON events(prop_path);
```

---

## PHP での properties エンコーディング

`properties` フィールドは呼び出し元から任意の JSON オブジェクトを受け付け、文字列として保存します:

```php
$properties = isset($body['properties']) && is_array($body['properties'])
    ? json_encode($body['properties'], JSON_THROW_ON_ERROR)
    : '{}';
```

`is_array($body['properties'])` は JSON スカラーと配列を拒否します（PHP 配列にデコードされますが、オブジェクトではありません）。`JSON_THROW_ON_ERROR` でエンコード失敗がサイレントな `false` ではなく例外として現れるようにします。

シリアライズ時、properties は PHP 配列にデコードされ、レスポンスにネストされたオブジェクトとして埋め込まれます:

```php
'properties' => json_decode($event->properties, true, 512, JSON_THROW_ON_ERROR),
```

---

## 関連ハウツー

- [`admin-report-aggregation.md`](admin-report-aggregation.md) — 管理レポートのための SQL 集計パターン
- [`shift-management.md`](shift-management.md) — 日付範囲の制限、集計クエリ
- [`pagination.md`](pagination.md) — `PaginationQueryParser` と `PaginationResponse`
- [`iso-datetime-validation.md`](iso-datetime-validation.md) — `occurred_at` の ISO 8601 ラウンドトリップバリデーション
