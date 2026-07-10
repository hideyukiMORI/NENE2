# 使い捨てデモを追加する

このガイドでは、プロダクトに**請求書スタイルの使い捨てデモ**を追加する方法を説明します。訪問者が
`GET /demo/{template}` を開くと、アプリは新品の使い捨て組織をプロビジョニングし、
リアルな業種データをシードし、認証済みセッションを着席させ、新しいテナントへ 302 リダイレクトします。
cron のスイーパーが TTL 経過後にデモ組織を破棄します。「デモのリセット」は単に URL を
もう一度叩くだけ — 毎回新しい組織が作られます。

フレームワークモジュールは `Nene2\Demo` です。プロダクトに依存しないオーケストレーション
（ゲート → スロットル/キャパシティ → 競合リトライ付きスラグ割り当て → プロビジョン → シード → 着席）
とスイープ判定（TTL + 超過分）を担当します。あなたはプロダクト固有の部分をすべて担う
4 つの小さなインターフェースを実装します。

**前提条件**: マルチテナントの組織モデル（スラグで識別される組織）を持ち、組織の作成と削除の
手段がある、動作する NENE2 アプリケーションがあること。

---

## フレームワークが提供するもの vs. あなたが実装するもの

| フレームワーク（`Nene2\Demo`） | あなた（プロダクト側） |
|---|---|
| `StartDisposableDemoHandler` — HTTP オーケストレーション | `DisposableOrgProvisionerInterface` — デモ組織 1 つ + 管理者を作成 |
| `DisposableDemoSweeper` — TTL / 超過分の判定、`SweepReport` | `DisposableOrgReaperInterface` — 組織 1 つを**子データもろとも**破棄 |
| `CountingDemoCapacityGuard` — 作成時の上限 + IP ごとのスロットル | `DemoSessionSeaterInterface` — 認証の受け渡し + リダイレクト |
| `DemoConfig` — `AppConfig::$demo` 上の型付き `DEMO_*` 設定 | `DemoDataSeederInterface` — 業種シードデータ |
| `DemoRouteRegistrar` — `GET /demo/{template}` を登録 | `DemoTemplateKeyInterface` — あなたのテンプレート enum |
| `MinimalDemoErrorPageRenderer` — 無ブランドのブラウザ向けエラーページ | `DemoErrorPageRendererInterface` — ブランド付きエラーページ（任意） |

---

## 1. 設定する

`DEMO_*` 変数は `ConfigLoader` によって `AppConfig::$demo`（型付きの `Nene2\Demo\DemoConfig`）に
読み込まれます — `getenv()` で読んではいけません:

```bash
DEMO_MODE=1            # 厳密にパースされる: 1/true/yes のみ有効。デフォルトは off
# DEMO_SLUG_PREFIX=demo-
# DEMO_TTL_HOURS=3
# DEMO_MAX_ORGS=200
# DEMO_SLUG_ATTEMPTS=5
```

`DEMO_MODE` が未設定の場合、エンドポイントは素の 404 を返します — 配線を休眠状態のまま出荷し、
デプロイメントごとに有効化できます。

## 2. テンプレートキーを定義する

シード可能な業種プリセットの string-backed enum です:

```php
enum DemoTemplate: string implements DemoTemplateKeyInterface
{
    case Kensetsu = 'kensetsu';   // {template} URL セグメント
    case Seisaku = 'seisaku';

    public function value(): string
    {
        return $this->value;
    }

    public static function tryFromValue(string $value): ?static
    {
        return self::tryFrom($value);
    }
}
```

## 3. プロビジョナーを実装する

既存の「組織を作成する」ユースケースの薄いラッパーです。スラグが使用済みの場合は
`SlugConflictException` を投げ（ハンドラーは新しいランダムスラグでリトライします）、
使い捨ての管理者クレデンシャルを内部で生成し、管理者の id を返します —
フレームワークがロールリテラルで管理者を検索することはありません:

```php
final readonly class DemoOrgProvisioner implements DisposableOrgProvisionerInterface
{
    public function __construct(private CreateOrganizationUseCaseInterface $createOrg)
    {
    }

    public function provision(string $slug, string $template): ProvisionedDemoOrg
    {
        try {
            $org = $this->createOrg->execute(null, new CreateOrganizationInput(
                name: $this->companyName($template),
                slug: $slug,
                adminEmail: 'admin@' . $slug . '.demo.local',
                adminPassword: SecureTokenHelper::generate(16),
            ));
        } catch (OrganizationSlugConflictException $e) {
            throw new SlugConflictException($slug, previous: $e);
        }

        return new ProvisionedDemoOrg($org->id, $org->slug, $org->adminUserId);
    }
}
```

## 4. シーダーを実装する

シードの内容は完全にあなた次第です。ただし 2 つの厳格なルールがあります:

- **注入されたただ 1 本のコネクションを通して書き込む** — リクエストが既に使っているのと同じ
  エグゼキューターです。同一データベースへの 2 本目の PDO は SQLite でデッドロックします
  （`database is locked`）。
- **すべての行に明示的な `$orgId` を持たせる** — デモルートは入口時点で組織なし（org-less）
  なので、シードは作ったばかりの組織への意図的なクロステナント書き込みです。ここで
  リクエストスコープのテナントホルダーに頼ってはいけません。

```php
final class DemoDataSeeder implements DemoDataSeederInterface
{
    public function __construct(
        private readonly DatabaseQueryExecutorInterface $query,
        private readonly ClockInterface $clock,
    ) {
    }

    public function seed(int $orgId, DemoTemplateKeyInterface $template): void
    {
        // clients, items, documents を挿入 ... $this->clock->now() を基準に
    }
}
```

シードする日付は注入されたクロックを基準にし（「今月」の相対日付にするとデモが常に最新に
見えます）、過去のイベントは今日以前にクランプします。

## 5. シーターを実装する

ここにプロダクトの認証が、完全に分離された形で置かれます。Cookie セッションのプロダクトなら
新しいテナントにスコープされたログイン Cookie を発行して SPA へ 302 し、JWT Bearer の
プロダクトなら SPA を独自の方法で着地させます:

```php
final readonly class DemoSessionSeater implements DemoSessionSeaterInterface
{
    public function seatAndRedirect(ServerRequestInterface $request, ProvisionedDemoOrg $org): ResponseInterface
    {
        $token = $this->refreshTokens->issue($org->adminUserId, $org->orgId);

        return $this->responseFactory->createResponse(302)
            ->withHeader('Location', '/' . $org->slug . '/dashboard')
            ->withHeader('Cache-Control', 'no-store')
            ->withAddedHeader('Set-Cookie', /* テナントにスコープされたセッション Cookie */);
    }
}
```

プロダクト固有のセッションセマンティクス（例: リロードするとログイン画面に落ちるワンショット
Cookie）はこのクラスの中に閉じ込めてください — オーケストレーションに漏らしてはいけません。

## 6. リーパーを実装する

> **警告**: 典型的な「組織を削除する」ユースケースは子テーブルにカスケード**しません** —
> 組織の行だけを削除すると、孤児となった子データが永久に取り残されます。リーパーが
> 完全な破棄を担い、フレームワークは意図的にあなたのスキーマを推測しようとしません。

まず子の行（親を経由してのみ到達できる孫を含む）を削除し、次に組織、そしてデータベース外の
残留物（ファイルスタンプ、キャッシュ）を削除します。`reap()` は**冪等**でなければなりません:
並行実行によって既にスイープ済みの組織はエラーではなく成功です —
`DisposableDemoSweeper` はこれに依存しており、あなたの例外をキャッチしません。

```php
final readonly class DemoOrgReaper implements DisposableOrgReaperInterface
{
    public function reap(int $orgId): void
    {
        foreach (self::CHILD_TABLES as $table) {
            $this->query->execute("DELETE FROM {$table} WHERE organization_id = ?", [$orgId]);
        }

        try {
            $this->deleteOrg->execute(null, $orgId);
        } catch (OrganizationNotFoundException) {
            // 既に消えている（並行スイープ）— 冪等な成功
        }
    }
}
```

