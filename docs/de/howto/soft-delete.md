# Soft Delete (Logisches Löschen)

Soft Delete behält einen Datensatz in der Datenbank, markiert ihn jedoch als gelöscht, indem ein `deleted_at`-Zeitstempel gesetzt wird. Dies ermöglicht:
- Rückgängig machen / Wiederherstellen von Funktionen
- Prüfpfade (wer was wann gelöscht hat)
- Referenzielle Integrität (Datensätze können noch referenziert werden, bis sie bereinigt werden)

## Schema

Eine `deleted_at`-Spalte hinzufügen, die für aktive Datensätze `NULL` ist und für gelöschte Datensätze einen Zeitstempel enthält:

```sql
CREATE TABLE articles (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    title      TEXT NOT NULL,
    body       TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    deleted_at TEXT NULL          -- NULL = aktiv, Zeitstempel = gelöscht
);
```

## Die kritische Regel: deleted_at immer filtern

**Jede Abfrage, die nur aktive Datensätze zurückgeben soll, muss `AND deleted_at IS NULL` enthalten.** Das Fehlen dieses Filters ist der häufigste Fehler — der Code funktioniert, aber gelöschte Daten werden in API-Antworten durchgesickert.

```php
// ❌ Fehlender Filter — gibt auch gelöschte Datensätze zurück
$rows = $this->executor->fetchAll('SELECT * FROM articles WHERE id = ?', [$id]);

// ✅ Gelöschte ausschließen
$rows = $this->executor->fetchAll(
    'SELECT * FROM articles WHERE id = ? AND deleted_at IS NULL',
    [$id],
);
```

Dies gilt für jede Abfrage: `findById`, `findAll`, `findByUser`, Paginierungsabfragen und JOIN-Ziele.

## Entity

```php
final readonly class Article
{
    public function __construct(
        public int $id,
        public string $title,
        public string $body,
        public string $createdAt,
        public string $updatedAt,
        public ?string $deletedAt,
    ) {
    }

    public function isDeleted(): bool
    {
        return $this->deletedAt !== null;
    }
}
```

## Repository-Muster

Ein `$includeTrashed = false`-Flag verwenden. Der Standard `false` bedeutet, dass Aufrufer explizit einwilligen müssen, gelöschte Datensätze zu sehen, was versehentliche Preisgabe verhindert:

```php
final class ArticleRepository
{
    public function findById(int $id, bool $includeTrashed = false): ?Article
    {
        $sql = $includeTrashed
            ? 'SELECT * FROM articles WHERE id = ?'
            : 'SELECT * FROM articles WHERE id = ? AND deleted_at IS NULL';

        $row = $this->executor->fetchOne($sql, [$id]);
        return $row !== null ? $this->hydrate($row) : null;
    }

    /** @return list<Article> */
    public function findActive(): array
    {
        $rows = $this->executor->fetchAll(
            'SELECT * FROM articles WHERE deleted_at IS NULL ORDER BY created_at DESC',
        );
        return array_map($this->hydrate(...), $rows);
    }

    /** @return list<Article> */
    public function findTrashed(): array
    {
        $rows = $this->executor->fetchAll(
            'SELECT * FROM articles WHERE deleted_at IS NOT NULL ORDER BY deleted_at DESC',
        );
        return array_map($this->hydrate(...), $rows);
    }

    public function softDelete(int $id): ?Article
    {
        $article = $this->findById($id); // nur aktiv
        if ($article === null) {
            return null;
        }
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $this->executor->execute('UPDATE articles SET deleted_at = ? WHERE id = ?', [$now, $id]);
        return new Article($article->id, $article->title, $article->body, $article->createdAt, $article->updatedAt, $now);
    }

    public function restore(int $id): ?Article
    {
        $article = $this->findById($id, includeTrashed: true);
        if ($article === null || !$article->isDeleted()) {
            return null; // nicht gefunden oder nicht im Papierkorb
        }
        $this->executor->execute('UPDATE articles SET deleted_at = NULL WHERE id = ?', [$id]);
        return new Article($article->id, $article->title, $article->body, $article->createdAt, $article->updatedAt, null);
    }

    /** Dauerhaft löschen — nur aus Papierkorb erlaubt. */
    public function purge(int $id): bool
    {
        $article = $this->findById($id, includeTrashed: true);
        if ($article === null || !$article->isDeleted()) {
            return false; // Schutz: muss zuerst im Papierkorb sein
        }
        $this->executor->execute('DELETE FROM articles WHERE id = ?', [$id]);
        return true;
    }
}
```

