# How-to : Gestion des membres de groupe

> **Référence FT** : FT291 (`NENE2-FT/grouplog`) — Appartenance au groupe : enum MemberRole (owner/admin/member), UNIQUE(group_id, user_id), garde owner-ne-peut-pas-être-supprimé, prévention IDOR inter-groupe, hiérarchie de rôles canManageMembers()/canChangeRoles(), VULN-A à L tous SAFE, 38 tests / 101 assertions PASS.

Ce guide montre comment construire un système de gestion de groupe avec contrôle des membres basé sur les rôles — propriétaires, admins et membres avec des permissions graduées.

## Schéma

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

`UNIQUE(group_id, user_id)` empêche les appartenances en double. `CHECK(role IN ...)` bloque les rôles invalides au niveau DB.

## Endpoints

| Méthode    | Chemin                                       | Auth              | Description                             |
|------------|----------------------------------------------|-------------------|-----------------------------------------|
| `POST`     | `/groups`                                    | `X-User-Id`      | Créer un groupe (l'acteur devient owner) |
| `GET`      | `/groups/{groupId}/members`                  | `X-User-Id` (membre) | Lister les membres                  |
| `POST`     | `/groups/{groupId}/members`                  | `X-User-Id` (owner/admin) | Ajouter un membre             |
| `DELETE`   | `/groups/{groupId}/members/{userId}`         | `X-User-Id`      | Supprimer un membre                     |
| `PUT`      | `/groups/{groupId}/members/{userId}/role`    | `X-User-Id` (owner) | Changer le rôle                      |

## Enum MemberRole

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

Capacités des rôles :
- **Owner** : peut ajouter/supprimer des membres, changer les rôles, ne peut pas être supprimé
- **Admin** : peut ajouter/supprimer des membres, ne peut pas changer les rôles
- **Member** : peut seulement quitter (se supprimer lui-même)

## Résolution de l'acteur

```php
private function resolveActorId(ServerRequestInterface $request): int
{
    $header = $request->getHeaderLine('X-User-Id');
    return is_numeric($header) ? (int) $header : 0;
}
```

Les en-têtes non numériques retournent 0 (invalide). Chaque opération privilégiée valide l'acteur contre la DB avant de procéder.

## Vérification d'appartenance avant toute opération

```php
$actorMembership = $actorId > 0 ? $this->repo->findMembership($groupId, $actorId) : null;

if ($actorMembership === null) {
    return $this->responseFactory->create(['error' => 'not a member'], 403);
}
```

Les non-membres reçoivent 403 pour toutes les opérations de groupe — y compris la liste des membres (prévention IDOR).

## Ajout de membres — Hiérarchie des rôles

```php
$actorRole = MemberRole::tryFrom($actorMembership['role']) ?? MemberRole::Member;

if (!$actorRole->canManageMembers()) {
    return $this->responseFactory->create(['error' => 'only owner or admin can add members'], 403);
}

// Impossible d'assigner 'owner' via l'endpoint add-member
$role = MemberRole::tryFrom($roleValue);
if ($role === null || $role === MemberRole::Owner) {
    return $this->responseFactory->create(['error' => 'role must be member or admin'], 422);
}
```

Le rôle `owner` ne peut pas être attribué via l'API — il est défini uniquement à la création du groupe.

## Le propriétaire ne peut pas être supprimé

```php
$targetRole = MemberRole::tryFrom($targetMembership['role']) ?? MemberRole::Member;

if ($targetRole === MemberRole::Owner) {
    return $this->responseFactory->create(['error' => 'cannot remove the group owner'], 422);
}
```

Le propriétaire est protégé de la suppression. Le transfert de propriété nécessiterait un endpoint dédié.

## Auto-départ vs. Suppression par admin

```php
$isSelfLeave = $actorId === $userId;

if (!$isSelfLeave && !$actorRole->canManageMembers()) {
    return $this->responseFactory->create(['error' => 'only owner or admin can remove members'], 403);
}
```

Les membres peuvent se supprimer eux-mêmes (auto-départ) sans droits d'admin. Supprimer un autre utilisateur nécessite `canManageMembers()`.

## Changement de rôle — Propriétaire uniquement

```php
if (!$actorRole->canChangeRoles()) {
    return $this->responseFactory->create(['error' => 'only owner can change roles'], 403);
}

$role = MemberRole::tryFrom($roleValue);
if ($role === null || $role === MemberRole::Owner) {
    return $this->responseFactory->create(['error' => 'role must be member or admin'], 422);
}
```

Seul le propriétaire peut promouvoir/rétrograder des membres. Le rôle `owner` ne peut pas être attribué (prévention du vol silencieux de propriété).

---

## Évaluation des vulnérabilités

### V-01 — IDOR : non-membre lit la liste des membres ✅ SAFE

**Risque** : Un non-membre appelle `GET /groups/{id}/members` pour énumérer les utilisateurs.
**Constatation** : SAFE — `findMembership(groupId, actorId) === null` → 403 avant de retourner des données.

---

### V-02 — IDOR : non-membre ajoute quelqu'un à un groupe ✅ SAFE

**Risque** : Un non-membre appelle `POST /groups/{id}/members` pour injecter des utilisateurs.
**Constatation** : SAFE — même vérification d'appartenance ; non-membre → 403.

---

### V-03 — Escalade de privilèges : membre ordinaire ajoute un autre membre ✅ SAFE

**Risque** : Un membre ordinaire (`role = 'member'`) essaie d'ajouter un nouvel utilisateur.
**Constatation** : SAFE — `canManageMembers()` retourne false pour `Member` → 403.

---

### V-04 — Escalade de privilèges : admin se promeut en owner ✅ SAFE

**Risque** : Un admin essaie d'attribuer `role = 'owner'` via les endpoints add-member ou change-role.
**Constatation** : SAFE — les deux endpoints rejettent `MemberRole::Owner` comme rôle assignable valide → 422.

---

### V-05 — Escalade de privilèges : membre se promeut lui-même ✅ SAFE

**Risque** : Un membre ordinaire appelle `PUT /groups/{id}/members/{self}/role`.
**Constatation** : SAFE — `canChangeRoles()` est owner-only → le membre reçoit 403.

---

### V-06 — Suppression du propriétaire ✅ SAFE

**Risque** : Un admin essaie de supprimer le propriétaire du groupe.
**Constatation** : SAFE — `if ($targetRole === MemberRole::Owner)` → 422.

---

### V-07 — X-User-Id manquant à la création de groupe ✅ SAFE

**Risque** : Une requête sans `X-User-Id` crée un groupe sans propriétaire valide.
**Constatation** : SAFE — `resolveActorId()` retourne 0 pour un en-tête manquant/invalide → `findUserById(0)` retourne null → 404.

---

### V-08 — X-User-Id non numérique ✅ SAFE

**Risque** : L'en-tête `X-User-Id: admin` contourne la validation numérique de l'acteur.
**Constatation** : SAFE — `is_numeric($header)` retourne false pour les chaînes non numériques → retourne 0 → rejeté.

---

### V-09 — Injection SQL dans le nom de groupe ✅ SAFE

**Risque** : Le nom de groupe `'; DROP TABLE user_groups; --` supprime des données.
**Constatation** : SAFE — toutes les requêtes utilisent des instructions paramétrées. La chaîne d'injection est stockée verbatim comme nom de groupe sans exécution.

---

### V-10 — Opération de membre inter-groupe (IDOR) ✅ SAFE

**Risque** : Le propriétaire du groupe A essaie de supprimer un membre du groupe B.
**Constatation** : SAFE — `findMembership(groupId, actorId)` vérifie l'appartenance dans le groupe *cible*. Le propriétaire du groupe A n'a pas d'appartenance dans le groupe B → 403.

---

### V-11 — ID de groupe négatif ✅ SAFE

**Risque** : `GET /groups/-1/members` cause une erreur DB ou un comportement inattendu.
**Constatation** : SAFE — `is_numeric($params['groupId']) ? (int)$params['groupId'] : 0` accepte `-1` comme numérique, mais `findGroupById(-1)` retourne null → 404.

---

### V-12 — L'admin ne peut pas changer les rôles ✅ SAFE

**Risque** : Un admin appelle `PUT /groups/{id}/members/{userId}/role` pour promouvoir des utilisateurs.
**Constatation** : SAFE — `canChangeRoles()` est owner-only → l'admin reçoit 403.

---

### Résumé VULN

| ID | Vulnérabilité | Constatation |
|----|---------------|--------------|
| V-01 | IDOR : non-membre lit la liste des membres | ✅ SAFE |
| V-02 | IDOR : non-membre ajoute un membre | ✅ SAFE |
| V-03 | Escalade : membre ajoute un membre | ✅ SAFE |
| V-04 | Escalade : admin → owner | ✅ SAFE |
| V-05 | Escalade : membre se promeut lui-même | ✅ SAFE |
| V-06 | Suppression du propriétaire | ✅ SAFE |
| V-07 | X-User-Id manquant à la création | ✅ SAFE |
| V-08 | X-User-Id non numérique | ✅ SAFE |
| V-09 | Injection SQL dans le nom de groupe | ✅ SAFE |
| V-10 | IDOR inter-groupe (owner d'un autre groupe) | ✅ SAFE |
| V-11 | ID de groupe négatif | ✅ SAFE |
| V-12 | L'admin ne peut pas changer les rôles | ✅ SAFE |

**12 SAFE, 0 EXPOSED**
La vérification d'appartenance avant chaque opération, la hiérarchie de rôles `canManageMembers()`/`canChangeRoles()`, et la garde de suppression du propriétaire préviennent tous les vecteurs d'escalade de privilèges et d'IDOR.

---

## À ne pas faire

| Anti-pattern | Risque |
|---|---|
| Pas de vérification d'appartenance avant la liste des membres | Les non-membres énumèrent tous les utilisateurs du groupe (IDOR) |
| Autoriser l'attribution du rôle `owner` via add-member | Tout admin peut prendre silencieusement la propriété |
| Autoriser l'attribution du rôle `owner` via change-role | Pareil — vol de propriété avec une seule requête |
| Ignorer la vérification `canManageMembers()` | Les membres ordinaires ajoutent/suppriment n'importe qui |
| Autoriser la suppression du propriétaire | Le groupe perd son utilisateur gestionnaire |
| Pas de `UNIQUE(group_id, user_id)` | Le même utilisateur ajouté deux fois ; enregistrements d'appartenance dupliqués |
| Vérification `is_numeric()` uniquement pour X-User-Id | `"1.5"` passe `is_numeric` ; utiliser le cast `(int)` + validation contre la DB |
| Vérifier l'appartenance dans le groupe de l'acteur (pas le groupe cible) | IDOR inter-groupe : le propriétaire du groupe A modifie le groupe B |
| Permettre à l'admin de changer les rôles | L'admin se promeut lui-même en owner ; contournement de la hiérarchie des rôles |
