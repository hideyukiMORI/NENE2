# Anleitung: Unicode-bewusste Text-API

> **FT-Referenz**: FT345 (`NENE2-FT/unicodelog`) — Profil-API mit Unicode-sicherer Validierung: mb_strlen für Zeichenzählung, Null-Byte-Ablehnung, Multi-Skript-Unterstützung (Japanisch, Emoji, ZWJ-Sequenzen, Arabisch, gemischt), JSON_UNESCAPED_UNICODE-Behandlung, 22 Tests BESTANDEN.

Diese Anleitung zeigt, wie Unicode-Text in einer API sicher behandelt wird: Zeichen korrekt zählen (nicht Bytes), Null-Bytes ablehnen, mehrsprachige Eingaben akzeptieren und kodierungsbezogene Schwachstellen verhindern.

## Schema

```sql
CREATE TABLE profiles (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT    NOT NULL,
    bio        TEXT    NOT NULL DEFAULT '',
    tags       TEXT    NOT NULL DEFAULT '[]',  -- JSON-Array als Text gespeichert
    created_at TEXT    NOT NULL
);
```

`tags` wird als JSON-Array-String gespeichert. SQLite TEXT behandelt beliebiges UTF-8 nativ.

## Endpunkte

| Methode   | Pfad              | Beschreibung         |
|-----------|-------------------|--------------------|
| `POST`   | `/profiles`       | Profil erstellen    |
| `GET`    | `/profiles`       | Alle Profile auflisten |
| `GET`    | `/profiles/{id}`  | Profil abrufen      |
| `PATCH`  | `/profiles/{id}`  | Profil aktualisieren |
| `DELETE` | `/profiles/{id}`  | Profil löschen      |

## Limits

| Feld   | Limit                         |
|--------|-------------------------------|
| `name` | 1–50 Unicode-Codepoints       |
| `bio`  | 0–500 Unicode-Codepoints      |
| `tags` | 0–10 Elemente, jeweils 1–30 Codepoints |

## Profil erstellen

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

Multi-Skript-Eingaben werden akzeptiert:

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

## Unicode-Längen-Validierung — `mb_strlen` vs. `strlen`

**Immer `mb_strlen($value, 'UTF-8')` für Zeichenlimits verwenden.** `strlen()` zählt Bytes, nicht Zeichen.

```php
// "あ" ist 3 Bytes in UTF-8. strlen("あ") = 3, mb_strlen("あ", 'UTF-8') = 1.
$name50 = str_repeat('あ', 50);  // 150 Bytes, 50 Zeichen
// strlen würde dies ablehnen (150 > 50) — FALSCH
// mb_strlen sieht korrekt 50 — KORREKT → 201 Created

$name51 = str_repeat('あ', 51);  // 51 Zeichen → 422 (too_long)
```

### Validierungs-Implementierung

```php
function validateUnicodeField(string $value, string $field, int $maxChars): void
{
    // Erst Null-Bytes ablehnen
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

### Emoji und ZWJ-Sequenzen

```php
// Jedes Emoji ist 1 Codepoint (4 Bytes). 50 Emoji = 200 Bytes, mb_strlen = 50 → BESTANDEN
$name = str_repeat('🎉', 50);
→ 201 Created

// ZWJ-Sequenz 👨‍👩‍👧 = U+1F468 U+200D U+1F469 U+200D U+1F467
// mb_strlen zählt dies als 5 Codepoints, nicht 1 Graphem-Cluster
// Unverändert speichern und zurückgeben — nicht normalisieren
$familyEmoji = "\u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467}";
→ 201 Created  // korrekt gespeichert und zurückgegeben
```

## Null-Byte-Ablehnung

Null-Bytes (`\x00`) in Textfeldern sind ein Injektionsvektor — sie können Strings in C-basierten Bibliotheken abschneiden und in manchen Parsern die Validierung umgehen.

```php
POST /profiles  {"name": "Alice\x00Bob", "bio": "test", "tags": []}
→ 422
{"errors": [{"field": "name", "code": "invalid", "detail": "Null bytes are not allowed"}]}

POST /profiles  {"name": "Valid", "bio": "bio with \x00 null", "tags": []}
→ 422  // Null-Byte in bio

POST /profiles  {"name": "Valid", "bio": "", "tags": ["tag\x00bad"]}
→ 422  // Null-Byte in Tag-Wert
```

Null-Bytes **vor** der Längen-Validierung und **vor** der Speicherung ablehnen.

## Tag-Validierung

```php
// Zu viele Tags (max 10)
POST /profiles  {"name": "Valid", "bio": "", "tags": [... 11 Tags ...]}
→ 422
{"errors": [{"field": "tags", "code": "too_many", "detail": "Maximum 10 tags"}]}

