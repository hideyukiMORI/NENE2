# ハウツー: 履歴付きのスラグ URL 管理

> **FT リファレンス**: FT339 (`NENE2-FT/sluglog`) — タイトルからの自動生成スラグ、衝突カウンター、古スラグ 301 リダイレクト用のスラグ履歴、明示的スラグオーバーライド、脆弱性アセスメント、17 テスト / 50+ アサーション PASS。

このガイドでは、コンテンツタイトルからクリーンな URL スラグを生成し、シーケンシャルサフィックスで衝突を処理し、古いスラグを永続的リダイレクト用の履歴テーブルに保存し、一般的な攻撃ベクターを防ぐ方法を解説します。

## スキーマ

```sql
CREATE TABLE articles (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    title      TEXT    NOT NULL,
    body       TEXT    NOT NULL,
    slug       TEXT    NOT NULL UNIQUE,
    created_at TEXT    NOT NULL,
    updated_at TEXT    NOT NULL
);

CREATE TABLE slug_history (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    article_id INTEGER NOT NULL REFERENCES articles(id),
    old_slug   TEXT    NOT NULL,
    created_at TEXT    NOT NULL
);
```

## エンドポイント

| メソッド | パス | 説明 |
|--------|------|------|
| `POST` | `/articles` | 記事を作成する（タイトルからスラグを自動生成） |
| `PUT`  | `/articles/{id}` | 記事を更新する（タイトル変更時にスラグ再生成） |
| `GET`  | `/articles/by-slug/{slug}` | 現在または古いスラグで取得する |
| `GET`  | `/articles/{id}/slug-history` | スラグ履歴を一覧表示する |

## スラグ生成

### `SlugHelper::fromTitle()`

```php
SlugHelper::fromTitle('Hello World')          // → "hello-world"
SlugHelper::fromTitle('PHP 8.4: New Features!') // → "php-8-4-new-features"
SlugHelper::fromTitle('  --Hello--  ')        // → "hello"
SlugHelper::fromTitle('')                     // → "untitled"
SlugHelper::fromTitle('---')                  // → "untitled"
```

ルール:
1. すべてを小文字にする
2. 非英数字の文字を `-` に置換する
3. 連続するハイフンを折りたたむ
4. 先頭/末尾のハイフンをトリムする
5. 結果が空の場合は `"untitled"` を返す

```php
public static function fromTitle(string $title): string
{
    $slug = strtolower($title);
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
    $slug = trim($slug, '-');
    return $slug !== '' ? $slug : 'untitled';
}
```

### 衝突解決

```php
POST /articles  {"title": "Hello", "body": "..."}  → 201  {"slug": "hello"}
POST /articles  {"title": "Hello", "body": "..."}  → 201  {"slug": "hello-2"}
POST /articles  {"title": "Hello", "body": "..."}  → 201  {"slug": "hello-3"}
```

```php
public static function makeUnique(string $base, callable $isTaken): string
{
    if (!$isTaken($base)) {
        return $base;
    }

    $i = 2;
    while ($isTaken("{$base}-{$i}")) {
        $i++;
    }

    return "{$base}-{$i}";
}
```

`$isTaken` は DB 検索コールバックです: `fn(string $s): bool => (bool) $repo->findBySlug($s)`。

## 記事の作成

```php
POST /articles
{"title": "My First Post", "body": "Content here."}
→ 201
{
  "id": 1,
  "title": "My First Post",
  "slug": "my-first-post",
  "body": "...",
  "created_at": "..."
}
```

## 記事の更新

```php
PUT /articles/1
{"title": "New Title", "body": "Updated content."}
→ 200  {"slug": "new-title", ...}
```

タイトルが変わると、新しいスラグが導出され古いスラグが `slug_history` に保存されます。

```php
// 同じタイトル — スラグ変更なし、履歴エントリなし
PUT /articles/1  {"title": "New Title", "body": "Different body."}
→ 200  {"slug": "new-title"}  // 同じスラグ

// 明示的なスラグオーバーライド
PUT /articles/1  {"title": "New Title", "body": "Body.", "slug": "custom-url-here"}
→ 200  {"slug": "custom-url-here"}

// 更新時の衝突 — 自動解決
// ("popular" が存在する場合、"popular-2" に改名)
PUT /articles/2  {"title": "Popular", "body": "Body."}
→ 200  {"slug": "popular-2"}

// 未知の記事
PUT /articles/9999  {"title": "X", "body": "Y"}
→ 404
```

