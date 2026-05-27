# スラグ管理 — 衝突解決と履歴付きの一意な URL スラグ

タイトルから URL セーフなスラグを生成し、衝突を自動的に解決し、**スラグ履歴テーブル**を保持することで古いスラグが受信リンクを壊さずに正規 URL にリダイレクトされるようにします。

**参照実装:** [hideyukiMORI/NENE2-examples](https://github.com/hideyukiMORI/NENE2-examples) の `FT174 sluglog`

---

## スキーマ

```sql
CREATE TABLE articles (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    title      TEXT    NOT NULL,
    slug       TEXT    NOT NULL UNIQUE,   -- 現在の正規スラグ
    body       TEXT    NOT NULL,
    created_at TEXT    NOT NULL,
    updated_at TEXT    NOT NULL
);

-- リダイレクトサポートのために古いスラグを保持
CREATE TABLE slug_history (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    article_id  INTEGER NOT NULL,
    old_slug    TEXT    NOT NULL UNIQUE,  -- リダイレクトソース; UNIQUE が重複を防ぐ
    replaced_at TEXT    NOT NULL,
    FOREIGN KEY (article_id) REFERENCES articles(id)
);
```

---

## スラグ生成

```php
final class SlugHelper
{
    public static function fromTitle(string $title): string
    {
        $slug = mb_strtolower($title);
        $slug = (string) preg_replace('/[^a-z0-9]+/', '-', $slug);
        $slug = trim($slug, '-');
        return $slug !== '' ? $slug : 'untitled';
    }

    /**
     * @param callable(string): bool $exists  スラグが使用済みの場合に true を返す。
     */
    public static function makeUnique(string $base, callable $exists): string
    {
        if (!$exists($base)) {
            return $base;
        }
        $counter = 2;
        while ($exists("{$base}-{$counter}")) {
            $counter++;
        }
        return "{$base}-{$counter}";
    }
}
```

### 一意性チェック — 両方のテーブルを含める

スラグが「使用済み」かどうかを確認する際は、`articles.slug` と `slug_history.old_slug` の**両方**を確認してください。そうしないと、新しい記事がまだリダイレクトソースとして使用中のスラグを主張できます:

```php
private function slugExists(string $slug): bool
{
    return $this->db->fetchOne('SELECT id FROM articles WHERE slug = ?', [$slug]) !== null
        || $this->db->fetchOne('SELECT id FROM slug_history WHERE old_slug = ?', [$slug]) !== null;
}
```

---

## リダイレクトヒント付きのスラグ検索

```php
public function findBySlugWithRedirect(string $slug): ?array
{
    // 1. 現在のスラグ列を確認 (200 OK)
    $article = $this->findBySlug($slug);
    if ($article !== null) {
        return ['found' => $article, 'redirect' => false];
    }

    // 2. スラグ履歴を確認 (301 リダイレクトヒント)
    $row = $this->db->fetchOne(
        'SELECT article_id FROM slug_history WHERE old_slug = ?', [$slug],
    );
    if ($row === null) {
        return null;  // 404
    }

    $article = $this->findById((int) $row['article_id']);
    return $article !== null ? ['found' => $article, 'redirect' => true] : null;
}
```

ハンドラーは `canonical_slug` と `data` を含む HTTP 301 を返します:

```json
// GET /articles/by-slug/old-title  →  301
{
  "redirect": true,
  "canonical_slug": "new-title",
  "data": { "id": 1, "slug": "new-title", ... }
}
```

---

## スラグ更新 — 履歴を記録する

記事の名前が変更されたとき、古いスラグを `slug_history` に移動します:

```php
if ($newSlug !== $article->slug) {
    // まだ履歴にない場合のみ挿入（べき等）
    $alreadyIn = $this->db->fetchOne(
        'SELECT id FROM slug_history WHERE old_slug = ?', [$article->slug],
    );
    if ($alreadyIn === null) {
        $this->db->insert(
            'INSERT INTO slug_history (article_id, old_slug, replaced_at) VALUES (?, ?, ?)',
            [$id, $article->slug, $now],
        );
    }
}
```

### 更新時の衝突処理

更新された記事の新しいスラグを計算する際、記事自身の**現在の**スラグを「存在」チェックから除外してください — そうしないと不必要に `-2` にインクリメントされます:

```php
$newSlug = SlugHelper::makeUnique(
    $newSlugBase,
    fn (string $s): bool => $s !== $article->slug && $this->slugExists($s),
);
```

---

## エンドポイント

| メソッド | パス | 説明 |
|---|---|---|
| `POST` | `/articles` | 記事を作成する — スラグはタイトルから自動導出 |
| `GET` | `/articles/{id}` | 数値 ID で取得する |
| `GET` | `/articles/by-slug/{slug}` | スラグで取得する（200 現在 / 301 履歴 / 404） |
| `PUT` | `/articles/{id}` | タイトル/ボディ/スラグを更新する; 古いスラグ → 履歴 |
| `GET` | `/articles/{id}/slug-history` | 履歴スラグを一覧表示する |

---

## 衝突シナリオ

| シナリオ | 結果 |
|---------|------|
| 最初の "Hello World" | `hello-world` |
| 2 番目の "Hello World" | `hello-world-2` |
| 3 番目の "Hello World" | `hello-world-3` |
| 記事をすでに使用済みのスラグに改名 | `taken-slug-2` |
| 同じタイトル、スラグに変更なし | 履歴エントリなし、スラグ変更なし |
| 古いスラグが履歴エントリに一致 | 301 リダイレクトレスポンス |

---

## ドメインレイヤー構造

```
src/Article/
├── Article.php
├── ArticleRepository.php   # create / findBySlug / findBySlugWithRedirect / update / slugHistory
├── SlugHelper.php          # fromTitle() + makeUnique()
└── ArticleNotFoundException.php
```

---

## 参照先

- [ソフトデリート](./soft-delete.md) — スラグ履歴とソフトデリートレコードの組み合わせ
- [コンテンツバージョニング](./content-versioning.md) — スラグ履歴と並んだバージョン履歴
- [コンテンツドラフトライフサイクル](./content-draft-lifecycle.md) — ドラフトステートにまたがるスラグの動作
