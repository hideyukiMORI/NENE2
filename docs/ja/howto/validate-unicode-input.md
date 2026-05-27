# ハウツー: Unicode 入力のバリデーション

NENE2 は文字列を UTF-8 として保存・返します。このガイドでは、Unicode 対応バリデーションの落とし穴とその対処方法を説明します。

## 文字数制限には `mb_strlen` を使用する

`strlen` はバイト数を数え、文字数ではありません。日本語、アラビア語、絵文字は文字あたり複数バイトを使用します。

```php
strlen('あ')              // 3（バイト）
mb_strlen('あ', 'UTF-8') // 1（文字）

strlen('🎉')              // 4（バイト）
mb_strlen('🎉', 'UTF-8') // 1（文字 — 1 つのコードポイント）
```

文字数制限を強制する場合は常に `mb_strlen($value, 'UTF-8')` を使用してください:

```php
private const int NAME_MAX_CHARS = 50;

if (mb_strlen($name, 'UTF-8') > self::NAME_MAX_CHARS) {
    $errors[] = ['field' => 'name', 'code' => 'too_long',
                 'message' => 'name must be at most ' . self::NAME_MAX_CHARS . ' characters.'];
}
```

**`strlen` が失敗する理由:** 50 文字の日本語名は 150 バイトです。`strlen(...) > 50` では拒否されてしまいます。

## ヌルバイトを明示的に拒否する

SQLite の TEXT カラムはヌルバイト（`\x00`）を受け入れます。PHP の文字列操作もこれを処理します — しかしユーザー入力のヌルバイトはほとんどの場合インジェクション試行またはエンコーディングのバグです。早期に拒否してください:

```php
if (str_contains($name, "\x00")) {
    $errors[] = ['field' => 'name', 'code' => 'invalid', 'message' => 'name must not contain null bytes.'];
}
```

このチェックは他のバリデーション（長さ、フォーマット等）の前にすべての文字列フィールドに適用してください。

## グラフェムクラスターとコードポイント

`mb_strlen` は Unicode の_コードポイント_を数えます。1 つの可視グリフ（グラフェムクラスター）は複数のコードポイントになる場合があります:

| 入力 | コードポイント | `mb_strlen` | グリフ |
|-------|-----------|-------------|--------|
| `é`（合成済み） | 1 | 1 | 1 |
| `é`（e + 結合アクセント） | 2 | 2 | 1 |
| 👨‍👩‍👧（ZWJ ファミリー） | 5 | 5 | 1 |

ほとんどのユースケース（ユーザー名、自己紹介）では、コードポイントのカウントで問題ありません。可視文字を数える必要がある場合は、`intl` 拡張の `grapheme_strlen()` を使用してください:

```php
grapheme_strlen('👨‍👩‍👧') // 1
mb_strlen('👨‍👩‍👧', 'UTF-8') // 5
```

フィールドに対するユーザーの期待に合った数え方を選択してください。

## JSON レスポンスと非 ASCII 文字

`JsonResponseFactory` は `JSON_UNESCAPED_UNICODE` でレスポンスをエンコードするため、非 ASCII 文字はレスポンスボディにリテラル UTF-8 として表示されます:

```json
{ "name": "田中太郎" }
```

他の場所でカスタムの `json_encode` 呼び出しを構築する場合（例: JSON を TEXT カラムにタグとして保存する）、同じフラグを追加してください:

```php
$tagsJson = json_encode($tags, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
```

`JSON_UNESCAPED_UNICODE` がないと、保存値が `["タグ"]` ではなく `["タグ"]` になります。

## 完全なバリデーション例

```php
private const int NAME_MAX_CHARS = 50;

private function validateName(string $raw): ?string
{
    if ($raw === '') {
        return 'name is required.';
    }
    if (str_contains($raw, "\x00")) {
        return 'name must not contain null bytes.';
    }
    if (mb_strlen($raw, 'UTF-8') > self::NAME_MAX_CHARS) {
        return 'name must be at most ' . self::NAME_MAX_CHARS . ' characters.';
    }
    return null; // 有効
}
```

## 境界値のテスト

常に以下をテストしてください:

- ちょうど `MAX` 文字（通過するはず）— バイト/文字の違いを確認するために Unicode 文字を使用する:

  ```php
  $name50 = str_repeat('あ', 50); // 150 バイト、50 文字 — 通過するはず
  ```

- `MAX + 1` 文字（失敗するはず）:

  ```php
  $name51 = str_repeat('あ', 51); // too_long で 422 を返すはず
  ```

- ヌルバイトの拒否:

  ```php
  "Valid\x00Name" // invalid で 422 を返すはず
  ```
