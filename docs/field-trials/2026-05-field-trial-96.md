# Field Trial 96 — Content Negotiation (Accept Header)

**Date:** 2026-05-20
**Project:** `/home/xi/docker/NENE2-FT/contentlog/`
**NENE2 version:** 1.5.29
**Theme:** Content negotiation — Accept header behavior, response Content-Type consistency, request Content-Type enforcement (or lack thereof)

---

## What was built

A minimal articles CRUD API used as a test harness for content negotiation behavior. All tests examine how NENE2 handles `Accept` headers, error response types, and request body `Content-Type` variations.

---

## Findings

### 1. NENE2 ignores the `Accept` header — always returns JSON (設計上の選択)

NENE2 does not implement content negotiation. Regardless of what the `Accept` header contains, all responses are `Content-Type: application/json; charset=utf-8`.

| Accept header sent | Response Content-Type |
|---|---|
| (none) | `application/json; charset=utf-8` |
| `application/json` | `application/json; charset=utf-8` |
| `*/*` | `application/json; charset=utf-8` |
| `text/html` | `application/json; charset=utf-8` |
| `application/xml` | `application/json; charset=utf-8` |
| `text/html;q=1.0, application/json;q=0.9` | `application/json; charset=utf-8` |

RFC 7231 §6.5.6 states that a server "SHOULD" return `406 Not Acceptable` when the Accept header lists no acceptable types. NENE2 does not follow this — it always returns JSON.

**This is correct for a JSON-first API** and is a deliberate design choice, but it is undocumented.

**DX観点 (初心者目線):** NENE2 が「JSON のみ返す」と明示されていないと、初心者は Accept ヘッダーを試して「なぜ 406 にならないのか」と混乱する可能性がある。明確なドキュメントがあれば問題なし。

---

### 2. Error responses use `application/problem+json` correctly (摩擦なし)

All error responses — 404, 405, 422, 500 — use `Content-Type: application/problem+json`. This is consistent regardless of what `Accept` header the client sends.

```
HTTP/1.1 404 Not Found
Content-Type: application/problem+json
```

This is RFC 9457 compliant and works well.

---

### 3. `JsonRequestBodyParser` does not enforce `Content-Type: application/json` on requests (中)

**Symptom:** A request with `Content-Type: application/x-www-form-urlencoded` and form-encoded body results in a 400 Bad Request (JSON parse failure), not a clean 415 Unsupported Media Type.

**More surprising:** A request with no `Content-Type` header and a valid JSON body is parsed correctly:

```php
// No Content-Type header set
$req = new ServerRequest('POST', '/articles');
$req->withBody('{"title":"Hello"}');
// → JsonRequestBodyParser parses successfully → 201 Created
```

`JsonRequestBodyParser::parse()` calls `json_decode()` unconditionally without checking whether the request's `Content-Type` is `application/json`. This means:
- Form-encoded bodies get 400 (JSON decode fails)
- Raw JSON bodies succeed even without the header

For a JSON API this is arguably correct (liberal input policy), but there is no way to return 415 Unsupported Media Type via NENE2's built-in tooling.

**DX観点:** 初心者が「Content-Type を付け忘れた JSON リクエスト」を送っても動いてしまう — これは問題にならないことが多いが、デバッグ時に驚く。「Content-Type が必要」という明示的なエラーが出ない。

---

### 4. `405 Method Not Allowed` is returned correctly (摩擦なし)

`DELETE /articles` (no such route defined, but GET and POST exist) returns 405. The router correctly distinguishes "no route at all" (404) from "route exists but wrong method" (405).

---

## Test results

16 tests, 28 assertions — all pass.

Key behaviors confirmed:
- No Accept header → 200 JSON
- `Accept: application/json` → 200 JSON
- `Accept: */*` → 200 JSON
- `Accept: text/html` → 200 JSON (no 406)
- `Accept: text/html;q=1.0, application/json;q=0.9` → 200 JSON (no 406)
- 404 → `application/problem+json`
- 422 → `application/problem+json`
- 404 with `Accept: text/html` → `application/problem+json`
- Form-encoded body → 400/415/422
- JSON body without Content-Type → 201 (success)
- Unknown route → 404 problem+json
- Wrong method → 405

---

## Developer Experience (DX) Review

### 初心者・ロースキル観点での実装しやすさ

テーマ自体は複雑だが、「NENE2 が Accept ヘッダーを無視する」という事実さえ知っていれば実装は簡単。ドキュメントに明記されれば初心者も混乱しない。

### 使ってみた印象

エラーレスポンスが `application/problem+json` で統一されていて安心感がある。406 を心配せず「JSON を返す」と割り切れるのはシンプルで良い。

### 楽しいか・気持ちいいか・快適か

コンテントネゴシエーションは実装が面倒な分野なので、NENE2 が「JSON のみ」に割り切っているのは快適。自前の `Accept` ヘッダーパーサーを書かなくていい。

### 簡単か

はい。このテーマでは NENE2 がほぼ透過的に動いた。唯一の複雑さは「`JsonRequestBodyParser` が `Content-Type` を無視する」という挙動の把握。

### また使いたいか

はい。JSON-only なユースケースでは NENE2 の設計はシンプルで嬉しい。

### 初心者に勧めたいか

はい、ただし「NENE2 は 406 を返さない」「Content-Type なしの JSON でも動く」という挙動をドキュメントで明示すると推薦度が上がる。

---

## Issues / PRs

- Issue: Accept ヘッダー無視の設計決定をドキュメント化（README / CLAUDE.md / ADR）
- Issue: `JsonRequestBodyParser` の Content-Type チェック非実施を howto で説明（415 を返したい場合のミドルウェア例）
