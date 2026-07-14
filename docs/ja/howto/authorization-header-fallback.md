# `Authorization` を剥がすプロキシ配下で Bearer 認証を復旧する

一部の共有ホスティングの前段プロキシは、リクエストが PHP に届く前に標準の
`Authorization` ヘッダを削除します（HETEML 系ホスティングの本番環境で実際に確認）。
カスタムヘッダは通るのに `Authorization` だけが通らないため、定番の復旧テクニックも
すべて無効です:

- `.htaccess` の `RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]` —
  Apache 自体がヘッダを受け取れないので無意味;
- `CGIPassAuth on` — 同じ理由で無効。

その結果、ブラウザは完全に有効なトークンを送っているのに、Bearer 保護された
すべてのエンドポイントが 401 `missing_token` を返します。

NENE2 は標準の 2 段構えの解決策を同梱しています（ADR 0019 参照）:

1. **フロントエンド**: `@hideyukimori/nene2-client`（1.1.0 以降）が全リクエストで
   標準ヘッダに加えて `X-Authorization: Bearer <token>` ミラーを送信します。
2. **バックエンド**: `Nene2\Middleware\AuthorizationHeaderFallbackMiddleware` が
   **`Authorization` が不在または空のときに限り**ミラーを採用します。標準ヘッダが
   届く環境ではバイト単位で無影響です。

---

## 既定パイプラインで有効化する

`RuntimeApplicationFactory` の opt-in フラグ 1 つです:

```php
$app = (new RuntimeApplicationFactory(
    $psr17, $psr17,
    routeRegistrars: [/* ... */],
    authMiddleware:  $bearerMiddleware,
    enableAuthorizationHeaderFallback: true, // 既定は off
))->create();
```

有効化すると、フォールバックは認証ステージの先頭 — マシン API キーチェックの前、
注入された認証ミドルウェアの前 — で実行されるため、資格情報を読むすべての
ミドルウェアが復元済みのヘッダを見ます。HTTP メソッドにもパスにも依存しません。

## 手動で配線する場合

自前で組んだパイプラインでは、認証ミドルウェアより前の任意の位置に置きます:

```php
$stack = [
    // ... request id、ロギング、セキュリティヘッダ、CORS、エラーハンドリング ...
    new AuthorizationHeaderFallbackMiddleware(),
    $bearerMiddleware,
];
```

PSR-15 パイプラインの外では、静的ヘルパーとして同じ変換を利用できます:

```php
$request = AuthorizationHeaderFallbackMiddleware::apply($request);
```

---

## 有効化してはいけないケース

フォールバックを有効化すると、`X-Authorization` は `Authorization` と資格情報として
等価になります。ヘッダが*偶発的に*剥がされるホストではまさに正解ですが、上流が
*意図的に*剥がしている構成ではまさに誤りです:

- ゲートウェイ自身が認証を行い、信頼済みの identity を後段へ渡す構成;
- 信頼できないクライアントからの資格情報を WAF がフィルタする構成。

こうした構成ではミラーがクライアント制御のバイパスになります。フラグを off のまま
にするか、上流で `X-Authorization` も併せて剥がしてください。

また、アクセスログや中間プロキシでは `X-Authorization` を `Authorization` と同じ
機密度で扱ってください。

## 補足

- ヘッダ名は**固定**です（`AuthorizationHeaderFallbackMiddleware::FALLBACK_HEADER`、
  `X-Authorization`）。フロントエンドクライアントとのフリート全体の配線契約であり、
  調整用のノブではありません。
- ミラー値はそのまま採用されます（`Bearer <token>` を含む）。トークン検証は従来どおり
  認証ミドルウェアの仕事です — 不正なミラーは不正な標準ヘッダとまったく同じように
  失敗します。
- 優先順位は常に: 空でない `Authorization` が勝ち、ミラーは標準ヘッダが不在または
  空のときにのみ参照されます。
