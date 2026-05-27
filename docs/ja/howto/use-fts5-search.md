# SQLite FTS5 全文検索の使用方法

SQLite の FTS5 拡張は転置インデックスを使った全文検索を提供します。このガイドでは、スキーマパターン、トリガーベースの同期、ユーザー入力を受け付ける際に遭遇するクエリ構文の落とし穴について説明します。

---

## 1. スキーマ: 仮想テーブル + 外部コンテンツ + トリガー

`content=<table>` を使うとデータを通常のテーブルに保持しながら、FTS5 が検索インデックスのみを管理するようにできます:

```sql
CREATE TABLE articles (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    title      TEXT NOT NULL,
    body       TEXT NOT NULL,
    author     TEXT NOT NULL,
    created_at TEXT NOT NULL
);

CREATE VIRTUAL TABLE articles_fts USING fts5(
    title,
    body,
    author,
    content=articles,   -- FTS5 は articles テーブルからコンテンツを読む
    content_rowid=id    -- FTS5 の rowid を articles.id にマッピングする
);

-- FTS インデックスをベーステーブルと同期させる
CREATE TRIGGER articles_ai AFTER INSERT ON articles BEGIN
    INSERT INTO articles_fts(rowid, title, body, author)
    VALUES (new.id, new.title, new.body, new.author);
END;

CREATE TRIGGER articles_au AFTER UPDATE ON articles BEGIN
    -- インデックスから古い行を削除し、更新された行を挿入する
    INSERT INTO articles_fts(articles_fts, rowid, title, body, author)
    VALUES ('delete', old.id, old.title, old.body, old.author);
    INSERT INTO articles_fts(rowid, title, body, author)
    VALUES (new.id, new.title, new.body, new.author);
END;

CREATE TRIGGER articles_ad AFTER DELETE ON articles BEGIN
    INSERT INTO articles_fts(articles_fts, rowid, title, body, author)
    VALUES ('delete', old.id, old.title, old.body, old.author);
END;
```

> **なぜトリガーを使うのか?** `content=` を指定した FTS5 は変更を自動追跡しません — トリガーでインデックスを管理する必要があります。`INSERT INTO fts(fts, rowid, ...) VALUES ('delete', ...)` パターンは、FTS5 がインデックスから行を削除するための方法です。

---

## 2. 検索クエリ

```php
$rows = $this->executor->fetchAll(
    "SELECT a.*, snippet(articles_fts, 1, '<b>', '</b>', '…', 10) AS snippet, rank
     FROM articles_fts
     JOIN articles a ON a.id = articles_fts.rowid
     WHERE articles_fts MATCH ?
     ORDER BY rank
     LIMIT ? OFFSET ?",
    [$query, $limit, $offset],
);
```

- `rank` は FTS5 が公開する仮想カラムです。値が小さい（負の値が大きい）ほど関連性が高いです。`ORDER BY rank` で最も関連性の高い結果を先頭に表示します。
- `snippet(table, column_index, open, close, ellipsis, token_count)` はハイライトされたフラグメントを返します。カラムインデックスは 0 始まりで: `0` = title、`1` = body です。

---

## 3. クエリ構文の落とし穴

### 3.1 ハイフンはカラムフィルタープレフィックス — フレーズセパレーターではない

**これはユーザー入力を受け付ける際に最もよく発生するバグの原因です。**

FTS5 は `word-other` をカラムフィルターとして解釈します: `word` という名前のカラムで `other` を検索します。`word` が FTS5 テーブルのカラムでない場合、SQLite はエラーをスローします:

```
General error: 1 no such column: text
```

```
full-text   ← エラー: "'full' カラムで 'text' を検索" — でも 'full' はカラムではない
full text   ← OK: AND クエリ（"full" と "text" の両方にマッチ）
"full text" ← OK: フレーズクエリ（正確な連続順序）
```

このエラーは `DatabaseConnectionException` として伝播し、入力をサニタイズしない限り 500 レスポンスになります。

**FTS5 に渡す前にユーザー入力をサニタイズしてください:**

