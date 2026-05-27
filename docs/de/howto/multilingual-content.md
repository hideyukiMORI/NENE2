# How-to: Mehrsprachige Inhalts-API

> **FT-Referenz**: FT232 (`NENE2-FT/i18nlog`) — Mehrsprachige Inhalts-API
> **ATK**: FT232 — Cracker-Mindset-Angriffstests (ATK-01 bis ATK-12)

Demonstriert eine mehrsprachige Artikel-API, bei der Inhalte als locale-verschlüsselte Übersetzungen separat vom Artikeldatensatz selbst gespeichert werden. Unterstützt BCP-47-Locale-Validierung, Upsert-Semantik für Übersetzungen, Locale-Fallback für Content-Negotiation und Veröffentlichungs-/Entwurfsstatus pro Artikel.

---

## Routen

| Methode | Pfad | Beschreibung |
|--------|-----------------------------------------|-----------------------------------------------|
| `POST` | `/articles`                             | Artikel erstellen (Entwurf oder veröffentlicht)|
| `GET`  | `/articles`                             | Veröffentlichte Artikel auflisten (optional `?locale=`) |
| `GET`  | `/articles/{id}`                        | Einzelnen Artikel abrufen (optional `?locale=`)|
| `PUT`  | `/articles/{id}/translations/{locale}`  | Übersetzung erstellen oder aktualisieren (Upsert)|

---

## Artikel erstellen

```json
{
  "default_locale": "en",
  "published": false
}
```

`default_locale` legt die Fallback-Sprache fest, wenn eine angeforderte Locale nicht verfügbar ist. `published` steuert die Sichtbarkeit in Listen — nur veröffentlichte Artikel erscheinen in `GET /articles`.

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

`$body['published'] === true` (strenge Gleichheit) bedeutet, dass JSON `true` das Flag setzt — jeder andere Wert (String `"true"`, Integer `1`, weggelassen) lässt den Artikel als Entwurf.

---

## BCP-47-Locale-Validierung

```php
preg_match('/^[a-z]{2}(-[A-Z]{2})?$/', $locale)
```

Akzeptiert:
- Zwei Kleinbuchstaben: `en`, `ja`, `fr`, `de`
- Zwei Kleinbuchstaben + Bindestrich + zwei Großbuchstaben: `fr-FR`, `zh-TW`, `pt-BR`

Lehnt ab:
- Falsche Groß-/Kleinschreibung: `EN`, `en_US`, `En`
- Unterstriche: `en_US` (BCP 47 verwendet Bindestriche)
- Subtags jenseits der Region: `zh-Hant-TW`
- Pfad-Traversierung: `../../etc/passwd`
- Leerer String: `""`

Dieser Regex ist für die gängigen `language`- und `language-REGION`-Formen ausreichend. Für vollständige BCP-47-Unterstützung (Script-Codes, Variant-Tags) ist eine dedizierte Bibliothek erforderlich.

---

## Übersetzung upserting

`PUT /articles/{id}/translations/{locale}` erstellt die Übersetzung, wenn sie nicht existiert, oder aktualisiert sie, wenn sie existiert — idempotent mit Last-Write-Wins-Semantik:

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
    // ... Zeile abrufen und zurückgeben
}
```

Der `UNIQUE(article_id, locale)`-Constraint im Schema dient als Rückhalt; das SELECT-then-INSERT/UPDATE auf Anwendungsebene vermeidet stille Konfliktlösung und ermöglicht die explizite Rückgabe der persistierten Zeile.

Body-Validierung lehnt leeren Titel oder Body ab:

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

`trim()` vor der Leer-Prüfung stellt sicher, dass auch nur-aus-Leerzeichen bestehende Strings die Validierung nicht bestehen.

---

## Locale-Fallback für Content-Negotiation

Wenn der Aufrufer `?locale=fr` übergibt, sucht die `Article`-Entität die angeforderte Locale und fällt auf `default_locale` zurück, wenn keine Übersetzung existiert:

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
        'title'          => $translation?->title,    // null wenn keine Übersetzung gespeichert
        'body'           => $translation?->body,
        'locale'         => $translation?->locale,   // gibt an, welche Locale geliefert wurde
        'translations'   => array_map(fn (Translation $t) => $t->toArray(), $this->translations),
        'created_at'     => $this->createdAt,
        'updated_at'     => $this->updatedAt,
    ];
}
```