## スラグで取得

```php
// 現在のスラグ → 200
GET /articles/by-slug/new-title
→ 200  {"id": 1, "slug": "new-title", "title": "New Title", ...}

// 古いスラグ → 301 リダイレクト
GET /articles/by-slug/my-first-post
→ 301
{
  "redirect": true,
  "canonical_slug": "new-title"
}

// 未知 → 404
GET /articles/by-slug/does-not-exist
→ 404
```

301 レスポンスはクローラー/クライアントに正規スラグへのリンクを更新するよう伝えます。

## スラグ履歴

```php
GET /articles/1/slug-history
→ 200
{
  "current_slug": "new-title",
  "slug_history": [
    {"old_slug": "my-first-post", "created_at": "..."}
  ]
}

// 新しい記事 — 空の履歴
{"current_slug": "fresh", "slug_history": []}

// 未知の記事 → 404
GET /articles/9999/slug-history → 404
```

履歴エントリはスラグが実際に変更されたときのみ蓄積されます。タイトルを変更せずにボディを更新しても履歴は変わりません。

---

## 脆弱性アセスメント

### V-01 — スラグを経由したパストラバーサル ✅ SAFE

**リスク**: 攻撃者が `GET /articles/by-slug/../../../etc/passwd` を送信してサーバーディレクトリを走査する。
**発見**: SAFE — スラグ検索はバインドパラメーター付きの SQL `WHERE slug = ?` です。パスセグメントはファイルシステムパスとして解釈されません。ルーティングがパスをコントローラーに到達する前にパースします; URL パスの `../` は HTTP レイヤーによって正規化されます。

---

### V-02 — URL のスラグを経由した SQL インジェクション ✅ SAFE

**リスク**: `GET /articles/by-slug/' OR '1'='1` がすべての記事を漏洩させる。
**発見**: SAFE — スラグは `WHERE slug = ?` のバインドパラメーターとして渡されます。スラグ値に関わらず SQL インジェクションは不可能です。

---

### V-03 — スラグ列挙（ブルートフォース発見） ⚠️ EXPOSED

**リスク**: 攻撃者が一般的なスラグ（`/articles/by-slug/admin`、`/articles/by-slug/secret-doc`）を反復してプライベート記事を発見する。
**発見**: EXPOSED — スラグは人間が読めるタイトルから予測可能に導出されます。`GET /articles/by-slug/{slug}` にはレート制限も認証も強制されていません。緩和策: プライベートコンテンツに認証を要求する; IP ごとのレート制限を追加する; 機密リソースには不透明な ID を検討する。

---

### V-04 — スラグ履歴の IDOR ✅ SAFE

**リスク**: 攻撃者が別のユーザーの記事の `GET /articles/{id}/slug-history` を呼び出して過去のタイトルを発見する。
**発見**: SAFE — スラグ履歴はパブリックメタデータです。記事がパブリックなら、その履歴もそうです。記事に認可が必要な場合は、`/slug-history` エンドポイントにも同じ認証チェックを一貫して適用してください。

---

### V-05 — スラグ履歴による無限リダイレクトループ ✅ SAFE

**リスク**: 記事 A がスラグ B に改名; 記事 B がスラグ A に改名 — `GET /by-slug/a` → B にリダイレクト → A にリダイレクト（無限ループ）。
**発見**: SAFE — 実装は `articles.slug` の**現在の**スラグを検索し、次に古いスラグのみ `slug_history` を確認します。301 レスポンスは常に現在の正規スラグを指します。リダイレクトに従うクライアントは 1 ホップで正規スラグに到達します。

---

### V-06 — スラグ衝突の悪用（シーケンシャルカウンター枯渇） ⚠️ EXPOSED

**リスク**: 攻撃者が "popular" というタイトルで何千もの記事を作成して "popular-2" から "popular-9999" を予約し、その後削除する — または高コストなカウンタースキャンを強制する。
**発見**: EXPOSED — 記事作成にレート制限がありません。`makeUnique` カウンタースキャンは O(n) の DB クエリです。緩和策: ユーザーごとに POST /articles をレート制限する; スラグカウンターを合理的な制限（例: 99）でキャップする; しきい値後はランダムサフィックスを使用する。

