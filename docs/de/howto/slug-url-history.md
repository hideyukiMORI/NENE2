# Anleitung: Slug-URL-Verwaltung mit Historie

> **FT-Referenz**: FT339 (`NENE2-FT/sluglog`) — Automatisch generierte Slugs aus Titeln, Kollisionszähler, Slug-Historie für alte-Slug-301-Weiterleitungen, explizite Slug-Überschreibung, Schwachstellen-Assessment, 17 Tests / 50+ Assertions BESTANDEN.

Diese Anleitung zeigt, wie saubere URL-Slugs aus Inhaltstiteln generiert werden, Kollisionen mit sequenziellen Suffixen behandelt werden, alte Slugs in einer Historientabelle für permanente Weiterleitungen gespeichert werden und häufige Angriffsvektoren verhindert werden.

## Schema

```sql
CREATE TABLE articles (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    title      TEXT    NOT NULL,
    body       TEXT    NOT NULL,
    slug       TEXT    NOT NULL UNIQUE,
    created_at TEXT    NOT NULL,
    updated_at TEXT    NOT NULL
);

CREATE TABLE slug_history (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    article_id INTEGER NOT NULL REFERENCES articles(id),
    old_slug   TEXT    NOT NULL,
    created_at TEXT    NOT NULL
);
```

## Endpunkte

| Methode | Pfad | Beschreibung |
|---------|------|-------------|
| `POST` | `/articles` | Artikel erstellen (automatischer Slug aus Titel) |
| `PUT`  | `/articles/{id}` | Artikel aktualisieren (Slug wird bei Titeländerung neu generiert) |
| `GET`  | `/articles/by-slug/{slug}` | Per aktuellem oder altem Slug abrufen |
| `GET`  | `/articles/{id}/slug-history` | Slug-Historie auflisten |

## Slug-Generierung

### `SlugHelper::fromTitle()`

```php
SlugHelper::fromTitle('Hello World')          // → "hello-world"
SlugHelper::fromTitle('PHP 8.4: New Features!') // → "php-8-4-new-features"
SlugHelper::fromTitle('  --Hello--  ')        // → "hello"
SlugHelper::fromTitle('')                     // → "untitled"
SlugHelper::fromTitle('---')                  // → "untitled"
```

Regeln:
1. Alles in Kleinbuchstaben umwandeln
2. Nicht-alphanumerische Zeichen durch `-` ersetzen
3. Aufeinanderfolgende Bindestriche zusammenfassen
4. Führende/nachfolgende Bindestriche trimmen
5. `"untitled"` zurückgeben wenn das Ergebnis leer ist

```php
public static function fromTitle(string $title): string
{
    $slug = strtolower($title);
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
    $slug = trim($slug, '-');
    return $slug !== '' ? $slug : 'untitled';
}
```

### Kollisionsauflösung

```php
POST /articles  {"title": "Hello", "body": "..."}  → 201  {"slug": "hello"}
POST /articles  {"title": "Hello", "body": "..."}  → 201  {"slug": "hello-2"}
POST /articles  {"title": "Hello", "body": "..."}  → 201  {"slug": "hello-3"}
```

```php
public static function makeUnique(string $base, callable $isTaken): string
{
    if (!$isTaken($base)) {
        return $base;
    }

    $i = 2;
    while ($isTaken("{$base}-{$i}")) {
        $i++;
    }

    return "{$base}-{$i}";
}
```

`$isTaken` ist ein DB-Lookup-Callback: `fn(string $s): bool => (bool) $repo->findBySlug($s)`.

## Artikel erstellen

```php
POST /articles
{"title": "My First Post", "body": "Content here."}
→ 201
{
  "id": 1,
  "title": "My First Post",
  "slug": "my-first-post",
  "body": "...",
  "created_at": "..."
}
```

## Artikel aktualisieren

```php
PUT /articles/1
{"title": "New Title", "body": "Updated content."}
→ 200  {"slug": "new-title", ...}
```

Wenn sich der Titel ändert, wird der neue Slug abgeleitet und der alte Slug in `slug_history` gespeichert.

```php
// Gleicher Titel — Slug unverändert, kein Historie-Eintrag
PUT /articles/1  {"title": "New Title", "body": "Different body."}
→ 200  {"slug": "new-title"}  // gleicher Slug

// Explizite Slug-Überschreibung
PUT /articles/1  {"title": "New Title", "body": "Body.", "slug": "custom-url-here"}
→ 200  {"slug": "custom-url-here"}

// Kollision bei Aktualisierung — automatisch aufgelöst
// (wenn "popular" existiert, wird in "popular-2" umbenannt)
PUT /articles/2  {"title": "Popular", "body": "Body."}
→ 200  {"slug": "popular-2"}

// Unbekannter Artikel
PUT /articles/9999  {"title": "X", "body": "Y"}
→ 404
```

