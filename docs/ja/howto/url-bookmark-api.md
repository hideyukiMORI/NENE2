# ハウツー: タグフィルタリング付き URL ブックマーク API

> **FT リファレンス**: FT265 (`NENE2-FT/linklog`) — URL ブックマーク API: UNIQUE URL 制約、カンマ区切りタグストレージ、LIKE ベースのタグマッチング

URL をカンマ区切り TEXT カラムのタグと共に保存するブックマーク API を実演します。
重複 URL は `UNIQUE` 制約で検出され、409 Conflict にマッピングされた `DuplicateUrlException` として扱われます。タグフィルタリングは、カンマ区切り文字列内の位置に関わらずタグをマッチさせるために 4 つの LIKE パターンを使用します。

---

## ルート

| メソッド | パス | 説明 |
|--------|------|------|
| `POST`   | `/links`      | ブックマークを作成する                                    |
| `GET`    | `/links`      | ブックマークを一覧表示する（検索 + タグフィルター、ページネーション） |
| `GET`    | `/links/{id}` | 単一ブックマークを取得する                                |
| `DELETE` | `/links/{id}` | ブックマークを削除する                                    |

---

## スキーマ

```sql
CREATE TABLE IF NOT EXISTS links (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    url         TEXT    NOT NULL UNIQUE,
    title       TEXT    NOT NULL,
    description TEXT    NOT NULL DEFAULT '',
    tags        TEXT    NOT NULL DEFAULT '',
    created_at  TEXT    NOT NULL
);
```

`url TEXT NOT NULL UNIQUE` は DB レベルで URL ごとに 1 つのブックマークを強制します。
`tags TEXT` はカンマ区切りリストを保存します（例: `"php,api,rest"`）。これにより小規模なユースケースでは別の `link_tags` 結合テーブルが不要になります。

---

## タグ: カンマ区切り TEXT vs M:N 結合テーブル

| アプローチ | クエリの複雑さ | 使用するとき |
|---|---|---|
| カンマ区切り TEXT | LIKE パターン（タグごとに 4 つ） | 小さなデータセット; 稀なタグクエリ |
| M:N 結合テーブル（`link_tags`） | JOIN + GROUP BY または IN | 大きなデータセット; 頻繁な AND/OR フィルタリング |
| タグカラム付き FTS5 | `WHERE fts MATCH ?` | 複数カラムにわたる全文検索 |

カンマ区切り TEXT は実装が簡単で、リンクとタグの数が少ない場合に適しています。数千のリンクと複雑なタグクエリ（AND フィルター、正確なカウント）を持つデータセットには、結合テーブル（[`multi-value-tag-filter.md`](multi-value-tag-filter.md) 参照）が好ましいです。

---

## タグ LIKE マッチング: 4 つのパターン

カンマ区切りカラムに保存されたタグは 4 つの位置に現れる可能性があります:
1. **完全一致**: `tags = 'php'`（唯一のタグ）
2. **先頭**: `tags LIKE 'php,%'`（複数のうち最初）
3. **中間**: `tags LIKE '%,php,%'`（最初でも最後でもない）
4. **末尾**: `tags LIKE '%,php'`（複数のうち最後）

```php
if ($tags !== null) {
    foreach ($tags as $tag) {
        $sql      .= ' AND (tags = ? OR tags LIKE ? OR tags LIKE ? OR tags LIKE ?)';
        $params[]  = $tag;            // 完全一致: "php"
        $params[]  = $tag . ',%';     // プレフィックス: "php,..."
        $params[]  = '%,' . $tag . ',%';  // 中間: "...,php,..."
        $params[]  = '%,' . $tag;     // サフィックス: "...,php"
    }
}
```

タグごとに 4 つのパターンすべてが AND されます: リンクはリクエストされたすべてのタグにマッチする必要があります。これによりタグ間の AND フィルターが実装されます。各 `?` はパラメーター化されたバインディングです — インジェクションのリスクはありません。

