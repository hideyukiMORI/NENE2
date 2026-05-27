# Como Fazer: Defesa Contra Mass Assignment com DTO Explícito

> **Referência FT**: FT256 (`NENE2-FT/masslog`) — Padrão de defesa contra mass assignment com whitelisting explícito de DTO
> **ATK**: FT256 — teste de ataque com mentalidade de cracker (ATK-01 a ATK-12)

Demonstra como prevenir vulnerabilidades de mass assignment usando um DTO readonly explícito
que faz whitelisting apenas dos campos que os chamadores têm permissão de definir. Campos controlados pelo servidor
(`role`, `is_active`, `created_at`, `id`) são excluídos do DTO e hardcoded no
repositório. Inclui uma avaliação completa de ataque com mentalidade de cracker.

---

## Rotas

| Método | Caminho     | Descrição               |
|--------|----------|---------------------------|
| `POST` | `/users` | Criar um usuário (role=user) |
| `GET`  | `/users` | Listar todos os usuários            |

---

## Schema

```sql
CREATE TABLE IF NOT EXISTS users (
    id        INTEGER PRIMARY KEY AUTOINCREMENT,
    name      TEXT    NOT NULL,
    email     TEXT    NOT NULL UNIQUE,
    role      TEXT    NOT NULL DEFAULT 'user' CHECK (role IN ('user', 'admin')),
    is_active INTEGER NOT NULL DEFAULT 1,
    created_at TEXT   NOT NULL
);
```

`CHECK(role IN ('user', 'admin'))` é uma rede de segurança no nível do BD. A aplicação sempre
escreve `'user'` em `role` na criação, então a constraint nunca é acionada em operação normal
— ela protege contra bugs ou acesso direto ao BD.

---

## O DTO explícito: whitelisting de campos

```php
/**
 * DTO explícito para criação de usuário — apenas name e email são aceitos da entrada do usuário.
 *
 * role e is_active são intencionalmente excluídos: eles devem ser definidos por lógica
 * de negócio do servidor, nunca do corpo da requisição. Esta é a defesa contra mass assignment.
 */
final readonly class CreateUserInput
{
    public function __construct(
        public string $name,
        public string $email,
    ) {}
}
```

O DTO tem exatamente dois campos — `name` e `email`. Não há campo `role`, `is_active`,
`created_at` ou `id`. Um atacante não pode injetar esses campos porque o
construtor simplesmente não os aceita.

**Por que isso é melhor que uma blocklist**:

| Abordagem | Modelo de segurança | Modo de falha |
|---|---|---|
| Allowlist explícita (DTO) | Rejeitar desconhecido por padrão | Seguro — novos campos devem ser explicitamente adicionados |
| Blocklist (`unset($body['role'])`) | Bloquear o que é sabidamente ruim | Inseguro — novos campos sensíveis são esquecidos |
| `array_intersect_key` | Filtrar para chaves conhecidas | Aceitável — mesmo que allowlist se as chaves estiverem completas |

Um DTO explícito falha com segurança: adicionar uma nova coluna sensível ao schema não
a expõe automaticamente — o desenvolvedor deve explicitamente adicioná-la ao DTO.

---

## Controller: extração explícita de campos

```php
private function createUser(ServerRequestInterface $request): ResponseInterface
{
    $body = json_decode((string) $request->getBody(), true);
    if (!is_array($body)) {
        return $this->problems->create($request, 'invalid-body', '...', 400);
    }

    $errors = [];

    if (!isset($body['name']) || !is_string($body['name']) || trim($body['name']) === '') {
        $errors[] = ['field' => 'name', 'code' => 'required', 'message' => 'name is required.'];
    }
    if (!isset($body['email']) || !is_string($body['email']) || !filter_var($body['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = ['field' => 'email', 'code' => 'invalid-email', 'message' => 'email must be a valid email address.'];
    }

    if ($errors !== []) {
        return $this->problems->create($request, 'validation-failed', 'Validation failed.', 422, null, ['errors' => $errors]);
    }

    // Apenas campos permitidos são mapeados — campos extras (role, is_active, etc.) são silenciosamente descartados
    $input = new CreateUserInput(
        name:  trim((string) $body['name']),
        email: strtolower(trim((string) $body['email'])),
    );

    $user = $this->repo->create($input);
    return $this->json->create([...], 201);
}
```

O controller lê `$body['name']` e `$body['email']` explicitamente. Todas as outras chaves em
`$body` são silenciosamente descartadas — elas nunca são lidas ou passadas para lugar algum.

Email é normalizado para minúsculas (`strtolower`) antes de criar o DTO, prevenindo
emails duplicados que diferem apenas em capitalização.

