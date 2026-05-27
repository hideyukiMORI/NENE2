# Como Fazer: Isolamento Multi-Tenant

Este guia cobre a construção de uma API multi-tenant com NENE2 onde os dados de cada tenant são
estritamente isolados. Pular qualquer etapa cria um IDOR (Insecure Direct Object Reference) silencioso
que expõe os dados de todos os tenants.

---

## A regra central: filtro `tenant_id` em toda query

Omitir o filtro de tenant de uma única query silenciosamente retorna dados de todos os tenants:

```sql
-- ❌ Sem filtro de tenant — retorna registros de todos os tenants
SELECT id, title, body FROM notes WHERE id = ?

-- ✅ Sempre inclua o filtro de tenant
SELECT id, title, body FROM notes WHERE id = ? AND tenant_id = ?
```

Nomeie os métodos do repositório com o sufixo `ForTenant` para tornar o contrato visível:

```php
public function findByIdForTenant(int $id, int $tenantId): ?Note
{
    /** @var array{id: int, tenant_id: int, title: string, body: string, created_at: string}|null $row */
    $row = $this->executor->fetchOne(
        'SELECT id, tenant_id, title, body, created_at FROM notes WHERE id = ? AND tenant_id = ?',
        [$id, $tenantId],
    );

    return $row !== null ? $this->hydrate($row) : null;
}

/** @return list<Note> */
public function findAllForTenant(int $tenantId): array
{
    /** @var list<array{id: int, tenant_id: int, title: string, body: string, created_at: string}> $rows */
    $rows = $this->executor->fetchAll(
        'SELECT id, tenant_id, title, body, created_at FROM notes WHERE tenant_id = ? ORDER BY id DESC',
        [$tenantId],
    );

    return array_map($this->hydrate(...), $rows);
}

public function delete(int $id, int $tenantId): bool
{
    $note = $this->findByIdForTenant($id, $tenantId);

    if ($note === null) {
        return false;
    }

    $this->executor->execute('DELETE FROM notes WHERE id = ? AND tenant_id = ?', [$id, $tenantId]);

    return true;
}
```

O sufixo `ForTenant` força os chamadores a fornecerem o ID do tenant. Também torna
a revisão de código direta: qualquer método sem esse sufixo é candidato à
revisão de IDOR.

---

## Incorpore `tenant_id` no JWT

Resolva a associação ao tenant uma vez no login e incorpore-a no token. Isso evita
uma ida ao banco de dados a cada requisição e mantém o contexto do tenant à prova de adulteração
(a assinatura JWT o cobre).

```php
$now   = time();
$token = $this->issuer->issue([
    'sub'       => $user->id,
    'tenant_id' => $user->tenantId,  // deve ser int
    'email'     => $user->email,
    'iat'       => $now,
    'exp'       => $now + self::TOKEN_TTL_SECONDS,
]);
```

Extraia e valide a claim nos handlers. Use `is_int()` — `is_string()` sozinho
não é seguro; MySQL/PostgreSQL pode rejeitar comparações string-para-int silenciosamente:

```php
private function tenantId(ServerRequestInterface $request): ?int
{
    /** @var array<string, mixed>|null $claims */
    $claims = $request->getAttribute('nene2.auth.claims');

    if (!is_array($claims) || !isset($claims['tenant_id']) || !is_int($claims['tenant_id'])) {
        return null;  // acionar 401
    }

    return $claims['tenant_id'];
}
```

`BearerTokenMiddleware` armazena as claims verificadas em `nene2.auth.claims`. O
middleware rejeita tokens expirados, assinaturas adulteradas e ataques `alg: none`
antes que o handler seja executado.

---

## Retorne 404 para acesso entre tenants (não 403)

Retornar 403 Forbidden revela que o recurso existe mas o chamador não tem
permissão — informação que cruza fronteiras de tenant. Sempre retorne 404:

```php
// ❌ 403 vaza informação entre tenants
if ($note->tenantId !== $tenantId) {
    return $this->problems->create($request, 'forbidden', 'Forbidden', 403);
}

// ✅ Filtro de tenant no SQL — registros entre tenants simplesmente retornam null
$note = $this->notes->findByIdForTenant($id, $tenantId);

if ($note === null) {
    return $this->problems->create(
        $request,
        'not-found',
        'Note Not Found',
        404,
        "Note {$id} does not exist.",
    );
}
```

Quando `WHERE id = ? AND tenant_id = ?` não corresponde a nada, o repositório retorna
`null` e o handler retorna 404 — sem verificação explícita entre tenants necessária.

---

## Exclua `tenant_id` das respostas

