# ハウツー: タイムゾーン付き ISO 8601 日時のバリデーション

ユーザーが制御する日時文字列を受け入れるには、慎重なバリデーションが必要です。このガイドでは最も重要な 2 つの落とし穴を解説します: **PHP が無効なタイムゾーンオフセットをサイレントに受け入れること**、および**異なるタイムゾーンオフセット間で文字列比較が失敗すること**です。

---

## V::isoDatetime — フォーマットバリデーション

```php
V::isoDatetime(mixed $raw): ?string
```

`±HH:MM` オフセット形式の日時文字列をバリデーションします:

```
✅ 2024-01-15T12:30:00+09:00   (JST)
✅ 2024-06-01T00:00:00+00:00   (UTC)
✅ 2024-12-31T23:59:59-05:00   (EST)
✅ 2026-06-15T09:00:00-14:00   (UTC−14, ハウランド島)
✅ 2026-06-15T09:00:00+14:00   (UTC+14, キリバス)

❌ 2024-01-15                   (日付のみ、時刻なし)
❌ 2024-01-15T12:00:00Z         ('Z' サフィックス、±HH:MM ではない)
❌ 2024-01-15T12:00:00          (オフセットなし)
❌ 2024-02-30T00:00:00+00:00   (2 月 30 日は存在しない)
❌ 2024-13-01T00:00:00+00:00   (月 13 は存在しない)
❌ 2026-06-15T09:00:00+25:00   (無効なオフセット — +14:00 を超える)
```

### 実装

```php
public static function isoDatetime(mixed $raw): ?string
{
    if (!is_string($raw)) return null;

    // 厳格な正規表現: ±HH:MM 必須、Z 不可、時刻のみ不可
    if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}([+-])(\d{2}):(\d{2})$/', $raw, $m)) {
        return null;
    }

    // オフセット範囲のバリデーション: 有効な UTC オフセットは −14:00 … +14:00。
    // PHP の DateTimeImmutable は +25:00 等の無効なオフセットをサイレントに受け入れる。
    $tzHours   = (int) $m[2];
    $tzMinutes = (int) $m[3];
    if ($tzHours > 14 || $tzMinutes > 59 || ($tzHours === 14 && $tzMinutes > 0)) {
        return null;
    }

    // DateTimeImmutable は入力タイムゾーンを保持する — サーバーのローカルタイムゾーンで
    // サイレントに再フォーマットする strtotime + date() を避ける。
    $dt = DateTimeImmutable::createFromFormat(DATE_ATOM, $raw);
    if ($dt === false) return null;

    // ラウンドトリップ比較でオーバーフロー日付をキャッチする（2 月 30 日 → 3 月 1 日等）
    return $dt->format(DATE_ATOM) === $raw ? $raw : null;
}
```

### なぜ `strtotime` + `date()` を使わないのか？

```php
// ❌ 間違い — date() はサーバーのローカルタイムゾーンを使用する
$ts = strtotime('2024-01-15T12:30:00+09:00');
$canonical = date('c', $ts);
// サーバーが UTC の場合: '2024-01-15T03:30:00+00:00' — タイムゾーンが失われる！
```

```php
// ✅ 正しい — DateTimeImmutable は元のオフセットを保持する
$dt = DateTimeImmutable::createFromFormat(DATE_ATOM, '2024-01-15T12:30:00+09:00');
$dt->format(DATE_ATOM); // → '2024-01-15T12:30:00+09:00' ✓
```

---

## V::futureDatetime — タイムゾーン間の未来チェック

```php
V::futureDatetime(mixed $raw, string $now): ?string
```

日時が `$now` より**厳密に後**の場合のみ、バリデーション済み文字列を返します。

### 重大なバグ: 文字列比較はタイムゾーン間で失敗する

```php
$now  = '2026-06-01T10:00:00+00:00';  // UTC 10:00

// JST 18:00 = UTC 09:00 → 1 時間「過去」
$pastJst = '2026-06-01T18:00:00+09:00';

// ❌ 間違い: 文字列比較は未来と判定する ("T18" > "T10")
$pastJst > $now  // → TRUE   ← 間違い！実際は過去！

// ✅ 正しい: DateTimeImmutable 比較は先に UTC に正規化する
$dtObj = new DateTimeImmutable('2026-06-01T18:00:00+09:00');  // UTC 09:00
$nowObj = new DateTimeImmutable('2026-06-01T10:00:00+00:00');  // UTC 10:00
$dtObj > $nowObj  // → FALSE ✓ (正しく過去と判定)
```

