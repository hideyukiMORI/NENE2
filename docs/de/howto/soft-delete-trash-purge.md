# Anleitung: Soft Delete, Papierkorb und dauerhaftes Bereinigen

> **FT-Referenz**: FT257 (`NENE2-FT/softdeletelog`) — Soft Delete / Papierkorb / dauerhaftes Bereinigen mit `deleted_at`-Spalte

Demonstriert einen dreistufigen Lebenszyklus für Datensätze: aktiv → soft-gelöscht (Papierkorb) → dauerhaft bereinigt.
Aktive Listen schließen gelöschte Datensätze automatisch aus. Ein dedizierter Papierkorb-Endpunkt listet nur gelöschte Datensätze auf.
Wiederherstellen gibt einen Datensatz vom Papierkorb zurück in den aktiven Zustand. Bereinigen entfernt den Datensatz physisch aus der Datenbank
(nur erlaubt wenn im Papierkorb).

---

## Routen

| Methode   | Pfad                   | Beschreibung                                  |
|-----------|------------------------|----------------------------------------------|
| `POST`   | `/notes`               | Notiz erstellen                               |
| `GET`    | `/notes`               | Aktive Notizen auflisten (schließt soft-gelöschte aus) |
| `GET`    | `/notes/trash`         | Nur Papierkorb-Notizen auflisten              |
| `GET`    | `/notes/{id}`          | Eine einzelne aktive Notiz abrufen            |
| `DELETE` | `/notes/{id}`          | Notiz soft-löschen (in Papierkorb verschieben) |
| `POST`   | `/notes/{id}/restore`  | Aus Papierkorb in aktiven Zustand wiederherstellen |
| `DELETE` | `/notes/{id}/purge`    | Dauerhaft löschen (nur aus Papierkorb)        |

> **Routenreihenfolge**: `/notes/trash` muss vor `/notes/{id}` registriert werden, damit das wörtliche Segment
> `trash` nicht als Pfadparameter erfasst wird.

---

## Schema

```sql
CREATE TABLE IF NOT EXISTS notes (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    title      TEXT NOT NULL,
    body       TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    deleted_at TEXT NULL
);
```

`deleted_at TEXT NULL` ist der Soft-Delete-Marker. Wenn `NULL`, ist der Datensatz aktiv; wenn auf einen
ISO-Zeitstempel gesetzt, befindet sich der Datensatz im Papierkorb. Kein separates `is_deleted`-Boolean wird benötigt — der Zeitstempel
erfasst auch _wann_ das Löschen stattfand, was für Prüfpfade und TTL-basierte Bereinigungsjobs nützlich ist.

---

## Domain-Objekt

```php
final readonly class Note
{
    public function __construct(
        public int     $id,
        public string  $title,
        public string  $body,
        public string  $createdAt,
        public string  $updatedAt,
        public ?string $deletedAt,     // null = aktiv, nicht-null = im Papierkorb
    ) {}

    public function isDeleted(): bool
    {
        return $this->deletedAt !== null;
    }
}
```

`isDeleted()` kapselt die Null-Prüfung, sodass Aufrufer das Implementierungsdetail nicht kennen müssen.

---

## Repository: das `includeTrashed`-Flag

```php
public function findById(int $id, bool $includeTrashed = false): ?Note
{
    $sql = $includeTrashed
        ? 'SELECT * FROM notes WHERE id = ?'
        : 'SELECT * FROM notes WHERE id = ? AND deleted_at IS NULL';

    $rows = $this->executor->fetchAll($sql, [$id]);
    return $rows === [] ? null : $this->hydrate($rows[0]);
}
```

Der Standard (`includeTrashed: false`) wendet den `deleted_at IS NULL`-Filter an, sodass Aufrufer das
sichere Verhalten automatisch erhalten. Nur Wiederherstellen und Bereinigen müssen Papierkorb-Datensätze sehen und übergeben
`includeTrashed: true` explizit.

**Warum keine separate `findByIdIncludingTrashed()`-Methode?**

Ein benannter Boolean-Parameter ist an der Aufrufstelle selbstdokumentierend:
- `findById($id)` — eindeutig nur-aktiv
- `findById($id, includeTrashed: true)` — eindeutig papierkorb-bewusst

Eine separate Methode würde die Hydrationslogik duplizieren oder einen internen gemeinsamen Helfer erfordern.

---

## Auflisten: aktiv vs. Papierkorb

```php
public function listActive(): array
{
    return $this->executor->fetchAll(
        'SELECT * FROM notes WHERE deleted_at IS NULL ORDER BY created_at DESC',
        [],
    );
}

public function listTrashed(): array
{
    return $this->executor->fetchAll(
        'SELECT * FROM notes WHERE deleted_at IS NOT NULL ORDER BY deleted_at DESC',
        [],
    );
}
```

Aktive Notizen werden nach Erstellungszeit sortiert (neueste zuerst). Papierkorb-Notizen werden nach Löschzeit sortiert
(zuletzt gelöscht zuerst), was für eine „Kürzlich gelöscht"-Benutzeroberfläche natürlich ist.

---

## Soft Delete

```php
public function softDelete(int $id, string $now): ?Note
{
    $note = $this->findById($id);   // nur-aktiver Lookup
    if ($note === null) {
        return null;   // nicht gefunden ODER bereits im Papierkorb → 404
    }

    $this->executor->execute(
        'UPDATE notes SET deleted_at = ? WHERE id = ?',
        [$now, $id],
    );

    return new Note($note->id, $note->title, $note->body, $note->createdAt, $note->updatedAt, $now);
}
```