Das `locale`-Feld in der Antwort zeigt dem Aufrufer, welche Locale tatsächlich geliefert wurde — nützlich wenn Fallback auftrat (`?locale=zh` → Artikel liefert `en`-Übersetzung, weil noch keine chinesische Übersetzung existiert).

---

## Schema

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

Wichtige Designentscheidungen:
- `published` wird als `INTEGER` gespeichert (SQLite-Boolean: 0/1); PHP liest es über `(bool) $row['published']`.
- `UNIQUE(article_id, locale)` erzwingt höchstens eine Übersetzung pro Locale pro Artikel.
- Keine Sprachvalidierung in der DB — die Anwendungsschicht erzwingt das BCP-47-Format.
- `article_translations.body` ist Klartext; JSON-API-Aufrufer sind verantwortlich für das Bereinigen vor dem Rendern in HTML.

---

## ATK — Cracker-Mindset-Angriffstests (FT232)

### ATK-01 — Keine Authentifizierung bei irgendeinem Endpunkt

**Angriff**: Artikel ohne Anmeldedaten erstellen oder ändern.

```bash
curl -s -X POST http://localhost:8080/articles \
  -H 'Content-Type: application/json' \
  -d '{"default_locale":"en","published":true}'
```

**Beobachtet**: `201 Created` — kein Token erforderlich. Jeder Aufrufer kann Artikel erstellen, übersetzen oder veröffentlichen.

**Urteil**: **EXPOSED** (by Design für FT232-Demo). Authentifizierung und Autorisierung für die Produktion hinzufügen. `POST /articles` und `PUT .../translations/{locale}` hinter einer Autoren- oder Admin-Rolle sichern.

---

### ATK-02 — Pfad-Traversierung im Locale-Pfadparameter

**Angriff**: Pfad-Traversierungs- oder Shell-Metazeichen-Strings als `{locale}`-Pfadparameter verwenden.

```
PUT /articles/1/translations/../../etc/passwd
PUT /articles/1/translations/../admin
PUT /articles/1/translations/%2F%2Fetc
```

**Beobachtet**: Der BCP-47-Regex `/^[a-z]{2}(-[A-Z]{2})?$/` lehnt alle davon ab — keiner entspricht zwei Kleinbuchstaben (optional gefolgt von einem Bindestrich und zwei Großbuchstaben). Antwort: `422 Unprocessable Entity`.

**Urteil**: **BLOCKED** — strenger Regex mit `^` und `$` verankert lehnt Traversierungssequenzen ab.

---

### ATK-03 — SQL-Injection über Locale-Pfadparameter

**Angriff**: SQL-Metazeichen im `{locale}`-Wert einbetten.

```
PUT /articles/1/translations/en'; DROP TABLE articles; --
PUT /articles/1/translations/en" OR "1"="1
```

**Beobachtet**:
1. Der BCP-47-Regex lehnt diese Strings sofort ab → `422` bevor SQL ausgeführt wird.
2. Selbst wenn der Regex umgangen würde, wird die Locale als parametrisierter `?`-Wert übergeben — keine String-Konkatenation mit SQL.

**Urteil**: **BLOCKED** — doppelte Schicht: Regex-Allowlist + parametrisierte Abfragen.

---

### ATK-04 — IDOR: Übersetzung eines anderen Benutzers

**Angriff**: Eine Übersetzung für einen Artikel schreiben, den der Angreifer nicht erstellt hat.

```bash
# Angreifer kennt Artikel-ID 1, die von einem anderen Benutzer erstellt wurde
curl -s -X PUT http://localhost:8080/articles/1/translations/fr \
  -H 'Content-Type: application/json' \
  -d '{"title":"Hacked","body":"Attacker content"}'
```

**Beobachtet**: `200 OK` — Übersetzung wird akzeptiert und überschreibt jede vorhandene französische Übersetzung. Kein Eigentumscheck existiert.

**Urteil**: **EXPOSED** — kein Eigentumsmodell. Eine `created_by`-Spalte hinzufügen und mit dem authentifizierten Aufrufer vergleichen, bevor Schreibvorgänge erlaubt werden.

---

### ATK-05 — Nur-Leerzeichen-Titel oder -Body

**Angriff**: Einen Titel oder Body senden, der nach dem Trimmen leer ist.

```json
{"title": "   ", "body": "\t\n"}
```

**Beobachtet**: `trim()` reduziert beide auf leere Strings. Beide Felder werden zu `$errors` hinzugefügt. Antwort: `422 Unprocessable Entity` mit strukturierten Feldfehlern.

