# ハウツー: SQLite FTS5 全文検索

> **FT リファレンス**: FT254 (`NENE2-FT/ftslog`) — SQLite FTS5 による全文検索

SQLite の組み込み FTS5 拡張を使った全文検索（FTS）を実演します。仮想の `posts_fts` テーブルが `posts` テーブルをミラーし、トリガーで同期を保ちます。
検索は `MATCH` を使い、`fts.rank` によって関連度でランク付けされた結果を返します。

---

## ルート

| メソッド | パス | 説明 |
|--------|------|------|
| `POST` | `/posts`         | 投稿を作成する（自動的にインデックス化） |
| `GET`  | `/posts`         | すべての投稿を一覧表示する             |
| `GET`  | `/posts/search`  | 全文検索（`?q=`）                      |

> **ルート順序**: `/posts/search` はリテラルセグメント `search` がパスパラメーターとしてキャプチャされないよう、`/posts/{id}` より**前に**登録しなければなりません。

---

## スキーマ: FTS5 仮想テーブル + トリガー

```sql
CREATE TABLE IF NOT EXISTS posts (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    title      TEXT    NOT NULL,
    body       TEXT    NOT NULL DEFAULT '',
    tags       TEXT    NOT NULL DEFAULT '',  -- スペース区切りタグ文字列
    created_at TEXT    NOT NULL
);

-- FTS5 仮想テーブル: 全文検索用に posts をシャドウ
CREATE VIRTUAL TABLE IF NOT EXISTS posts_fts USING fts5(
    title,
    body,
    tags,
    content='posts',      -- 外部コンテンツテーブル
    content_rowid='id'    -- コンテンツテーブルの rowid カラム
);

-- FTS インデックスを posts と同期
CREATE TRIGGER IF NOT EXISTS posts_ai AFTER INSERT ON posts BEGIN
    INSERT INTO posts_fts(rowid, title, body, tags)
    VALUES (new.id, new.title, new.body, new.tags);
END;

CREATE TRIGGER IF NOT EXISTS posts_ad AFTER DELETE ON posts BEGIN
    INSERT INTO posts_fts(posts_fts, rowid, title, body, tags)
    VALUES ('delete', old.id, old.title, old.body, old.tags);
END;

CREATE TRIGGER IF NOT EXISTS posts_au AFTER UPDATE ON posts BEGIN
    INSERT INTO posts_fts(posts_fts, rowid, title, body, tags)
    VALUES ('delete', old.id, old.title, old.body, old.tags);
    INSERT INTO posts_fts(rowid, title, body, tags)
    VALUES (new.id, new.title, new.body, new.tags);
END;
```

**`content='posts'`** は `posts_fts` をコンテンツテーブルとして宣言します — FTS トークンを保存しますが実際のテキスト保存は `posts` に委譲します。これにより全文の複製を避けられます。

**`content_rowid='id'`** は FTS5 に結合に使用する `posts` のカラムを伝えます。

**トリガー**は FTS インデックスを同期させます。これなしでは、`posts` への挿入と更新が `posts_fts` に反映されません。削除トリガーは FTS インデックスから行を削除するために特別な `'delete'` コマンド構文を使用します。

---

## スペース区切り文字列としてのタグ

```php
$tags = isset($body['tags']) && is_string($body['tags']) ? trim($body['tags']) : '';
// 例: "php api backend"
$post = $this->repo->create($title, $postBody, $tags, $now);
```

タグは JSON 配列や M:N 結合テーブルではなくスペース区切り文字列（例: `"php api backend"`）として保存されます。これにより JOIN なしで FTS5 でタグを検索できます — `kubernetes` の検索は `"docker kubernetes devops"` とタグ付けされた投稿にマッチします。

**トレードオフ**:

| アプローチ | FTS 検索可能 | 正確なタグフィルター | 正規タグエンティティ |
|---|---|---|---|
| スペース区切り文字列 | ✅ | ❌（LIKE 必要） | ❌ |
| M:N 結合テーブル | ❌（JOIN 必要） | ✅（IN 句） | ✅ |
| JSON 配列カラム | 限定的（`json_each`） | 限定的 | ❌ |

正確なタグフィルタリングが主要なユースケースの場合は M:N 結合テーブルアプローチを使用してください（[`multi-value-tag-filter.md`](multi-value-tag-filter.md) 参照）。

---

## 全文検索クエリ: `MATCH` + `rank`

```php
public function search(string $query): array
{
    if (trim($query) === '') {
        return [];
    }

    $rows = $this->executor->fetchAll(
        'SELECT p.*, fts.rank
         FROM posts_fts fts
         JOIN posts p ON p.id = fts.rowid
         WHERE posts_fts MATCH ?
         ORDER BY fts.rank',
        [$query],
    );

    return array_map(
        static fn (array $row): SearchResult => new SearchResult(
            new Post(...),
            (float) $row['rank'],
        ),
        $rows,
    );
}
```

