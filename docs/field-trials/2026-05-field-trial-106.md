# Field Trial 106 — ETag & Conditional Requests

**Date:** 2026-05-21
**Project:** `/home/xi/docker/NENE2-FT/etaglog/`
**NENE2 version:** 1.5.39
**Theme:** ETag ヘッダーによる条件付きリクエスト — コンテンツハッシュからの ETag 生成、`If-None-Match` による 304 Not Modified、`If-Match` による楽観的ロック（412 Precondition Failed / 428 Precondition Required）。`ConditionalGetHelper` と `ConditionalWriteHelper` の活用。

---

## What was built

記事の作成・取得・更新 API を実装した。

- `POST /articles` → 201 + `ETag` + `Last-Modified`
- `GET /articles/{id}` → 200 + ETag ヘッダー。`If-None-Match` が一致すれば 304 Not Modified
- `PATCH /articles/{id}` → `If-Match` 必須。一致なら 200、不一致なら 412、ヘッダーなしなら 428

---

## Findings

### 1. `ConditionalGetHelper` と `ConditionalWriteHelper` が既に NENE2 に存在（摩擦なし）

FT 開始前に `vendor/hideyukimori/nene2/src/Http/` を確認したところ、`ConditionalGetHelper` と `ConditionalWriteHelper` の両クラスが既に存在し、PHPDoc にサンプルコードまで添付されていた。

```php
// GET ハンドラ
$etag        = $article->etag();
$notModified = ConditionalGetHelper::check($request, $this->responseFactory, $etag, $article->updatedAt);
if ($notModified !== null) {
    return $notModified; // 304 with ETag + Last-Modified
}
return $this->json->create($this->serialize($article))
    ->withHeader('ETag', $etag)
    ->withHeader('Last-Modified', $article->updatedAt);
```

```php
// PATCH ハンドラ
$preconditionFailed = ConditionalWriteHelper::check($request, $this->problems, $article->etag());
if ($preconditionFailed !== null) {
    return $preconditionFailed; // 412 or 428
}
```

**評価:** 「どんな引数で呼べばいいか」「いつ呼ぶか」がサンプルと PHPDoc だけで分かる設計。

---

### 2. `ETag` の生成位置をどこに置くか（摩擦あり）

ETag をコントローラーで生成するか、エンティティに持たせるかで迷いがある。

```php
// ❌ コントローラーに散在するパターン
$etag = '"' . md5($article->title . $article->body . $article->updatedAt) . '"';

// ✅ Article クラスにメソッドとして閉じ込めるパターン
final readonly class Article
{
    public function etag(): string
    {
        return '"' . md5($this->title . $this->body . $this->updatedAt) . '"';
    }
}
```

Article クラスに `etag()` を持たせることで、ETag 生成ロジックの変更（例: SHA-256 への切り替え）が一箇所で済む。

---

### 3. ETag のフォーマット — ダブルクォートが必須

RFC 9110 では ETag 値は必ずダブルクォートで囲む必要がある:

```php
// ✅ 正しい
$etag = '"' . md5($content) . '"';

// ❌ クォートなし — RFC 違反、If-None-Match との比較が常に失敗する
$etag = md5($content);
```

`ConditionalGetHelper` は `$ifNoneMatch === $etag` の厳密比較を行うため、クォートがずれると 304 が返らなくなる。

---

### 4. `If-Match: *` ワイルドカード（知らないと落とし穴）

`ConditionalWriteHelper` は `If-Match: *` を「任意の既存リソース」として扱う:

```php
if ($ifMatch === '*') {
    return null; // precondition passed — caller checks for 404
}
```

これは RFC 9110 準拠の動作。クライアントが `*` を送れば ETag なしで条件付き書き込みができる。ただし「リソースが存在するか確認する責任はコントローラー側」という前提があり、コントローラーで事前に `findById()` をして 404 を返すパターンが必要。

---

### 5. `Last-Modified` を ISO 8601 形式にする

