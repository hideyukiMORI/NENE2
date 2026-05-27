# How-to: Soft Delete, Wiederherstellen und dauerhaftes Löschen

> **FT-Referenz**: `NENE2-FT/softdelete` — Soft Delete via `deleted_at`-Zeitstempel, Wiederherstellen
> (nur soft-gelöschte Notizen können wiederhergestellt werden), dauerhaftes Hard Delete (nur soft-gelöschte
> Notizen können permanent gelöscht werden); 14 Tests PASS.

Diese Anleitung zeigt, wie drei Löschzustände implementiert werden: aktiv, soft-gelöscht
(wiederherstellbar) und dauerhaft gelöscht (weg). Vergleiche mit
`docs/howto/soft-delete-trash-restore.md` (FT340 softdeletelog), das eine dedizierte
Papierkorb-Ansicht und Massen-Purge hinzufügt.

## Schema

```sql
CREATE TABLE notes (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    title      TEXT    NOT NULL,
    body       TEXT    NOT NULL DEFAULT '',
    created_at TEXT    NOT NULL,
    updated_at TEXT    NOT NULL,
    deleted_at TEXT             -- NULL = aktiv; Zeitstempel = soft-gelöscht
);

CREATE INDEX idx_notes_deleted ON notes(deleted_at);
```

`deleted_at IS NULL` → aktiv. `deleted_at IS NOT NULL` → soft-gelöscht.

## Endpunkte

| Methode   | Pfad                     | Beschreibung                          |
|-----------|--------------------------|---------------------------------------|
| `POST`    | `/notes`                 | Notiz erstellen                       |
| `GET`     | `/notes`                 | Nur aktive Notizen auflisten          |
| `GET`     | `/notes/{id}`            | Notiz abrufen (404 wenn gelöscht)     |
| `DELETE`  | `/notes/{id}`            | Soft Delete (setzt deleted_at)        |
| `POST`    | `/notes/{id}/restore`    | Soft-gelöschte Notiz wiederherstellen |
| `DELETE`  | `/notes/{id}/permanent`  | Soft-gelöschte Notiz dauerhaft löschen |

## Notiz erstellen

```php
POST /notes  {"title": "My Note", "body": "Some content"}

→ 201
{
  "id": 1,
  "title": "My Note",
  "body": "Some content",
  "deleted_at": null,    // ← null = aktiv
  "created_at": "..."
}
```

## Aktive Notizen auflisten

```php
GET /notes
→ 200  {"items": [{...aktive Notizen...}], "total": 2}
```

Gibt nur Notizen mit `deleted_at IS NULL` zurück. Soft-gelöschte Notizen sind hier unsichtbar.

## Soft Delete

```php
DELETE /notes/1
→ 200  // setzt deleted_at = jetzt

// Soft-gelöschte Notiz verschwindet aus aktiver Liste
GET /notes
→ 200  {"items": [], "total": 0}

// Und aus direktem GET
GET /notes/1
→ 404
```

```sql
UPDATE notes SET deleted_at = ? WHERE id = ? AND deleted_at IS NULL
```

## Wiederherstellen

```php
// Soft-gelöschte Notiz wiederherstellen
POST /notes/1/restore
→ 200  {"id": 1, "title": "My Note", "deleted_at": null, ...}  // wieder aktiv

// Wiederhergestellte Notiz erscheint wieder in aktiver Liste
GET /notes
→ 200  {"items": [{...}], "total": 1}
```

### Wiederherstellen einer aktiven Notiz → 404

```php
// Versuch, eine aktive (nicht soft-gelöschte) Notiz wiederherzustellen → 404
POST /notes/2/restore   // Notiz 2 wurde nie gelöscht
→ 404
```

Nur soft-gelöschte Notizen können wiederhergestellt werden. Aktive Notizen geben 404 bei der
Wiederherstellung zurück.

```sql
UPDATE notes SET deleted_at = NULL WHERE id = ? AND deleted_at IS NOT NULL
-- Wenn 0 Zeilen betroffen → Notiz ist aktiv oder existiert nicht → 404
```

## Dauerhaftes Löschen

```php
// Muss zuerst soft-gelöscht sein
DELETE /notes/1   // soft delete
POST /notes/1/restore  // wiederherstellen (optional)

// Soft-gelöschte Notiz dauerhaft löschen
DELETE /notes/1          // zuerst soft-löschen
DELETE /notes/1/permanent
→ 200  {"permanent": true}

GET /notes/1
→ 404  // für immer weg
```

### Dauerhaftes Löschen einer aktiven Notiz → 404

```php
// Eine aktive Notiz dauerhaft löschen → 404
// Muss zuerst soft-löschen, dann dauerhaft löschen
DELETE /notes/2/permanent   // Notiz 2 ist aktiv
→ 404
```

```sql
DELETE FROM notes WHERE id = ? AND deleted_at IS NOT NULL
-- Wenn 0 Zeilen betroffen → Notiz ist aktiv oder existiert nicht → 404
```

## Zustandsdiagramm

```
Aktiv
  │
  │ DELETE /notes/{id}     (soft delete)
  ▼
Soft-gelöscht
  │           │
  │ POST      │ DELETE
  │ /restore  │ /permanent
  ▼           ▼
Aktiv      Weg (hard deleted)
```

**Die zentrale Invariante**: dauerhaftes Löschen erfordert einen vorherigen Soft Delete. Dies
verhindert versehentliche Hard Deletes aus dem aktiven Zustand.

---

## Was NOT zu tun ist

| Anti-Muster | Risiko |
|---|---|
| Dauerhaftes Löschen aktiver Notiz erlauben | Übergeht das Soft-Delete-Sicherheitsnetz; Daten weg ohne Wiederherstellungsfenster |
| 200 bei Wiederherstellen einer aktiven Notiz zurückgeben | Aufrufer kann nicht feststellen ob die Wiederherstellung nötig war; 404 signalisiert „nicht im Papierkorb" |
| Kein Index auf `deleted_at` | Vollständiger Tabellen-Scan für jede Listenabfrage; `WHERE deleted_at IS NULL` ist ohne Index langsam |
| Bei `DELETE /notes/{id}` sofort hard-deleten | Keine Wiederherstellung möglich; zuerst Soft Delete verwenden |
| `deleted_at` in aktiver Liste ausgeben | Clients sehen das Feld; visualisiert Antworten unübersichtlich; herausfiltern oder `null` verwenden |