## 7. ハンドラーとルートを配線する

```php
$config = $container->get(AppConfig::class);

$guard = new CountingDemoCapacityGuard(
    // カウントを注入する — フレームワークはテナントスキーマの知識を持たない。
    demoOrgCount: fn (): int => (int) $query->fetchValue(
        'SELECT COUNT(*) FROM organizations WHERE slug LIKE ?',
        [$config->demo->slugPrefix . '%'],
    ),
    config: $config->demo,
    throttleStorage: $rateLimitStorage,   // 本番では共有ストレージ!
);

$handler = new StartDisposableDemoHandler(
    $config->demo,
    $guard,
    new DemoOrgProvisioner($createOrg),
    new DemoDataSeeder($query, $clock),
    new DemoSessionSeater(...),
    $problemDetails,
    DemoTemplate::class,
);

(new DemoRouteRegistrar($handler))($router);   // GET /demo/{template}
```

このエンドポイントは設計上パブリックかつ組織なし（org-less）です（組織を*作る*側だからです）。
プロダクトにテナント解決ミドルウェアがある場合は、`/demo/...` を組織解決の対象から除外してください。

> **警告**: ガードのスロットルには [レート制限を追加する](add-rate-limiting.md) と同じ注意点が
> 当てはまります: `InMemoryRateLimitStorage` は PHP-FPM ワーカー間で状態を共有しません
> （本番では Redis/Memcached/DB を使ってください）。またリバースプロキシの背後では、
> 信頼できる転送 IP ヘッダーを読む `keyExtractor` を注入してください — さもないと
> すべてのクライアントが 1 つのバケットを共有します。

ガードのスロットルの既定値は**クライアント IP ごとに 1 時間あたり 30 回のデモ開始**です。
`throttleLimit` をチューニングする際は、このスタイルのデモが設計上ワンショットであること —
「デモのリセット」はリンクの再クリックであり、クリックのたびに 1 回分を消費します — と、
事務所や携帯キャリアの NAT によって多数の正規訪問者が 1 つの IP に同居することを念頭に
置いてください。10 回/時は本番で正規利用を枯渇させました。これより下げてはいけません。

## 8. 任意: ブラウザ向けエラーページをブランド化する

デモ開始ルートは、実在の人間が**ブラウザ**で開く唯一のルートです（営業プロスペクトが紹介
リンクをクリックする）。そのためハンドラーはエラーをコンテントネゴシエーションします:
リクエストの `Accept` ヘッダーに `text/html` が含まれる場合、4xx/5xx の Problem Details JSON は、
あなたが注入する `DemoErrorPageRendererInterface` の HTML ページに置き換えられます。既定は
同梱の `MinimalDemoErrorPageRenderer`（最小限・無ブランド・英語のカード）なので、そのままでも
動作します。プロダクトの文言・言語・ブランドを載せるには差し替えてください:

```php
final readonly class BrandedDemoErrorPageRenderer implements DemoErrorPageRendererInterface
{
    public function render(int $statusCode, ?int $retryAfterSeconds): ResponseInterface
    {
        // ステータスごとの固定文言。$retryAfterSeconds は「約N分後に再試行」に変換する。
    }
}

$handler = new StartDisposableDemoHandler(
    // ... ステップ 7 と同じ ...
    errorPageRenderer: new BrandedDemoErrorPageRenderer($responseFactory),
);
```

どのレンダラーを配線しても、フレームワークがトランスポート上の不変条件を強制します:
ページは元のエラーステータスと元の `Retry-After` ヘッダー（429）を保持し、
`X-Robots-Tag: noindex` が付与されます。API クライアント（`Accept` に `text/html` を
含まない）と成功リダイレクトはバイト単位で不変のままです。

カスタムレンダラーの 2 つの厳格なルール:

