# Como Fazer: Hashing de Senha

Armazenando e verificando senhas com segurança usando `password_hash()` / `password_verify()` nativo do PHP com NENE2.

---

## Início rápido

```php
// Registro — hash antes de armazenar
$hash = password_hash($password, PASSWORD_ARGON2ID);
$user = $this->repo->create($email, $hash);

// Login — verificação em tempo constante
if (!password_verify($inputPassword, $user->passwordHash)) {
    // retornar 401
}
```

---

## Algoritmo: sempre use `PASSWORD_ARGON2ID`

`PASSWORD_DEFAULT` ainda é `bcrypt` no PHP 8.4. Argon2id é memory-hard e resiste a ataques por GPU/ASIC.

```php
// ❌ PASSWORD_DEFAULT = bcrypt — mais vulnerável a força bruta por GPU
$hash = password_hash($password, PASSWORD_DEFAULT);

// ✅ Argon2id — memory-hard, recomendado para novos projetos
$hash = password_hash($password, PASSWORD_ARGON2ID);
```

Argon2id requer PHP 7.3+. O NENE2 requer PHP 8.4, então está sempre disponível.

---

## Detectando violações de UNIQUE: `DatabaseConstraintException`

O `PdoDatabaseQueryExecutor` do NENE2 encapsula todas as violações de restrição (UNIQUE, FK, NOT NULL) em `DatabaseConstraintException` antes de relançar. Capturar `\PDOException` diretamente **não** funciona.

```php
use Nene2\Database\DatabaseConstraintException;

// ❌ Nunca chega aqui — PDOException já está encapsulada
catch (\PDOException $e) {
    if (str_contains($e->getMessage(), 'UNIQUE constraint failed')) { ... }
}

// ✅ Capturar o wrapper do NENE2
catch (DatabaseConstraintException) {
    throw new DuplicateEmailException($email);
}
```

`DatabaseConstraintException` faz parte da API pública estável (ADR 0009).

Padrão completo de repositório:

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

## Prevenção de enumeração de usuário (ataque de timing)

Se você retornar 401 imediatamente quando o e-mail não é encontrado, uma diferença de timing revela se o e-mail existe — respostas de não encontrado retornam instantaneamente, enquanto respostas de senha errada levam o tempo completo de computação do Argon2id.

```php
// ❌ Vazamento de timing — não encontrado é mensuravelmente mais rápido
if ($user === null) {
    return $this->problems->create($request, 'invalid-credentials', ...);
}
if (!password_verify($password, $user->passwordHash)) {
    return $this->problems->create($request, 'invalid-credentials', ...);
}

// ✅ Sempre executar password_verify — tempo constante independente de o usuário existir
$dummyHash   = '$argon2id$v=19$m=65536,t=4,p=1$dummysaltdummysaltdummysalt$dummyhashvaluedummyhashvaluedummyh';
$hashToCheck = $user !== null ? $user->passwordHash : $dummyHash;

if (!password_verify($password, $hashToCheck) || $user === null) {
    return $this->problems->create($request, 'invalid-credentials', 'Invalid Credentials', 401,
        'The email or password is incorrect.');
}
```

O hash fictício **deve** ser uma string válida no formato Argon2id começando com `$argon2id$`. Se não for, `password_verify()` retorna `false` imediatamente, recriando o vazamento de timing.

---

## `password_verify()` é agnóstico de algoritmo

`password_verify()` lê o prefixo do hash para determinar o algoritmo. Você não precisa alterar o código de verificação ao migrar de bcrypt para Argon2id.

```php
// Funciona tanto em hashes bcrypt quanto Argon2id
$result = password_verify($plaintext, $storedHash); // sempre correto
```

Use `password_needs_rehash()` no login bem-sucedido para fazer upgrade de hashes legados de forma transparente:

```php
if (password_verify($password, $user->passwordHash)) {
    if (password_needs_rehash($user->passwordHash, PASSWORD_ARGON2ID)) {
        $newHash = password_hash($password, PASSWORD_ARGON2ID);
        $this->repo->updatePasswordHash($user->id, $newHash);
    }
    // prosseguir com usuário autenticado
}
```

---

## Nunca incluir `password_hash` na resposta

Helpers como `toArray()` podem incluir todas as colunas. Liste explicitamente apenas os campos que pretende retornar.

```php
// ❌ Pode vazar password_hash se $user tiver um método toArray()
return $this->json->create($user->toArray(), 201);

// ✅ Lista explícita de campos — password_hash nunca está presente
return $this->json->create([
    'id'         => $user->id,
    'email'      => $user->email,
    'created_at' => $user->createdAt,
], 201);
```

---

## Conflito de nome `RouteRegistrar::register()`

O contrato `RouteRegistrar` do NENE2 requer um método público `register(Router $router)`. **Não** nomeie um handler de rota como `register()` — o PHP rejeitará o nome de método duplicado.

```php
// ❌ Erro fatal: Cannot redeclare RouteRegistrar::register()
$router->post('/register', $this->register(...));
private function register(...) { ... }

// ✅ Use um nome de handler distinto
$router->post('/register', $this->handleRegister(...));
private function handleRegister(...) { ... }
```

---

## Checklist de revisão de código

- [ ] `password_hash()` com `PASSWORD_ARGON2ID` é usado (não MD5, SHA-1, bcrypt, ou `PASSWORD_DEFAULT`)
- [ ] `password_verify()` é usado para comparação (não `===`, `hash_equals()`, ou comparação customizada)
- [ ] `password_verify()` é executado mesmo quando o usuário não é encontrado (padrão de hash fictício)
- [ ] `DatabaseConstraintException` é capturado para detecção de e-mail/nome de usuário duplicado
- [ ] Campos `password_hash` / `password` são excluídos de todas as respostas da API
- [ ] Login retorna 401 (não 404) para e-mail desconhecido — nunca revelar se o e-mail existe
- [ ] Senha em texto puro não é escrita nos logs
