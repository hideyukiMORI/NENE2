# Benutzer-Follow-System mit NENE2 aufbauen

Diese Anleitung führt durch den Aufbau eines Twitter/Instagram-ähnlichen sozialen Follow-Systems — Benutzer folgen sich gegenseitig, sehen Follower-/Following-Listen und prüfen gegenseitigen Follow-Status.

**Field Trial**: FT134  
**NENE2-Version**: ^1.5  
**Behandelte Themen**: Follow/Unfollow, idempotente Schreibvorgänge, Graph-Abfragen, Soft-Constraint-Enforcement

---

## Was wird gebaut

Eine REST API, die Benutzern erlaubt:

- Andere Benutzer zu folgen und zu entfolgen (idempotenter Follow, 204 Unfollow)
- Follower-/Following-Listen abzufragen (geordnet nach neuesten zuerst)
- Follower-/Following-Anzahl in einem Stat-Aufruf zu prüfen
- Zu prüfen, ob ein bestimmter Benutzer einem anderen folgt

---

## Datenbankschema

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

Zwei Constraints leisten die Hauptarbeit auf DB-Ebene:

- `UNIQUE (follower_id, followee_id)` — verhindert doppelte Follow-Zeilen
- `CHECK (follower_id != followee_id)` — verhindert Self-Follow auf DB-Ebene (Anwendung validiert das ebenfalls)

---

## API-Endpunkte

| Methode | Pfad | Beschreibung |
|---------|------|--------------|
| `POST` | `/users` | Benutzer erstellen |
| `POST` | `/users/{followerId}/follow` | Benutzer folgen (idempotent) |
| `DELETE` | `/users/{followerId}/follow/{followeeId}` | Benutzer entfolgen |
| `GET` | `/users/{userId}/stats` | Follower-/Following-Anzahl abrufen |
| `GET` | `/users/{userId}/followers` | Follower auflisten |
| `GET` | `/users/{userId}/following` | Benutzer auflisten, denen dieser Benutzer folgt |
| `GET` | `/users/{followerId}/is-following/{followeeId}` | Follow-Beziehung prüfen |

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
            return false; // folgt bereits — false zurückgeben, damit Handler 200 sendet
        }

        $this->executor->execute(
            'INSERT INTO follows (follower_id, followee_id, created_at) VALUES (?, ?, ?)',
            [$followerId, $followeeId, $now],
        );

        return true; // neuer Follow — Handler sendet 201
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

Die `follow()`-Methode gibt `bool` zurück, um neu-vs-existierend zu signalisieren, damit der Handler den richtigen HTTP-Status senden kann, ohne eine zusätzliche Abfrage.

---

## Follow-Handler — idempotenter POST

Das Knifflige ist, **201 Created** für einen neuen Follow und **200 OK** für einen wiederholten Follow zurückzugeben, während Self-Follow mit 422 abgelehnt wird.

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

**Warum vor der Self-Follow-Prüfung validieren?** Die 404-Prüfungen kommen zuerst, sodass `POST /users/9999/follow` mit `followee_id: 9999` 404 (Benutzer existiert nicht) statt 422 (Self-Follow) zurückgibt. Existenzfehler haben Priorität.

---

## Unfollow-Handler — 204 oder 404

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

Hinweis: `createEmpty(204)` — `JsonResponseFactory` hat eine dedizierte Methode für Body-lose Antworten.

---

## Stats-Endpunkt

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

## Teststrategie

Jeder Test läuft gegen eine frische In-Memory-SQLite-Datei, die in `setUp()` erstellt und in `tearDown()` gelöscht wird.

Wichtige Testfälle:

```php
// Idempotenter Follow → 200
public function testFollowIdempotentReturns200(): void
{
    $alice = $this->createUser('Alice');
    $bob   = $this->createUser('Bob');

    $this->request('POST', "/users/{$alice}/follow", ['followee_id' => $bob]);
    $res = $this->request('POST', "/users/{$alice}/follow", ['followee_id' => $bob]);

    $this->assertSame(200, $res->getStatusCode());
    $this->assertTrue($this->json($res)['following']);
}

// Self-Follow → 422
public function testFollowSelfReturns422(): void
{
    $alice = $this->createUser('Alice');
    $res   = $this->request('POST', "/users/{$alice}/follow", ['followee_id' => $alice]);
    $this->assertSame(422, $res->getStatusCode());
}

// Entfolgen dann erneut folgen → 201
public function testUnfollowThenRefollow(): void
{
    $alice = $this->createUser('Alice');
    $bob   = $this->createUser('Bob');

    $this->request('POST', "/users/{$alice}/follow", ['followee_id' => $bob]);
    $this->request('DELETE', "/users/{$alice}/follow/{$bob}");
    $res = $this->request('POST', "/users/{$alice}/follow", ['followee_id' => $bob]);

    $this->assertSame(201, $res->getStatusCode());
}

// Gegenseitiger Follow
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

## Sortierkonvention

Sowohl `listFollowers` als auch `listFollowing` verwenden `ORDER BY f.id DESC` — der zuletzt gefolgte Benutzer erscheint zuerst. Das spiegelt das Verhalten des "Followers"-Tabs von Twitter/Instagram wider.

---

## Häufige Fallstricke

| Fallstrick | Lösung |
|-----------|--------|
| 422 für unbekannten Benutzer bei Self-Follow-Prüfung zurückgeben | Benutzerexistenz *vor* der Self-Follow-Prüfung validieren |
| `followee_id` aus dem Pfad statt aus dem Body lesen | `followee_id` ist im Request-Body; `followeeId` ist der Pfadparameter für DELETE |
| `createJson()` verwenden | Die Methode heißt `create(array $data, int $status = 200)` |
| `createEmpty()` für eine 200-Antwort mit Body verwenden | `createEmpty()` nur für 204 No Content verwenden |
| `JsonRequestBodyParser::parse()` fehlt | Muss in jedem Handler, der einen JSON-Body liest, manuell aufgerufen werden |
