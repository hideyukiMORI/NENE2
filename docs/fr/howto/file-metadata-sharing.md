# Guide d'implémentation de l'API de gestion et partage de métadonnées de fichiers

## Vue d'ensemble

Ce guide explique comment implémenter une API de gestion de métadonnées de fichiers avec NENE2.
Il ne s'agit pas de stocker des fichiers réels, mais de gérer les métadonnées (nom, taille, type MIME, description, visibilité) et de supporter le partage entre utilisateurs avec des permissions (view/edit).

---

## Schéma DB

```sql
CREATE TABLE files (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    name TEXT NOT NULL,
    size INTEGER NOT NULL DEFAULT 0 CHECK (size >= 0),
    mime_type TEXT NOT NULL,
    description TEXT,
    visibility TEXT NOT NULL DEFAULT 'private' CHECK (visibility IN ('private', 'public')),
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

CREATE TABLE file_shares (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    file_id INTEGER NOT NULL,
    shared_with_user_id INTEGER NOT NULL,
    can_edit INTEGER NOT NULL DEFAULT 0 CHECK (can_edit IN (0, 1)),
    created_at TEXT NOT NULL,
    UNIQUE (file_id, shared_with_user_id),
    FOREIGN KEY (file_id) REFERENCES files(id),
    FOREIGN KEY (shared_with_user_id) REFERENCES users(id)
);
```

**Points de conception**

- `visibility CHECK (visibility IN ('private', 'public'))` — contrainte des valeurs valides au niveau DB
- `can_edit CHECK (can_edit IN (0, 1))` — les booléens SQLite sont des INTEGER 0/1
- `UNIQUE (file_id, shared_with_user_id)` — empêche le double partage avec le même utilisateur

---

## Conception des endpoints

| Méthode    | Chemin                                | Description                                              |
|------------|---------------------------------------|----------------------------------------------------------|
| `GET`      | `/files`                              | Liste des fichiers accessibles (les miens + partagés)   |
| `POST`     | `/files`                              | Créer des métadonnées de fichier                        |
| `GET`      | `/files/{fileId}`                     | Obtenir le fichier (propriétaire, public ou partagé uniquement) |
| `PUT`      | `/files/{fileId}`                     | Mettre à jour (propriétaire ou partage edit)            |
| `DELETE`   | `/files/{fileId}`                     | Supprimer (propriétaire uniquement)                     |
| `POST`     | `/files/{fileId}/shares`              | Partager avec un utilisateur                            |
| `DELETE`   | `/files/{fileId}/shares/{userId}`     | Annuler le partage (propriétaire uniquement)            |

---

## Conception du contrôle d'accès

### 3 niveaux d'accès

```
Propriétaire (user_id = X-User-Id)
  → Toutes les opérations possibles
  
Partage edit (file_shares.can_edit = 1)
  → GET / PUT possibles
  → Pas de changement de visibility (propriétaire uniquement)
  → DELETE impossible
  
Partage view (file_shares.can_edit = 0) ou fichier public
  → GET uniquement possible
```

### Non-divulgation d'existence (prévention IDOR)

Retourner **404** pour les fichiers privés des autres utilisateurs (pas 403).
403 implique "le fichier existe mais vous n'avez pas accès", ce qui facilite les attaques de devinage d'ID.

```php
if ((int) $file['user_id'] !== $userId) {
    $share = $this->repo->findShare($fileId, $userId);
    if ($share === null) {
        return $this->json->create(['error' => 'File not found'], 404); // 404, pas 403
    }
}
```

---

## Requête de liste des fichiers accessibles

```php
return $this->db->fetchAll(
    'SELECT f.id, f.user_id, f.name, f.size, f.mime_type, f.description,
            f.visibility, f.created_at, f.updated_at,
            u.name AS owner_name,
            CASE WHEN f.user_id = ? THEN 1 ELSE fs.can_edit END AS can_edit,
            CASE WHEN f.user_id = ? THEN 1 ELSE 0 END AS is_owner
     FROM files f
     JOIN users u ON u.id = f.user_id
     LEFT JOIN file_shares fs ON fs.file_id = f.id AND fs.shared_with_user_id = ?
     WHERE f.user_id = ? OR fs.shared_with_user_id = ?
     ORDER BY f.created_at DESC, f.id DESC',
    [$userId, $userId, $userId, $userId, $userId]
);
```

- `LEFT JOIN` pour joindre la table de partage, `WHERE` pour récupérer "les miens OU partagés avec moi"
- Les fichiers publics ne sont pas inclus dans la liste (la consultation est possible individuellement via GET)
- `CASE WHEN` pour calculer le drapeau propriétaire et les droits d'édition

---

## Prévention de l'escalade de visibilité

Même les partages avec droits d'édition ne peuvent pas changer `visibility`. Seul le propriétaire peut le changer.

```php
// Only owner can change visibility
if ($ownerId !== $userId) {
    $visibility = (string) $file['visibility']; // Remplacer par la valeur actuelle
}

$this->repo->update($fileId, $name, $size, $mimeType, $description, $visibility, $now);
```

