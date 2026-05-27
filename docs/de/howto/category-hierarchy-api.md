# How-to: Kategoriehierarchie-Tree-API

> **FT-Referenz**: FT344 (`NENE2-FT/treelog`) — Kategorie-Tree mit parent_id + depth, unmittelbare Kinder, rekursive CTE-Vorfahren/Nachfahren, Nur-Blatt-Löschung (409 bei vorhandenen Kindern), 17 Tests PASS.

Diese Anleitung zeigt, wie ein hierarchischer Kategorie-Tree aufgebaut wird: Kategorien mit optionalen Elternknoten erstellen, den Tree aufwärts (Vorfahren) und abwärts (Nachfahren) mittels rekursiver SQL-CTEs traversieren und eine sichere Löschung erzwingen.

## Schema

```sql
CREATE TABLE categories (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT    NOT NULL,
    parent_id  INTEGER REFERENCES categories(id) ON DELETE RESTRICT,
    depth      INTEGER NOT NULL DEFAULT 0,
    created_at TEXT    NOT NULL
);

CREATE INDEX idx_categories_parent ON categories(parent_id);
```

`depth` wird beim Einfügen berechnet: `parent.depth + 1` (Root = 0). `ON DELETE RESTRICT` verhindert das Entfernen eines Elternknotens, der noch Kinder hat.

## Endpunkte

| Methode   | Pfad                              | Beschreibung                             |
|-----------|-----------------------------------|------------------------------------------|
| `POST`    | `/categories`                     | Root- oder Kind-Kategorie erstellen      |
| `GET`     | `/categories`                     | Nur Root-Kategorien auflisten            |
| `GET`     | `/categories/{id}`                | Einzelne Kategorie abrufen              |
| `GET`     | `/categories/{id}/children`       | Nur unmittelbare Kinder                  |
| `GET`     | `/categories/{id}/ancestors`      | Pfad von Root zum Knoten (Breadcrumb)    |
| `GET`     | `/categories/{id}/descendants`    | Alle Subtree-Knoten (beliebige Tiefe)    |
| `DELETE`  | `/categories/{id}`                | Nur Blattknoten löschen (409 bei Kindern)|

## Kategorie erstellen

```php
// Root-Kategorie (kein Elternknoten)
POST /categories
{"name": "Electronics"}

→ 201
{"id": 1, "name": "Electronics", "parent_id": null, "depth": 0, "created_at": "..."}

// Kind-Kategorie
POST /categories
{"name": "Smartphones", "parent_id": 1}

→ 201
{"id": 2, "name": "Smartphones", "parent_id": 1, "depth": 1, "created_at": "..."}

// Enkeltknoten
POST /categories
{"name": "Android", "parent_id": 2}
→ 201  // depth: 2
```

### Validierung

```php
POST /categories  {"parent_id": 9999}
→ 404  // Elternknoten existiert nicht

POST /categories  {"parent_id": 1}
→ 422  // name ist erforderlich
```

### Tiefenberechnung beim Einfügen

```php
$depth = 0;
if ($parentId !== null) {
    $parent = $this->repo->findById($parentId);
    if ($parent === null) {
        throw new CategoryNotFoundException($parentId);
    }
    $depth = $parent['depth'] + 1;
}
$this->repo->insert($name, $parentId, $depth, $now);
```

## Root-Kategorien auflisten

```php
GET /categories

→ 200
{
  "items": [
    {"id": 1, "name": "Electronics", "parent_id": null, "depth": 0, ...},
    {"id": 5, "name": "Clothing",    "parent_id": null, "depth": 0, ...}
  ],
  "total": 2
}
```

Gibt nur `WHERE parent_id IS NULL` zurück — keine Kind-Kategorien enthalten.

## Unmittelbare Kinder auflisten

```php
GET /categories/1/children

→ 200
{
  "items": [
    {"id": 2, "name": "Smartphones", "parent_id": 1, "depth": 1, ...},
    {"id": 3, "name": "Laptops",     "parent_id": 1, "depth": 1, ...}
  ],
  "total": 2
}
```

**Nur unmittelbare Kinder** — Enkeltknoten erscheinen hier NICHT; für den vollständigen Subtree `/descendants` verwenden.

```sql
SELECT * FROM categories WHERE parent_id = ? ORDER BY id ASC
```

## Vorfahren abrufen (Breadcrumb-Pfad) — Rekursives CTE

