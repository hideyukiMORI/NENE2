# Comment construire la gestion des membres de groupe avec NENE2

Ce guide explique comment construire un système de groupe où les utilisateurs créent des groupes, invitent des membres avec des rôles (owner/admin/member), gèrent les appartenances et contrôlent les promotions de rôles.

**Essai sur le terrain** : FT138  
**Version NENE2** : ^1.5  
**Sujets couverts** : appartenance basée sur les rôles, auto-adhésion du propriétaire, auto-départ, piège du mot réservé MySQL (`groups`), évaluation des vulnérabilités

---

## Ce que nous construisons

- `POST /groups` — créer un groupe (le créateur devient propriétaire)
- `GET /groups/{groupId}/members` — lister les membres (membres uniquement)
- `POST /groups/{groupId}/members` — ajouter un membre (owner/admin uniquement, rôle : member ou admin)
- `DELETE /groups/{groupId}/members/{userId}` — supprimer un membre (owner/admin peut supprimer les autres ; n'importe qui peut s'auto-départ)
- `PUT /groups/{groupId}/members/{userId}/role` — changer le rôle (owner uniquement)

---

## Schéma de base de données — IMPORTANT : éviter `groups` comme nom de table

`groups` est un **mot réservé en MySQL** (utilisé dans `GROUP BY`). Utiliser `user_groups` à la place.

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

## Enum de rôle avec méthodes de capacité

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

Les méthodes de capacité sur l'enum gardent la logique d'autorisation en dehors des gestionnaires.

---

## Auto-adhésion du propriétaire à la création du groupe

Quand un groupe est créé, le propriétaire est automatiquement ajouté comme membre avec le rôle `owner` :

```php
public function createGroup(string $name, int $ownerId, string $now): int
{
    $this->executor->execute(
        'INSERT INTO user_groups (name, owner_id, created_at) VALUES (?, ?, ?)',
        [$name, $ownerId, $now],
    );

    $groupId = (int) $this->executor->lastInsertId();

    // Le propriétaire est automatiquement membre avec le rôle 'owner'
    $this->executor->execute(
        'INSERT INTO memberships (group_id, user_id, role, joined_at) VALUES (?, ?, ?, ?)',
        [$groupId, $ownerId, 'owner', $now],
    );

    return $groupId;
}
```

---

## Gestionnaire d'ajout de membre — validation du rôle

Le rôle `owner` ne peut pas être attribué via l'API add-member. Pattern `TokenScope::tryFrom()` appliqué à `MemberRole::tryFrom()` :

```php
$role = MemberRole::tryFrom($roleValue);

if ($role === null || $role === MemberRole::Owner) {
    return $this->responseFactory->create(['error' => 'role must be member or admin'], 422);
}
```

---

## Supprimer un membre — auto-départ et suppression par admin

Un membre peut quitter son propre groupe (auto-départ) sans droits admin. Les admins peuvent supprimer les autres. Le propriétaire ne peut jamais être supprimé :

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

## Démontage FK MySQL — l'ordre est important

Lors de la réinitialisation MySQL dans les tests, supprimer les tables dépendantes de FK d'abord avec `FOREIGN_KEY_CHECKS = 0` :

```php
$this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
$this->pdo->exec('DROP TABLE IF EXISTS memberships');
$this->pdo->exec('DROP TABLE IF EXISTS user_groups');
$this->pdo->exec('DROP TABLE IF EXISTS users');
$this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
```

---

## Évaluation des vulnérabilités (FT138)

Douze tests de vulnérabilité vérifient :

| ID | Attaque | Résultat attendu | Résultat |
|----|---------|-----------------|----------|
| VULN-A | IDOR : non-membre lit la liste des membres | 403 | Pass |
| VULN-B | IDOR : non-membre ajoute un membre | 403 | Pass |
| VULN-C | Membre ordinaire essaie d'ajouter quelqu'un | 403 | Pass |
| VULN-D | Admin essaie de définir le rôle owner | pas 200 | Pass |
| VULN-E | Membre essaie de se promouvoir en admin | 403 | Pass |
| VULN-F | Supprimer le propriétaire du groupe | 422 | Pass |
| VULN-G | X-User-Id manquant à la création | pas 201 | Pass |
| VULN-H | X-User-Id non numérique | pas 200 | Pass |
| VULN-I | Injection SQL dans le nom de groupe | 201 (verbatim) | Pass |
| VULN-J | Opération de membre inter-groupe | 403 | Pass |
| VULN-K | ID de groupe négatif | 404 | Pass |
| VULN-L | L'admin ne peut pas changer les rôles | 403 | Pass |

Les 12 tests de vulnérabilité passent. Aucune vulnérabilité trouvée.

---

## Pièges courants

| Piège | Correction |
|-------|------------|
| Utiliser `groups` comme nom de table en MySQL | Utiliser `user_groups` — `groups` est un mot réservé MySQL |
| Propriétaire non auto-ajouté aux appartenances | INSERT l'appartenance owner dans `createGroup()` |
| Admin pouvant changer les rôles | `canChangeRoles()` retourne true uniquement pour `Owner` |
| Autoriser le rôle `owner` via l'API add-member | Rejeter `role === MemberRole::Owner` → 422 |
| Non-membre contournant 403 avec acteur manquant | Vérifier `findMembership(groupId, actorId) !== null` |
| DROP TABLE MySQL échoue avec les contraintes FK | `SET FOREIGN_KEY_CHECKS = 0` avant DROP |