```php
private function sanitizeFtsQuery(string $query): string
{
    // ハイフンをスペースに置き換える: "full-text" → "full text"（AND ロジック）
    return str_replace('-', ' ', $query);
}
```

またはフレーズマッチのためにダブルクォートでエスケープすることもできます:

```php
private function sanitizeFtsQuery(string $query): string
{
    // クエリ全体をダブルクォートで囲んでフレーズマッチを強制する
    $escaped = str_replace('"', '""', $query);
    return '"' . $escaped . '"';
}
```

### 3.2 デフォルトではステミングなし

デフォルトの `unicode61` トークナイザーはステミングを行いません。`framework` は `frameworks` にマッチせず、`run` は `running` にマッチしません。

オプション:

| アプローチ | 方法 |
|---|---|
| 完全一致 | ドキュメントとクエリの両方で正確な語形を使用する |
| プレフィックスマッチ | クエリ用語の末尾に `*` を付ける: `framework*` は `framework`、`frameworks`、`framework-agnostic` にマッチ |
| Porter ステマー | `CREATE VIRTUAL TABLE` 文で `tokenize='porter ascii'` を宣言する |

**プレフィックスマッチの例:**

```php
// ユーザーが "frame" と入力 → "framework"、"frameworks" 等にマッチするよう * を付ける
$query = trim($userInput) . '*';
```

**Porter ステマー:**

```sql
CREATE VIRTUAL TABLE articles_fts USING fts5(
    title, body, author,
    content=articles,
    content_rowid=id,
    tokenize='porter ascii'  -- 英語のステミング
);
```

> `porter` トークナイザーは SQLite が FTS5 サポート付きでコンパイルされている場合のみ利用可能です（標準ビルドには含まれています）。英語テキストに有用です。他の言語では、インデックス作成前の外部ステミングを検討してください。

### 3.3 AND / OR / NOT 演算子

FTS5 クエリ構文:

| 構文 | 意味 |
|---|---|
| `one two` | AND: 両方が存在する必要がある |
| `one OR two` | OR: どちらかが存在する必要がある |
| `one NOT two` | NOT: 最初は存在し、2 番目は不在 |
| `"one two"` | フレーズ: 正確な連続順序 |
| `one*` | プレフィックス: `one`、`ones` 等にマッチ |
| `title:query` | カラムフィルター: `title` カラムに検索を限定する |

> **注意**: `NOT` は大文字でなければなりません。小文字の `not` は検索語として扱われます。

---

## 4. 検索結果のカウント

別クエリで FTS5 マッチに `COUNT(*)` を使ってカウントします:

```php
$count = $this->executor->fetchOne(
    'SELECT COUNT(*) AS cnt FROM articles_fts WHERE articles_fts MATCH ?',
    [$query],
);
```

---

## 5. リポジトリの完全な例

```php
final readonly class ArticleRepository
{
    public function __construct(
        private DatabaseQueryExecutorInterface $executor,
    ) {}

    /** @return list<array<string, mixed>> */
    public function search(string $userQuery, int $limit, int $offset): array
    {
        $query = $this->sanitizeFtsQuery($userQuery);

        return $this->executor->fetchAll(
            "SELECT a.*, snippet(articles_fts, 1, '<b>', '</b>', '…', 10) AS snippet, rank
             FROM articles_fts
             JOIN articles a ON a.id = articles_fts.rowid
             WHERE articles_fts MATCH ?
             ORDER BY rank
             LIMIT ? OFFSET ?",
            [$query, $limit, $offset],
        );
    }

    public function countSearch(string $userQuery): int
    {
        $query = $this->sanitizeFtsQuery($userQuery);
        $row   = $this->executor->fetchOne(
            'SELECT COUNT(*) AS cnt FROM articles_fts WHERE articles_fts MATCH ?',
            [$query],
        );

        return (int) ($row['cnt'] ?? 0);
    }

    private function sanitizeFtsQuery(string $query): string
    {
        // ハイフンをスペースに置き換える: "full-text" → "full text"（AND ロジック）
        return str_replace('-', ' ', trim($query));
    }
}
```
