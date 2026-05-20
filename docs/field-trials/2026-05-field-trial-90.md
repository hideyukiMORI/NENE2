# Field Trial 90 — Optimistic Concurrency Control (ETag / If-Match)

**Date:** 2026-05-20
**Project:** `/home/xi/docker/NENE2-FT/locklog/`
**NENE2 version:** 1.5.23
**Theme:** HTTP conditional writes — preventing the lost-update problem with ETag + If-Match

---

## What was built

A document editing API with optimistic locking. Each document carries a `version` counter.
Clients receive an `ETag: "v{version}"` header on read and must echo it back as `If-Match: "v{version}"` on write.
If the version has advanced (another writer won the race), the server returns `412 Precondition Failed`.
Missing `If-Match` returns `428 Precondition Required`.

### Endpoints

| Method | Path | Description |
|--------|------|-------------|
| POST | `/documents` | Create document; responds with `ETag: "v1"` |
| GET | `/documents/{id}` | Fetch document; responds with `ETag: "v{n}"` |
| PUT | `/documents/{id}` | Conditional update — requires `If-Match`; 412 on stale, 428 on missing |
| DELETE | `/documents/{id}` | Conditional delete — same `If-Match` semantics |

### Schema

```sql
CREATE TABLE IF NOT EXISTS documents (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    title      TEXT    NOT NULL,
    body       TEXT    NOT NULL DEFAULT '',
    version    INTEGER NOT NULL DEFAULT 1,
    updated_at TEXT    NOT NULL
);
```

---

## Frictions found

### 1. No `If-Match` conditional write support — must implement manually

**Severity:** High (feature gap)

NENE2 ships `ConditionalGetHelper` for read-side caching (`If-None-Match` → 304 Not Modified).
There is no equivalent for the write side: `If-Match` header parsing, `*` wildcard handling, or
`412 Precondition Failed` / `428 Precondition Required` response helpers.

Every optimistic-locking endpoint requires:
1. Reading `$request->getHeaderLine('If-Match')`
2. Handling the `*` wildcard (RFC 9110 §13.1.1 — "match if resource exists")
3. Parsing the quoted ETag format (`"v3"` → `3`)
4. Emitting `412` (version mismatch) and `428` (missing header) manually

In contrast, the GET-side is one call: `ConditionalGetHelper::check(...)`.
The asymmetry means a developer who finds `ConditionalGetHelper` may assume write-side ETags
are similarly handled, and then be surprised when they are not.

**Reproduction:**
```php
// GET: one line — ConditionalGetHelper handles everything
$notModified = ConditionalGetHelper::check($request, $psr17, $etag, $lastModified);

// PUT: must manually parse, validate, and emit errors
$ifMatch = $request->getHeaderLine('If-Match');
if ($ifMatch === '') {
    return $problems->create($request, 'precondition-required', 'Precondition Required', 428, '...');
}
if ($ifMatch !== '*') {
    // parse "v3" → 3, compare, emit 412
}
```

---

### 2. `If-Match: *` wildcard semantics are non-obvious

**Severity:** Medium (discoverability gap)

RFC 9110 §13.1.1 defines `If-Match: *` to mean "the precondition succeeds if the target resource
has a current representation" — i.e., the resource exists, any version.
This is useful for "update-if-exists" semantics without knowing the current ETag.

NENE2 documents nothing about this. Developers writing conditional APIs must discover it from
the RFC or other framework docs. The test `testUpdateWithWildcardIfMatch` was written to verify
the implementation handles it — a beginner would likely miss it entirely.

---

### 3. Race condition window between version check and update

**Severity:** Low (architecture note)

The implementation performs `findById()` + `UPDATE ... WHERE id = ? AND version = ?` as two
separate queries. The version comparison in the WHERE clause is the actual lock guard, but
between the initial `findById()` check (used to determine 404 vs 412) and the conditional UPDATE,
a concurrent writer could interleave. SQLite's per-database write serialization makes this
extremely unlikely, but on PostgreSQL under high concurrency it becomes a real TOCTOU window.

