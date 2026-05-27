# ハウツー: オフセット & カーソルページネーション

> **FT リファレンス**: FT325 (`NENE2-FT/pagelog`) — デュアルページネーション戦略（オフセットベースとカーソルベース）、`next_offset`/`next_cursor`、`has_more`、カテゴリフィルター、15 テスト / 47 アサーション PASS。

このガイドでは同一リソースにオフセットベースとカーソルベースの両方のページネーションエンドポイントを実装する方法を解説します。クライアントはユースケースに合った戦略を選択できます。

## スキーマ

```sql
CREATE TABLE articles (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    title      TEXT    NOT NULL,
    author     TEXT    NOT NULL,
    category   TEXT    NOT NULL DEFAULT 'general',
    created_at TEXT    NOT NULL
);
```

## エンドポイント

| メソッド | パス | 説明 |
|--------|------|-------------|
| `POST` | `/articles` | 記事を作成する |
| `GET`  | `/articles/offset` | オフセットページネーション |
| `GET`  | `/articles/cursor` | カーソルページネーション |
| `GET`  | `/articles/by-category` | カテゴリフィルター |

## オフセットページネーション

```
GET /articles/offset?limit=10&offset=0
→ 200
{
  "items": [...],     // 10 件
  "total": 25,
  "limit": 10,
  "offset": 0,
  "has_more": true,
  "next_offset": 10   // 最終ページでは null
}

// ページ 2
GET /articles/offset?limit=10&offset=10
→ {"items": [...], "has_more": true, "next_offset": 20}

// 最終ページ
GET /articles/offset?limit=10&offset=20
→ {"items": [...], "has_more": false, "next_offset": null}

// 範囲外
GET /articles/offset?limit=10&offset=100
→ {"items": [], "has_more": false}
```

`has_more` の場合は `next_offset = offset + limit`、そうでない場合は `null`。

## カーソルページネーション

```
GET /articles/cursor?limit=10
→ 200
{
  "items": [...],        // 新しい順
  "has_more": true,
  "next_cursor": 15      // 最後に返されたアイテムの id
}

// カーソルを使った次のページ
GET /articles/cursor?limit=10&after=15
→ {"items": [...], "has_more": true, "next_cursor": 5}

// 最終ページ
GET /articles/cursor?limit=10&after=5
→ {"items": [...], "has_more": false, "next_cursor": null}
```

カーソルは最後に返されたアイテムの `id` です: `WHERE id < $after ORDER BY id DESC LIMIT $limit + 1`（`has_more` を判定するために 1 件余分に取得します）。

## カテゴリフィルター

```
GET /articles/by-category?category=tech&limit=5
→ {"items": [...], "total": N}
```

## オフセット vs カーソル — 使い分け

| 基準 | オフセット | カーソル |
|-----------|--------|--------|
| ランダムなページジャンプ | ✅ `?offset=50` | ❌ 順に辿る必要あり |
| 合計件数が必要 | ✅ 常に含まれる | ❌ コスト高 |
| 挿入時の一貫した結果 | ❌ 新行がページをずらす | ✅ 安定 |
| 大規模データセットのパフォーマンス | ❌ `OFFSET N` で N 行スキャン | ✅ `WHERE id < X` でインデックス使用 |
| 無限スクロール / フィード | ❌ | ✅ |

---

## してはいけないこと

| アンチパターン | リスク |
|---|---|
| 最終ページでも `next_offset` を返す | クライアントが余分な空のリクエストをする |
| 数百万行のテーブルで `OFFSET N` を使う | DB が結果を返す前に N 行スキャンする。大規模データにはカーソルを使う |
| カーソルレスポンスから `has_more` を省略する | クライアントが次のページを取得するかどうか判断できない |
| タイムスタンプをカーソルとして使う | 重複したタイムスタンプにより行がスキップまたは重複する。ユニークな整数 id を使う |
