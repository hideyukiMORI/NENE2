# ハウツー: 記事バージョニング API

> **FT リファレンス**: FT249 (`NENE2-FT/contentvlog`) — 記事バージョニング API
> **VULN**: FT249 — 脆弱性アセスメント（V-01 から V-10）

`articles` テーブルの `current_version` 整数カラムが最新バージョンを追跡し、各更新が `article_versions` に追記され、ロールバックが過去のコンテンツから新しいバージョンを作成する記事バージョニングシステムを示します。未認証設計の脆弱性アセスメントを含みます。

---

## ルート

| メソッド | パス | 説明 |
|--------|-----------------------------------|------------------------------------------------------|
| `POST` | `/articles` | 記事を作成する（バージョン 1） |
| `GET` | `/articles/{id}` | 記事を取得する（現在のコンテンツ） |
| `PUT` | `/articles/{id}` | 記事を更新する（新しいバージョンを作成） |
| `GET` | `/articles/{id}/versions` | バージョン履歴を一覧表示する（メタデータのみ） |
| `GET` | `/articles/{id}/versions/{version}` | 特定のバージョンを取得する |
| `POST` | `/articles/{id}/rollback` | バージョンにロールバックする（新しいバージョンを作成） |

---

## スキーマ: `current_version` 整数カラム

```sql
CREATE TABLE articles (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    title           TEXT    NOT NULL,
    body            TEXT    NOT NULL,
    current_version INTEGER NOT NULL DEFAULT 1,
    created_at      TEXT    NOT NULL,
    updated_at      TEXT    NOT NULL
);

CREATE TABLE article_versions (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    article_id INTEGER NOT NULL,
    version    INTEGER NOT NULL,
    title      TEXT    NOT NULL,
    body       TEXT    NOT NULL,
    created_at TEXT    NOT NULL,
    UNIQUE (article_id, version),
    FOREIGN KEY (article_id) REFERENCES articles(id)
);
```

`current_version` カラムは現在のコンテンツのバージョン番号を保存します。`UNIQUE(article_id, version)` は同じ記事に対する重複バージョン番号を防止します。

**`is_current` フラグアプローチとの比較**（`document-versioning.md` 参照）:

| アプローチ | `current_version` 整数 | `is_current` フラグ |
|---|---|---|
| スキーマ | `articles` テーブルのカラム | `versions` テーブルのカラム |
| 現在バージョンのルックアップ | `SELECT * FROM articles WHERE id = ?`（JOIN なし） | `LEFT JOIN ... ON dv.is_current = 1` |
| バージョン番号の追跡 | 親行の明示的な整数 | 行数または MAX からの暗黙的 |
| アトミック性 | article 更新 + version 挿入（2 書き込み） | フラグ UPDATE + INSERT（2 書き込み） |

---

## 作成: 2 書き込みの初期化

記事の作成は両方のテーブルに書き込みます:

```php
$id = $this->db->insert(
    'INSERT INTO articles (title, body, current_version, created_at, updated_at) VALUES (?, ?, 1, ?, ?)',
    [$title, $body, $now, $now],
);
$this->db->insert(
    'INSERT INTO article_versions (article_id, version, title, body, created_at) VALUES (?, 1, ?, ?, ?)',
    [$id, $title, $body, $now],
);
```

両方の書き込みはラップするトランザクションなしに行われます。2 番目の挿入が失敗した場合、`articles` 行は存在しますが `article_versions` に対応するエントリがありません — 記事はバージョン 1 で履歴レコードなしの状態になります。本番環境では両方を `$txManager->transactional()` でラップしてください。

---

## 更新: 読み取り後インクリメントパターン

```php
public function update(int $id, string $title, string $body, string $now): bool
{
    $article = $this->find($id);
    if ($article === null) {
        return false;
    }
    $nextVersion = (int) $article['current_version'] + 1;

    $this->db->insert(
        'UPDATE articles SET title = ?, body = ?, current_version = ?, updated_at = ? WHERE id = ?',
        [$title, $body, $nextVersion, $now, $id],
    );
    $this->db->insert(
        'INSERT INTO article_versions (article_id, version, title, body, created_at) VALUES (?, ?, ?, ?, ?)',
        [$id, $nextVersion, $title, $body, $now],
    );
    return true;
}
```

バージョン番号は読み取られ、PHP でインクリメントされてから書き戻されます。トランザクションなしでは、同時更新が重複バージョン番号を生成する可能性があります — `UNIQUE(article_id, version)` 制約がこれをキャッチしますが、`articles` への `UPDATE` が成功した後に `article_versions` への `INSERT` が失敗し、記事の `current_version` が履歴より先に進んだ状態になる可能性があります。

