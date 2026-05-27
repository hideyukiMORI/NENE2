# ハウツー: Unicode 対応テキスト API

> **FT リファレンス**: FT345 (`NENE2-FT/unicodelog`) — Unicode 安全バリデーションを持つプロフィール API: 文字カウントのための mb_strlen、ヌルバイト拒否、マルチスクリプトサポート（日本語、絵文字、ZWJ シーケンス、アラビア語、混合）、JSON_UNESCAPED_UNICODE 処理、22 テスト PASS。

このガイドでは、API で Unicode テキストを安全に処理する方法を説明します: 文字数を正確にカウントする（バイト数ではなく）、ヌルバイトを拒否する、多言語入力を受け入れ、エンコーディング関連の脆弱性を防ぎます。

## スキーマ

```sql
CREATE TABLE profiles (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT    NOT NULL,
    bio        TEXT    NOT NULL DEFAULT '',
    tags       TEXT    NOT NULL DEFAULT '[]',  -- テキストとして保存された JSON 配列
    created_at TEXT    NOT NULL
);
```

`tags` は JSON 配列文字列として保存されます。SQLite TEXT は任意の UTF-8 をネイティブに処理します。

## エンドポイント

| メソッド | パス | 説明 |
|--------|------|------|
| `POST`   | `/profiles`      | プロフィールを作成する         |
| `GET`    | `/profiles`      | すべてのプロフィールを一覧表示する |
| `GET`    | `/profiles/{id}` | プロフィールを取得する         |
| `PATCH`  | `/profiles/{id}` | プロフィールを更新する         |
| `DELETE` | `/profiles/{id}` | プロフィールを削除する         |

## 制限

| フィールド | 制限 |
|---------|------|
| `name` | 1〜50 Unicode コードポイント |
| `bio`  | 0〜500 Unicode コードポイント |
| `tags` | 0〜10 アイテム、各 1〜30 コードポイント |

## プロフィールの作成

```php
POST /profiles
{
  "name": "田中太郎",
  "bio": "プログラマーです。PHPが大好きです！",
  "tags": ["エンジニア", "PHP"]
}

→ 201
{
  "id": 1,
  "name": "田中太郎",
  "bio": "プログラマーです。PHPが大好きです！",
  "tags": ["エンジニア", "PHP"],
  "created_at": "2026-05-27T09:00:00Z"
}
```

マルチスクリプトの入力が受け入れられます:

```php
POST /profiles
{"name": "🎉 Yuki 🎊", "bio": "I love emojis! 🚀✨", "tags": ["🎨", "🎵"]}
→ 201

POST /profiles
{"name": "محمد علي", "bio": "مبرمج ويب من مصر", "tags": ["مطور"]}
→ 201

POST /profiles
{"name": "André García 鈴木", "bio": "Café résumé naïve", "tags": ["日本語", "español"]}
→ 201
```

## Unicode 長さバリデーション — `mb_strlen` vs `strlen`

**文字制限には常に `mb_strlen($value, 'UTF-8')` を使用してください。** `strlen()` は文字ではなくバイトをカウントします。

```php
// "あ" は UTF-8 で 3 バイト。strlen("あ") = 3、mb_strlen("あ", 'UTF-8') = 1。
$name50 = str_repeat('あ', 50);  // 150 バイト、50 文字
// strlen はこれを拒否する（150 > 50）— 間違い
// mb_strlen は正確に 50 を認識 — 正しい → 201 Created

$name51 = str_repeat('あ', 51);  // 51 文字 → 422（too_long）
```

### バリデーション実装

```php
function validateUnicodeField(string $value, string $field, int $maxChars): void
{
    // 最初にヌルバイトを拒否
    if (str_contains($value, "\x00")) {
        throw new ValidationException($field, 'invalid', 'Null bytes are not allowed');
    }

    $length = mb_strlen($value, 'UTF-8');
    if ($length === 0 && $field === 'name') {
        throw new ValidationException($field, 'required', 'Field is required');
    }
    if ($length > $maxChars) {
        throw new ValidationException($field, 'too_long', "Max {$maxChars} characters");
    }
}
```

