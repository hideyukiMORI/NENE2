# Field Trial 93 — Unicode / Emoji / Encoding Boundary

**Date:** 2026-05-20
**Project:** `/home/xi/docker/NENE2-FT/unicodelog/`
**NENE2 version:** 1.5.26
**Theme:** Input boundary / encoding — Japanese, emoji, ZWJ sequences, Arabic RTL, null bytes, SQL-injection-style input, `mb_strlen` vs `strlen`, `JSON_UNESCAPED_UNICODE`

---

## What was built

A multilingual user-profile API that accepts names, bios, and tags in any Unicode script. The API exercises:

- Japanese (kanji, hiragana, katakana)
- Emoji — single codepoint (🎉) and ZWJ sequences (👨‍👩‍👧 = U+1F468 U+200D U+1F469 U+200D U+1F467)
- Arabic RTL text
- Mixed scripts (Latin + CJK + Arabic)
- Length validation using `mb_strlen('UTF-8')` — character count, not byte count
- Null byte (`\x00`) rejection
- SQL-injection-style strings stored safely via parameterized queries

### Endpoints

| Method | Path | Description |
|--------|------|-------------|
| POST | `/profiles` | Create a profile |
| GET | `/profiles` | List all profiles |
| GET | `/profiles/{id}` | Get a profile |
| PATCH | `/profiles/{id}` | Update a profile |
| DELETE | `/profiles/{id}` | Delete a profile |

### Schema

```sql
CREATE TABLE IF NOT EXISTS profiles (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT    NOT NULL,
    bio        TEXT    NOT NULL DEFAULT '',
    tags       TEXT    NOT NULL DEFAULT '[]',
    created_at TEXT    NOT NULL
);
```

Tags are stored as a JSON array string inside a TEXT column.

---

## Frictions encountered

### 1. `JsonResponseFactory` silently escapes non-ASCII to `\uXXXX` (高)

**Symptom:** A response body containing `田中太郎` arrives as `"田中太郎"` in the raw HTTP body.

`JsonResponseFactory::create()` calls:

```php
json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_PRESERVE_ZERO_FRACTION)
```

`JSON_UNESCAPED_UNICODE` is absent. The result is valid JSON, but:

- Increases payload size by ~3× for CJK characters (6 chars per `\uXXXX` vs 3 bytes UTF-8).
- Harder to debug in logs or curl output.
- Surprising to developers who expect literal UTF-8.

**Workaround:** None at the call site. The JSON _decodes_ correctly, so API consumers that parse JSON see the right strings. But raw bytes differ from expectations.

**Suggested fix:** Add `JSON_UNESCAPED_UNICODE` to `JsonResponseFactory`.

---

### 2. `mb_strlen` is not obvious as the correct validator (中)

**Symptom:** A developer using `strlen()` for length validation silently breaks Unicode inputs.

`strlen('あ') === 3` (bytes), `mb_strlen('あ', 'UTF-8') === 1` (character).

For a 50-character name limit:
- 50 Japanese characters = 150 bytes → `strlen` rejects it wrongly.
- 50 emoji (each 4 bytes) = 200 bytes → `strlen` rejects it wrongly.

NENE2 provides no built-in validation helper that wraps `mb_strlen`. The framework offers no guide or utility for Unicode-aware length constraints. Each developer must remember to use `mb_strlen($val, 'UTF-8')` explicitly.

**Suggested fix:** Add a how-to guide covering Unicode-aware validation in NENE2.

---

### 3. ZWJ emoji sequences count as multiple codepoints (低)

**Symptom:** `👨‍👩‍👧` (`\u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467}`) is rendered as one glyph but is 5 codepoints. `mb_strlen` counts 5, not 1.

This is a standard Unicode segmentation issue, not a NENE2-specific problem, but worth documenting: if a "50 character" name limit means "50 grapheme clusters", PHP's `mb_strlen` gives the wrong answer. The `grapheme_strlen()` intl function gives the grapheme count.

