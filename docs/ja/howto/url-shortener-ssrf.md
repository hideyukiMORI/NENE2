# URL 短縮 API と SSRF 防止

**FT183** — `shortlog` フィールドトライアル（脆弱性診断 VULN-A〜L）。

URL 短縮サービスはユーザーがリダイレクトターゲットとして任意の URL を送信できます。バリデーションなしでリダイレクトがサーバーサイドでフォローされる場合（例: リンクプレビューやアナリティクス）、攻撃者はそれを内部サービスに向けることができます — これが **Server-Side Request Forgery（SSRF）** 攻撃です。

このガイドでは、shortlog 実装に対して実行された完全な VULN-A〜L セキュリティ監査と共に SSRF 防止を説明します。

---

## SSRF: コアリスク

URL 短縮サービスは攻撃者が制御する URL を保存し、潜在的に取得します。SSRF により攻撃者は:

- 内部サービスに到達: `http://10.0.0.1/admin`、`http://192.168.1.1/`
- クラウドメタデータを取得: `http://169.254.169.254/latest/meta-data/`（AWS IMDS）
- ローカルファイルを読む: `file:///etc/passwd`
- ブラウザスクリプトを実行: `javascript:alert(1)`
- ループバックサービスにアクセス: `http://127.0.0.1:8080/`

**修正:** URL のスキーム_と_保存前の宛先 IP を検証してください。

---

## URL バリデーション戦略（VULN-K）

### ステップ 1 — スキーム allowlist

`filter_var($url, FILTER_VALIDATE_URL)` だけでは**不十分**です — `javascript:alert(1)` や `ftp://` を有効な URL として受け入れます。`parse_url()` と明示的なスキーム allowlist を使用してください:

```php
$parts = parse_url($url);

if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
    return false;   // 不正な URL — スキームまたはホストなし
}

if (!in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
    return false;   // 拒否: javascript:, file://, ftp://, data: 等
}
```

`parse_url()` は正規表現ではありません — ReDoS 悪用できません（VULN-F）。

### ステップ 2 — ホスト / IP バリデーション

```php
$host = strtolower($parts['host']);

// IPv6 ブラケットをストリップ: [::1] → ::1
if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
    $host = substr($host, 1, -1);
}

// localhost と *.localhost エイリアスをブロック
if ($host === 'localhost' || str_ends_with($host, '.localhost')) {
    return false;
}

// ホストが IP リテラルなら直接チェック
if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
    return !isBlockedIp($host);
}

// そうでなければホスト名を解決 → 解決された IP をチェック
$resolved = gethostbyname($host);

if ($resolved !== $host) {   // 解決できない場合は false
    return !isBlockedIp($resolved);
}
// 解決できないホスト名 → 許可（サーバーから到達できない有効なドメインかもしれない）
return true;
```

### ステップ 3 — プライベート / 予約済み IP チェック

```php
function isBlockedIp(string $ip): bool
{
    // IPv6 ループバック
    if ($ip === '::1') return true;

    // FILTER_FLAG_NO_PRIV_RANGE: 10.x, 172.16-31.x, 192.168.x をブロック
    // FILTER_FLAG_NO_RES_RANGE:  127.x, 169.254.x, 0.x, 240.x+ をブロック
    return filter_var(
        $ip,
        FILTER_VALIDATE_IP,
        FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
    ) === false;
}
```

### DNS リバインディングの注意

DNS リバインディング攻撃はバリデーション通過_後_にドメインの IP を変更します。重要なユースケースでは、保存時だけでなく_取得時_にも URL を検証するか、プライベートレンジをブロックするネットワークレイヤーの Egress ファイアウォールを使用してください。

---

## テスト用にリゾルバーを注入する

ユニットテストでの DNS 呼び出しは遅く、非決定論的です。リゾルバーを注入可能にしてください:

```php
final class UrlValidator
{
    /** @param (callable(string): string)|null $ipResolver */
    public function __construct(private readonly mixed $ipResolver = null)
    {
    }

    private function resolveHost(string $host): string
    {
        /** @var callable(string): string $resolver */
        $resolver = $this->ipResolver ?? static fn (string $h): string => gethostbyname($h);
        return $resolver($host);
    }
}
```

テストでは:

