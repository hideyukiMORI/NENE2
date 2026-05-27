# ハウツー: SQL インジェクション防御

> **FT リファレンス**: FT264 (`NENE2-FT/injectionlog`) — SQL インジェクション防御: パラメーター化クエリ、LIKE インジェクション、ORDER BY allowlist
> **ATK**: FT264 — クラッカー攻撃テスト（ATK-01〜ATK-12）

PHP API における 3 つの主要な SQL インジェクションベクター — 値インジェクション、LIKE ワイルドカードインジェクション、ORDER BY カラムインジェクション — とそれぞれの正しい防御を実演します。完全なクラッカー攻撃アセスメントを含みます。

---

## ルート

| メソッド | パス | 説明 |
|--------|------|------|
| `GET`    | `/products`      | 商品を一覧/検索する（フィルター可能、ソート可能） |
| `POST`   | `/products`      | 商品を作成する                                   |
| `GET`    | `/products/{id}` | 単一の商品を取得する                             |
| `DELETE` | `/products/{id}` | 商品を削除する                                   |

---

## スキーマ

```sql
CREATE TABLE IF NOT EXISTS products (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    name        TEXT    NOT NULL,
    category    TEXT    NOT NULL,
    price       REAL    NOT NULL DEFAULT 0.0,
    description TEXT    NOT NULL DEFAULT ''
);
```

---

## 3 つの SQL インジェクション面

### 1. 値インジェクション: パラメーター化クエリ

```php
// ❌ 文字列補間 — インジェクタブル
$rows = $db->fetchAll("SELECT * FROM products WHERE id = {$id}");

// ✅ パラメーター化 — ドライバーがすべての値をエスケープ
$rows = $db->fetchAll('SELECT * FROM products WHERE id = ?', [$id]);
```

PDO の `?` プレースホルダーは値を型付きパラメーターとしてバインドします。値は SQL 文字列に補間されることはありません。`id = "1; DROP TABLE products; --"` を送信した攻撃者の入力全体はリテラル文字列バインディングとして保存されます — SQL は変更されません。

### 2. LIKE ワイルドカードインジェクション: パラメーター化ワイルドカード

```php
// ❌ 補間された LIKE — インジェクタブルかつワイルドカードエスケープ
$rows = $db->fetchAll("SELECT * FROM products WHERE name LIKE '%{$q}%'");

// ✅ パラメーター化ワイルドカード — ? 値は || 連結後にバインドされる
$rows = $db->fetchAll(
    "SELECT * FROM products WHERE name LIKE '%' || ? || '%' OR description LIKE '%' || ? || '%'",
    [$q, $q],
);
```

`'%' || ? || '%'` は標準的な SQL 文字列連結（SQLite、PostgreSQL）です。`?` 値はパラメーターとしてバインドされます — `%` ワイルドカードはユーザー入力からではなく SQL 文字列のリテラルです。

**LIKE メタ文字エスケープ**: ユーザー入力 `$q` 内の `%` と `_` はこの実装ではエスケープされません。`%` の検索はすべてにマッチします。本番環境では LIKE メタ文字をエスケープしてください:

```php
$escaped = str_replace(['%', '_', '\\'], ['\\%', '\\_', '\\\\'], $q);
$rows = $db->fetchAll("... WHERE name LIKE '%' || ? || '%' ESCAPE '\\'", [$escaped, $escaped]);
```

### 3. ORDER BY インジェクション: カラム allowlist

```php
private const array ALLOWED_SORT_FIELDS = ['id', 'name', 'category', 'price'];

public function search(string $query = '', string $sortField = 'id', string $sortDir = 'asc'): array
{
    if (!in_array($sortField, self::ALLOWED_SORT_FIELDS, true)) {
        throw new InvalidSortFieldException("Invalid sort field: {$sortField}");
    }

    $sortDir    = strtolower($sortDir) === 'desc' ? 'DESC' : 'ASC';
    $sortClause = $sortField . ' ' . $sortDir;   // 安全: allowlist 済みカラム + ホワイトリスト済みの方向

    $rows = $db->fetchAll(
        "SELECT * FROM products ORDER BY {$sortClause}",
    );
}
```

`ORDER BY` にはパラメーター化プレースホルダーを使えません — カラム名は補間しなければなりません。
正しい防御は明示的な allowlist です: `ALLOWED_SORT_FIELDS` にある値のみが SQL 文字列に現れることができます。他の値は例外をスローします（コントローラーで 400）。

`sortDir` は正確に `'ASC'` または `'DESC'` にマップされます — ユーザー入力は直接補間されません。

---

## ATK — クラッカー攻撃テスト（FT264）

### ATK-01 — GET パラメーター経由のクラシック SELECT インジェクション

