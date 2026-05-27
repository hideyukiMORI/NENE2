# ハウツー: セキュアダウンロードのための署名付き URL

> **FT リファレンス**: FT338 (`NENE2-FT/signedlog`) — TTL 付き HMAC-SHA256 署名 URL 生成、改ざん検出（401）、有効期限（410 Gone）、リソースバウンドトークン、誤シークレット拒否、16 テスト / 40+ アサーション PASS。

このガイドでは、長期的な認証情報を公開せずに未認証での非公開ファイルダウンロードを許可する時間制限付き署名 URL の生成方法を解説します。

## スキーマ

```sql
CREATE TABLE files (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT    NOT NULL,
    owner_id   INTEGER NOT NULL,
    mime_type  TEXT    NOT NULL DEFAULT 'application/octet-stream',
    created_at TEXT    NOT NULL
);
```

## エンドポイント

| メソッド | パス | 説明 |
|--------|------|------|
| `POST` | `/files` | ファイルレコードを登録する |
| `POST` | `/files/{id}/sign` | 署名付きダウンロード URL を生成する |
| `GET`  | `/download?token=...` | 署名付きトークンを使ってダウンロードする |

## ファイルの登録

```php
POST /files
{"name": "report.pdf", "owner_id": 1}
→ 201
{
  "id": 1,
  "name": "report.pdf",
  "owner_id": 1,
  "mime_type": "application/octet-stream",
  "created_at": "..."
}

// カスタム MIME
POST /files  {"name": "image.png", "owner_id": 2, "mime_type": "image/png"}
→ 201  {"mime_type": "image/png", ...}

// バリデーション
POST /files  {"owner_id": 1}     → 422  // name 必須
POST /files  {"name": "f.pdf"}   → 422  // owner_id 必須
```

## 署名付き URL の生成

```php
POST /files/1/sign
{"ttl_seconds": 300}
→ 200
{
  "token": "1|2026-05-27 09:05:00|a3f9e2...",
  "expires_at": "2026-05-27T09:05:00Z",
  "url": "/download?token=1%7C2026-05-27+09%3A05%3A00%7Ca3f9e2...",
  "ttl_seconds": 300
}

// 省略時はデフォルト TTL = 3600（1 時間）
POST /files/1/sign  {}
→ 200  {"ttl_seconds": 3600}

// 未知のファイル
POST /files/999/sign  {"ttl_seconds": 60}
→ 404
```

## トークンを使ったダウンロード

```php
GET /download?token=1|2026-05-27+09:05:00|a3f9e2...
→ 200  {"id": 1, "name": "report.pdf", "mime_type": "application/octet-stream"}

// トークンなし
GET /download
→ 401

// 改ざんされたトークン（末尾 4 文字を変更）
GET /download?token=1|2026-05-27+09:05:00|XXXX
→ 401

// 有効期限切れトークン（expires_at が過去）
GET /download?token=1|2020-01-01+00:00:00|...valid_hmac...
→ 410 Gone

// ランダムなゴミ
GET /download?token=totally-invalid-garbage
→ 401
```

**410 Gone**（401 ではない）は有効期限切れトークンに使用します: URL は存在していて有効でした — 単に期限が切れただけです。これにより、クライアントは「一度も有効でない」と「かつて有効だったが古くなった」を区別できます。

## トークンフォーマット — HMAC-SHA256

```
token = "{file_id}|{expires_at}|{hmac}"

hmac = HMAC-SHA256(key=server_secret, message="{file_id}|{expires_at}")
```

```php
class HmacSigner
{
    public function __construct(private readonly string $secret)
    {
    }

    public function sign(int $fileId, string $expiresAt): string
    {
        $payload = "{$fileId}|{$expiresAt}";
        $hmac    = hash_hmac('sha256', $payload, $this->secret);
        return "{$payload}|{$hmac}";
    }

    public function verify(string $token, string $now): ?int
    {
        $parts = explode('|', $token, 3);
        if (count($parts) !== 3) {
            return null;
        }

        [$fileIdStr, $expiresAt, $receivedHmac] = $parts;
        $fileId  = (int) $fileIdStr;
        $payload = "{$fileId}|{$expiresAt}";

        // 定数時間比較
        $expected = hash_hmac('sha256', $payload, $this->secret);
        if (!hash_equals($expected, $receivedHmac)) {
            return null;  // 改ざんされているか誤ったシークレット
        }

        // HMAC の検証後に有効期限を確認
        if ($expiresAt < $now) {
            return -1;  // 期限切れ — 呼び出し元は 410 を返す
        }

        return $fileId;
    }
}
```

**重要な順序**: 有効期限を確認する前に必ず HMAC を検証してください。無効なトークンで先に有効期限を確認すると、攻撃者が有効期限の動作を調査できます。

### リソースバインディング

各トークンは `file_id` をエンコードします。異なるファイルのトークンは異なる HMAC ダイジェストを生成します:

```php
$token1 = $signer->sign(1, $future);
$token2 = $signer->sign(2, $future);
// $token1 !== $token2 — ファイル 1 のトークンをファイル 2 のアクセスに再利用できない
```

### 誤ったシークレット

異なるシークレットで署名されたトークンは `verify()` で null を返します:

```php
$otherSigner = new HmacSigner('different-secret');
$token = $otherSigner->sign(1, $future);
$signer->verify($token, $now);  // null — HMAC ミスマッチ
```

---

## してはいけないこと

| アンチパターン | リスク |
|---|---|
| HMAC 比較に `hash_equals()` の代わりに `===` を使用する | タイミング攻撃で HMAC をバイトごとに漏洩させる |
| HMAC を検証する前に有効期限を確認する | 攻撃者が偽造トークンで有効期限を調査してサーバークロックを知る |
| トークンペイロードに user_id だけを含め file_id を含めない | ユーザー 1 のファイル 1 のトークンがユーザー 1 のファイル 2 のアクセスに再利用可能になる |
| HMAC-SHA256 の代わりに `md5()` または `sha1()` を使用する | キー付きハッシュが必要; キーなしハッシュは簡単に偽造できる |
| 有効期限切れトークンに 401 を返す | 410 はクライアントに「トークンは本物だが古い」ことを伝える; 適切な再署名フローを可能にする |
| アクセスログにトークン値を記録する | トークンはアクセス権を付与する — パスワードのように扱う; ログでマスクするか省略する |
| 弱いまたは予測可能なシークレットを使用する | キーは少なくとも 32 のランダムバイトでなければならない; タイムスタンプやホスト名から派生させない |
