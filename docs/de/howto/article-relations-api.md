# How-to: Artikel-Relations-API

> **FT-Referenz**: FT334 (`NENE2-FT/relatedlog`) — Typisierte Artikel-zu-Artikel-Beziehungen mit automatischer inverser Erstellung, symmetrische und asymmetrische Beziehungstypen, Filterung nach Typ und eingebettete Beziehungs-Stubs in GET-Antworten, 17 Tests / 40+ Assertions PASS.

Diese Anleitung zeigt, wie man typisierte Beziehungen zwischen Inhaltselementen modelliert — `related`, `sequel`, `prequel`, `reference` — mit automatischer inversiver Verwaltung, damit jede Beziehung in beiden Richtungen konsistent bleibt.

## Schema

```sql
CREATE TABLE articles (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    title      TEXT    NOT NULL,
    body       TEXT    NOT NULL,
    created_at TEXT    NOT NULL
);

CREATE TABLE article_relations (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    article_id    INTEGER NOT NULL REFERENCES articles(id),
    related_id    INTEGER NOT NULL REFERENCES articles(id),
    relation_type TEXT    NOT NULL,
    created_at    TEXT    NOT NULL,
    UNIQUE(article_id, related_id, relation_type)
);
```

`UNIQUE(article_id, related_id, relation_type)` verhindert doppelte Beziehungskanten für den gleichen Typ. Verschiedene Typen zwischen demselben Paar sind erlaubt.

## Beziehungstypen & Inverse

| Eingereichter Typ | Automatisch erstellte Inverse |
|---|---|
| `related` | `related` (symmetrisch) |
| `sequel` | `prequel` |
| `prequel` | `sequel` |
| `reference` | `reference` (symmetrisch) |

Wenn A→B `sequel` ist, fügt der Server atomisch B→A als `prequel` ein. Das Löschen von A→B löscht auch B→A.

## Endpunkte

| Methode | Pfad | Beschreibung |
|--------|------|-------------|
| `POST` | `/articles` | Artikel erstellen |
| `GET` | `/articles/{id}` | Artikel mit eingebetteten Beziehungen abrufen |
| `POST` | `/articles/{id}/relations` | Beziehung hinzufügen |
| `GET` | `/articles/{id}/relations` | Beziehungen auflisten (optional ?type=) |
| `DELETE` | `/articles/{id}/relations/{relatedId}?type=` | Beziehung (und ihre Inverse) entfernen |

## Artikel erstellen

```php
POST /articles
{"title": "Hello", "body": "World"}
→ 201  {"id": 1, "title": "Hello", "body": "World", "created_at": "..."}

// Fehlender title
POST /articles  {"body": "No title"}
→ 422

// Fehlender body
POST /articles  {"title": "No body"}
→ 422
```

## GET-Artikel mit eingebetteten Beziehungen

```php
GET /articles/1
→ 200
{
  "data": {"id": 1, "title": "Intro", ...},
  "relations": [
    {
      "relation": {"relation_type": "sequel"},
      "related":  {"id": 2, "title": "Follow-up"}
    }
  ]
}

// Noch keine Beziehungen
GET /articles/1
→ 200  {"data": {...}, "relations": []}

GET /articles/9999
→ 404
```

## Beziehung hinzufügen

```php
POST /articles/1/relations
{"related_id": 2, "relation_type": "sequel"}
→ 201  {"relation_type": "sequel", "article_id": 1, "related_id": 2}

// Inverse wird automatisch eingefügt: Artikel 2 hat jetzt eine "prequel"-Beziehung zu 1
GET /articles/2/relations
→ 200  {"data": [{"relation_type": "prequel", "related_id": 1}]}
```

### Symmetrische Beziehung

```php
POST /articles/1/relations
{"related_id": 2, "relation_type": "related"}
→ 201

// B bekommt automatisch auch eine "related"-Beziehung zu A
GET /articles/2/relations
→ 200  {"data": [{"related_id": 1, "relation_type": "related"}]}
```

