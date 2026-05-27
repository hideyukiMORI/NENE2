# Content Relations — Typisierte M:N-Selbstreferenzielle Verknüpfungen

Artikel (oder beliebige Ressourcen) mithilfe einer **Verbindungstabelle mit einer `relation_type`-Spalte** miteinander verknüpfen. Unterstützung asymmetrischer Typen (Sequel ↔ Prequel) mit automatischem inversen Einfügen sowie symmetrischer Typen (related, reference) mit derselben inversen Logik.

**Referenzimplementierung:** `FT173 relatedlog` in
[hideyukiMORI/NENE2-examples](https://github.com/hideyukiMORI/NENE2-examples)

---

## Wann dieses Muster verwenden

| Dieses Muster verwenden, wenn… | Alternativen in Betracht ziehen, wenn… |
|-------------------------------|----------------------------------------|
| Ressourcen mit typisierten Kanten miteinander verknüpft sind | Nur nicht-typisierte "verwandte" Links benötigt werden |
| Asymmetrische Kanten benötigt werden (A ist Sequel von B) | Ein einfaches Tagging-System ausreicht |
| Bidirektionale Abfragen schnell bleiben müssen | Graph-Traversierung über viele Hops erforderlich ist |
| Relationstyp das UI-Verhalten beeinflusst ("Sequels anzeigen") | — |

---

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
    article_id    INTEGER NOT NULL,
    related_id    INTEGER NOT NULL,
    relation_type TEXT    NOT NULL,
    -- 'related' | 'sequel' | 'prequel' | 'reference'
    created_at    TEXT    NOT NULL,
    UNIQUE (article_id, related_id, relation_type),
    FOREIGN KEY (article_id) REFERENCES articles(id),
    FOREIGN KEY (related_id) REFERENCES articles(id),
    CHECK (article_id != related_id)      -- Selbst-Relation auf DB-Ebene verhindert
);
```

### Design-Hinweise

- Der `UNIQUE (article_id, related_id, relation_type)`-Constraint verhindert doppelte Kanten gleichen Typs. Dasselbe Paar kann **mehrere** Typen haben (z.B. A → B sowohl `related` als auch `reference`).
- `CHECK (article_id != related_id)` verhindert Selbst-Schleifen auf DB-Ebene.
- **Beide Richtungen werden gespeichert**: Das Hinzufügen von `A → B (sequel)` fügt auch `B → A (prequel)` ein. Dadurch werden Abfragen pro Artikel trivial (`WHERE article_id = ?`) ohne Joins.

---

## Relationstypen

```php
enum RelationType: string
{
    case Related   = 'related';    // symmetrisch: A related B ↔ B related A
    case Sequel    = 'sequel';     // asymmetrisch: A sequel→B ↔ B prequel→A
    case Prequel   = 'prequel';    // asymmetrisch: Inverse von sequel
    case Reference = 'reference';  // symmetrisch: bidirektionales Zitat

