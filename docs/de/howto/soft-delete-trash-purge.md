# How-to: Soft Delete, Papierkorb und dauerhafter Purge

> **FT-Referenz**: FT257 (`NENE2-FT/softdeletelog`) — Soft Delete / Papierkorb / dauerhaftes Purge-Muster
> mit `deleted_at`-Spalte

Demonstriert einen dreistufigen Lebenszyklus für Datensätze: aktiv → soft-gelöscht (Papierkorb) →
dauerhaft gelöscht. Aktive Listen schließen gelöschte Datensätze automatisch aus. Ein dedizierter
Papierkorb-Endpunkt listet nur gelöschte Datensätze auf. Wiederherstellen bringt einen Datensatz
vom Papierkorb zurück auf aktiv. Purge entfernt den Datensatz physisch aus der Datenbank
(nur erlaubt, solange er sich im Papierkorb befindet).

---

## Routen

| Methode   | Pfad                   | Beschreibung                                        |
|-----------|------------------------|-----------------------------------------------------|
| `POST`    | `/notes`               | Notiz erstellen                                     |
| `GET`     | `/notes`               | Aktive Notizen auflisten (ohne soft-gelöschte)      |
| `GET`     | `/notes/trash`         | Nur Papierkorb-Notizen auflisten                    |
| `GET`     | `/notes/{id}`          | Eine einzelne aktive Notiz abrufen                  |
| `DELETE`  | `/notes/{id}`          | Notiz soft-löschen (in Papierkorb verschieben)      |
| `POST`    | `/notes/{id}/restore`  | Aus Papierkorb auf aktiv wiederherstellen           |
| `DELETE`  | `/notes/{id}/purge`    | Dauerhaft löschen (nur aus Papierkorb)              |

> **Routenreihenfolge**: `/notes/trash` muss vor `/notes/{id}` registriert werden, damit das
> literale Segment `trash` nicht als Pfadparameter erfasst wird.

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

`deleted_at TEXT NULL` ist der Soft-Delete-Marker. Wenn `NULL` ist der Datensatz aktiv; wenn auf
einen ISO-Zeitstempel gesetzt befindet sich der Datensatz im Papierkorb. Kein separates `is_deleted`-
Boolean ist nötig — der Zeitstempel zeichnet auch auf _wann_ das Löschen stattfand, was für
Audit-Protokolle und TTL-basierte Purge-Jobs nützlich ist.

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

Der Standard (`includeTrashed: false`) wendet den `deleted_at IS NULL`-Filter an, sodass Aufrufer
automatisch sicheres Verhalten erhalten. Nur Wiederherstellen und Purge müssen gelöschte Datensätze
sehen und übergeben `includeTrashed: true` explizit.

**Warum keine separate `findByIdIncludingTrashed()`-Methode?**

Ein benannter Boolean-Parameter ist an der Aufrufstelle selbst-dokumentierend:
- `findById($id)` — klar nur-aktiv
- `findById($id, includeTrashed: true)` — klar papierkorb-bewusst

Eine separate Methode würde die Hydration-Logik duplizieren oder einen internen gemeinsamen Helfer erfordern.

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

Aktive Notizen werden nach Erstellungszeit sortiert (neueste zuerst). Papierkorb-Notizen werden
nach Löschzeit sortiert (zuletzt gelöschte zuerst), was für eine „kürzlich gelöscht"-UI natürlich ist.

---

## Soft Delete