**制限**: `ph` のタグ クエリは保存されたタグ `php` にマッチ**しません**。なぜならパターンは正確なデリミター（`,` または文字列境界）をチェックするからです。タグは部分文字列ではなく正確な文字列値でマッチされます。

---

## カンマ区切りタグのシリアライズとデシリアライズ

**保存**: `implode(',', $tags)` — `['php', 'api', 'rest']` → `'php,api,rest'`

**読み取り**: 
```php
$tagsStr = (string) $row['tags'];
$tags    = $tagsStr === '' ? [] : array_values(array_filter(explode(',', $tagsStr)));
```

`array_filter()` は先頭/末尾のカンマや二重カンマによって作成された空文字列を削除します。
`array_values()` は `list<string>` に再インデックスします。

**タグクエリ解析**: `?tags=php,api` → カンマで分割 → `['php', 'api']`

```php
$rawTags = QueryStringParser::string($request, 'tags');
$tags    = $rawTags !== null
    ? array_values(array_filter(array_map('trim', explode(',', $rawTags))))
    : null;
```

---

## 重複 URL: カスタム例外 + ハンドラー

```php
public function create(string $url, ...): Link
{
    try {
        $this->executor->execute(
            'INSERT INTO links (url, title, description, tags, created_at) VALUES (?, ?, ?, ?, ?)',
            [$url, $title, $description, implode(',', $tags), $createdAt],
        );
    } catch (DatabaseConnectionException $e) {
        $previous = $e->getPrevious();
        if ($previous !== null && str_contains($previous->getMessage(), 'UNIQUE constraint failed')) {
            throw new DuplicateUrlException($url);
        }
        throw $e;
    }

    return new Link($this->executor->lastInsertId(), ...);
}
```

リポジトリは汎用の `DatabaseConnectionException`（PDO 例外が発生したときフレームワークによってスローされる）をキャッチし、前の例外のメッセージを `UNIQUE constraint failed` で検査し、ドメイン固有の `DuplicateUrlException` として再スローします。これにより、ドメイン言語（`DuplicateUrlException`）がインフラの詳細（`PDOException`）と分離されます。

`DuplicateUrlExceptionHandler` ミドルウェアは `DuplicateUrlException` をキャッチし、409 Conflict Problem Details を返します:

```php
return $this->problems->create($request, 'duplicate-url', 'URL already exists.', 409, $e->url);
```

---

## 検索: タイトルと URL の LIKE

```php
if ($search !== null) {
    $sql      .= ' AND (title LIKE ? OR url LIKE ?)';
    $params[]  = '%' . $search . '%';
    $params[]  = '%' . $search . '%';
}
```

検索クエリは `title` と `url` の両方のカラムに適用されます。1 つの `$search` バインディングが両方のカラムに繰り返されます。タグフィルタリングと同様に、ワイルドカード `%` はユーザー入力からではなくクエリ文字列のリテラル SQL です — ユーザーの検索用語はパラメーターとしてバインドされます。

---

## 例: タグ AND フィルター

**リクエスト**: `GET /links?tags=php,api`

`tags` カラムに `php` と `api` の**両方**を持つリンクにマッチします:
- `"php,api"` ✓（php: プレフィックスマッチ、api: サフィックスマッチ）
- `"rest,php,api"` ✓（php: 中間マッチ、api: サフィックスマッチ）
- `"php"` ✗（`api` がない）

---

## 関連 howto

- [`multi-value-tag-filter.md`](multi-value-tag-filter.md) — AND/OR タグフィルタリングを持つ M:N 結合テーブル（大規模データセット向け）
- [`sqlite-fts5-search.md`](sqlite-fts5-search.md) — LIKE の代替としての FTS5 全文検索
- [`sql-injection-defence.md`](sql-injection-defence.md) — パラメーター化された LIKE パターンとインジェクション防御
