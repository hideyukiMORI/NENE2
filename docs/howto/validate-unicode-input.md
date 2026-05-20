# How to validate Unicode input

NENE2 stores and returns strings as UTF-8. This guide covers the pitfalls of Unicode-aware validation and how to handle them.

## Use `mb_strlen` for character-count limits

`strlen` counts bytes, not characters. Japanese, Arabic, and emoji use multiple bytes per character.

```php
strlen('あ')              // 3 (bytes)
mb_strlen('あ', 'UTF-8') // 1 (character)

strlen('🎉')              // 4 (bytes)
mb_strlen('🎉', 'UTF-8') // 1 (character — one codepoint)
```

Always use `mb_strlen($value, 'UTF-8')` when enforcing a character limit:

```php
private const int NAME_MAX_CHARS = 50;

if (mb_strlen($name, 'UTF-8') > self::NAME_MAX_CHARS) {
    $errors[] = ['field' => 'name', 'code' => 'too_long',
                 'message' => 'name must be at most ' . self::NAME_MAX_CHARS . ' characters.'];
}
```

**Why `strlen` breaks:** A 50-character Japanese name is 150 bytes. `strlen(...) > 50` would reject it.

## Reject null bytes explicitly

SQLite TEXT columns accept null bytes (`\x00`). PHP string operations handle them too — but null bytes in user input are almost always injection attempts or encoding bugs. Reject them early:

```php
if (str_contains($name, "\x00")) {
    $errors[] = ['field' => 'name', 'code' => 'invalid', 'message' => 'name must not contain null bytes.'];
}
```

Apply this check to every string field before other validation (length, format, etc.).

## Grapheme clusters vs codepoints

`mb_strlen` counts Unicode _codepoints_. A visible glyph (grapheme cluster) can be multiple codepoints:

| Input | Codepoints | `mb_strlen` | Glyphs |
|-------|-----------|-------------|--------|
| `é` (precomposed) | 1 | 1 | 1 |
| `é` (e + combining accent) | 2 | 2 | 1 |
| 👨‍👩‍👧 (ZWJ family) | 5 | 5 | 1 |

For most use cases (usernames, bios), codepoint counting is fine. If you need to count visible characters, use `grapheme_strlen()` from the `intl` extension:

```php
grapheme_strlen('👨‍👩‍👧') // 1
mb_strlen('👨‍👩‍👧', 'UTF-8') // 5
```

Choose the counting method that matches the user's expectation for your field.

## JSON responses and non-ASCII characters

`JsonResponseFactory` encodes responses with `JSON_UNESCAPED_UNICODE`, so non-ASCII characters appear as literal UTF-8 in the response body:

```json
{ "name": "田中太郎" }
```

If you are building a custom `json_encode` call elsewhere (e.g., storing tags as JSON in a TEXT column), add the same flag:

```php
$tagsJson = json_encode($tags, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
```

Without `JSON_UNESCAPED_UNICODE`, the stored value would be `["タグ"]` instead of `["タグ"]`.

## Complete validation example

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
    return null; // valid
}
```

## Testing boundary values

Always write tests for:

- Exactly `MAX` characters (should pass) — use a Unicode character to verify byte/char difference:

  ```php
  $name50 = str_repeat('あ', 50); // 150 bytes, 50 chars — should pass
  ```

- `MAX + 1` characters (should fail):

  ```php
  $name51 = str_repeat('あ', 51); // should return 422 with too_long
  ```

- Null byte rejection:

  ```php
  "Valid\x00Name" // should return 422 with invalid
  ```
