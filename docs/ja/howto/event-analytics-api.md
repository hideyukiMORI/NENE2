# ハウツー: イベントアナリティクス API

> **FT リファレンス**: FT243 (`NENE2-FT/statslog`) — イベントアナリティクス API
> **VULN**: FT243 — 脆弱性アセスメント（V-01〜V-10）

JSON `properties` blob と共に生のアナリティクスイベントを記録し、SQLite の `json_extract()` でクエリを行い、日ごと / タイプごと / ユニークユーザーの統計に集計するイベント取り込みと集計 API を実演します。認証なしの設計に関する完全な脆弱性アセスメントを含みます。

---

## ルート

| メソッド | パス | 説明 |
|--------|------------------------|------------------------------------------------------|
| `POST` | `/events`              | アナリティクスイベントを記録する |
| `GET`  | `/events`              | イベントを一覧表示する（ページネーション） |
| `GET`  | `/events/by-property`  | JSON プロパティのキー+値でイベントをフィルタリングする |
| `GET`  | `/events/{id}`         | 単一イベントを取得する |
| `GET`  | `/stats/per-day`       | 日ごとにグループ化されたイベントカウント |
| `GET`  | `/stats/per-type`      | イベントタイプごとにグループ化されたイベントカウント |
| `GET`  | `/stats/unique-users`  | 日ごとにグループ化されたユニークユーザーカウント |

> **静的ルートをパラメーター化ルートの前に**: `/events/by-property` は `/events/{id}` の前に登録して、ルーターがリテラルパスを正しくディスパッチするようにします。

---

## スキーマ

```sql
CREATE TABLE IF NOT EXISTS events (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    event_type  TEXT    NOT NULL,
    user_id     TEXT    NOT NULL,
    session_id  TEXT    NOT NULL DEFAULT '',
    properties  TEXT    NOT NULL DEFAULT '{}',
    occurred_at TEXT    NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_events_type     ON events(event_type);
CREATE INDEX IF NOT EXISTS idx_events_occurred ON events(occurred_at);
CREATE INDEX IF NOT EXISTS idx_events_user     ON events(user_id);
```

`properties` は JSON 文字列（`TEXT`）として保存されます。SQLite の `json_extract()` を使うと、別のスキーマなしに読み込み時に blob にクエリできます。3 つのインデックスが最も一般的なアクセスパターン（タイプ別、時間範囲別、ユーザー別）をカバーします。

---

## イベント作成: JSON properties blob

`POST /events` は必須の `event_type` と `user_id` に加えて柔軟な `properties` オブジェクトを受け付けます:

```php
$eventType  = trim((string) $body['event_type']);
$userId     = trim((string) $body['user_id']);
$sessionId  = isset($body['session_id']) && is_string($body['session_id']) ? $body['session_id'] : '';
$properties = isset($body['properties']) && is_array($body['properties'])
    ? json_encode($body['properties'], JSON_THROW_ON_ERROR)
    : '{}';
$occurredAt = isset($body['occurred_at']) && is_string($body['occurred_at'])
    ? $body['occurred_at']
    : (new \DateTimeImmutable())->format('Y-m-d\TH:i:s\Z');
```

- `properties` は JSON オブジェクトでなければならない（`is_array()` チェック） — スカラー値は `'{}'` にフォールバック。
- `occurred_at` は呼び出し元が提供するか、デフォルトで現在時刻 — 有効な範囲内かのサーバーサイド強制はない。
- `JSON_THROW_ON_ERROR` で不正な中間 JSON が `false` を生成するのではなく即座にスローされる。

読み込み時のデシリアライズ:
```php
'properties' => json_decode($event->properties, true, 512, JSON_THROW_ON_ERROR),
```

---

## `json_extract()` を使った JSON プロパティ検索

`GET /events/by-property?key=page&value=/home` はプロパティのキー/値でイベントをフィルタリングします:

```php
$rows = $this->executor->fetchAll(
    'SELECT * FROM events WHERE json_extract(properties, ?) = ? ORDER BY occurred_at DESC LIMIT ? OFFSET ?',
    ['$.' . $propertyKey, $propertyValue, $limit, $offset],
);
```

`json_extract(properties, '$.page')` は JSON blob から `page` フィールドを抽出します。パス `'$.' . $propertyKey` は連結で構築され、パス自体はパラメーター化**されません** — SQLite の `json_extract()` はパス式に対してバウンドパラメーターではなくリテラルパス文字列のみを受け付けます。キーはクエリ文字列から来ますが、それ以上バリデーションされません（V-05 参照）。

`= ?` は抽出された値をパラメーター化されたバインディングとして提供された `$propertyValue` と比較します — 値による SQL インジェクションはブロックされます。パス連結が監査すべき境界です。

---

## 集計クエリ

### 日ごとのイベントカウント

```php
$rows = $this->executor->fetchAll(
    "SELECT strftime('%Y-%m-%d', occurred_at) AS day, COUNT(*) AS count
     FROM events
     WHERE occurred_at >= ? AND occurred_at < ?
     GROUP BY strftime('%Y-%m-%d', occurred_at)
     ORDER BY day ASC",
    [$from, $to],
);
```

`strftime('%Y-%m-%d', occurred_at)` はタイムスタンプを日付に切り捨てます。同じ式の `GROUP BY` で同じ日のすべてのイベントをグループ化します。`$from` と `$to` の両方がパラメーター化 — SQL への文字列連結はありません。

### タイプごとのイベントカウント

```php
$rows = $this->executor->fetchAll(
    'SELECT event_type, COUNT(*) AS count
     FROM events
     WHERE occurred_at >= ? AND occurred_at < ?
     GROUP BY event_type
     ORDER BY count DESC',
    [$from, $to],
);
```

`ORDER BY count DESC` で最も頻繁なイベントタイプを先に表示します。

### 日ごとのユニークユーザー

```php
$rows = $this->executor->fetchAll(
    "SELECT strftime('%Y-%m-%d', occurred_at) AS day, COUNT(DISTINCT user_id) AS unique_users
     FROM events
     WHERE occurred_at >= ? AND occurred_at < ?
     GROUP BY strftime('%Y-%m-%d', occurred_at)
     ORDER BY day ASC",
    [$from, $to],
);
```

`COUNT(DISTINCT user_id)` は日ごとに各 `user_id` を一度のみカウントします。

### 日付範囲のデフォルト

```php
private function parseDateRange(ServerRequestInterface $request): array
{
    $from = QueryStringParser::string($request, 'from') ?? '2000-01-01T00:00:00Z';
    $to   = QueryStringParser::string($request, 'to') ?? '2100-01-01T00:00:00Z';

    return [$from, $to];
}
```

広いデフォルト（`2000-01-01` から `2100-01-01`）により、日付範囲なしの統計もすべてのイベントを含みます。本番では、大規模データセットでのフルテーブルスキャンを避けるためにデフォルト範囲を合理的なウィンドウ（例: 直近 30 日）に制限してください。

---

## VULN — 脆弱性アセスメント（FT243）

### V-01 — 認証なし: 誰でもイベントを記録できる

**リスク**: 任意の呼び出し元が任意の `event_type` と `user_id` でイベントを送信できる。API キー、セッション、トークンチェックがない。

**影響**: 攻撃者が何百万もの偽イベントでアナリティクスデータセットを汚染し、統計を歪め、任意のユーザー ID になりすませる。

**判定**: **EXPOSED** — 書き込みエンドポイントに API キーまたは JWT 認証を追加する。読み取り専用の統計はパブリックのままでもよいが、取り込みは認証が必要。

---

### V-02 — 統計の認可なし: 統計は誰でも読める

**リスク**: `GET /stats/per-day`、`/stats/per-type`、`/stats/unique-users` は認証なしで集計データを返す。