```php
$stubResolver = static function (string $host): string {
    return match ($host) {
        'private.internal'   => '10.0.0.1',       // プライベート → ブロック
        'public.example.com' => '93.184.216.34',  // パブリック → 許可
        default              => $host,             // 解決できない → 許可
    };
};

$validator = new UrlValidator($stubResolver);
```

---

## VULN-A〜L アセスメント結果

### VULN-A — 整数オーバーフロー（`limit` クエリパラメーター）

`V::queryInt()` は `ctype_digit()` + `strlen() > 18` ガードを使用します。
20 桁と 19 桁の文字列は `(int)` キャスト前に拒否されます。

```
✅ PASS — オーバーフローガードがサイレントな PHP_INT_MAX ラップを防ぐ
```

### VULN-B — 型の混乱（JSON ボディからの URL / スラグ）

`V::str()` は `is_string()` を強制します — `int 42`、`bool true`、`null` を拒否します。

```php
V::str($body['original_url'] ?? null, 2048)  // → 非文字列に対して null
V::str($body['slug'] ?? null, 20)            // → 非文字列に対して null
```

```
✅ PASS — URL またはスラグバリデーションの前に文字列型を強制
```

### VULN-C — SQL インジェクション

すべてのクエリは PDO パラメーター化ステートメントを使用します:

```php
'SELECT ... FROM links WHERE slug = :slug LIMIT 1'
// → $stmt->execute([':slug' => $slug])
```

`'; DROP TABLE links; --'` はスラグフォーマットバリデーション（SLUG_PATTERN）で DB に到達する前に失敗します。DB に到達しても、パラメーター化クエリが実行を防ぎます。

```
✅ PASS — パラメーター化クエリ + スラグ allowlist
```

### VULN-D — パラメーター汚染

PSR-7 の `getQueryParams()` は PHP の `parse_str()` を呼び出し、重複キーに対して_最後_の値を取ります。`?limit=10&limit=999999` を送ると → `limit=999999` となり `V::queryInt()` の範囲チェック（> MAX_LIMIT）で失敗します。

```
✅ PASS — 範囲チェックが任意の単一値をキャッチ; クラッシュなし
```

### VULN-E — IDOR（クロスユーザーリンクアクセス）

DELETE は `deleteForUser($slug, $userId)` を使用します:

```sql
DELETE FROM links WHERE slug = :slug AND user_id = :user_id
```

ユーザー B の `DELETE /links/user-a-slug` に自分の `X-User-Id` を使うと 404 が返されます（行は削除されず、単に WHERE 句にマッチしません）。

```
✅ PASS — 所有権を DB レベルで強制; 404 は列挙を防ぐ
```

### VULN-F — ReDoS 耐性

URL バリデーションは `parse_url()`（C 拡張、バックトラッキングなし）を使用します。
スラグバリデーションは代替グループのないシンプルなアンカー付き正規表現を使用します。
`V::queryInt()` は `ctype_digit()`（O(n)、バックトラッキング耐性）を使用します。

```
✅ PASS — 信頼できない入力に指数バックトラッキング正規表現なし
```

### VULN-G — パストラバーサル

この API にはファイルシステムアクセスがありません。適用外です。

```
N/A
```

### VULN-H — シークレット比較のタイミング攻撃

`V::secret()` は `hash_equals()` に委譲します — 文字列の差異がどこにあっても一定時間。文字列の異なる点でタイミングにより長さ/プレフィックス情報が漏洩する早期終了文字列比較を避けます。

```
✅ PASS — hash_equals() がタイミングオラクルを防ぐ
```

### VULN-I — 空の期待シークレットバイパス

`V::secret('', '')` → `false`。未設定の API キーはアクセスを許可しません:

```php
return $expected !== '' && hash_equals($expected, $actual);
```

```
✅ PASS — 空の期待値は常に false を返す
```

### VULN-J — `expires_at` の ISO 8601 日付オーバーフロー

`V::isoDatetime()` は `DateTimeImmutable::createFromFormat(DATE_ATOM, ...)` + ラウンドトリップ比較を使用します。`2024-02-30T00:00:00+00:00` は PHP で Mar 1 にロールオーバーします; 再フォーマットされた文字列が入力にマッチしません → null。

`+25:00` オフセット: 明示的な `$tzHours > 14` 範囲チェックでキャッチされます（チェックなしでは PHP はサイレントに受け入れ、ラウンドトリップも通過します — 明示的なチェックを必須にします）。