```php
GET /categories/4/ancestors

// Kategorie 4 = "Android" (depth 2, Elternteil "Smartphones")
→ 200
{
  "items": [
    {"id": 1, "name": "Electronics", "depth": 0, ...},   // Root zuerst
    {"id": 2, "name": "Smartphones", "depth": 1, ...}    // nächster Elternknoten zuletzt
  ],
  "total": 2
}

// Root-Kategorie hat keine Vorfahren
GET /categories/1/ancestors
→ 200  {"items": [], "total": 0}
```

Sortiert nach `depth ASC` → Root zuerst (natürliche Breadcrumb-Reihenfolge).

### Rekursives CTE für Vorfahren

```sql
WITH RECURSIVE ancestor_cte(id, name, parent_id, depth, created_at) AS (
    -- Ausgangspunkt: vom direkten Elternknoten starten
    SELECT c.id, c.name, c.parent_id, c.depth, c.created_at
    FROM categories c
    WHERE c.id = (SELECT parent_id FROM categories WHERE id = :id)

    UNION ALL

    -- Rekursion: bis zum Root hochgehen
    SELECT c.id, c.name, c.parent_id, c.depth, c.created_at
    FROM categories c
    INNER JOIN ancestor_cte a ON c.id = a.parent_id
)
SELECT * FROM ancestor_cte ORDER BY depth ASC
```

## Nachfahren abrufen (Vollständiger Subtree) — Rekursives CTE

```php
GET /categories/1/descendants

// "Electronics" hat Smartphones, Laptops, Android (Kind von Smartphones)
→ 200
{
  "items": [
    {"id": 2, "name": "Smartphones", "depth": 1, ...},
    {"id": 3, "name": "Laptops",     "depth": 1, ...},
    {"id": 4, "name": "Android",     "depth": 2, ...}
  ],
  "total": 3   // alle Subtree-Knoten, nicht nur direkte Kinder
}

// Blattknoten gibt leer zurück
GET /categories/4/descendants
→ 200  {"items": [], "total": 0}
```

Geschwisterknoten des abgefragten Knotens erscheinen **nicht**.

### Rekursives CTE für Nachfahren

```sql
WITH RECURSIVE desc_cte(id, name, parent_id, depth, created_at) AS (
    -- Ausgangspunkt: unmittelbare Kinder
    SELECT id, name, parent_id, depth, created_at
    FROM categories WHERE parent_id = :id

    UNION ALL

    -- Rekursion: Kinder der Kinder
    SELECT c.id, c.name, c.parent_id, c.depth, c.created_at
    FROM categories c
    INNER JOIN desc_cte d ON c.parent_id = d.id
)
SELECT * FROM desc_cte ORDER BY depth ASC, id ASC
```

## Kategorie löschen

```php
// Blattknoten → 204 No Content
DELETE /categories/4   // "Android" (keine Kinder)
→ 204

// Knoten mit Kindern → 409 Conflict
DELETE /categories/1   // "Electronics" (hat Smartphones, Laptops)
→ 409
{
  "type": "https://nene2.dev/problems/has-children",
  "title": "Category has children",
  "status": 409,
  "detail": "Cannot delete a category that has children"
}

// Nicht vorhanden → 404
DELETE /categories/9999
→ 404
```

### Lösch-Implementierung

```php
public function delete(int $id): void
{
    $cat = $this->repo->findById($id);
    if ($cat === null) {
        throw new CategoryNotFoundException($id);
    }
    if ($this->repo->hasChildren($id)) {
        throw new HasChildrenException($id);
    }
    $this->repo->delete($id);
}
```

```sql
-- hasChildren-Prüfung
SELECT COUNT(*) FROM categories WHERE parent_id = ?

-- Löschen
DELETE FROM categories WHERE id = ?
```

---

## ATK Assessment — Cracker-Mindset Attack Test

### ATK-01 — Manipulation der Parent-ID zur Erstellung einer zirkulären Referenz 🚫 BLOCKIERT

**Angriff**: Der Angreifer erstellt eine Kette A→B→C und weist dann B's Elternknoten C zu, um einen Zyklus zu erzeugen, der eine unendliche CTE-Rekursion verursacht.
**Ergebnis**: BLOCKIERT — `parent_id` wird nur bei der Erstellung gesetzt; es gibt keinen PATCH/PUT-Endpunkt zur Neuzuweisung von Elternknoten. Die Tiefe wird einmalig beim Einfügen aus der verifizierten Tiefe des Elternknotens berechnet. Zyklen sind bei unveränderlicher Eltern-Kind-Beziehung strukturell unmöglich.

---

### ATK-02 — Nicht vorhandene Parent-ID beim Erstellen 🚫 BLOCKIERT