負のオフセットでも逆の誤りが発生します:

```php
// EST 08:00 = UTC 13:00 → 3 時間「未来」
$futureEst = '2026-06-01T08:00:00-05:00';

// ❌ 間違い: 文字列比較は過去と判定する ("T08" < "T10")
$futureEst > $now  // → FALSE  ← 間違い！実際は未来！

// ✅ 正しい: オブジェクト比較
$dtObj = new DateTimeImmutable('2026-06-01T08:00:00-05:00');  // UTC 13:00
$dtObj > $nowObj  // → TRUE ✓ (正しく未来と判定)
```

### 実装

```php
public static function futureDatetime(mixed $raw, string $now): ?string
{
    $dt = self::isoDatetime($raw);
    if ($dt === null) return null;

    $dtObj  = DateTimeImmutable::createFromFormat(DATE_ATOM, $dt);
    $nowObj = DateTimeImmutable::createFromFormat(DATE_ATOM, $now);

    if ($dtObj === false || $nowObj === false) return null;

    // オブジェクト比較は比較前に両方を UTC に正規化する。
    return $dtObj > $nowObj ? $dt : null;
}
```

### ルートハンドラーでの使用例

```php
private function handleCreate(ServerRequestInterface $request): ResponseInterface
{
    // ...
    $rawRemindAt = $body['remind_at'] ?? null;

    if (!is_string($rawRemindAt)) {
        return $this->responseFactory->create(
            ['error' => 'remind_at is required (ISO 8601 with timezone, e.g. 2026-06-01T09:00:00+09:00).'],
            422,
        );
    }

    // タイムゾーン保持の「現在時刻」のために DateTimeImmutable を使用する
    $now      = (new DateTimeImmutable())->format(DATE_ATOM);
    $remindAt = V::futureDatetime($rawRemindAt, $now);

    if ($remindAt === null) {
        return $this->responseFactory->create(
            ['error' => 'remind_at must be a valid ISO 8601 datetime with timezone and must be in the future.'],
            422,
        );
    }

    // $remindAt はこれで安全に保存できる — タイムゾーン保持の送信文字列そのまま。
    $reminder = $this->repository->create($userId, $message, $remindAt, $now);
    // ...
}
```

---

## タイムゾーンの保持

`remind_at`（またはユーザー送信の日時）はバリデーション済みのまま保存してください — UTC に変換しないでください。

```php
// ✅ バリデーション済み文字列をそのまま保存する
'INSERT INTO reminders (remind_at, ...) VALUES (:remind_at, ...)'
// :remind_at = '2026-06-15T09:00:00+09:00'

// API レスポンスでもそのまま返す
$reminder->remindAt  // → '2026-06-15T09:00:00+09:00'
```

これはユーザーの意図を尊重し、暗黙のタイムゾーン変換を避けます。SQL での順序付け/比較に UTC 正規化が必要な場合は、書き込み時に計算される別の `remind_at_utc` カラムを追加してください。

---

## バリデーション済み入力 → 安全な SQL

`V::isoDatetime()` / `V::futureDatetime()` の後、文字列はパラメーター化クエリで安全に挿入できます。生の日時文字列を SQL に直接補間しないでください。

```php
// ✅ 安全 — 事前バリデーション済み、パラメーター化
$stmt->execute(['remind_at' => $remindAt]);

// ❌ 危険 — 生のユーザー入力を補間
$sql = "INSERT INTO reminders (remind_at) VALUES ('{$_POST['remind_at']}')";
```

---

## 関連

- FT181 — reminderlog: ISO 8601 Datetime Validation & Timezone-Aware API
- [RFC 3339](https://www.rfc-editor.org/rfc/rfc3339) — Date and Time on the Internet
- [IANA Time Zone Database](https://www.iana.org/time-zones) — UTC オフセットリファレンス
- `docs/howto/json-merge-patch.md` — created_at にも isoDatetime を使用
