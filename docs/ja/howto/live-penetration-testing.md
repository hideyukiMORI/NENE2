# ハウツー: ライブコンテナペネトレーションテスト

このガイドは、NENE2 アプリケーションに対する敵対的なライブコンテナペネトレーションテストの実行方法を — セットアップから 30 の攻撃フェーズすべてまで — 文書化し、v1.5.329 のテストセッション（2026-05-31、150 件以上のケース）からの正準の結果を記録します。

このテストは**クラッカー視点**を採用します: 攻撃者はソースコードへの完全なアクセス（ホワイトボックス）を持ち、すべての公開ドキュメントを読み、諦める前にあらゆる既知の攻撃クラスを試すと仮定します。

---

## 前提条件

- Docker Compose が利用可能（`docker compose version`）
- ホストに `curl`、`nc`（netcat）、`openssl`、`python3` がインストールされている
- テスト用認証情報を持つ稼働中の NENE2 コンテナ

---

## 1. コンテナのセットアップ

隔離されたテストターゲットを起動します。専用ポート（本番ポートは決して使わない）を使い、テスト用認証情報を注入します:

```bash
# PHP ビルトインサーバーターゲット — 最速で起動でき、生の NENE2 の振る舞いをテストする
NENE2_MACHINE_API_KEY=pentest-key docker compose run -d --rm \
  -e NENE2_LOCAL_JWT_SECRET=pentest-jwt-secret-32chars-min!! \
  -e APP_ENV=local \
  -e APP_DEBUG=false \
  -p 8299:80 \
  app php -S 0.0.0.0:80 -t public_html/

# Apache ターゲット — Apache 設定のハードニングを含むフルスタックをテストする
NENE2_MACHINE_API_KEY=pentest-key docker compose up -d app
# :8200 で利用可能（CLAUDE.md §8 のポートレジストリ参照）
```

ベースラインのスモークチェック:

```bash
curl -si http://localhost:8299/
# 期待: 200 OK、セキュリティヘッダーあり、Server/X-Powered-By なし
```

OpenAPI から攻撃面を列挙する:

```bash
curl -s http://localhost:8299/openapi.php | grep -E "^  /"
# → /, /health, /machine/health, /examples/protected,
#   /examples/notes, /examples/notes/{id}, /examples/tags, /examples/tags/{id}
```

コンテナ内でテスト用認証情報を生成する:

```bash
CID=$(docker ps --filter "publish=8299" --format "{{.ID}}")
VALID_JWT=$(docker exec $CID php -r "
  require 'vendor/autoload.php';
  \$v = new Nene2\Auth\LocalBearerTokenVerifier('pentest-jwt-secret-32chars-min!!');
  echo \$v->issue(['sub'=>'tester','exp'=>time()+86400]);
")
```

---

## 2. 攻撃フェーズ

### フェーズ 1 — JWT アルゴリズム混同

| ID | Attack | Expected | v1.5.329 |
|----|--------|----------|----------|
| J-01 | `alg:none`（空の署名） | 401 | ✅ BLOCKED |
| J-02 | `alg:NONE`（大文字） | 401 | ✅ BLOCKED |
| J-03 | `alg:None`（大小混在） | 401 | ✅ BLOCKED |
| J-04 | `alg:hs256`（小文字） | 401 | ✅ BLOCKED |
| J-05 | `alg:RS256`（鍵混同） | 401 | ✅ BLOCKED |
| J-06 | `alg` フィールドなし | 401 | ✅ BLOCKED |
| J-07 | `kid: ../../etc/passwd` | 200（有効な署名） | ✅ SAFE — 余分なヘッダーフィールドは無視 |
| J-08 | `jku: http://evil.com` | 200（有効な署名） | ✅ SAFE — JWK フェッチなし |