---

## Nettoyage des entrées de partage lors de la suppression

```php
public function delete(int $id): void
{
    $this->db->execute('DELETE FROM file_shares WHERE file_id = ?', [$id]);
    $this->db->execute('DELETE FROM files WHERE id = ?', [$id]);
}
```

En raison de la contrainte FK, supprimer `file_shares` en premier, puis `files`.

---

## Conception de la validation

```php
// name : requis, 255 caractères maximum
if (!isset($body['name']) || !is_string($body['name']) || trim($body['name']) === '') {
    $errors[] = new ValidationError('name', 'name is required', 'required');
} elseif (mb_strlen($body['name']) > 255) {
    $errors[] = new ValidationError('name', 'name is too long', 'too_long');
}

// size : doit être de type entier, >= 0
if (!isset($body['size']) || !is_int($body['size'])) {
    $errors[] = new ValidationError('size', 'size must be an integer', 'invalid_type');
}

// visibility : vérification des valeurs énumérées
if (!in_array($body['visibility'], ['private', 'public'], true)) {
    $errors[] = new ValidationError('visibility', 'visibility must be private or public', 'invalid_value');
}
```

---

## Résultats du diagnostic de vulnérabilités (FT156)

| ID | Vulnérabilité | Résultat |
|---|---|---|
| VULN-A | IDOR : accès direct aux fichiers privés d'autres utilisateurs | Pass (retourne 404) |
| VULN-B | IDOR : suppression de fichiers d'autres utilisateurs | Pass (retourne 404) |
| VULN-C | IDOR : mise à jour de fichiers d'autres utilisateurs | Pass (retourne 404) |
| VULN-D | Escalade de privilèges : utilisateur view-share effectue une opération edit | Pass (retourne 403) |
| VULN-E | Injection de propriété : user_id dans le corps | Pass (ignoré) |
| VULN-F | Usurpation de suppression de partage : la personne partagée supprime son propre partage | Pass (retourne 404) |
| VULN-G | Injection SQL : champ nom de fichier | Pass (requêtes paramétrées) |
| VULN-H | Nom trop long : 300 caractères | Pass (retourne 422) |
| VULN-I | Confusion de type : float pour size | Pass (retourne 422) |
| VULN-J | Escalade de visibilité : utilisateur edit-share change visibility | Pass (ignoré) |
| VULN-K | Sondage d'existence : 403 vs 404 | Pass (retourne 404) |
| VULN-L | Contournement d'authentification : X-User-Id=0 / valeur négative | Pass (retourne 401) |

---

## Résultats des tests d'attaque cracker (FT156)

| ID | Scénario d'attaque | Résultat |
|---|---|---|
| ATK-01 | Usurpation : GET du fichier d'un autre utilisateur | Pass (retourne 404) |
| ATK-02 | Usurpation : DELETE du fichier d'un autre utilisateur | Pass (retourne 404) |
| ATK-03 | Utilisateur view-share tente PUT pour éditer | Pass (retourne 403) |
| ATK-04 | Injection de user_id dans le corps pour usurper la propriété | Pass (ignoré) |
| ATK-05 | Traversée de chemin : `../../etc/passwd` | Pass (retourne 404) |
| ATK-06 | Tentative d'accès avec ID chaîne | Pass (retourne 404) |
| ATK-07 | Envoi d'en-tête X-User-Id vide | Pass (retourne 401) |
| ATK-08 | Injection SQL : champ mime_type | Pass (requêtes paramétrées) |
| ATK-09 | Envoi de description ultra-longue (10000 chars) | Pass (stocké sans troncature, name > 255 → 422) |
| ATK-10 | Utilisateur edit-share escalade visibility vers public | Pass (ignoré) |
| ATK-11 | La personne partagée tente de supprimer son partage | Pass (retourne 404) |
| ATK-12 | Sondage d'existence : deviner l'ID de fichier d'un autre | Pass (retourne 404) |

---

## Points clés des tests

```php
// Les fichiers privés d'autres utilisateurs retournent 404 (pas 403)
$res = $this->req('GET', "/files/{$fileId}", ['X-User-Id' => '2']);
$this->assertSame(404, $res->getStatusCode());

// Les utilisateurs edit-share ne peuvent pas changer visibility
$this->req('PUT', "/files/{$fileId}", ['X-User-Id' => '2'], [
    'name' => 'a.txt', 'size' => 1, 'mime_type' => 'text/plain', 'visibility' => 'public',
]);
$check = $this->req('GET', "/files/{$fileId}", ['X-User-Id' => '1']);
$this->assertSame('private', $this->json($check)['visibility']);

// user_id dans le corps est ignoré (pris depuis X-User-Id)
$res = $this->req('POST', '/files', ['X-User-Id' => '1'], ['name' => 'test.txt', 'size' => 1, 'mime_type' => 'text/plain', 'user_id' => 2]);
$this->assertSame(1, $this->json($res)['user_id']);
```
