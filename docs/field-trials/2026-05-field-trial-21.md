# Field Trial 21 — feedlog: ページネーション・PATCH 実地検証

## Date

2026-05-20

## Baseline

- NENE2 v1.5.4（`hideyukimori/nene2: ^1.5`、Packagist から取得）
- PHP 8.4
- プロジェクト: **feedlog** — ニュースフィード管理 API
- エンティティ: `Entry`（title, url, category, is_read, published_at）
- DB: SQLite（テストごとにファイル新規生成・削除）
- テスト: PHPUnit 19/19・PHPStan level 8・PHP-CS-Fixer 全通過

## Goal

`PaginationQuery` / `PaginationResponse`（公開 API だが未検証）と PATCH ルートの摩擦を探す。

検証ポイント:
- `PaginationQueryParser` とカスタムフィルタの組み合わせ
- `PaginationResponse::toArray()` で total を含むパターン
- PATCH ルートの部分更新実装
- `JsonRequestBodyParser` の空ボディ挙動

---

## 実装ログ

1. `composer require hideyukimori/nene2:^1.5` — v1.5.4 インストール成功。
2. SQLite スキーマ（`database/schema.sql`）と `SqliteEntryRepository` を実装。
3. `EntryRouteRegistrar` に GET（ページネーション＋フィルタ）・POST・PATCH・DELETE を実装。
4. テスト実行 → 2 件失敗（F-4 による PATCH 空ボディ 400、テストヘルパーの `compact` バグ）。
5. 修正後 19/19 通過。PHPStan level 8: 0 エラー。PHP-CS-Fixer: 0 件。

---

## 摩擦記録

### F-1（中）: `PaginationQueryParser` とカスタムフィルタが分離している

**状況**: `PaginationQueryParser::parse()` は `limit` と `offset` のみを扱う。
カスタムフィルタ（`category`、`is_read` 等）は `$request->getQueryParams()` から
consumer が別途手動で抽出する必要がある。

```php
// F-1: 2 ステップのクエリ解析
$pagination = PaginationQueryParser::parse($request, defaultLimit: 20);

$query    = $request->getQueryParams();
$category = isset($query['category']) && $query['category'] !== ''
    ? (string) $query['category'] : null;
```

ページネーション付きリストエンドポイントでは必ずこのパターンが必要になる。
`PaginationQueryParser` がフィルタを一切知らないため、consumer がすべての
クエリパラメータ解析を自前で実装することになる。

**期待する解決策**:
- `PaginationQueryParser::parse()` に加えて、`$request` からクエリ文字列全体を
  型変換付きで取得するユーティリティ（例: `QueryParser::string($request, 'category')`）を提供する。
- または howto に「ページネーション＋フィルタ」のパターンを記載する。

---

### F-2（低）: boolean クエリパラメータの文字列変換が必要

**状況**: HTTP クエリ文字列はすべて文字列。`?is_read=false` は PHP では
`"false"` という truthy 文字列として渡される。

```php
// PHP の is_bool($query['is_read']) は常に false
// (bool) "false" === true  ← 誤り
// (bool) "0"    === false  ← 正しい

// 正しい変換が必要
$isRead = null;
if (isset($query['is_read'])) {
    $raw    = $query['is_read'];
    $isRead = !in_array($raw, ['0', 'false', 'no'], true);
}
```

NENE2 に boolean クエリパラメータを解析するヘルパーがなく、consumer が毎回
変換ロジックを書く必要がある。F-1 と同根の問題。

---

### F-3（中）: PATCH の部分更新に `array_key_exists()` が必要

**状況**: PATCH リクエストでは「フィールドが body に存在しない」（更新しない）と
「フィールドが明示的に `null`」（null クリア）を区別する必要がある。

`JsonRequestBodyParser::parse()` が返す連想配列に対して、
`isset()` はどちらも `false` を返すため `array_key_exists()` を使う必要がある。

```php
$body   = JsonRequestBodyParser::parse($request);
$fields = [];

// isset() ではなく array_key_exists() — absent と explicit null を区別するため
if (array_key_exists('title', $body)) {
    $fields['title'] = $body['title'];
}
if (array_key_exists('is_read', $body)) {
    $fields['is_read'] = (bool) $body['is_read'];
}
```

NENE2 に PATCH 向けの部分更新ユーティリティはなく、このパターンを
consumer が毎回実装する必要がある。

