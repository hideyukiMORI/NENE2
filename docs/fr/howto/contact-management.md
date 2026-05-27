# How-to : API de gestion des contacts

> **Référence FT** : FT238 (`NENE2-FT/contactlog`) — API de gestion des contacts

Montre une API de gestion de contacts avec un CRUD scopé par propriétaire, un système de groupes de contacts many-to-many, une recherche plein texte `LIKE` combinée à un filtre `EXISTS`, et des opérations d'appartenance de groupe idempotentes basées sur la gestion de `DatabaseConstraintException`.

---

## Routes

| Méthode | Chemin | Description |
|---------|--------|-------------|
| `POST` | `/owners/{ownerId}/contacts` | Créer un contact |
| `GET` | `/owners/{ownerId}/contacts` | Rechercher des contacts (optionnel `?q=`, `?group_id=`) |
| `GET` | `/owners/{ownerId}/contacts/{id}` | Obtenir un contact spécifique |
| `PUT` | `/owners/{ownerId}/contacts/{id}` | Mettre à jour un contact (remplacement complet) |
| `DELETE` | `/owners/{ownerId}/contacts/{id}` | Supprimer un contact |
| `POST` | `/owners/{ownerId}/groups` | Créer un groupe |
| `PUT` | `/owners/{ownerId}/contacts/{contactId}/groups/{groupId}` | Ajouter un contact à un groupe |
| `DELETE` | `/owners/{ownerId}/contacts/{contactId}/groups/{groupId}` | Retirer un contact d'un groupe |

`{ownerId}` scope toutes les opérations à un propriétaire — les contacts et groupes créés par un propriétaire sont invisibles aux autres.

---

## Schéma : contacts, groups, contact_groups

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

Points de conception clés :
- `contact_groups` utilise une `PRIMARY KEY (contact_id, group_id)` composite — il peut y avoir au maximum une ligne par paire (contact, groupe). Tenter d'insérer un doublon lève une erreur de contrainte.
- `groups.UNIQUE(owner_id, name)` empêche les noms de groupe en doublon chez un même propriétaire.
- `email`, `phone`, `notes` ont pour défaut `''` — pas besoin de gérer les NULL pour les champs optionnels.

---

## Prévention IDOR : owner_id dans chaque requête

Toutes les opérations de lecture et d'écriture incluent `owner_id` dans la clause `WHERE` :

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

Une requête pour `/owners/alice/contacts/5` où le contact 5 appartient à `bob` retourne `null` → `404 Not Found`. L'appelant ne peut pas distinguer "n'existe pas" de "ne vous appartient pas" — cela empêche la confirmation de l'existence d'un ID.

---

## Recherche : filtre LIKE dynamique + EXISTS

L'endpoint de liste construit une clause `WHERE` dynamique basée sur les paramètres de requête optionnels :

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

Patterns utilisés :
- **Accumulation de conditions dynamiques** : commencer par les conditions requises (`owner_id`) et ajouter les optionnelles. `implode(' AND ', $conditions)` les joint en toute sécurité.
- **`LIKE ? OR LIKE ?`** : LIKE paramétré — pas d'injection SQL. Les wildcards `%` se trouvent dans la chaîne PHP, pas dans l'entrée utilisateur. Cependant, si `$query` contient lui-même `%` ou `_`, ces caractères sont interprétés comme des wildcards LIKE par SQLite — les échapper avec `str_replace(['%', '_'], ['\\%', '\\_'], $query)` si une correspondance littérale est requise.
- **`EXISTS (SELECT 1 ...)`** : sous-requête corrélée qui filtre les contacts appartenant à un groupe donné sans JOIN (évite les lignes en doublon quand un contact appartient à plusieurs groupes).

---

## Création de groupe : nom en doublon → 409

`UNIQUE(owner_id, name)` sur `groups` fait d'un nom de groupe en doublon chez le même propriétaire une erreur de contrainte. Le repository la capture et retourne `null` :

```php
public function createGroup(string $ownerId, string $name): ?array
{
    try {
        $id = $this->db->insert(
            'INSERT INTO groups (owner_id, name, created_at) VALUES (?, ?, ?)',
            [$ownerId, $name, $now],
        );
    } catch (DatabaseConstraintException) {
        return null;  // nom de groupe déjà existant pour ce propriétaire
    }
    // ...
}
```

Le contrôleur mappe `null` sur `409 Conflict` :

```php
$group = $this->repo->createGroup($ownerId, $name);

if ($group === null) {
    return $this->problems->create($request, 'conflict', 'Group Already Exists', 409,
        "Group {$name} already exists.");
}
```

`409` est le statut correct — la requête est valide mais entre en conflit avec une ressource existante.

---

## Appartenance à un groupe : ajout idempotent via capture de contrainte

Ajouter un contact à un groupe est idempotent — les appels répétés réussissent sans erreur :

```php
public function addToGroup(int $contactId, int $groupId, string $ownerId): bool
{
    // Vérifier que le contact et le groupe appartiennent bien à ce propriétaire
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
        // Violation de PRIMARY KEY — contact déjà dans le groupe. Traiter comme un succès (idempotent).
    }

    return true;
}
```

La `PRIMARY KEY (contact_id, group_id)` composite applique l'unicité au niveau DB. Le pattern capture-et-ignore rend l'opération sûre à appeler plusieurs fois — une appartenance déjà existante n'est pas une erreur du point de vue de l'appelant.

Le `contact` et le `group` sont tous deux vérifiés pour appartenir à `$ownerId` avant d'insérer l'appartenance. L'appartenance inter-propriétaires (le contact d'Alice ajouté au groupe de Bob) est ainsi empêchée.

---

## Suppression de l'appartenance à un groupe

La suppression vérifie la propriété du contact et supprime si l'appartenance existe :

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

    return $count > 0;  // false si l'appartenance n'existait pas → 404
}
```

Retourner `false` quand l'appartenance n'existe pas résulte en `404`, ce qui est correct : l'appelant a tenté de supprimer quelque chose qui n'est pas là.

---

## Guides associés

- [`group-membership-management.md`](group-membership-management.md) — patterns d'appartenance à des groupes basés sur les rôles
- [`tagging-system.md`](tagging-system.md) — relations de tags many-to-many
- [`enforce-resource-ownership.md`](enforce-resource-ownership.md) — patterns de prévention IDOR
- [`use-fts5-search.md`](use-fts5-search.md) — recherche plein texte pour les grands ensembles de données
