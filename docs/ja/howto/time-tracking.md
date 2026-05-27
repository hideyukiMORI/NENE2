# ハウツー: タイムトラッキング API

> **FT リファレンス**: FT246 (`NENE2-FT/timelog`) — タイムトラッキング API

タイマーエントリが `start_time` と nullable な `end_time`（`NULL` = 実行中、非 `NULL` = 停止済み）を持ち、一度に 1 つのタイマーしか実行できず、SQLite の `julianday()` で時間が計算され、日次サマリーがカレンダー日ごとの合計トラッキング秒数を集計するストップウォッチスタイルのタイムトラッキング API を実演します。

---

## ルート

| メソッド | パス | 説明 |
|--------|------|------|
| `POST`   | `/timers/start`   | 新しいタイマーを開始する（すでに実行中の場合は失敗） |
| `POST`   | `/timers/stop`    | 現在実行中のタイマーを停止する                       |
| `GET`    | `/timers/running` | 現在実行中のタイマーを取得する（または `running: false`） |
| `GET`    | `/timers/summary` | 日次サマリー: 日ごとの合計秒数とエントリ数           |
| `GET`    | `/timers`         | エントリを一覧表示する（ページネーション、ラベルと日付でフィルタリング可） |
| `GET`    | `/timers/{id}`    | 単一タイマーエントリを取得する                       |
| `DELETE` | `/timers/{id}`    | タイマーエントリを削除する（`204 No Content`）        |

> **静的ルートを最初に**: `/timers/start`、`/timers/stop`、`/timers/running`、`/timers/summary` はすべて `/timers/{id}` より前に登録されるため、リテラルパスはパラメーター化されたセグメントとしてキャプチャされません。

---

## スキーマ

```sql
CREATE TABLE IF NOT EXISTS time_entries (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    label      TEXT NOT NULL,
    start_time TEXT NOT NULL,
    end_time   TEXT,              -- NULL = 実行中
    created_at TEXT NOT NULL
);
```

`end_time` は nullable です — `NULL` はタイマーがまだ実行中であることを意味します。`NOT NULL` は停止済みであることを意味します。別の `status` カラムはありません; `end_time` の有無が実行状態をエンコードします。

---

## 実行状態: `end_time IS NULL`

タイマーの実行状態は `end_time` カラムから純粋に検出されます:

```php
final readonly class TimeEntry
{
    public function isRunning(): bool
    {
        return $this->endTime === null;
    }

    public function durationSeconds(): ?int
    {
        if ($this->endTime === null) {
            return null;  // まだ実行中 — まだ時間がない
        }
        $start = new \DateTimeImmutable($this->startTime);
        $end   = new \DateTimeImmutable($this->endTime);
        return (int) $end->getTimestamp() - (int) $start->getTimestamp();
    }
}
```

`isRunning()` は `endTime` が `null` のとき `true` を返します。`durationSeconds()` は実行中のタイマーに対して `null` を返します — タイマーが停止するまで時間を計算できません。レスポンスにはアクティブなエントリに対して `"running": true` と `"duration_seconds": null` が含まれます。

---

## シングルトンタイマー: 一度に 1 つのみ実行できる

`start()` は新しいタイマーを作成する前に実行中のタイマーを確認します:

```php
public function start(string $label, string $startTime, string $createdAt): TimeEntry
{
    $running = $this->findRunning();
    if ($running !== null) {
        throw new TimerAlreadyRunningException($running->id);
    }

    $this->executor->execute(
        'INSERT INTO time_entries (label, start_time, end_time, created_at) VALUES (?, ?, NULL, ?)',
        [$label, $startTime, $createdAt],
    );

    return $this->findById($this->executor->lastInsertId());
}
```

タイマーがすでに実行中の場合、`TimerAlreadyRunningException` がスローされます → `409 Conflict`。
`end_time` はリテラルの `NULL` SQL 値として挿入されます。

実行中のタイマーの検索:

```php
public function findRunning(): ?TimeEntry
{
    $row = $this->executor->fetchOne(
        'SELECT * FROM time_entries WHERE end_time IS NULL ORDER BY start_time DESC LIMIT 1',
        [],
    );
    return $row !== null ? $this->hydrate($row) : null;
}
```

`WHERE end_time IS NULL` — 標準的な SQL の `NULL` 比較（`= NULL` ではなく）。`LIMIT 1` は不変条件が違反された場合に複数行が返されるのを防ぎます。

---

## タイマーの停止: `stop()`

```php
public function stop(string $endTime): TimeEntry
{
    $running = $this->findRunning();
    if ($running === null) {
        throw new NoRunningTimerException();
    }

    $this->executor->execute(
        'UPDATE time_entries SET end_time = ? WHERE id = ?',
        [$endTime, $running->id],
    );

    return $this->findById($running->id);
}
```

