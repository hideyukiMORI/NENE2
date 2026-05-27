# 署名付き URL

署名付き URL は、呼び出し元がアカウントで認証することなく、保護されたリソースへの時間制限付きのリソーススコープアクセスを提供します。このパターンはファイルダウンロード、事前署名付きアップロードスロット、第三者と一時的なアクセスを共有する必要があるあらゆるケースで使用されます。

## 中核概念

署名付き URL にはアクセスを認可するために必要なすべてが含まれています: リソース ID、有効期限、および URL が信頼されたサーバーによって生成されたことを証明する HMAC 署名。サーバーは検証に自身のシークレットキーだけが必要です — データベース検索は不要です。

## トークンフォーマット

```
base64url({resource_id}|{expires_at}|{hmac-sha256(resource_id|expires_at, secret)})
```

HMAC は `resource_id|expires_at` を一緒にカバーします。どちらかの部分を変更すると署名が無効になります。これにより、トークンは正確に 1 つのリソースと 1 つの有効期限ウィンドウに束縛されます。

## Signer 実装

```php
final readonly class HmacSigner
{
    private const string ALGO = 'sha256';

    public function __construct(
        private string $secret,
    ) {}

    public function sign(int $resourceId, string $expiresAt): string
    {
        $payload = $resourceId . '|' . $expiresAt;
        $mac     = hash_hmac(self::ALGO, $payload, $this->secret);

        return $this->base64UrlEncode($payload . '|' . $mac);
    }

    public function verify(string $token, string $now): ?int
    {
        $decoded = $this->base64UrlDecode($token);
        if ($decoded === null) {
            return null;
        }

        $parts = explode('|', $decoded, 3);
        if (count($parts) !== 3) {
            return null;
        }

        [$resourceId, $expiresAt, $storedMac] = $parts;

        $expectedMac = hash_hmac(self::ALGO, $resourceId . '|' . $expiresAt, $this->secret);

        // hash_equals() は必須 — === を使うとタイミング情報が漏洩する
        if (!hash_equals($expectedMac, $storedMac)) {
            return null;
        }

        if ($expiresAt < $now) {
            return null;
        }

        return (int) $resourceId;
    }
}
```

`hash_equals()` は交渉の余地がありません。文字列等価比較は最初の不一致で終了し、HMAC の何文字が一致するかを漏洩します。攻撃者はこれを利用して署名をバイトごとに偽造できます。`hash_equals()` は常にすべての文字を比較します。

## 有効期限切れトークンの 410 Gone vs 401 Unauthorized

ユーザーはリンクが期限切れになった（新しいものを要求すべき）のか、リンクが一度も有効でなかったのかを知ることでメリットを得ます。Signer は最初に HMAC を検証し、次に有効期限を確認します。HTTP レスポンスでこれらを区別するには:

```php
$resourceId = $this->signer->verify($token, $now);

if ($resourceId === null) {
    // HMAC 検証なしで有効期限を抽出
    $expiresAt = $this->signer->extractExpiresAt($token);
    if ($expiresAt !== null && $expiresAt < $now) {
        return $problems->create($request, 'gone', 'This link has expired.', 410, '');
    }
    return $problems->create($request, 'unauthorized', 'Invalid or expired token.', 401, '');
}
```

`extractExpiresAt()` は base64 をデコードして `|` で分割するだけです — HMAC を検証しません。これは安全です。なぜなら:
1. 有効期限はシークレットではありません（署名付き URL に元々表示されています）。
2. 攻撃者は操作された有効期限で有効なトークンを偽造できません。`verify()` がそれを拒否します。
3. 410 レスポンスはトークンの偽造に役立つ情報を提供しません。

「HMAC ミスマッチ」と「有効期限切れ」に対して異なるエラーメッセージを公開しないでください — 攻撃者がまず任意の有効期限値に対して有効な署名を構築し、次にそれらを使ってタイミングを調査できるようになります。

## 署名付き URL の生成

```php
// POST /files/{id}/sign
$expiresAt = (new \DateTimeImmutable())
    ->add(new \DateInterval("PT{$ttlSeconds}S"))
    ->format('Y-m-d H:i:s');

$token = $this->signer->sign($file->id, $expiresAt);

return $json->create([
    'token'       => $token,
    'expires_at'  => $expiresAt,
    'ttl_seconds' => $ttlSeconds,
    'url'         => '/download?token=' . urlencode($token),
]);
```

URL に埋め込む前にトークンを必ず `urlencode()` してください — base64url の文字は URL セーフですが、`=` パディング（存在する場合）はそうではなく、デコードされたペイロードの `|` セパレーターはエンコードされた形式に現れてはいけません。

## シークレットキー管理

- 環境変数からシークレットを注入してください — ハードコードしないでください。
- 少なくとも 32 バイトのランダムデータを使用してください（`random_bytes(32)` → 16 進数または base64）。
- シークレットのローテーションには、複数のシークレットを同時に検証すること（1 つが成功するまでそれぞれを試す）をサポートし、その後古いシークレットを段階的に廃止してください。

```php
// ローテーション中のマルチシークレットサポート
public function verifyWithRotation(string $token, string $now, array $secrets): ?int
{
    foreach ($secrets as $secret) {
        $signer = new HmacSigner($secret);
        $id = $signer->verify($token, $now);
        if ($id !== null) {
            return $id;
        }
    }
    return null;
}
```

## ステートレス vs ステートフルな署名付き URL

このパターンは**ステートレス**です — サーバーは発行されたトークンを追跡しません。これが主な利点です（ダウンロードごとに DB 検索なし）が、これは意味します:

- 有効期限前に署名付き URL を失効させることができません。
- シークレットがローテーションされると、以前に発行されたすべてのトークンが即座に無効になります。

失効可能なトークンのためには、ブロックリストテーブル（`revoked_tokens`）を管理し、検証中にそれを確認してください。これによりステートレスの利点と失効可能性がトレードオフになります。

## してはいけないこと

| アンチパターン | リスク |
|---|---|
| HMAC 比較に `===` または `strcmp()` を使用する | タイミング攻撃 — 署名の偽造を許す |
| 有効期限なしで `resource_id` だけに署名する | トークンが永続的になる — 期限切れにできない |
| `resource_id` なしで `expires_at` だけに署名する | 1 つのトークンですべてのリソースにアクセス可能になる |
| 有効期限を使って「改ざん」と「期限切れ」を区別する | HMAC へのオラクル攻撃を許す |
| トークンに生のキーを埋め込む | 目的が損なわれる — トークンは不透明でなければならない |
| 長い TTL（日/週） | トークンが漏洩した場合の露出ウィンドウが増加する |