### Fehlerfälle

```php
// Unbekannte related_id
POST /articles/1/relations  {"related_id": 9999, "relation_type": "related"}
→ 404

// Duplikat (gleiches Paar + gleicher Typ existiert bereits)
POST /articles/1/relations  {"related_id": 2, "relation_type": "related"}
→ 409

// Selbst-Beziehung
POST /articles/1/relations  {"related_id": 1, "relation_type": "related"}
→ 422

// Ungültiger Beziehungstyp
POST /articles/1/relations  {"related_id": 2, "relation_type": "not-a-type"}
→ 422
```

### Mehrere Typen zwischen dem gleichen Paar

Das gleiche Paar kann mehrere verschiedene Beziehungstypen haben:

```php
POST /articles/1/relations  {"related_id": 2, "relation_type": "related"}   → 201
POST /articles/1/relations  {"related_id": 2, "relation_type": "reference"} → 201

GET /articles/1/relations
→ 200  {"data": [
    {"related_id": 2, "relation_type": "related"},
    {"related_id": 2, "relation_type": "reference"}
  ]}
```

## Beziehungen auflisten

```php
// Alle Beziehungen
GET /articles/1/relations
→ 200  {"data": [{...}, {...}]}

// Nach Typ filtern
GET /articles/1/relations?type=sequel
→ 200  {"data": [{"related_id": 2, "relation_type": "sequel"}]}

// Unbekannter Artikel
GET /articles/9999/relations
→ 404
```

## Beziehung löschen

```php
DELETE /articles/1/relations/2?type=related
→ 200  {"deleted": true}

// Inverse wird ebenfalls automatisch entfernt
GET /articles/2/relations
→ 200  {"data": []}  // hat keine "related"-Beziehung zu 1 mehr

// Nicht gefunden
DELETE /articles/1/relations/2?type=related
→ 404

// Fehlender type-Query-Parameter
DELETE /articles/1/relations/2
→ 422
```

## Implementierung — Atomare Inverse-Verwaltung

```php
private function addRelation(int $articleId, int $relatedId, string $type): void
{
    $this->db->beginTransaction();

    $inverse = match ($type) {
        'sequel'    => 'prequel',
        'prequel'   => 'sequel',
        default     => $type,   // related, reference → symmetrisch
    };

    $this->repo->insert($articleId, $relatedId, $type);
    $this->repo->insert($relatedId, $articleId, $inverse);

    $this->db->commit();
}

private function removeRelation(int $articleId, int $relatedId, string $type): void
{
    $inverse = match ($type) {
        'sequel'    => 'prequel',
        'prequel'   => 'sequel',
        default     => $type,
    };

    $this->db->beginTransaction();
    $this->repo->delete($articleId, $relatedId, $type);
    $this->repo->delete($relatedId, $articleId, $inverse);
    $this->db->commit();
}
```

Beide Einfügungen/Löschungen in eine Transaktion einschließen — wenn eine fehlschlägt, wird keine committed.

---

## Was Sie NICHT tun sollten

| Anti-Pattern | Risiko |
|---|---|
| Beziehung einfügen, ohne Artikelexistenz zu prüfen | FK-Verletzung oder stilles 0-Zeilen-Insert; immer 404 bei unbekannten IDs |
| Keine Transaktion um Vorwärts- + Inverse-Einfügung | Teilfehler hinterlässt asymmetrische Daten (A→B existiert, aber B→A nicht) |
| Kein `UNIQUE(article_id, related_id, relation_type)` | Doppelte Kanten blähen die Listenanzahl auf |
| Selbst-Beziehungen erlauben | Zyklen bei der Beziehungsdurchquerung; `sequel` von sich selbst hat keine Bedeutung |
| Symmetrische Annahme für alle Typen hart codieren | `sequel`→`sequel` (falsch) statt `prequel` |
| Nur die Vorwärtskante löschen | Inverse-Waise bleibt; B "sieht" A immer noch als Prequel, nachdem A gelöscht wurde |
