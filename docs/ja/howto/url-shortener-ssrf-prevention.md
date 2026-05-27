# ハウツー: SSRF 防止付き URL 短縮サービス

> **FT リファレンス**: FT337 (`NENE2-FT/shortlog`) — SSRF ブロック（プライベート IP、ループバック、リンクローカル、危険なスキーム）、スラグバリデーション、マスアサインメント防止、ISO 8601 日付バリデーション、ReDoS 安全なリミット解析を持つ URL 短縮サービス、50+ テスト PASS。

このガイドでは、安全なパブリック URL のみを受け入れ、スラグを検証し、マスアサインメントを防止し、Server-Side Request Forgery（SSRF）から保護する URL 短縮サービスの構築方法を説明します。

## スキーマ

```sql
CREATE TABLE links (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id      INTEGER NOT NULL,
    slug         TEXT    NOT NULL UNIQUE,
    original_url TEXT    NOT NULL,
    expires_at   TEXT,               -- ISO 8601、nullable
    click_count  INTEGER NOT NULL DEFAULT 0,
    created_at   TEXT    NOT NULL
);
```

## エンドポイント

| メソッド | パス | 説明 |
|--------|------|------|
| `POST`   | `/links`        | 短縮リンクを作成する          |
| `GET`    | `/links`        | 自分のリンクを一覧表示する    |
| `GET`    | `/links/{slug}` | スラグでリンクを取得する      |
| `DELETE` | `/links/{slug}` | 自分のリンクを削除する        |

## 短縮リンクの作成

```php
POST /links
X-User-Id: 1
{
  "original_url": "https://example.com/very/long/path",
  "slug": "my-link",
  "expires_at": "2030-12-31T23:59:59+09:00"
}
→ 201
{
  "id": 1,
  "user_id": 1,
  "slug": "my-link",
  "original_url": "https://example.com/very/long/path",
  "expires_at": "2030-12-31T23:59:59+09:00",
  "click_count": 0,
  "created_at": "..."
}
```

`slug` はオプションです — 省略された場合は自動生成されます（`[a-z0-9_-]+`）。

### 認証なし

```php
POST /links  (X-User-Id ヘッダーなし)
→ 401
```

### 重複スラグ

```php
POST /links  {"slug": "my-link"}  // すでに存在する
→ 409
```

## スラグバリデーション

```
有効: 小文字、数字、ハイフン、アンダースコア
長さ: 3〜20 文字

有効な例: "abc", "my-link", "link123", "test-link-01"
```

```php
POST /links  {"slug": "ab"}          → 422  // 短すぎる（最小 3）
POST /links  {"slug": "a".repeat(21)} → 422  // 長すぎる（最大 20）
POST /links  {"slug": "MySlug"}       → 422  // 大文字は不可
POST /links  {"slug": "sl@g!"}        → 422  // 特殊文字
POST /links  {"slug": "my slug"}      → 422  // スペースは不可
POST /links  {"slug": 42}             → 422  // 型は文字列でなければならない（VULN-B）
```

## URL バリデーション

```php
POST /links  {"original_url": ""}              → 422  // 空
POST /links  {}                                → 422  // 欠落
POST /links  {"original_url": 42}              → 422  // 文字列でない（VULN-B）
POST /links  {"original_url": true}            → 422  // 真偽値（VULN-B）
POST /links  {"original_url": null}            → 422  // null（VULN-B）
POST /links  {"original_url": "https://..."+"x".repeat(2030)}  → 422  // 長すぎる
```

## SSRF 防止

サーバーが内部インフラを呼び出す URL をブロックします:

### ブロックされたスキーム

```php
POST /links  {"original_url": "javascript:alert(1)"}  → 422
POST /links  {"original_url": "file:///etc/passwd"}   → 422
POST /links  {"original_url": "ftp://example.com/"}   → 422
```

`http://` と `https://` のみが許可されます。

### ブロックされた IP レンジ

```php
// ループバック
POST /links  {"original_url": "http://127.0.0.1/admin"}     → 422
POST /links  {"original_url": "http://localhost/secret"}     → 422
POST /links  {"original_url": "http://internal.localhost/"}  → 422  // *.localhost

// RFC 1918 プライベートレンジ
POST /links  {"original_url": "http://10.0.0.1/metadata"}    → 422
POST /links  {"original_url": "http://192.168.1.1/router"}   → 422
POST /links  {"original_url": "http://172.16.0.1/internal"}  → 422

// リンクローカル（AWS メタデータ等）
POST /links  {"original_url": "http://169.254.169.254/latest/meta-data/"}  → 422

// パブリック IP — 受け入れられる
POST /links  {"original_url": "https://8.8.8.8/"}            → 201  ✅
```

### DNS リバインディング防止

プライベート IP に解決されるホスト名もブロックされます:

```php
// "private.internal" が 10.0.0.1 に解決される → ブロック
POST /links  {"original_url": "http://private.internal/data"}  → 422

// "public.example.com" が 93.184.216.34 に解決される → 許可
POST /links  {"original_url": "https://public.example.com/page"}  → 201  ✅
```

### 実装

```php
private const BLOCKED_RANGES = [
    '127.',          // ループバック
    '10.',           // RFC 1918
    '172.16.', '172.17.', '172.18.', '172.19.',
    '172.20.', '172.21.', '172.22.', '172.23.',
    '172.24.', '172.25.', '172.26.', '172.27.',
    '172.28.', '172.29.', '172.30.', '172.31.',  // RFC 1918
    '192.168.',      // RFC 1918
    '169.254.',      // リンクローカル
];

private const ALLOWED_SCHEMES = ['http', 'https'];

public function validate(string $url): bool
{
    $parsed = parse_url($url);
    if (!$parsed || !in_array($parsed['scheme'] ?? '', self::ALLOWED_SCHEMES, true)) {
        return false;
    }

    $host = $parsed['host'] ?? '';

    // *.localhost をブロック
    if ($host === 'localhost' || str_ends_with($host, '.localhost')) {
        return false;
    }

    // ホスト名を IP に解決
    $ip = ($this->dnsResolver)($host);

    foreach (self::BLOCKED_RANGES as $prefix) {
        if (str_starts_with($ip, $prefix)) {
            return false;
        }
    }

    return true;
}
```

## マスアサインメント防止

```php
// 攻撃者が click_count または created_at を設定しようとする
POST /links
{
  "original_url": "https://example.com",
  "slug": "attack",
  "click_count": 999999,
  "created_at": "2000-01-01T00:00:00+00:00"
}
→ 201  {"click_count": 0, "created_at": "2026-..."}  // 無視されたフィールド
```

リクエストボディから `original_url`、`slug`、`expires_at` のみをホワイトリストに含めてください。ボディから `click_count`、`created_at`、`user_id` を決して読み取らないでください。

## ISO 8601 日付バリデーション

```php
// 無効なカレンダー日付
POST /links  {"expires_at": "2024-02-30T00:00:00+00:00"}  → 422  // 2月30日
POST /links  {"expires_at": "2024-13-01T00:00:00+00:00"}  → 422  // 13月
POST /links  {"expires_at": "2030-06-01T00:00:00+25:00"}  → 422  // +25:00 オフセット

// 有効
POST /links  {"expires_at": "2030-06-01T00:00:00+09:00"}  → 201  ✅
```

バリデーションパターン: `DateTimeImmutable::createFromFormat()` で解析し、ラウンドトリップを確認します:

```php
$dt = DateTimeImmutable::createFromFormat(DATE_RFC3339, $value);
if ($dt === false) return false;
// ラウンドトリップチェックは PHP が "2024-03-01" に正規化する "2024-02-30" をキャッチする
return $dt->format(DATE_RFC3339) === $value;
```

## ReDoS 安全なリミットバリデーション

```php
// O(n) の ctype_digit — ReDoS 免疫
GET /links?limit=10       → 200  ✅
GET /links?limit=999999   → 422  // MAX_LIMIT 超過
GET /links?limit=9...9 (19 桁)  → 422  // オーバーフローガード
GET /links?limit=111...1x (x を含む 51 文字)  → 422、<100ms  // ReDoS ペイロード
```

## IDOR 防止

```php
// ユーザー 2 がユーザー 1 のリンクを削除しようとする
DELETE /links/user1-link
X-User-Id: 2
→ 404  // 403 ではない — 列挙を防ぐ
```

リンクは存在しますが、検索は `WHERE slug = ? AND user_id = ?` にスコープされます。不一致は 404 を返し、リンクが存在しないかのように見えます。

---

## やってはいけないこと

| アンチパターン | リスク |
|---|---|
| `http://localhost` または `http://127.0.0.1` を許可する | サーバーが短縮リンク経由で自身の管理エンドポイントを取得する |
| DNS 解決チェックをスキップする | 攻撃者が `evil.example.com` を登録し、A レコードに `10.0.0.1` を設定して IP リテラルチェックをバイパスする |
| `javascript:` スキームを許可する | 短縮リンクを開くブラウザーで XSS |
| `file://` スキームを許可する | 短縮サービスが作成時に URL を取得する場合、サーバーが `/etc/passwd` を読む |
| リクエストボディから `click_count` を受け入れる | 攻撃者がクリックメトリクスを水増しする |
| スラグの長さ/文字セット制限がない | `slug = "' OR 1=1--"` がバリデーションを通過して SQL に到達する |
| リミットバリデーションに正規表現 `/^\d+$/` を使用する | 長い混合数字ペイロードで ReDoS |
| リクエストボディから `created_at` を返す | 時刻偽造が監査証跡を破壊する |