**攻撃**: 検索クエリ `?q=' OR '1'='1` で SQL をインジェクトする。

```
GET /products?q=' OR '1'='1
```

**観察**: `$q` は `LIKE '%' || ? || '%'` の `?` パラメーターとしてバインドされます。文字列全体 `' OR '1'='1` はマッチするリテラルテキスト値として扱われます。追加の行は返されません。

**判定**: **BLOCKED** — パラメーター化 LIKE が値インジェクションを防ぎます。

---

### ATK-02 — 検索経由の DROP TABLE インジェクション

**攻撃**: 破壊的なステートメントをインジェクトする。

```
GET /products?q='; DROP TABLE products; --
```

**観察**: ペイロードは LIKE パターンパラメーターとしてバインドされます。`'; DROP TABLE products; --` はリテラルテキストとして検索されます。テーブルは削除されません。

**判定**: **BLOCKED** — パラメーター化クエリはインジェクトされたステートメントを実行できません。

---

### ATK-03 — ORDER BY カラムインジェクション: 任意のカラム

**攻撃**: 認識されないソートカラムをインジェクトする。

```
GET /products?sort=password
```

**観察**: `in_array('password', self::ALLOWED_SORT_FIELDS, true)` は `false` を返します。`InvalidSortFieldException` がスローされます。コントローラーがそれをキャッチして 400 を返します。

**判定**: **BLOCKED** — カラム allowlist が未知のカラム名を拒否します。

---

### ATK-04 — ORDER BY インジェクション: サブクエリインジェクション

**攻撃**: ソートカラムとしてサブクエリをインジェクトする。

```
GET /products?sort=(SELECT%20name%20FROM%20users%20LIMIT%201)
```

**観察**: デコードされた値 `(SELECT name FROM users LIMIT 1)` は `ALLOWED_SORT_FIELDS` にありません。`InvalidSortFieldException` がスローされます。400 が返されます。

**判定**: **BLOCKED** — allowlist はサブクエリを含む既知のカラムリストにない任意の値を拒否します。

---

### ATK-05 — ORDER BY インジェクション: 方向改ざん

**攻撃**: ソート方向パラメーター経由で SQL をインジェクトする。

```
GET /products?order=DESC;%20DROP%20TABLE%20products;--
```

**観察**: インジェクトされた値に対して `strtolower($sortDir) === 'desc'` は `false` です。方向は `'ASC'` にフォールスルーします。インジェクトされた SQL は補間されません。200 が ASC 順の商品で返されます。

**判定**: **BLOCKED** — 方向は正確に `'ASC'` または `'DESC'` にマップされ、補間されません。

---

### ATK-06 — 検索クエリ経由の UNION インジェクション

**攻撃**: データを漏洩させるために `UNION SELECT` をインジェクトする。

```
GET /products?q=' UNION SELECT id,name,email,password,'' FROM users --
```

**観察**: インジェクション文字列全体が LIKE パラメーター値としてバインドされます。`UNION SELECT` は `name` と `description` カラムのリテラルテキストとして検索されます。ユーザーデータは返されません。

**判定**: **BLOCKED** — パラメーター化クエリが UNION インジェクションを防ぎます。

---

### ATK-07 — パスパラメーター経由の ID インジェクション

**攻撃**: パスパラメーター経由で SQL をインジェクトする。

```
GET /products/1;%20DROP%20TABLE%20products;
```

**観察**: パスパラメーター `{id}` は `(int) $params['id']` によって `int` にキャストされます。SQL は `WHERE id = 1` になります — インジェクションサフィックスはキャストによって切り捨てられます。テーブルは削除されません。

**判定**: **BLOCKED** — `(int)` キャストは最初の非数字文字で切り捨てます。

---

### ATK-08 — 検索経由のブーリアンベースブラインドインジェクション

**攻撃**: ブーリアン条件でデータを漏洩させる。

```
GET /products?q=' AND '1'='1
GET /products?q=' AND '1'='2
```

**観察**: 両方の文字列は LIKE パラメーターとしてバインドされます。両方ともリテラルテキスト `' AND '1'='1` を含む名前または説明を持つ商品を返します。どちらのクエリも SQL の WHERE ロジックを変更しません。両方とも同じ（空の）結果セットを返します。

**判定**: **BLOCKED** — パラメーター化バインディングがブーリアンインジェクションを防ぎます。

---

### ATK-09 — 2 次インジェクション: 後で取得される保存されたペイロード

**攻撃**: SQL を含む名前で商品を作成し、その後すべての商品を検索する。

```json
POST /products {"name": "'; DROP TABLE products; --", "category": "test", "price": 1}
GET /products
```

**観察**: `INSERT` はパラメーター化された `?` を使用します — インジェクションペイロードはリテラルテキストとして保存されます。`SELECT *` と `LIKE` クエリもパラメーター化クエリを使用します。ペイロードは SQL として実行されることなく文字列値として返されます。

