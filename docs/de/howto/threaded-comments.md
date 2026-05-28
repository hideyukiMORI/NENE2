# Threaded Comments

Selbstreferenzierende Kommentar-Threads mit Tiefenlimits und Soft Delete implementieren.

## Übersicht

Ein Threaded-Comment-System hat eine Tabelle, die auf sich selbst verweist. Jeder Kommentar kennt seine `parent_id` (null für Top-Level), seine `depth` (0-basiert) und seinen `status`. Antworten werden in der Antwortstruktur innerhalb ihres Elternkommentars verschachtelt.

## Datenbankschema

```sql
CREATE TABLE comments (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    post_id     INTEGER NOT NULL,
    parent_id   INTEGER,
    author_name TEXT    NOT NULL,
    body        TEXT    NOT NULL,
    status      TEXT    NOT NULL DEFAULT 'published',
    depth       INTEGER NOT NULL DEFAULT 0,
    created_at  TEXT    NOT NULL,
    FOREIGN KEY (post_id)   REFERENCES posts(id),
    FOREIGN KEY (parent_id) REFERENCES comments(id)
);
```

`depth` ist in der Zeile denormalisiert, um rekursive Vorfahren-Abfragen bei jedem Insert zu vermeiden.

## Maximale Tiefe

Ein Tiefenlimit beim Schreiben durchsetzen:

```php
public const int MAX_DEPTH = 3;

public function canHaveReplies(): bool
{
    return $this->depth < self::MAX_DEPTH;
}
```

Im Routen-Handler vor dem Einfügen prüfen:

```php
if (!$parent->canHaveReplies()) {
    return $this->problems->create($request, 'unprocessable-entity', 'Maximum comment depth reached.', 422, '');
}

$comment = $this->repo->addComment(
    $parent->postId, $parentId, $authorName, $body, $parent->depth + 1, $now,
);
```

## Soft Delete

Soft Delete ersetzt den Body durch `[deleted]` und setzt `status = 'deleted'`. Kindkommentare bleiben erhalten:

```php
public function softDelete(int $id): void
{
    $this->executor->execute(
        "UPDATE comments SET status = 'deleted', body = '[deleted]' WHERE id = ?",
        [$id],
    );
}
```

Der Baumlade-Vorgang gibt gelöschte Kommentare mit `[deleted]`-Bodies zurück, damit die Thread-Struktur kohärent bleibt — Leser sehen einen Platzhalter, wo der gelöschte Kommentar war, und seine Kindkommentare sind noch sichtbar.

Ein Versuch, auf einen gelöschten Kommentar zu antworten, gibt 409 zurück:

```php
if ($parent->isDeleted()) {
    return $this->problems->create($request, 'conflict', 'Cannot reply to a deleted comment.', 409, '');
}
```

## Kommentarbaum ohne N+1 aufbauen

Alle Kommentare für einen Beitrag in einer einzelnen Abfrage laden, geordnet nach ID (Eltern haben immer niedrigere IDs als ihre Kinder). Dann den Baum in PHP mit zwei Durchläufen zusammenbauen:

```php
// Durchlauf 1: Rohzeilen-Map und Kinder-ID-Adjazenzliste aufbauen
foreach ($rows as $row) {
    $rowMap[(int) $row['id']]   = $row;
    $childIds[(int) $row['id']] = [];
}

foreach ($rowMap as $id => $row) {
    $parentId = isset($row['parent_id']) ? (int) $row['parent_id'] : null;
    if ($parentId === null) {
        $roots[] = $id;
    } elseif (isset($childIds[$parentId])) {
        $childIds[$parentId][] = $id;
    }
}

// Durchlauf 2: Rekursiv Comment-Value-Objekte von Wurzeln aufbauen
return $this->buildTree($roots, $rowMap, $childIds);
```

Rohzeilen und `int[]`-Kinder-ID-Listen von `Comment`-Value-Objekten zu trennen vermeidet PHPStan-Typverwirrung beim Arbeiten mit `readonly`-Klassen.

## Zeilendaten von Value-Objekten trennen

Wenn man einen Baum von readonly Value-Objekten rekursiv zusammenbaut, braucht PHPStan klare Typgrenzen. Das funktionierende Muster:

1. **Durchlauf 1** — `array<int, array<string, mixed>> $rowMap` und `array<int, int[]> $childIds` aus Rohzeilen aufbauen. Noch keine Value-Objekte.
2. **Durchlauf 2** — `buildTree()` nimmt nur `int[]`-IDs und die zwei Maps, rekursiert und hydratisiert `Comment`-Objekte mit vollständig zusammengebauten Kinder-Arrays.

Dies vermeidet das Mischen von `Comment`-Objekten und `int`-IDs im selben Array, was einen Union-Typ erzeugen würde, den PHPStan nicht einengen kann.

## Routen-Zusammenfassung

| Methode | Pfad | Beschreibung |
|---------|------|--------------|
| `POST` | `/posts` | Beitrag erstellen |
| `GET` | `/posts/{id}` | Beitrag abrufen |
| `POST` | `/posts/{id}/comments` | Top-Level-Kommentar hinzufügen |
| `GET` | `/posts/{id}/comments` | Kommentarbaum abrufen |
| `POST` | `/comments/{id}/replies` | Auf Kommentar antworten |
| `DELETE` | `/comments/{id}` | Kommentar soft löschen |

## Design-Hinweise

- `depth` ist in der Zeile gespeichert (denormalisiert), um rekursive Vorfahren-Abfragen bei jedem Insert zu vermeiden.
- `ORDER BY id ASC` garantiert, dass Eltern vor ihren Kindern beim Laden der flachen Liste erscheinen.
- Soft Delete bewahrt die Thread-Struktur — Hard Delete würde Kindkommentare zu Waisen machen.
- Auf einen gelöschten Kommentar zu antworten ist gesperrt (409), um Ghost-Threads zu verhindern.