---

## Repositório: campos controlados pelo servidor

```php
public function create(CreateUserInput $input): User
{
    $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
    $id = $this->executor->insert(
        'INSERT INTO users (name, email, role, is_active, created_at) VALUES (?, ?, ?, ?, ?)',
        [$input->name, $input->email, 'user', 1, $now],  // role e is_active são hardcoded
    );

    return new User(
        id:        $id,
        name:      $input->name,
        email:     $input->email,
        role:      'user',    // hardcoded, não de $input
        isActive:  true,      // hardcoded, não de $input
        createdAt: $now,
    );
}
```

`'user'` e `1` são valores literais no INSERT. Não há forma de a entrada do usuário influenciar
`role` ou `is_active`. A assinatura de tipo do DTO `CreateUserInput` reforça isso
no nível de tipo PHP.

---

## ATK — Teste de ataque com mentalidade de cracker (FT256)

### ATK-01 — Escalação de role: injetar `role: "admin"` no corpo da requisição

**Ataque**: Incluir `role` no corpo da requisição para criar um usuário admin.

```json
{"name": "Attacker", "email": "attacker@example.com", "role": "admin"}
```

**Observado**: `role` não é um campo em `CreateUserInput`. O controller lê apenas
`name` e `email` de `$body`. A chave extra é silenciosamente descartada. O usuário criado
tem `role = 'user'`.

**Veredicto**: **BLOCKED** — whitelist de campos do DTO explícito previne escalação de privilégio.

---

### ATK-02 — Manipulação de estado da conta: injetar `is_active: false`

**Ataque**: Criar um usuário com `is_active = false` para criar uma conta desativada ou
testar se o campo é gravável.

```json
{"name": "Bob", "email": "bob@example.com", "is_active": false}
```

**Observado**: `is_active` não está em `CreateUserInput`. O usuário criado tem
`is_active = true` (hardcoded no INSERT).

**Veredicto**: **BLOCKED** — `is_active` nunca é lido da requisição.

---

### ATK-03 — Manipulação de timestamp: injetar `created_at`

**Ataque**: Retrodata o timestamp de criação do usuário.

```json
{"name": "Carol", "email": "carol@example.com", "created_at": "2000-01-01 00:00:00"}
```

**Observado**: `created_at` não está em `CreateUserInput`. O repositório gera
`$now` de `DateTimeImmutable` no momento da escrita.

**Veredicto**: **BLOCKED** — timestamps de auditoria são gerados pelo servidor, não fornecidos pelo cliente.

---

### ATK-04 — Sequestro de ID: injetar `id: 9999`

**Ataque**: Pré-selecionar uma chave primária para sobrescrever um registro existente ou reivindicar um
ID conhecido.

```json
{"name": "Dave", "email": "dave@example.com", "id": 9999}
```

**Observado**: `id` não está em `CreateUserInput`. O INSERT usa `AUTOINCREMENT` — o
`id` é atribuído pelo SQLite, não de qualquer valor fornecido pelo usuário.

**Veredicto**: **BLOCKED** — atribuição de chave primária é sempre do servidor.

---

### ATK-05 — SQL injection via name ou email

**Ataque**: Incorporar metacaracteres SQL.

```json
{"name": "'; DROP TABLE users; --", "email": "sql@example.com"}
```

**Observado**: Ambos os campos são vinculados como placeholders parametrizados `?` no INSERT.
O payload de injeção é armazenado como texto literal.

**Veredicto**: **BLOCKED** — queries parametrizadas previnem SQL injection.

---

### ATK-06 — Bypass de capitalização de email: enviar email em maiúsculas

**Ataque**: Registrar `ADMIN@EXAMPLE.COM` como usuário diferente de `admin@example.com`.

```json
{"name": "Eve", "email": "ADMIN@EXAMPLE.COM"}
```

**Observado**: O controller aplica `strtolower()` antes de passar para o DTO. Tanto
`ADMIN@EXAMPLE.COM` quanto `admin@example.com` normalizam para `admin@example.com`. A
constraint `UNIQUE` previne um segundo registro.

**Veredicto**: **BLOCKED** — normalização de capitalização + constraint UNIQUE previnem contas duplicadas.

---

### ATK-07 — Email duplicado: registrar o mesmo endereço duas vezes

**Ataque**: Registrar o mesmo endereço de email para acionar um erro ou criar contas duplicadas.

```json
{"name": "Frank", "email": "frank@example.com"}
{"name": "FrankDuplicate", "email": "frank@example.com"}
```