`ConditionalGetHelper` の `If-Modified-Since` チェックは文字列比較（`$ifModifiedSince >= $lastModified`）で行われる。そのため ISO 8601 形式（`2026-05-21T12:00:00Z`）のような辞書順で比較可能な形式を使う必要がある。HTTP標準の `Sat, 21 May 2026 12:00:00 GMT` 形式だと文字列比較が正しく動かない。

今回の FT は ISO 8601 を採用:

```php
$now = (new \DateTimeImmutable())->format('Y-m-d\TH:i:s\Z');
```

---

### 6. 304 はボディなし — PSR-7 では自動で保証されない

RFC 9110 では 304 レスポンスにボディを含めてはならない。NENE2 の `ConditionalGetHelper::notModified()` は空のボディを返す `createResponse(304)` を使うため問題なし。

テストで確認:

```php
$this->assertEmpty((string) $res->getBody(), '304 must have no body');
```

---

## Test results

15 tests, 37 assertions — all pass.

Key behaviors confirmed:
- POST → 201 with ETag and Last-Modified
- GET → 200 with ETag matching content hash
- GET with matching `If-None-Match` → 304 (empty body)
- GET with stale `If-None-Match` → 200 (full response)
- GET with no `If-None-Match` → 200
- GET with `If-None-Match` + matching `If-Modified-Since` → 304
- PATCH without `If-Match` → 428 Precondition Required
- PATCH with stale `If-Match` → 412 Precondition Failed
- PATCH with correct `If-Match` → 200, new ETag
- PATCH with `If-Match: *` → 200 (wildcard)
- ETag changes after update; old ETag returns 200 on next GET
- Old ETag no longer valid for PATCH after update → 412
- GET /404 → 404
- PATCH /404 → 404
- POST without body → 400

---

## Developer Experience (DX) Review

### ペルソナ1: 初心者（プログラミング歴1.5年・PHP 独学・女性・バックエンド志望）

**ETag の概念理解:** 「ETag って何？」から始まる。「ブラウザキャッシュの仕組みとして使われる識別子で、コンテンツが変わったか判定するもの」と説明されれば理解しやすいが、「なぜ 304 を返すのか」「ブラウザは 304 をもらったら何をするのか」という流れが見えないと混乱する。

**`ConditionalGetHelper` の発見:** クラス名が明確で PHPDoc に例が書いてあるため、一度発見すれば使える。ただし最初に「こういうヘルパーがあることを知る機会」がないと独力では見つけられない。howto があれば入口として機能する。

**ETag フォーマット:** ダブルクォートが必要なことを知らないと `md5(...)` だけを渡してしまい、If-None-Match との比較が常に失敗する。エラーメッセージも出ないため、「なぜ 304 が返らないのか」でハマる。

**事故リスク:** 高。クォートなし ETag は動作するが正しく動かないパターン。

---

### ペルソナ2: ロースキル経験者（PHP 歴4年・受託 Web 開発・男性・SES）

**コピペ可能性:** `ConditionalGetHelper::check()` と `ConditionalWriteHelper::check()` は引数が明確で、howto のサンプルをそのままコピーすれば動く。

**`ResponseFactoryInterface` の注入:** `ConditionalGetHelper` に `ResponseFactoryInterface` を渡す必要があることに気付きにくい。「$psr17 を渡せばいい」と分かれば問題ないが、DI コンテナに慣れていないとどこから持ってくるか迷う。

**If-Match vs If-None-Match の区別:** 「GET には If-None-Match、PATCH には If-Match」というルールが直感的でない。「条件付き GET には If-None-Match、条件付き書き込みには If-Match」と覚えれば分かるが、混同してバグを起こすリスクがある。

**事故リスク:** 中。コピペで正しく動いても GET に If-Match、PATCH に If-None-Match を使うという間違いがコンパイルエラーにならない。

---

### ペルソナ3: フロントエンド寄り経験者（React/TS 歴4年・フルスタック転向中・ノンバイナリ）

