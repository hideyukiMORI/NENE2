# ハウツー: 多言語コンテンツ API

> **FT リファレンス**: FT232 (`NENE2-FT/i18nlog`) — 多言語コンテンツ API
> **ATK**: FT232 — クラッカー思考攻撃テスト（ATK-01 〜 ATK-12）

コンテンツがロケールキーの翻訳としてアーティクルレコードとは別に保存される多言語アーティクル API を実証します。BCP 47 ロケールバリデーション、翻訳のアップサートセマンティクス、コンテンツネゴシエーションのためのロケールフォールバック、アーティクルごとの公開/下書き状態をサポートします。

---

## ルート

| メソッド | パス | 説明 |
|--------|------|-------------|
| `POST` | `/articles` | アーティクルを作成する（下書きまたは公開） |
| `GET` | `/articles` | 公開済みアーティクルを一覧表示する（オプション `?locale=`） |
| `GET` | `/articles/{id}` | 単一アーティクルを取得する（オプション `?locale=`） |
| `PUT` | `/articles/{id}/translations/{locale}` | 翻訳を作成または更新する（アップサート） |

---

## アーティクルの作成

```json
{
  "default_locale": "en",
  "published": false
}
```

`default_locale` はリクエストされたロケールが利用できない場合のフォールバック言語を設定します。`published` は一覧の可視性を制御します — 公開済みアーティクルのみが `GET /articles` に表示されます。

```php
$defaultLocale = isset($body['default_locale']) && is_string($body['default_locale'])
    ? trim($body['default_locale']) : 'en';
$published = isset($body['published']) && $body['published'] === true;

if (!preg_match('/^[a-z]{2}(-[A-Z]{2})?$/', $defaultLocale)) {
    return $this->problems->create($request, 'validation-failed', 'Validation Failed', 422, null, [
        'errors' => [['field' => 'default_locale', 'code' => 'invalid',
                      'message' => 'default_locale must be a BCP 47 language tag (e.g. en, ja, fr-FR).']],
    ]);
}
```

`$body['published'] === true`（厳格な等価）は JSON `true` がフラグを設定することを意味します — その他の値（文字列 `"true"`、整数 `1`、省略）はアーティクルを下書きのままにします。

---

## BCP 47 ロケールバリデーション

```php
preg_match('/^[a-z]{2}(-[A-Z]{2})?$/', $locale)
```

受け入れるもの:
- 2 つの小文字: `en`、`ja`、`fr`、`de`
- 2 つの小文字 + ハイフン + 2 つの大文字: `fr-FR`、`zh-TW`、`pt-BR`

拒否するもの:
- 誤ったケース: `EN`、`en_US`、`En`
- アンダースコア: `en_US`（BCP 47 はハイフンを使用）
- 地域を超えるサブタグ: `zh-Hant-TW`
- パストラバーサル: `../../etc/passwd`
- 空文字列: `""`

この正規表現は一般的な `language` と `language-REGION` 形式には十分です。完全な BCP 47 サポート（スクリプトコード、バリアントタグ）には専用ライブラリが必要です。

---

## 翻訳のアップサート

`PUT /articles/{id}/translations/{locale}` は翻訳が存在しない場合は作成し、存在する場合は更新します — 最後の書き込みが勝つセマンティクスで冪等です:

```php
public function upsertTranslation(int $articleId, string $locale, string $title, string $body, string $now): Translation
{
    $existing = $this->executor->fetchAll(
        'SELECT * FROM article_translations WHERE article_id = ? AND locale = ?',
        [$articleId, $locale],
    );

    if ($existing !== []) {
        $this->executor->execute(
            'UPDATE article_translations SET title = ?, body = ?, updated_at = ? WHERE article_id = ? AND locale = ?',
            [$title, $body, $now, $articleId, $locale],
        );
    } else {
        $this->executor->execute(
            'INSERT INTO article_translations (article_id, locale, title, body, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)',
            [$articleId, $locale, $title, $body, $now, $now],
        );
    }
    // ... 行をフェッチして返す
}
```

スキーマの `UNIQUE(article_id, locale)` 制約がバックストップとして機能します。アプリケーションレベルの SELECT-then-INSERT/UPDATE はサイレントな競合解決を避け、永続化された行の明示的な返却を可能にします。

ボディバリデーションは空のタイトルまたはボディを拒否します:

```php
$title = isset($body['title']) && is_string($body['title']) ? trim($body['title']) : '';
$text  = isset($body['body'])  && is_string($body['body'])  ? trim($body['body'])  : '';

$errors = [];
if ($title === '') {
    $errors[] = ['field' => 'title', 'code' => 'required', 'message' => 'title is required.'];
}
if ($text === '') {
    $errors[] = ['field' => 'body', 'code' => 'required', 'message' => 'body is required.'];
}
```

