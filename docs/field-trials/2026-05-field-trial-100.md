# Field Trial 100 — Large Dataset Pagination: OFFSET vs Cursor Performance

**Date:** 2026-05-21
**Project:** `/home/xi/docker/NENE2-FT/pagelog/`
**NENE2 version:** 1.5.33
**Theme:** ページネーション方式の比較 — OFFSET と カーソル（keyset）の正確性・パフォーマンス特性の検証

---

## What was built

記事一覧 API に OFFSET ベースと カーソルベースの2通りのページネーションを実装し、正確性・実装コスト・パフォーマンス特性を比較した。500 行シードで深いページ（490 行スキップ）の結果が両方式で一致することを確認した。

---

## Findings

### 1. Namespace の罠 — `"Page\\": "src/Page/"` は `Page\Foo`（中間ディレクトリ不要）（摩擦あり・高）

**事象:** `namespace Page\Page;` と書いた場合、オートローダーは `src/Page/Page/Foo.php` を探す。実際のファイルは `src/Page/Foo.php` にあるため、`Class "Page\Page\SqliteArticleRepository" not found` が発生する。

**原因:** PSR-4 の規則: `"Page\\": "src/Page/"` は「`Page\` を取り除いた残りのパスを `src/Page/` 配下で探す」。ファイルが `src/Page/Foo.php` にあるなら namespace は `Page\Foo`（`Page\Page\Foo` ではない）。

**修正:** namespace を `Page` に統一。

**DX観点:** FT プロジェクトの namespace 設計でよくある初心者ミス。NENE2 ではなく PHP PSR-4 の仕様だが、最初の FT で必ずはまる。「src のディレクトリ名がそのまま namespace のルートになる」という命名規則で防げる。

---

### 2. `ProblemDetailsResponseFactory::create()` の引数順序（摩擦あり・中）

**誤った呼び出し（FT99 の csrflog パターンを参考に書いた）:**

```php
$problems->create($request, 'validation-failed', 'Validation failed.', $errors, 422);
// ↑ PHPStan: argument.type (status が array、detail が int になる)
```

**正しい呼び出し:**

```php
$problems->create($request, $type, $title, $status, $detail, $extensions);
// 例:
$problems->create($request, 'validation-failed', 'Validation failed.', 422, null, ['errors' => $errors]);
```

**シグネチャ:**

```php
public function create(
    ServerRequestInterface $request,
    string $type,
    string $title,
    int $status,
    ?string $detail = null,
    array $extensions = [],
): ResponseInterface
```

PHPStan level 8 がすぐに検出するため、ランタイムで気づかずに済んだ。引数順序は直感と少し異なる（`status` が `title` の直後に来ることが多い他フレームワークと違う）。

---

### 3. OFFSET vs カーソル — 実装コストと特性の比較

#### OFFSET（シンプルだが深くなると遅い）

```php
// 実装が最も単純
$rows = $this->executor->fetchAll(
    'SELECT * FROM articles ORDER BY id DESC LIMIT ? OFFSET ?',
    [$limit, $offset],
);
$total = $this->count(); // 総件数が別クエリで必要
```

| 特徴 | OFFSET |
|---|---|
| 実装 | シンプル |
| 総件数 | COUNT(*) が必要 |
| 深いページ | DB がスキップ行を全スキャン（遅くなる） |
| ページ番号表示 | 容易 |
| リアルタイム更新 | ページずれ発生 |

SQLite の `EXPLAIN QUERY PLAN` で確認すると、`OFFSET 490` は 490 行を読んでから捨てる。行が増えるほど深いページのコストが線形に増加する。

#### カーソル（実装はやや複雑だが定速）

```php
// fetch+1 で has_more を COUNT なしに判定するパターン
$fetch   = $limit + 1;
$rows    = $this->executor->fetchAll(
    'SELECT * FROM articles WHERE id < ? ORDER BY id DESC LIMIT ?',
    [$afterId, $fetch],
);
$hasMore = count($rows) > $limit;
if ($hasMore) {
    array_pop($rows); // 余分な1件を捨てる
}
$nextCursor = $hasMore && $rows !== [] ? end($rows)->id : null;
```

| 特徴 | カーソル |
|---|---|
| 実装 | やや複雑（fetch+1 パターン） |
| 総件数 | 不要（has_more のみ） |
| 深いページ | インデックス seek で定速 |
| ページ番号表示 | 困難（前のページに戻れない） |
| リアルタイム更新 | 安全（ページずれなし） |

`WHERE id < ? ORDER BY id DESC` はインデックスで直接シークするため、1ページ目も50ページ目も同じ速度。

#### 選択基準

- **ページ番号 UI、管理画面、総件数が必要** → OFFSET（行数が少ない場合）
- **無限スクロール、フィード、大量データ** → カーソル

---

### 4. 深いページでの結果一致（正確性確認）

500 行シードで OFFSET(490, 10) とカーソルで同じ位置のページを取得し、ID 列が完全一致することを確認した。

```php
// OFFSET で490行目以降を取得
$offsetPage = $this->get('/articles/offset?limit=10&offset=490');

// カーソルで同じ位置: 489行目の id を anchor として使う
$anchor     = $this->get('/articles/offset?limit=1&offset=489');
$anchorId   = $anchor['items'][0]['id'];
$cursorPage = $this->get("/articles/cursor?limit=10&after={$anchorId}");

// 結果が一致
assertSame($offsetIds, $cursorIds);
```

---

## Test results

15 tests, 47 assertions — all pass.

Key behaviors confirmed:
- OFFSET 1ページ目・2ページ目・最終ページ・範囲外
- カーソル1ページ目・2ページ目・最終ページ・ページ間重複なし
- limit のクランプ（最小1・最大100）
- OFFSET とカーソルの1ページ目一致
- 500 行シードでの深いページ正確性（OFFSET と カーソルが同じ結果を返す）

---

## Developer Experience (DX) Review

### 初心者・ロースキル観点での実装しやすさ

OFFSET の実装は PSR-7 + NENE2 の fetchAll があれば非常に簡単。カーソルは「fetch+1 で has_more を判定する」パターンの知識が必要で、初見では思いつきにくい。NENE2 にカーソルページネーション用ヘルパーはないが、パターン自体は小さい。

### 使ってみた印象

`fetchAll` の SELECT + LIMIT/OFFSET は SQL と 1:1 で書けて直感的。`QueryStringParser::int($request, 'after')` でクエリパラメータが取れるのも簡単だった。

### 楽しいか・気持ちいいか・快適か

「同じ位置のページを OFFSET とカーソルの両方で取って、ID が一致する」というテストが書けたのは面白かった。500 行のシードをループで挿入して大きなデータセットをテストできるのも実験的で楽しい。

### 簡単か

OFFSET は簡単。カーソルは「fetch+1 パターン」「has_more の判定」「next_cursor は末尾の ID」という3つの知識が必要で、少し難しい。

### また使いたいか

はい。カーソルパターンは一度書けばコピペで使える。OFFSET は小さいデータなら最もシンプル。

### 初心者に勧めたいか

はい、ただし「OFFSET は大量データで遅くなる」という注意書きが必要。カーソルが必要になるタイミングをドキュメントで示せると、初心者が適切に選択できる。

---

## Issues / PRs

- Issue: `docs/howto/pagination.md` — OFFSET vs カーソルの選択基準・fetch+1 パターン・SQLite EXPLAIN QUERY PLAN の使い方・パフォーマンス特性の比較表