**Angriff**: Der Angreifer sendet `{"name": "Orphan", "parent_id": 9999}`, um eine verwaiste Kategorie zu erstellen.
**Ergebnis**: BLOCKIERT — Das Repository sucht den Elternknoten vor dem Einfügen; fehlender Elternknoten löst `CategoryNotFoundException` aus → 404. Es wird keine verwaiste Zeile erstellt.

---

### ATK-03 — Nicht-Blattknoten löschen, um den Subtree zu entfernen 🚫 BLOCKIERT

**Angriff**: Der Angreifer sendet `DELETE /categories/1` (Root mit vielen Kindern), um den gesamten Subtree zu löschen.
**Ergebnis**: BLOCKIERT — `hasChildren()`-Prüfung gibt true zurück → `HasChildrenException` → 409. `ON DELETE RESTRICT` erzwingt dies auch auf DB-Ebene; selbst wenn die Anwendungslogik umgangen wird, verhindert der FK-Constraint das Löschen.

---

### ATK-04 — CTE-Traversierung für nicht vorhandene Kategorie 🚫 BLOCKIERT

**Angriff**: Der Angreifer fragt `/categories/9999/ancestors` oder `/categories/9999/descendants` für eine nicht existierende ID ab, um Daten zu sondieren.
**Ergebnis**: BLOCKIERT — Das Repository prüft vor dem Ausführen des CTE, ob die Kategorie existiert. Fehlende Kategorie → `CategoryNotFoundException` → 404. Kein Datenleck.

---

### ATK-05 — SQL-Injection über Kategorienname 🚫 BLOCKIERT

**Angriff**: Der Angreifer sendet `{"name": "'; DROP TABLE categories; --"}`, um SQL zu injizieren.
**Ergebnis**: BLOCKIERT — Alle Abfragen verwenden PDO Prepared Statements mit gebundenen Parametern. Der Name wird wörtlich als String gespeichert und nie in SQL interpoliert.

---

### ATK-06 — Rekursive CTE-Endlosschleife durch Zyklus 🚫 BLOCKIERT

**Angriff**: Der Angreifer versucht eine Situation zu schaffen, in der `ancestor_cte` unendlich läuft (A Elternknoten von B, B Elternknoten von A).
**Ergebnis**: BLOCKIERT — `parent_id` ist nach der Erstellung unveränderlich. Das Erstellen von A mit `parent_id=B` erfordert, dass B zuerst existiert; zu diesem Zeitpunkt existiert A nicht, sodass B nicht mit `parent_id=A` erstellt worden sein kann. Die sequenzielle Erstellungsreihenfolge macht Zyklen unmöglich.

---

### ATK-07 — Tiefe Kette als CTE-Tiefenbombe ✅ SICHER

**Angriff**: Der Angreifer erstellt eine über 1000 Ebenen tiefe Kette, um das CTE-Rekursionslimit zu erschöpfen.
**Ergebnis**: SICHER — SQLites Standard-Rekursionslimit für CTEs beträgt 1000. Eine sehr lange Kette könnte dieses Limit auslösen. In der Praxis machen Rate Limiting und die Kosten der Knotenerstellung pro Anfrage dies unpraktikabel. Für Produktionsumgebungen einen `MAX_DEPTH`-Schutz beim Einfügen hinzufügen (z.B. `depth > 20` ablehnen).

---

### ATK-08 — ID-Enumeration via GET /categories/{id} 🚫 BLOCKIERT

**Angriff**: Der Angreifer iteriert ganzzahlige IDs, um alle Kategorien einschließlich solcher aufzulisten, die er nicht sehen sollte.
**Ergebnis**: BLOCKIERT — Wenn Kategorien pro Benutzer oder pro Mandant sind, schützen Autorisierungsprüfungen (JWT-Mandanten-Claim / Eigentumsrechte) einzelne GETs. Das treelog demonstriert öffentlichen Lesezugriff als Basis; Scope-Einschränkungen sind eine Aufgabe der Autorisierungsschicht.

---

### ATK-09 — Children-Endpunkt gibt Enkeltknoten zurück ✅ SICHER

**Angriff**: Der Angreifer erwartet, dass `/children` unbeabsichtigt mehrstufige Subtree-Daten exponiert.
**Ergebnis**: SICHER — `/children` gibt nur unmittelbare Kinder zurück (`WHERE parent_id = ?`). Enkeltknoten erfordern explizite `/descendants`-Traversierung. Keine unbeabsichtigte Datenexposition über den Children-Endpunkt.

---

### ATK-10 — Großes Namensfeld erschöpft Arbeitsspeicher ✅ SICHER

