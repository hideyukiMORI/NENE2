# Hierarchische Daten — Selbstreferentielle FK + Materialized Path

> **FT-Referenz**: FT171 (`NENE2-FT/hierarchylog`) — Hierarchische Kategorien mit selbstreferentiellem FK und materialisierten Pfad für O(1)-Teilbaum-Abfragen.

Einen Baum von Kategorien (oder jede Hierarchie) in einer einzigen SQL-Tabelle speichern, indem ein **selbstreferentielle Fremdschlüssel** (`parent_id`) plus ein **materialisierter Pfad** (`/1/3/7/`) verwendet wird, um O(1)-Teilbaum-Abfragen zu ermöglichen.

---

## Wann dieses Muster verwenden

| Verwenden wenn… | Alternativen in Betracht ziehen wenn… |
|---|---|
| Tiefe ist begrenzt (≤ 5–10 Ebenen) | Unbegrenzte Tiefe mit häufigem Neuverknüpfen |
| Teilbaum-Lesevorgänge sind häufig | Baum ist schreibintensiv mit vielen Verschiebungen |
| Einzel-Datenbank-Lösung bevorzugt | Graph-Beziehungen (mehrere Eltern) |

---

## Schema-Design

```sql
CREATE TABLE categories (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT    NOT NULL,
    parent_id  INTEGER,                      -- NULL = Root-Knoten
    path       TEXT    NOT NULL UNIQUE,      -- materialisierter Pfad: "/1/", "/1/3/", "/1/3/7/"
    depth      INTEGER NOT NULL DEFAULT 0,  -- 0 = Root
    created_at TEXT    NOT NULL,
    FOREIGN KEY (parent_id) REFERENCES categories(id)
);
```

### Pfad-Konvention

- Root-Knoten: `/1/` (entspricht `/{id}/`)
- Level-1-Kind des Roots: `/1/3/`
- Level-2-Enkelin: `/1/3/7/`
- Beginnt und endet immer mit `/`.
- Nach INSERT `path = parentPath . newId . '/'` berechnen und die Zeile aktualisieren.

---

## Kernoperationen

### Erstellen (mit Pfadberechnung)

```php
// 1. INSERT mit temporärem Platzhalter
$id = $this->db->insert(
    'INSERT INTO categories (name, parent_id, path, depth, created_at) VALUES (?, ?, ?, ?, ?)',
    [$name, $parentId, '__tmp__', $depth, $now],
);
// 2. Pfad korrigieren, jetzt da wir die ID kennen
$path = $parentPath . $id . '/';
$this->db->execute('UPDATE categories SET path = ? WHERE id = ?', [$path, $id]);
```

### Teilbaum-Abfrage (O(1) auf indizierten Pfad-Spalte)

```php
// Alle Nachkommen des Knotens mit Pfad "/1/3/"
$rows = $this->db->fetchAll(
    "SELECT * FROM categories WHERE path LIKE ? AND id != ? ORDER BY path",
    [$root->path . '%', $rootId],
);
```

### Vorfahren

```php
// Pfad "/1/3/7/" → Vorfahren-IDs [1, 3]
$parts = array_filter(explode('/', $node->path));
$ancestorIds = array_filter(
    array_map('intval', $parts),
    fn(int $pid) => $pid !== $node->id,
);
```

### Verschieben (kaskadiert zu Nachkommen)

```php
$oldPath = $node->path;
$newPath = $newParentPath . $id . '/';

// Den Knoten selbst aktualisieren
$this->db->execute(
    'UPDATE categories SET parent_id = ?, path = ?, depth = ? WHERE id = ?',
    [$newParentId, $newPath, $newDepth, $id],
);

// Zu allen Nachkommen kaskadieren
foreach ($this->subtree($id) as $desc) {
    $updatedPath  = $newPath . substr($desc->path, strlen($oldPath));
    $updatedDepth = $desc->depth - $node->depth + $newDepth;
    $this->db->execute(
        'UPDATE categories SET path = ?, depth = ? WHERE id = ?',
        [$updatedPath, $updatedDepth, $desc->id],
    );
}
```

---

## Validierungsregeln

| Regel | Implementierung |
|-------|----------------|
| Max-Tiefe | `if ($parent->depth >= MAX_DEPTH - 1) throw CategoryDepthException` |
| Zirkulär (Selbst-Verschieben) | `if ($newParentId === $id) throw CategoryCircularException` |
| Zirkulär (Nachkomme) | `if (str_starts_with($newParent->path, $node->path)) throw CategoryCircularException` |
| Nur Blatt löschen | `if ($children !== []) throw CategoryHasChildrenException` |
| Verschiebe-Tiefe-Überlauf | `$newDepth + maxSubtreeRelativeDepth >= MAX_DEPTH` vor Verschieben prüfen |

---

## Endpunkte

| Methode | Pfad | Beschreibung |
|---------|------|--------------|
| `GET` | `/categories` | Root-Kategorien auflisten (`?parent_id=N` für Kinder) |
| `POST` | `/categories` | Kategorie erstellen |
| `GET` | `/categories/{id}` | Eine Kategorie mit ihrer Vorfahrenkette abrufen |
| `GET` | `/categories/{id}/subtree` | Alle Nachkommen abrufen |
| `PUT` | `/categories/{id}` | Kategorie umbenennen |
| `PATCH` | `/categories/{id}/move` | Zu neuem Elternteil verschieben (`parent_id: null` für Root) |
| `DELETE` | `/categories/{id}` | Blatt löschen (ablehnen wenn Kinder vorhanden → 409) |

---

## Antwort-Formen

### Kategorie-Objekt

```json
{
  "id": 7,
  "name": "PHP Frameworks",
  "parent_id": 3,
  "path": "/1/3/7/",
  "depth": 2,
  "created_at": "2026-01-01T00:00:00+00:00"
}
```

### GET /categories/{id} mit Vorfahren

```json
{
  "data": { ... },
  "ancestors": [
    { "id": 1, "name": "Technology", "depth": 0, ... },
    { "id": 3, "name": "Programming", "depth": 1, ... }
  ]
}
```

---

## Domain-Layer-Struktur

```
src/Category/
├── Category.php                    # readonly Entity
├── CategoryRepository.php          # Baum-Operationen (create / list / subtree / ancestors / move / delete)
├── RouteRegistrar.php              # verbindet HTTP-Handler mit Router
├── CategoryNotFoundException.php
├── CategoryDepthException.php
├── CategoryCircularException.php
└── CategoryHasChildrenException.php
```

---

## Abwägungen vs. Nested Sets / Closure Tables

| Ansatz | Teilbaum-Lesen | Einfügen | Verschieben |
|--------|----------------|----------|-------------|
| **Materialized Path** (diese Anleitung) | Schnell (`LIKE`) | O(1) | O(Teilbaum-Größe) |
| Closure Table | Schnell (Join) | O(Vorfahren) | O(Teilbaum × Vorfahren) |
| Nested Sets | Schnell (`BETWEEN`) | O(Tabelle) | O(Tabelle) |

Materialized Path ist der Sweet Spot für tiefenbegrenzte Bäume, bei denen Verschiebungen selten sind. Closure Table verwenden, wenn Vorfahren-Abfragen nur-Index sein müssen und Verschiebungen häufig sind.

---

## Siehe auch

- [Datenbankgestützten Endpunkt hinzufügen](./add-database-endpoint.md)
- [Paginierung hinzufügen](./add-pagination.md)
- [Soft Delete](./soft-delete.md)
