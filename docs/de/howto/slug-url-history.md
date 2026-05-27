# How-to: Slug-URL-Verwaltung mit Verlauf

> **FT-Referenz**: FT339 (`NENE2-FT/sluglog`) — Automatisch generierte Slugs aus Titeln, Kollisionszähler,
> Slug-Verlauf für 301-Weiterleitungen bei alten Slugs, explizite Slug-Überschreibung,
> Schwachstellenbewertung; 17 Tests / 50+ Assertions PASS.

Diese Anleitung zeigt, wie saubere URL-Slugs aus Inhalts-Titeln generiert, Kollisionen mit sequenziellen
Suffixen behandelt, alte Slugs in einer Verlaufstabelle für dauerhafte Weiterleitungen aufbewahrt und
häufige Angriffsvektoren verhindert werden.

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
| `POST`  | `/articles` | Artikel erstellen (automatischer Slug aus Titel) |
| `PUT`   | `/articles/{id}` | Artikel aktualisieren (Slug wird bei Titeländerung neu generiert) |
| `GET`   | `/articles/by-slug/{slug}` | Per aktuellem oder altem Slug abrufen |
| `GET`   | `/articles/{id}/slug-history` | Slug-Verlauf auflisten |

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
1. Alles in Kleinbuchstaben
2. Nicht-alphanumerische Zeichen durch `-` ersetzen
3. Aufeinanderfolgende Bindestriche zusammenfassen
4. Führende/abschließende Bindestriche entfernen
5. `"untitled"` zurückgeben wenn Ergebnis leer

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

Bei einer Titeländerung wird der neue Slug abgeleitet und der alte Slug in `slug_history` gespeichert.

```php
// Gleicher Titel — Slug unverändert, kein Verlaufseintrag
PUT /articles/1  {"title": "New Title", "body": "Different body."}
→ 200  {"slug": "new-title"}  // gleicher Slug

// Explizite Slug-Überschreibung
PUT /articles/1  {"title": "New Title", "body": "Body.", "slug": "custom-url-here"}
→ 200  {"slug": "custom-url-here"}

// Kollision bei Aktualisierung — automatisch aufgelöst
// (wenn "popular" existiert, Umbenennung auf "popular-2")
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

// Alter Slug → 301 Weiterleitung
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

301-Antworten teilen Crawlern/Clients mit, ihre Links auf den kanonischen Slug zu aktualisieren.

## Slug-Verlauf

```php
GET /articles/1/slug-history
→ 200
{
  "current_slug": "new-title",
  "slug_history": [
    {"old_slug": "my-first-post", "created_at": "..."}
  ]
}

// Neuer Artikel — leerer Verlauf
{"current_slug": "fresh", "slug_history": []}