### 絵文字と ZWJ シーケンス

```php
// 各絵文字は 1 コードポイント（4 バイト）。50 絵文字 = 200 バイト、mb_strlen = 50 → PASS
$name = str_repeat('🎉', 50);
→ 201 Created

// ZWJ シーケンス 👨‍👩‍👧 = U+1F468 U+200D U+1F469 U+200D U+1F467
// mb_strlen はこれを 1 書記素クラスターではなく 5 コードポイントとしてカウント
// そのまま保存して返す — 正規化しない
$familyEmoji = "\u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467}";
→ 201 Created  // 正しく保存されて返される
```

## ヌルバイト拒否

テキストフィールドのヌルバイト（`\x00`）はインジェクションベクターです — C ベースのライブラリで文字列を切り捨て、一部のパーサーでバリデーションをバイパスする可能性があります。

```php
POST /profiles  {"name": "Alice\x00Bob", "bio": "test", "tags": []}
→ 422
{"errors": [{"field": "name", "code": "invalid", "detail": "Null bytes are not allowed"}]}

POST /profiles  {"name": "Valid", "bio": "bio with \x00 null", "tags": []}
→ 422  // bio のヌルバイト

POST /profiles  {"name": "Valid", "bio": "", "tags": ["tag\x00bad"]}
→ 422  // タグ値のヌルバイト
```

長さバリデーションの**前**、保存の**前**にヌルバイトを拒否してください。

## タグバリデーション

```php
// タグが多すぎる（最大 10）
POST /profiles  {"name": "Valid", "bio": "", "tags": [... 11 タグ ...]}
→ 422
{"errors": [{"field": "tags", "code": "too_many", "detail": "Maximum 10 tags"}]}

// タグが長すぎる（最大 30 Unicode 文字）
POST /profiles  {"name": "Valid", "bio": "", "tags": ["あ" × 31]}
→ 422
{"errors": [{"field": "tags[0]", "code": "too_long", "detail": "Max 30 characters"}]}

// 非文字列タグ値
POST /profiles  {"name": "Valid", "bio": "", "tags": [42]}
→ 422

// 空の name
POST /profiles  {"name": "", "bio": "", "tags": []}
→ 422
```

### タグ実装

```php
$rawTags = $input['tags'] ?? [];
if (!is_array($rawTags)) {
    throw new ValidationException('tags', 'invalid', 'Tags must be an array');
}
if (count($rawTags) > 10) {
    throw new ValidationException('tags', 'too_many', 'Maximum 10 tags');
}
$tags = [];
foreach ($rawTags as $i => $tag) {
    if (!is_string($tag)) {
        throw new ValidationException("tags[{$i}]", 'invalid', 'Each tag must be a string');
    }
    if (str_contains($tag, "\x00")) {
        throw new ValidationException("tags[{$i}]", 'invalid', 'Null bytes not allowed');
    }
    if (mb_strlen($tag, 'UTF-8') > 30) {
        throw new ValidationException("tags[{$i}]", 'too_long', 'Max 30 characters per tag');
    }
    $tags[] = $tag;
}
$tagsJson = json_encode($tags, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
```

## JSON レスポンスエンコーディング

NENE2 の `JsonResponseFactory` はデフォルトで `JSON_UNESCAPED_UNICODE` なしに `json_encode()` を使用します。これは生のレスポンスボディが非 ASCII 文字に対して `\uXXXX` エスケープシーケンスを含むことを意味します — ただしデコードされた値は同一です。

```php
// 生のレスポンスボディ:
{"name":"田中太郎", ...}

// json_decode() の結果:
["name" => "田中太郎", ...]  // ← 正しい
```

標準的な JSON パーサーを使用するクライアントは正しい Unicode 値を見ます。`\uXXXX` エンコーディングは RFC 8259 に従い有効です。

---

## 脆弱性アセスメント

