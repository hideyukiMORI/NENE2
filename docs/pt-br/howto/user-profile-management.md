# Gerenciamento de Perfil de Usuário

Armazene e atualize dados de perfil voltados ao usuário: nome de exibição, bio e URL de avatar. A criação do perfil é separada da criação do usuário — os usuários existem primeiro, depois um perfil é criado uma vez e atualizado no lugar.

## Visão Geral

Uma API de gerenciamento de perfil envolve:
- **Criar usuário** — registro de usuário baseado em email (um perfil por usuário)
- **Criar perfil** — configuração inicial do perfil (resistente à idempotência: 409 se já existe)
- **Obter perfil** — recuperar dados atuais do perfil
- **Atualizar perfil** — substituir campos do perfil (propriedade aplicada)

## Schema do Banco de Dados

```sql
CREATE TABLE users (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    email      TEXT    NOT NULL UNIQUE,
    created_at TEXT    NOT NULL
);

CREATE TABLE profiles (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id      INTEGER NOT NULL UNIQUE,
    display_name TEXT    NOT NULL DEFAULT '',
    bio          TEXT    NOT NULL DEFAULT '',
    avatar_url   TEXT    NOT NULL DEFAULT '',
    updated_at   TEXT    NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id)
);
```

`UNIQUE` em `user_id` aplica um perfil por usuário no nível do banco.

## Tratando Email Duplicado

Capture `DatabaseConstraintException` para retornar 409 em vez de vazar um 500:

```php
try {
    $userId = $this->repo->createUser($email, $now);
} catch (DatabaseConstraintException) {
    return $this->responseFactory->create(['error' => 'email already registered'], 409);
}
```

Sem essa captura, um email duplicado causa uma exceção não tratada que expõe detalhes internos de erro ao cliente.

## Validação de URL de Avatar

Permita apenas URLs `https://` para prevenir esquemas `javascript:`, `data:`, `file://` e `http://`:

```php
private function isValidAvatarUrl(string $url): bool
{
    if (mb_strlen($url) > UserProfile::MAX_AVATAR_URL_LENGTH) {
        return false;
    }

    // Apenas https — bloqueia javascript:, data:, file://, ftp://, http://
    if (!str_starts_with($url, 'https://')) {
        return false;
    }

    return filter_var($url, FILTER_VALIDATE_URL) !== false;
}
```

String vazia é permitida (sem avatar definido). O limite `MAX_AVATAR_URL_LENGTH = 2048` previne abuso de armazenamento.

## Limites de Comprimento de Campo

Defina limites como constantes no value object para uma única fonte de verdade:

```php
final readonly class UserProfile
{
    public const int MAX_BIO_LENGTH          = 500;
    public const int MAX_DISPLAY_NAME_LENGTH = 100;
    public const int MAX_AVATAR_URL_LENGTH   = 2048;
    ...
}
```

Use `mb_strlen()` em vez de `strlen()` para correção de multi-byte (UTF-8).

## Verificação de Propriedade

O endpoint `PUT /users/{userId}/profile` usa um cabeçalho `X-User-Id` para identificar o ator da requisição. Em produção, substitua isso por um claim JWT:

```php
private function resolveActorId(ServerRequestInterface $request): int
{
    $header = $request->getHeaderLine('X-User-Id');
    return is_numeric($header) ? (int) $header : 0;
}

// No handler:
if ($actorId !== $userId) {
    return $this->responseFactory->create(['error' => 'forbidden'], 403);
}
```

Cabeçalho não numérico ou ausente resulta em `0`, que nunca corresponde a um ID de usuário real → 403.

## Prevenção de Perfil Duplicado

Verifique se já existe um perfil antes de inserir e retorne 409:

```php
if ($this->repo->findByUserId($userId) !== null) {
    return $this->responseFactory->create(['error' => 'profile already exists'], 409);
}
```

Isso previne que um segundo `POST /users/{userId}/profile` sobrescreva silenciosamente um perfil existente.

## Propriedades de Segurança

| Propriedade | Implementação |
|---|---|
| Email duplicado | `DatabaseConstraintException` capturada → 409 (sem stack trace vazando) |
| Esquema de avatar_url | `str_starts_with('https://')` bloqueia todos os esquemas não-https |
| Comprimento de avatar_url | `MAX_AVATAR_URL_LENGTH = 2048` |
| Comprimento de bio | `MAX_BIO_LENGTH = 500` com `mb_strlen()` |
| Propriedade | Cabeçalho `X-User-Id` (substituir por claim JWT em produção) |
| Um perfil por usuário | Restrição `UNIQUE (user_id)` no banco + verificação 409 no handler |

## Resumo das Rotas

| Método | Caminho | Descrição |
|---|---|---|
| `POST` | `/users` | Registrar um usuário (email, 409 em duplicata) |
| `POST` | `/users/{userId}/profile` | Criar perfil (409 se já existe) |
| `GET` | `/users/{userId}/profile` | Obter perfil |
| `PUT` | `/users/{userId}/profile` | Atualizar perfil (requer cabeçalho `X-User-Id`) |