```
✅ PASS — ラウンドトリップがオーバーフロー日付をキャッチ; 明示的なオフセット範囲チェックが +25:00 をキャッチ
```

### VULN-K — SSRF

URL バリデーションなし: `http://127.0.0.1/admin`、`http://169.254.169.254/`、
`http://10.0.0.1/`、`javascript:alert(1)`、`file:///etc/passwd` がすべて保存され、潜在的に取得されます。

`UrlValidator` を使用すると:

| 入力 | ブロック理由 |
|---|---|
| `http://127.0.0.1/` | ループバック IP（`NO_RES_RANGE`） |
| `http://localhost/` | 完全一致 `'localhost'` |
| `http://internal.localhost/` | `.localhost` サフィックス |
| `http://10.0.0.1/` | プライベート IP（`NO_PRIV_RANGE`） |
| `http://192.168.1.1/` | プライベート IP |
| `http://169.254.169.254/` | 予約済み IP（`NO_RES_RANGE`） |
| `http://private.internal/` | 10.0.0.1 に解決 → ブロック |
| `javascript:alert(1)` | スキームが `['http','https']` にない |
| `file:///etc/passwd` | スキームが allowlist にない |
| `ftp://example.com/` | スキームが allowlist にない |

```
✅ PASS — スキーム allowlist + IP レンジフィルターがすべての SSRF ベクターをブロック
```

### VULN-L — マスアサインメント

`click_count` と `created_at` は `LinkRepository::create()` でサーバーサイドで設定されます。
リクエストボディのキー `click_count: 999999` と `created_at: "2000-01-01..."` は単純に無視されます — コントローラーはそれらを読みません。

```
✅ PASS — サーバーサイドフィールドはリポジトリで設定され、リクエストボディからは設定されない
```

---

## VULN アセスメントサマリー

| ID | 脆弱性 | ステータス |
|---|---|---|
| VULN-A | 整数オーバーフロー | ✅ PASS |
| VULN-B | 型の混乱 | ✅ PASS |
| VULN-C | SQL インジェクション | ✅ PASS |
| VULN-D | パラメーター汚染 | ✅ PASS |
| VULN-E | IDOR | ✅ PASS |
| VULN-F | ReDoS | ✅ PASS |
| VULN-G | パストラバーサル | N/A |
| VULN-H | タイミング攻撃 | ✅ PASS |
| VULN-I | 空のシークレットバイパス | ✅ PASS |
| VULN-J | DateTime オーバーフロー | ✅ PASS |
| VULN-K | SSRF | ✅ PASS |
| VULN-L | マスアサインメント | ✅ PASS |

**すべての適用可能な脆弱性: PASS（11/11）**

---

## スラグの安全性（VULN-A、C）

スラグはインジェクションと予期しないルーティングの両方を防ぐために、安全な文字セットに制限する必要があります:

```php
// パターン: 小文字英数字 + ハイフン/アンダースコア、3〜20 文字
// 英数字で始まり英数字で終わる
private const SLUG_PATTERN = '/^[a-z0-9][a-z0-9_-]{1,18}[a-z0-9]$|^[a-z0-9]{3}$/';

if (!preg_match(self::SLUG_PATTERN, $rawSlug)) {
    return 422;
}
```

この単一の正規表現はアンカー付きで、重複するマッチパスを持つ代替グループがありません — ReDoS に悪用できません。

**拒否されるスラグ**: `'; DROP TABLE links; --'` · `../../etc` · `MySlug`
· `sl@g!` · `a`（短すぎる） · 21 文字の文字列（長すぎる）

---

## 主要なポイント

| パターン | 実装 |
|---|---|
| SSRF 防止 | `parse_url()` スキーム allowlist + `filter_var NO_PRIV_RANGE` |
| テストでの DNS 解決 | 注入可能な `ipResolver` コールバック |
| スラグの安全性 | 文字 allowlist 正規表現（アンカー付き、バックトラッキングなし） |
| URL 型強制 | `V::str()` → URL 解析前の `is_string()` |
| 有効期限バリデーション | `V::isoDatetime()` とラウンドトリップ + オフセット範囲チェック |
| IDOR 防止 | すべての書き込みクエリで `WHERE slug = ? AND user_id = ?` |
| マスアサインメント | サーバーサイドフィールドはリポジトリで設定、コントローラーでは無視 |