### `insert()` für INSERT verwenden

Beim Erstellen von Datensätzen `insert()` verwenden (nicht `execute()` + `lastInsertId()`):

```php
// ❌ Zwei Aufrufe
$this->executor->execute('INSERT INTO articles ...', [...]);
$id = $this->executor->lastInsertId();

// ✅ Ein Aufruf — gibt die eingefügte Zeilen-ID zurück
$id = $this->executor->insert('INSERT INTO articles ...', [...]);
```

## Endpunkte

Eine typische Soft-Delete-API:

| Methode | Pfad | Beschreibung |
|---|---|---|
| `POST` | `/articles` | Erstellen |
| `GET` | `/articles` | Nur aktive Datensätze |
| `GET` | `/articles/trash` | Nur gelöschte Datensätze |
| `GET` | `/articles/{id}` | Einen abrufen (404 wenn gelöscht) |
| `DELETE` | `/articles/{id}` | Soft Delete → 404 wenn bereits gelöscht |
| `POST` | `/articles/{id}/restore` | Wiederherstellen → 404 wenn nicht im Papierkorb |
| `DELETE` | `/articles/{id}/purge` | Hard Delete → 404 wenn nicht im Papierkorb |

**Hinweis zur REST-Semantik:** `DELETE /articles/{id}` verhält sich als Soft Delete, nicht als dauerhafte Entfernung. Wenn dies Clients überrascht, dies klar in der OpenAPI-Spezifikation dokumentieren oder `POST /articles/{id}/trash` für die Soft-Delete-Aktion verwenden.

## `deleted_at` immer in Antworten einschließen

`deleted_at` in jede Antwort einschließen, damit Clients den Ressourcenzustand ohne zusätzliche Anfragen bestimmen können:

```php
return $this->json->create([
    'id'         => $article->id,
    'title'      => $article->title,
    'body'       => $article->body,
    'created_at' => $article->createdAt,
    'updated_at' => $article->updatedAt,
    'deleted_at' => $article->deletedAt, // null = aktiv; Zeitstempel = gelöscht
]);
```

## Foreign Keys und Soft Delete

Wenn andere Tabellen auf einen soft-gelöschten Datensatz verweisen:
- Soft Deletion unterbricht keine Foreign-Key-Bedingungen — die Zeile existiert noch
- Hard Delete (Bereinigung) kann Bedingungen verletzen, wenn verweisende Zeilen existieren
- Vor der Bereinigung auf abhängige Datensätze prüfen oder Soft Delete auf Abhängige kaskadieren

## Code-Review-Checkliste

- [ ] Jede Abfrage für aktive Datensätze enthält `AND deleted_at IS NULL`
- [ ] `findById()`-Standard ist `$includeTrashed = false` — Aufrufer müssen explizit einwilligen
- [ ] `purge()` schützt vor Hard Delete von aktiven Datensätzen (Prüfung `isDeleted()`)
- [ ] `restore()` gibt `null` (→ 404) zurück, wenn der Datensatz nicht im Papierkorb ist
- [ ] JOIN-Abfragen auf soft-gelöschten Tabellen filtern auch `deleted_at IS NULL` auf der verknüpften Tabelle
- [ ] `deleted_at` ist in API-Antworten enthalten, damit Clients den Zustand bestimmen können
- [ ] Das Verhalten von `DELETE /articles/{id}` (soft vs. hart) ist in OpenAPI dokumentiert
- [ ] Tests decken ab: Löschen → 404 bei GET, Liste schließt gelöschte aus, Wiederherstellen → wieder sichtbar, Bereinigen → überall weg, Doppellöschen → 404, aktives Bereinigen → 404
- [ ] `insert()` wird für INSERT verwendet (nicht `execute()` + `lastInsertId()`)
