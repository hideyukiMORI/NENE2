# Como Impor Ownership de Recurso (Prevenção de IDOR)

Insecure Direct Object Reference (IDOR) é a vulnerabilidade de API #1 (OWASP API Security Top 10).
Ocorre quando um usuário pode acessar ou modificar os recursos de outro usuário adivinando ou enumerando IDs.

O NENE2 não fornece imposição automática de ownership — cada repositório e handler deve implementá-la
explicitamente. Este guia mostra os padrões recomendados.

---

## 1. A regra central: 404, não 403

Quando um usuário acessa um recurso que pertence a outro usuário, retorne `404 Not Found` — **não** `403 Forbidden`.

- **403** diz ao atacante: "este recurso existe, mas você não pode acessá-lo." — vazamento de informação
- **404** diz ao atacante: "este recurso não existe." — sem confirmação

```php
// ERRADO — vaza existência
if ($note->ownerId !== $authUserId) {
    return $this->problems->create($request, 'forbidden', 'Forbidden', 403, '');
}

// CORRETO — não revela nada
if ($note === null) {
    return $this->problems->create($request, 'not-found', 'Not Found', 404, '');
}
```

A forma prática de conseguir isso: fazer o repositório **incapaz de retornar um recurso que não
pertence ao chamador** — veja a próxima seção.

---

## 2. Impor ownership no nível SQL

O padrão mais seguro é incluir `owner_id` em toda consulta. O método literalmente não pode retornar
dados de outro usuário, independentemente de como o chamador usa o resultado.

```php
public function findByIdAndOwner(int $id, string $ownerId): ?Resource
{
    $row = $this->db->fetchOne(
        'SELECT * FROM resources WHERE id = ? AND owner_id = ?',
        [$id, $ownerId],
    );
    return $row !== null ? $this->hydrate($row) : null;
}

public function update(int $id, string $ownerId, string $newValue): bool
{
    $updated = $this->db->execute(
        'UPDATE resources SET value = ? WHERE id = ? AND owner_id = ?',
        [$newValue, $id, $ownerId],
    );
    return $updated > 0;
}

public function delete(int $id, string $ownerId): bool
{
    return $this->db->execute(
        'DELETE FROM resources WHERE id = ? AND owner_id = ?',
        [$id, $ownerId],
    ) > 0;
}
```

**Por que o nível SQL é melhor que o nível de aplicação:**
- Uma verificação no nível de app pode ser contornada se um desenvolvedor se esquecer de chamá-la
- Uma verificação no nível SQL não pode ser pulada — a linha do proprietário errado simplesmente não será retornada
- Retornar `null` para "não encontrado" e "proprietário errado" impede que o chamador acidentalmente ramifique em um caso que não deveria conhecer

---

## 3. Padrão de handler

```php
private function show(ServerRequestInterface $request): ResponseInterface
{
    $authUserId = $this->resolveAuthUser($request);
    if ($authUserId === null) {
        return $this->unauthorized($request);
    }

    $id       = $this->resolveId($request);
    $resource = $this->repo->findByIdAndOwner($id, $authUserId);

    if ($resource === null) {
        // 404 cobre tanto "não encontrado" quanto "pertence a outro usuário"
        return $this->problems->create($request, 'not-found', 'Not Found', 404, '');
    }

    return $this->json->create($resource->toArray());
}
```

---

## 4. Listagem: filtrar por owner na consulta

```php
public function listByOwner(string $ownerId): array
{
    return $this->db->fetchAll(
        'SELECT * FROM resources WHERE owner_id = ? ORDER BY id DESC',
        [$ownerId],
    );
}
```

Nunca busque todas as linhas e filtre em PHP. Isso vaza dados de outros usuários se a lógica de filtragem estiver errada
e também é um problema de N+1.

---

## 5. Testar acesso cross-owner explicitamente

Adicione testes dedicados que verificam que IDOR está prevenido:

```php
public function testCannotReadAnotherUsersResource(): void
{
    $bobId = $this->decode($this->create('bob', 'Bob content'))['id'];

    // Alice tenta ler o recurso de Bob — deve obter 404
    $res = $this->request('GET', '/resources/' . $bobId, authUser: 'alice');
    self::assertSame(404, $res->getStatusCode());
    // Especificamente não 403 — o que vazaria a existência do recurso
    self::assertNotSame(403, $res->getStatusCode());
}

public function testListDoesNotLeakCrossTenantData(): void
{
    $this->create('alice', 'Alice content');
    $this->create('bob', 'Bob content');

    $aliceList = $this->decode($this->request('GET', '/resources', authUser: 'alice'));
    $titles    = array_column($aliceList['items'], 'content');

    self::assertNotContains('Bob content', $titles);
}
```

---

## Observações

- **Por que 404 parece errado**: Retornar 404 para um recurso que você pode ver na URL parece "desonesto".
  É — mas o OWASP recomenda explicitamente isso para prevenir ataques de enumeração de ID. O tradeoff
  é uma prática de segurança aceita.
- **Bypass de admin**: Se você tem rotas de admin que podem ver qualquer recurso, mantenha-as em um
  prefixo de caminho separado com uma verificação de ownership separada (ou sem verificação). Não complique os métodos de ownership
  com flags "is admin".
- **Schema do banco de dados**: sempre adicione um índice em `owner_id` (e em `(owner_id, id)` para
  lookups compostos). Sem índice, toda consulta por usuário é um full table scan.
