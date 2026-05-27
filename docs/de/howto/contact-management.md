# How-to: Kontaktverwaltungs-API

> **FT-Referenz**: FT238 (`NENE2-FT/contactlog`) — Kontaktverwaltungs-API

Demonstriert eine Kontaktverwaltungs-API mit eigentümerbezogenem CRUD, einem Many-to-Many-Kontaktgruppensystem, `LIKE`-Volltext-Suche kombiniert mit `EXISTS`-Gruppenfilterung und idempotenten Gruppenzugehörigkeitsoperationen, die durch `DatabaseConstraintException`-Behandlung abgesichert sind.

---

## Routen

| Methode  | Pfad                                                       | Beschreibung                             |
|----------|------------------------------------------------------------|------------------------------------------|
| `POST`   | `/owners/{ownerId}/contacts`                               | Kontakt erstellen                        |
| `GET`    | `/owners/{ownerId}/contacts`                               | Kontakte suchen (optional `?q=`, `?group_id=`) |
| `GET`    | `/owners/{ownerId}/contacts/{id}`                          | Einzelnen Kontakt abrufen               |
| `PUT`    | `/owners/{ownerId}/contacts/{id}`                          | Kontakt aktualisieren (vollständige Ersetzung) |
| `DELETE` | `/owners/{ownerId}/contacts/{id}`                          | Kontakt löschen                         |
| `POST`   | `/owners/{ownerId}/groups`                                 | Gruppe erstellen                        |
| `PUT`    | `/owners/{ownerId}/contacts/{contactId}/groups/{groupId}`  | Kontakt zur Gruppe hinzufügen           |
| `DELETE` | `/owners/{ownerId}/contacts/{contactId}/groups/{groupId}`  | Kontakt aus Gruppe entfernen            |

`{ownerId}` begrenzt alle Operationen auf einen Eigentümer — Kontakte und Gruppen, die von einem Eigentümer erstellt wurden, sind für andere unsichtbar.

---

## Schema: contacts, groups, contact_groups

```sql
CREATE TABLE IF NOT EXISTS contacts (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    owner_id   TEXT    NOT NULL,
    name       TEXT    NOT NULL,
    email      TEXT    NOT NULL DEFAULT '',
    phone      TEXT    NOT NULL DEFAULT '',
    notes      TEXT    NOT NULL DEFAULT '',
    created_at TEXT    NOT NULL,
    updated_at TEXT    NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_contacts_owner ON contacts (owner_id);

CREATE TABLE IF NOT EXISTS groups (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    owner_id   TEXT    NOT NULL,
    name       TEXT    NOT NULL,
    created_at TEXT    NOT NULL,
    UNIQUE(owner_id, name)
);

CREATE TABLE IF NOT EXISTS contact_groups (
    contact_id INTEGER NOT NULL,
    group_id   INTEGER NOT NULL,
    PRIMARY KEY (contact_id, group_id)
);
```

Wichtige Design-Entscheidungen:
- `contact_groups` verwendet einen zusammengesetzten `PRIMARY KEY (contact_id, group_id)` — es kann maximal eine Zeile pro (Kontakt, Gruppe)-Paar geben. Der Versuch, ein Duplikat einzufügen, löst einen Constraint-Fehler aus.
- `groups.UNIQUE(owner_id, name)` verhindert doppelte Gruppennamen innerhalb eines Eigentümers.
- `email`, `phone`, `notes` haben den Standardwert `''` — keine NULL-Behandlung für optionale Felder erforderlich.

---

## IDOR-Prävention: owner_id in jeder Abfrage

Alle Lese- und Schreiboperationen enthalten `owner_id` in der `WHERE`-Klausel:

```php
public function findById(int $id, string $ownerId): ?Contact
{
    $rows = $this->db->fetchAll(
        'SELECT * FROM contacts WHERE id = ? AND owner_id = ?',
        [$id, $ownerId],
    );

    return $rows !== [] ? $this->hydrateWithGroups($rows[0]) : null;
}
```

Eine Anfrage für `/owners/alice/contacts/5`, bei der Kontakt 5 zu `bob` gehört, gibt `null` zurück → `404 Not Found`. Der Aufrufer kann "existiert nicht" nicht von "gehört nicht Ihnen" unterscheiden — dies verhindert die Bestätigung der ID-Existenz.

---

## Suche: Dynamisches LIKE + EXISTS-Filter

Der Listen-Endpunkt erstellt eine dynamische `WHERE`-Klausel basierend auf optionalen Query-Parametern:

```php
public function search(string $ownerId, ?string $query, ?string $groupId): array
{
    $conditions = ['c.owner_id = ?'];
    $bindings   = [$ownerId];

    if ($query !== null) {
        $conditions[] = '(c.name LIKE ? OR c.email LIKE ?)';
        $bindings[]   = "%{$query}%";
        $bindings[]   = "%{$query}%";
    }

    if ($groupId !== null) {
        $conditions[] = 'EXISTS (SELECT 1 FROM contact_groups cg WHERE cg.contact_id = c.id AND cg.group_id = ?)';
        $bindings[]   = (int) $groupId;
    }

    $where = 'WHERE ' . implode(' AND ', $conditions);
    $rows  = $this->db->fetchAll(
        "SELECT c.* FROM contacts c {$where} ORDER BY c.name ASC",
        $bindings,
    );

    return array_map(fn (array $row) => $this->hydrateWithGroups($row), $rows);
}
```