空チェック前の `trim()` で空白のみの文字列もバリデーション失敗になります。

---

## コンテンツネゴシエーションのためのロケールフォールバック

呼び出し元が `?locale=fr` を渡すと、`Article` エンティティはリクエストされたロケールを検索し、翻訳が存在しない場合は `default_locale` にフォールバックします:

```php
public function getTranslationWithFallback(string $locale): ?Translation
{
    return $this->getTranslation($locale)
        ?? $this->getTranslation($this->defaultLocale);
}

public function toArray(?string $locale = null): array
{
    $translation = $locale !== null
        ? $this->getTranslationWithFallback($locale)
        : null;

    return [
        'id'             => $this->id,
        'default_locale' => $this->defaultLocale,
        'published'      => $this->published,
        'title'          => $translation?->title,    // 翻訳が保存されていない場合は null
        'body'           => $translation?->body,
        'locale'         => $translation?->locale,   // 実際に提供されたロケールを示す
        'translations'   => array_map(fn (Translation $t) => $t->toArray(), $this->translations),
        'created_at'     => $this->createdAt,
        'updated_at'     => $this->updatedAt,
    ];
}
```

レスポンスの `locale` フィールドは実際に提供されたロケールを呼び出し元に伝えます — フォールバックが発生した場合に便利です（`?locale=zh` → 中国語翻訳がまだ存在しないため `en` 翻訳を提供する）。

---

## スキーマ

```sql
CREATE TABLE IF NOT EXISTS articles (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    default_locale TEXT    NOT NULL DEFAULT 'en',
    published      INTEGER NOT NULL DEFAULT 0,
    created_at     TEXT    NOT NULL,
    updated_at     TEXT    NOT NULL
);

CREATE TABLE IF NOT EXISTS article_translations (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    article_id INTEGER NOT NULL REFERENCES articles(id),
    locale     TEXT    NOT NULL,
    title      TEXT    NOT NULL,
    body       TEXT    NOT NULL,
    created_at TEXT    NOT NULL,
    updated_at TEXT    NOT NULL,
    UNIQUE(article_id, locale)
);
```

主要な設計の選択:
- `published` は `INTEGER` として保存（SQLite boolean: 0/1）; PHP は `(bool) $row['published']` で読み取る。
- `UNIQUE(article_id, locale)` はアーティクルごとにロケールあたり最大 1 つの翻訳を強制する。
- DB に言語バリデーションなし — アプリケーション層が BCP 47 フォーマットを強制する。
- `article_translations.body` はプレーンテキスト; JSON API の呼び出し元は HTML でレンダリングする前のサニタイズに責任を持つ。

---

## ATK — クラッカー思考攻撃テスト（FT232）

### ATK-01 — すべてのエンドポイントで認証なし

**攻撃**: 認証情報なしでアーティクルを作成または変更する。

```bash
curl -s -X POST http://localhost:8080/articles \
  -H 'Content-Type: application/json' \
  -d '{"default_locale":"en","published":true}'
```

**観察結果**: `201 Created` — トークン不要。任意の呼び出し元がアーティクルを作成、翻訳、公開できます。

**判定**: ⚠️ EXPOSED（FT232 デモの設計による）。本番では認証と認可を追加してください。`POST /articles` と `PUT .../translations/{locale}` をライターまたは管理者ロールの後ろに配置してください。

---

### ATK-02 — ロケールパスパラメーターでのパストラバーサル

**攻撃**: パストラバーサルまたはシェルメタ文字の文字列を `{locale}` パスパラメーターとして使用する。

```
PUT /articles/1/translations/../../etc/passwd
PUT /articles/1/translations/../admin
PUT /articles/1/translations/%2F%2Fetc
```

**観察結果**: BCP 47 正規表現 `/^[a-z]{2}(-[A-Z]{2})?$/` がこれらすべてを拒否します — 2 つの小文字（オプションでハイフンと 2 つの大文字が続く）にどれもマッチしません。レスポンス: `422 Unprocessable Entity`。

**判定**: 🚫 BLOCKED — `^` と `$` で固定された厳格な正規表現がトラバーサルシーケンスを拒否します。

---

### ATK-03 — ロケールパスパラメーター経由の SQL インジェクション

**攻撃**: `{locale}` の値に SQL メタ文字を埋め込む。

```
PUT /articles/1/translations/en'; DROP TABLE articles; --
PUT /articles/1/translations/en" OR "1"="1
```

