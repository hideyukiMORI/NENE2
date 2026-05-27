# ハウツー: ページネーション境界 & リミットインジェクション

**FT177 — limitlog**

オフセットおよびカーソルベースのページネーションの堅牢な整数パラメーターバリデーション —
DB ダンプ、オーバーフロー、型の混乱、ReDoS を防ぎます。

---

## 攻撃面

すべてのページネーションエンドポイントは少なくとも 2 つの整数パラメーター（`limit`、`page` / `after`）を公開します。
攻撃者は日常的に以下のプローブを行います:

| 攻撃 | 例 | リスク |
|--------|---------|------|
| 過大な limit | `limit=999999` | フルテーブルダンプ |
| ゼロ/負の値 | `limit=0`、`limit=-1` | 負の OFFSET → DB エラーまたはラップ |
| Float インジェクション | `limit=10.5`、`limit=1e2` | サイレントキャスト: `(int)"10.5" === 10` |
| パディング/符号付き | `limit=+10`、`limit= 10` | サイレントトリム: `(int)" 10" === 10` |
| 整数オーバーフロー | `limit=99999999999999999999` | 64 ビットの負値へのラップ |
| 非数値 | `limit=abc`、`limit=1;DROP TABLE` | 型エラーまたはインジェクション |
| Hex / 8 進数 | `limit=0x10`、`limit=010` | `0x` → ctype 失敗; `010` は通過！ |
| 重複パラメーター | `?limit=5&limit=1000` | 最後の値が検証済みのものを上書き |
| ReDoS ペイロード | `limit=111...1x` | 指数的な正規表現バックトラッキング |

---

## `clampInt()` パターン

```php
/**
 * @param array<string, mixed> $params
 */
private function clampInt(array $params, string $key, ?int $default, int $min, int $max): ?int
{
    if (!array_key_exists($key, $params)) {
        return $default;  // 不在 → デフォルトを使用（null = 無効ではない）
    }

    $raw = $params[$key];

    // ctype_digit: O(n)、ReDoS 免疫、'' / '-' / '.' / '+' / ' ' / 'e' を拒否
    // ctype_digit('') === false → 空文字列は既に拒否される
    if (!is_string($raw) || !ctype_digit($raw)) {
        return null;  // シグナル: 呼び出し元は 422 を返す必要がある
    }

    // PHP のサイレントオーバーフローを防ぐ: (int)"99999999999999999999" がラップする
    if (strlen($raw) > 18) {
        return null;
    }

    $value = (int) $raw;

    if ($value < $min || $value > $max) {
        return null;
    }

    return $value;
}
```

### なぜ正規表現ではなく `ctype_digit` なのか

| バリデーター | ReDoS セーフ? | `010` を拒否? | `+10` を拒否? |
|-----------|------------|----------------|----------------|
| `/^\d+$/` | ❌ `111...1x` で指数的 | ✅ | ❌ |
| `ctype_digit()` | ✅ O(n) | ✅（`0` プレフィックス: 通過 — ただし範囲でキャップ） | ✅ |
| `is_numeric()` | ✅ | ❌ | ❌ |
| `filter_var(FILTER_VALIDATE_INT)` | ✅ | ✅ | ❌（`+10` は通過！） |

**`ctype_digit()` を使ってください** — 最も厳密で高速です。

### `010` の落とし穴

`ctype_digit('010')` → `true`（桁チェックを通過）、`(int)'010'` → `10`（10 進数、8 進数ではない）。
これは PHP が（PHP リテラルとしての `010` とは異なり）文字列キャストされた整数に 8 進数解釈を実行しないため安全です。チームが不確かな場合はテストで確認してください。

---

## カーソルベースのページネーション

```php
// has_more を判定するために 1 件余分に取得する — COUNT クエリ不要
$rows = $this->db->fetchAll(
    'SELECT * FROM articles WHERE id < ? ORDER BY id DESC LIMIT ?',
    [$afterId, $limit + 1],
);

$hasMore = count($rows) > $limit;
if ($hasMore) {
    array_pop($rows);  // センチネルを削除する
}

$nextCursor = $hasMore && count($rows) > 0 ? end($rows)->id : null;
```

### 「最初のページ」のカーソルセンチネル

```php
private const int NO_CURSOR = PHP_INT_MAX;

// GET /articles/cursor (?after パラメーターなし) → afterId がデフォルトで PHP_INT_MAX
// WHERE id < PHP_INT_MAX  ==>  実質的にすべての行
```

---

## オフセットページネーション — ページゼロガード

`page=0` は `OFFSET = (0-1) * limit = -limit` を生成します — 負の OFFSET は
一部のデータベース（MySQL は拒否する）では SQL エラーになるか、他では静かにラップします。

```php
$page  = $this->clampInt($params, 'page', 1, 1, PHP_INT_MAX);
// min=1 → page=0 が null を返す → 422
```

---

## 整数オーバーフローガード

PHP の `(int)` キャストは 20 桁の文字列をサイレントにラップします:

```php
(int)'99999999999999999999'  // === 64 ビット PHP で -1
```

`strlen($raw) > 18` ガードはキャストの前にこれを防ぎます。18 桁は
`PHP_INT_MAX`（19 桁）を余裕を持ってカバーするため、キャストは常に安全です。

---

## VULN-A から VULN-L のチェックリスト

| # | テスト | 期待値 |
|---|------|-------------|
| VULN-A | `limit` が MAX（100）を超える | 422 — 明示的な拒否、サイレントな切り詰めではない |
| VULN-B | `limit=0`、`limit=-1` | 422 — `0` は min=1 で失敗; `-` は ctype_digit で失敗 |
| VULN-C | Float 文字列 `10.5`、`1e2`、`1.0` | 422 — `.` と `e` は ctype_digit で失敗 |
| VULN-D | パディング `%2010`、`10%20`、`%2B10` | 422 — スペース/`+` は ctype_digit で失敗 |
| VULN-E | オーバーフロー `9999...`（20 桁） | 422 — strlen > 18 ガード |
| VULN-F | 非数値、hex `0x10`、SQL インジェクション | 422 — ctype_digit がすべて拒否 |
| VULN-G | `page=0`（オフセットページネーション） | 422 — min=1 ガード |
| VULN-H | カーソル境界: `after=0` は有効、オーバーフローカーソルは 422 | 混合 |
| VULN-I | `author_id=0`、`-1`、`abc`、`1.5` | 422 |
| VULN-J | 非常に大きなページ（page=999999） | 200 空 — クラッシュしてはならない |
| VULN-K | 重複パラメーター `?limit=5&limit=1000` | 200（安全）または 422 — MAX を超えない |
| VULN-L | ReDoS ペイロード `111...1x`（50 桁 + x） | 100ms 以内に 422 |

---

## テストノート: VULN-J vs VULN-A

これらは矛盾して見えますが、異なる目標を持ちます:

- **VULN-A**: `limit=999999` → **422** — 不合理に大きな行数を拒否
- **VULN-J**: `page=999999&limit=10` → **200 空** — データがたまたまない有効なページ

サーバーは意味的には有効だが実際には空のページでクラッシュやエラーを起こしてはなりません。
`OFFSET = (999999-1) * 10 = 9999980` は正当な SQL OFFSET です; 結果は単純に空です。