**影響**: 競合他社やクローラーが製品使用トレンド、デイリーアクティブユーザー、機能採用を監視できる。

**判定**: **EXPOSED** — 統計エンドポイントを認証されたロール（admin、analytics viewer）に制限する。統計が意図的にパブリックな場合は設計上の決定としてドキュメント化する。

---

### V-03 — `user_id` はユーザー提供: アイデンティティの検証なし

**リスク**: `user_id` は呼び出し元がその ID を所有しているという証明なしにリクエストボディから直接取得される。

```json
{"event_type": "login", "user_id": "alice", "occurred_at": "2026-01-01T00:00:00Z"}
```

**影響**: 攻撃者が任意のユーザー ID のアクティビティを捏造し、ユーザーごとの統計とユニークユーザーカウントを操作できる。

**判定**: **EXPOSED** — 認証コンテキストでは、リクエストボディではなくトークン/セッションの検証済みアイデンティティから `user_id` を導出する。

---

### V-04 — `occurred_at` はユーザー提供: イベントのバックデートとフューチャーデート

**リスク**: `occurred_at` フィールドは範囲バリデーションなしに呼び出し元から受け付けられる。

```json
{"event_type": "purchase", "user_id": "alice", "occurred_at": "2020-01-01T00:00:00Z"}
```

**影響**: 攻撃者が任意の過去の時間スロット（バックデート）または遠い将来にイベントを挿入でき、時系列統計を歪める。

**判定**: **EXPOSED** — `occurred_at` が許容可能なウィンドウ（例: 直近 24 時間から +5 分）内に収まることをバリデーションし、範囲外のタイムスタンプを拒否する。

---

### V-05 — `json_extract()` パス連結: JSON パスインジェクション

**リスク**: プロパティキーが JSON パス式に直接連結される: `'$.' . $propertyKey`。`$propertyKey` が安全な識別子かのバリデーションがない。

**攻撃**:
```
GET /events/by-property?key=x%22%5D+OR+1%3D1+--&value=y
```
結果: `json_extract(properties, '$.x"] OR 1=1 --')` — SQLite はパス引数を SQL ではなく `json_extract` に渡す文字列リテラルとして解釈する。パスは SQL として実行されない — SQLite の JSON 関数が文字列として処理する。無効なパスは `NULL` を返すため、クエリはすべての行ではなく行なしを返す。

**観察**: `json_extract()` は 2 番目の引数全体をパス式として扱う。不正なパス（`$.x"] OR 1=1 --`）はすべての行に `NULL` を返す — SQL インジェクションなし。ただし、動作は SQLite の JSON 実装に依存します — 多層防御として `preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $key)` で `$propertyKey` をバリデーションするとよいでしょう。

**判定**: **PARTIALLY BLOCKED** — SQLite の `json_extract()` がパス引数をサンドボックス化する。多層防御のために明示的なキーバリデーション（`[a-zA-Z_][a-zA-Z0-9_]*`）を追加する。

---

### V-06 — 無制限の event_type: 許可リストなし

**リスク**: `event_type` は任意の空でない文字列を受け付ける。非常に長い文字列や高カーディナリティのタイプが `countPerType` 結果セットを膨張させる。

```json
{"event_type": "aaaa....(10000 chars)", "user_id": "x"}
```

**影響**: `GROUP BY event_type` の無制限なカーディナリティがメモリ負荷を引き起こす可能性がある。非常に長い文字列によるストレージの膨張。

**判定**: **EXPOSED** — 最大長チェック（例: 100 文字）を追加し、必要に応じてイベントタイプ許可リストまたは長さ制限を設ける。

---

### V-07 — `from`/`to` 日付パラメーターによる SQL インジェクション

**攻撃**: 日付範囲に SQL メタキャラクターを渡す。

```
GET /stats/per-day?from=2000-01-01%27+OR+%271%27%3D%271&to=2100-01-01
```

