# How-to : Authentification par mot de passe avec Argon2id

> **Référence FT** : FT331 (`NENE2-FT/pwdlog`) — Inscription et connexion utilisateur avec hachage Argon2id, mot de passe/hash jamais exposé dans les réponses, prévention de l'énumération d'utilisateurs (même 401 pour mauvais mot de passe et email inconnu), re-hachage lors de la migration d'algorithme, 14 tests / 40 assertions PASS.

Ce guide montre comment créer une authentification sécurisée par mot de passe : stocker les mots de passe de manière sûre avec Argon2id, ne jamais exposer les credentials dans les réponses, et empêcher les attaquants d'énumérer les adresses email enregistrées.

## Schéma

```sql
CREATE TABLE users (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    email         TEXT    NOT NULL UNIQUE,
    password_hash TEXT    NOT NULL,
    created_at    TEXT    NOT NULL
);
```

`password_hash` stocke la chaîne de sortie complète d'Argon2id (ex. `$argon2id$v=19$m=65536,...`). **Ne jamais stocker en clair ni utiliser MD5/SHA-1.**

## Endpoints

| Méthode | Chemin | Description |
|---------|--------|-------------|
| `POST` | `/register` | Inscrire un nouvel utilisateur |
| `POST` | `/login` | S'authentifier et retourner les données utilisateur |

## Inscription

```php
POST /register
{"email": "alice@example.com", "password": "correct-horse"}

→ 201
{"id": 1, "email": "alice@example.com", "created_at": "2026-05-27T09:00:00Z"}
```

**`password` et `password_hash` ne sont JAMAIS retournés** dans la réponse — même masqués ou tronqués.

### Validation

```php
POST /register  {"email": "alice@example.com", "password": "court"}
→ 422  // mot de passe trop court (minimum 8 caractères)

POST /register  {"email": "pas-un-email", "password": "correct-horse"}
→ 422  // format email invalide

POST /register  {"email": "alice@example.com"}
→ 400  // champ password manquant

POST /register  {"email": "alice@example.com", "password": "battery-staple"}
// (après qu'alice est déjà inscrite)
→ 409  {"type": ".../email-taken", "detail": "Email already registered"}
```

## Connexion

```php
POST /login
{"email": "alice@example.com", "password": "correct-horse"}

→ 200
{"id": 1, "email": "alice@example.com", "created_at": "..."}
// password_hash non retourné
```

### Prévention de l'énumération d'utilisateurs

```php
// Mauvais mot de passe pour email connu
POST /login  {"email": "alice@example.com", "password": "mauvais"}
→ 401  {"type": ".../invalid-credentials", "detail": "Invalid email or password"}

// Email inconnu
POST /login  {"email": "fantome@example.com", "password": "n'importe"}
→ 401  {"type": ".../invalid-credentials", "detail": "Invalid email or password"}
```

**Les deux cas retournent le même 401 avec le message `detail` identique.** Retourner 404 pour un email inconnu permettrait aux attaquants de sonder la base de données utilisateurs.

```php
// Test : même chaîne detail
$this->assertSame($wrongPasswordBody['detail'], $unknownEmailBody['detail']);
```

## Implémentation

### Stockage du mot de passe — Argon2id

```php
// Inscription
$hash = password_hash($plaintext, PASSWORD_ARGON2ID);
// Stocke : $argon2id$v=19$m=65536,t=4,p=1$...

// Ne jamais stocker :
// md5($plaintext)          — réversible en secondes
// sha1($plaintext)         — attaque par table arc-en-ciel
// $plaintext               — stockage en clair
```

`password_hash(PASSWORD_ARGON2ID)` de PHP :
- Génère automatiquement un sel aléatoire par hash
- Stocke l'algorithme, les paramètres, le sel et le condensé dans une seule chaîne
- Résiste au brute force GPU (résistant à la mémoire)

### Vérification — Temps constant

```php
$row = $this->repo->findByEmail($email);

if ($row === null || !password_verify($plaintext, $row['password_hash'])) {
    // Même réponse que l'email soit inconnu ou le mot de passe incorrect
    return $this->problems->create('invalid-credentials', 'Invalid email or password', 401);
}
```

`password_verify()` est à temps constant et fonctionne sur toutes les familles d'algorithmes (bcrypt, Argon2id, etc.).

### Re-hachage lors de la migration d'algorithme

Lors de la mise à niveau de bcrypt vers Argon2id, re-hacher à la connexion réussie :

```php
if (password_needs_rehash($row['password_hash'], PASSWORD_ARGON2ID)) {
    $newHash = password_hash($plaintext, PASSWORD_ARGON2ID);
    $this->repo->updateHash($row['id'], $newHash);
}
```

Les utilisateurs sont silencieusement migrés vers l'algorithme plus fort la prochaine fois qu'ils se connectent — pas de réinitialisation de mot de passe forcée requise.

### Ne jamais retourner les credentials

```php
private function toPublic(array $user): array
{
    // Supprimer explicitement les champs sensibles
    unset($user['password_hash']);
    return $user;
}
```

Appliquer `toPublic()` à chaque réponse : inscription 201, connexion 200, et tout endpoint de profil.

---

## À ne pas faire

| Anti-pattern | Risque |
|---|---|
| Retourner 404 pour email inconnu à la connexion | Énumération d'utilisateurs : l'attaquant découvre quels emails sont enregistrés |
| Retourner un message `detail` différent pour mauvais mot de passe vs email inconnu | Révèle quelle condition a échoué |
| Stocker le mot de passe en MD5 ou SHA-1 | Attaque par table arc-en-ciel casse tous les mots de passe en quelques heures |
| Stocker le mot de passe en bcrypt sans chemin de migration | Impossible de passer à un algorithme plus fort sans réinitialisation forcée |
| Retourner `password_hash` dans n'importe quelle réponse | Le hash peut être utilisé pour un brute force hors ligne |
| Ignorer `password_needs_rehash()` à la connexion | Les hashes faibles hérités persistent indéfiniment même après la mise à niveau d'algorithme |
| Utiliser `===` pour comparer les hashes | Attaque de timing révèle les octets du hash ; toujours utiliser `password_verify()` |
