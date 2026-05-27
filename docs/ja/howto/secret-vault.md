# ハウツー: パーソナルシークレットボールト API

HMAC 整合性、IDOR 防止、管理者のみのメタデータアクセスを備えたユーザーごとのキーバリューストレージを実演します。
フィールドトライアル: FT195 (`../NENE2-FT/vaultlog/`)。VULN-A〜L セキュリティ監査を含みます。

---

## パターンサマリー

| 懸念事項 | アプローチ |
|---|---|
| ユーザー分離 | すべてのクエリに `WHERE user_id = :uid` — IDOR 不可能 |
| 管理者は値を見ない | 管理者エンドポイントは `user_id + key` のみを返す |
| HMAC 整合性 | `HMAC-SHA256(userId|key|value, secret)` をエントリごとに保存 |
| キーバリデーション | `preg_match('/\A[a-z0-9_-]{1,64}\z/', $key)` — 安全で ReDoS リスクなし |
| ユーザー ID バリデーション | `ctype_digit()` + 長さガード + `> 0` チェック |
| 管理者キー | `hash_equals()` 定数時間、空キーにはフェイルクローズ |
| アップサート | `UNIQUE(user_id, key_name)` → 初回保存（201）または更新（200） |

---

## ルート

| メソッド | パス | 認証 | 説明 |
|---|---|---|---|
| `POST` | `/vault` | `X-User-Id` | シークレットを保存または更新する |
| `GET` | `/vault` | `X-User-Id` | ユーザーのシークレットキーを一覧表示する（値なし） |
| `GET` | `/vault/{key}` | `X-User-Id` | ユーザーのシークレット値を取得する |
| `DELETE` | `/vault/{key}` | `X-User-Id` | ユーザーのシークレットを削除する |
| `GET` | `/admin/vault` | `X-Admin-Key` | すべてのユーザー + キーを一覧表示する（値なし） |
| `GET` | `/admin/vault/{userId}` | `X-Admin-Key` | 特定ユーザーのキーを一覧表示する |

---

## データベーススキーマ

```sql
CREATE TABLE vault_entries (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    key_name   TEXT    NOT NULL,
    value      TEXT    NOT NULL,
    hmac       TEXT    NOT NULL,   -- HMAC-SHA256 整合性タグ
    created_at TEXT    NOT NULL,
    updated_at TEXT    NOT NULL,
    UNIQUE (user_id, key_name)
);
```

`UNIQUE(user_id, key_name)` 制約により、（ユーザー、キー）ペアごとに 1 エントリが強制されます。

---

## HMAC 整合性

```php
private function computeHmac(int $userId, string $key, string $value): string
{
    return hash_hmac('sha256', "{$userId}|{$key}|{$value}", $this->hmacSecret);
}
```

GET 時、ハンドラーは保存された HMAC を検証します:

```php
if (!$this->repo->verifyIntegrity($entry)) {
    return $this->problem(500, 'integrity-error', 'Secret integrity check failed.');
}
```

これにより DB への直接改ざん（例: 危殆化した DBA が API を経由せずに値を変更する）が検出されます。

---

## IDOR 防止

すべてのクエリに `user_id = :uid` が含まれます:

```sql
SELECT * FROM vault_entries WHERE user_id = :uid AND key_name = :key
```

ユーザー 200 がユーザー 100 が所有するキー `private-key` をクエリすると 404 を受け取ります — 「見つからない」と同一で、他のユーザーのキーが存在するかどうかの列挙を防ぎます。

管理者エンドポイントは `value` を返しません:

```php
// ユーザーは自分の値を見る
public function toUserArray(): array
{
    return ['key' => ..., 'value' => $this->value, ...];
}

// 管理者はメタデータのみ見る — 値なし
public function toAdminArray(): array
{
    return ['user_id' => ..., 'key' => ..., ...];
}
```

---

## キーバリデーション

```php
private const string KEY_PATTERN = '/\A[a-z0-9_-]{1,64}\z/';
```

`\A` と `\z` アンカーにより部分マッチを防ぎます。文字クラスは最小限です: 小文字英数字、ダッシュ、アンダースコア。長さは `{1,64}` に制限されています — バックトラッキングの増幅はありません。

これは以下を拒否します:
- 大文字（`UPPER_CASE`）
- スペースや特殊文字
- パストラバーサルフラグメント（`../etc/passwd`）
- SQL インジェクタブルな文字列（`' OR '1'='1`）
- 空文字列や 64 文字を超える文字列

---

## ユーザー ID バリデーション

```php
private function resolveUserId(ServerRequestInterface $request): ?int
{
    $raw = $request->getHeaderLine('X-User-Id');
    if ($raw === '' || !ctype_digit($raw) || strlen($raw) > 18) return null;
    $id = (int) $raw;
    return $id > 0 ? $id : null;
}
```

- `ctype_digit()` は負の数を拒否します（`-` 記号は数字ではありません）
- `strlen > 18` は整数オーバーフローを防ぎます（`PHP_INT_MAX` は 19 桁）
- `> 0` は無効なユーザー ID として `"0"` を拒否します

---

## アップサートパターン

```php
public function store(int $userId, string $key, string $value): string
{
    $existing = $this->findEntry($userId, $key);
    if ($existing !== null) {
        // UPDATE ...
        return 'updated';  // → 200
    }
    // INSERT ...
    return 'stored';  // → 201
}
```

初回書き込み時は `'stored'`（201）、上書き時は `'updated'`（200）を返します。
ハンドラーはこれらを HTTP ステータスコードにマップします。

---

## VULN-A〜L 結果

| チェック | テスト | 結果 |
|---|---|---|
| VULN-A | キーパラメーター/ボディへの SQL インジェクション | PASS — キーバリデーションがクエリ前に拒否 |
| VULN-B | IDOR: ユーザーが他ユーザーのキーを読む/削除する | PASS — クロスユーザーアクセスで 404 |
| VULN-C | 一覧が自分のエントリのみ返す | PASS — WHERE user_id スコープ |
| VULN-D | 管理者キーのブルートフォース/バイパス | PASS — hash_equals + フェイルクローズ |
| VULN-E | 値への XSS | PASS — そのまま保存、JSON レスポンスは HTML ではない |
| VULN-F | キーアップサートのべき等性 | PASS — 最後の書き込みが勝つ、重複なし |
| VULN-G | キーのパストラバーサル | PASS — パターンが `..` とスラッシュを拒否 |
| VULN-H | 負またはゼロのユーザー ID | PASS — ctype_digit + > 0 ガード |
| VULN-I | 非常に大きなユーザー ID（オーバーフロー） | PASS — strlen > 18 ガード |
| VULN-J | パスのヌルバイト | PASS — ルーター/パターンが拒否 |
| VULN-K | ボディの過長キー | PASS — 422 バリデーション |
| VULN-L | 空の HMAC シークレット（パニックなし） | PASS — 空キーで決定論的 HMAC、クラッシュなし |

---

## テストノート

- `AppFactory::create(?PDO, ?string adminKey, ?string hmacSecret)` — すべてユニットテストのために注入可能。
- `withParsedBody($body)` はテストヘルパーで必須（Nyholm PSR-7 は JSON を自動パースしない）。
- IDOR テスト: ユーザー 100 として保存し、ユーザー 200 としてアクセスを試みる → 404 でなければならない。
- 管理者テスト: すべてのレスポンス配列から `value` キーが存在しないことを確認する。