`stop()` は実行中のタイマーを見つけ、`end_time` を設定し、計算された時間付きの更新されたエントリを返します。タイマーが実行中でない場合、`NoRunningTimerException` がスローされます → `409 Conflict`。

---

## 時間計算: SQL での `julianday()`

集計サマリーの場合、時間は SQLite の `julianday()` 関数を使って SQL で計算されます:

```sql
SUM(CAST((julianday(end_time) - julianday(start_time)) * 86400 AS INTEGER)) AS total_seconds
```

`julianday()` は ISO 日時文字列をユリウス日数（紀元前 4713 年 1 月 1 日正午からの日数を表す実数）に変換します。2 つのユリウス日数を引くと日数の差が得られます。`86400` を掛けると日数が秒数に変換されます。`CAST(... AS INTEGER)` で整数秒に切り捨てます。

`SUM(...)` はその日のすべての完了エントリを合計します。`WHERE end_time IS NOT NULL` はサマリーからまだ実行中のタイマーをフィルタリングします。

個別エントリに対する PHP サイドの計算:

```php
$start = new \DateTimeImmutable($this->startTime);
$end   = new \DateTimeImmutable($this->endTime);
return (int) $end->getTimestamp() - (int) $start->getTimestamp();
```

どちらのアプローチも UTC タイムスタンプに対して同じ結果を生成します。SQL アプローチは集計に使用されます（すべての行を取得して合計するのを避けるため）; PHP アプローチは個別エントリのシリアライゼーションに使用されます。

---

## 日次サマリー集計

```php
$sql = 'SELECT date(start_time) AS day,
               SUM(CAST((julianday(end_time) - julianday(start_time)) * 86400 AS INTEGER)) AS total_seconds,
               COUNT(*) AS entry_count
          FROM time_entries
         WHERE ' . implode(' AND ', $where) . '
         GROUP BY day
         ORDER BY day DESC';
```

`date(start_time)` は `start_time` ISO 文字列からカレンダー日付を抽出します。`GROUP BY day` は同じ日のすべての完了エントリをグループ化します。`ORDER BY day DESC` は最新の日を最初に返します。

`$where` 句は常に `['end_time IS NOT NULL']` から始まり実行中のタイマーを除外し、次に日付範囲フィルター用に `date(start_time) >= ?` と `date(start_time) <= ?` をオプションで追加します。

---

## 日付のみのフィルタリングのための `date()` 関数

カレンダー日付でエントリをフィルタリングするには SQLite の `date()` 関数を使用します:

```php
if ($date !== null) {
    $where[]  = "date(start_time) = ?";
    $params[] = $date;
}
```

`date(start_time)` は ISO 日時文字列から `YYYY-MM-DD` のみを抽出します。`= ?` は抽出された日付をフィルター値と比較します。これにより、時刻コンポーネントに関わらず指定された日に開始されたすべてのエントリが正しくマッチします。

---

## `LIKE` によるラベルフィルタリング

```php
if ($label !== null) {
    $where[]  = 'label LIKE ?';
    $params[] = '%' . $label . '%';
}
```

`LIKE '%label%'` は SQLite のデフォルト照合順序で大文字小文字を区別しない部分文字列マッチを実行します。`$label` 内の特殊文字 `%` と `_` は LIKE ワイルドカードとして解釈されます — 厳密なリテラルマッチングが必要な場合はエスケープしてください。

---

## `GET /timers/running` レスポンス契約

実行中のエンドポイントはタイマーがアクティブかどうかに関わらず一貫した形状を返します:

```php
if ($entry === null) {
    return $this->json->create(['running' => false, 'entry' => null]);
}
return $this->json->create(['running' => true, 'entry' => $this->serialize($entry)]);
```

`running: false, entry: null` — タイマーなし。
`running: true, entry: {...}` — `end_time: null` と `duration_seconds: null` を持つアクティブなタイマー。

これにより「実行中のタイマーがない」に対して `404` を避けられます — `404` はリソースが存在しないことを示しますが、「実行中のタイマー」の概念は常に存在します（ただし空です）。`running: false` を使う方が意味的にすっきりしています。

---

## 関連 howto

- [`shift-management.md`](shift-management.md) — nullable な終了時間を持つシフトの出退勤
- [`scheduled-reminders.md`](scheduled-reminders.md) — タイムゾーン対応の日時バリデーション
- [`aggregate-reporting.md`](aggregate-reporting.md) — `GROUP BY date` 集計パターン
- [`handle-timezones.md`](handle-timezones.md) — UTC ストレージとタイムゾーン変換