**Urteil**: **BLOCKED** — `trim()` vor der Leer-String-Prüfung behandelt nur-aus-Leerzeichen bestehende Eingaben.

---

### ATK-06 — XSS-Payload in Titel oder Body

**Angriff**: Ein Script-Tag in einem Übersetzungsfeld speichern.

```json
{"title": "<script>alert(1)</script>", "body": "<img src=x onerror=alert(1)>"}
```

**Beobachtet**: Inhalt wird unverändert gespeichert und wörtlich in JSON zurückgegeben. Die API selbst kodiert die Ausgabe nicht HTML-mäßig — es ist eine JSON-API, kein HTML-Renderer.

**Urteil**: **BY DESIGN AKZEPTIERT** — JSON-APIs geben rohen Inhalt zurück; die Rendering-Schicht (Browser, mobile App) ist für HTML-Escaping verantwortlich. Dies klar in der API-Spec dokumentieren, damit Consumer unvertrauten Inhalt nicht ohne Bereinigung rendern.

---

### ATK-07 — Unbegrenzter Titel oder Body

**Angriff**: Einen mehrmegabyte-großen Titel oder Body senden.

```python
{"title": "A" * 1_000_000, "body": "B" * 5_000_000}
```

**Beobachtet**: Kein Längenlimit wird erzwungen — sehr große Payloads werden gespeichert und zurückgegeben. Speicher- und I/O-Nutzung skalieren mit der Payload-Größe. SQLite `TEXT` hat kein praktisches Größenlimit.

**Urteil**: **EXPOSED** — eine `maxlength`-Prüfung hinzufügen:
```php
if (mb_strlen($title) > 500) {
    $errors[] = ['field' => 'title', 'code' => 'too_long', 'message' => 'title must not exceed 500 characters.'];
}
if (mb_strlen($text) > 50000) {
    $errors[] = ['field' => 'body', 'code' => 'too_long', 'message' => 'body must not exceed 50 000 characters.'];
}
```
Außerdem eine Request-Size-Middleware-Begrenzung anwenden, um die gesamten Body-Bytes vor dem Parsen zu begrenzen.

---

### ATK-08 — BCP-47-Groß-/Kleinschreibungs- und Trennzeichen-Bypass

**Angriff**: Varianten versuchen, die semantisch ähnlich, aber syntaktisch falsch sind.

```
PUT /articles/1/translations/EN        → Großgeschriebener Sprachcode
PUT /articles/1/translations/en_US     → Unterstrich-Trennzeichen (POSIX-Stil)
PUT /articles/1/translations/en-us     → Region in Kleinbuchstaben
PUT /articles/1/translations/EN-us     → Gemischte Groß-/Kleinschreibung
PUT /articles/1/translations/fra       → Dreistelliger ISO-639-2-Code
```

**Beobachtet**: Alle von `/^[a-z]{2}(-[A-Z]{2})?$/` abgelehnt:
- `EN` — schlägt `[a-z]` fehl
- `en_US` — `_` schlägt `(-[A-Z]{2})?` fehl
- `en-us` — `us` schlägt `[A-Z]` fehl
- `fra` — drei Zeichen schlagen `{2}` fehl

**Urteil**: **BLOCKED** — der Regex ist präzise; nur exakte BCP-47-`ll`- oder `ll-RR`-Formen bestehen.

---

### ATK-09 — Übersetzung für nicht existierenden Artikel

**Angriff**: Eine Artikel-ID angeben, die nicht existiert.

```bash
curl -s -X PUT http://localhost:8080/articles/99999/translations/en \
  -H 'Content-Type: application/json' \
  -d '{"title":"Ghost","body":"Body"}'
```

**Beobachtet**: `findById(99999)` gibt `null` zurück. Der Handler gibt `404 Not Found` zurück, bevor der Body verarbeitet wird.

**Urteil**: **BLOCKED** — die Existenz des Artikels wird vor dem Schreiben der Übersetzung verifiziert.

---

### ATK-10 — Veröffentlichungs-Manipulation ohne Auth

**Angriff**: Einen Artikel als veröffentlicht erstellen, um die Entwurfsprüfung zu umgehen.

```json
{"default_locale": "en", "published": true}
```

**Beobachtet**: `201 Created` — `published: true` wird sofort akzeptiert. Kein Entwurfsprüfungs- oder Genehmigungsgate existiert; jeder Aufrufer kann veröffentlichen.