**判定**: **BLOCKED** — すべての読み取りと書き込みパスがパラメーター化クエリを使用します。

---

### ATK-10 — LIKE メタ文字フラッド: `%` 検索

**攻撃**: `?q=%` を送信してすべての商品にマッチし、意図した空検索デフォルトをバイパスする。

```
GET /products?q=%25   (URL デコード: %)
```

**観察**: `$q = '%'` が LIKE パラメーターとしてバインドされます。`LIKE '%' || '%' || '%'` = `LIKE '%%%'` はすべての行にマッチします。すべての商品が返されます。

**判定**: **EXPOSED** — ユーザー入力の `%` と `_` はエスケープされません。`%` の検索はすべてにマッチします; `_` の検索は任意の 1 文字にマッチします。LIKE メタ文字をエスケープするか、動作を意図的なものとして文書化してください。

---

### ATK-11 — ヌルバイトインジェクション

**攻撃**: 検索クエリにヌルバイトを埋め込む。

```
GET /products?q=widget%00extra
```

**観察**: PHP の `?` バインディングはヌルバイトを含む生の文字列を SQLite のパラメーター化クエリに渡します。SQLite はヌルバイトを文字列の一部として扱います。`LIKE '%widget\0extra%'` は通常の商品名にマッチしません。インジェクションは発生しません。

**判定**: **BLOCKED** — パラメーター化クエリはヌルバイトをリテラル文字列コンテンツとして処理します。

---

### ATK-12 — スタックドクエリ（マルチステートメントインジェクション）

**攻撃**: セミコロンの後に 2 番目のステートメントをインジェクトする。

```
GET /products?q=test'; INSERT INTO products VALUES (99,'hacked','x',0,''); --
```

**観察**: PDO は `query()`/`prepare()` 呼び出しごとに 1 つのステートメントのみを実行します — スタックドクエリはデフォルトでサポートされていません。PDO が複数のステートメントを許可しても、値はパラメーターとしてバインドされます（補間されない）。インジェクトされた INSERT はリテラル LIKE 検索テキストとして保存されます。

**判定**: **BLOCKED** — パラメーター化バインディング + PDO 単一ステートメントモードがスタックドクエリを防ぎます。

---

## ATK サマリー

| # | 攻撃ベクター | 判定 |
|---|------------|------|
| ATK-01 | `?q=` 経由のクラシック SELECT インジェクション | BLOCKED |
| ATK-02 | 検索経由の DROP TABLE | BLOCKED |
| ATK-03 | ORDER BY 未知のカラム | BLOCKED |
| ATK-04 | ORDER BY サブクエリインジェクション | BLOCKED |
| ATK-05 | ソート方向インジェクション | BLOCKED |
| ATK-06 | 検索経由の UNION SELECT | BLOCKED |
| ATK-07 | パスパラメーター経由の ID インジェクション | BLOCKED |
| ATK-08 | ブーリアンベースのブラインドインジェクション | BLOCKED |
| ATK-09 | 2 次インジェクション | BLOCKED |
| ATK-10 | LIKE メタ文字フラッド（`%`） | EXPOSED |
| ATK-11 | ヌルバイトインジェクション | BLOCKED |
| ATK-12 | スタックドクエリ | BLOCKED |

**本番前に修正すべき実際の脆弱性**:
1. **ATK-10** — ワイルドカードフラッドを防ぐためにバインド前に LIKE メタ文字（`%`、`_`、`\`）をエスケープする。

---

## 防御サマリー

| 面 | 脆弱なパターン | 安全なパターン |
|---|---|---|
| WHERE 内の値 | `WHERE id = {$id}` | `$parameters` に `[$id]` を持つ `WHERE id = ?` |
| LIKE 検索 | `WHERE name LIKE '%{$q}%'` | `WHERE name LIKE '%' \|\| ? \|\| '%'` |
| ORDER BY カラム | `ORDER BY {$sortField}` | `in_array($sortField, ALLOWED, true)` + 補間 |
| ORDER BY 方向 | `ORDER BY col {$dir}` | `$dir === 'desc' ? 'DESC' : 'ASC'` |
| パスパラメーター ID | `WHERE id = {$id}` | `(int) $id` + パラメーター化 |

---

## 関連 howto

- [`mass-assignment-defence.md`](mass-assignment-defence.md) — より広い防御パターンとしての明示的な DTO ホワイトリスティング
- [`sqlite-fts5-search.md`](sqlite-fts5-search.md) — 全文検索の LIKE 代替としての FTS5
- [`jwt-authentication.md`](jwt-authentication.md) — SQL インジェクションを含む VULN アセスメント（V-08）