---

## ロールバック: 非破壊的（新バージョンとしてコピー）

```php
public function rollback(int $id, int $version, string $now): bool
{
    $target = $this->findVersion($id, $version);
    if ($target === null) {
        return false;
    }
    $article     = $this->find($id);
    $nextVersion = (int) $article['current_version'] + 1;
    $title       = (string) $target['title'];
    $body        = (string) $target['body'];

    $this->db->insert(
        'UPDATE articles SET title = ?, body = ?, current_version = ?, updated_at = ? WHERE id = ?',
        [$title, $body, $nextVersion, $now, $id],
    );
    $this->db->insert(
        'INSERT INTO article_versions (article_id, version, title, body, created_at) VALUES (?, ?, ?, ?, ?)',
        [$id, $nextVersion, $title, $body, $now],
    );
    return true;
}
```

ロールバックはバージョンを削除しません — 対象バージョンのコンテンツを新しい（現在の）バージョンとしてコピーします。履歴は常に保持されます。記事がバージョン 5 にあり、バージョン 2 にロールバックした場合:

```
v1 → v2 → v3 → v4 → v5 → v6（v2 コンテンツのコピー）
```

---

## バージョン一覧: メタデータのみ（本文なし）

`GET /articles/{id}/versions` は本文なしでバージョンメタデータを返します:

```php
$this->db->fetchAll(
    'SELECT id, article_id, version, title, created_at FROM article_versions
     WHERE article_id = ? ORDER BY version ASC',
    [$articleId],
);
```

`body` は一覧から除外されます — 呼び出し元はコンテンツを取得するために `GET /articles/{id}/versions/{version}` で個別のバージョンをフェッチする必要があります。これにより一覧レスポンスで大きなコンテンツを送信しなくて済みます。

---

## VULN — 脆弱性アセスメント（FT249）

### V-01 — 認証なし: 任意の呼び出し元が任意の記事を更新または削除できる

**リスク**: すべてのエンドポイントが未認証です。

**影響**: 攻撃者は任意の記事を上書きし、コンテンツを以前のバージョンにロールバックし、すべてのバージョン履歴を列挙できます。

**判定**: **EXPOSED** — 認証を追加してください（API キー、JWT、またはセッション）。更新/ロールバックには記事の所有者による認証が必要です。

---

### V-02 — 所有権なし: 任意の認証済みユーザーが任意の記事を変更できる

**リスク**: 認証があっても、所有権スコープのクエリが存在しません。任意の認証済みユーザーが他ユーザーの記事を更新できます。

**影響**: `WHERE id = ? AND owner_id = ?` なしでは、記事 ID は列挙可能で有効なトークンを持つ誰でも変更できます。

**判定**: **EXPOSED** — `articles` に `owner_id` カラムを追加してください。すべての書き込み操作で `WHERE id = ? AND owner_id = ?` で所有権を強制してください。

---

### V-03 — IDOR: 別ユーザーのバージョン履歴を読み取る

**リスク**: `GET /articles/{id}/versions` は任意の記事 ID のすべてのバージョン履歴を返します。

**影響**: 攻撃者は著者が公開を意図していなかったドラフトコンテンツ履歴を列挙できます。

**判定**: **EXPOSED** — すべての読み取りに所有権スコープを追加してください: 記事の所有者（または明示的な読み取り権限を持つロール）のみがバージョン履歴を見られるべきです。

---

### V-04 — バージョン番号インクリメントでのレース条件

**リスク**: `update()` が `current_version` を読み取り、PHP でインクリメントしてから書き戻します。読み取り-書き込みシーケンスをトランザクションや行ロックで囲んでいません。

**攻撃**: 2 つの同時 `PUT /articles/1` リクエストが両方 `current_version = 3` を読み取ります。両方が `nextVersion = 4` を計算します。一方が成功（バージョン 4 を挿入）；もう一方は `UNIQUE(article_id, version)` 制約に失敗します — ただし `UPDATE articles` はすでに成功して、バージョン 4 に設定されており、履歴には 1 つのバージョンレコードしかありません。

**判定**: **EXPOSED** — `find` + `UPDATE` + `INSERT` を DB トランザクションでラップしてください。アトミックなインクリメントには `UPDATE articles SET current_version = current_version + 1` を使用してください。

---

### V-05 — タイトルまたは本文への SQL インジェクション

**攻撃**: SQL メタ文字を埋め込む。

```json
{"title": "'; DROP TABLE articles; --", "body": "x"}
```

**観測結果**: 値はパラメータ化された `?` プレースホルダーとしてバインドされます。インジェクションはリテラルテキストとして保存されます。

**判定**: **BLOCKED** — パラメータ化クエリが SQL インジェクションを防止します。

