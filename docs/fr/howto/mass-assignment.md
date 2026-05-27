# Défense contre la mass assignment

La mass assignment est une vulnérabilité où un attaquant ajoute des champs supplémentaires à un corps de requête — comme `role=admin` ou `is_active=false` — et le serveur les persiste sans l'avoir voulu.

NENE2 n'a pas de méthode magique `create($body)` qui rendrait cela facile à déclencher accidentellement. Même ainsi, le pattern de liste blanche DTO est la défense correcte et explicite.

## La vulnérabilité

```php
// ❌ Dangereux : $body passé directement à INSERT
$body = json_decode((string) $request->getBody(), true);

$this->executor->insert(
    'INSERT INTO users (name, email, role, is_active) VALUES (?, ?, ?, ?)',
    [$body['name'], $body['email'], $body['role'] ?? 'user', $body['is_active'] ?? 1],
);
```

Un attaquant envoie :

```json
{
  "name": "Attacker",
  "email": "attacker@example.com",
  "role": "admin"
}
```

Parce que `$body['role']` est lu depuis la requête, l'attaquant obtient `role=admin` dans la base de données.

## La défense : liste blanche explicite par DTO

Définir un DTO qui contient uniquement les champs qu'un utilisateur est autorisé à fournir :

```php
/**
 * Seuls name et email sont acceptés en entrée utilisateur.
 * role et is_active sont définis par la logique côté serveur, jamais depuis la requête.
 */
final readonly class CreateUserInput
{
    public function __construct(
        public string $name,
        public string $email,
    ) {}
}
```

Dans le contrôleur, mapper uniquement les champs autorisés vers le DTO :

```php
// ✅ Les champs supplémentaires (role, is_active, id, created_at) ne sont jamais lus depuis $body
$input = new CreateUserInput(
    name:  trim((string) $body['name']),
    email: strtolower(trim((string) $body['email'])),
);

$user = $this->repo->create($input);
```

Dans le repository, utiliser directement les propriétés du DTO :

```php
public function create(CreateUserInput $input): User
{
    $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
    $id = $this->executor->insert(
        'INSERT INTO users (name, email, role, is_active, created_at) VALUES (?, ?, ?, ?, ?)',
        [$input->name, $input->email, 'user', 1, $now], // role et is_active sont codés en dur
    );
    // ...
}
```

Même si l'attaquant envoie `role=admin`, `$input` n'a que `name` et `email` — le champ supplémentaire n'atteint jamais l'INSERT.

## Scénarios d'attaque couverts

| Champ | Intention d'attaque | Défense |
|-------|---------------------|---------|
| `role=admin` | Escalade de privilèges | `role` n'est pas dans `CreateUserInput` ; toujours défini à `'user'` dans le repository |
| `is_active=false` | Créer un compte désactivé ou verrouiller un utilisateur | `is_active` pas dans le DTO ; toujours défini à `1` |
| `id=9999` | Écraser la clé primaire | `id` pas dans le DTO ; auto-assigné par SQLite |
| `created_at=2000-01-01` | Falsifier l'horodatage d'audit | `created_at` pas dans le DTO ; toujours défini à l'heure courante |

## Contrôle des champs de réponse

La défense s'étend à la réponse : ne jamais retourner les lignes DB directement. Mapper explicitement ce qui doit être inclus :

```php
return $this->json->create([
    'id'         => $user->id,
    'name'       => $user->name,
    'email'      => $user->email,
    'role'       => $user->role,
    'is_active'  => $user->isActive,
    'created_at' => $user->createdAt,
    // password_hash intentionnellement exclu
    // deleted_at intentionnellement exclu
], 201);
```

Tester l'absence des champs sensibles :

```php
$this->assertArrayNotHasKey('password_hash', $data);
$this->assertArrayNotHasKey('deleted_at', $data);
```

## Services internes de confiance

Quand un service interne doit créer un utilisateur admin (ex: un service de provisionnement), utiliser un DTO séparé :

```php
final readonly class AdminCreateUserInput
{
    public function __construct(
        public string $name,
        public string $email,
        public string $role,   // autorisé pour les appelants internes uniquement
        public bool $isActive,
    ) {}
}
```

Appeler ce DTO uniquement depuis des chemins de code qui ont déjà vérifié l'identité de l'appelant (ex: clé API machine, auth de service interne). Ne jamais exposer un endpoint HTTP public qui accepte `AdminCreateUserInput` directement.

## `create()` vs `createList()` pour les réponses

Lors du retour d'une liste, utiliser `createList()` au lieu de `create()` :

```php
// ✅ Tableau JSON de premier niveau
return $this->json->createList(array_map(fn (User $u) => [...], $users));

// ✅ Objet JSON de premier niveau
return $this->json->create(['id' => $user->id, ...], 201);
```

`create()` attend `array<string, mixed>` (un objet). Passer directement la sortie de `array_map()` à `create()` cause une erreur de type PHPStan level 8 parce que `array_map` retourne une `list<T>`.

## Checklist de revue de code

- [ ] Les champs du corps de requête sont mappés vers un DTO avant d'être passés au repository
- [ ] Le DTO ne contient que les champs que l'utilisateur est autorisé à fournir
- [ ] Les champs contrôlés par le serveur (`role`, `is_active`, horodatages, clés primaires) sont définis dans le repository, pas lus depuis `$body`
- [ ] La réponse liste explicitement les champs retournés ; pas de `SELECT *` générique ni de sérialisation directe de ligne en JSON
- [ ] Les tests vérifient que les champs de requête supplémentaires sont ignorés et n'affectent pas la valeur persistée