**観察結果**:
1. BCP 47 正規表現がこれらの文字列を即座に拒否 → SQL が実行される前に `422`。
2. 正規表現がバイパスされたとしても、ロケールはパラメーター化された `?` の値として渡されます — SQL との文字列連結なし。

**判定**: 🚫 BLOCKED — 二重層: 正規表現許可リスト + パラメーター化クエリ。

---

### ATK-04 — IDOR: 他のユーザーのアーティクルを翻訳する

**攻撃**: 攻撃者が作成していないアーティクルの翻訳を書く。

```bash
# 攻撃者はアーティクル ID 1 が他のユーザーによって作成されたことを知っている
curl -s -X PUT http://localhost:8080/articles/1/translations/fr \
  -H 'Content-Type: application/json' \
  -d '{"title":"Hacked","body":"Attacker content"}'
```

**観察結果**: `200 OK` — 翻訳が受け入れられ、既存のフランス語翻訳を上書きします。所有権チェックが存在しません。

**判定**: ⚠️ EXPOSED — 所有権モデルなし。`created_by` カラムを追加して、書き込みを許可する前に認証済み呼び出し元と比較してください。

---

### ATK-05 — 空白のみのタイトルまたはボディ

**攻撃**: トリム後に空白になるタイトルまたはボディを送信する。

```json
{"title": "   ", "body": "\t\n"}
```

**観察結果**: `trim()` が両方を空文字列に縮小します。両フィールドが `$errors` に追加されます。レスポンス: 構造化されたフィールドエラー付きの `422 Unprocessable Entity`。

**判定**: 🚫 BLOCKED — 空文字列チェック前の `trim()` が空白のみの入力を処理します。

---

### ATK-06 — タイトルまたはボディへの XSS ペイロード

**攻撃**: 翻訳フィールドにスクリプトタグを保存する。

```json
{"title": "<script>alert(1)</script>", "body": "<img src=x onerror=alert(1)>"}
```

**観察結果**: コンテンツはそのまま保存され、JSON でそのまま返されます。API 自体は HTML エンコードされた出力を行いません — HTML レンダラーではなく JSON API です。

**判定**: ACCEPTED BY DESIGN — JSON API は生のコンテンツを返します。レンダリング層（ブラウザ、モバイルアプリ）が HTML エスケープに責任を持ちます。API 仕様でこれを明確にドキュメント化してください。

---

### ATK-07 — タイトルまたはボディの長さ制限なし

**攻撃**: 数メガバイトのタイトルまたはボディを送信する。

```python
{"title": "A" * 1_000_000, "body": "B" * 5_000_000}
```

**観察結果**: 長さ制限が強制されません — 非常に大きなペイロードが保存されて返されます。メモリと I/O の使用量はペイロードサイズとともにスケールします。SQLite の `TEXT` には実際の長さ制限がありません。

**判定**: ⚠️ EXPOSED — `maxlength` チェックを追加してください:
```php
if (mb_strlen($title) > 500) {
    $errors[] = ['field' => 'title', 'code' => 'too_long', 'message' => 'title must not exceed 500 characters.'];
}
if (mb_strlen($text) > 50000) {
    $errors[] = ['field' => 'body', 'code' => 'too_long', 'message' => 'body must not exceed 50 000 characters.'];
}
```
パース前に合計ボディのバイトを制限するリクエストサイズミドルウェアも適用してください。

---

### ATK-08 — BCP 47 ケースとセパレーターのバイパス

**攻撃**: 意味的に類似しているが構文的に誤ったバリアントを試す。

```
PUT /articles/1/translations/EN        → 大文字の言語コード
PUT /articles/1/translations/en_US     → アンダースコアセパレーター（POSIX スタイル）
PUT /articles/1/translations/en-us     → 小文字の地域
PUT /articles/1/translations/EN-us     → 混在ケース
PUT /articles/1/translations/fra       → 3 文字の ISO 639-2 コード
```

**観察結果**: `/^[a-z]{2}(-[A-Z]{2})?$/` ですべて拒否:
- `EN` — `[a-z]` に失敗
- `en_US` — `_` が `(-[A-Z]{2})?` に失敗
- `en-us` — `us` が `[A-Z]` に失敗
- `fra` — 3 文字が `{2}` 正確に失敗

**判定**: 🚫 BLOCKED — 正規表現が精密です; 正確な BCP 47 `ll` または `ll-RR` 形式のみが通過します。

---

### ATK-09 — 存在しないアーティクルの翻訳

**攻撃**: 存在しないアーティクル ID を対象にする。

```bash
curl -s -X PUT http://localhost:8080/articles/99999/translations/en \
  -H 'Content-Type: application/json' \
  -d '{"title":"Ghost","body":"Body"}'
```

**観察結果**: `findById(99999)` は `null` を返します。ハンドラーはボディを処理する前に `404 Not Found` を返します。

