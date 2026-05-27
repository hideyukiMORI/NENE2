# So bauen Sie eine Gruppenmitgliedschaftsverwaltung mit NENE2

Diese Anleitung führt durch den Aufbau eines Gruppensystems, bei dem Benutzer Gruppen erstellen, Mitglieder mit Rollen einladen (owner/admin/member), Mitgliedschaften verwalten und Rollenbeförderungen steuern können.

**Field Trial**: FT138  
**NENE2-Version**: ^1.5  
**Behandelte Themen**: rollenbasierte Mitgliedschaft, automatischer Owner-Join, Self-Leave, MySQL-Reserviertes-Wort-Falle (`groups`), Vulnerability Assessment

---

## Was wir bauen

- `POST /groups` — eine Gruppe erstellen (Ersteller wird Owner)
- `GET /groups/{groupId}/members` — Mitglieder auflisten (nur Mitglieder)
- `POST /groups/{groupId}/members` — Mitglied hinzufügen (nur Owner/Admin, Rolle: member oder admin)
- `DELETE /groups/{groupId}/members/{userId}` — Mitglied entfernen (Owner/Admin können andere entfernen; jeder kann sich selbst entfernen)
- `PUT /groups/{groupId}/members/{userId}/role` — Rolle ändern (nur Owner)

---

## Datenbankschema — WICHTIG: `groups` als Tabellenname vermeiden

`groups` ist ein **reserviertes Wort in MySQL** (verwendet in `GROUP BY`). Stattdessen `user_groups` verwenden.

```sql
-- SQLite
CREATE TABLE user_groups (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT    NOT NULL,
    owner_id   INTEGER NOT NULL,
    created_at TEXT    NOT NULL,
    FOREIGN KEY (owner_id) REFERENCES users(id)
);

CREATE TABLE memberships (
    id        INTEGER PRIMARY KEY AUTOINCREMENT,
    group_id  INTEGER NOT NULL,
    user_id   INTEGER NOT NULL,
    role      TEXT    NOT NULL DEFAULT 'member',
    joined_at TEXT    NOT NULL,
    UNIQUE (group_id, user_id),
    CHECK (role IN ('owner', 'admin', 'member')),
    FOREIGN KEY (group_id) REFERENCES user_groups(id),
    FOREIGN KEY (user_id)  REFERENCES users(id)
);
```

```sql
-- MySQL
CREATE TABLE user_groups (
    id         INT          NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name       VARCHAR(255) NOT NULL,
    owner_id   INT          NOT NULL,
    created_at VARCHAR(32)  NOT NULL,
    FOREIGN KEY (owner_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE memberships (
    id        INT         NOT NULL AUTO_INCREMENT PRIMARY KEY,
    group_id  INT         NOT NULL,
    user_id   INT         NOT NULL,
    role      VARCHAR(16) NOT NULL DEFAULT 'member',
    joined_at VARCHAR(32) NOT NULL,
    UNIQUE KEY uq_group_user (group_id, user_id),
    CONSTRAINT chk_role CHECK (role IN ('owner', 'admin', 'member')),
    FOREIGN KEY (group_id) REFERENCES user_groups(id),
    FOREIGN KEY (user_id)  REFERENCES users(id)
) ENGINE=InnoDB;
```

---

## Rollen-Enum mit Fähigkeitsmethoden

```php
enum MemberRole: string
{
    case Owner  = 'owner';
    case Admin  = 'admin';
    case Member = 'member';

    public function canManageMembers(): bool
    {
        return $this === self::Owner || $this === self::Admin;
    }

    public function canChangeRoles(): bool
    {
        return $this === self::Owner;
    }
}
```

Fähigkeitsmethoden auf dem Enum halten die Autorisierungslogik aus den Handlern heraus.

---

## Automatischer Owner-Join bei Gruppenerstellung

Wenn eine Gruppe erstellt wird, wird der Owner automatisch als Mitglied mit der `owner`-Rolle hinzugefügt:

```php
public function createGroup(string $name, int $ownerId, string $now): int
{
    $this->executor->execute(
        'INSERT INTO user_groups (name, owner_id, created_at) VALUES (?, ?, ?)',
        [$name, $ownerId, $now],
    );

    $groupId = (int) $this->executor->lastInsertId();

    // Owner wird automatisch Mitglied mit 'owner'-Rolle
    $this->executor->execute(
        'INSERT INTO memberships (group_id, user_id, role, joined_at) VALUES (?, ?, ?, ?)',
        [$groupId, $ownerId, 'owner', $now],
    );

    return $groupId;
}
```

---

## Mitglied hinzufügen Handler — Rollenvalidierung

Die `owner`-Rolle kann nicht über die add-member-API zugewiesen werden. Das `TokenScope::tryFrom()`-Muster wird auf `MemberRole::tryFrom()` angewendet:

```php
$role = MemberRole::tryFrom($roleValue);

if ($role === null || $role === MemberRole::Owner) {
    return $this->responseFactory->create(['error' => 'role must be member or admin'], 422);
}
```

---

## Mitglied entfernen — Self-Leave und Admin-Entfernung

Ein Mitglied kann seine eigene Gruppe verlassen (Self-Leave) ohne Admin-Rechte. Admins können andere entfernen. Der Owner kann nie entfernt werden:

```php
$isSelfLeave = $actorId === $userId;

if (!$isSelfLeave && !$actorRole->canManageMembers()) {
    return $this->responseFactory->create(['error' => 'only owner or admin can remove members'], 403);
}

$targetRole = MemberRole::tryFrom($targetMembership['role']) ?? MemberRole::Member;

if ($targetRole === MemberRole::Owner) {
    return $this->responseFactory->create(['error' => 'cannot remove the group owner'], 422);
}
```

---

## MySQL-FK-Teardown — Reihenfolge wichtig

Beim Zurücksetzen von MySQL in Tests, FK-abhängige Tabellen zuerst mit `FOREIGN_KEY_CHECKS = 0` droppen:

```php
$this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
$this->pdo->exec('DROP TABLE IF EXISTS memberships');
$this->pdo->exec('DROP TABLE IF EXISTS user_groups');
$this->pdo->exec('DROP TABLE IF EXISTS users');
$this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
```

---

## Vulnerability Assessment (FT138)

Zwölf Vulnerability-Tests verifizieren:

| ID | Angriff | Erwartet | Ergebnis |
|----|---------|----------|----------|
| VULN-A | IDOR: Nicht-Mitglied liest Mitgliederliste | 403 | Pass |
| VULN-B | IDOR: Nicht-Mitglied fügt ein Mitglied hinzu | 403 | Pass |
| VULN-C | Reguläres Mitglied versucht, jemanden hinzuzufügen | 403 | Pass |
| VULN-D | Admin versucht, Owner-Rolle zu setzen | nicht 200 | Pass |
| VULN-E | Mitglied versucht, sich selbst zu Admin zu befördern | 403 | Pass |
| VULN-F | Gruppen-Owner entfernen | 422 | Pass |
| VULN-G | Fehlende X-User-Id bei Erstellung | nicht 201 | Pass |
| VULN-H | Nicht-numerische X-User-Id | nicht 200 | Pass |
| VULN-I | SQL-Injection im Gruppennamen | 201 (wörtlich) | Pass |
| VULN-J | Cross-Group-Mitgliederoperation | 403 | Pass |
| VULN-K | Negative Gruppen-ID | 404 | Pass |
| VULN-L | Admin kann keine Rollen ändern | 403 | Pass |

Alle 12 Vulnerability-Tests bestanden. Keine Schwachstellen gefunden.

---

## Häufige Fallstricke

| Fallstrick | Lösung |
|------------|--------|
| `groups` als Tabellenname in MySQL verwenden | `user_groups` verwenden — `groups` ist ein MySQL-Reserviertwort |
| Owner nicht automatisch zu memberships hinzugefügt | Owner-Mitgliedschaft in `createGroup()` einfügen |
| Admin kann Rollen ändern | `canChangeRoles()` gibt true nur für `Owner` zurück |
| `owner`-Rolle über add-member-API erlauben | `role === MemberRole::Owner` → 422 ablehnen |
| Nicht-Mitglied umgeht 403 via fehlendem Actor | `findMembership(groupId, actorId) !== null` prüfen |
| MySQL DROP TABLE scheitert mit FK-Constraints | `SET FOREIGN_KEY_CHECKS = 0` vor DROP |