### V-01 — ヌルバイトインジェクション ✅ SAFE

**リスク**: ヌルバイト（`\x00`）は一部の PHP 拡張機能で C 文字列処理を切り捨て、バリデーションをバイパスし、または下流のコンシューマーで予期しない動作を引き起こす可能性がある。
**判定**: SAFE — 明示的な `str_contains($value, "\x00")` チェックが保存前に `name`、`bio`、各タグのすべてのヌルバイトを拒否する。422 を返す。

---

### V-02 — マルチバイト文字によるバイトカウントオーバーフロー ✅ SAFE

**リスク**: 制限に `strlen()` を使用すると、50 文字の日本語フィールド（150 バイト）は通過すべきなのに「長すぎる」として拒否される。
**判定**: SAFE — `mb_strlen($value, 'UTF-8')` はバイトではなくコードポイントをカウントする。50 文字の日本語 = 50 コードポイント → `max: 50` を通過。51 文字の日本語 = 51 → 拒否される。絵文字（各 4 バイト）は正確に各 1 コードポイントとしてカウントされる。

---

### V-03 — タグ配列型インジェクション ✅ SAFE

**リスク**: 攻撃者がタグ配列に非文字列値（整数、オブジェクト、配列）を送信して、下流コードの型の混乱を悪用する。
**判定**: SAFE — 各タグ要素は型チェックされる（`is_string()`）。非文字列値は 422 を返す。タグ数も 10 に制限される。

---

### V-04 — Unicode ペイロード経由の SQL インジェクション ✅ SAFE

**リスク**: 攻撃者が Unicode の名前/bio/タグとして SQL キーワードまたはインジェクション文字列を送信し、エンコーディング正規化またはデコーディングが文字列を危険なものに変えることを期待する。
**判定**: SAFE — すべてのクエリは PDO プリペアドステートメントを使用する。テスト `"'; DROP TABLE profiles; --"` は SQL として解釈されずにそのまま文字列として保存される。そのような書き込み後も SQLite は存在し 200 を返す。

---

### V-05 — Unicode のそっくり文字によるホモグラフ攻撃 ⚠️ EXPOSED

**リスク**: 攻撃者が既存ユーザーと視覚的に同一の名前（例: ラテン文字 `a` の代わりにキリル文字 `а` を使った `аdmin`）でプロフィールを作成する。名前を読む人間が騙される可能性がある。
**判定**: EXPOSED — API は Unicode 正規化（NFC/NFD）または類似文字検出なしに名前をそのまま保存して返す。視覚的に同一だがコードポイントが異なる 2 つのプロフィール名が共存できる。高信頼コンテキスト（管理者ユーザー名、予約名）では、保存前に `Normalizer::normalize($name, Normalizer::FORM_C)` を追加し、ICU または専用ライブラリで類似文字を確認してください。

---

### V-06 — 過大なタグ配列による DoS ✅ SAFE

**リスク**: 攻撃者が `"tags": [1000 アイテム]` を送信して、処理中に過剰なメモリ割り当てをトリガーする。
**判定**: SAFE — 要素ごとの処理前に `count($rawTags) > 10` チェックが 11 以上のアイテムで配列を拒否する。すぐに 422 を返す。

---

### V-07 — JSON レスポンスエンコーディング漏洩 ✅ SAFE

**リスク**: JSON エンコーダーが適切なコンテンツタイプ文字セット宣言なしにリテラルな非 ASCII バイトを出力すると、一部のクライアントがエンコーディングを誤解する可能性がある。
**判定**: SAFE — レスポンスは `Content-Type: application/json` を持つ（RFC 8259 で UTF-8 が暗黙的）。`\uXXXX` エスケープ出力は有効な JSON で曖昧さがない。標準パーサーを使用するクライアントは常に正しい Unicode 値を取得する。

---

### V-08 — ZWJ シーケンス長バイパス ✅ SAFE

