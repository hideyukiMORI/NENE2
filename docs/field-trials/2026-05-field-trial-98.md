# Field Trial 98 — File Upload (Base64 JSON)

**Date:** 2026-05-20
**Project:** `/home/xi/docker/NENE2-FT/uploadlog/`
**NENE2 version:** 1.5.31
**Theme:** ファイルアップロード — MIME バリデーション・サイズ制限・パストラバーサル防止

---

## What was built

アバター/ファイルアップロード API。NENE2 は JSON ファーストなため、ファイルはリクエストボディに base64 エンコードして送信。`finfo_buffer()` でバイト列から MIME を検出し、許可リストと照合。サイズ・拡張子・パストラバーサルを検証した。

---

## Findings

### 1. `requestMaxBodyBytes` デフォルト 1 MiB が base64 ファイルアップロードを遮断する（高: 驚きが大きい）

**Symptom:** 3 MiB のファイルを base64 エンコードして送信すると、`FileValidator` に到達する前に NENE2 の `RequestSizeLimitMiddleware` が 413 を返す。

**Root cause:** base64 エンコードはデータ量を約 33% 増やす。2 MiB のファイル → ~2.7 MiB の base64 文字列 → JSON エンベロープを含む ~2.7 MiB のリクエストボディ。NENE2 のデフォルト上限 1 MiB を超える。

**必要な対応:**

```php
// RuntimeApplicationFactory の requestMaxBodyBytes を拡大する
new RuntimeApplicationFactory(
    $psr17,
    $psr17,
    routeRegistrars:     [...],
    requestMaxBodyBytes: 10 * 1024 * 1024,  // 許容ファイルサイズ × 1.4 以上
)
```

base64 オーバーヘッドの計算式:

| ファイル上限 | 必要な requestMaxBodyBytes |
|---|---|
| 1 MiB | ~1.4 MiB |
| 2 MiB | ~2.8 MiB |
| 10 MiB | ~14 MiB |

**DX観点 (初心者目線):** 「2 MiB まで許可する」と実装したのに、フレームワークが「Request body too large」で先に弾く。自分のバリデーションが呼ばれているのか分からず、なぜ 413 なのか途方に暮れる。`requestMaxBodyBytes` とファイルサイズの関係をドキュメントに明記する必要がある。

---

### 2. NENE2 にファイルアップロードのヘルパーが一切ない（中: 実装量が多い）

NENE2 は JSON-only フレームワークなので当然だが、以下を全て自前実装する必要がある:

- base64 デコード + `finfo_buffer()` による MIME 検出
- ファイルサイズ検証（デコード後のバイト数）
- ファイル名サニタイズ（パストラバーサル・null バイト・危険な拡張子）
- ストレージへの書き込み
- DB メタデータ管理

`FileValidator` クラスを実装する工数は決して小さくない。

**DX観点:** 「NENE2 でファイルアップロードをするには？」という問いに答えるドキュメントが存在しない。初心者は何から手を付ければいいかわからない。

---

### 3. パストラバーサル防止に `basename()` だけでは不十分（高: セキュリティ）

`basename('../../../etc/passwd')` は `'passwd'` を返す — ディレクトリ部分は取り除かれる。しかし:

- **null バイト:** `"image.png\x00.php"` → `basename()` の結果に `.php` が残る（PHP は null バイト以降を切り捨てない）
- **Windows パス区切り (`\`):** `basename('..\\..\\Windows\\evil.png')` → OS によって挙動が異なる
- **危険な拡張子:** `.php` などはコンテンツ検証で弾かれるが、ストレージ上のファイル名に残るとサーバー設定によっては実行される可能性がある

**必要な対応（実装済み）:**

```php
$name = basename($filename);
$name = str_replace("\x00", '', $name);    // null バイト除去
$name = ltrim($name, '.');                 // 先頭ドット除去
$name = preg_replace('/[^\w\-.]/', '_', $name);  // 特殊文字をアンダースコアに
// 危険な拡張子を無害化 (.php → _php)
if (in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), self::DANGEROUS_EXTENSIONS, true)) {
    $name = pathinfo($name, PATHINFO_FILENAME) . '_' . pathinfo($name, PATHINFO_EXTENSION);
}
```

**DX観点:** パストラバーサル防止の「正しい実装」を初心者が自力で揃えるのは難しい。穴が多く、見落としやすい。howto があれば助かる。

---

### 4. MIME 検出は `finfo_buffer()` で実際のバイト列から行う必要がある（摩擦なし、正しい設計）

クライアントが送る `Content-Type` やファイル拡張子を信頼してはいけない。PHP `<?php system(...); ?>` を `image.jpg` として送っても、`finfo_buffer()` は `text/x-php`（または `text/plain`）と検出する。

```php
$finfo = new \finfo(FILEINFO_MIME_TYPE);
$mime  = $finfo->buffer($bytes);  // 実際のバイト列で判定
```

テスト確認:
- PHP スクリプト + `filename: "avatar.jpg"` → `unsupported-mime-type` で 422
- 1×1 JPEG + `filename: "totally.txt"` → 201（MIME は content から判定）

**DX観点:** `finfo_buffer()` の存在を知らない初心者は拡張子やクライアント送信の `Content-Type` を信頼してしまう。howto で強調すべき。

---

### 5. `base64_encode('')` = `''` — RouteRegistrar レイヤーで 'required' エラーになる（低）

`FileValidator` に `empty-file` チェックを実装したが、空ファイルを送るには `content: ""` を送るしかなく、RouteRegistrar が先に 'required' エラーとして弾く。`FileValidator::validate()` 内の空ファイルチェックはユニットテストでのみ到達可能な防衛的コード。API 設計と内部実装の整合性の小さなズレ。

---

### 6. `Exception::$code` は readonly にできない — プロパティ名の衝突（低）

```php
// Fatal error: Cannot redeclare non-readonly property Exception::$code
// as readonly UploadValidationException::$code
final class UploadValidationException extends \InvalidArgumentException {
    public function __construct(
        public readonly string $code,  // ← Exception::$code と衝突
    ) {}
}
```

`$errorCode` に改名して回避。PHP の組み込み例外クラスのプロパティ名（`$code`, `$message`, `$file`, `$line`）を readonly プロパティとして再宣言できない。初見では戸惑う。

---

## Test results

19 tests, 44 assertions — all pass.

Key behaviors confirmed:
- JPEG / PNG 正常アップロード → 201、ファイルがストレージに保存
- テキスト・PDF・PHP スクリプト → 422 `unsupported-mime-type`
- 3 MiB ファイル（デコード後）→ 422 `file-too-large`（requestMaxBodyBytes 10 MiB に設定）
- `../../../etc/passwd` → sanitize → 201、ストレージ外に書き込まれない
- `image.png\x00.php` → 422（PHP スクリプト内容のため MIME 拒否）
- `...` (dots only filename) → sanitize → 201
- Windows パストラバーサル `..\\Windows\\evil.png` → sanitized → 201

---

## Developer Experience (DX) Review

### 初心者・ロースキル観点での実装しやすさ

難しい。MIME 検出・パストラバーサル防止・base64 サイズ計算・requestMaxBodyBytes 調整など、知っていなければ正しく実装できない落とし穴が多い。各パーツは難しくないが、組み合わせの正しさを初心者が保証するのは難しい。

### 使ってみた印象

NENE2 が JSON ファーストであることは理解しているが、「base64 のオーバーヘッドで requestMaxBodyBytes を拡大しなければならない」という非自明なステップが最初にある。エラーメッセージも「Request body too large」という汎用メッセージで、ファイルアップロード文脈だと困惑する。

### 楽しいか・気持ちいいか・快適か

PHP の `finfo_buffer()` + `basename()` + `preg_replace()` の組み合わせで要件を満たせるのは良い。ただし「これで本当に安全か？」という不安が残る。セキュリティ上の落とし穴が多いドメインなので、公式 howto の安心感が欲しい。

### 簡単か

いいえ。MIME 検出・サイズ制限・パストラバーサルの三段構えを初心者が正しく実装するのは難しい。

### また使いたいか

はい。ただし howto ドキュメントが整備されれば、という条件付き。

### 初心者に勧めたいか

ファイルアップロード機能が必要な場合は、まず howto を用意してから勧める。現状では落とし穴が多すぎる。

---

## Issues / PRs

- Issue: `docs/howto/file-upload.md` — base64 JSON 方式・requestMaxBodyBytes 計算・finfo 検出・パストラバーサル防止の完全実装例
- Issue: `requestMaxBodyBytes` と base64 ファイルサイズの関係をドキュメント化（誤解が起きやすい箇所）