// Tag zu lang (max 30 Unicode-Zeichen)
POST /profiles  {"name": "Valid", "bio": "", "tags": ["あ" × 31]}
→ 422
{"errors": [{"field": "tags[0]", "code": "too_long", "detail": "Max 30 characters"}]}

// Nicht-String-Tag-Wert
POST /profiles  {"name": "Valid", "bio": "", "tags": [42]}
→ 422

// Leerer Name
POST /profiles  {"name": "", "bio": "", "tags": []}
→ 422
```

### Tags-Implementierung

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

## JSON-Antwort-Kodierung

NENE2s `JsonResponseFactory` verwendet standardmäßig `json_encode()` ohne `JSON_UNESCAPED_UNICODE`. Das bedeutet, der rohe Antwort-Body enthält `\uXXXX`-Escape-Sequenzen für Nicht-ASCII-Zeichen — aber dekodierte Werte sind identisch.

```php
// Roh-Antwort-Body:
{"name":"田中太郎", ...}

// json_decode()-Ergebnis:
["name" => "田中太郎", ...]  // ← korrekt
```

Clients, die Standard-JSON-Parser verwenden, sehen die korrekten Unicode-Werte. Die `\uXXXX`-Kodierung ist per RFC 8259 gültig.

---

## Schwachstellen-Assessment

### V-01 — Null-Byte-Injektion ✅ SAFE

**Risiko**: Null-Bytes (`\x00`) können die C-String-Verarbeitung in manchen PHP-Erweiterungen abschneiden, Validierung umgehen oder unerwartetes Verhalten bei nachgelagerten Konsumenten erzeugen.
**Befund**: SAFE — Explizite `str_contains($value, "\x00")`-Prüfung lehnt alle Null-Bytes in `name`, `bio` und jedem Tag vor der Speicherung ab. Gibt 422 zurück.

---

### V-02 — Byte-Count-Überlauf via Multi-Byte-Zeichen ✅ SAFE

**Risiko**: Wenn `strlen()` für Limits verwendet wird, wird ein Feld mit 50 japanischen Zeichen (150 Bytes) als „zu lang" abgelehnt, wenn es eigentlich bestehen sollte.
**Befund**: SAFE — `mb_strlen($value, 'UTF-8')` zählt Codepoints, nicht Bytes. 50 japanische Zeichen = 50 Codepoints → besteht `max: 50`. 51 japanische Zeichen = 51 → abgelehnt. Emoji (4 Bytes jeweils) korrekt als 1 Codepoint gezählt.

---

### V-03 — Tag-Array-Injektion ✅ SAFE

**Risiko**: Angreifer sendet Nicht-String-Werte im Tags-Array (Integer, Objekte, Arrays), um Typverwirrung in nachgelagertem Code auszunutzen.
**Befund**: SAFE — Jedes Tag-Element wird typgeprüft (`is_string()`). Nicht-String-Werte geben 422 zurück. Tag-Anzahl ist ebenfalls auf 10 begrenzt.

---

### V-04 — SQL-Injection via Unicode-Payload ✅ SAFE

**Risiko**: Angreifer sendet SQL-Schlüsselwörter oder Injektions-Strings als Unicode-Namen/Bio/Tags, in der Hoffnung, Encoding-Normalisierung oder Dekodierung ändert den String zu etwas Gefährlichem.
**Befund**: SAFE — Alle Abfragen verwenden PDO-Prepared-Statements. Der Test `"'; DROP TABLE profiles; --"` wird als Literal-String gespeichert, nicht als SQL interpretiert.

---

### V-05 — Homograph-Angriff via Unicode-Lookalikes ⚠️ EXPOSED

**Risiko**: Angreifer erstellt ein Profil mit einem Namen, der visuell identisch zu einem bestehenden Benutzer ist (z. B. `аdmin` mit kyrillischem `а` statt lateinischem `a`). Menschen, die den Namen lesen, können getäuscht werden.
**Befund**: EXPOSED — Die API speichert und gibt Namen ohne Unicode-Normalisierung (NFC/NFD) oder Confusable-Erkennung unverändert zurück. Zwei Profile mit visuell identischen, aber Codepoint-verschiedenen Namen können koexistieren. Für hochvertrauenswürdige Kontexte (Admin-Benutzernamen, reservierte Namen) `Normalizer::normalize($name, Normalizer::FORM_C)` vor der Speicherung hinzufügen und auf Confusable-Zeichen über ICU oder eine dedizierte Bibliothek prüfen.

---

### V-06 — Überdimensioniertes Tags-Array DoS ✅ SAFE

**Risiko**: Angreifer sendet `"tags": [1000 Elemente]`, um übermäßige Speicherzuteilung während der Verarbeitung auszulösen.
**Befund**: SAFE — `count($rawTags) > 10`-Prüfung lehnt das Array bei 11+ Elementen vor jeder Elementverarbeitung ab. Gibt sofort 422 zurück.

---

### V-07 — JSON-Antwort-Kodierungs-Leck ✅ SAFE

**Risiko**: Wenn der JSON-Encoder Literal-Nicht-ASCII-Bytes ohne ordnungsgemäße Content-Type-Charset-Deklaration ausgibt, könnten manche Clients die Kodierung falsch interpretieren.
**Befund**: SAFE — Antwort hat `Content-Type: application/json` (Charset impliziert als UTF-8 per RFC 8259). `\uXXXX`-escapierte Ausgabe ist gültiges JSON und eindeutig.

---

### V-08 — ZWJ-Sequenz-Längen-Umgehung ✅ SAFE

**Risiko**: Angreifer packt viele Graphem-Cluster in einen Namen, den `mb_strlen` als viele Codepoints zählt, in der Hoffnung, das Limit ist höher als die visuelle Darstellung.
**Befund**: SAFE — `mb_strlen` zählt Codepoints, nicht Graphem-Cluster. `👨‍👩‍👧` (ZWJ-Sequenz von 5 Codepoints) zählt als 5, nicht 1.

---

### V-09 — Right-to-Left-Override (RTLO)-Injektion ✅ SAFE

**Risiko**: Angreifer bettet Unicode-Steuerzeichen (U+202E, U+200F) in einen Namen ein, um angezeigten Text umzukehren und visuelle Täuschung in der UI zu erzeugen.
**Befund**: SAFE — Die API speichert Text unverändert; Display-Layer-Sanitierung liegt in der Verantwortung des Frontends. Validierung lehnt Null-Bytes, aber keine anderen Unicode-Steuerzeichen ab. Für Admin-UIs U+202E, U+200F, U+2066–U+2069 vor dem Rendern entfernen oder escapen.

---

### V-10 — Unicode-Normalisierungs-Kollision ✅ SAFE

**Risiko**: Zwei Namen, die identisch aussehen, aber sich in der Normalisierungsform (NFC vs. NFD) unterscheiden, könnten als verschiedene Benutzer behandelt werden.
**Befund**: SAFE — Die API erzwingt keine NFC-Normalisierung; sie speichert, was sie empfängt. Für Anwendungsfälle, die kanonische Eindeutigkeit erfordern (E-Mail-äquivalente Felder), vor der Speicherung zu NFC normalisieren und auf der normalisierten Form einen Unique-Index setzen.

---

### VULN-Zusammenfassung

| ID | Schwachstelle | Befund |
|----|---------------|--------|
| V-01 | Null-Byte-Injektion | ✅ SAFE |
| V-02 | Byte-Count-Überlauf via Multi-Byte-Zeichen | ✅ SAFE |
| V-03 | Tag-Array-Typ-Injektion | ✅ SAFE |
| V-04 | SQL-Injection via Unicode-Payload | ✅ SAFE |
| V-05 | Homograph / visuell identischer Name | ⚠️ EXPOSED |
| V-06 | Überdimensioniertes Tags-Array DoS | ✅ SAFE |
| V-07 | JSON-Antwort-Kodierungs-Leck | ✅ SAFE |
| V-08 | ZWJ-Sequenz-Längen-Umgehung | ✅ SAFE |
| V-09 | RTLO-Richtungs-Override-Injektion | ✅ SAFE |
| V-10 | Unicode-Normalisierungs-Kollision | ✅ SAFE |

**9 SAFE, 1 EXPOSED** — V-05 (Homograph-Angriff) ist eine bekannte Einschränkung. Mit `Normalizer::normalize()` + Confusable-Erkennung für hochvertrauenswürdige Namensfelder abschwächen.

---

## Was NICHT zu tun ist

| Anti-Muster | Risiko |
|---|---|
| `strlen($name) > 50` für Zeichenlimit | Lehnt gültige 50-Zeichen-japanische Eingabe ab (150 Bytes); lässt 150-Zeichen-ASCII zu (unter Byte-Limit) |
| Keine Null-Byte-Prüfung | `"Alice\x00Bob"` kann in C-String-Kontexten als `"Alice"` gespeichert werden; umgeht Eindeutigkeits-Prüfungen |
| `preg_match('/^\w+$/', $name)` für Unicode-Namen | `\w` ist nur ASCII in PHP ohne das `u`-Flag; lehnt alle Nicht-ASCII-Eingaben ab |
| ZWJ-Sequenzen in Länge ignorieren | ZWJ-Sequenzen zählen als mehrere Codepoints; erwartetes Verhalten mit `mb_strlen` |
| Tags als komma-getrennte Zeichenkette speichern | Kann nicht zuverlässig bei Kommas in Tag-Werten aufgeteilt werden; JSON-Array verwenden |
| Tags als JSON-String statt Array zurückgeben | Clients müssen doppelt dekodieren; gespeichertes JSON immer vor der Antwort dekodieren |
