# Slug-Verwaltung — Eindeutige URL-Slugs mit Kollisionsauflösung und Verlauf

URL-sichere Slugs aus Titeln generieren, Kollisionen automatisch auflösen und eine
**Slug-Verlaufstabelle** führen, sodass alte Slugs auf die kanonische URL weiterleiten,
ohne eingehende Links zu unterbrechen.

**Referenzimplementierung:** `FT174 sluglog` in
[hideyukiMORI/NENE2-examples](https://github.com/hideyukiMORI/NENE2-examples)

---

## Schema

```sql
CREATE TABLE articles (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    title      TEXT    NOT NULL,
    slug       TEXT    NOT NULL UNIQUE,   -- aktueller kanonischer Slug
    body       TEXT    NOT NULL,
    created_at TEXT    NOT NULL,
    updated_at TEXT    NOT NULL
);

-- Alte Slugs für Weiterleitungsunterstützung aufbewahren
CREATE TABLE slug_history (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    article_id  INTEGER NOT NULL,
    old_slug    TEXT    NOT NULL UNIQUE,  -- Weiterleitungsquelle; UNIQUE verhindert Duplikate
    replaced_at TEXT    NOT NULL,
    FOREIGN KEY (article_id) REFERENCES articles(id)
);
```

---

## Slug-Generierung

```php
final class SlugHelper
{
    public static function fromTitle(string $title): string
    {
        $slug = mb_strtolower($title);
        $slug = (string) preg_replace('/[^a-z0-9]+/', '-', $slug);
        $slug = trim($slug, '-');
        return $slug !== '' ? $slug : 'untitled';
    }

    /**
     * @param callable(string): bool $exists  Gibt true zurück, wenn Slug bereits vergeben.
     */
    public static function makeUnique(string $base, callable $exists): string
    {
        if (!$exists($base)) {
            return $base;
        }
        $counter = 2;
        while ($exists("{$base}-{$counter}")) {
            $counter++;
        }
        return "{$base}-{$counter}";
    }
}
```

### Eindeutigkeitsprüfung — Beide Tabellen einbeziehen

Bei der Prüfung, ob ein Slug „vergeben" ist, **beide** Tabellen prüfen: `articles.slug` und
`slug_history.old_slug`. Andernfalls könnte ein neuer Artikel einen Slug beanspruchen, der noch
aktiv als Weiterleitungsquelle verwendet wird:

```php
private function slugExists(string $slug): bool
{
    return $this->db->fetchOne('SELECT id FROM articles WHERE slug = ?', [$slug]) !== null
        || $this->db->fetchOne('SELECT id FROM slug_history WHERE old_slug = ?', [$slug]) !== null;
}
```

---

## Slug-Suche mit Weiterleitungshinweis

```php
public function findBySlugWithRedirect(string $slug): ?array
{
    // 1. Aktuellen Slug prüfen (200 OK)
    $article = $this->findBySlug($slug);
    if ($article !== null) {
        return ['found' => $article, 'redirect' => false];
    }

    // 2. Slug-Verlauf prüfen (301 Weiterleitungshinweis)
    $row = $this->db->fetchOne(
        'SELECT article_id FROM slug_history WHERE old_slug = ?', [$slug],
    );
    if ($row === null) {
        return null;  // 404
    }

    $article = $this->findById((int) $row['article_id']);
    return $article !== null ? ['found' => $article, 'redirect' => true] : null;
}
```

Der Handler gibt dann HTTP 301 mit `canonical_slug` und `data` zurück:

```json
// GET /articles/by-slug/old-title  →  301
{
  "redirect": true,
  "canonical_slug": "new-title",
  "data": { "id": 1, "slug": "new-title", ... }
}
```

---

## Slug-Aktualisierung — Verlauf protokollieren

Wenn ein Artikel umbenannt wird, den alten Slug nach `slug_history` verschieben:

```php
if ($newSlug !== $article->slug) {
    // Nur einfügen, wenn noch nicht im Verlauf (idempotent)
    $alreadyIn = $this->db->fetchOne(
        'SELECT id FROM slug_history WHERE old_slug = ?', [$article->slug],
    );
    if ($alreadyIn === null) {
        $this->db->insert(
            'INSERT INTO slug_history (article_id, old_slug, replaced_at) VALUES (?, ?, ?)',
            [$id, $article->slug, $now],
        );
    }
}
```

### Kollisionsbehandlung bei Aktualisierung

Bei der Berechnung des neuen Slugs für einen aktualisierten Artikel den aktuellen Slug des Artikels
aus der „exists"-Prüfung ausschließen — andernfalls würde er unnötigerweise auf `-2` hochzählen:

```php
$newSlug = SlugHelper::makeUnique(
    $newSlugBase,
    fn (string $s): bool => $s !== $article->slug && $this->slugExists($s),
);
```

---

## Endpunkte

| Methode | Pfad | Beschreibung |
|---|---|---|
| `POST`  | `/articles` | Artikel erstellen — Slug wird automatisch aus Titel abgeleitet |
| `GET`   | `/articles/{id}` | Per numerischer ID abrufen |
| `GET`   | `/articles/by-slug/{slug}` | Per Slug abrufen (200 aktuell / 301 historisch / 404) |
| `PUT`   | `/articles/{id}` | Titel/Body/Slug aktualisieren; alter Slug → Verlauf |
| `GET`   | `/articles/{id}/slug-history` | Historische Slugs auflisten |

---

## Kollisionsszenarien

| Szenario | Ergebnis |
|---|---|
| Erstes „Hallo Welt" | `hallo-welt` |
| Zweites „Hallo Welt" | `hallo-welt-2` |
| Drittes „Hallo Welt" | `hallo-welt-3` |
| Artikel umbenannt auf einen bereits vergebenen Slug | `vergeben-slug-2` |
| Gleicher Titel, keine Slug-Änderung | Kein Verlaufseintrag, Slug unverändert |
| Alter Slug passt zu einem Verlaufseintrag | 301 Weiterleitungsantwort |

---

## Domain-Schicht-Struktur

```
src/Article/
├── Article.php
├── ArticleRepository.php   # create / findBySlug / findBySlugWithRedirect / update / slugHistory
├── SlugHelper.php          # fromTitle() + makeUnique()
└── ArticleNotFoundException.php
```

---

## Siehe auch

- [Soft Delete](./soft-delete.md) — Slug-Verlauf mit soft-gelöschten Datensätzen kombinieren
- [Inhalts-Versionierung](./content-versioning.md) — Versionshistorie neben Slug-Verlauf
- [Inhalts-Entwurfs-Lebenszyklus](./content-draft-lifecycle.md) — Slug-Verhalten über Entwurfszustände hinweg