```bash
# J-01: alg:none
H=$(echo -n '{"typ":"JWT","alg":"none"}' | base64 -w0 | tr '+/' '-_' | tr -d '=')
P=$(echo -n '{"sub":"admin","exp":9999999999}' | base64 -w0 | tr '+/' '-_' | tr -d '=')
curl -si -H "Authorization: Bearer $H.$P." http://localhost:8299/examples/protected
# → 401  detail: "Token algorithm must be HS256."
```

### フェーズ 1b — JWT ペイロード改ざん

| ID | Attack | Expected | v1.5.329 |
|----|--------|----------|----------|
| J-09 | `exp: 0`（エポック 1970） | 401 expired | ✅ BLOCKED |
| J-10 | `exp: null` | 401 must be numeric | ✅ BLOCKED |
| J-11 | `exp: "never"` | 401 must be numeric | ✅ BLOCKED |
| J-12 | `exp: 9999999999.9`（float） | 401 must be numeric | ✅ BLOCKED |
| J-13 | ペイロードが JSON 配列 | 401 must be numeric | ✅ BLOCKED |
| J-14 | Bearer 値内のダブルスペース | 401 | ✅ BLOCKED |
| J-15 | Bearer スキームなし | 401 | ✅ BLOCKED |
| J-16 | 4 セグメントトークン（余分なドット） | 401 format invalid | ✅ BLOCKED |
| J-17 | ヘッダー + ペイロードのみ（署名なし） | 401 | ✅ BLOCKED |

> **重要な不変条件**: `exp` は存在する整数でなければならない — 不在または誤った型は拒否される（v1.5.329 で修正）。

### フェーズ 2 — SQL インジェクション

すべてのリポジトリは `?` プレースホルダーのパラメーター化クエリを使用します。生の文字列補間はありません。

| ID | Attack | Expected | v1.5.329 |
|----|--------|----------|----------|
| S-01 | title 内のクラシックな `' OR 1=1--` | 201（リテラルとして保存） | ✅ SAFE |
| S-02 | `UNION SELECT 1,2,3--` | 201（リテラルとして保存） | ✅ SAFE |
| S-03 | ブール盲目的 `AND 1=1--` | 201（リテラルとして保存） | ✅ SAFE |
| S-04 | 時間ベース `AND SLEEP(2)--` | <50ms で 201 | ✅ SAFE — SLEEP は実行されない |
| S-05 | パスパラメーター SQLi `/notes/1' OR '1'='1` | 200（int キャスト → 1） | ✅ SAFE |
| S-06 | ヌルバイト `\0' OR '1'='1` | 201（リテラル） | ✅ SAFE |
| S-07 | セカンドオーダー: ペイロードを保存してから読む | 200（リテラルとして再読込） | ✅ SAFE |
| S-08 | ボディフィールド内の SLEEP(5) | <50ms で 201 | ✅ SAFE |
| S-10 | クエリ文字列内の `limit=UNION SELECT...` | 422（バリデーション） | ✅ SAFE |

```bash
# パラメーター化クエリを検証: SLEEP は実行されない
time curl -si -X POST -H "Content-Type: application/json" \
  -d '{"title":"x'\'' AND SLEEP(3)--","body":"x"}' \
  http://localhost:8299/examples/notes
# → < 100ms で 201（SLEEP は実行されなかった）
```

### フェーズ 3 — パストラバーサル / LFI / PHP ラッパー

