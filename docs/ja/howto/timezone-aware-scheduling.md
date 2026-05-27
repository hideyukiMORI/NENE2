# ハウツー: タイムゾーン対応イベントスケジューリング

> **FT リファレンス**: FT286 (`NENE2-FT/schedulelog`) — タイムゾーン対応スケジューリング: UTC ストレージ + ローカル時間変換、DateTimeZone::listIdentifiers() による IANA タイムゾーンバリデーション、InvalidTimezoneException、動的 `?timezone` クエリパラメーター、19 テスト / 39 アサーション PASS。

このガイドでは、時刻を UTC で保存し、クライアントが要求した任意のタイムゾーンで提示するイベントスケジューリング API の構築方法を説明します。

## なぜ UTC で保存するのか？

UTC は普遍的な参照点です。ローカル時間は曖昧であり（DST の変更、タイムゾーンルールの変更）、クライアントの場所によって異なります。UTC で保存することで:
- ソートと比較は常に正確
- クライアントはローカルタイムゾーンで表示できる
- DST の移行が過去のデータに曖昧さを生じさせない

## スキーマ

```sql
CREATE TABLE IF NOT EXISTS events (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    title       TEXT    NOT NULL,
    timezone    TEXT    NOT NULL,      -- イベント作成者の IANA タイムゾーン
    start_utc   TEXT    NOT NULL,      -- UTC ISO 8601: 2026-05-20T15:00:00Z
    start_local TEXT    NOT NULL,      -- ローカル ISO 8601: 2026-05-20T10:00:00
    created_at  TEXT    NOT NULL
);
```

`start_utc` と `start_local` の両方が保存されます。`start_utc` が権威ある値です; `start_local` は作成者のタイムゾーン用の便宜キャッシュです。

## エンドポイント

| メソッド | パス | 説明 |
|--------|------|------|
| `POST` | `/events`       | イベントを作成する（タイムゾーン + ローカル開始時間 → UTC） |
| `GET`  | `/events`       | イベントを一覧表示する（オプション `?timezone=America/New_York`） |
| `GET`  | `/events/{id}`  | イベントを取得する（オプション `?timezone=`） |

## IANA タイムゾーンバリデーション

PHP の `DateTimeZone` コンストラクターは一部の無効な識別子をサイレントに受け入れます。明示的に検証してください:

```php
final class TimezoneConverter
{
    public static function localToUtc(string $localDatetime, string $ianaTimezone): \DateTimeImmutable
    {
        try {
            $tz = new \DateTimeZone($ianaTimezone);
        } catch (\Exception) {
            throw new InvalidTimezoneException("Unknown timezone: $ianaTimezone");
        }

        // PHP は一部のバージョンで "EST" のような無効な省略形を受け入れる —
        // 正規の IANA リストに対して明示的に検証する。
        $valid = \DateTimeZone::listIdentifiers();
        if (!in_array($ianaTimezone, $valid, true)) {
            throw new InvalidTimezoneException("Unknown timezone: $ianaTimezone");
        }

        $local = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s', $localDatetime, $tz);

        if ($local === false) {
            throw new \InvalidArgumentException("Cannot parse datetime: $localDatetime");
        }

        return $local->setTimezone(new \DateTimeZone('UTC'));
    }
}
```

`DateTimeZone::listIdentifiers()` は PHP がコンパイルした IANA 識別子のリストを返します。非 IANA 文字列（`EST`、`GMT+5` など）は拒否されます。

## イベントの作成: ローカル → UTC

```php
try {
    $utc = TimezoneConverter::localToUtc($start, $timezone);
} catch (InvalidTimezoneException) {
    return $this->problems->create($request, 'validation-failed', 'Validation Failed', 422, null, [
        'errors' => [['field' => 'timezone', 'code' => 'invalid', 'message' => "Unknown timezone: $timezone"]],
    ]);
} catch (\InvalidArgumentException) {
    return $this->problems->create($request, 'validation-failed', 'Validation Failed', 422, null, [
        'errors' => [['field' => 'start', 'code' => 'invalid', 'message' => "Cannot parse datetime: $start"]],
    ]);
}

$startUtc   = TimezoneConverter::formatUtc($utc);                              // "2026-05-20T15:00:00Z"
$startLocal = TimezoneConverter::formatLocal($utc->setTimezone(new \DateTimeZone($timezone)));  // "2026-05-20T10:00:00"
```

