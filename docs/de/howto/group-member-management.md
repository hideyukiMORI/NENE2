# How-to: Gruppenmitglieder-Verwaltung

> **FT-Referenz**: FT291 (`NENE2-FT/grouplog`) — Gruppenmitgliedschaft: MemberRole-Enum (owner/admin/member), UNIQUE(group_id, user_id), Owner-kann-nicht-entfernt-werden-Guard, Cross-Group-IDOR-Prävention, canManageMembers()/canChangeRoles()-Rollenhierarchie, VULN-A–L alle SAFE, 38 Tests / 101 Assertions bestanden.

Diese Anleitung zeigt, wie ein Gruppenverwaltungssystem mit rollenbasierter Mitgliedschaftskontrolle aufgebaut wird — Eigentümer, Admins und Mitglieder mit abgestuften Berechtigungen.

## Schema

```sql
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

`UNIQUE(group_id, user_id)` verhindert doppelte Mitgliedschaften. `CHECK(role IN ...)` blockiert ungültige Rollen auf DB-Ebene.

## Endpunkte

| Methode | Pfad | Auth | Beschreibung |
|---------|------|------|--------------|
| `POST` | `/groups` | `X-User-Id` | Gruppe erstellen (Actor wird Owner) |
| `GET` | `/groups/{groupId}/members` | `X-User-Id` (Mitglied) | Mitglieder auflisten |
| `POST` | `/groups/{groupId}/members` | `X-User-Id` (Owner/Admin) | Mitglied hinzufügen |
| `DELETE` | `/groups/{groupId}/members/{userId}` | `X-User-Id` | Mitglied entfernen |
| `PUT` | `/groups/{groupId}/members/{userId}/role` | `X-User-Id` (Owner) | Rolle ändern |

## MemberRole-Enum

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

Rollenfähigkeiten:
- **Owner**: kann Mitglieder hinzufügen/entfernen, Rollen ändern, kann nicht entfernt werden
- **Admin**: kann Mitglieder hinzufügen/entfernen, kann keine Rollen ändern
- **Member**: kann nur verlassen (sich selbst entfernen)

## Actor-Auflösung

```php
private function resolveActorId(ServerRequestInterface $request): int
{
    $header = $request->getHeaderLine('X-User-Id');
    return is_numeric($header) ? (int) $header : 0;
}
```

Nicht-numerische Header geben 0 zurück (ungültig). Jede privilegierte Operation validiert den Actor gegen die DB, bevor sie fortfährt.

## Mitgliedschaftsprüfung vor jeder Operation

```php
$actorMembership = $actorId > 0 ? $this->repo->findMembership($groupId, $actorId) : null;

if ($actorMembership === null) {
    return $this->responseFactory->create(['error' => 'not a member'], 403);
}
```

Nicht-Mitglieder erhalten 403 bei allen Gruppenoperationen — auch beim Auflisten von Mitgliedern (IDOR-Prävention).

## Mitglieder hinzufügen — Rollenhierarchie

```php
$actorRole = MemberRole::tryFrom($actorMembership['role']) ?? MemberRole::Member;

if (!$actorRole->canManageMembers()) {
    return $this->responseFactory->create(['error' => 'only owner or admin can add members'], 403);
}

// 'owner'-Rolle kann nicht über add-member-Endpunkt zugewiesen werden
$role = MemberRole::tryFrom($roleValue);
if ($role === null || $role === MemberRole::Owner) {
    return $this->responseFactory->create(['error' => 'role must be member or admin'], 422);
}
```

Die `owner`-Rolle kann nicht über die API zugewiesen werden — sie wird nur bei der Gruppenerstellung gesetzt.

## Owner kann nicht entfernt werden

```php
$targetRole = MemberRole::tryFrom($targetMembership['role']) ?? MemberRole::Member;

if ($targetRole === MemberRole::Owner) {
    return $this->responseFactory->create(['error' => 'cannot remove the group owner'], 422);
}
```

Der Owner ist vor Entfernung geschützt. Eine Eigentumsübertragung würde einen dedizierten Endpunkt erfordern.

## Selbstverlassen vs. Admin-Entfernung

```php
$isSelfLeave = $actorId === $userId;