**期待する解決策**: howto に「PATCH 部分更新パターン」を記載する。

---

### F-4（中）: `JsonRequestBodyParser` は JSON 配列 `[]` を拒否する

**状況**: `JsonRequestBodyParser::parse()` は JSON オブジェクト（`stdClass`）のみを受け付け、
JSON 配列（`[]`）を受け取ると `JsonBodyParseException`（→ 400）を投げる。

空の PATCH body を PHP の `[]` でエンコードすると `"[]"`（JSON 配列）になるため、
リクエストが 400 で拒否される。

```php
// 誤り: json_encode([]) === "[]" → 400 Bad Request
$response = $this->request('PATCH', "/entries/{$id}", []);

// 正しい: json_encode((object)[]) === "{}" → 200 OK
$response = $this->request('PATCH', "/entries/{$id}", new \stdClass());
```

PHP の空配列と空オブジェクトの JSON エンコード差異がこの問題の原因。
`JsonRequestBodyParser` の `stdClass` 検証は意図的（配列混入を防ぐ）だが、
エラーメッセージに「JSON オブジェクトが必要」と記載されないと consumer が気づきにくい。

---

## ページネーション検証

`PaginationResponse::toArray()` は `limit`・`offset`・`items`・`total`（optional）を返す。
`total` を含むことでクライアントが最終ページを計算できる。

```json
{
    "items": [...],
    "limit": 20,
    "offset": 0,
    "total": 42
}
```

`PaginationQueryParser` のバリデーション（`limit < 1` → 422、`offset < 0` → 422）は
期待どおり動作した。

---

## PATCH 動作確認

| テスト | 検証内容 |
|---|---|
| `testPatchMarkAsRead` | `is_read: true` を送ると更新される |
| `testPatchUpdateTitle` | `title` のみ更新、`is_read` は変わらない |
| `testPatchWithNoFieldsReturnsCurrentState` | 空オブジェクト `{}` → 200、現状を返す |
| `testPatchNonExistentEntryReturns404` | 存在しない ID → 404 |

---

## テストカバレッジ

| テスト | 検証内容 |
|---|---|
| `testListReturnsEmptyInitially` | GET /entries → 空・ページネーションメタ含む |
| `testCreateEntry` | POST /entries → 201 |
| `testGetEntryById` | GET /entries/{id} → 200 |
| `testDeleteEntry` | DELETE → 200、その後 404 |
| `testPaginationLimitAndOffset` | limit=2&offset=0 → 2 件・total=5 |
| `testPaginationSecondPage` | offset=2 → 重複なし |
| `testPaginationInvalidLimitReturns422` | limit=0 → 422 |
| `testPaginationInvalidOffsetReturns422` | offset=-1 → 422 |
| `testFilterByCategory` | ?category=tech → 該当のみ |
| `testFilterByIsRead` | ?is_read=false/true → 正しく分離 |
| `testFilterByCategoryAndIsRead` | 複合フィルタ |
| `testPatchMarkAsRead` | PATCH is_read |
| `testPatchUpdateTitle` | PATCH title |
| `testPatchWithNoFieldsReturnsCurrentState` | 空 `{}` → 現状返却 |
| `testPatchNonExistentEntryReturns404` | PATCH 404 |
| `testCreateRejectsMissingTitle` | 422 |
| `testCreateDefaultsCategoryToUncategorized` | category 省略 → uncategorized |
| `testGetNonExistentEntryReturns404` | 404 |
| `testDeleteNonExistentEntryReturns404` | 404 |

**合計**: 19/19 通過

---

## 総評

`PaginationQuery` / `PaginationResponse` は NENE2 v1.5.4 で正常に動作する。
`PaginationQueryParser` のバリデーション（422）と `PaginationResponse::toArray()` の
`total` フィールドはそのまま使えた。

主な摩擦はクエリパラメータ解析の分離（F-1・F-2）と PATCH 実装パターン（F-3・F-4）。
特に F-1 は「ページネーション付きフィルタリスト」というよくあるパターンで毎回発生し、
consumer の実装量が増える。

次のアクション候補:
1. F-1/F-2 → クエリパラメータ解析ユーティリティの検討（新 Issue）
2. F-3 → PATCH パターンを howto に追加（`add-domain-exception-handler.md` 同様の howto）
3. F-4 → `JsonRequestBodyParser` のエラーメッセージ改善または howto への注記
