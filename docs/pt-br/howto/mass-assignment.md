# Defesa Contra Mass Assignment

Mass assignment é uma vulnerabilidade onde um atacante adiciona campos extras ao corpo da requisição — como `role=admin` ou `is_active=false` — e o servidor os persiste sem intenção.

O NENE2 não possui um método mágico `create($body)` que tornaria isso fácil de acionar acidentalmente. Mesmo assim, o padrão de whitelist por DTO é a defesa correta e explícita.

## A Vulnerabilidade

```php
// ❌ Perigoso: $body passado diretamente para INSERT
$body = json_decode((string) $request->getBody(), true);

$this->executor->insert(
    'INSERT INTO users (name, email, role, is_active) VALUES (?, ?, ?, ?)',
    [$body['name'], $body['email'], $body['role'] ?? 'user', $body['is_active'] ?? 1],
);
```

Um atacante envia:

```json
{
  "name": "Attacker",
  "email": "attacker@example.com",
  "role": "admin"
}
```

Como `$body['role']` é lido da requisição, o atacante recebe `role=admin` no banco de dados.

## A Defesa: Whitelist Explícita por DTO

Defina um DTO que contenha apenas os campos que o usuário tem permissão de fornecer:

```php
/**
 * Apenas name e email são aceitos da entrada do usuário.
 * role e is_active são definidos por lógica do lado do servidor, nunca da requisição.
 */
final readonly class CreateUserInput
{
    public function __construct(
        public string $name,
        public string $email,
    ) {}
}
```

No controller, mapeie apenas os campos permitidos para o DTO:

```php
// ✅ Campos extras (role, is_active, id, created_at) nunca são lidos de $body
$input = new CreateUserInput(
    name:  trim((string) $body['name']),
    email: strtolower(trim((string) $body['email'])),
);

$user = $this->repo->create($input);
```

No repositório, use as propriedades do DTO diretamente:

```php
public function create(CreateUserInput $input): User
{
    $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
    $id = $this->executor->insert(
        'INSERT INTO users (name, email, role, is_active, created_at) VALUES (?, ?, ?, ?, ?)',
        [$input->name, $input->email, 'user', 1, $now], // role e is_active são hardcoded
    );
    // ...
}
```

Mesmo que o atacante envie `role=admin`, `$input` só tem `name` e `email` — o campo extra nunca chega ao INSERT.

## Cenários de Ataque Cobertos

| Campo | Intenção do ataque | Defesa |
|-------|-------------------|--------|
| `role=admin` | Escalação de privilégio | `role` não está em `CreateUserInput`; sempre definido como `'user'` no repositório |
| `is_active=false` | Criar conta desativada ou bloquear usuário | `is_active` não está no DTO; sempre definido como `1` |
| `id=9999` | Sobrescrever chave primária | `id` não está no DTO; atribuído automaticamente pelo SQLite |
| `created_at=2000-01-01` | Forjar timestamp de auditoria | `created_at` não está no DTO; sempre definido como hora atual |

## Controle de Campos na Resposta

A defesa se estende à resposta: nunca retorne linhas do banco de dados diretamente. Mapeie explicitamente o que incluir:

```php
return $this->json->create([
    'id'         => $user->id,
    'name'       => $user->name,
    'email'      => $user->email,
    'role'       => $user->role,
    'is_active'  => $user->isActive,
    'created_at' => $user->createdAt,
    // password_hash intencionalmente excluído
    // deleted_at intencionalmente excluído
], 201);
```

Teste a ausência de campos sensíveis:

```php
$this->assertArrayNotHasKey('password_hash', $data);
$this->assertArrayNotHasKey('deleted_at', $data);
```

## Serviços Internos Confiáveis

Quando um serviço interno precisa criar um usuário admin (por ex., um serviço de provisionamento), use um DTO separado:

```php
final readonly class AdminCreateUserInput
{
    public function __construct(
        public string $name,
        public string $email,
        public string $role,   // permitido apenas para chamadores internos
        public bool $isActive,
    ) {}
}
```

Chame este DTO somente de caminhos de código que já verificaram a identidade do chamador (por ex., chave de API de máquina, autenticação de serviço interno). Nunca exponha um endpoint HTTP público que aceite `AdminCreateUserInput` diretamente.

## `create()` vs `createList()` para Respostas

Ao retornar uma lista, use `createList()` em vez de `create()`:

```php
// ✅ Array JSON de nível superior
return $this->json->createList(array_map(fn (User $u) => [...], $users));

// ✅ Objeto JSON de nível superior
return $this->json->create(['id' => $user->id, ...], 201);
```

`create()` espera `array<string, mixed>` (um objeto). Passar a saída de `array_map()` diretamente para `create()` causa um erro de tipo PHPStan nível 8 porque `array_map` retorna `list<T>`.

## Checklist de Revisão de Código

- [ ] Campos do corpo da requisição são mapeados para um DTO antes de serem passados ao repositório
- [ ] O DTO contém apenas campos que o usuário tem permissão de fornecer
- [ ] Campos controlados pelo servidor (`role`, `is_active`, timestamps, chaves primárias) são definidos no repositório, não lidos de `$body`
- [ ] A resposta lista explicitamente os campos retornados; sem `SELECT *` curinga ou serialização direta de linha para JSON
- [ ] Testes verificam que campos extras da requisição são ignorados e não afetam o valor persistido