**クライアントサイドの理解:** ETag は HTTP キャッシュでよく使われるため「知っている」。fetch() でのハンドリングは `headers: { 'If-None-Match': etag }` の1行で済む。

**412 の取り扱い:** `If-Match` が一致しなかった場合の 412 はフロントで「楽観的ロック失敗」として扱う必要があり、「再 GET して ETag を取り直してから再送」というパターンを実装する必要がある。

**`If-Match: *` の発見:** ワイルドカードの存在はフロントエンド開発者には知られていないことが多く、howto に明記されていると助かる。

**事故リスク:** 低。HTTP ヘッダーの扱いは慣れている。ただし 304 のレスポンスボディが空であることを前提としない実装（`res.json()` を常に呼ぶなど）は落とし穴になる。

---

### ペルソナ4: バックエンド経験者（Laravel 歴6年・男性・リードエンジニア）

**他フレームワークとの差異:** Laravel では `response()->withHeaders(['ETag' => $etag])` のような手動実装か、`etag()` レスポンスマクロを使う。`ConditionalGetHelper` は NENE2 独自の静的ユーティリティだが、引数が明確で理解しやすい。

**`ConditionalWriteHelper` の 428:** Laravel での実装では `abort(412)` を直接呼ぶことが多いが、NENE2 は「`If-Match` なしで来たら 428 を返す」という厳格なデフォルトを持つ。オプションの `$require = false` で任意にできる設計は良い。

**ETag と楽観的ロックの関係:** ETag ベースの楽観的ロック（HTTP ヘッダー）と FT105 のバージョンフィールドベースの楽観的ロックを組み合わせることも可能。ETag が「表示レイヤーのキャッシュ」でバージョンが「DB レイヤーの競合検出」という役割分担が明確になると良い。

**事故リスク:** 低。

---

### ペルソナ5: シニアエンジニア（設計・コードレビュー担当・女性・12年）

**コードレビューポイント:**
1. ETag にダブルクォートが付いているか（RFC 9110 必須）
2. 304 レスポンスにボディが付いていないか
3. `ConditionalGetHelper` に渡す ETag と `withHeader('ETag', $etag)` で返す ETag が同一か（別々に生成すると不整合が起きる）
4. `ConditionalWriteHelper` を呼ぶのが書き込みの前か（後に呼んでも意味がない）
5. `If-Match: *` のワイルドカードを正しく扱っているか（存在確認は呼び出し元の責任）

**パフォーマンス考慮:** ETag を毎回コンテンツハッシュで計算するのは DB から取得後のメモリ操作なので低コスト。DB クエリ数は変わらない。`updated_at` だけで ETag を作るとコンテンツ変更なしのタッチで 304 を返さなくなるトレードオフがある。

**スケール時の問題:** 複数インスタンス構成でも ETag はコンテンツから決定的に生成されるため、インスタンス間で整合する。バージョン番号ベースの ETag より優れている点。

---

### ペルソナ6: 設計者・ポリシー照合（NENE2 設計ポリシー目線）

**ポリシー整合:**
- `ConditionalGetHelper` と `ConditionalWriteHelper` の静的メソッド設計は「フレームワークマジックでコントロールフローを隠さない」方針と整合
- `check()` の戻り値が `?ResponseInterface` という設計は「early return でシンプルに書ける」パターンを促進

**設計上のギャップ:**
1. `Last-Modified` の比較が文字列順序比較のため、ISO 8601 形式以外では壊れる — howto に明記が必要
2. ETag をエンティティクラスに `etag()` メソッドとして持たせるパターンは推奨すべき
3. `ConditionalWriteHelper` の `$require = false` の使いどころが howto に欠けている

---

## Issues / PRs

- Issue: `docs/howto/etag-conditional-requests.md` — ETag 生成・304 条件判定・If-Match 楽観的ロック・Last-Modified フォーマット注意・ワイルドカード
