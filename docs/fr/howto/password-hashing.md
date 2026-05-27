# How-to : Hachage de mot de passe

Stocker et vérifier les mots de passe de manière sécurisée avec les fonctions natives PHP `password_hash()` / `password_verify()` avec NENE2.

---

## Démarrage rapide

```php
// Inscription — hacher avant de stocker
$hash = password_hash($password, PASSWORD_ARGON2ID);
$user = $this->repo->create($email, $hash);

// Connexion — vérification à temps constant
if (!password_verify($inputPassword, $user->passwordHash)) {
    // retourner 401
}
```

---

## Algorithme : toujours utiliser `PASSWORD_ARGON2ID`

`PASSWORD_DEFAULT` est toujours `bcrypt` à partir de PHP 8.4. Argon2id est résistant à la mémoire et résiste aux attaques GPU/ASIC.

```php
// ❌ PASSWORD_DEFAULT = bcrypt — plus vulnérable au brute force GPU
$hash = password_hash($password, PASSWORD_DEFAULT);

// ✅ Argon2id — résistant à la mémoire, recommandé pour les nouveaux projets
$hash = password_hash($password, PASSWORD_ARGON2ID);
```

Argon2id nécessite PHP 7.3+. NENE2 nécessite PHP 8.4, donc il est toujours disponible.

---

## Détection des violations UNIQUE : `DatabaseConstraintException`

`PdoDatabaseQueryExecutor` de NENE2 encapsule toutes les violations de contrainte (UNIQUE, FK, NOT NULL) dans `DatabaseConstraintException` avant de les relancer. Intercepter `\PDOException` directement ne **fonctionne pas**.

```php
use Nene2\Database\DatabaseConstraintException;

// ❌ N'atteint jamais ici — PDOException est déjà encapsulée
catch (\PDOException $e) {
    if (str_contains($e->getMessage(), 'UNIQUE constraint failed')) { ... }
}

// ✅ Intercepter l'encapsuleur NENE2
catch (DatabaseConstraintException) {
    throw new DuplicateEmailException($email);
}
```

`DatabaseConstraintException` fait partie de l'API publique stable (ADR 0009).

Pattern de repository complet :

```php
use Nene2\Database\DatabaseConstraintException;
use Nene2\Database\DatabaseQueryExecutorInterface;

final class UserRepository
{
    public function __construct(private readonly DatabaseQueryExecutorInterface $executor) {}

    /** @throws DuplicateEmailException */
    public function create(string $email, string $passwordHash): User
    {
        try {
            $now = (new \DateTimeImmutable())->format('Y-m-d\TH:i:s\Z');
            $id  = $this->executor->insert(
                'INSERT INTO users (email, password_hash, created_at) VALUES (?, ?, ?)',
                [$email, $passwordHash, $now],
            );

            return new User(id: $id, email: $email, passwordHash: $passwordHash, createdAt: $now);
        } catch (DatabaseConstraintException) {
            throw new DuplicateEmailException($email);
        }
    }
}
```

---

## Prévention de l'énumération d'utilisateurs (attaque de timing)

Si vous retournez 401 immédiatement quand l'email n'est pas trouvé, une différence de timing révèle si l'email existe — les réponses "non trouvé" reviennent instantanément, tandis que les réponses "mauvais mot de passe" prennent tout le temps de calcul Argon2id.

```php
// ❌ Fuite de timing — "non trouvé" est mesurably plus rapide
if ($user === null) {
    return $this->problems->create($request, 'invalid-credentials', ...);
}
if (!password_verify($password, $user->passwordHash)) {
    return $this->problems->create($request, 'invalid-credentials', ...);
}

// ✅ Toujours exécuter password_verify — temps constant que l'utilisateur existe ou non
$dummyHash   = '$argon2id$v=19$m=65536,t=4,p=1$dummysaltdummysaltdummysalt$dummyhashvaluedummyhashvaluedummyh';
$hashToCheck = $user !== null ? $user->passwordHash : $dummyHash;

if (!password_verify($password, $hashToCheck) || $user === null) {
    return $this->problems->create($request, 'invalid-credentials', 'Invalid Credentials', 401,
        'The email or password is incorrect.');
}
```

Le hash factice **doit** être une chaîne au format Argon2id valide commençant par `$argon2id$`. Si ce n'est pas le cas, `password_verify()` court-circuite et retourne `false` immédiatement, recréant la fuite de timing.

---

## `password_verify()` est agnostique à l'algorithme

`password_verify()` lit le préfixe du hash pour déterminer l'algorithme. Vous n'avez pas besoin de modifier le code de vérification lors de la migration de bcrypt vers Argon2id.

```php
// Fonctionne sur les hashes bcrypt et Argon2id
$result = password_verify($plaintext, $storedHash); // toujours correct
```

Utiliser `password_needs_rehash()` à la connexion réussie pour mettre à niveau les hashes hérités de manière transparente :

```php
if (password_verify($password, $user->passwordHash)) {
    if (password_needs_rehash($user->passwordHash, PASSWORD_ARGON2ID)) {
        $newHash = password_hash($password, PASSWORD_ARGON2ID);
        $this->repo->updatePasswordHash($user->id, $newHash);
    }
    // continuer avec l'utilisateur authentifié
}
```

---

## Ne jamais inclure `password_hash` dans la réponse

`toArray()` ou des helpers similaires peuvent inclure chaque colonne. Lister explicitement uniquement les champs que vous avez l'intention de retourner.

```php
// ❌ Peut exposer password_hash si $user a une méthode toArray()
return $this->json->create($user->toArray(), 201);

// ✅ Liste de champs explicite — password_hash n'est jamais présent
return $this->json->create([
    'id'         => $user->id,
    'email'      => $user->email,
    'created_at' => $user->createdAt,
], 201);
```

---

## Conflit de nom `RouteRegistrar::register()`

Le contrat `RouteRegistrar` de NENE2 requiert une méthode publique `register(Router $router)`. Ne **pas** nommer un gestionnaire de route `register()` — PHP rejettera le nom de méthode dupliqué.

```php
// ❌ Erreur fatale : Impossible de redéclarer RouteRegistrar::register()
$router->post('/register', $this->register(...));
private function register(...) { ... }

// ✅ Utiliser un nom de gestionnaire distinct
$router->post('/register', $this->handleRegister(...));
private function handleRegister(...) { ... }
```

---

## Liste de vérification pour la revue de code

- [ ] `password_hash()` avec `PASSWORD_ARGON2ID` est utilisé (pas MD5, SHA-1, bcrypt ou `PASSWORD_DEFAULT`)
- [ ] `password_verify()` est utilisé pour la comparaison (pas `===`, `hash_equals()` ou comparaison personnalisée)
- [ ] `password_verify()` s'exécute même quand l'utilisateur n'est pas trouvé (pattern hash factice)
- [ ] `DatabaseConstraintException` est interceptée pour la détection d'email/nom d'utilisateur dupliqué
- [ ] Les champs `password_hash` / `password` sont exclus de toutes les réponses API
- [ ] La connexion retourne 401 (pas 404) pour un email inconnu — ne jamais révéler si l'email existe
- [ ] Le mot de passe en clair n'est pas écrit dans les logs