| ID | Attack | Expected | v1.5.329 |
|----|--------|----------|----------|
| P-01 | `../../etc/passwd` | 404 | ✅ BLOCKED |
| P-02 | URL エンコードされた `%2e%2e%2f` バリアント（5 形式） | 404 | ✅ BLOCKED |
| P-03 | 二重エンコードの `%252e%252e` | 404 | ✅ BLOCKED |
| P-04 | UTF-8 オーバーロング `%c0%ae` | 404 | ✅ BLOCKED |
| P-05 | `php://input` / `php://filter` / `data://` | 404 | ✅ BLOCKED |
| P-06 | `{id}` パラメーター経由の LFI | 404 | ✅ BLOCKED |
| P-07 | ヌルバイト `1%00.html` | 200（int キャスト → 1） | ✅ SAFE — id=1 の DB レコードが返る |
| P-08 | Apache 上の `.htaccess` | 403 | ✅ BLOCKED |
| P-08b | PHP ビルトインサーバー上の `.htaccess` | **200** | ⚠️ EXPOSED（VULN-01 参照） |
| P-09 | `.git/HEAD` | 404 | ✅ BLOCKED |
| P-10 | バックアップファイル（`.bak`、`.swp`、`~` など） | 404 | ✅ BLOCKED |

### フェーズ 4 — HTTP プロトコル攻撃

| ID | Attack | Expected | v1.5.329 |
|----|--------|----------|----------|
| H-01 | CL.TE リクエストスマグリング | レスポンスなし（PHP ビルトインがブロック） | ✅ |
| H-02 | TE.CL スマグリング | 405（root メソッド不一致） | ✅ |
| H-03 | TE.TE 難読化 Transfer-Encoding | レスポンスなし | ✅ |
| H-04 | HTTP/1.0 ダウングレード | 200（正しいボディ） | ✅ |
| H-05 | 絶対 URI プロキシ悪用 | 404 | ✅ |
| H-06 | HTTP ヘッダーフォールディング | 500（PHP ビルトインのバグ） | ⚠️ VULN-02 |
| H-07 | HTTP パイプライニング | レスポンスがインターリーブ | ✅ SAFE |
| H-08 | 100 個の同時カスタムヘッダー | 200 | ✅ SAFE |
| H-10 | WebSocket アップグレード | 200（アップグレードは無視） | ✅ SAFE |
| H-12 | 無効な HTTP バージョン（`HTTP/9.9`） | 200（PHP ビルトインは受理） | ✅ SAFE |

### フェーズ 5 — マスアサインメント / IDOR / ビジネスロジック

| ID | Attack | Expected | v1.5.329 |
|----|--------|----------|----------|
| B-01 | マスアサインメント（ボディ内の `id`、`__proto__`） | 201（余分なフィールドは無視） | ✅ SAFE |
| B-02 | IDOR: 別ユーザーの note を DELETE | 204 | ℹ️ 想定どおり（examples に所有権なし） |
| B-04 | 負 / ゼロの ID | 404 | ✅ SAFE |
| B-05 | 整数オーバーフロー ID | 404 | ✅ SAFE |
| B-06 | DELETE してから同じ ID に再アクセス | 404 | ✅ SAFE |
| B-07 | 並行 DELETE レース | すべて 404（冪等） | ✅ SAFE |
| B-08 | 1MB 上限のボディ | 413 | ✅ BLOCKED |

### フェーズ 6 — API キーバイパス

| ID | Attack | Expected | v1.5.329 |
|----|--------|----------|----------|
| A-01 | キーなし | 401 | ✅ BLOCKED |
| A-02 | クエリ文字列内のキー（`?key=`、`?api_key=`） | 401 | ✅ BLOCKED |
| A-03 | リクエストボディ内のキー | 401 | ✅ BLOCKED |
| A-04 | ヘッダー名のケースバリエーション | 200（PSR-7 が正規化） | ✅ SAFE |
| A-05 | 値の前後の空白 | 200（PSR-7 がトリム） | ✅ SAFE |
| A-06 | `//machine/health` 二重スラッシュ | キーなしで 401、ありで 200 | ✅ SAFE |
| A-07 | `X-Original-URL` / `X-Rewrite-URL` | 200（ヘッダーは無視） | ✅ SAFE |
| A-08 | OPTIONS プリフライトバイパス | 405 | ✅ BLOCKED |
| A-09 | HEAD メソッド | 401 | ✅ BLOCKED |
| A-10 | 一般的なパスワードのブルートフォース | すべて 401 | ✅ BLOCKED |
| A-11 | URL エンコードされたパス（`%6Dachine`） | 404 | ✅ BLOCKED |