## Per Slug abrufen

```php
// Aktueller Slug → 200
GET /articles/by-slug/new-title
→ 200  {"id": 1, "slug": "new-title", "title": "New Title", ...}

// Alter Slug → 301-Weiterleitung
GET /articles/by-slug/my-first-post
→ 301
{
  "redirect": true,
  "canonical_slug": "new-title"
}

// Unbekannt → 404
GET /articles/by-slug/does-not-exist
→ 404
```

301-Antworten teilen Crawlern/Clients mit, ihre Links zum kanonischen Slug zu aktualisieren.

## Slug-Historie

```php
GET /articles/1/slug-history
→ 200
{
  "current_slug": "new-title",
  "slug_history": [
    {"old_slug": "my-first-post", "created_at": "..."}
  ]
}

// Neuer Artikel — leere Historie
{"current_slug": "fresh", "slug_history": []}

// Unbekannter Artikel → 404
GET /articles/9999/slug-history → 404
```

Historie-Einträge akkumulieren sich nur, wenn der Slug tatsächlich geändert wird. Das Aktualisieren des Bodys ohne Titeländerung lässt die Historie unberührt.

---

## Schwachstellen-Assessment

### V-01 — Path-Traversal via Slug ✅ SAFE

**Risiko**: Angreifer sendet `GET /articles/by-slug/../../../etc/passwd`, um Serververzeichnisse zu traversieren.
**Ergebnis**: SAFE — Slug-Lookups sind SQL `WHERE slug = ?` mit gebundenem Parameter. Das Pfadsegment wird nie als Dateisystempfad interpretiert. Das Routing parst den Pfad bevor er den Controller erreicht; `../` in einem URL-Pfad wird von der HTTP-Schicht kanonisiert.

---

### V-02 — SQL-Injection via Slug in URL ✅ SAFE

**Risiko**: `GET /articles/by-slug/' OR '1'='1` gibt alle Artikel preis.
**Ergebnis**: SAFE — Slug wird als gebundener Parameter in `WHERE slug = ?` übergeben. SQL-Injection ist unabhängig vom Slug-Wert unmöglich.

---

### V-03 — Slug-Enumeration (Brute-Force-Entdeckung) ⚠️ EXPOSED

**Risiko**: Angreifer iteriert häufige Slugs (`/articles/by-slug/admin`, `/articles/by-slug/secret-doc`), um private Artikel zu entdecken.
**Ergebnis**: EXPOSED — Slugs sind vorhersehbare Ableitungen von menschenlesbaren Titeln. Kein Rate-Limiting oder Authentifizierung wird für `GET /articles/by-slug/{slug}` durchgesetzt. Abhilfe: Authentifizierung für private Inhalte erfordern; Pro-IP-Rate-Limiting hinzufügen; opake IDs für sensible Ressourcen in Betracht ziehen.

---

### V-04 — Slug-Historie IDOR ✅ SAFE

**Risiko**: Angreifer ruft `GET /articles/{id}/slug-history` für den Artikel eines anderen Benutzers auf, um vergangene Titel zu entdecken.
**Ergebnis**: SAFE — Slug-Historie sind öffentliche Metadaten. Wenn Artikel öffentlich sind, ist ihre Historie es auch. Wenn Artikel Autorisierung erfordern, dieselbe Auth-Prüfung konsistent auf den `/slug-history`-Endpunkt anwenden.

---

### V-05 — Unendliche Weiterleitungsschleife via Slug-Historie ✅ SAFE

**Risiko**: Artikel A wird in Slug B umbenannt; Artikel B wird in Slug A umbenannt — `GET /by-slug/a` → Weiterleitung zu B → Weiterleitung zu A (unendliche Schleife).
**Ergebnis**: SAFE — Die Implementierung sucht den **aktuellen** Slug in `articles.slug`, dann prüft `slug_history` nur für alte Slugs. Eine 301-Antwort zeigt immer auf das aktuelle Kanonische. Clients, die Weiterleitungen folgen, erreichen das Kanonische in einem Hop.

---

### V-06 — Slug-Kollisions-Missbrauch (Sequenzieller Zähler-Erschöpfung) ⚠️ EXPOSED

**Risiko**: Angreifer erstellt tausende Artikel mit dem Titel „popular", um „popular-2" bis „popular-9999" zu reservieren und dann zu löschen — oder um teures Zähler-Scanning zu erzwingen.
**Ergebnis**: EXPOSED — Kein Rate-Limiting für die Artikelerstellung. Das `makeUnique`-Zähler-Scan ist O(n) DB-Abfragen. Abhilfe: Rate-Limit POST /articles pro Benutzer; Slug-Zähler bei einem vernünftigen Limit begrenzen (z. B. 99); nach Schwellenwert zufälliges Suffix verwenden.