if (!$isSelfLeave && !$actorRole->canManageMembers()) {
    return $this->responseFactory->create(['error' => 'only owner or admin can remove members'], 403);
}
```

Mitglieder können sich selbst entfernen (Self-Leave) ohne Admin-Rechte. Das Entfernen eines anderen Benutzers erfordert `canManageMembers()`.

## Rollenänderung — Nur Owner

```php
if (!$actorRole->canChangeRoles()) {
    return $this->responseFactory->create(['error' => 'only owner can change roles'], 403);
}

$role = MemberRole::tryFrom($roleValue);
if ($role === null || $role === MemberRole::Owner) {
    return $this->responseFactory->create(['error' => 'role must be member or admin'], 422);
}
```

Nur der Owner kann Mitglieder befördern/degradieren. Die `owner`-Rolle kann nicht zugewiesen werden (verhindert stille Eigentümerschaftsdiebstähle).

---

## Vulnerability Assessment

### V-01 — IDOR: Nicht-Mitglied liest Mitgliederliste ✅ SAFE

**Risk**: Nicht-Mitglied ruft `GET /groups/{id}/members` auf, um Benutzer zu enumerieren.
**Finding**: SAFE — `findMembership(groupId, actorId) === null` → 403, bevor Daten zurückgegeben werden.

---

### V-02 — IDOR: Nicht-Mitglied fügt jemanden zur Gruppe hinzu ✅ SAFE

**Risk**: Nicht-Mitglied ruft `POST /groups/{id}/members` auf, um Benutzer einzuschleusen.
**Finding**: SAFE — gleiche Mitgliedschaftsprüfung; Nicht-Mitglied → 403.

---

### V-03 — Privilegienerweiterung: Reguläres Mitglied fügt ein anderes Mitglied hinzu ✅ SAFE

**Risk**: Reguläres Mitglied (`role = 'member'`) versucht, einen neuen Benutzer hinzuzufügen.
**Finding**: SAFE — `canManageMembers()` gibt false für `Member` zurück → 403.

---

### V-04 — Privilegienerweiterung: Admin befördert zum Owner ✅ SAFE

**Risk**: Admin versucht, `role = 'owner'` über add-member oder change-role-Endpunkte zuzuweisen.
**Finding**: SAFE — beide Endpunkte lehnen `MemberRole::Owner` als zulässige Rolle ab → 422.

---

### V-05 — Privilegienerweiterung: Mitglied befördert sich selbst ✅ SAFE

**Risk**: Reguläres Mitglied ruft `PUT /groups/{id}/members/{self}/role` auf.
**Finding**: SAFE — `canChangeRoles()` gibt false für `Member` und `Admin` zurück → 403.

---

### V-06 — Owner-Entfernung ✅ SAFE

**Risk**: Admin versucht, den Gruppen-Owner zu entfernen.
**Finding**: SAFE — `if ($targetRole === MemberRole::Owner)` → 422.

---

### V-07 — Fehlende X-User-Id bei Gruppenerstellung ✅ SAFE

**Risk**: Anfrage ohne `X-User-Id` erstellt Gruppe ohne gültigen Owner.
**Finding**: SAFE — `resolveActorId()` gibt 0 bei fehlendem/ungültigem Header zurück → `findUserById(0)` gibt null zurück → 404.

---

### V-08 — Nicht-numerische X-User-Id ✅ SAFE

**Risk**: Header `X-User-Id: admin` umgeht die numerische Actor-Validierung.
**Finding**: SAFE — `is_numeric($header)` gibt false für nicht-numerische Strings zurück → gibt 0 zurück → abgelehnt.

---

### V-09 — SQL-Injection im Gruppennamen ✅ SAFE

**Risk**: Gruppenname `'; DROP TABLE user_groups; --` löscht Daten.
**Finding**: SAFE — alle Abfragen verwenden parametrisierte Statements. Injection-String wird wörtlich als Gruppenname gespeichert ohne Ausführung.

