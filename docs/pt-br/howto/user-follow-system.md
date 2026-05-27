# Como Construir um Sistema de Seguir Usuários com NENE2

Este guia apresenta a construção de um sistema de seguir social estilo Twitter/Instagram — usuários se seguem, visualizam listas de seguidores/seguindo e verificam o status de seguimento mútuo.

**Field Trial**: FT134  
**Versão NENE2**: ^1.5  
**Tópicos abordados**: seguir/deixar de seguir, escritas idempotentes, queries de grafo, aplicação de restrições suaves

---

## O que estamos construindo

Uma API REST que permite que usuários:

- Sigam e deixem de seguir outros usuários (seguir idempotente, 204 para deixar de seguir)
- Consultem listas de seguidores/seguindo (ordenadas pelas mais recentes primeiro)
- Verifiquem contagens de seguidores/seguindo em uma única chamada de estatísticas
- Verifiquem se um usuário específico está seguindo outro

---

## Schema do banco de dados

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

Duas restrições fazem o trabalho pesado na camada do banco:

- `UNIQUE (follower_id, followee_id)` — previne linhas de seguir duplicadas
- `CHECK (follower_id != followee_id)` — previne auto-seguimento no nível do banco (a aplicação também valida isso)

---

## Endpoints da API

| Método   | Caminho                                         | Descrição                            |
|----------|-------------------------------------------------|--------------------------------------|
| `POST`   | `/users`                                        | Criar um usuário                     |
| `POST`   | `/users/{followerId}/follow`                    | Seguir um usuário (idempotente)      |
| `DELETE` | `/users/{followerId}/follow/{followeeId}`       | Deixar de seguir um usuário          |
| `GET`    | `/users/{userId}/stats`                         | Obter contagens de seguidores/seguindo |
| `GET`    | `/users/{userId}/followers`                     | Listar seguidores                    |
| `GET`    | `/users/{userId}/following`                     | Listar usuários que este usuário segue |
| `GET`    | `/users/{followerId}/is-following/{followeeId}` | Verificar relacionamento de seguimento |

---

## Repositório

```php
final class FollowRepository
{
    public function __construct(
        private readonly DatabaseQueryExecutorInterface $executor,
    ) {}

    public function follow(int $followerId, int $followeeId, string $now): bool
    {
        if ($this->isFollowing($followerId, $followeeId)) {
            return false; // já seguindo — retorna false para o handler enviar 200
        }

        $this->executor->execute(
            'INSERT INTO follows (follower_id, followee_id, created_at) VALUES (?, ?, ?)',
            [$followerId, $followeeId, $now],
        );

        return true; // novo seguimento — handler envia 201
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

O método `follow()` retorna `bool` para sinalizar novo vs. existente, para que o handler possa emitir o status HTTP correto sem uma query extra.

---

## Handler de seguimento — POST idempotente

A parte complicada é retornar **201 Created** para um novo seguimento e **200 OK** para um seguimento repetido, enquanto rejeita auto-seguimento com 422.

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

**Por que validar antes da verificação de auto-seguimento?** As verificações de 404 vêm primeiro para que `POST /users/9999/follow` com `followee_id: 9999` retorne 404 (usuário não existe) em vez de 422 (auto-seguimento). Erros de existência têm prioridade.

---

## Handler de deixar de seguir — 204 ou 404

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

Nota: `createEmpty(204)` — `JsonResponseFactory` tem um método dedicado para respostas sem corpo.

---

## Endpoint de estatísticas

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

## Estratégia de testes

Cada teste é executado em um arquivo SQLite fresco em memória, criado em `setUp()` e deletado em `tearDown()`.

Casos principais:

```php
// Seguimento idempotente → 200
public function testFollowIdempotentReturns200(): void
{
    $alice = $this->createUser('Alice');
    $bob   = $this->createUser('Bob');

    $this->request('POST', "/users/{$alice}/follow", ['followee_id' => $bob]);
    $res = $this->request('POST', "/users/{$alice}/follow", ['followee_id' => $bob]);

    $this->assertSame(200, $res->getStatusCode());
    $this->assertTrue($this->json($res)['following']);
}

// Auto-seguimento → 422
public function testFollowSelfReturns422(): void
{
    $alice = $this->createUser('Alice');
    $res   = $this->request('POST', "/users/{$alice}/follow", ['followee_id' => $alice]);
    $this->assertSame(422, $res->getStatusCode());
}

// Deixar de seguir e re-seguir → 201 novamente
public function testUnfollowThenRefollow(): void
{
    $alice = $this->createUser('Alice');
    $bob   = $this->createUser('Bob');

    $this->request('POST', "/users/{$alice}/follow", ['followee_id' => $bob]);
    $this->request('DELETE', "/users/{$alice}/follow/{$bob}");
    $res = $this->request('POST', "/users/{$alice}/follow", ['followee_id' => $bob]);

    $this->assertSame(201, $res->getStatusCode());
}

// Seguimento mútuo
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

## Convenção de ordenação

Tanto `listFollowers` quanto `listFollowing` usam `ORDER BY f.id DESC` — o usuário seguido mais recentemente aparece primeiro. Isso espelha o comportamento da aba "seguidores" do Twitter/Instagram.

---

## Armadilhas comuns

| Armadilha | Correção |
|---|---|
| Retornar 422 para usuário desconhecido na verificação de auto-seguimento | Validar existência do usuário *antes* da verificação de auto-seguimento |
| `followee_id` lido do caminho em vez do corpo | `followee_id` está no corpo da requisição; `followeeId` é o parâmetro de caminho para DELETE |
| Usar `createJson()` | O método é `create(array $data, int $status = 200)` |
| Usar `createEmpty()` para 200 com corpo | Só use `createEmpty()` para 204 No Content |
| Esquecer a chamada `JsonRequestBodyParser::parse()` | Deve ser chamado manualmente em todo handler que lê um corpo JSON |