---

### V-07 — 明示的なスラグインジェクション（別の記事のスラグを上書き） ✅ SAFE

**リスク**: 攻撃者が `PUT /articles/2  {"slug": "popular"}` を使用して "popular" が記事 1 に属している場合に上書きする。
**発見**: SAFE — `articles.slug` には `UNIQUE` 制約があります。別の記事がすでに主張しているスラグを設定しようとすると DB 制約違反が発生し、409 Conflict に変換されます。

---

### V-08 — Unicode/ホモグラフスラグ攻撃 ⚠️ EXPOSED

**リスク**: 攻撃者が既存の ASCII スラグと同じバイトに正規化される Unicode タイトル（例: `café` → `caf-`）で記事を作成して視覚的に混乱する URL を作成する。
**発見**: EXPOSED — `SlugHelper::fromTitle()` は `preg_replace('/[^a-z0-9]+/', '-', strtolower($title))` を使用します。非 ASCII 文字は `-` に置換され、予期しない衝突または空のスラグが発生する場合があります。緩和策: スラグ生成前に Unicode を ASCII 音訳に正規化する（例: `iconv`）; 正規化後すべての非 ASCII を `-` として扱う。

---

### V-09 — スラグに保存されたタイトルを経由した XSS ✅ SAFE

**リスク**: タイトル `<script>alert(1)</script>` がスラグ `script-alert-1-script` を生成 — 安全な英数字出力。
**発見**: SAFE — `SlugHelper::fromTitle()` はすべての非英数字文字を `-` に除去します。スラグ出力は常に `[a-z0-9-]` であり、スラグ経由の HTML インジェクションは不可能です。

---

### V-10 — 古いスラグ検索が改名されたコンテンツを明かす ⚠️ EXPOSED

**リスク**: 記事が "secret-plan-v1" から "public-announcement" に改名; 攻撃者が古いスラグを使用してリダイレクトレスポンスの `canonical_slug` 経由で元のタイトルを発見する。
**発見**: EXPOSED — 301 レスポンスは新しい正規スラグを公開し、改名されたコンテンツを明かす場合があります。スラグ履歴エンドポイントもすべての古い名前を明かします。機密の改名には、新しい場所を明かさずに古いスラグをトゥームストーン化するか、不透明なスラグを使用してください。

---

### VULN サマリー

| ID | 脆弱性 | 発見 |
|----|--------|------|
| V-01 | スラグを経由したパストラバーサル | ✅ SAFE |
| V-02 | スラグを経由した SQL インジェクション | ✅ SAFE |
| V-03 | スラグ列挙 | ⚠️ EXPOSED |
| V-04 | スラグ履歴の IDOR | ✅ SAFE |
| V-05 | 無限リダイレクトループ | ✅ SAFE |
| V-06 | 衝突カウンター枯渇 | ⚠️ EXPOSED |
| V-07 | 明示的なスラグ上書き | ✅ SAFE |
| V-08 | Unicode ホモグラフ攻撃 | ⚠️ EXPOSED |
| V-09 | タイトルを経由した XSS | ✅ SAFE |
| V-10 | 古いスラグが改名されたコンテンツを明かす | ⚠️ EXPOSED |

**6 SAFE、4 EXPOSED** — 記事作成をレート制限する; プライベートコンテンツに認証を追加する; スラグ生成前に Unicode を正規化する; 機密の改名にはトゥームストーン専用のスラグ履歴を検討する。

---

## してはいけないこと

| アンチパターン | リスク |
|---|---|
| スラグを SQL に直接補間する | スラグパスパラメーター経由の SQL インジェクション |
| 記事削除時にスラグ履歴をハードデリートする | 古い URL が 301 ではなく 404 を返す; SEO とリンク腐敗 |
| `articles.slug` に `UNIQUE` 制約なし | 同時挿入で重複スラグが作成される |
| タイトル更新時に古いスラグをそのまま返す | スラグドリフト — URL がコンテンツを反映しなくなる |
| `makeUnique` にカウンターの上限なし | 攻撃者が一括作成でカウンターを枯渇させる |
| 既存スラグの比較に `!==` を使用する | 型強制の驚き; スラグ比較には常に `===` を使用する |