---

### V-10 — Cross-Group-Mitgliederoperation (IDOR) ✅ SAFE

**Risk**: Owner von Gruppe A versucht, ein Mitglied aus Gruppe B zu entfernen.
**Finding**: SAFE — `findMembership(groupId, actorId)` prüft Mitgliedschaft in der *Zielgruppe*. Owner von Gruppe A hat keine Mitgliedschaft in Gruppe B → 403.

---

### V-11 — Negative Gruppen-ID ✅ SAFE

**Risk**: `GET /groups/-1/members` verursacht DB-Fehler oder unerwartetes Verhalten.
**Finding**: SAFE — `is_numeric($params['groupId']) ? (int)$params['groupId'] : 0` akzeptiert `-1` als numerisch, aber `findGroupById(-1)` gibt null zurück → 404.

---

### V-12 — Admin kann keine Rollen ändern ✅ SAFE

**Risk**: Admin ruft `PUT /groups/{id}/members/{userId}/role` auf, um Benutzer zu befördern.
**Finding**: SAFE — `canChangeRoles()` ist nur Owner-exklusiv → Admin erhält 403.

---

### VULN-Zusammenfassung

| ID | Schwachstelle | Befund |
|----|---------------|--------|
| V-01 | IDOR: Nicht-Mitglied liest Mitgliederliste | ✅ SAFE |
| V-02 | IDOR: Nicht-Mitglied fügt Mitglied hinzu | ✅ SAFE |
| V-03 | Privilegienerweiterung: Mitglied fügt Mitglied hinzu | ✅ SAFE |
| V-04 | Privilegienerweiterung: Admin → Owner | ✅ SAFE |
| V-05 | Privilegienerweiterung: Mitglied befördert sich | ✅ SAFE |
| V-06 | Owner-Entfernung | ✅ SAFE |
| V-07 | Fehlende X-User-Id bei Erstellung | ✅ SAFE |
| V-08 | Nicht-numerische X-User-Id | ✅ SAFE |
| V-09 | SQL-Injection im Gruppennamen | ✅ SAFE |
| V-10 | Cross-Group-IDOR (Owner einer anderen Gruppe) | ✅ SAFE |
| V-11 | Negative Gruppen-ID | ✅ SAFE |
| V-12 | Admin kann keine Rollen ändern | ✅ SAFE |

**12 SAFE, 0 EXPOSED**
Mitgliedschaftsprüfung vor jeder Operation, `canManageMembers()`/`canChangeRoles()`-Rollenhierarchie und Owner-Entfernungs-Guard verhindern alle Privilegienerweiterungs- und IDOR-Vektoren.

---

## Was man NICHT tun sollte

| Anti-Muster | Risiko |
|---|---|
| Keine Mitgliedschaftsprüfung vor dem Auflisten von Mitgliedern | Nicht-Mitglieder enumerieren alle Gruppenbenutzer (IDOR) |
| `owner`-Rollenzuweisung über add-member erlauben | Jeder Admin kann still die Eigentümerschaft übernehmen |
| `owner`-Rollenzuweisung über change-role erlauben | Gleiches — Eigentümerschaftsdiebstahl mit einer Anfrage |
| `canManageMembers()`-Prüfung überspringen | Reguläre Mitglieder fügen/entfernen jeden |
| Owner-Entfernung erlauben | Gruppe verliert ihren Verwaltungsbenutzer |
| Kein `UNIQUE(group_id, user_id)` | Gleicher Benutzer zweimal hinzugefügt; doppelte Mitgliedschaftseinträge |
| `is_numeric()`-Prüfung nur für X-User-Id | `"1.5"` besteht `is_numeric`; `(int)`-Cast + gegen DB validieren |
| Mitgliedschaft in Actors eigener Gruppe prüfen (nicht Zielgruppe) | Cross-Group-IDOR: Owner von Gruppe A modifiziert Gruppe B |
| Admin Rollen ändern lassen | Admin befördert sich selbst zum Owner; Rollenhierarchie-Bypass |
