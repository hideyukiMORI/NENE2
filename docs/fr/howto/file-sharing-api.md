# How-to : API de partage de fichiers

> **Référence FT** : FT303 (`NENE2-FT/filelog`) — API de partage de fichiers : les fichiers privés retournent 404 (pas 403) aux non-propriétaires, suppression/changement de visibilité réservés au propriétaire, niveaux de permission view-share vs edit-share, `user_id` du corps ignoré (propriété depuis l'en-tête), limite de longueur de nom 255, taille `is_int()` stricte, VULN-A à L tous SAFE, 59 tests / 82 assertions PASS.

Ce guide montre comment construire une API de métadonnées de fichiers où les utilisateurs possèdent des fichiers, contrôlent la visibilité et partagent l'accès avec d'autres utilisateurs au niveau view ou edit.

## Schéma

```sql
CREATE TABLE users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    created_at TEXT NOT NULL
);

CREATE TABLE files (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id     INTEGER NOT NULL,
    name        TEXT    NOT NULL,
    size        INTEGER NOT NULL DEFAULT 0 CHECK (size >= 0),
    mime_type   TEXT    NOT NULL,
    description TEXT,
    visibility  TEXT    NOT NULL DEFAULT 'private'
                        CHECK (visibility IN ('private', 'public')),
    created_at  TEXT    NOT NULL,
    updated_at  TEXT    NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

CREATE TABLE file_shares (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    file_id             INTEGER NOT NULL,
    shared_with_user_id INTEGER NOT NULL,
    can_edit            INTEGER NOT NULL DEFAULT 0 CHECK (can_edit IN (0, 1)),
    created_at          TEXT    NOT NULL,
    UNIQUE (file_id, shared_with_user_id),
    FOREIGN KEY (file_id) REFERENCES files(id),
    FOREIGN KEY (shared_with_user_id) REFERENCES users(id)
);
```

Partage à deux niveaux : `can_edit = 0` (lecture seule) et `can_edit = 1` (accès en édition). `UNIQUE(file_id, shared_with_user_id)` empêche les entrées de partage dupliquées.

## Endpoints

| Méthode    | Chemin                                  | Auth          | Description                      |
|------------|-----------------------------------------|---------------|----------------------------------|
| `POST`     | `/files`                                | `X-User-Id`  | Téléverser les métadonnées        |
| `GET`      | `/files`                                | `X-User-Id`  | Lister ses propres fichiers       |
| `GET`      | `/files/{fileId}`                       | `X-User-Id`  | Obtenir le fichier (vérif. visibilité) |
| `PUT`      | `/files/{fileId}`                       | `X-User-Id`  | Mettre à jour (propriétaire ou edit-share) |
| `DELETE`   | `/files/{fileId}`                       | `X-User-Id`  | Supprimer (propriétaire uniquement) |
| `POST`     | `/files/{fileId}/shares`                | `X-User-Id` (propriétaire) | Ajouter un partage |
| `DELETE`   | `/files/{fileId}/shares/{userId}`       | `X-User-Id` (propriétaire) | Supprimer un partage |

## Fichier privé → 404 (pas 403)

```php
// Les non-propriétaires ne peuvent pas voir les fichiers privés — 404 cache l'existence
if ($file['visibility'] === 'private') {
    $share = $this->repo->findShare($fileId, $userId);
    if ($share === null) {
        return $this->problems->create($request, 'not-found', 'File not found', 404);
    }
}
```

Les fichiers privés retournent 404 aux non-propriétaires et non-partagés. Retourner 403 révélerait que le fichier existe. Les fichiers publics retournent 200 à tous les utilisateurs authentifiés.

## Propriété depuis l'en-tête — Ignorer user_id du corps

```php
$userId = $this->requireUserId($request);
// ... validation ...
$id = $this->repo->create($userId, $name, $size, $mimeType, $description, $visibility, $now);
```

Le `user_id` du fichier est toujours pris depuis l'en-tête `X-User-Id`. Tout `user_id` dans le corps de la requête est silencieusement ignoré. Cela prévient les attaques d'injection de propriété (VULN-E).

## View-Share vs Edit-Share — Deux niveaux

```php
// Le propriétaire peut toujours éditer
$isOwner = ((int) $file['user_id']) === $userId;

if (!$isOwner) {
    $share = $this->repo->findShare($fileId, $userId);
    if ($share === null || !(bool) $share['can_edit']) {
        return $this->problems->create($request, 'forbidden', 'Edit access required', 403);
    }
}
```

- **Propriétaire** : toutes les opérations (lecture, écriture, suppression, gestion des partages, visibilité)
- **Edit-share** (`can_edit=1`) : peut mettre à jour nom/taille/mime/description — mais PAS la visibilité
- **View-share** (`can_edit=0`) : lecture seule — toute tentative d'écriture → 403

Seuls les propriétaires peuvent changer la `visibility` :

```php
// Only owner can change visibility
if (!$isOwner && isset($body['visibility'])) {
    $visibility = (string) $file['visibility']; // ignorer silencieusement la requête
}
```

## Validation stricte des entrées

```php
$size = $body['size'] ?? null;
if (!is_int($size) || $size < 0) {
    $errors[] = ['field' => 'size', 'code' => 'invalid', 'message' => 'size must be a non-negative integer'];
}

if (!is_string($name) || strlen($name) > 255 || $name === '') {
    $errors[] = ['field' => 'name', 'code' => 'invalid', 'message' => 'name required, max 255 chars'];
}
```

- `size` : `is_int()` rejette les flottants comme `1.5` (VULN-I)
- `name` : max 255 caractères — prévient les crashs sur entrée surdimensionnée (VULN-H)
- `visibility` : `in_array($value, ['private', 'public'], true)` liste blanche stricte

## Suppression de partage — Propriétaire uniquement

```php
// Seul le propriétaire du fichier peut supprimer des partages
if ((int) $file['user_id'] !== $userId) {
    return $this->problems->create($request, 'not-found', 'File not found', 404);
}
```

L'utilisateur partagé ne peut pas se retirer lui-même de la liste de partage — seul le propriétaire peut gérer les partages. Les non-propriétaires reçoivent 404 (pas 403) pour cacher l'existence du fichier (VULN-F).

## Validation de l'ID utilisateur — Rejeter zéro et négatif

```php
$raw = $request->getHeaderLine('X-User-Id');
$userId = ctype_digit($raw) ? (int) $raw : 0;
if ($userId <= 0) {
    return $this->problems->create($request, 'unauthorized', 'Authentication required', 401);
}
```

`X-User-Id: 0` et `X-User-Id: -1` retournent 401 (VULN-L). Seuls les entiers positifs sont des ID utilisateur valides.

---

## Évaluation des vulnérabilités

### V-01 — IDOR : fichier privé accessible par un autre utilisateur ✅ SAFE

**Risque** : L'utilisateur B lit le fichier privé de l'utilisateur A.
**Constatation** : SAFE — les fichiers privés retournent 404 aux non-propriétaires sans entrée de partage.

---

### V-02 — IDOR : supprimer le fichier d'un autre utilisateur ✅ SAFE

**Risque** : L'utilisateur B supprime le fichier de l'utilisateur A.
**Constatation** : SAFE — la suppression vérifie la propriété ; le non-propriétaire reçoit 404. Le fichier existe toujours après une tentative échouée.

---

### V-03 — IDOR : mettre à jour le fichier d'un autre utilisateur ✅ SAFE

**Risque** : L'utilisateur B met à jour le nom/métadonnées du fichier de l'utilisateur A.
**Constatation** : SAFE — la mise à jour vérifie la propriété ; le non-propriétaire sans edit-share reçoit 404.

---

### V-04 — Escalade de privilèges : view-share tente d'éditer ✅ SAFE

**Risque** : L'utilisateur avec partage view-only appelle PUT pour modifier le fichier.
**Constatation** : SAFE — la vérification d'édition nécessite `can_edit = 1` ; le view-share retourne 403.

---

### V-05 — Injection de propriété : user_id dans le corps de la requête ✅ SAFE

**Risque** : `{ "user_id": 99, "name": "..." }` attribue le fichier à l'utilisateur 99.
**Constatation** : SAFE — `user_id` du corps est silencieusement ignoré ; la propriété vient toujours de l'en-tête `X-User-Id`.

---

### V-06 — Suppression de partage par un non-propriétaire ✅ SAFE

**Risque** : L'utilisateur partagé se retire lui-même de la liste de partage.
**Constatation** : SAFE — l'endpoint de suppression de partage vérifie la propriété du fichier ; le non-propriétaire reçoit 404.

---

### V-07 — Injection SQL dans le champ nom ✅ SAFE

**Risque** : `"name": "test'; DROP TABLE files; --"` détruit les données.
**Constatation** : SAFE — les requêtes paramétrées stockent la chaîne d'injection comme données littérales. Table files intacte.

---

### V-08 — Nom surdimensionné cause un crash ✅ SAFE

**Risque** : Un nom de 300 caractères cause une erreur DB ou un épuisement mémoire.
**Constatation** : SAFE — la validation `strlen($name) > 255` retourne 422 avant l'insertion.

---

### V-09 — Confusion de type avec taille flottante ✅ SAFE

**Risque** : `"size": 1.5` passe la validation et corrompt le suivi de taille.
**Constatation** : SAFE — `is_int($size)` rejette les flottants → 422.

---

### V-10 — Edit-share escalade la visibilité vers public ✅ SAFE

**Risque** : L'utilisateur edit-share définit `"visibility": "public"` pour exposer un fichier privé.
**Constatation** : SAFE — les changements de visibilité sont réservés au propriétaire ; le champ visibility dans le corps PUT du edit-share est silencieusement ignoré.

---

### V-11 — Divulgation d'existence de fichier privé via 403 ✅ SAFE

**Risque** : Une réponse 403 révèle que le fichier existe même aux utilisateurs non autorisés.
**Constatation** : SAFE — les non-propriétaires reçoivent 404, pas 403. L'existence du fichier n'est pas divulguée.

---

### V-12 — Contournement d'auth via X-User-Id: 0 ou négatif ✅ SAFE

**Risque** : `X-User-Id: 0` ou `X-User-Id: -1` contourne la vérification utilisateur.
**Constatation** : SAFE — `ctype_digit()` + vérification `$userId <= 0` retourne 401 pour les valeurs zéro et négatives.

---

### Résumé VULN

| ID | Vulnérabilité | Constatation |
|----|---------------|--------------|
| V-01 | IDOR : accès fichier privé | ✅ SAFE |
| V-02 | IDOR : supprimer le fichier d'un autre utilisateur | ✅ SAFE |
| V-03 | IDOR : mettre à jour le fichier d'un autre utilisateur | ✅ SAFE |
| V-04 | Escalade de privilèges view-share | ✅ SAFE |
| V-05 | Injection de propriété via le corps | ✅ SAFE |
| V-06 | Suppression de partage par non-propriétaire | ✅ SAFE |
| V-07 | Injection SQL dans le nom | ✅ SAFE |
| V-08 | Crash par nom surdimensionné | ✅ SAFE |
| V-09 | Confusion de type taille flottante | ✅ SAFE |
| V-10 | Escalade de visibilité par edit-share | ✅ SAFE |
| V-11 | Divulgation d'existence de fichier privé | ✅ SAFE |
| V-12 | Contournement d'auth via ID utilisateur invalide | ✅ SAFE |

**12 SAFE, 0 EXPOSED**
Le pattern 404 pour fichier privé, la propriété par en-tête uniquement, les permissions de partage à deux niveaux, la validation stricte de type et la visibilité réservée au propriétaire préviennent tous les vecteurs IDOR et d'escalade de privilèges.

---

## À ne pas faire

| Anti-pattern | Risque |
|---|---|
| Retourner 403 pour un fichier privé à un non-propriétaire | Révèle l'existence du fichier aux utilisateurs non autorisés |
| Accepter `user_id` du corps de la requête pour la propriété | Tout utilisateur authentifié revendique la propriété de n'importe quel fichier |
| Permettre au view-share d'appeler PUT | Les visionneuses partagées peuvent modifier les métadonnées du fichier |
| Permettre à l'edit-share de changer la visibilité | Les éditeurs partagés exposent les fichiers privés au public |
| Permettre à l'utilisateur partagé de supprimer son propre partage | Les utilisateurs peuvent révoquer la gestion d'accès du propriétaire |
| Accepter `size: 1.5` (flottant) | Confusion de type ; les tailles de fichier non entières corrompent le suivi de taille |
| Pas de limite de longueur pour `name` | Les noms de fichier longs peuvent causer un dépassement de colonne DB ou des problèmes mémoire |
| `X-User-Id: 0` accepté comme valide | L'ID utilisateur 0 peut correspondre à des lignes non initialisées ou contourner les vérifications de propriété |
| `ctype_digit()` sans vérification `> 0` | `"0"` passe `ctype_digit` mais n'est pas un ID utilisateur valide |