**Urteil**: **EXPOSED** (gleiche Ursache wie ATK-01). Eine Veröffentlichungsaktion sollte mindestens eine Autoren-Rolle erfordern. Das `published`-Flag vom Erstellungs-Payload trennen — eine explizite `POST /articles/{id}/publish`-Aktion erfordern, die durch Autorisierung gesichert ist.

---

### ATK-11 — `?locale=` mit unbekannter Locale fällt stillschweigend zurück

**Angriff**: Einen Artikel mit einer Locale anfordern, für die keine Übersetzung gespeichert ist.

```
GET /articles/1?locale=zh-TW
```

**Beobachtet**: `getTranslationWithFallback('zh-TW')` findet keine chinesische Übersetzung und fällt auf `default_locale` zurück (z.B. `en`). Das `locale`-Feld in der Antwort zeigt `en` — gibt an, dass Fallback aufgetreten ist. Kein 404 oder Fehler wird zurückgegeben.

**Urteil**: **BY DESIGN AKZEPTIERT** — stilles Fallback ist korrekt für die Content-Delivery. Aufrufer können Fallback erkennen, indem sie die angeforderte Locale mit `locale` in der Antwort vergleichen. Wenn strikte Locale-Erzwingung benötigt wird, einen `?strict=1`-Parameter hinzufügen.

---

### ATK-12 — Nicht-numerische Artikel-ID

**Angriff**: Einen String oder Float als Artikel-ID übergeben.

```
GET /articles/abc
GET /articles/1.5
GET /articles/0x10
```

**Beobachtet**:
- `GET /articles/abc` → Router stimmt den `{id}`-Parameter ab; `(int) 'abc'` = `0`. `findById(0)` gibt `null` zurück → `404 Not Found`.
- `GET /articles/1.5` → `(int) '1.5'` = `1`. Wenn Artikel 1 existiert, wird er zurückgegeben. Das ist eine stille Abschneidung, kein Fehler.

**Urteil**: **TEILWEISE BLOCKED** — nicht-numerische Strings lösen auf 0 auf und geben 404 zurück. Floats werden stillschweigend abgeschnitten. Für strenge Validierung hinzufügen:
```php
if (!ctype_digit((string) ($params['id'] ?? ''))) {
    return $this->problems->create($request, 'validation-failed', 'Validation Failed', 422, null, [
        'errors' => [['field' => 'id', 'code' => 'invalid', 'message' => 'id must be a positive integer.']],
    ]);
}
```

---

## ATK-Zusammenfassung

| # | Angriffsvektor | Urteil |
|---|---------------|---------|
| ATK-01 | Keine Authentifizierung | EXPOSED |
| ATK-02 | Pfad-Traversierung in Locale | BLOCKED |
| ATK-03 | SQL-Injection über Locale | BLOCKED |
| ATK-04 | IDOR: anderen Artikel übersetzen | EXPOSED |
| ATK-05 | Nur-Leerzeichen-Titel/Body | BLOCKED |
| ATK-06 | XSS in Titel/Body | BY DESIGN AKZEPTIERT |
| ATK-07 | Unbegrenzter Titel/Body | EXPOSED |
| ATK-08 | BCP-47-Groß-/Kleinschreibungs-/Trennzeichen-Bypass | BLOCKED |
| ATK-09 | Übersetzung für nicht existierenden Artikel | BLOCKED |
| ATK-10 | Veröffentlichen ohne Auth | EXPOSED |
| ATK-11 | Unbekannte `?locale=` fällt stillschweigend zurück | BY DESIGN AKZEPTIERT |
| ATK-12 | Nicht-numerische Artikel-ID | TEILWEISE BLOCKED |

**Echte Schwachstellen, die vor der Produktion behoben werden müssen**:
1. **ATK-01 / ATK-04 / ATK-10** — Authentifizierung, Eigentumskontrollen und eine separate Veröffentlichungsaktion hinzufügen
2. **ATK-07** — Titel- und Body-Längenlimits hinzufügen
3. **ATK-12** — `ctype_digit()`-Guard für ID-Parameter hinzufügen

---

## Verwandte Anleitungen

- [`approval-workflow.md`](approval-workflow.md) — Zustandsmaschine für Inhaltsprüfung vor der Veröffentlichung
- [`bulk-status-update.md`](bulk-status-update.md) — Massenmutations-Muster mit Teilerfolg
- [`media-watchlist.md`](media-watchlist.md) — Enum-backed-Status und optionale nullable Felder
