# タイムゾーンの処理方法

PHP のタイムゾーン処理にはいくつかのサイレントな失敗モードがあります。このガイドでは、実際の NENE2 フィールドトライアルで遭遇したパターンと落とし穴を解説します。

## `DateTimeImmutable` 作成時は常にタイムゾーンを指定する

`new DateTimeImmutable('now')` はサーバーの `date.timezone` ini 設定を使用しますが、これは環境によって異なります。サーバーサイドのタイムスタンプには常に `UTC` を明示的に指定してください:

```php
// 脆弱 — サーバーの date.timezone に依存する
$now = new \DateTimeImmutable('now');

// 正しい — 常に UTC
$now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
```

保存されるタイムスタンプは ISO 8601 UTC 形式でフォーマットしてください:

```php
$now->format('Y-m-d\TH:i:s\Z') // → "2026-05-20T15:00:00Z"
```

## IANA タイムゾーン識別子を明示的にバリデーションする

PHP の `DateTimeZone` コンストラクターは `"EST"` などのタイムゾーン略語を例外なしに受け入れますが、これらは正規の IANA 識別子ではありません。正しい IANA 形式は `"America/New_York"` です。

```php
// これは成功する — しかし "EST" は IANA 識別子ではない
$tz = new \DateTimeZone('EST'); // 例外なし!

// 正しいバリデーション:
try {
    $tz = new \DateTimeZone($input);
} catch (\Exception) {
    throw new InvalidTimezoneException("Unknown timezone: $input");
}

// 非 IANA 略語の追加チェック:
if (!in_array($input, \DateTimeZone::listIdentifiers(), true)) {
    throw new InvalidTimezoneException("Unknown timezone: $input");
}
```

`listIdentifiers()` チェックなしでは、`"EST"`、`"PST"` などの略語が暗黙に通過します。

## `createFromFormat` でローカル日時文字列を解析する

タイムゾーンオフセットなしのローカル日時をユーザー入力から受け取る場合は、明示的なフォーマットとタイムゾーン付きで `createFromFormat` を使用してください:

```php
$tz    = new \DateTimeZone('Asia/Tokyo');
$local = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s', '2026-06-01T10:00:00', $tz);

if ($local === false) {
    // 無効なフォーマット — '2026/06/01 10:00'、'2026-06-01' などはすべて false を返す
    throw new \InvalidArgumentException('Invalid datetime format. Expected YYYY-MM-DDTHH:mm:ss.');
}
```

`new DateTimeImmutable($str, $tz)` よりも `createFromFormat` を優先してください — コンストラクターは寛容で多くのフォーマットを暗黙に受け入れます。

## 保存のためにローカル時間を UTC に変換する

```php
$utc = $local->setTimezone(new \DateTimeZone('UTC'));
// 保存: $utc->format('Y-m-d\TH:i:s\Z')
```

常に UTC をデータベースに保存してください。取得時にローカル時間を再構築できるよう、元のタイムゾーン名も一緒に保存してください。

## DST 遷移: 曖昧な壁時計時間

「時計を戻す」遷移時（例: `America/New_York` の 11 月第 1 日曜日）、一部の壁時計時間が 2 回存在します:

- `2026-11-01 01:30 AM` は EDT（UTC-4）と EST（UTC-5）の両方に存在する

PHP は**最初の発生**（夏/DST 時間）を選択して曖昧さを解決します:

```php
$dt = \DateTimeImmutable::createFromFormat(
    'Y-m-d\TH:i:s',
    '2026-11-01T01:30:00',
    new \DateTimeZone('America/New_York'),
);
// → 05:30 UTC (EDT = UTC-4)、06:30 UTC (EST = UTC-5) ではない
```

これは IANA 標準に準拠しています。アプリケーションが 2 つの発生を区別する必要がある場合（例: カレンダーシステム）、アプリケーションレベルで処理する必要があります — PHP は 2 番目の発生を選択する API を公開していません。

## 完全なローカル→UTC 変換パターン

```php
use Schedule\Event\InvalidTimezoneException;

function localToUtc(string $localDatetime, string $ianaTimezone): \DateTimeImmutable
{
    try {
        $tz = new \DateTimeZone($ianaTimezone);
    } catch (\Exception) {
        throw new InvalidTimezoneException("Unknown timezone: $ianaTimezone");
    }

    // 非 IANA 略語を拒否（例: "EST"）
    if (!in_array($ianaTimezone, \DateTimeZone::listIdentifiers(), true)) {
        throw new InvalidTimezoneException("Unknown timezone: $ianaTimezone");
    }

    $local = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s', $localDatetime, $tz);

    if ($local === false) {
        throw new \InvalidArgumentException("Cannot parse datetime: $localDatetime");
    }

    return $local->setTimezone(new \DateTimeZone('UTC'));
}
```

