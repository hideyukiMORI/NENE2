# Field Trial 97 — SQL Injection Defense

**Date:** 2026-05-20
**Project:** `/home/xi/docker/NENE2-FT/injectionlog/`
**NENE2 version:** 1.5.30
**Theme:** SQL injection — parameterized queries, LIKE injection, ORDER BY injection, path parameter coercion

---

## What was built

A product search API (`GET /products?q=...&sort=...&order=...`) used as a test harness for SQL injection defense. Tests send classic injection payloads (tautology, DROP TABLE, UNION SELECT, ORDER BY injection) and verify that NENE2's parameterized query layer neutralizes them.

---

## Findings

### 1. NENE2's `fetchAll()` / `fetchOne()` / `insert()` use PDO prepared statements — safe by default (摩擦なし)

All DB methods accept `array $parameters` and bind values via PDO. This means any user input passed through these methods is automatically parameterized — no string interpolation occurs.

```php
// Safe: parameterized LIKE — wildcard in SQL literal, value bound separately
$rows = $this->db->fetchAll(
    "SELECT * FROM products WHERE name LIKE '%' || ? || '%'",
    [$userInput],
);
```

Classic tautology `' OR '1'='1`, DROP TABLE, and UNION SELECT payloads all became literal search strings — 0 matches, table intact.

**DX観点:** 「デフォルトで安全」なのは非常に良い。初心者が `fetchAll()` を使っていれば、特別なことをしなくてもSQLインジェクションは防げる。

---

### 2. ORDER BY column cannot be parameterized — requires explicit whitelist (高: 重要な設計判断)

**Symptom:** `ORDER BY ?` does not work in SQL — column names are SQL structure, not values, and PDO cannot bind them as parameters. A developer who naively tries to pass `sort` from query string directly into an `ORDER BY` clause creates an injection vector.

**Example of the footgun:**
```php
// UNSAFE — if $sortField comes from user input:
$sql = "SELECT * FROM products ORDER BY {$sortField} ASC";
$this->db->fetchAll($sql);
```

**Correct approach — whitelist validation:**
```php
private const array ALLOWED_SORT_FIELDS = ['id', 'name', 'category', 'price'];

if (!in_array($sortField, self::ALLOWED_SORT_FIELDS, true)) {
    throw new InvalidSortFieldException("...");
}
// Only after validation:
$sql = "SELECT * FROM products ORDER BY {$sortField} {$sortDir}";
```

NENE2's `execute()` / `fetchAll()` have no mechanism to prevent raw interpolation — this is entirely up to the developer.

**DX観点 (初心者目線):** `QueryStringParser::string($request, 'sort')` で取得した値をそのまま `ORDER BY` に入れてしまう初心者は多い。NENE2 は「ORDER BY にパラメーターを使えない」という事実を警告する仕組みがない。Howto ドキュメントで明示しないと、初心者が脆弱なコードを書く可能性が高い。

---

### 3. `QueryStringParser::string()` returns `?string` — no default value argument (中)

**Symptom:** `QueryStringParser::string($request, 'sort', 'id')` のように第3引数でデフォルト値を渡そうとすると、引数が存在しないため PHP エラーになる。

```php
// BROKEN — no 3rd argument:
$sort = QueryStringParser::string($request, 'sort', 'id');

// Correct:
$sort = QueryStringParser::string($request, 'sort') ?? 'id';
```

`JsonRequestBodyParser::parse()` はフォールバックを返すが、`QueryStringParser::string()` は `null` を返す。デフォルト値を第3引数で渡せる設計の方がより一貫性がある。

**DX観点:** 「デフォルト値を渡したい」という自然な使い方が2ステップ（取得→`?? 'default'`）になる。引数でデフォルトを渡せると書き方が一行で完結して気持ちいい。

---

### 4. Route parameters are in `nene2.route.parameters` attribute — not directly accessible (中)

**Symptom:** `$request->getAttribute('id')` returns null. Path params must be read via:

```php
$params = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
$id     = (int) ($params['id'] ?? 0);
```

The constant `Router::PARAMETERS_ATTRIBUTE = 'nene2.route.parameters'` is stable API but the two-step access is non-obvious. FT94 authlog already hit this footgun.

**DX観点:** 毎回 `(array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE)['id']` を書くのは冗長。`Router::param($request, 'id')` のようなヘルパーがあると嬉しい。

---

### 5. ORDER direction injection is neutralized by normalization (摩擦なし)

Even if a user sends `?order=ASC; DROP TABLE products; --`, the repository normalizes the direction with `strtolower($sortDir) === 'desc' ? 'DESC' : 'ASC'` — any string that isn't `desc` becomes `ASC`. No injection possible through the direction parameter.

---

## Test results

19 tests, 45 assertions — all pass.

Key behaviors confirmed:
- Tautology injection `' OR '1'='1` → 0 results (literal string search)
- DROP TABLE injection → table survives, 0 results
- UNION SELECT injection → no injected rows in result
- ORDER BY whitelisted field → works correctly
- ORDER BY injection payload → 400 `invalid-sort-field`
- Order direction injection → normalized to `ASC`, table intact
- SQL injection in POST body name/description → stored as literal string, table intact
- `(int)` coercion of path `{id}` parameter → prevents numeric injection

---

## Developer Experience (DX) Review

### 初心者・ロースキル観点での実装しやすさ

NENE2 の DB 層はデフォルトで安全（全メソッドがパラメーター化クエリを使う）なため、`fetchAll()` / `insert()` を正しく使えばインジェクションは防げる。ただし ORDER BY の扱いは NENE2 が直接ガードしてくれるわけではなく、初心者がホワイトリスト検証を「知っていなければ危険」な設計上の空白がある。

### 使ってみた印象

PDO ベースのパラメーター化は当然の正解だが、`LIKE '%' || ? || '%'` という書き方は SQL 方言依存で初見では「これで合ってる？」と不安になる。動作確認できれば問題ないが、ドキュメント例がほしい。

### 楽しいか・気持ちいいか・快適か

SQLインジェクションペイロードをテストとして書いて「ちゃんと0件が返ってくる」を確認するのは、セキュリティ検証として爽快感がある。NENE2 が守ってくれている安心感を実感できる。

### 簡単か

基本的なCRUDと検索は簡単。困ったのは ORDER BY のホワイトリスト必須という暗黙の知識と、`QueryStringParser::string()` のデフォルト値引数がないこと。

### また使いたいか

はい。デフォルト安全な DB 層は非常に信頼できる。ORDER BY の扱いを howto で明示してくれれば完璧。

### 初心者に勧めたいか

はい、ただし「ORDER BY にユーザー入力を直接入れてはいけない・ホワイトリスト必須」という点を howto に明示すること。現状その警告がドキュメントにないため、初心者が脆弱なコードを書くリスクがある。

---

## Issues / PRs

- Issue: ORDER BY injection footgun を howto で説明（ホワイトリスト必須の理由と実装例）
- Issue: `QueryStringParser::string()` にデフォルト値引数を追加（`?? 'default'` 二段構えをなくす）
- Issue: `Router::param($request, 'key')` ヘルパーメソッド追加（路由パラメーター取得の簡略化）
