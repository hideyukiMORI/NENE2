# Field Trial 15 — noteboard: HTML View 層の実地検証

## Date

2026-05-20

## Baseline

- NENE2 v1.5.0（`hideyukimori/nene2: ^1.5`）
- PHP 8.4
- プロジェクト: **noteboard** — シンプルなメモ共有ボード
- エンティティ: `Note`（1 ドメイン、HTML 3ルート + JSON API 3ルート）
- テスト: PHPUnit 13、PHPStan level 8、PHP-CS-Fixer
- DB: SQLite（ローカル）
- フロントエンド: なし（サーバーレンダリング HTML のみ）

## Goal

`HtmlResponseFactory` + `NativePhpViewRenderer` を使ったサーバー HTML レンダリング層を実地検証する。
同一アプリに JSON API と HTML ルートを共存させ、NENE2 の「薄い HTML」方針が実用に耐えるかを確認する。

---

## Steps Taken

### 1. HtmlResponseFactory と NativePhpViewRenderer のセットアップ

```php
$html = new HtmlResponseFactory(
    $psr17,
    $psr17,
    new NativePhpViewRenderer(dirname(__DIR__) . '/templates'),
);
```

`NativePhpViewRenderer` にはテンプレートルートディレクトリを渡す。以降、テンプレートパスはこのルートからの相対パスで指定する（例: `'notes/index.php'`）。

### 2. ハンドラー実装

HTML ハンドラーは JSON ハンドラーと対称的な構造で記述できる。

```php
final readonly class NoteListHandler
{
    public function __construct(
        private HtmlResponseFactory $html,
        private NoteRepository $notes,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        return $this->html->create('notes/index.php', ['notes' => $this->notes->all()]);
    }
}
```

`HtmlResponseFactory::create()` の第 2 引数に渡した連想配列は、テンプレート内でそのままローカル変数として展開される。

### 3. ネイティブ PHP テンプレート

テンプレートでは XSS エスケープヘルパー `$e()` が自動注入される。

```php
<?php foreach ($notes as $note): ?>
  <li><a href="/notes/<?= $e($note->id) ?>"><?= $e($note->title) ?></a></li>
<?php endforeach; ?>
```

`$e()` は `htmlspecialchars()` のショートカット。戻り値を出力する際は必ず使用する。

### 4. HTML + JSON API のルート共存

```php
$router->get('/notes',       new NoteListHandler($html, $noteRepo));
$router->get('/notes/{id}', new NoteShowHandler($html, $noteRepo, $problems));
$router->get('/api/notes',       new NoteListApiHandler($json, $noteRepo));
$router->post('/api/notes',      new NoteCreateApiHandler($json, $problems, $noteRepo));
$router->get('/api/notes/{id}', new NoteShowApiHandler($json, $problems, $noteRepo));
```

HTML ルートと API ルートを `/notes/*` と `/api/notes/*` で明示的に分けることで、ルーティングテーブルが読みやすく保たれる。NENE2 のルーターはシンプルな完全一致 + プレースホルダーのみのため、この規約は特に有効。

---

## Findings

### F-1: `HtmlResponseFactory` の DI が直感的で摩擦ゼロ [情報]

`JsonResponseFactory` と同様のコンストラクタシグネチャで、既存の JSON ハンドラーパターンをそのまま転用できた。`NativePhpViewRenderer` の構築も 1 行で完結する。ドキュメントなしで実装できた。

---

### F-2: `$e()` ヘルパーが howto に記載されていない [中]

テンプレート内の XSS エスケープが `$e()` で行えることを、ソースコードを読むまで把握できなかった。`add-html-view.md` howto はすでに追加されているが、`$e()` の存在と使い方に関する記述が薄い。

**提案**: `add-html-view.md` に `$e()` の説明と、うっかり生出力した場合の XSS リスクを明示するセクションを追加する。

---

### F-3: HTML ルートで 404 を返す場合に Problem Details が混在する [低]

`NoteShowHandler` で存在しない Note を参照した際、`ProblemDetailsResponseFactory` を使って `application/problem+json` のレスポンスを返した。HTML ビューでこのレスポンスを受け取ったブラウザはプレーンな JSON を表示する。

```php
return $this->problems->create($request, 'not-found', 'Not Found', 404, "Note {$id} not found.");
```

API クライアントにとっては問題ないが、HTML ユーザー向けには HTML の 404 エラーページが望ましい。

**解決策（暫定）**: `NotFoundHandler` を HTML テンプレートで返す専用クラスとして用意し、HTML ルートでは `$problems` の代わりに使用する。

**提案**: NENE2 に `HtmlErrorPage` ユーティリティを追加するか、`ErrorHandlerMiddleware` が HTML リクエスト（`Accept: text/html`）をハンドルする拡張ポイントを提供する。

---

### F-4: テストは `assertStringContainsString` でシンプルに書ける [情報]

HTML エンドポイントのテストは `Content-Type` と本文の部分一致で十分に品質を担保できる。

```php
public function testNoteListHtmlShowsNotes(): void
{
    $this->noteRepo->create('Hello', 'World content');
    $response = $this->app->handle(new ServerRequest('GET', '/notes'));

    self::assertSame(200, $response->getStatusCode());
    self::assertStringContainsString('text/html', $response->getHeaderLine('Content-Type'));
    self::assertStringContainsString('Hello', (string) $response->getBody());
}
```

ルーター → ハンドラー → テンプレートの全段を 1 テストで通せるため、結合度の高いアサーションが自然に書ける。HTML の DOM 構造を細かく検証する必要はほとんどなかった。

---

### F-5: `NativePhpViewRenderer` のパスが相対解決のみ [低]

テンプレートルートを変えたい場合（例: テスト用に別ディレクトリを指定したい）、`NativePhpViewRenderer` のコンストラクタに別のルートを渡せば対応できる。ただし、テンプレートファイル名が存在しない場合の例外メッセージが汎用的で、デバッグ時に原因を特定しにくかった。

**提案**: テンプレートファイルが見つからない場合に「テンプレートルート: ${templateRoot}/${path} が見つかりません」という明確なメッセージを出力する。

---

## Summary

| 項目 | 結果 |
|---|---|
| `HtmlResponseFactory` セットアップ | 摩擦なし ✓ |
| `$e()` XSS エスケープ | 動作するが発見性が低い △ |
| HTML + JSON API 共存 | クリーンに分離できる ✓ |
| HTML エラーハンドリング | Problem Details JSON が混在する △ |
| HTML テスト | assertStringContainsString で十分 ✓ |

NENE2 の HTML レンダリング層は最小限の API で実装できる。`$e()` の発見性とエラーページの HTML 化が次の改善候補。