## マルチタイムゾーンリストクエリ

UTC で保存されたイベントを一覧表示するとき、出力時にビューワーのタイムゾーンに変換します:

```php
$viewTz = QueryStringParser::string($request, 'timezone');

if ($viewTz !== null) {
    try {
        $tz    = new \DateTimeZone($viewTz);
        $local = (new \DateTimeImmutable($event->startUtc, new \DateTimeZone('UTC')))->setTimezone($tz);
        $data['start_local'] = $local->format('Y-m-d\TH:i:s');
    } catch (\Exception) {
        // 無効なリクエストタイムゾーン — 変換を暗黙にスキップ
    }
}
```

---

## SQLite 固有: `datetime('now')` は常に UTC を返す

SQLite の組み込み日時関数は、サーバーの OS タイムゾーンや PHP の `date.timezone` 設定に関わらず、常に **UTC** で動作します。

```sql
SELECT datetime('now');          -- → "2026-05-27 11:30:00"  (UTC)
SELECT date('now');              -- → "2026-05-27"            (UTC 日付)
SELECT date('now', '+1 day');    -- → "2026-05-28"            (UTC + 1 日)
SELECT datetime('now', '-9 hours'); -- → JST 近似（手動オフセット — これは避けること）
```

**これは通常必要なもの**: タイムスタンプを UTC の TEXT として保存し、UTC で比較します。

### UTC で「今日」のフィルタリング

```sql
-- 今日（UTC）作成されたレコード
SELECT * FROM events WHERE DATE(created_at) = DATE('now');

-- 今後 30 日以内のレコード（UTC）
SELECT * FROM reminders WHERE reminder_at <= DATE('now', '+30 days');

-- 特定の月のレコード（UTC）
SELECT * FROM logs WHERE STRFTIME('%Y-%m', created_at) = '2026-05';
```

### 落とし穴: 「今日」はタイムゾーンによって異なる

ユーザーが JST（UTC+9）にいる場合、JST の「今日」は UTC の「今日」より 9 時間早く始まります。
SQLite の `DATE('now')` は UTC 日付を返します — これはミスマッチです。

```php
// 誤り: SQLite の DATE('now') = UTC 日付、ユーザーのローカル日付ではない
$rows = $this->db->fetchAll("SELECT * FROM tasks WHERE DATE(due_date) = DATE('now')");

// 正しい: PHP でユーザーの「今日」を計算してパラメーターとして渡す
$todayUtc = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d');
$rows = $this->db->fetchAll(
    "SELECT * FROM tasks WHERE DATE(due_date) = ?",
    [$todayUtc],
);
```

「今日」が UTC を意味するサービスでは `DATE('now')` で問題ありません。ユーザー向けの「今日が期限」機能では、ユーザーのタイムゾーンを使って PHP で境界を計算し、バインドパラメーターとして渡してください。

### カラム値を使った動的インターバル

SQLite では `date()` をカラム値から構築した文字列と組み合わせることができます:

```sql
-- interval_days カラムに基づいて next_review_at = 今日 のレコード
SELECT * FROM cards WHERE next_review_at <= DATE('now');

-- 次のレビュー日を動的に計算（結果を保存、SELECT で依存しない）
SELECT DATE('now', '+' || interval_days || ' days') AS next_date FROM cards;
```

スケジュールを進める `UPDATE` ステートメントで便利です:

```php
$this->db->execute(
    "UPDATE cards SET next_review_at = DATE('now', '+' || interval_days || ' days') WHERE id = ?",
    [$cardId],
);
```

### `STRFTIME` フォーマットリファレンス

| パターン | 出力 | 用途 |
|---------|--------|-----|
| `%Y-%m-%d` | `2026-05-27` | 完全な日付 |
| `%Y-%m` | `2026-05` | 年月グループ化 |
| `%Y-%W` | `2026-22` | 年 + **日曜始まり**週番号（0〜53） |
| `%H:%M:%S` | `11:30:00` | 時間のみ |
| `%s` | Unix タイムスタンプ | エポックからの整数秒 |

**`%W` は日曜始まり**であり、ISO 8601（月曜始まり）ではありません。月曜始まりの週番号には PHP で週の境界を計算してください:

```php
// 現在の ISO 週の月曜日を取得
$monday = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
    ->modify('Monday this week')
    ->format('Y-m-d');

$sunday = (new \DateTimeImmutable($monday))->modify('+6 days')->format('Y-m-d');

$rows = $this->db->fetchAll(
    "SELECT * FROM workouts WHERE workout_date BETWEEN ? AND ?",
    [$monday, $sunday],
);
```