Verwendete Muster:
- **Dynamische Bedingungsakkumulation**: Mit erforderlichen Bedingungen (`owner_id`) beginnen und optionale anhängen. `implode(' AND ', $conditions)` verbindet sie sicher.
- **`LIKE ? OR LIKE ?`**: Parametrisiertes LIKE — keine SQL-Injection. Die `%`-Wildcards befinden sich im PHP-String, nicht in der Benutzereingabe. Wenn `$query` jedoch `%` oder `_` enthält, werden diese Zeichen von SQLite als LIKE-Wildcards interpretiert — mit `str_replace(['%', '_'], ['\\%', '\\_'], $query)` escapen, wenn wörtliche Übereinstimmung erforderlich ist.
- **`EXISTS (SELECT 1 ...)`**: Korrelierte Unterabfrage filtert Kontakte, die zu einer bestimmten Gruppe gehören, ohne JOIN (vermeidet doppelte Zeilen, wenn ein Kontakt zu mehreren Gruppen gehört).

---

## Gruppenerstellung: Doppelter Name → 409

`UNIQUE(owner_id, name)` auf `groups` macht doppelte Gruppennamen innerhalb eines Eigentümers zu einem Constraint-Fehler. Das Repository fängt ihn ab und gibt `null` zurück:

```php
public function createGroup(string $ownerId, string $name): ?array
{
    try {
        $id = $this->db->insert(
            'INSERT INTO groups (owner_id, name, created_at) VALUES (?, ?, ?)',
            [$ownerId, $name, $now],
        );
    } catch (DatabaseConstraintException) {
        return null;  // Gruppenname existiert bereits für diesen Eigentümer
    }
    // ...
}
```

Der Controller ordnet `null` zu `409 Conflict` zu:

```php
$group = $this->repo->createGroup($ownerId, $name);

if ($group === null) {
    return $this->problems->create($request, 'conflict', 'Group Already Exists', 409,
        "Group {$name} already exists.");
}
```

`409` ist der korrekte Status — die Anfrage ist gültig, steht aber in Konflikt mit einer vorhandenen Ressource.

---

## Gruppenzugehörigkeit: Idempotentes Hinzufügen via Constraint-Abfangen

Das Hinzufügen eines Kontakts zu einer Gruppe ist idempotent — wiederholte Aufrufe gelingen ohne Fehler:

```php
public function addToGroup(int $contactId, int $groupId, string $ownerId): bool
{
    // Prüfen, ob sowohl Kontakt als auch Gruppe zu diesem Eigentümer gehören
    $contact = $this->db->fetchOne('SELECT id FROM contacts WHERE id = ? AND owner_id = ?', [$contactId, $ownerId]);
    $group   = $this->db->fetchOne('SELECT id FROM groups WHERE id = ? AND owner_id = ?', [$groupId, $ownerId]);

    if ($contact === null || $group === null) {
        return false;  // → 404 Not Found
    }

    try {
        $this->db->execute(
            'INSERT INTO contact_groups (contact_id, group_id) VALUES (?, ?)',
            [$contactId, $groupId],
        );
    } catch (DatabaseConstraintException) {
        // PRIMARY KEY-Verletzung — Kontakt bereits in Gruppe. Als Erfolg behandeln (idempotent).
    }

    return true;
}
```

Der zusammengesetzte `PRIMARY KEY (contact_id, group_id)` erzwingt die Eindeutigkeit auf DB-Ebene. Das Abfangen-und-Ignorieren-Muster macht die Operation sicher für mehrfache Aufrufe — eine bereits vorhandene Mitgliedschaft ist aus Sicht des Aufrufers kein Fehler.

Sowohl `contact` als auch `group` werden vor dem Einfügen der Mitgliedschaft auf Zugehörigkeit zu `$ownerId` geprüft. Eigentümerübergreifende Mitgliedschaft (Kontakt von Alice in Gruppe von Bob hinzufügen) wird verhindert.

---

## Entfernen aus Gruppe

Das Entfernen prüft die Kontakteigentumsrechte und löscht, wenn die Mitgliedschaft existiert:

```php
public function removeFromGroup(int $contactId, int $groupId, string $ownerId): bool
{
    $contact = $this->db->fetchOne('SELECT id FROM contacts WHERE id = ? AND owner_id = ?', [$contactId, $ownerId]);
    if ($contact === null) {
        return false;  // → 404
    }

    $count = $this->db->execute(
        'DELETE FROM contact_groups WHERE contact_id = ? AND group_id = ?',
        [$contactId, $groupId],
    );

    return $count > 0;  // false, wenn Mitgliedschaft nicht existiert → 404
}
```

Die Rückgabe von `false`, wenn die Mitgliedschaft nicht existiert, führt zu `404`, was korrekt ist: Der Aufrufer versuchte, etwas zu entfernen, das nicht vorhanden ist.

---

## Verwandte Anleitungen

- [`group-membership-management.md`](group-membership-management.md) — rollenbasierte Gruppenzugehörigkeitsmuster
- [`tagging-system.md`](tagging-system.md) — Many-to-Many-Tag-Beziehungen
- [`enforce-resource-ownership.md`](enforce-resource-ownership.md) — IDOR-Präventionsmuster
- [`use-fts5-search.md`](use-fts5-search.md) — Volltext-Suche für größere Datensätze
