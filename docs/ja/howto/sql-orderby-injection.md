# SQL ORDER BY インジェクションの防止方法

SQL の `ORDER BY` 句は標準プレースホルダー（`?`）でパラメーター化できません。つまり、ユーザーが制御するソートカラムと方向は SQL に直接補間してはいけません。
このガイドでは唯一の安全なアプローチを解説します: 明示的な allowlist。

---

## 問題

プリペアドステートメントのプレースホルダーは `WHERE` 句のカラム値を保護しますが、`ORDER BY` のカラム名やソート方向には**機能しません**:

```php
// ❌ 間違い — これはインジェクションから保護しない
$stmt = $pdo->prepare("SELECT * FROM articles ORDER BY ? ?");
$stmt->execute([$column, $direction]);
// 多くのデータベースドライバーは ORDER BY 引数を識別子ではなくリテラルとして扱う。
```

`?sort=SLEEP(5)` や `?sort=(SELECT password FROM users LIMIT 1)` を送信した攻撃者は、タイムベース攻撃、情報漏洩、またはスキーマ詳細を明かすエラーを引き起こす可能性があります。

---

## 唯一の安全な解決策: 明示的な allowlist

```php
// ✅ 安全 — allowlist + in_array 厳密チェック
public const array SORT_COLUMNS = ['id', 'title', 'status', 'created_at'];
public const array SORT_DIRS    = ['asc', 'desc'];

$sql = "SELECT * FROM articles ORDER BY {$sortCol} {$sortDir} LIMIT ?";
```

allowlist の値はあなたが制御する**ハードコードされた文字列**です。それらの値のみが SQL に到達します。

---

## 完全なルートハンドラーパターン

```php
// ── ソートカラム — allowlist に対して検証しなければならない ──────────────────
//
// セキュリティ: ORDER BY は標準 SQL で ? プレースホルダーをサポートしない。
// 唯一の安全なアプローチは in_array 厳密チェックで確認された明示的な allowlist。
//
$rawSort = $params['sort'] ?? null;

if ($rawSort !== null) {
    // 配列インジェクション: PSR-7 は ?sort[]=id に対して配列を渡す可能性がある
    if (!is_string($rawSort)) {
        return $this->responseFactory->create(['error' => 'sort must be a string.'], 422);
    }

    // ヌルバイトチェック — PSR-7 は %00 を実際のヌルバイトにデコードする
    if (str_contains($rawSort, "\0")) {
        return $this->responseFactory->create(['error' => 'sort contains invalid characters.'], 422);
    }

    // allowlist チェック — 厳密、大文字小文字区別。
    // PSR-7 はクエリ文字列を一度 URL デコードする（%65 → e）ため、単一エンコードされた有効な
    // カラム名は受け入れられる。二重エンコードされた値（%2565 → $rawSort の %65）は
    // 2 度目にデコードされないため、allowlist に失敗して拒否される。
    if (!in_array($rawSort, MyRepository::SORT_COLUMNS, true)) {
        return $this->responseFactory->create(
            ['error' => sprintf('sort must be one of: %s.', implode(', ', MyRepository::SORT_COLUMNS))],
            422,
        );
    }

    $sortCol = $rawSort;
} else {
    $sortCol = 'created_at';  // 安全なデフォルト
}

// ── ソート方向 — allowlist のみ ───────────────────────────────────────────────
$rawOrder = $params['order'] ?? null;

if ($rawOrder !== null) {
    if (!is_string($rawOrder)) {
        return $this->responseFactory->create(['error' => 'order must be a string.'], 422);
    }

    $dir = strtolower(trim($rawOrder));

    if (!in_array($dir, MyRepository::SORT_DIRS, true)) {
        return $this->responseFactory->create(
            ['error' => sprintf('order must be one of: %s.', implode(', ', MyRepository::SORT_DIRS))],
            422,
        );
    }

    $sortDir = $dir;
} else {
    $sortDir = 'desc';  // 安全なデフォルト
}
```

