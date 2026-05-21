# Field Trial Report — FT123: Personal Data Export

**Date**: 2026-05-21
**Release**: v1.5.57
**App**: `exportlog` (`/home/xi/docker/NENE2-FT/exportlog/`)
**Tests**: 19/19 passed (18 functional + 1 vulnerability fix verification)
**PHPStan**: level 8, 0 errors
**CS**: clean
**Vulnerability Assessment**: 1 finding fixed

## Theme

Implement a GDPR-style personal data export system: users request an export, a worker processes it (building the payload), and the user downloads via a random opaque token. Key concerns: sensitive field exclusion, token entropy, and expiry enforcement at all stages.

FT123 is 3rd in the FT121/FT122/FT123 cycle → **vulnerability assessment**.

## Vulnerability Assessment

### V1 — Fixed: processExport silently processes expired export requests

`POST /exports/{token}/process` did not check `expires_at` before building the payload and writing it to the database. A worker processing a stale job would:
1. Write the user's personal data into the `payload` column
2. Set `status = 'ready'`
3. Leave the record in DB permanently (with sensitive data) — but the download endpoint returns 410, so it is never served

This creates orphaned records containing personal data that can never be retrieved and never expires on its own. In a DB dump or breach scenario, this data would be exposed even past its intended expiry window.

**Fix**: Added expiry check at the start of `processExport` — returns 410 before any payload is written.

```php
if ($export->isExpired($now)) {
    return $this->problems->create($request, 'gone',
        'Export request has expired. Please request a new export.', 410, '');
}
```

### V2 — PASS: Sensitive field exclusion

`ExportRepository::processExport()` explicitly excludes `password_hash` and `phone` from the payload JSON. `User::toPublicArray()` also excludes both fields from the profile endpoint. Tests verify both.

### V3 — PASS: Token entropy

`bin2hex(random_bytes(32))` = 64 hex chars = 256 bits. Not guessable or enumerable.

### V4 — PASS: Expiry enforcement on download

`downloadExport` calls `$export->isExpired($now)` before serving the payload. Expired exports return 410 Gone.

### V5 — Design note: process endpoint is unauthenticated

`POST /exports/{token}/process` is exposed publicly for testability. In production this must be restricted to internal workers. The token's 256-bit entropy provides some protection but is not a substitute for proper access control on internal endpoints.

## Test coverage (19 tests)

| Category | Tests |
|---|---|
| User registration (valid, missing field, duplicate email) | 3 |
| Profile: returns 200, excludes password_hash, excludes phone, 404 | 4 |
| Export request: 202 with token, token entropy check, 404 for missing user | 3 |
| Process: marks ready, 404 for missing token | 2 |
| Process expired export → 410 (vuln fix) | 1 |
| Download: ready (200), payload has email/name, no password_hash, no phone | 4 |
| Download: pending → 409, not found → 404, expired → 410 | 3 |

## Developer Experience (DX) Review

### ペルソナ1: 初心者（プログラミング歴2年・PHP独学・男性・バックエンド志望）

**「なんで process が別エンドポイントなの？」:** 「リクエストしたらすぐデータが返ってくる」と思いがち。非同期処理の必要性（重いデータ収集・タイムアウト回避）が説明なしには伝わらない。「Webサービスでダウンロードリンクをメールで送る経験」があれば理解が早い。

**機密フィールドの除外を忘れる:** `toArray()` を書くとき全フィールドを返してしまう。「DB の全カラムをそのままレスポンスにする」癖がある初心者は `password_hash` や `phone` の除外を後付けで気づく。`toPublicArray()` と内部向け `toArray()` を最初から分けて命名するパターンで防げる。

### ペルソナ2: ロースキル経験者（PHP歴3年・受託Web・女性・SES）

**token に user_id を使いがち:** `$token = "export-{$userId}-" . time()` のような実装を書きがち。「ランダムでないとなぜ問題か」を IDOR（他人のエクスポートをDLできる）で説明すると納得する。

**期限切れチェックの抜け:** `downloadExport` には `isExpired()` を書いても、`processExport` の同じチェックが「なぜ必要か」ピンとこない。「孤児レコードにPIIが残る」という説明で腹落ちする。

### ペルソナ3: フロントエンド寄り経験者（React/TS歴5年・フルスタック転向中・ノンバイナリ）

**ポーリングUI:** 「export リクエスト → 202 Accepted → ポーリングで status をチェック → ready になったら DL」というフローは SWR や React Query との相性が良い。ただし `GET /exports/{token}` でステータスだけ取るエンドポイントを別途用意した方が DL と分離できる。

**ダウンロードリンク:** `token` をクエリパラメータにするか URL パスにするかで URLの見た目が変わる。パスに含める `/exports/{token}` はブラウザのアドレスバーに token が表示されるが、HTTPS 前提なら問題ない。

### ペルソナ4: バックエンド経験者（Laravel歴5年・男性・シニア開発）

**S3 との比較:** 本番では payload をDBに入れず S3 に書いて presigned URL を返す設計が多い。DB は大きな blob を持つのが苦手で、エクスポートが増えると rows が重くなる。このFTの DB 格納はシンプルさ優先のデモ設計であることをドキュメントに明記すべき。

**process エンドポイントの認証:** Laravel なら Artisan コマンドやキューワーカーで内部実行する。NENE2 の場合も本番では API キー認証付きの内部エンドポイントか、直接リポジトリを呼ぶワーカースクリプトにすべき。

### ペルソナ5: シニアエンジニア（設計・コードレビュー担当・ノンバイナリ・15年）

**コードレビューポイント:**
1. `processExport` の `isExpired()` チェックは V1 で追加されたが、最初から両エンドポイントに入れるべきだった。expiry チェックは「書き込み操作でも必要」という原則
2. `token` が `DataExport::toArray()` に含まれていること — process レスポンスにも token が返るが、process を呼ぶ worker はすでに token を知っているので情報漏洩ではない。ただし内部ログに token が出ないよう注意
3. 同一ユーザーが短時間に大量のエクスポートリクエストを送れる — rate limiting が必要

**孤児レコードのパージ:** expires_at を過ぎた `data_exports` レコードは定期的に DELETE するべき。PII を含む payload が DB に残り続けることは GDPR 的に問題。

### ペルソナ6: 設計者・ポリシー照合（NENE2設計ポリシー目線）

**ポリシー整合:**
- `User::toPublicArray()` と `processExport()` での除外は「センシティブ情報をレスポンスに含めない」NENE2 ポリシーと整合
- `DataExport::isExpired()` をレコード側に置く設計は「ドメインロジックを HTTP から独立」させる方針と整合
- 410 vs 404 の使い分け（expired → 410, not found → 404）は RFC 7231 に準拠

**課題: process エンドポイントの認可がない** — NENE2 の API キー認証（`X-NENE2-API-Key`）を使えば内部ワーカーのみアクセス可能にできる。本番利用時の必須対応として howto に記載済み。

## Issues / PRs

- Issue #748: このトライアルの起票 → `docs/howto/personal-data-export.md` で解消