```bash
# タイミング攻撃: hash_equals 使用 → 定数時間比較
time (for i in $(seq 1 10); do
  curl -so /dev/null -H "X-NENE2-API-Key: a" http://localhost:8299/machine/health
done)
time (for i in $(seq 1 10); do
  curl -so /dev/null -H "X-NENE2-API-Key: pentest-key" http://localhost:8299/machine/health
done)
# → 10 リクエストでタイミング差 < 5ms: SAFE
```

### フェーズ 7 — インジェクション / XSS / SSTI / コード実行

| ID | Attack | Expected | v1.5.329 |
|----|--------|----------|----------|
| I-01 | XSS `<script>alert(1)</script>` を保存 | 201、JSON 文字列として返る | ✅ SAFE — JSON エンコーディングが無効化 |
| I-02 | SSTI `{{7*7}}` / `${7*7}` | 201、リテラルとして保存 | ✅ SAFE — テンプレートエンジンなし |
| I-03 | PHP `<?php system("id"); ?>` | 201、リテラルとして保存 | ✅ SAFE |
| I-04 | Log4Shell `${jndi:ldap://...}` | 200（ヘッダーは無視） | ✅ SAFE — Java ではなく PHP |
| I-05 | 1000 階層のネストした JSON | 400（PHP パース制限） | ✅ BLOCKED |
| I-06 | Unicode BiDi 制御文字 | 201（保存） | ✅ SAFE — 表示のみのリスク |
| I-07 | 重複した JSON キー | 最後の値が勝つ（PHP の振る舞い） | ℹ️ INFO-01 |

> **格納型 XSS に関する注意**: XSS ペイロードは保存され、JSON レスポンスでそのまま返されます。API は JSON 専用（`Content-Type: application/json` + `X-Content-Type-Options: nosniff`）であるため、ブラウザはスクリプトを実行しません。リスクが顕在化するのは、別のアプリケーションがこのデータをエスケープせずに HTML コンテキストでレンダリングした場合のみです。

### フェーズ 8 — デシリアライゼーション / PHP オブジェクトインジェクション

| ID | Attack | Expected | v1.5.329 |
|----|--------|----------|----------|
| D-01 | パスパラメーター内の `phar://` ラッパー | 404 | ✅ BLOCKED |
| D-02 | PHP `O:8:"stdClass":...` シリアライズペイロード | 400（無効なボディ） | ✅ BLOCKED |
| D-03 | シリアライズペイロード付き URL エンコードフォーム | 400（誤った Content-Type） | ✅ BLOCKED |

### フェーズ 9 — ヘッダーインジェクション / レスポンス分割

| ID | Attack | Expected | v1.5.329 |
|----|--------|----------|----------|
| R-01 | 作成された note id 経由の Location ヘッダーインジェクション | `/examples/notes/<int>` | ✅ SAFE — int のみ |
| R-02 | JWT エラー経由の WWW-Authenticate 内 CRLF | 無害化された固定メッセージ | ✅ SAFE |
| R-03 | Content-Type スニッフィング | `X-Content-Type-Options: nosniff` | ✅ SAFE |
| R-04 | クリックジャッキング | `X-Frame-Options: SAMEORIGIN` | ✅ SAFE |

### フェーズ 10 — CORS / SOP バイパス

| ID | Attack | Expected | v1.5.329 |
|----|--------|----------|----------|
| C-01 | `Origin: null`（サンドボックス化 iframe） | Vary: Origin、ACAO ヘッダーなし | ✅ SAFE |
| C-02 | Origin ヘッダー内の CRLF | curl/http 層で無害化 | ✅ SAFE |
| C-03 | Vary ヘッダーキャッシュポイズニング | `Vary: Origin` あり | ✅ SAFE |
| C-04 | 注入されたメソッド付きプリフライト | メソッドは PHP に無視される | ✅ SAFE |
| C-05 | `Access-Control-Allow-Origin: *` | ヘッダーなし（allowlist が空） | ✅ SAFE |