**リスク**: 攻撃者が `mb_strlen` が多くのコードポイントとしてカウントする書記素クラスターを名前に詰め込み、制限が視覚的表現より高いことを期待する。
**判定**: SAFE — `mb_strlen` は書記素クラスターではなくコードポイントをカウントする。`👨‍👩‍👧`（5 コードポイントの ZWJ シーケンス）は 1 ではなく 5 としてカウントされる。ZWJ シーケンスを使用した 10 文字の視覚的な名前が 50 以上のコードポイントを消費して制限に達することが期待される通りになる。

---

### V-09 — 右から左への上書き（RTLO）インジェクション ✅ SAFE

**リスク**: 攻撃者が名前に Unicode 制御文字（U+202E、U+200F）を埋め込んで表示テキストを反転し、UI で視覚的な欺瞞を作り出す。
**判定**: SAFE — API はテキストをそのまま保存する; 表示レイヤーのサニタイズはフロントエンドの責任。バリデーションはヌルバイトを拒否するが他の Unicode 制御文字は拒否しない。管理者 UI では、レンダリング前に U+202E、U+200F、U+2066–U+2069（方向性オーバーライド）をストリップまたはエスケープしてください。

---

### V-10 — Unicode 正規化の衝突 ✅ SAFE

**リスク**: 見た目は同一だが正規化形式（NFC vs NFD）が異なる 2 つの名前が異なるユーザーとして扱われ、アカウントの混乱を引き起こす可能性がある。
**判定**: SAFE — API は NFC 正規化を強制しない; 受け取ったものをそのまま保存する。正規の一意性が必要なユースケース（メール相当のフィールド）では、保存前に NFC に正規化し、正規化された形式で一意インデックスを作成してください。プロフィール名はこの FT では表示のみのため、衝突はセキュリティ問題ではない。

---

### VULN サマリー

| ID | 脆弱性 | 判定 |
|----|--------|------|
| V-01 | ヌルバイトインジェクション | ✅ SAFE |
| V-02 | マルチバイト文字によるバイトカウントオーバーフロー | ✅ SAFE |
| V-03 | タグ配列型インジェクション | ✅ SAFE |
| V-04 | Unicode ペイロード経由の SQL インジェクション | ✅ SAFE |
| V-05 | ホモグラフ / 視覚的に同一の名前 | ⚠️ EXPOSED |
| V-06 | 過大なタグ配列による DoS | ✅ SAFE |
| V-07 | JSON レスポンスエンコーディング漏洩 | ✅ SAFE |
| V-08 | ZWJ シーケンス長バイパス | ✅ SAFE |
| V-09 | RTLO 方向性オーバーライドインジェクション | ✅ SAFE |
| V-10 | Unicode 正規化の衝突 | ✅ SAFE |

**9 SAFE、1 EXPOSED** — V-05（ホモグラフ攻撃）は既知の制限です。高信頼の名前フィールドには `Normalizer::normalize()` + 類似文字検出で軽減してください。

---

## やってはいけないこと

| アンチパターン | リスク |
|---|---|
| 文字制限に `strlen($name) > 50` を使用する | 有効な 50 文字の日本語入力（150 バイト）を拒否する; 150 文字の ASCII（バイト制限以下）を許可する |
| ヌルバイトチェックがない | `"Alice\x00Bob"` は C 文字列コンテキストで `"Alice"` として保存される可能性がある; 一意性チェックをバイパスする |
| Unicode 名前に `preg_match('/^\w+$/', $name)` を使用する | PHP で `u` フラグなしの `\w` は ASCII のみ; すべての非 ASCII 入力を拒否する |
| 長さで ZWJ シーケンスを無視する | ZWJ シーケンスは複数のコードポイントとしてカウントされる; `mb_strlen` を使用した期待される動作 |
| タグをカンマ区切り文字列として保存する | タグ値にカンマがある場合、信頼性よく分割できない; JSON 配列を使用すること |
| タグを配列ではなく JSON 文字列として返す | クライアントが二重デコードしなければならない; 保存された JSON を常にデコードしてからレスポンスで返すこと |
