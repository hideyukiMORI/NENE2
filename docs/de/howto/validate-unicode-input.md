# Unicode-Eingaben validieren

NENE2 speichert und gibt Strings als UTF-8 zurück. Diese Anleitung behandelt die Fallstricke der Unicode-bewussten Validierung und deren Handhabung.

## `mb_strlen` für Zeichenanzahl-Limits verwenden

`strlen` zählt Bytes, keine Zeichen. Japanisch, Arabisch und Emoji verwenden mehrere Bytes pro Zeichen.

```php
strlen('あ')              // 3 (Bytes)
mb_strlen('あ', 'UTF-8') // 1 (Zeichen)

strlen('🎉')              // 4 (Bytes)
mb_strlen('🎉', 'UTF-8') // 1 (Zeichen — ein Codepoint)
```

Immer `mb_strlen($value, 'UTF-8')` verwenden, wenn ein Zeichenlimit durchgesetzt wird:

```php
private const int NAME_MAX_CHARS = 50;

if (mb_strlen($name, 'UTF-8') > self::NAME_MAX_CHARS) {
    $errors[] = ['field' => 'name', 'code' => 'too_long',
                 'message' => 'name must be at most ' . self::NAME_MAX_CHARS . ' characters.'];
}
```

**Warum `strlen` versagt:** Ein japanischer Name mit 50 Zeichen ist 150 Bytes. `strlen(...) > 50` würde ihn ablehnen.

## Null-Bytes explizit ablehnen

SQLite-TEXT-Spalten akzeptieren Null-Bytes (`\x00`). PHP-String-Operationen behandeln sie ebenfalls — aber Null-Bytes in Benutzereingaben sind fast immer Injektionsversuche oder Kodierungsfehler. Sie frühzeitig ablehnen:

```php
if (str_contains($name, "\x00")) {
    $errors[] = ['field' => 'name', 'code' => 'invalid', 'message' => 'name must not contain null bytes.'];
}
```

Diese Prüfung auf jedes String-Feld vor anderen Validierungen (Länge, Format etc.) anwenden.

## Graphem-Cluster vs. Codepoints

`mb_strlen` zählt Unicode-_Codepoints_. Ein sichtbares Glyph (Graphem-Cluster) kann aus mehreren Codepoints bestehen:

| Eingabe | Codepoints | `mb_strlen` | Glyphen |
|---------|-----------|-------------|---------|
| `é` (präkomponiert) | 1 | 1 | 1 |
| `é` (e + kombinierender Akzent) | 2 | 2 | 1 |
| 👨‍👩‍👧 (ZWJ-Familie) | 5 | 5 | 1 |

Für die meisten Anwendungsfälle (Benutzernamen, Bios) ist die Codepoint-Zählung ausreichend. Wenn sichtbare Zeichen gezählt werden müssen, `grapheme_strlen()` aus der `intl`-Erweiterung verwenden:

```php
grapheme_strlen('👨‍👩‍👧') // 1
mb_strlen('👨‍👩‍👧', 'UTF-8') // 5
```

Die Zählmethode wählen, die der Benutzererwartung für das jeweilige Feld entspricht.

## JSON-Antworten und Nicht-ASCII-Zeichen

`JsonResponseFactory` kodiert Antworten mit `JSON_UNESCAPED_UNICODE`, sodass Nicht-ASCII-Zeichen als literales UTF-8 im Antwort-Body erscheinen:

```json
{ "name": "田中太郎" }
```

Bei einer benutzerdefinierten `json_encode`-Aufruf anderswo (z. B. Tags als JSON in einer TEXT-Spalte speichern) dasselbe Flag hinzufügen:

```php
$tagsJson = json_encode($tags, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
```

Ohne `JSON_UNESCAPED_UNICODE` wäre der gespeicherte Wert `["タグ"]` statt `["タグ"]`.

## Vollständiges Validierungsbeispiel

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
    return null; // gültig
}
```

## Grenzwerte testen

Immer Tests schreiben für:

- Genau `MAX` Zeichen (sollte bestehen) — Unicode-Zeichen verwenden, um Byte-/Zeichen-Unterschied zu verifizieren:

  ```php
  $name50 = str_repeat('あ', 50); // 150 Bytes, 50 Zeichen — sollte bestehen
  ```

- `MAX + 1` Zeichen (sollte fehlschlagen):

  ```php
  $name51 = str_repeat('あ', 51); // sollte 422 mit too_long zurückgeben
  ```

- Null-Byte-Ablehnung:

  ```php
  "Valid\x00Name" // sollte 422 mit invalid zurückgeben
  ```