---

## リポジトリレイヤー

リポジトリはすでに検証済みの値を受け取り、直接補間します:

```php
/**
 * $sortCol と $sortDir は呼び出し元が allowlist で検証しなければならない。
 * このメソッドはそれらを信頼し、直接 SQL に補間する。
 *
 * @return array{data: list<Article>, total: int, sort: string, order: string, limit: int}
 */
public function list(string $sortCol, string $sortDir, ?ArticleStatus $status, int $limit): array
{
    $where  = $status !== null ? 'WHERE status = ?' : '';
    $params = $status !== null ? [$status->value] : [];

    // $sortCol と $sortDir は事前検証済み — 補間しても安全。
    // ここに生のユーザー入力を入れないこと。
    $sql  = "SELECT * FROM articles {$where} ORDER BY {$sortCol} {$sortDir} LIMIT ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([...$params, $limit]);
    ...
}
```

---

## このアプローチでブロックされる攻撃パターン

| 攻撃 | 入力 | 結果 |
|---|---|---|
| DROP TABLE インジェクション | `?sort='; DROP TABLE articles--` | 422 — allowlist にない |
| UNION SELECT データ漏洩 | `?sort=1; SELECT password` | 422 — allowlist にない |
| サブクエリ抽出 | `?sort=(SELECT name FROM sqlite_master)` | 422 — allowlist にない |
| タイムベースブラインド | `?sort=SLEEP(5)` | 422 — allowlist にない |
| カラムインデックスインジェクション | `?sort=1` | 422 — allowlist にない |
| 未知のカラム | `?sort=password` | 422 — allowlist にない |
| 大文字/コメントバイパス | `?sort=CREATED_AT--` | 422 — 大文字小文字区別 |
| ヌルバイトバイパス | `?sort=created_at%00` | 422 — ヌルバイトチェック |
| 配列インジェクション | `?sort[]=created_at` | 422 — 型チェック |
| 二重 URL エンコード | `?sort=cr%2565ated_at` | 422 — PSR-7 は一度デコード; `cr%65ated_at` は allowlist にない |
| 単一 URL エンコード（有効） | `?sort=cr%65ated_at` | 200 — PSR-7 が `created_at` にデコード ✓ |
| 方向インジェクション | `?order=asc; UNION SELECT 1--` | 422 — allowlist にない |

---

## 重要なポイント

1. **PSR-7 の後に `rawurldecode()` しない**: PSR-7 の `getQueryParams()` はすでにクエリ文字列を一度デコードします。再度 `rawurldecode()` を呼び出すと二重エンコードされた値が allowlist チェックを通り抜けることがあります。

2. **`in_array($value, $allowlist, true)`**: 3 番目の引数 `true` は厳密な（型安全な）比較を有効にします。なしだと、PHP が文字列を整数に強制するため `in_array(0, ['id', 'created_at'])` は `true` を返します。

3. **大文字小文字区別チェック**: カラム名は小文字であり正確にマッチされるべきです。allowlist チェックの前に `strcasecmp` や `strtolower` を使わないでください — `CREATED_AT` は信頼の観点から `created_at` と同じトークンではありません。

4. **方向: `strtolower(trim())` は安全**: カラム名とは異なり、方向（`asc`/`desc`）は有効な値が 2 つだけです。allowlist 自体が exhaustive で小文字のため、allowlist チェックの前にケースを正規化することは許容されます。

5. **契約を文書化する**: リポジトリメソッドはその入力を信頼することを文書化しなければなりません。呼び出し元は生のユーザー入力を渡してはいけません。

---

## 関連

- FT180 — sortlog: SQL ORDER BY インジェクション & 動的ソート/フィルター防止
- [RFC 3986](https://www.rfc-editor.org/rfc/rfc3986) — URI エンコーディング
- [PSR-7](https://www.php-fig.org/psr/psr-7/) — `ServerRequestInterface::getQueryParams()`