    public function inverse(): self
    {
        return match ($this) {
            self::Sequel  => self::Prequel,
            self::Prequel => self::Sequel,
            default       => $this,  // related, reference sind selbst-invers
        };
    }
}
```

---

## Kernoperation: Relation mit automatischer Inversen hinzufügen

```php
public function addRelation(int $articleId, int $relatedId, RelationType $type, string $now): ArticleRelation
{
    // 1. Sicherstellen, dass beide Artikel existieren
    // 2. Auf Duplikat prüfen (UNIQUE-Constraint würde dies ebenfalls abfangen)
    $existing = $this->db->fetchOne(
        'SELECT id FROM article_relations WHERE article_id = ? AND related_id = ? AND relation_type = ?',
        [$articleId, $relatedId, $type->value],
    );
    if ($existing !== null) {
        throw new RelationAlreadyExistsException($articleId, $relatedId, $type);
    }

    // 3. Vorwärts-Relation einfügen
    $id = $this->db->insert(
        'INSERT INTO article_relations (article_id, related_id, relation_type, created_at) VALUES (?, ?, ?, ?)',
        [$articleId, $relatedId, $type->value, $now],
    );

    // 4. Inverse einfügen (wenn nicht bereits vorhanden)
    $inverse = $type->inverse();
    $inverseExists = $this->db->fetchOne(
        'SELECT id FROM article_relations WHERE article_id = ? AND related_id = ? AND relation_type = ?',
        [$relatedId, $articleId, $inverse->value],
    );
    if ($inverseExists === null) {
        $this->db->insert(
            'INSERT INTO article_relations (article_id, related_id, relation_type, created_at) VALUES (?, ?, ?, ?)',
            [$relatedId, $articleId, $inverse->value, $now],
        );
    }

    return new ArticleRelation($id, $articleId, $relatedId, $type, $now);
}
```

### Relation entfernen (Inverse kaskadieren)

```php
public function removeRelation(int $articleId, int $relatedId, RelationType $type): bool
{
    $deleted = $this->db->execute(
        'DELETE FROM article_relations WHERE article_id = ? AND related_id = ? AND relation_type = ?',
        [$articleId, $relatedId, $type->value],
    );
    // Inverse entfernen
    $inverse = $type->inverse();
    $this->db->execute(
        'DELETE FROM article_relations WHERE article_id = ? AND related_id = ? AND relation_type = ?',
        [$relatedId, $articleId, $inverse->value],
    );
    return $deleted > 0;
}
```

---

## Endpunkte

| Methode | Pfad | Beschreibung |
|---------|------|--------------|
| `POST` | `/articles` | Artikel erstellen |
| `GET` | `/articles/{id}` | Artikel mit eingebetteten Relationen-Stubs abrufen |
| `POST` | `/articles/{id}/relations` | Relation hinzufügen (+ automatisches Einfügen der Inversen) |
| `GET` | `/articles/{id}/relations` | Relationen auflisten (`?type=sequel` zum Filtern) |
| `DELETE` | `/articles/{id}/relations/{relatedId}` | Relation entfernen (`?type=sequel` erforderlich) |

---

## Antwortstrukturen

### GET /articles/{id} — mit eingebetteten Relationen

```json
{
  "data": { "id": 1, "title": "Part 1", ... },
  "relations": [
    {
      "relation": { "id": 1, "article_id": 1, "related_id": 2, "relation_type": "sequel", ... },
      "related":  { "id": 2, "title": "Part 2", ... }
    }
  ]
}
```

### POST /articles/{id}/relations — Anfrage

```json
{
  "related_id": 2,
  "relation_type": "sequel"
}
```

### DELETE /articles/{id}/relations/{relatedId}

```
DELETE /articles/1/relations/2?type=sequel
```

Der `type`-Query-Parameter ist **erforderlich** — ein Paar kann gleichzeitig mehrere Relationstypen haben, sodass der Typ disambiguiert, welche Kante entfernt werden soll.

---

## Domain-Layer-Struktur

```
src/Article/
├── Article.php
├── ArticleRelation.php
├── ArticleRepository.php       # addRelation / removeRelation / listRelations / findWithRelations
├── RelationType.php            # Enum mit inverse()
├── ArticleNotFoundException.php
└── RelationAlreadyExistsException.php
```

---

## Grenzfälle

| Szenario | Verhalten |
|----------|-----------|
| Selbst-Relation (`article_id == related_id`) | 422 — im Handler vor DB geprüft |
| Doppelter Typ zwischen gleichem Paar | 409 Conflict |
| Gleiches Paar mit anderem Typ | 201 — gültig, als separate Zeilen gespeichert |
| Nicht existierende Relation entfernen | 404 |
| Entfernen ohne `type`-Parameter | 422 |
| Fehlende Artikel | 404 für jede ungültige ID |

---

## Siehe auch

- [Tagging-System (M:N)](./tagging-system.md) — Ressource-zu-Tag M:N ohne typisierte Kanten
- [Threaded Comments](./threaded-comments.md) — Selbstreferenzieller `parent_id`
- [Hierarchical Data](./hierarchical-data.md) — Materialisierter Pfad-Tree
- [User Follow System](./user-follow-system.md) — Gerichtetes M:N zwischen Benutzern