`findById($id)` ohne `includeTrashed` bedeutet, dass das Aufrufen von `DELETE /notes/{id}` auf einer bereits im Papierkorb befindlichen
Notiz `null` → 404 zurückgibt. Dies verhindert Doppellösch-Verwirrung: Ein Client kann aus einem 404 nicht erkennen,
ob die Notiz aktiv und fehlend war oder bereits im Papierkorb.

---

## Wiederherstellen

```php
public function restore(int $id): ?Note
{
    $note = $this->findById($id, includeTrashed: true);
    if ($note === null || !$note->isDeleted()) {
        return null;   // nicht gefunden ODER bereits aktiv → 404
    }

    $this->executor->execute(
        'UPDATE notes SET deleted_at = NULL WHERE id = ?',
        [$id],
    );

    return new Note($note->id, $note->title, $note->body, $note->createdAt, $note->updatedAt, null);
}
```

`includeTrashed: true` ist hier erforderlich — die Notiz IST gelöscht, sodass der Standardfilter sie ausblenden würde.
Der `!$note->isDeleted()`-Schutz lehnt eine aktive Notiz ab: Das Aufrufen von Restore auf einer aktiven Notiz gibt `null`
→ 404 zurück. Dies macht Restore im „bereits wiederhergestellt"-Pfad idempotent: Ein Client, der Restore zweimal aufruft,
erhält beim ersten Aufruf 200 und beim zweiten 404.

---

## Bereinigen (dauerhaftes Löschen)

```php
public function purge(int $id): bool
{
    $note = $this->findById($id, includeTrashed: true);
    if ($note === null || !$note->isDeleted()) {
        return false;   // nicht gefunden ODER noch aktiv → 404
    }

    $this->executor->execute('DELETE FROM notes WHERE id = ?', [$id]);
    return true;
}
```

`purge()` funktioniert nur bei Papierkorb-Datensätzen (`isDeleted()` muss true sein). Das Aufrufen von `DELETE /notes/{id}/purge`
auf einer aktiven Notiz gibt `false` → 404 zurück. Dies schützt vor versehentlichem Datenverlust über den falschen
Endpunkt — ein Client muss explizit soft-löschen, bevor er bereinigen kann.

---

## Zustandsmaschine

```
           POST /notes
               │
               ▼
           [aktiv]  ←──────── POST /notes/{id}/restore ────────┐
               │                                                  │
    DELETE /notes/{id}                                           │
               │                                                  │
               ▼                                                  │
           [Papierkorb]  ──────────────────────────────────────┘
               │
    DELETE /notes/{id}/purge
               │
               ▼
          [weg — physisches DELETE]
```

`aktiv → Papierkorb` ist reversibel. `Papierkorb → weg` ist irreversibel. Es gibt keinen direkten Pfad von
`aktiv → weg`: Bereinigen erfordert einen vorherigen Soft-Delete-Schritt.

---

## Controller: Routenregistrierungsreihenfolge

```php
public function register(Router $router): void
{
    $router->post('/notes',              $this->create(...));
    $router->get('/notes',               $this->listActive(...));
    $router->get('/notes/trash',         $this->listTrashed(...));   // ← muss vor {id} kommen
    $router->get('/notes/{id}',          $this->get(...));
    $router->delete('/notes/{id}',       $this->softDelete(...));
    $router->post('/notes/{id}/restore', $this->restore(...));
    $router->delete('/notes/{id}/purge', $this->purge(...));
}
```

`/notes/trash` muss vor `/notes/{id}` registriert werden. Wenn die Reihenfolge umgekehrt wäre, würde eine `GET /notes/trash`-
Anfrage `{id}` mit `id = "trash"` matchen, den Integer-Cast scheitern lassen und 404 oder 200 mit leerem
Body zurückgeben statt der Papierkorbliste.

---

## HTTP-Semantik

| Aktion        | Methode   | Warum                                                            |
|---------------|-----------|------------------------------------------------------------------|
| Soft Delete   | `DELETE` | Client beabsichtigt, die Ressource aus seiner Sicht zu entfernen  |
| Wiederherstellen | `POST` | Nicht idempotent (zweiter Aufruf gibt 404 zurück); `POST` ist angemessen |
| Bereinigen    | `DELETE` | Client beabsichtigt dauerhafte Entfernung                         |

`PATCH /notes/{id}` mit `{"deleted_at": null}` ist eine Alternative für Wiederherstellen, aber `POST /restore`
ist expliziter und vermeidet die Preisgabe des internen Spaltennamens im API-Vertrag.

---

## Design-Vergleich

| Ansatz | Aktiv-Filter | Gelöscht-Marker | Wiederherstellen | Bereinigen |
|---|---|---|---|---|
| `deleted_at`-Zeitstempel | `WHERE deleted_at IS NULL` | Zeitstempel + Prüfpfad | `SET deleted_at = NULL` | Physisches `DELETE` |
| `is_deleted`-Boolean | `WHERE is_deleted = 0` | Nur Boolean | `SET is_deleted = 0` | Physisches `DELETE` |
| Separate `deleted_notes`-Tabelle | Kein Filter nötig | Zeile in andere Tabelle verschieben | Zeile zurückverschieben | Aus `deleted_notes` löschen |

`deleted_at` ist das häufigste Muster: Eine Spalte, minimale Schemaänderung und ein eingebauter Prüf-
Zeitstempel ohne Mehraufwand.

---

## Verwandte Anleitungen

- [`article-versioning-api.md`](article-versioning-api.md) — Versionshistorie für Inhalte (Prüfpfad-Muster)
- [`mass-assignment-defence.md`](mass-assignment-defence.md) — explizite DTO-Whitelisting zur Verhinderung von Feldinjektion
- [`transaction-scope-pattern.md`](transaction-scope-pattern.md) — atomare Multi-Schreib-Operationen