**Status:** Low friction; most use-cases are satisfied with codepoint counting. Document as a known trade-off in the how-to guide.

---

### 4. Null byte stored in SQLite without error (中)

**Symptom:** SQLite TEXT columns accept null bytes. Without explicit validation, `"Alice\x00Bob"` is stored and retrieved. PHP string operations that use C-style null termination (none in modern PHP, but in some ext functions) could misbehave.

NENE2 has no built-in null-byte stripping or rejection mechanism. Developers must add `str_contains($val, "\x00")` guards manually.

**Suggested fix:** Document null-byte rejection in the Unicode validation how-to guide.

---

### 5. PHPStan false positive on `array_values(array_map(...))` (低)

**Symptom:** PHPStan level 8 reports:

```
Parameter #1 $array (list<T>) of array_values is already a list, call has no effect.
```

This occurs when `fetchAll()` returns `array<int, array<string, mixed>>` but the PHPDoc says `list<>`. `array_map` over a `list` returns a `list`, so `array_values` is redundant. Harmless, but the warning is confusing.

**Fix:** Remove the outer `array_values()` call. Note that this is a PHPStan inference issue, not a NENE2 bug.

---

## Test results

22 tests, 50 assertions — all pass.

Tested scenarios:
- English, Japanese, emoji, ZWJ sequences, Arabic, mixed script profiles
- mb_strlen boundary (50 chars exact, 51 chars rejected; 50 emoji codepoints accepted)
- Null byte rejection in name, bio, and tags
- Too-many-tags validation (max 10)
- Per-tag length limit with Unicode tags
- Non-string tag type rejection
- SQL-injection strings stored safely
- Raw JSON body contains `\uXXXX` but decoded values are correct
- PATCH and DELETE with Unicode

---

## Developer Experience (DX) Review

### 初心者・ロースキル観点での実装しやすさ

難しかった。`mb_strlen` の使い方は知っていないと `strlen` で実装してしまい、日本語名が50文字なのに「文字数超過」と弾かれる見えにくいバグを作りこむ。フレームワーク側に「Unicode 対応長さチェック」のガイドや例がないので、初心者は気づけない。

null バイト対策 (`str_contains($v, "\x00")`) も、NENE2 のドキュメントには登場しない「どこかで知っておくべき知識」。

### 使ってみた印象

ルーティング・DI・レスポンス生成のコアは非常に素直。`JsonRequestBodyParser::parse()` で body が配列で取れる点は良い。ただし `json_encode` に `JSON_UNESCAPED_UNICODE` がないせいで、ターミナルで `curl | cat` したときに文字化けして見えて、一瞬「バグ？」と思う。

### 楽しいか・気持ちいいか・快適か

ルーティングを書く部分は楽しい。`$router->post('/profiles', $this->createProfile(...))` のシンタックスは気持ちいい。バリデーションを自前で書かなければならない部分は機械的で退屈。

emoji のラウンドトリップが問題なく通るのは小気味よく、「よし！」と思う瞬間があった。

### 簡単か

コアの組み立ては簡単。ただし Unicode 周りの地雷（`strlen` vs `mb_strlen`、null バイト、ZWJ）を踏む前提知識が必要で、初心者には「簡単ではない」。NENE2 のドキュメントに Unicode バリデーション how-to があれば大幅に改善する。

### また使いたいか

はい。PSR-7/15 に準拠していてテストが書きやすく、SQLite との組み合わせが手軽。

### 初心者に勧めたいか

コア部分（ルーティング・DI・DB）は勧めたい。ただし Unicode バリデーションについては「フレームワークが守ってくれない部分」として明示的に教育が必要。how-to ガイドが充実すれば推薦度は上がる。

---

## Issues / PRs

- Issue: `JsonResponseFactory` に `JSON_UNESCAPED_UNICODE` を追加
- Issue: Unicode バリデーション how-to ガイドの追加（`mb_strlen`・null バイト・grapheme cluster）
