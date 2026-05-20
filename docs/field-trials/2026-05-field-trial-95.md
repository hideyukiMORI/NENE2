# Field Trial 95 — Timezone-Sensitive Scheduling

**Date:** 2026-05-20
**Project:** `/home/xi/docker/NENE2-FT/schedulelog/`
**NENE2 version:** 1.5.28
**Theme:** Timezone-aware event scheduling — IANA timezone validation, local→UTC conversion, DST handling, multi-timezone list view

---

## What was built

An event scheduling API where events are stored with both a UTC timestamp and the original local time. Input is a local datetime string (`YYYY-MM-DDTHH:mm:ss`) plus an IANA timezone name. The API converts to UTC on create and can re-express stored UTC times in any requested timezone on list.

### Endpoints

| Method | Path | Description |
|--------|------|-------------|
| POST | `/events` | Create event (title, timezone, start) |
| GET | `/events` | List events ordered by UTC; optional `?timezone=X` for local-time view |
| GET | `/events/{id}` | Get single event |

### Domain layer

- `TimezoneConverter` — static helpers: `localToUtc()`, `utcToLocal()`, `formatUtc()`, `formatLocal()`
- `InvalidTimezoneException` — thrown for unknown IANA identifiers
- IANA validation: `\DateTimeZone::listIdentifiers()` + explicit membership check (PHP's `DateTimeZone` constructor silently accepts some abbreviations not in the canonical list)

---

## Frictions encountered

### 1. `QueryStringParser::parse()` doesn't exist — correct API is `QueryStringParser::string()` (高)

**Symptom:** `Error: Call to undefined method Nene2\Http\QueryStringParser::parse()` at runtime — all list endpoints return 500.

Natural reflex after `JsonRequestBodyParser::parse($request)` is to call `QueryStringParser::parse($request)`. But these two classes have completely different APIs:

```php
// JsonRequestBodyParser — returns whole array:
$body = JsonRequestBodyParser::parse($request);

// QueryStringParser — per-parameter typed accessors:
$tz = QueryStringParser::string($request, 'timezone'); // returns ?string
```

`QueryStringParser` has no `parse()` method. Developers who infer the API from `JsonRequestBodyParser` hit a fatal error. The inconsistency between the two parsers is a real trap for beginners.

**Workaround used:** `$request->getQueryParams()` directly.

**Suggested fix:** Add `QueryStringParser::parse()` that returns `array<string, mixed>` as an alias for `$request->getQueryParams()`, or document the API inconsistency clearly in the how-to guide.

**DX観点 (初心者目線):** 同じ `Parser` という名前でも API が全く違い、既存のパターンを真似すると実行時エラー。型エラーでなく「メソッドが存在しない」エラーなので静的解析でのみ発見可能。初心者に優しくない設計。

---

### 2. PHP の `DateTimeZone` は "EST" を黙って受け入れる（中）

**Symptom:** `new DateTimeZone('EST')` は例外を投げない。しかし `DateTimeZone::listIdentifiers()` に "EST" は含まれない。

```php
new DateTimeZone('EST');         // succeeds! No exception
in_array('EST', DateTimeZone::listIdentifiers(), true); // false
```

"EST"（東部標準時の略称）はPHPで有効な timezone 文字列として扱われるが、IANA 識別子（`America/New_York` など）ではない。コンストラクタで例外が出ないため、バリデーションに `try/catch (Exception)` だけを使うと不正な略称が通過してしまう。

**Fix:** `\DateTimeZone::listIdentifiers()` による明示的なメンバシップチェックが必須。

**DX観点:** PHP のドキュメントを読んだだけでは気づきにくい落とし穴。NENE2 のガイドに「IANA 識別子の検証は listIdentifiers() で確認せよ」と明記すべき。

---

### 3. DST 境界のあいまい時刻 — PHP は夏時間（最初の出現）を選ぶ（低）

**Symptom:** 2026年11月1日 01:30 AM America/New_York は DST 切り替え後のあいまい時刻。
- EDT (UTC-4) の 01:30 → 05:30 UTC（切り替え前・夏時間）
- EST (UTC-5) の 01:30 → 06:30 UTC（切り替え後・冬時間）

PHP は `DateTimeImmutable::createFromFormat()` でこの時刻を **最初の出現（EDT = UTC-4）** として解釈する。

```php
// Nov 1 2026 01:30 AM → PHP returns 05:30 UTC (not 06:30)
```

これは IANA の仕様（最初の出現）に一致するが、「折り返し時刻を冬時間として扱うべき」という期待を持つ開発者は驚く。

**Status:** 回避策不要だが、ドキュメントで「PHP は最初の出現を選ぶ」と明記する価値あり。

---

### 4. `new DateTimeImmutable()` のデフォルトタイムゾーンはサーバー設定依存（中）

**Symptom:** `new DateTimeImmutable('now')` はサーバーの `date.timezone` ini 設定に依存する。

テスト環境と本番で `date.timezone` が異なると、暗黙の「now」が異なる。

**Fix:** `new DateTimeImmutable('now', new DateTimeZone('UTC'))` で常にタイムゾーンを明示する。

**DX観点:** この落とし穴は有名だが、NENE2 の how-to で「`now` には明示的に UTC を渡せ」と書いてあると初心者が助かる。

---

## Test results

19 tests, 39 assertions — all pass.

Tested scenarios:
- Tokyo (JST=UTC+9): 10:00 → 01:00 UTC
- New York summer (EDT=UTC-4): 09:00 → 13:00 UTC
- London summer (BST=UTC+1): 12:00 → 11:00 UTC
- London winter (GMT=UTC+0): 12:00 → 12:00 UTC
- UTC timezone: no conversion
- DST fall-back 01:30 AM → PHP uses summer time (05:30 UTC)
- Invalid timezone name → 422
- Timezone abbreviation "EST" (not IANA) → 422
- Invalid datetime format → 422
- Date-only string → 422
- ISO8601 with offset → 422 (format mismatch)
- Missing required fields → 422
- List ordered by UTC (multi-timezone)
- List with requested-timezone conversion (01:00 UTC → 03:00 CEST)
- GET by ID
- 404 for missing event
- Same wall-clock time in different timezones = different UTC (naive assumption footgun)

---

## Developer Experience (DX) Review

### 初心者・ロースキル観点での実装しやすさ

難しかった。`TimezoneConverter` を自前で書く必要があり、PHP の DateTimeZone の落とし穴（EST の黙認、listIdentifiers() の必要性、now のデフォルト TZ）を知っていないと正しく実装できない。NENE2 がタイムゾーン変換のユーティリティを提供していれば大幅に楽になる。

`QueryStringParser` の API 不一致（`parse()` がない）も初心者の大きな落とし穴。

### 使ってみた印象

コアのルーティング・DI・DB は快適。タイムゾーン変換の自前実装に多くの時間を使った。もし NENE2 が `TimezoneHelper::localToUtc()` 的なユーティリティを提供していたら、FT の本筋に集中できた。

`QueryStringParser::string($request, 'key')` の API に慣れると使いやすいが、初見では `parse()` を探してしまう。

### 楽しいか・気持ちいいか・快適か

タイムゾーン変換のテストは「日本の 10:00 が UTC では 01:00 になる」を確認する瞬間が楽しい。多タイムゾーンのイベントを一覧して UTC 順にソートされるのを見ると、正しく動いている実感がある。

DST のテスト（あいまい時刻）は少し不安になる — 「PHP はどちらを選ぶのか？」を確かめる過程は面白いが不確実性がある。

### 簡単か

タイムゾーン分野自体は難しい（PHP・IANA・DST の知識が必要）。NENE2 は邪魔をせず、PSR-7 リクエストから値を取り出して変換→保存→返す流れは明快。難しさの 80% は PHP/タイムゾーン知識であり、フレームワーク由来の難しさは `QueryStringParser` の API 差異のみ。

### また使いたいか

はい。難しいドメインでも NENE2 が DB・HTTP まわりをきれいに処理してくれるので、ビジネスロジックに集中できた。

### 初心者に勧めたいか

タイムゾーン操作は「PHP 力」が必要なテーマなので万人に勧めるのは難しい。ただし NENE2 の部分はシンプルで、`QueryStringParser` の API 差異さえドキュメント化されれば推薦度は上がる。

---

## Issues / PRs

- Issue: `QueryStringParser` に `parse()` メソッド追加、または `JsonRequestBodyParser` との API 一貫性ドキュメント化
- Issue: タイムゾーン処理 how-to ガイド追加（IANA 検証・DST・`now` に UTC 明示）