---

### V-07 — Explizite Slug-Injektion (Überschreiben des Slugs eines anderen Artikels) ✅ SAFE

**Risiko**: Angreifer verwendet `PUT /articles/2  {"slug": "popular"}`, wo „popular" Artikel 1 gehört.
**Ergebnis**: SAFE — `articles.slug` hat eine `UNIQUE`-Bedingung. Der Versuch, einen bereits von einem anderen Artikel beanspruchten Slug zu setzen, löst eine DB-Bedingungsverletzung aus, die in 409 Conflict übersetzt wird.

---

### V-08 — Unicode/Homograph-Slug-Angriff ⚠️ EXPOSED

**Risiko**: Angreifer erstellt einen Artikel mit einem Unicode-Titel, der auf dieselben Bytes wie ein vorhandener ASCII-Slug normalisiert (z. B. `café` → `caf-`), um eine visuell verwirrende URL zu erstellen.
**Ergebnis**: EXPOSED — `SlugHelper::fromTitle()` verwendet `preg_replace('/[^a-z0-9]+/', '-', strtolower($title))`. Nicht-ASCII-Zeichen werden durch `-` ersetzt, was unerwartete Kollisionen oder leere Slugs verursachen kann. Abhilfe: Unicode vor der Slug-Generierung auf ASCII-Transliteration normalisieren (z. B. `iconv`); alle Nicht-ASCII nach der Normalisierung als `-` behandeln.

---

### V-09 — XSS via Titel im Slug gespeichert ✅ SAFE

**Risiko**: Titel `<script>alert(1)</script>` erzeugt Slug `script-alert-1-script` — sichere alphanumerische Ausgabe.
**Ergebnis**: SAFE — `SlugHelper::fromTitle()` entfernt alle nicht-alphanumerischen Zeichen zu `-`. Die Slug-Ausgabe ist immer `[a-z0-9-]`, was HTML-Injection durch den Slug unmöglich macht.

---

### V-10 — Alter Slug enthüllt umbenannten Inhalt ⚠️ EXPOSED

**Risiko**: Artikel von „secret-plan-v1" in „public-announcement" umbenannt; Angreifer verwendet alten Slug, um den Originaltitel über die Weiterleitungsantwort `canonical_slug` zu entdecken.
**Ergebnis**: EXPOSED — Die 301-Antwort gibt den neuen kanonischen Slug preis, der den umbenannten Inhalt enthüllen kann. Der Slug-Historie-Endpunkt gibt auch alle alten Namen preis. Für sensible Umbenennungen alte Slugs als Tombstone markieren ohne den neuen Ort preiszugeben; oder opake Slugs verwenden.

---

### VULN-Zusammenfassung

| ID | Schwachstelle | Ergebnis |
|----|---------------|---------|
| V-01 | Path-Traversal via Slug | ✅ SAFE |
| V-02 | SQL-Injection via Slug | ✅ SAFE |
| V-03 | Slug-Enumeration | ⚠️ EXPOSED |
| V-04 | Slug-Historie IDOR | ✅ SAFE |
| V-05 | Unendliche Weiterleitungsschleife | ✅ SAFE |
| V-06 | Kollisionszähler-Erschöpfung | ⚠️ EXPOSED |
| V-07 | Explizite Slug-Überschreibung | ✅ SAFE |
| V-08 | Unicode-Homograph-Angriff | ⚠️ EXPOSED |
| V-09 | XSS via Titel | ✅ SAFE |
| V-10 | Alter Slug enthüllt umbenannten Inhalt | ⚠️ EXPOSED |

**6 SAFE, 4 EXPOSED** — Artikelerstellung rate-limitieren; Authentifizierung für private Inhalte hinzufügen; Unicode vor der Slug-Generierung normalisieren; für sensible Umbenennungen nur Tombstone-Slug-Historie in Betracht ziehen.

---

## Was man nicht tun sollte

| Anti-Muster | Risiko |
|---|---|
| Slug direkt in SQL interpolieren | SQL-Injection via Slug-Pfadparameter |
| Slug-Historie bei Artikellöschung hart löschen | Alte URLs geben 404 statt 301 zurück; SEO und Link-Rot |
| Keine `UNIQUE`-Bedingung auf `articles.slug` | Gleichzeitige Inserts erstellen doppelte Slugs |
| Alten Slug bei Titelaktualisierung unverändert zurückgeben | Slug-Drift — URL spiegelt den Inhalt nicht mehr wider |
| Kein Zähler-Cap in `makeUnique` | Angreifer erschöpft Zähler durch Massenerstellung |
| `!==` zum Vergleich vorhandener Slugs verwenden | Typ-Konvertierungs-Überraschungen; für Slug-Vergleich immer `===` verwenden |