**観察**: `$from` と `$to` の両方がパラメーター化された値（`?` プレースホルダー）としてバインドされる。SQL エンジンはそれらを SQL フラグメントではなくリテラル文字列として扱う。

**判定**: **BLOCKED** — パラメーター化されたクエリが日付パラメーターによる SQL インジェクションを防ぐ。

---

### V-08 — properties のサイズ: JSON blob にサイズ制限なし

**リスク**: `properties` はサイズバリデーションなしに `TEXT` として保存される。攻撃者が数メガバイトの JSON オブジェクトを送信できる。

```json
{"event_type": "x", "user_id": "y", "properties": {"data": "AAAA....(1MB)"}}
```

**影響**: 各大きなイベントが大量のストレージを消費する。大きなイベントの大量挿入がディスク容量を枯渇させる可能性がある。

**判定**: **EXPOSED** — 生の `properties` 値のサイズチェックを追加する（例: `strlen($raw) > 65535 → 422`）。外部の制限としてリクエストサイズミドルウェアを使用する。

---

### V-09 — イベントフラッド: POST /events にレート制限なし

**リスク**: 取り込みエンドポイントにレート制限がない。

**影響**: 1 つのクライアントが 1 秒あたり数百万のイベントを送信でき、データベースとストレージを圧倒する。

**判定**: **EXPOSED** — 書き込みエンドポイントに `ThrottleMiddleware` または IP ごと / API キーごとのレート制限を適用する。

---

### V-10 — 統計の露出: `COUNT(DISTINCT user_id)` がユーザーカウントを漏洩

**リスク**: `GET /stats/unique-users` は日ごとの個別ユーザー ID のカウントを返す。

**影響**: 認証なしでは、デイリーアクティブユーザーカウント（機密性の高いビジネス指標）が漏洩する。

**判定**: **EXPOSED**（V-02 と同じ根本原因）。統計エンドポイントを制限または認証する。

---

## VULN サマリー

| # | 脆弱性 | 判定 |
|---|---------------|---------|
| V-01 | 書き込みエンドポイントに認証なし | EXPOSED |
| V-02 | 統計エンドポイントが誰でも読める | EXPOSED |
| V-03 | `user_id` が未検証（アイデンティティなりすまし） | EXPOSED |
| V-04 | `occurred_at` がユーザー提供（バックデート/フューチャーデート） | EXPOSED |
| V-05 | `json_extract()` パス連結 | PARTIALLY BLOCKED |
| V-06 | `event_type` に許可リスト / 長さ制限なし | EXPOSED |
| V-07 | 日付範囲パラメーターによる SQL インジェクション | BLOCKED |
| V-08 | `properties` JSON blob にサイズ制限なし | EXPOSED |
| V-09 | POST /events にレート制限なし | EXPOSED |
| V-10 | ユニークユーザーカウントが DAU 指標を漏洩 | EXPOSED |

**本番前に必要な重大な修正**:
1. **V-01 / V-02 / V-10** — 書き込みと統計エンドポイントに認証（API キーまたは JWT）を追加する
2. **V-03** — リクエストボディではなく検証済みアイデンティティから `user_id` を導出する
3. **V-04** — `occurred_at` が許容可能な時間ウィンドウ内に収まることをバリデーションする
4. **V-05** — `preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $key)` バリデーションを追加する
5. **V-06** — `event_type` の最大長チェックを追加する（例: 100 文字）
6. **V-08** — `properties` のサイズ制限を追加する（例: 64 KB）
7. **V-09** — POST /events にレート制限を適用する

---

## 関連ハウツー

- [`event-sourcing.md`](event-sourcing.md) — イミュータブルイベントログパターン
- [`api-usage-metering.md`](api-usage-metering.md) — クォータ強制付きメータリング API
- [`quota-management.md`](quota-management.md) — QuotaWindow によるリソースごとのクォータ
- [`cursor-pagination.md`](cursor-pagination.md) — 大量イベントフィードの効率的なページネーション
