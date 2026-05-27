# Como Fazer: Autenticação com Senha usando Argon2id

> **Referência FT**: FT331 (`NENE2-FT/pwdlog`) — Registro de usuário e login com hash de senha Argon2id, senha/hash nunca exposta nas respostas, prevenção de enumeração de usuário (mesmo 401 para senha errada e e-mail desconhecido), rehash para migração de algoritmo, 14 testes / 40 assertivas PASS.

Este guia mostra como construir autenticação segura baseada em senha: armazenar senhas com segurança usando Argon2id, nunca vazar credenciais nas respostas e prevenir que atacantes enumerem endereços de e-mail registrados.

## Schema

```sql
CREATE TABLE users (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    email         TEXT    NOT NULL UNIQUE,
    password_hash TEXT    NOT NULL,
    created_at    TEXT    NOT NULL
);
```

`password_hash` armazena a string completa de saída do Argon2id (ex.: `$argon2id$v=19$m=65536,...`). **Nunca armazene texto puro ou MD5/SHA-1.**

## Endpoints

| Método | Caminho | Descrição |
|--------|---------|-----------|
| `POST` | `/register` | Registrar um novo usuário |
| `POST` | `/login` | Autenticar e retornar dados do usuário |

## Registrar

```php
POST /register
{"email": "alice@example.com", "password": "correct-horse"}

→ 201
{"id": 1, "email": "alice@example.com", "created_at": "2026-05-27T09:00:00Z"}
```

**`password` e `password_hash` NUNCA são retornados** na resposta — nem mascarados ou truncados.

### Validação

```php
POST /register  {"email": "alice@example.com", "password": "curta"}
→ 422  // senha muito curta (mínimo 8 caracteres)

POST /register  {"email": "nao-e-email", "password": "correct-horse"}
→ 422  // formato de e-mail inválido

POST /register  {"email": "alice@example.com"}
→ 400  // campo password ausente

POST /register  {"email": "alice@example.com", "password": "battery-staple"}
// (após alice já estar registrada)
→ 409  {"type": ".../email-taken", "detail": "Email already registered"}
```

## Login

```php
POST /login
{"email": "alice@example.com", "password": "correct-horse"}

→ 200
{"id": 1, "email": "alice@example.com", "created_at": "..."}
// password_hash não retornado
```

### Prevenção de Enumeração de Usuário

```php
// Senha errada para e-mail conhecido
POST /login  {"email": "alice@example.com", "password": "errada"}
→ 401  {"type": ".../invalid-credentials", "detail": "Invalid email or password"}

// E-mail desconhecido
POST /login  {"email": "fantasma@example.com", "password": "qualquer"}
→ 401  {"type": ".../invalid-credentials", "detail": "Invalid email or password"}
```

**Ambos os casos retornam o mesmo 401 com a mesma mensagem `detail`.** Retornar 404 para e-mail desconhecido permitiria que atacantes sondassem o banco de usuários.

```php
// Teste: mesma string detail
$this->assertSame($wrongPasswordBody['detail'], $unknownEmailBody['detail']);
```

## Implementação

### Armazenamento de Senha — Argon2id

```php
// Registro
$hash = password_hash($plaintext, PASSWORD_ARGON2ID);
// Armazena: $argon2id$v=19$m=65536,t=4,p=1$...

// Nunca armazenar:
// md5($plaintext)          — reversível em segundos
// sha1($plaintext)         — ataque por tabela rainbow
// $plaintext               — armazenamento em texto puro
```

`password_hash(PASSWORD_ARGON2ID)` do PHP automaticamente:
- Gera um salt aleatório por hash
- Armazena algoritmo, parâmetros, salt e digest em uma string
- Resiste a força bruta por GPU (memory-hard)

### Verificação — Tempo Constante

```php
$row = $this->repo->findByEmail($email);

if ($row === null || !password_verify($plaintext, $row['password_hash'])) {
    // Mesma resposta seja o e-mail desconhecido ou a senha errada
    return $this->problems->create('invalid-credentials', 'Invalid email or password', 401);
}
```

`password_verify()` é de tempo constante e funciona entre famílias de algoritmos (bcrypt, Argon2id, etc.).

### Rehash para Migração de Algoritmo

Ao fazer upgrade de bcrypt para Argon2id, refaça o hash no login bem-sucedido:

```php
if (password_needs_rehash($row['password_hash'], PASSWORD_ARGON2ID)) {
    $newHash = password_hash($plaintext, PASSWORD_ARGON2ID);
    $this->repo->updateHash($row['id'], $newHash);
}
```

Os usuários são migrados silenciosamente para o algoritmo mais forte na próxima vez que fazem login — sem redefinição forçada de senha.

### Nunca Retornar Credenciais

```php
private function toPublic(array $user): array
{
    // Remover explicitamente campos sensíveis
    unset($user['password_hash']);
    return $user;
}
```

Aplique `toPublic()` a toda resposta: registro 201, login 200 e qualquer endpoint de perfil.

---

## O Que NÃO Fazer

| Anti-padrão | Risco |
|---|---|
| Retornar 404 para e-mail desconhecido no login | Enumeração de usuário: atacante descobre quais e-mails estão registrados |
| Retornar mensagem `detail` diferente para senha errada vs e-mail desconhecido | Vaza qual condição falhou |
| Armazenar senha como MD5 ou SHA-1 | Ataque por tabela rainbow quebra todas as senhas em horas |
| Armazenar senha como bcrypt sem caminho de migração | Não é possível fazer upgrade para algoritmo mais forte sem redefinição forçada |
| Retornar `password_hash` em qualquer resposta | Hash pode ser usado para força bruta offline |
| Ignorar `password_needs_rehash()` no login | Hashes fracos legados persistem para sempre mesmo após upgrade de algoritmo |
| Usar `===` para comparar hashes | Ataque de timing revela bytes do hash; sempre usar `password_verify()` |
