# Field Trial 91 — IDOR Prevention (Object-Level Authorization)

**Date:** 2026-05-20
**Project:** `/home/xi/docker/NENE2-FT/noteslog/`
**NENE2 version:** 1.5.24
**Theme:** Insecure Direct Object Reference (IDOR) prevention — enforcing resource ownership so users cannot read, modify, or delete each other's data

---

## What was built

A personal notes API where each note has an `owner_id`. All write and read operations enforce ownership: a user can only access their own notes. Accessing another user's note returns 404 (not 403) to prevent information leakage.

Authentication is simulated via an `X-Auth-User` header (stands in for real JWT auth in the FT context).

### Endpoints

| Method | Path | Description |
|--------|------|-------------|
| POST | `/notes` | Create note (owner = authenticated user) |
| GET | `/notes` | List own notes only |
| GET | `/notes/{id}` | View note — 404 if not mine or not found |
| PUT | `/notes/{id}` | Update note — 404 if not mine |
| DELETE | `/notes/{id}` | Delete note — 404 if not mine |

### Schema

```sql
CREATE TABLE IF NOT EXISTS notes (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    owner_id   TEXT    NOT NULL,
    title      TEXT    NOT NULL,
    body       TEXT    NOT NULL DEFAULT '',
    created_at TEXT    NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_notes_owner ON notes (owner_id);
```

---

## Frictions found

### 1. No ownership guard helper — boilerplate repeated in every handler

**Severity:** High (DX friction, security-sensitive)

NENE2 provides no abstraction for the "fetch resource and verify it belongs to the current user" pattern. Every handler that operates on an owned resource must:

1. Extract the authenticated user (parsing header/attribute)
2. Fetch the resource
3. Compare `ownerId` to `authUser`
4. Return 404 if mismatch (not 403 — see F-2)

This pattern repeated 4 times (show, update, delete, list filter). Without a helper, each
handler author must independently remember to enforce ownership — and the check is easy to forget.

**Reproduction (the boilerplate pattern, repeated verbatim for each verb):**
```php
$authUser = trim($request->getHeaderLine('X-Auth-User'));
if ($authUser === '') { return $this->unauthorized($request); }

$id   = $this->resolveId($request);
$note = $this->repo->findByIdAndOwner($id, $authUser);
if ($note === null) { return $this->problems->create(..., 404); }
// actual handler logic
```

**Alternative approach**: move ownership enforcement to the SQL layer (`WHERE id = ? AND owner_id = ?`) — this is what the FT does and it's elegant. But NENE2's docs and howtos don't describe this pattern anywhere.

---

### 2. 403 vs 404 — the IDOR information leakage decision is undocumented

**Severity:** Medium (security design gap)

OWASP recommends returning `404 Not Found` (not `403 Forbidden`) when a user accesses a resource that belongs to another user. A `403` confirms the resource exists — useful information for an attacker enumerating IDs.

NENE2's Problem Details documentation does not mention this design consideration. A beginner
would default to `403 Forbidden` (semantically "correct" but security-naive).

The correct pattern: `findByIdAndOwner()` returns `null` for both "not found" and "wrong owner"
— the caller cannot and should not distinguish the two cases.

---

### 3. No `X-Auth-User` / "current user" extraction utility in NENE2

**Severity:** Low (minor DX friction)

Every handler that needs the authenticated user must call:
```php
$authUser = trim($request->getHeaderLine('X-Auth-User'));
```
(or read from a request attribute set by `BearerTokenMiddleware`).

There is no `AuthContext::fromRequest($request)` abstraction to DRY this up. In FTs using JWT
auth (`BearerTokenMiddleware`), the pattern is `$request->getAttribute('nene2.auth.claims')['sub']`.
In simpler FTs using API-key-style headers, it's manual header parsing.

---

### 4. PHPStan parse error on method chaining with `new` expression

**Severity:** Low (PHPStan limitation)

```php
// This caused a PHPStan "syntax error" even though PHP 8.4 accepts it:
return $this->json->create(new Note($id, $ownerId, $title, $body, $now)->toArray());

// Fix: split to two lines
$updated = new Note($id, $ownerId, $title, $body, $now);
return $this->json->create($updated->toArray());
```

PHPStan 1.12.x does not always handle method calls on `new` expressions in certain contexts.
This is a PHPStan issue, not a NENE2 issue, but it appears in FT projects regularly enough to
be worth documenting.

---

## Results

| Check | Result |
|-------|--------|
| PHPUnit tests | 15 tests, 31 assertions — OK |
| PHPStan level 8 | 1 error (new expression method chain) → fixed → 0 errors |
| PHP-CS-Fixer | 0 files to fix |

---

## Developer Experience (DX) Review

### 初心者・ロースキル観点での実装しやすさ

**難易度: 低〜中（パターン自体は単純、セキュリティ設計知識が必要）**

- CRUD実装は問題なし。NENE2のJSON・Problem Details・ルーティングAPIは直感的。
- 「IDORとは何か、なぜ危険か」を知らない初心者は、そもそもこの問題を意識しない。NENE2にヘルプがない以上、セキュリティ知識が前提になってしまう。
- `WHERE id = ? AND owner_id = ?` というSQL側での所有権強制のパターンは、一度習得すれば明快で覚えやすい。

### 使ってみた印象

ルーティング・レスポンス生成はスムーズで、FT82〜90で培ったパターンが活きる。しかしIDOR対策はフレームワーク側のガイドが何もなく、「自力で気づいてください」状態。他のフレームワーク（Laravel/Symfony）でもこの問題は同様に難しいが、少なくともドキュメントや認可ライブラリが存在する。

### 楽しいか・気持ちいいか・快適か

- **楽しい点**: クロステナントアクセスをテストで明示的に証明できるのは気持ちいい。`testCannotReadAnotherUsersNote()` が通った瞬間、「このAPIは安全だ」という達成感がある。
- **不快な点**: 同じ `resolveAuthUser()` 呼び出しを5つのハンドラーに書く繰り返しが煩わしい。フレームワーク側でこれをインターセプトする仕組みがあれば。
- **快適な点**: `WHERE id = ? AND owner_id = ?` パターンはSQL側で完結するため、アプリ側のロジックがシンプルになる。

### 簡単か

パターン自体（`findByIdAndOwner`）は簡単。難しいのは「なぜ403でなく404を返すか」というセキュリティ設計の判断。知らなければ間違える。

### また使いたいか

**はい** — ルーティング・バリデーション・レスポンス生成の一貫性は高い。IDORパターンのベストプラクティスがドキュメントに記載されれば、より自信を持って使える。

### 初心者に勧めたいか

基本CRUD部分は勧められる。セキュリティ（IDOR）部分は中級者向けとして位置づけ、howtoで誘導すべき。

---

## Notes

- SQL側での所有権強制（`WHERE id = ? AND owner_id = ?`）はアプリ側のif分岐より安全 — コードパスを誤って通り抜けることがない。
- `findByIdAndOwner()` が「存在しない」と「所有者が違う」の両方で `null` を返すのがポイント。呼び出し側がこの2ケースを区別できないようにすることで、誤った分岐が書けなくなる。
- テナント分離の確認は `testListDoesNotLeakOtherTenantNotes()` のように複数ユーザーのデータをセットアップしてから検証するのが最も確実。