---

### V-06 — バージョン列挙: 無制限の履歴アクセス

**リスク**: `GET /articles/{id}/versions` はページネーションや制限なしに全バージョン履歴を返します。

**影響**: 何千ものバージョンを持つ記事が単一レスポンスですべての行を返し、メモリプレッシャーとスロークエリを引き起こします。

**判定**: **EXPOSED** — バージョン一覧エンドポイントにページネーション（`LIMIT ? OFFSET ?`）を追加してください。記事ごとの最大バージョン数の上限設定も検討してください。

---

### V-07 — 非トランザクションな 2 書き込み操作

**リスク**: `create()` と `update()` の両方がラップする DB トランザクションなしで 2 つの連続した書き込みを行います。

**影響**: 2 番目の書き込みが失敗した場合（例: 制約違反、接続エラー）、システムは不整合な状態になります: `articles.current_version` が `article_versions` 行数と異なる可能性があり、バージョンレコードのない記事が存在する可能性があります。

**判定**: **EXPOSED** — ペアになった書き込みを `DatabaseTransactionManagerInterface::transactional()` でラップしてください。

---

### V-08 — 別の記事のバージョンへのロールバック

**攻撃**: 異なる記事に存在する `version` 番号でロールバックを送信する。

```bash
# 記事 1 にはバージョン 1-3 がある；記事 2 にはバージョン 1 がある
POST /articles/1/rollback  {"version": 1}
```

**観測結果**: `findVersion(articleId=1, version=1)` は `WHERE article_id = ? AND version = ?` を使用します — 記事 1 に属するバージョンのみを見つけます。記事 2 に存在するバージョンは返されません。

**判定**: **BLOCKED** — バージョンルックアップは `article_id` でスコープされています。

---

### V-09 — 大きな本文: 記事コンテンツのサイズ制限なし

**リスク**: `body` はバリデーションなしに任意の長さの文字列を受け入れます。

**影響**: 数メガバイトの本文が毎回の読み取り時にストレージとメモリを消費します。

**判定**: **EXPOSED** — 本文の長さチェックを追加してください（例: `strlen($body) > 1_000_000 → 422`）。外部の制限としてリクエストサイズミドルウェアを利用してください。

---

### V-10 — `version = 0` または負のバージョンへのロールバック

**攻撃**: バージョン 0 または -1 でロールバックを送信する。

```json
{"version": 0}
{"version": -1}
```

**観測結果**: `(int) $body['version']` は任意の整数を受け入れます。`findVersion($id, 0)` と `findVersion($id, -1)` は `null` を返します（そのようなバージョンはない）→ `404 Not Found`。バージョン 0 は保存されることはありません（バージョンは 1 から始まります）。

**判定**: **BLOCKED** — `findVersion` は存在しないバージョンに `null` を返します；特別なケースは不要です。

---

## VULN まとめ

| # | 脆弱性 | 判定 |
|---|---------------|---------|
| V-01 | 書き込みエンドポイントに認証なし | EXPOSED |
| V-02 | 所有権チェックなし（任意のユーザーが任意の記事を変更できる） | EXPOSED |
| V-03 | バージョン履歴での IDOR | EXPOSED |
| V-04 | バージョン番号インクリメントでのレース条件 | EXPOSED |
| V-05 | タイトル/本文への SQL インジェクション | BLOCKED |
| V-06 | 無制限のバージョン一覧（ページネーションなし） | EXPOSED |
| V-07 | 非トランザクションなペアになった書き込み | EXPOSED |
| V-08 | 別の記事のバージョンへのロールバック | BLOCKED |
| V-09 | 本文サイズ制限なし | EXPOSED |
| V-10 | バージョン 0 / 負へのロールバック | BLOCKED |

**本番前の重要な修正**:
1. **V-01 / V-02 / V-03** — 認証と `owner_id` 所有権強制を追加する
2. **V-04 / V-07** — すべての複数書き込み操作を `transactional()` でラップ；アトミックなバージョンインクリメントを使用する
3. **V-06** — バージョン一覧に `LIMIT ? OFFSET ?` ページネーションを追加する
4. **V-09** — 本文サイズバリデーションを追加する

---

## 関連ハウツー

- [`document-versioning.md`](document-versioning.md) — `DatabaseTransactionManagerInterface` を使った `is_current` フラグアプローチ
- [`content-versioning.md`](content-versioning.md) — 線形バージョン番号によるコンテンツバージョニング
- [`transactions.md`](transactions.md) — DatabaseTransactionManagerInterface パターン
- [`optimistic-locking.md`](optimistic-locking.md) — バージョンカラム + 条件付き UPDATE によるレース条件防止
