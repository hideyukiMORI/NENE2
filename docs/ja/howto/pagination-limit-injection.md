# ハウツー: ページネーション境界 & リミットインジェクション防止

> **FT リファレンス**: FT319 (`NENE2-FT/limitlog`) — 厳密な limit/page バリデーション、MAX_LIMIT キャップ強制、ReDoS セーフ ctype_digit バリデーションを持つオフセットおよびカーソルページネーション、20 テスト / 384 アサーション PASS。

このガイドでは、整数境界攻撃とリミットインジェクションを防ぎながら、オフセットとカーソルの両戦略で安全なページネーションを実装する方法を解説します。

## 定数

```php
const DEFAULT_LIMIT = 20;
const MAX_LIMIT     = 100;
```

## オフセットページネーション

```php
GET /articles?page=1&limit=10
→ 200
{
  "data": [...],      // 10 件
  "total": 25,
  "limit": 10,
  "page": 1,
  "has_more": true
}
```

```php
// 25 件で limit=10 の 3 ページ目 → 最終ページ
GET /articles?page=3&limit=10
→ 200  {"data": [...], "has_more": false}  // 5 件
```

**OFFSET 計算**: `(page - 1) * limit` — 負の OFFSET を防ぐために page は ≥ 1 でなければなりません。

## カーソルページネーション

```php
GET /articles/cursor?limit=5
→ 200  {"data": [...], "next_cursor": 42, "has_more": true}

GET /articles/cursor?after=42&limit=5
→ 200  {"data": [...], "next_cursor": 37, "has_more": true}

GET /articles/cursor?after=37&limit=5
→ 200  {"data": [...], "next_cursor": null, "has_more": false}
```

カーソルは最後のアイテムの `id` です: `WHERE id < $after ORDER BY id DESC LIMIT $limit`。

## 著者フィルター

```php
GET /articles/by-author?author_id=2&limit=10
→ 200  {"data": [...]}  // author_id = 2 のアイテムのみ
```

`author_id` は正の整数でなければなりません（`limit` と同じバリデーション）。

## リミットバリデーション — `ctype_digit` パターン

O(n) バリデーションに `ctype_digit()` を使ってください — 正規表現 `^\d+$` と違い ReDoS に免疫があります:

```php
/**
 * クエリ文字列の整数パラメーターをパースします。
 * 拒否: ゼロ、負の値、float、オーバーフロー、非数値、空白。
 */
function parseQueryInt(string $raw, int $min, int $max): int
{
    // 空、float、符号、空白、非数字文字を拒否
    if ($raw === '' || !ctype_digit($raw)) {
        throw new ValidationException(/* 422 */);
    }
    // キャスト前に 64 ビットオーバーフローをガード
    if (strlen($raw) > 18) {
        throw new ValidationException(/* 422 */);
    }
    $val = (int) $raw;
    if ($val < $min || $val > $max) {
        throw new ValidationException(/* 422 */);
    }
    return $val;
}
```

### `ctype_digit` がブロックするもの

| 入力 | `ctype_digit` | 理由 |
|-------|--------------|-----|
| `"10"` | ✅ Pass | 有効な桁 |
| `"0"` | ✅ Pass（ctype） | min=1 チェックで拒否 |
| `"-1"` | ❌ 拒否 | `-` は桁ではない |
| `"10.5"` | ❌ 拒否 | `.` は桁ではない |
| `"1e2"` | ❌ 拒否 | `e` は桁ではない |
| `"+10"` | ❌ 拒否 | `+` は桁ではない |
| `" 10"` | ❌ 拒否 | スペースは桁ではない |
| `"0x10"` | ❌ 拒否 | `x` は桁ではない |
| `"10\x00"` | ❌ 拒否 | ヌルバイトは桁ではない |
| 20 桁の文字列 | ❌ 拒否 | strlen > 18 ガード |
| ReDoS ペイロード `"1...1x"` | ❌ 拒否（高速） | O(n) スキャン、バックトラッキングなし |

### エラーケース

```php
GET /articles?limit=999999  → 422  // MAX_LIMIT を超える
GET /articles?limit=0       → 422  // min=1
GET /articles?limit=-1      → 422  // ctype_digit ではない
GET /articles?limit=10.5    → 422  // float
GET /articles?limit=abc     → 422  // 非数値
GET /articles?page=0        → 422  // 負の OFFSET
GET /articles/cursor?after=99999999999999999999  → 422  // オーバーフロー
```

## 重複パラメーター攻撃

```php
GET /articles?limit=5&limit=1000
// PHP は最後の値を取る: 1000 → MAX_LIMIT を超える → 422
```

ほとんどの PSR-7 実装は最後の出現を取ります。422（最後の値が MAX を超える）または有効な値の 200 は許容されます — 1000 をサイレントに使うことは絶対にしません。

## 大きなページ番号

```php
GET /articles?page=999999&limit=10
→ 200  {"data": [], "has_more": false}  // 空、クラッシュではない
```

合計件数を超える巨大なページは有効です — エラーではなく空のデータを返します。

---

## してはいけないこと

| アンチパターン | リスク |
|---|---|
| `ctype_digit` なしの `(int) $raw` | `-1`、`1.5`、`" 10"` がすべてサイレントに整数にキャストされる |
| 整数バリデーションに正規表現 `/^\d+$/` | 長い混合入力での壊滅的なバックトラッキング（ReDoS） |
| MAX_LIMIT キャップなし | `limit=999999` がテーブル全体を 1 リクエストでダンプする |
| `page=0` を許可する | `OFFSET = (0-1)*limit = -limit` が SQL クエリを破壊またはエラーにする |
| strlen のみのオーバーフローガード | `"1.5"` は 3 文字 — 短すぎて通過するが有効な整数ではない |
| `author_id` の最小チェックなし | `author_id=0` がサイレントに空の結果を返す; 意味的に無効 |