The correct fix requires wrapping both in a `BEGIN IMMEDIATE` / `SERIALIZABLE` transaction.
NENE2's `DatabaseTransactionManagerInterface` does not expose isolation levels.

---

### 4. 428 Precondition Required not in NENE2's error docs

**Severity:** Low (documentation gap)

RFC 6585 status codes (428, 429, 431, 511) are not mentioned in NENE2's Problem Details
documentation or howto guides. A developer who doesn't know RFC 6585 would likely use
`400 Bad Request` or `422 Unprocessable Entity` for "missing If-Match" — both semantically wrong.
428 is the correct status: the request was well-formed, but a precondition (If-Match) was
absent that the server requires.

---

## Results

| Check | Result |
|-------|--------|
| PHPUnit tests | 15 tests, 30 assertions — OK |
| PHPStan level 8 | No errors (first run) |
| PHP-CS-Fixer | 0 files to fix |

---

## Developer Experience (DX) Review

### 初心者・ロースキル観点での実装しやすさ

**難易度: 高い（ETagを初めて実装する開発者には厳しい）**

- ETag / If-Match / 412 / 428 はすべてHTTP仕様（RFC 9110 / RFC 6585）の知識が前提。ドキュメントなしにこれを実装させるのはハードルが高い。
- `ConditionalGetHelper` を発見すると「書き込み側も同じようにサポートされているはず」と思ってしまう。実際には未サポートで、ギャップが大きい。
- `If-Match: *` の wildcard 意味論はコードを読んでも発見できない — RFC を読む必要がある。
- 一方、ルート定義・レスポンス返却・バリデーションエラーのパターンはFT82〜89で習得済みなら詰まらない。中盤以降は快適。

### 使ってみた印象

`ConditionalGetHelper` の存在がポジティブな発見だった。「NENE2はHTTPキャッシュ周りまで考えている」という印象を与える。ただしGET側だけで書き込み側がないため、「半分のツール」を見つけたような感覚。PSR-7の `.withHeader('ETag', $etag)` チェーンはシンプルで気持ちいい。

### 楽しいか・気持ちいいか・快適か

- **楽しい点**: ロストアップデート問題をテストで証明できるのは気持ちいい。`testLostUpdatePrevented()` が通った瞬間はフレームワークの力を感じた。
- **不快な点**: `If-Match` ヘッダー解析、ETag文字列のパース（`"v3"` → `3`）、wildcard分岐を全部手書きしなければならず、「またゼロから書いた」感が残る。
- **快適な点**: `ProblemDetailsResponseFactory::create()` で412も428も一貫して返せるのはよかった。

### 簡単か

簡単ではない。Happyパス（作成・取得・更新）は簡単だが、ETagの正確な実装（特にwildcard・TOCTOU・428）は上級者向け。

### また使いたいか

**はい** — ただしETag/If-Matchを使う場合は、フレームワークにヘルパーがあれば確実に使い直したい。`ConditionalGetHelper` と対になる `ConditionalWriteHelper` があれば、このトライアルはずっと快適だったはず。コアAPI（routing・JSON factory・problem details）の一貫性は高く評価できる。

### 初心者に勧めたいか

ETagなし（バージョン番号をJSONボディで受け取る方法）なら勧められる。HTTPヘッダー方式は中〜上級者向け。

---

## Notes

- ETag format `"v{version}"` (integer-based) is simple and debuggable. Hash-based ETags (`md5($body)`) are more robust but harder to test predictably.
- `ConditionalGetHelper` handles `If-None-Match` (read side). A `ConditionalWriteHelper` for `If-Match` (write side) would complete the pair.
- The `WHERE id = ? AND version = ?` conditional UPDATE is the correct SQL-level lock guard — no extra SELECT needed if you accept that a 0-row UPDATE means "version mismatch or not found".
- Distinguishing 404 from 412 requires a pre-check `findById()`, which introduces the TOCTOU window. Without serializable transactions, this is a known limitation.
