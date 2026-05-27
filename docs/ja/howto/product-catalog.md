# ハウツー: 商品カタログ API（ATK-01〜12）

このガイドでは、管理者専用の書き込み操作、キーワード検索、ソフトデリートを備えた商品カタログ API を実演し、ATK-01〜12 のクラッカー攻撃ベクターを網羅します。

## パターン概要

- カタログの読み取りは公開; 書き込み（作成、削除）には管理者（`X-Admin-Key`）が必要です。
- SKU は大文字英数字とハイフン（`/\A[A-Z0-9\-]{1,32}\z/`）のみです。
- ソフトデリート（`active = 0`）は履歴を失わずに商品を非表示にします。
- キーワード検索はキーワード爆弾を防ぐために長さガード付きの `LIKE` を使用します。

## スキーマ

```sql
CREATE TABLE IF NOT EXISTS products (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    sku         TEXT    NOT NULL UNIQUE,
    name        TEXT    NOT NULL,
    description TEXT    NOT NULL DEFAULT '',
    price_cents INTEGER NOT NULL,
    active      INTEGER NOT NULL DEFAULT 1,
    created_at  TEXT    NOT NULL
);
```

## ATK-01: 検索キーワードへの SQL インジェクション

```php
$kw   = '%' . $keyword . '%';
$stmt = $this->pdo->prepare(
    'SELECT * FROM products WHERE active = 1 AND (name LIKE :kw OR ...) LIMIT :lim OFFSET :off'
);
$stmt->bindValue(':kw', $kw, PDO::PARAM_STR);
```

`%` ワイルドカードはパラメーター化クエリに渡されるリテラル値の一部です — 補間は発生しません。

## ATK-02: 管理者フェイルクローズド

```php
private function isAdmin(ServerRequestInterface $req): bool
{
    if ($this->adminKey === '') {
        return false;
    }
    return hash_equals($this->adminKey, $req->getHeaderLine('X-Admin-Key'));
}
```

管理者キーが空 → 常に 403。誤ったキー → `hash_equals()` でタイミング漏洩を回避します。

## ATK-03: 商品 ID の整数オーバーフロー

```php
if (!ctype_digit($raw) || strlen($raw) > 18) {
    return null;  // → 404
}
```

20 桁の ID 文字列は 18 文字を超えており、`(int)` キャストや DB クエリの前に拒否されます。

## ATK-04: 負の ID

`-1` に対する `ctype_digit()` は失敗します（数字以外の文字）→ 404。

## ATK-05: 浮動小数点価格

```php
if (!is_int($priceCents) || $priceCents < 0) {
    return $this->problem(422, ...);
}
```

`is_int(9.99)` は `false` を返します — 浮動小数点価格は拒否されます。

## ATK-06: SKU インジェクション

SKU 正規表現 `/\A[A-Z0-9\-]{1,32}\z/` は `; DROP TABLE`、クォート、スペース、小文字を拒否します。正確なフォーマットのみ受け付けられます。

## ATK-07: ワイルドカード検索インジェクション

検索キーワード内の `%` は SQL LIKE ワイルドカードとして扱われ、すべてにマッチします。これは意図的なものです（ユーザーはすべてを検索できる）。LIKE はパラメーター化されているため、`%; DROP TABLE products; --` は SQL として実行されません:

```sql
WHERE name LIKE '%%; DROP TABLE products; --%'
```

結果は単に広い LIKE マッチであり、インジェクションではありません。

## ATK-08: 二重削除

リポジトリの `delete()` はまず `findById()`（active=1 のみ）をチェックします。ソフトデリート済みの商品は null を返す → 2 回目の削除で 404。

## ATK-09: SKU が長すぎる

正規表現の量指定子 `{1,32}` は DB に到達する前に 32 文字より長い SKU を拒否します。

## ATK-10: 誤った管理者キー

`hash_equals()` の比較は何文字一致するかに関わらず常に同じ時間がかかります。

## キーワード長ガード

```php
if ($keyword !== null && strlen($keyword) > 100) {
    return $this->problem(422, 'validation-failed', 'q too long (max 100).');
}
```

10 MB の LIKE パターンをデータベースに送信することを防ぎます。

## ソフトデリート

```php
$this->pdo->prepare('UPDATE products SET active = 0 WHERE id = :id')->execute([':id' => $id]);
```

すべての読み取りに `WHERE active = 1` が含まれます。削除された商品は物理的な削除なしに非表示になります。

## ルート

```
POST   /products      商品を作成する（管理者のみ）
GET    /products      商品を一覧/検索する（公開）
GET    /products/{id} 商品を取得する（公開）
DELETE /products/{id} 商品をソフトデリートする（管理者のみ）
```

## 関連情報

- FT212 ソース: `../NENE2-FT/productlog/`
- 関連: `docs/howto/inventory-management.md`（FT203、SKU ベースの在庫）
- 関連: `docs/howto/session-token-management.md`（FT208、ATK も実施）
