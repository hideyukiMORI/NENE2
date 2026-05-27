# Comment construire un système de suivi d'utilisateurs avec NENE2

Ce guide explique comment construire un système de suivi social de style Twitter/Instagram — les utilisateurs se suivent mutuellement, consultent les listes de followers/following, et vérifient le statut de suivi mutuel.

**Field Trial** : FT134  
**Version NENE2** : ^1.5  
**Sujets couverts** : follow/unfollow, écritures idempotentes, requêtes de graphe, application de contrainte souple

---

## Ce que nous construisons

Une API REST qui permet aux utilisateurs de :

- Suivre et ne plus suivre d'autres utilisateurs (follow idempotent, 204 pour unfollow)
- Interroger les listes de followers/following (ordonnées par le plus récent en premier)
- Vérifier les comptages de followers/following en un seul appel stats
- Vérifier si un utilisateur spécifique en suit un autre

---

## Schéma de base de données

```sql
CREATE TABLE users (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT NOT NULL,
    created_at TEXT NOT NULL
);

CREATE TABLE follows (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    follower_id INTEGER NOT NULL,
    followee_id INTEGER NOT NULL,
    created_at  TEXT    NOT NULL,
    UNIQUE (follower_id, followee_id),
    CHECK  (follower_id != followee_id),
    FOREIGN KEY (follower_id) REFERENCES users(id),
    FOREIGN KEY (followee_id) REFERENCES users(id)
);
```

Deux contraintes font le travail lourd au niveau DB :

- `UNIQUE (follower_id, followee_id)` — empêche les lignes de suivi en double
- `CHECK (follower_id != followee_id)` — empêche l'auto-suivi au niveau DB (l'application valide également ceci)

---

## Endpoints API

| Méthode   | Chemin                                          | Description                            |
|-----------|-------------------------------------------------|----------------------------------------|
| `POST`    | `/users`                                        | Créer un utilisateur                   |
| `POST`    | `/users/{followerId}/follow`                    | Suivre un utilisateur (idempotent)     |
| `DELETE`  | `/users/{followerId}/follow/{followeeId}`       | Ne plus suivre un utilisateur          |
| `GET`     | `/users/{userId}/stats`                         | Obtenir les comptages followers/following |
| `GET`     | `/users/{userId}/followers`                     | Lister les followers                   |
| `GET`     | `/users/{userId}/following`                     | Lister les utilisateurs que cet utilisateur suit |
| `GET`     | `/users/{followerId}/is-following/{followeeId}` | Vérifier la relation de suivi          |

---

## Repository

```php
final class FollowRepository
{
    public function __construct(
        private readonly DatabaseQueryExecutorInterface $executor,
    ) {}

    public function follow(int $followerId, int $followeeId, string $now): bool
    {
        if ($this->isFollowing($followerId, $followeeId)) {
            return false; // suit déjà — retourner false pour que le handler envoie 200
        }

        $this->executor->execute(
            'INSERT INTO follows (follower_id, followee_id, created_at) VALUES (?, ?, ?)',
            [$followerId, $followeeId, $now],
        );

        return true; // nouveau suivi — le handler envoie 201
    }

    public function unfollow(int $followerId, int $followeeId): bool
    {
        $count = $this->executor->execute(
            'DELETE FROM follows WHERE follower_id = ? AND followee_id = ?',
            [$followerId, $followeeId],
        );

        return $count > 0;
    }

    public function isFollowing(int $followerId, int $followeeId): bool
    {
        return $this->executor->fetchOne(
            'SELECT id FROM follows WHERE follower_id = ? AND followee_id = ?',
            [$followerId, $followeeId],
        ) !== null;
    }

    /** @return array<int, array{id: int, name: string}> */
    public function listFollowers(int $userId): array
    {
        $rows = $this->executor->fetchAll(
            'SELECT u.id, u.name FROM users u
             INNER JOIN follows f ON f.follower_id = u.id
             WHERE f.followee_id = ?
             ORDER BY f.id DESC',
            [$userId],
        );

        return array_map(fn(mixed $row) => $this->hydrateUser((array) $row), $rows);
    }

    /** @return array<int, array{id: int, name: string}> */
    public function listFollowing(int $userId): array
    {
        $rows = $this->executor->fetchAll(
            'SELECT u.id, u.name FROM users u
             INNER JOIN follows f ON f.followee_id = u.id
             WHERE f.follower_id = ?
             ORDER BY f.id DESC',
            [$userId],
        );

        return array_map(fn(mixed $row) => $this->hydrateUser((array) $row), $rows);
    }
}
```