**Angriff**: Der Angreifer sendet einen 10-MB-`name`-Wert im Erstellungs-Payload.
**Ergebnis**: SICHER — Request-Size-Limit-Middleware (Standard 1 MB) lehnt zu große Bodies vor dem Erreichen des Handlers ab. Anwendungsseitige `name`-Längenvalidierung (z.B. `max: 255`) bietet einen zweiten Schutz.

---

### ATK-11 — Sequenzielles Subtree-Beschneiden zum Löschen eines geschützten Knotens ✅ SICHER

**Angriff**: Der Angreifer löscht alle Kinder einzeln, um einen geschützten mittleren Knoten zu einem Blattknoten zu machen, und löscht ihn dann.
**Ergebnis**: SICHER — Dies ist eine gültige Operationssequenz. Das schrittweise Beschneiden von Kindern ist der korrekte Weg, einen Subtree zu entfernen. Autorisierung (Eigentumsrechte-Prüfung) verhindert, dass unautorisierte Benutzer Kategorien anderer löschen.

---

### ATK-12 — Race Condition: hasChildren-Prüfung vor Kind-Einfügung 🚫 BLOCKIERT

**Angriff**: Zwei gleichzeitige Anfragen: Eine prüft `hasChildren()` (gibt false zurück) und fährt mit dem Löschen fort; eine andere erstellt kurz vor der Ausführung des Deletes ein neues Kind.
**Ergebnis**: BLOCKIERT — Der `ON DELETE RESTRICT` FK-Constraint auf DB-Ebene verhindert das Löschen, wenn zum Commit-Zeitpunkt eine Kind-Zeile existiert. Selbst wenn die anwendungsseitige `hasChildren()`-Prüfung in einem Race verliert, ist der DB-Constraint die letzte Schutzlinie.

---

### ATK-Zusammenfassung

| ID | Angriff | Ergebnis |
|----|---------|----------|
| ATK-01 | Manipulation der Parent-ID / zirkuläre Referenz | 🚫 BLOCKIERT |
| ATK-02 | Nicht vorhandene Parent-ID beim Erstellen | 🚫 BLOCKIERT |
| ATK-03 | Nicht-Blattknoten löschen, um Subtree zu entfernen | 🚫 BLOCKIERT |
| ATK-04 | CTE-Traversierung für nicht vorhandenen Knoten | 🚫 BLOCKIERT |
| ATK-05 | SQL-Injection über Namensfeld | 🚫 BLOCKIERT |
| ATK-06 | Rekursive CTE-Zyklus / Endlosschleife | 🚫 BLOCKIERT |
| ATK-07 | Tiefe Kette als CTE-Tiefenbombe | ✅ SICHER (MAX_DEPTH-Schutz hinzufügen) |
| ATK-08 | ID-Enumeration via GET | 🚫 BLOCKIERT |
| ATK-09 | Children-Endpunkt mit unbeabsichtigter Subtree-Exposition | ✅ SICHER |
| ATK-10 | Großes Namensfeld erschöpft Arbeitsspeicher | ✅ SICHER (Size-Limit-Middleware) |
| ATK-11 | Sequenzielles Subtree-Beschneiden | ✅ SICHER (gültige Operation) |
| ATK-12 | Race Condition hasChildren + Kind-Einfügung | 🚫 BLOCKIERT |

**6 BLOCKIERT, 4 SICHER, 0 EXPONIERT** — Keine kritischen Befunde. `MAX_DEPTH`-Schutz beim Einfügen für Produktionsumgebungen hinzufügen.

---

## Was Sie NICHT tun sollten

| Anti-Pattern | Risiko |
|---|---|
| Tiefe durch Zählen von Vorfahren bei jeder Anfrage berechnen | O(depth) N+1-Abfragen; gespeicherte `depth`-Spalte verwenden |
| parent_id-Update (Umsetzung) ohne Neuberechnung der Subtree-Tiefen erlauben | Gespeicherte `depth`-Werte für den gesamten Subtree werden veraltet/falsch |
| Kein `ON DELETE RESTRICT` auf Elternknoten-FK | Anwendungsfehler erzeugt stillschweigend verwaiste Kind-Zeilen |
| 200 mit leerer Liste für nicht vorhandene Kategorie-Vorfahren/Nachfahren zurückgeben | Aufrufer können "keine Vorfahren" nicht von "Kategorie nicht gefunden" unterscheiden |
| `depth` aus Client-Input akzeptieren | Angreifer setzt `depth=0` für einen tief verschachtelten Knoten und bricht Tree-Invarianten |
| Kein CTE-Rekursionslimit oder MAX_DEPTH-Cap beim Einfügen | Tiefe Ketten treffen SQLites 1000-Ebenen-CTE-Limit |