### フェーズ 11-20 — エンコーディング / プロトコル / タイミング

| ID | Attack | Result |
|----|--------|--------|
| E-01 | JSON 内の絵文字 / 高位 Unicode | ✅ 201（正しく保存） |
| E-02 | BiDi RTL オーバーライド（なりすましリスク） | ✅ 201（表示のみ） |
| E-05 | クエリパラメーター経由のページネーション SQLi | ✅ 422（整数として検証） |
| H-06b | フォールドされた Authorization ヘッダー | ⚠️ 500（PHP ビルトインのバグ） |
| 20 | X-Request-Id 129 文字が拒否される | ✅ サーバーが新しいランダム ID を生成 |
| 21 | X-Request-Id `%0a` 経由のログインジェクション | ✅ 拒否（無効な文字） |
| 22 | Apache ServerTokens/ServerSignature | ✅ `Server: Apache` のみ |
| 23 | JWT sub=admin 権限昇格 | ✅ クレームは認可に使われない |
| 26 | JWT リプレイ（2 秒前に期限切れ） | ✅ 401 `Token has expired.` |
| 27 | 500 スタックトレース開示 | ✅ 汎用メッセージのみ |
| 28 | Problem Details `instance` 内の XSS | ✅ URL エンコード済み（安全） |
| 29 | ヘルスチェックエンドポイント経由の SSRF | ✅ URL は受理されない |
| 15 | API キータイミングオラクル | ✅ `hash_equals` — < 5ms の差 |

---

## 3. 発見事項

### VULN-01 — PHP ビルトインサーバーから `.htaccess` が読める ⚠️ MEDIUM

**Trigger**: `curl http://localhost:8299/.htaccess`  
**Response**: 200 + ファイル全体の内容（Apache リライトルール）  
**Root cause**: PHP のビルトインサーバー（`php -S`）は `.htaccess` のアクセス制限を強制しません — `.htaccess` を静的ファイルとして扱います。  
**Impact**: URL リライトルールを露出します。内容は非機密（パスワード/トークンなし）ですが、index.php へのリライトパターンを確認できてしまいます。  
**Mitigation**: セキュリティに敏感なテストでは `php -S` ではなく Apache コンテナ（`docker compose up -d app`）を使ってください。Apache は正しく 403 を返します。

```bash
# Apache（正しい）: 403 Forbidden
curl -si http://localhost:8200/.htaccess | head -1

# PHP ビルトインサーバー（露出）: 200 OK
curl -si http://localhost:8299/.htaccess | head -1
```

### VULN-02 — HTTP ヘッダーフォールディングが PHP ビルトインサーバーをクラッシュさせる ⚠️ LOW

**Trigger**:
```
GET / HTTP/1.1\r\nHost: localhost\r\nX-NENE2-API-Key:\r\n <key>\r\n\r\n
```
**Response**: `HTTP/1.0 500 Internal Server Error`（空ボディ）  
**Root cause**: PHP のビルトイン HTTP サーバーは RFC 7230 のヘッダーフォールディング（非推奨だが HTTP/1.1 ではまだ有効）をサポートしません。NENE2 フレームワークコードは関与していません。  
**Impact**: 開発時のみ（PHP ビルトインサーバー）。Apache はフォールドされたヘッダーを正しく処理します。

### INFO-01 — 重複した JSON キー: 最後の値が勝つ

`{"title":"first","title":"INJECTED"}` → `title = "INJECTED"`  
標準の PHP `json_decode` の振る舞いです。バリデーションは最終（最後）の値に適用されるため、バリデーションバイパスの経路はありません。認識のために記録します。