`WHERE posts_fts MATCH ?` はすべてのインデックスカラム（`title`、`body`、`tags`）を検索します。`?` プレースホルダーはパラメーター化された値です — クエリ文字列は SQL に補間されないため、クエリ構造を変更できません。

`fts.rank` は負の浮動小数点数です — より小さい（より負の）値はより高い関連度を示します。`ORDER BY fts.rank` は最良のマッチを最初にソートします（昇順、最も関連度が高い順）。

---

## FTS5 クエリ構文

FTS5 は MATCH 値として渡される豊かなクエリ言語をサポートします:

| クエリ | マッチ |
|-------|-------|
| `php` | "php" を含む任意の投稿 |
| `php api` | "php" と "api" の**両方**を含む投稿（デフォルト: 暗黙の AND） |
| `php AND api` | "php" と "api" の両方を含む投稿（明示、上と同じ） |
| `php OR api` | "php" または "api" を含む投稿 |
| `"quick brown"` | 正確なフレーズ "quick brown" を含む投稿 |
| `php*` | 任意のトークンが "php" で始まる投稿（プレフィックス検索） |
| `title:php` | タイトルカラムに "php" を含む投稿 |
| `php NOT python` | "php" を含むが "python" を含まない投稿 |

フレーズ検索（`"..."`）は正確なトークンシーケンスにマッチします。
カラムスコープ検索（`title:php`）は 1 つのカラムにマッチングを制限します。

---

## 無効なクエリハンドリング: try-catch → 400

FTS5 はクエリ構文が無効な場合に `PDOException`（またはラップしたもの）をスローします:

```php
private function searchPosts(ServerRequestInterface $request): ResponseInterface
{
    $query = isset($params['q']) && is_string($params['q']) ? trim($params['q']) : '';

    if ($query === '') {
        return $this->json->create(['error' => 'q query parameter is required'], 422);
    }

    try {
        $results = $this->repo->search($query);
    } catch (\Exception $e) {
        // FTS5 は構文エラーでスローする（例: 閉じられていない引用符: '"unclosed'）
        return $this->json->create(['error' => 'invalid search query'], 400);
    }

    return $this->json->create([...]);
}
```

無効な FTS クエリ（閉じられていない引用符、不正な演算子）は DB 例外を引き起こします。それをキャッチして `400 Bad Request` を返すことで、`500` がクライアントに漏洩することを防ぎます。

---

## 大文字小文字の区別なし

FTS5 は ASCII 文字のデフォルトで大文字小文字を区別しません。`php` の検索は `PHP`、`Php`、または `php` を含む投稿にマッチします。非 ASCII の大文字小文字の統一にはカスタムトークナイザー（`unicode61` または `ascii`）が必要です。デフォルトの `porter` トークナイザーは英語の語句にステミングを適用します。

---

## 検索レスポンス

```json
GET /posts/search?q=php

{
    "query": "php",
    "total": 2,
    "items": [
        {
            "id": 1,
            "title": "PHP Framework",
            "body": "Building APIs with PHP",
            "tags": "php backend",
            "created_at": "2026-05-27T10:00:00Z",
            "rank": -1.234
        }
    ]
}
```

`rank` は各結果に含まれ、クライアント側の表示やソートに使用できます。
`rank` が小さい（より負の）ほど関連度が高くなります。

---

## 比較: FTS5 vs LIKE 検索

| 機能 | FTS5 MATCH | LIKE `%term%` |
|---|---|---|
| インデックス済み | ✅ | ❌（フルスキャン） |
| 関連度ランキング | ✅（`rank`） | ❌ |
| 複数語検索 | ✅（自然） | ❌（複数の LIKE 必要） |
| フレーズ検索 | ✅（`"..."`） | 部分的（`%quick brown%`） |
| 大文字小文字区別なし | ✅（ASCII） | ✅（NOCASE で） |
| プレフィックス検索 | ✅（`php*`） | ✅（`php%`） |
| カラムスコープ | ✅（`title:php`） | ❌ |
| セットアップコスト | FTS 仮想テーブル + トリガー | なし |

FTS5 は検索が主要機能である大規模なデータセットに適しています。LIKE は小さなテーブルやシンプルなプレフィックスオートコンプリートに十分です。

---

## 関連 howto

- [`use-fts5-search.md`](use-fts5-search.md) — 既存のテーブルへの FTS5 の追加
- [`search-autocomplete.md`](search-autocomplete.md) — LIKE ベースのプレフィックスオートコンプリート（searchlog FT157）
- [`multi-value-tag-filter.md`](multi-value-tag-filter.md) — AND/OR セマンティクスを持つ M:N タグフィルタリング
- [`event-analytics-api.md`](event-analytics-api.md) — JSON プロパティ検索の `json_extract()`