`tenant_id` é um identificador de infraestrutura. Expô-lo em respostas permite
que atacantes enumerem todos os IDs de tenant e sirva como ponto de partida para ataques direcionados:

```php
// ❌ tenant_id vaza na resposta
return $this->json->create([
    'id'        => $note->id,
    'tenant_id' => $note->tenantId,  // remova isto
    'title'     => $note->title,
    'body'      => $note->body,
]);

// ✅ Apenas campos que o cliente precisa
return $this->json->create([
    'id'         => $note->id,
    'title'      => $note->title,
    'body'       => $note->body,
    'created_at' => $note->createdAt,
]);
```

---

## PHPStan: `assertIsList()` para tipos de retorno `list<>`

`json_decode()` retorna `mixed`. Após `assertIsArray()`, o PHPStan estreita o
tipo para `array<mixed>`, mas isso não satisfaz `list<array<string, mixed>>`.
Adicione `assertIsList()` para estreitar mais:

```php
/** @return list<array<string, mixed>> */
private function jsonList(ResponseInterface $response): array
{
    $data = json_decode((string) $response->getBody(), true);

    $this->assertIsArray($data);
    $this->assertIsList($data);  // estreita array<mixed> → list<mixed>

    return $data;
}
```

`assertIsList()` do PHPUnit também valida em tempo de execução que o array tem
chaves inteiras sequenciais começando em 0 — uma verificação de correção útil para respostas
de lista da API.

---

## Design do schema

```sql
CREATE TABLE IF NOT EXISTS tenants (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT NOT NULL,
    created_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS users (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id     INTEGER NOT NULL REFERENCES tenants(id),
    email         TEXT NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    created_at    TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS notes (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id  INTEGER NOT NULL REFERENCES tenants(id),
    title      TEXT NOT NULL,
    body       TEXT NOT NULL,
    created_at TEXT NOT NULL
);
```

Toda tabela com escopo de tenant carrega uma chave estrangeira `tenant_id NOT NULL`. Isso é
aplicado na camada do banco de dados além dos filtros da camada de aplicação.

---

## Checklist de revisão de código

Ao revisar código multi-tenant, verifique:

1. Todo `SELECT`, `UPDATE` e `DELETE` inclui `WHERE tenant_id = ?`
2. `tenant_id` é obtido da claim do JWT, não de um parâmetro de URL ou corpo da requisição
3. Acesso entre tenants retorna 404, não 403
4. As respostas não incluem `tenant_id`
5. Nenhum `JOIN` cruza fronteiras de tenant sem um filtro de tenant
6. A verificação de tipo `is_int($claims['tenant_id'])` está presente

---

## Testando o isolamento

Testes unitários são insuficientes — escreva testes de integração entre tenants que realmente
tentam acessar os dados de outro tenant:

```php
public function testCrossTenantGetReturns404NotForbidden(): void
{
    $aliceToken = $this->loginAs('alice@acme.com');
    $bobToken   = $this->loginAs('bob@beta.com');

    $res    = $this->post('/notes', ['title' => 'Secret', 'body' => 'Acme secret'], $aliceToken);
    $noteId = $this->json($res)['id'];

    // Bob tenta acessar a nota de Alice
    $crossRes = $this->get('/notes/' . $noteId, $bobToken);

    // Deve ser 404 — NÃO 403
    $this->assertSame(404, $crossRes->getStatusCode());
}

public function testListNotesShowsOnlyCurrentTenantNotes(): void
{
    $aliceToken = $this->loginAs('alice@acme.com');
    $bobToken   = $this->loginAs('bob@beta.com');

    $this->post('/notes', ['title' => 'Alice Note', 'body' => 'Acme'], $aliceToken);
    $this->post('/notes', ['title' => 'Bob Note',   'body' => 'Beta'], $bobToken);

    $aliceNotes = $this->jsonList($this->get('/notes', $aliceToken));
    $bobNotes   = $this->jsonList($this->get('/notes', $bobToken));

    $this->assertCount(1, $aliceNotes);
    $this->assertSame('Alice Note', $aliceNotes[0]['title']);

    $this->assertCount(1, $bobNotes);
    $this->assertSame('Bob Note', $bobNotes[0]['title']);
}
```

Testes de caminho feliz apenas verificam que os dados do seu próprio tenant funcionam. Testes
entre tenants são a única forma de capturar falhas de isolamento.

---

## Veja também

- `docs/howto/jwt-authentication.md` — emissão e verificação de JWT
- `docs/howto/rbac.md` — controle de acesso baseado em role em cima do JWT
- `docs/howto/enforce-resource-ownership.md` — verificações de propriedade por usuário
- `docs/field-trials/2026-05-field-trial-112.md` — field trial de isolamento multi-tenant