La méthode `follow()` retourne `bool` pour signaler nouveau-vs-existant afin que le handler puisse émettre le statut HTTP correct sans requête supplémentaire.

---

## Handler de suivi — POST idempotent

La partie délicate est de retourner **201 Created** pour un nouveau suivi et **200 OK** pour un suivi répété, tout en rejetant l'auto-suivi avec 422.

```php
private function followUser(ServerRequestInterface $request): ResponseInterface
{
    $params     = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
    $followerId = isset($params['followerId']) && is_numeric($params['followerId'])
        ? (int) $params['followerId'] : 0;

    if ($followerId <= 0 || !$this->repo->findUserById($followerId)) {
        return $this->responseFactory->create(['error' => 'follower not found'], 404);
    }

    $body       = JsonRequestBodyParser::parse($request);
    $followeeId = isset($body['followee_id']) && is_int($body['followee_id'])
        ? $body['followee_id'] : 0;

    if ($followeeId <= 0 || !$this->repo->findUserById($followeeId)) {
        return $this->responseFactory->create(['error' => 'followee not found'], 404);
    }

    if ($followerId === $followeeId) {
        return $this->responseFactory->create(['error' => 'cannot follow yourself'], 422);
    }

    $wasNew = $this->repo->follow($followerId, $followeeId, date('Y-m-d H:i:s'));
    $status = $wasNew ? 201 : 200;

    return $this->responseFactory->create([
        'follower_id' => $followerId,
        'followee_id' => $followeeId,
        'following'   => true,
    ], $status);
}
```

**Pourquoi valider avant la vérification d'auto-suivi ?** Les vérifications 404 viennent d'abord pour que `POST /users/9999/follow` avec `followee_id: 9999` retourne 404 (l'utilisateur n'existe pas) plutôt que 422 (auto-suivi). Les erreurs d'existence ont la priorité.

---

## Handler de cessation de suivi — 204 ou 404

```php
private function unfollowUser(ServerRequestInterface $request): ResponseInterface
{
    $params     = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
    $followerId = isset($params['followerId']) && is_numeric($params['followerId'])
        ? (int) $params['followerId'] : 0;
    $followeeId = isset($params['followeeId']) && is_numeric($params['followeeId'])
        ? (int) $params['followeeId'] : 0;

    if ($followerId <= 0 || !$this->repo->findUserById($followerId)) {
        return $this->responseFactory->create(['error' => 'follower not found'], 404);
    }

    $removed = $this->repo->unfollow($followerId, $followeeId);

    if (!$removed) {
        return $this->responseFactory->create(['error' => 'not following'], 404);
    }

    return $this->responseFactory->createEmpty(204);
}
```

Note : `createEmpty(204)` — `JsonResponseFactory` a une méthode dédiée pour les réponses sans corps.

---

## Endpoint de statistiques

```php
private function stats(ServerRequestInterface $request): ResponseInterface
{
    $params = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
    $userId = isset($params['userId']) && is_numeric($params['userId'])
        ? (int) $params['userId'] : 0;

    if ($userId <= 0 || !$this->repo->findUserById($userId)) {
        return $this->responseFactory->create(['error' => 'user not found'], 404);
    }

    return $this->responseFactory->create([
        'user_id'         => $userId,
        'followers_count' => $this->repo->followerCount($userId),
        'following_count' => $this->repo->followingCount($userId),
    ]);
}
```

---