- **リクエスト入力を絶対にページへ入れない。** インターフェースが意図的にステータスコードと
  再試行秒数しか受け取らないのはこのためです — すべての文言は固定テキストとサーバー側で
  計算した数値だけで構成してください。さもないとエラーページが XSS の入口になります。
  `<meta name="robots" content="noindex">` を含め、外部アセットは参照しないでください。
- **Content-Security-Policy に注意。** あなたのアプリはほぼ確実に、アプリ全体の
  `default-src 'self'` を持つ `SecurityHeadersMiddleware` を実行しています。これは自己完結型の
  エラーページが必要とする**インラインの `<style>`/`<script>` をブロック**します — ページは
  素の無装飾テキストとして表示されてしまいます。このミドルウェアは不在のヘッダーしか
  追加しないので、レンダラーのレスポンスにページ専用の CSP を載せてください:

  ```
  Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; base-uri 'none'; form-action 'none'
  ```

  `script-src 'unsafe-inline'` はページが本当にスクリプト（例: 再試行カウントダウン）を
  持つ場合にのみ加えてください。インライン許可がここで安全なのは、まさにページが
  リクエスト入力を一切含まないからです。同梱レンダラーはこの CSP を既に持っています。

エラーページの差し替え以上のこと — 追加のゲート、ログ、レスポンスの後処理 — が必要なら、
`DemoRouteRegistrar` は任意の PSR-15 `RequestHandlerInterface` を受け取れるので、ルート登録を
再実装する代わりに `StartDisposableDemoHandler` をデコレーターで包めます。

## 9. cron でスイープする

```php
// tools/sweep-demo.php — 毎時実行する
$sweeper = new DisposableDemoSweeper($config->demo, new DemoOrgReaper(...), new UtcClock());

$rows = $query->fetchAll(
    'SELECT id, created_at FROM organizations WHERE slug LIKE ?',
    [$config->demo->slugPrefix . '%'],
);
$report = $sweeper->sweep(array_map(
    static fn (array $row): DemoOrgRecord => new DemoOrgRecord(
        (int) $row['id'],
        new DateTimeImmutable((string) $row['created_at']),
    ),
    $rows,
));

echo count($report->reapedOrgIds) . " demo orgs swept\n";
```

2 つの基準が組み合わされます: `DEMO_TTL_HOURS` より古い組織は期限切れとなり、さらに年齢に
かかわらず新しい方から `DEMO_MAX_ORGS` 件だけが生き残ります（暴走への保険）。スイーパーは
あなたが渡したレコードしか見ません — 実在の組織を守っているのはクエリの `LIKE 'demo-%'`
フィルターなので、絶対に広げないでください。

---

## HTTP サーフェス

| 状況 | レスポンス |
|---|---|
| `DEMO_MODE` が off | 404 `not-found`（ルートが無い場合と区別不能） |
| 未知の `{template}` | 404 `not-found` |
| IP ごとのスロットル超過 | 429 `too-many-requests` + `Retry-After` |
| デモ組織の上限到達 | 503 `demo-capacity-exceeded` |
| すべてのスラグ試行が衝突 | `SlugConflictException` がエスケープ → エラーミドルウェア経由で 500 |
| 成功 | シーターが返すもの（通常は 302 + `Cache-Control: no-store`） |

API クライアントは RFC 9457 Problem Details を受け取ります。ブラウザクライアント
（`Accept` に `text/html` を含む）は、同じステータスと `Retry-After` を保持したまま
ステップ 8 のエラーページを受け取ります。

## スイーパーが既に件数を抑えているのに、なぜ作成時にガードするのか

スイープだけでは**定常状態**しか抑えられません。スイープの合間に、クローラーや攻撃者は
テナントテーブルを際限なく増やせます — デモ開始のたびに組織 1 件とその全シードデータが
書き込まれるからです。`CountingDemoCapacityGuard` は、**何かが作られる前に**上限と
クライアントごとのレートをチェックすることでその隙間を塞ぎます。