```php
public function softDelete(int $id, string $now): ?Note
{
    $note = $this->findById($id);   // nur-aktive Suche
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

`findById($id)` ohne `includeTrashed` bedeutet, dass `DELETE /notes/{id}` auf einer bereits im
Papierkorb befindlichen Notiz `null` → 404 zurückgibt. Dies verhindert Doppel-Lösch-Verwirrung:
ein Client kann aus einem 404 nicht ableiten, ob die Notiz aktiv und fehlend war oder bereits im Papierkorb.

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

`includeTrashed: true` ist hier erforderlich — die Notiz IST gelöscht, also würde der Standard-Filter
sie verbergen. Der `!$note->isDeleted()`-Schutz lehnt eine aktive Notiz ab: `restore()` auf einer
aktiven Notiz aufzurufen gibt `null` → 404 zurück. Dies macht `restore()` idempotent auf dem
„bereits wiederhergestellt"-Pfad: ein Client, der restore zweimal aufruft, erhält beim ersten
Aufruf 200 und beim zweiten 404.

---

## Purge (dauerhaftes Löschen)

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

`purge()` funktioniert nur auf Papierkorb-Datensätzen (`isDeleted()` muss true sein). `DELETE /notes/{id}/purge`
auf einer aktiven Notiz aufzurufen gibt `false` → 404 zurück. Dies schützt vor versehentlicher
Datenzerstörung über den falschen Endpunkt — ein Client muss explizit soft-löschen, bevor er purgen kann.

---

## Zustandsautomat

```
           POST /notes
               │
               ▼
           [aktiv]  ←──────── POST /notes/{id}/restore ────────┐
               │                                                │
    DELETE /notes/{id}                                         │
               │                                                │
               ▼                                                │
          [Papierkorb]  ─────────────────────────────────────┘
               │
    DELETE /notes/{id}/purge
               │
               ▼
          [weg — physisches DELETE]
```

`aktiv → Papierkorb` ist umkehrbar. `Papierkorb → weg` ist irreversibel. Es gibt keinen direkten
Weg von `aktiv → weg`: Purge erfordert einen vorherigen Soft-Delete-Schritt.

---

## Controller: Reihenfolge der Routenregistrierung

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

`/notes/trash` muss vor `/notes/{id}` registriert werden. Wäre die Reihenfolge umgekehrt, würde
eine `GET /notes/trash`-Anfrage `{id}` mit `id = "trash"` matchen, den Integer-Cast fehlschlagen
lassen und 404 oder 200 mit leerem Body statt der Papierkorbliste zurückgeben.

---

## HTTP-Semantik

| Aktion          | Methode   | Warum                                                              |
|-----------------|-----------|--------------------------------------------------------------------|
| Soft Delete     | `DELETE`  | Client möchte die Ressource aus seiner Ansicht entfernen           |
| Wiederherstellen | `POST`   | Nicht idempotent (zweiter Aufruf gibt 404 zurück); `POST` passend  |
| Purge           | `DELETE`  | Client möchte dauerhafte Entfernung                                |

`PATCH /notes/{id}` mit `{"deleted_at": null}` ist eine Alternative für die Wiederherstellung,
aber `POST /restore` ist expliziter und vermeidet das Preisgeben des internen Spaltennamens im
API-Vertrag.

---

## Design-Vergleich

| Ansatz | Aktiv-Filter | Gelöscht-Marker | Wiederherstellen | Purge |
|---|---|---|---|---|
| `deleted_at`-Zeitstempel | `WHERE deleted_at IS NULL` | Zeitstempel + Audit-Spur | `SET deleted_at = NULL` | Physisches `DELETE` |
| `is_deleted`-Boolean | `WHERE is_deleted = 0` | Nur Boolean | `SET is_deleted = 0` | Physisches `DELETE` |
| Separate `deleted_notes`-Tabelle | Kein Filter nötig | Zeile in andere Tabelle verschieben | Zeile zurückverschieben | Aus `deleted_notes` löschen |

`deleted_at` ist das häufigste Muster: eine Spalte, minimale Schema-Änderung und ein eingebauter
Audit-Zeitstempel ohne zusätzliche Kosten.

---

## Verwandte Anleitungen

- [`article-versioning-api.md`](article-versioning-api.md) — Versionshistorie für Inhalte (Audit-Spur-Muster)
- [`mass-assignment-defence.md`](mass-assignment-defence.md) — Explizites DTO-Whitelisting zur Verhinderung von Feld-Injection
- [`transaction-scope-pattern.md`](transaction-scope-pattern.md) — Atomare Multi-Write-Operationen