## AppFactory

```php
final class AppFactory
{
    public static function createSqliteApp(string $dbPath): RequestHandlerInterface
    {
        $dbConfig = new DatabaseConfig(
            url: null,
            environment: 'test',
            adapter: 'sqlite',
            host: '',
            port: 1,
            name: $dbPath,
            user: '',
            password: '',
            charset: '',
        );

        $factory  = new PdoConnectionFactory($dbConfig);
        $executor = new PdoDatabaseQueryExecutor($factory);
        $psr17    = new Psr17Factory();
        $json     = new JsonResponseFactory($psr17, $psr17);

        $repo      = new FollowRepository($executor);
        $registrar = new RouteRegistrar($repo, $json);

        return new RuntimeApplicationFactory(
            $psr17,
            $psr17,
            routeRegistrars: [static fn(Router $r) => $registrar->register($r)],
        )->create();
    }
}
```

---

## Stratégie de test

Chaque test s'exécute contre un fichier SQLite en mémoire fraîchement créé dans `setUp()` et supprimé dans `tearDown()`.

Cas clés :

```php
// Suivi idempotent → 200
public function testFollowIdempotentReturns200(): void
{
    $alice = $this->createUser('Alice');
    $bob   = $this->createUser('Bob');

    $this->request('POST', "/users/{$alice}/follow", ['followee_id' => $bob]);
    $res = $this->request('POST', "/users/{$alice}/follow", ['followee_id' => $bob]);

    $this->assertSame(200, $res->getStatusCode());
    $this->assertTrue($this->json($res)['following']);
}

// Auto-suivi → 422
public function testFollowSelfReturns422(): void
{
    $alice = $this->createUser('Alice');
    $res   = $this->request('POST', "/users/{$alice}/follow", ['followee_id' => $alice]);
    $this->assertSame(422, $res->getStatusCode());
}

// Cessation de suivi puis re-suivi → 201 à nouveau
public function testUnfollowThenRefollow(): void
{
    $alice = $this->createUser('Alice');
    $bob   = $this->createUser('Bob');

    $this->request('POST', "/users/{$alice}/follow", ['followee_id' => $bob]);
    $this->request('DELETE', "/users/{$alice}/follow/{$bob}");
    $res = $this->request('POST', "/users/{$alice}/follow", ['followee_id' => $bob]);

    $this->assertSame(201, $res->getStatusCode());
}

// Suivi mutuel
public function testMutualFollow(): void
{
    $alice = $this->createUser('Alice');
    $bob   = $this->createUser('Bob');

    $this->request('POST', "/users/{$alice}/follow", ['followee_id' => $bob]);
    $this->request('POST', "/users/{$bob}/follow", ['followee_id' => $alice]);

    $this->assertTrue($this->json($this->request('GET', "/users/{$alice}/is-following/{$bob}"))['following']);
    $this->assertTrue($this->json($this->request('GET', "/users/{$bob}/is-following/{$alice}"))['following']);
}
```

---

## Convention de tri

Les deux `listFollowers` et `listFollowing` utilisent `ORDER BY f.id DESC` — l'utilisateur suivi le plus récemment apparaît en premier. Cela reflète le comportement de l'onglet "followers" de Twitter/Instagram.

---

## Pièges courants

| Piège | Correction |
|-------|-----------|
| Retourner 422 pour un utilisateur inconnu dans la vérification d'auto-suivi | Valider l'existence de l'utilisateur *avant* la vérification d'auto-suivi |
| `followee_id` lu depuis le chemin au lieu du corps | `followee_id` est dans le corps de la requête ; `followeeId` est le paramètre de chemin pour DELETE |
| Utiliser `createJson()` | La méthode est `create(array $data, int $status = 200)` |
| Utiliser `createEmpty()` pour un 200 avec corps | N'utiliser `createEmpty()` que pour 204 No Content |
| Oublier l'appel `JsonRequestBodyParser::parse()` | Doit être appelé manuellement dans chaque handler qui lit un corps JSON |