**判定**: 🚫 BLOCKED — 翻訳を書く前にアーティクルの存在が確認されます。

---

### ATK-10 — 認証なしの公開操作

**攻撃**: 下書きレビューをバイパスするためにアーティクルを公開として作成する。

```json
{"default_locale": "en", "published": true}
```

**観察結果**: `201 Created` — `published: true` が即座に受け入れられます。下書きレビューまたは承認ゲートが存在しません; 任意の呼び出し元が公開できます。

**判定**: ⚠️ EXPOSED（ATK-01 と同じ根本原因）。公開アクションには少なくともライターロールが必要であるべきです。`published` フラグを作成ペイロードから分離してください — 認可でガードされた明示的な `POST /articles/{id}/publish` アクションを要求してください。

---

### ATK-11 — 不明な `?locale=` がサイレントにフォールバックする

**攻撃**: 翻訳が保存されていないロケールでアーティクルをリクエストする。

```
GET /articles/1?locale=zh-TW
```

**観察結果**: `getTranslationWithFallback('zh-TW')` が中国語翻訳を見つけられず `default_locale`（例: `en`）にフォールバックします。レスポンスの `locale` フィールドに `en` が表示されます — フォールバックが発生したことを示します。404 またはエラーは返されません。

**判定**: ACCEPTED BY DESIGN — サイレントフォールバックはコンテンツデリバリーとして正しいです。呼び出し元はリクエストされたロケールとレスポンスの `locale` を比較することでフォールバックを検出できます。厳格なロケール強制が必要な場合は `?strict=1` パラメーターを追加してください。

---

### ATK-12 — 非数値のアーティクル ID

**攻撃**: 文字列または浮動小数点数をアーティクル ID として渡す。

```
GET /articles/abc
GET /articles/1.5
GET /articles/0x10
```

**観察結果**:
- `GET /articles/abc` → ルーターが `{id}` パラメーターにマッチ; `(int) 'abc'` = `0`。`findById(0)` は `null` を返す → `404 Not Found`。
- `GET /articles/1.5` → `(int) '1.5'` = `1`。アーティクル 1 が存在すれば返される。これはサイレントな切り捨てであり、エラーではない。

**判定**: PARTIALLY BLOCKED — 非数値の文字列は 0 に解決されて 404 を返します。浮動小数点数はサイレントに切り捨てられます。厳格なバリデーションのためには以下を追加してください:
```php
if (!ctype_digit((string) ($params['id'] ?? ''))) {
    return $this->problems->create($request, 'validation-failed', 'Validation Failed', 422, null, [
        'errors' => [['field' => 'id', 'code' => 'invalid', 'message' => 'id must be a positive integer.']],
    ]);
}
```

---

## ATK サマリー

| # | 攻撃ベクトル | 判定 |
|---|------------|------|
| ATK-01 | 認証なし | ⚠️ EXPOSED |
| ATK-02 | ロケールでのパストラバーサル | 🚫 BLOCKED |
| ATK-03 | ロケール経由の SQL インジェクション | 🚫 BLOCKED |
| ATK-04 | IDOR: 他のアーティクルを翻訳する | ⚠️ EXPOSED |
| ATK-05 | 空白のみのタイトル/ボディ | 🚫 BLOCKED |
| ATK-06 | タイトル/ボディへの XSS | ACCEPTED BY DESIGN |
| ATK-07 | タイトル/ボディの長さ制限なし | ⚠️ EXPOSED |
| ATK-08 | BCP 47 ケース/セパレーターのバイパス | 🚫 BLOCKED |
| ATK-09 | 存在しないアーティクルの翻訳 | 🚫 BLOCKED |
| ATK-10 | 認証なしの公開 | ⚠️ EXPOSED |
| ATK-11 | 不明な `?locale=` がサイレントにフォールバック | ACCEPTED BY DESIGN |
| ATK-12 | 非数値のアーティクル ID | PARTIALLY BLOCKED |

**本番前に修正すべき本物の脆弱性**:
1. **ATK-01 / ATK-04 / ATK-10** — 認証、所有権チェック、別個の公開アクションを追加する
2. **ATK-07** — タイトルとボディの長さ制限を追加する
3. **ATK-12** — ID パラメーターに `ctype_digit()` ガードを追加する

---

## 関連 howto

- [`approval-workflow.md`](approval-workflow.md) — 公開前のコンテンツレビューのためのステートマシン
- [`bulk-status-update.md`](bulk-status-update.md) — 部分的な成功を伴うバルク変異パターン
- [`media-watchlist.md`](media-watchlist.md) — enum バックのステータスとオプションの nullable フィールド
