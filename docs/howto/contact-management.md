# How-to: Contact Management API

> **FT reference**: FT238 (`NENE2-FT/contactlog`) — Contact Management API

Demonstrates a contact management API with owner-scoped CRUD, a many-to-many contact
group system, `LIKE` full-text search combined with `EXISTS` group filtering, and
idempotent group membership operations backed by `DatabaseConstraintException` handling.

---

## Routes

| Method   | Path                                                   | Description                            |
|----------|--------------------------------------------------------|----------------------------------------|
| `POST`   | `/owners/{ownerId}/contacts`                           | Create a contact                       |
| `GET`    | `/owners/{ownerId}/contacts`                           | Search contacts (optional `?q=`, `?group_id=`) |
| `GET`    | `/owners/{ownerId}/contacts/{id}`                      | Get a single contact                   |
| `PUT`    | `/owners/{ownerId}/contacts/{id}`                      | Update a contact (full replacement)    |
| `DELETE` | `/owners/{ownerId}/contacts/{id}`                      | Delete a contact                       |
| `POST`   | `/owners/{ownerId}/groups`                             | Create a group                         |
| `PUT`    | `/owners/{ownerId}/contacts/{contactId}/groups/{groupId}` | Add contact to group              |
| `DELETE` | `/owners/{ownerId}/contacts/{contactId}/groups/{groupId}` | Remove contact from group         |

`{ownerId}` scopes all operations to one owner — contacts and groups created by one
owner are invisible to others.

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

Key design choices:
- `contact_groups` uses a composite `PRIMARY KEY (contact_id, group_id)` — there can
  be at most one row per (contact, group) pair. Attempting to insert a duplicate raises
  a constraint error.
- `groups.UNIQUE(owner_id, name)` prevents duplicate group names within one owner.
- `email`, `phone`, `notes` default to `''` — no NULL handling needed for optional fields.

---

## IDOR prevention: owner_id in every query

All read and write operations include `owner_id` in the `WHERE` clause:

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

A request for `/owners/alice/contacts/5` where contact 5 belongs to `bob` returns
`null` → `404 Not Found`. The caller cannot distinguish "does not exist" from "not
yours" — this prevents confirmation of ID existence.

---

## Search: dynamic LIKE + EXISTS filter

The list endpoint builds a dynamic `WHERE` clause based on optional query parameters:

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

Patterns used:
- **Dynamic condition accumulation**: start with required conditions (`owner_id`) and
  append optional ones. `implode(' AND ', $conditions)` joins them safely.
- **`LIKE ? OR LIKE ?`**: parameterised LIKE — no SQL injection. The `%` wildcards are
  in the PHP string, not in user input. However, if `$query` itself contains `%` or `_`,
  those characters are interpreted as LIKE wildcards by SQLite — escape them with
  `str_replace(['%', '_'], ['\\%', '\\_'], $query)` if literal matching is required.
- **`EXISTS (SELECT 1 ...)`**: correlated subquery filters contacts that belong to a
  given group without a JOIN (avoids duplicate rows when a contact belongs to multiple
  groups).

---

## Group creation: duplicate name → 409

`UNIQUE(owner_id, name)` on `groups` makes duplicate group names within an owner a
constraint error. The repository catches it and returns `null`:

```php
public function createGroup(string $ownerId, string $name): ?array
{
    try {
        $id = $this->db->insert(
            'INSERT INTO groups (owner_id, name, created_at) VALUES (?, ?, ?)',
            [$ownerId, $name, $now],
        );
    } catch (DatabaseConstraintException) {
        return null;  // group name already exists for this owner
    }
    // ...
}
```

The controller maps `null` to `409 Conflict`:

```php
$group = $this->repo->createGroup($ownerId, $name);

if ($group === null) {
    return $this->problems->create($request, 'conflict', 'Group Already Exists', 409,
        "Group {$name} already exists.");
}
```

`409` is the correct status — the request is valid but conflicts with an existing resource.

---

## Group membership: idempotent add via constraint catch

Adding a contact to a group is idempotent — repeated calls succeed without error:

```php
public function addToGroup(int $contactId, int $groupId, string $ownerId): bool
{
    // Verify both contact and group belong to this owner
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
        // PRIMARY KEY violation — contact already in group. Treat as success (idempotent).
    }

    return true;
}
```

The composite `PRIMARY KEY (contact_id, group_id)` enforces uniqueness at the DB level.
The catch-and-ignore pattern makes the operation safe to call multiple times — an
already-existing membership is not an error from the caller's perspective.

Both `contact` and `group` are verified to belong to `$ownerId` before inserting the
membership. Cross-owner membership (alice's contact added to bob's group) is prevented.

---

## Group membership removal

Removal verifies contact ownership and deletes if the membership exists:

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

    return $count > 0;  // false if membership didn't exist → 404
}
```

Returning `false` when the membership doesn't exist results in `404`, which is correct:
the caller attempted to remove something that isn't there.

---

## Related howtos

- [`group-membership-management.md`](group-membership-management.md) — role-based group membership patterns
- [`tagging-system.md`](tagging-system.md) — many-to-many tag relationships
- [`enforce-resource-ownership.md`](enforce-resource-ownership.md) — IDOR prevention patterns
- [`use-fts5-search.md`](use-fts5-search.md) — full-text search for larger datasets