**Observado**: A primeira requisição tem sucesso com `201`. A segunda aciona uma
violação de constraint `UNIQUE` do SQLite. A implementação atual não captura esta
exceção — ela se propaga como um erro não tratado.

**Veredicto**: **EXPOSED** — capturar a violação de constraint única e retornar uma
resposta `409 Conflict` ou `422 Unprocessable Entity` estruturada. Vazar erros brutos do BD é um
problema de segurança e UX.

---

### ATK-08 — Payload XSS em name ou email

**Ataque**: Armazenar uma tag de script.

```json
{"name": "<script>alert(1)</script>", "email": "xss@example.com"}
```

**Observado**: Conteúdo é armazenado como está e retornado verbatim em JSON. A API não
HTML-encoda a saída.

**Veredicto**: **ACCEPTED BY DESIGN** — APIs JSON retornam conteúdo bruto. A
camada de renderização deve sanitizar antes de inserir em HTML.

---

### ATK-09 — Campos obrigatórios ausentes

**Ataque**: Omitir `name` ou `email`.

```json
{"email": "missing@example.com"}
{"name": "NoEmail"}
{}
```

**Observado**: Cada um retorna `422 Unprocessable Entity` com um array `errors` estruturado
identificando o campo ausente pelo nome.

**Veredicto**: **BLOCKED** — verificações de presença explícitas para cada campo obrigatório.

---

### ATK-10 — Confusão de tipo: enviar name como inteiro

**Ataque**: Enviar `name` como número JSON.

```json
{"name": 12345, "email": "typed@example.com"}
```

**Observado**: `is_string($body['name'])` retorna `false` para valores inteiros. A requisição
retorna `422` com `name is required`.

**Veredicto**: **BLOCKED** — `is_string()` rejeita tipos não-string.

---

### ATK-11 — Name ou email muito longo

**Ataque**: Enviar um name ou email com 10.000+ caracteres.

```json
{"name": "aaaa...aaaa (10000 chars)", "email": "x@example.com"}
```

**Observado**: A requisição tem sucesso com `201`. Nenhuma validação de comprimento é aplicada a
`name` ou `email`. SQLite armazena TEXT sem limite de comprimento inerente.

**Veredicto**: **EXPOSED** — adicionar validação de comprimento (ex.: `mb_strlen($name) > 255 → 422`).
Confiar no middleware de tamanho de requisição como limite externo.

---

### ATK-12 — Múltiplos valores de role: injetar como array

**Ataque**: Enviar `role` como array em vez de string.

```json
{"name": "Grace", "email": "grace@example.com", "role": ["admin", "superuser"]}
```

**Observado**: `role` não é lido de `$body` de forma alguma. Se é string, array,
ou null não tem efeito no usuário criado.

**Veredicto**: **BLOCKED** — o DTO exclui `role` completamente; seu tipo é irrelevante.

---

## Resumo ATK

| # | Vetor de ataque | Veredicto |
|---|---------------|---------|
| ATK-01 | Escalação de role via `role: "admin"` | BLOCKED |
| ATK-02 | Manipulação de estado da conta via `is_active: false` | BLOCKED |
| ATK-03 | Retrodatação de timestamp via `created_at` | BLOCKED |
| ATK-04 | Sequestro de ID via `id: 9999` | BLOCKED |
| ATK-05 | SQL injection via name/email | BLOCKED |
| ATK-06 | Bypass de capitalização de email (`ADMIN@EXAMPLE.COM`) | BLOCKED |
| ATK-07 | Email duplicado (sem tratamento gracioso de erro) | EXPOSED |
| ATK-08 | Payload XSS em name | ACCEPTED BY DESIGN |
| ATK-09 | Campos obrigatórios ausentes | BLOCKED |
| ATK-10 | Confusão de tipo (name como inteiro) | BLOCKED |
| ATK-11 | Name ou email muito longo (sem limite de comprimento) | EXPOSED |
| ATK-12 | Role como array | BLOCKED |

**Vulnerabilidades reais a corrigir antes de produção**:
1. **ATK-07** — Capturar violação de constraint UNIQUE; retornar `409 Conflict` com mensagem para o usuário
2. **ATK-11** — Adicionar validação de comprimento `mb_strlen` para `name` e `email`

---

## Howtos relacionados

- [`mass-assignment.md`](mass-assignment.md) — visão geral de padrões de defesa contra mass assignment
- [`enforce-resource-ownership.md`](enforce-resource-ownership.md) — queries com escopo de propriedade para prevenir IDOR
- [`rbac.md`](rbac.md) — controle de acesso baseado em role com claims JWT
- [`user-profile-management.md`](user-profile-management.md) — atualização de perfil com whitelisting de campos