---

## 4. 検証されたセキュリティ不変条件

これらの保証は 150 件以上のすべてのテストケースで保持されました:

| 不変条件 | 検証 |
|-----------|-------------|
| すべての SQL クエリがパラメーター化されている | SLEEP は実行されず、インジェクションペイロードはリテラルとして保存される |
| JWT は HS256 + 有効な署名 + 整数の exp でなければならない | 17 件すべての JWT 攻撃バリアントをブロック |
| API キーは `hash_equals` でチェックされる | 10 反復でタイミング差 < 5ms |
| `Content-Length` オーバーフローが処理される | 正しいヘッダーで 413、PHP 警告の漏洩なし |
| すべてのレスポンスにセキュリティヘッダー | CSP / XCTO / XFO / Referrer-Policy / Permissions-Policy を確認 |
| `Server:` / `X-Powered-By:` が削除される | Apache レスポンスにどちらのヘッダーもなし |
| スタックトレースが 500 ボディに決して入らない | 汎用の `"The server encountered an unexpected condition."` のみ |
| パストラバーサルがブロックされる | 15 件すべてのエンコーディングバリアントが 404 を返す |
| `.env` / `.git` / バックアップファイル | ドキュメントルートですべて 404 |
| CORS デフォルト: 許可オリジンなし | 任意のオリジンに対して `Access-Control-Allow-Origin` なし |

---

## 5. テストスイートの実行

主要チェックの最小限の再現（< 5 分）:

```bash
TARGET=http://localhost:8299
APIKEY=pentest-key
SECRET=pentest-jwt-secret-32chars-min!!
CID=$(docker ps --filter "publish=8299" --format "{{.ID}}")

# 1. JWT alg:none
H=$(echo -n '{"typ":"JWT","alg":"none"}' | base64 -w0 | tr '+/' '-_' | tr -d '=')
P=$(echo -n '{"sub":"admin","exp":9999999999}' | base64 -w0 | tr '+/' '-_' | tr -d '=')
curl -si -H "Authorization: Bearer $H.$P." $TARGET/examples/protected | grep "HTTP/"
# 期待: 401

# 2. SQL インジェクション時間ベース
time curl -so /dev/null -X POST -H "Content-Type: application/json" \
  -d '{"title":"x'\'' AND SLEEP(3)--","body":"x"}' $TARGET/examples/notes
# 期待: 合計 < 500ms

# 3. パストラバーサル
curl -si "$TARGET/%2e%2e/%2e%2e/etc/passwd" | grep "HTTP/"
# 期待: 404

# 4. Content-Length オーバーフロー
curl -si -X POST -H "Content-Length: 9999999999999" $TARGET/ | head -3
# 期待: 413 Request Entity Too Large（200 + PHP 警告ではない）

# 5. API キータイミング
time (for i in $(seq 1 10); do
  curl -so /dev/null -H "X-NENE2-API-Key: a" $TARGET/machine/health
done)
# 期待: 正しいキーと同様のタイミング（hash_equals）

# 6. .htaccess の露出（Apache のみ）
curl -si http://localhost:8200/.htaccess | grep "HTTP/"
# 期待: 403

# 7. JWT exp 必須
NEXP=$(docker exec $CID php -r "
  require 'vendor/autoload.php';
  \$v = new Nene2\Auth\LocalBearerTokenVerifier('$SECRET');
  echo \$v->issue(['sub'=>'user1']);
")
curl -si -H "Authorization: Bearer $NEXP" $TARGET/examples/protected | grep "detail"
# 期待: "Token must contain a numeric exp claim."
```

---

## 関連

- [ページネーション境界 & リミットインジェクション](pagination-boundary-attack.md)
- [Webhook 署名検証](webhook-signature-verification.md)
- [JWT 認証を追加する](add-jwt-authentication.md)
- ADR 0011: セキュリティレビューポリシー