## イベントの一覧表示: 動的タイムゾーン変換

`?timezone=` クエリパラメーターはすべてのイベントをその場でクライアントのタイムゾーンに変換します:

```php
$viewTz = isset($params['timezone']) && $params['timezone'] !== '' ? $params['timezone'] : null;

$items = array_map(static function (Event $e) use ($viewTz): array {
    $data = $e->toArray();
    if ($viewTz !== null) {
        try {
            $local = TimezoneConverter::utcToLocal($e->startUtc, $viewTz);
            $data['start_local'] = TimezoneConverter::formatLocal($local);
            $data['view_timezone'] = $viewTz;
        } catch (InvalidTimezoneException) {
            // 無効なビュータイムゾーン: サイレントに UTC を返す
            $data['view_timezone'] = 'UTC';
        }
    }
    return $data;
}, $events);
```

無効な `?timezone=` 値はエラーを返すのではなく、保存された `start_local` にサイレントにフォールバックします — 読み取り専用ビューに適した設計上の選択です。

## UTC フォーマット: Z サフィックス付き ISO 8601

```php
public static function formatUtc(\DateTimeImmutable $dt): string
{
    return $dt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    //                                                                           ^ リテラル Z
}
```

`Z` サフィックスは明示的に UTC を示します（ISO 8601 / RFC 3339 に従う）。`+00:00` を使用するかオフセットを省略する代替手段も許容されますが、`Z` はよりコンパクトで広く認識されています。

## DST 安全な変換

```
例: Asia/Tokyo は UTC+9（DST なし）
ローカル: 2026-05-20T10:00:00  Asia/Tokyo
UTC:   2026-05-20T01:00:00Z

例: America/New_York（DST あり）
ローカル: 2026-05-20T10:00:00  America/New_York（EDT = 夏は UTC-4）
UTC:   2026-05-20T14:00:00Z

ローカル: 2026-01-20T10:00:00  America/New_York（EST = 冬は UTC-5）
UTC:   2026-01-20T15:00:00Z
```

名前付き IANA タイムゾーンを持つ `DateTimeImmutable` は DST を自動的に処理します。固定オフセットではなく、その特定の日付にアクティブなオフセットを使用します。

---

## やってはいけないこと

| アンチパターン | リスク |
|---|---|
| タイムゾーンカラムなしでローカル時間を保存する | 後で UTC に変換できない; DST の変更後に過去のデータが曖昧になる |
| `EST`、`PST`、`GMT+5` をタイムゾーンとして受け入れる | 曖昧な省略形; 一部は複数の IANA ゾーンにマップされる; `DateTimeZone::listIdentifiers()` はこれらを拒否する |
| `listIdentifiers()` を確認せずに `new DateTimeZone($tz)` を使用する | PHP は一部の無効または廃止された識別子をサイレントに受け入れる; 正規バリデーションがこれらをキャッチする |
| IANA 名の代わりに UTC オフセット（`+09:00`）を保存する | オフセットだけでは DST を処理できない; `Asia/Tokyo` は常に +9 だが `America/New_York` は変化する |
| `start_local` でイベントをソートする | ローカル時間の辞書的ソートはタイムゾーンの差を無視する; 常に `start_utc` でソートすること |
| すべてのクエリでタイムゾーン変換を行う | 大規模なデータセットでは高コスト; 一般的なビュータイムゾーンのキャッシュや事前計算を検討する |
| GET で無効な `?timezone=` に 422 を返す | 読み取り専用クエリはグレースフルにデグレードすべき; エラーではなく UTC にフォールバックする |
| `DateTimeImmutable` の代わりに `date()` を使用する | `date()` はサーバーのデフォルトタイムゾーンを使用する; 明示的なゾーンを持つ `DateTimeImmutable` は予測可能 |