// Unbekannter Artikel → 404
GET /articles/9999/slug-history → 404
```

Verlaufseinträge akkumulieren sich nur, wenn sich der Slug tatsächlich ändert. Das Aktualisieren
des Body ohne Titeländerung lässt den Verlauf unverändert.

---

## Schwachstellenbewertung

### V-01 — Pfad-Traversal via Slug ✅ SICHER

**Risiko**: Angreifer sendet `GET /articles/by-slug/../../../etc/passwd` um Server-Verzeichnisse zu durchsuchen.
**Befund**: SICHER — Slug-Lookups sind SQL `WHERE slug = ?` mit einem gebundenen Parameter. Das Pfadsegment wird nie als Dateisystempfad interpretiert. Routing parst den Pfad bevor er den Controller erreicht; `../` in einem URL-Pfad wird durch die HTTP-Schicht kanonisiert.

---

### V-02 — SQL-Injection via Slug in URL ✅ SICHER

**Risiko**: `GET /articles/by-slug/' OR '1'='1` gibt alle Artikel preis.
**Befund**: SICHER — Slug wird als gebundener Parameter in `WHERE slug = ?` übergeben. SQL-Injection ist unabhängig vom Slug-Wert unmöglich.

---

### V-03 — Slug-Enumeration (Brute-Force-Entdeckung) ⚠️ EXPONIERT

**Risiko**: Angreifer iteriert übliche Slugs (`/articles/by-slug/admin`, `/articles/by-slug/secret-doc`) um private Artikel zu entdecken.
**Befund**: EXPONIERT — Slugs sind vorhersehbare Ableitungen menschenlesbarer Titel. Auf `GET /articles/by-slug/{slug}` wird weder Ratenbegrenzung noch Authentifizierung erzwungen. Abhilfe: Authentifizierung für private Inhalte erforderlich machen; IP-basierte Ratenbegrenzung hinzufügen; opake IDs für sensible Ressourcen in Betracht ziehen.

---

### V-04 — Slug-Verlauf IDOR ✅ SICHER

**Risiko**: Angreifer ruft `GET /articles/{id}/slug-history` für den Artikel eines anderen Benutzers auf, um vergangene Titel zu entdecken.
**Befund**: SICHER — Slug-Verlauf ist öffentliche Metadaten. Wenn Artikel öffentlich sind, gilt das auch für ihren Verlauf. Wenn Artikel Autorisierung erfordern, dieselbe Auth-Prüfung konsequent auf den `/slug-history`-Endpunkt anwenden.

---

### V-05 — Unendliche Weiterleitungsschleife via Slug-Verlauf ✅ SICHER

**Risiko**: Artikel A wird auf Slug B umbenannt; Artikel B wird auf Slug A umbenannt — `GET /by-slug/a` → Weiterleitung auf B → Weiterleitung auf A (unendliche Schleife).
**Befund**: SICHER — Die Implementierung sucht den **aktuellen** Slug in `articles.slug`, prüft dann `slug_history` nur für alte Slugs. Eine 301-Antwort zeigt stets auf den aktuellen kanonischen Slug. Clients, die Weiterleitungen folgen, erreichen den kanonischen Slug in einem Hop.

---

### V-06 — Slug-Kollisionsmissbrauch (Sequenzieller Zählererschöpfungsangriff) ⚠️ EXPONIERT

**Risiko**: Angreifer erstellt tausende Artikel mit dem Titel „popular" um „popular-2" bis „popular-9999" zu reservieren, löscht sie dann — oder erzwingt teure Zählerscans.
**Befund**: EXPONIERT — Keine Ratenbegrenzung bei der Artikelerstellung. Der `makeUnique`-Zählerscan ist O(n) DB-Abfragen. Abhilfe: POST /articles pro Benutzer ratenbegrenzen; Slug-Zähler bei einem vernünftigen Limit deckeln (z.B. 99); zufälliges Suffix nach Schwellenwert verwenden.

---

### V-07 — Explizite Slug-Injection (Slug eines anderen Artikels überschreiben) ✅ SICHER

**Risiko**: Angreifer verwendet `PUT /articles/2  {"slug": "popular"}` wo „popular" zu Artikel 1 gehört.
**Befund**: SICHER — `articles.slug` hat einen `UNIQUE`-Constraint. Der Versuch, einen bereits von einem anderen Artikel beanspruchten Slug zu setzen, löst einen DB-Constraint-Verstoß aus, der als 409 Conflict übersetzt wird.

---

### V-08 — Unicode/Homograph-Slug-Angriff ⚠️ EXPONIERT

**Risiko**: Angreifer erstellt einen Artikel mit einem Unicode-Titel, der zu denselben Bytes wie ein vorhandener ASCII-Slug normalisiert (z.B. `café` → `caf-`) um eine visuell verwirrende URL zu erzeugen.
**Befund**: EXPONIERT — `SlugHelper::fromTitle()` verwendet `preg_replace('/[^a-z0-9]+/', '-', strtolower($title))`. Nicht-ASCII-Zeichen werden durch `-` ersetzt, was unerwartete Kollisionen oder leere Slugs verursachen kann. Abhilfe: Unicode vor der Slug-Generierung auf ASCII-Transliteration normalisieren (z.B. `iconv`); alle Nicht-ASCII nach Normalisierung als `-` behandeln.

---

### V-09 — XSS via im Slug gespeichertem Titel ✅ SICHER

**Risiko**: Titel `<script>alert(1)</script>` erzeugt Slug `script-alert-1-script` — sicherer alphanumerischer Output.
**Befund**: SICHER — `SlugHelper::fromTitle()` entfernt alle nicht-alphanumerischen Zeichen zu `-`. Der Slug-Output ist stets `[a-z0-9-]`, was HTML-Injection durch den Slug unmöglich macht.

---

### V-10 — Alter Slug gibt umbenannten Inhalt preis ⚠️ EXPONIERT

**Risiko**: Artikel von „secret-plan-v1" auf „public-announcement" umbenannt; Angreifer verwendet alten Slug um den Originaltitel über die `canonical_slug`-Weiterleitungsantwort zu entdecken.
**Befund**: EXPONIERT — Die 301-Antwort gibt den neuen kanonischen Slug preis, der den umbenannten Inhalt enthüllen kann. Der Slug-Verlaufs-Endpunkt gibt auch alle alten Namen preis. Für sensible Umbenennungen alte Slugs ohne Preisgabe des neuen Speicherorts tombstonen; oder opake Slugs verwenden.

---

### VULN-Zusammenfassung

| ID | Schwachstelle | Befund |
|----|---------------|--------|
| V-01 | Pfad-Traversal via Slug | ✅ SICHER |
| V-02 | SQL-Injection via Slug | ✅ SICHER |
| V-03 | Slug-Enumeration | ⚠️ EXPONIERT |
| V-04 | Slug-Verlauf IDOR | ✅ SICHER |
| V-05 | Unendliche Weiterleitungsschleife | ✅ SICHER |
| V-06 | Kollisionszählererschöpfung | ⚠️ EXPONIERT |
| V-07 | Explizite Slug-Überschreibung | ✅ SICHER |
| V-08 | Unicode-Homograph-Angriff | ⚠️ EXPONIERT |
| V-09 | XSS via Titel | ✅ SICHER |
| V-10 | Alter Slug gibt umbenannten Inhalt preis | ⚠️ EXPONIERT |

**6 SICHER, 4 EXPONIERT** — Artikelerstellung ratenbegrenzen; Authentifizierung für private Inhalte hinzufügen; Unicode vor Slug-Generierung normalisieren; für sensible Umbenennungen ausschließlich Tombstone-Slug-Verlauf in Betracht ziehen.

---

## Was NOT zu tun ist

| Anti-Muster | Risiko |
|---|---|
| Slug direkt in SQL interpolieren | SQL-Injection via Slug-Pfadparameter |
| Slug-Verlauf bei Artikel-Löschung hard-deleten | Alte URLs geben 404 statt 301 zurück; SEO und Link-Rot |
| Kein `UNIQUE`-Constraint auf `articles.slug` | Gleichzeitige Inserts erzeugen doppelte Slugs |
| Alten Slug bei Titeländerung unverändert lassen | Slug-Drift — URL spiegelt Inhalt nicht mehr wider |
| Kein Zähler-Limit in `makeUnique` | Angreifer erschöpft Zähler durch Massenerstellung |
| `!==` zum Vergleichen vorhandener Slugs verwenden | Typkoerzionstücken; stets `===` für Slug-Vergleiche verwenden |
