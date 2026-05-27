# Como fazer: API de Perfil de Usuário

> **Referência FT**: FT275 (`NENE2-FT/profilelog`) — Perfil de usuário: um-perfil-por-usuário (UNIQUE user_id), email validado com FILTER_VALIDATE_EMAIL, limites de comprimento de campo (display_name 100 / bio 500 / avatar_url 2048), URL de avatar somente https, DatabaseConstraintException → 409, proteção de propriedade via X-User-Id, 32 testes PASSAM.

Demonstra um sistema de usuário-para-perfil 1:1: criar um usuário (email único), criar/obter/atualizar o perfil. Os campos do perfil têm limites de comprimento aplicados e uma restrição de segurança de URL.

---

## Schema

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

`user_id UNIQUE` aplica o invariante de um-perfil-por-usuário no nível do banco.

---

## Rotas

| Método | Caminho | Descrição |
|--------|---------|-----------|
| `POST` | `/users` | Criar usuário (email obrigatório, único) |
| `POST` | `/users/{userId}/profile` | Criar perfil para o usuário |
| `GET`  | `/users/{userId}/profile` | Obter perfil |
| `PUT`  | `/users/{userId}/profile` | Atualizar perfil (somente dono) |

---

## Validação de email

```php
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    return $this->responseFactory->create(['error' => 'valid email is required'], 422);
}
```

Em email duplicado, `DatabaseConstraintException` é capturada e mapeada para 409:

```php
try {
    $userId = $this->repo->createUser($email, $now);
} catch (DatabaseConstraintException) {
    return $this->responseFactory->create(['error' => 'email already registered'], 409);
}
```

---

## Limites de campo (value object UserProfile)

```php
final readonly class UserProfile
{
    public const int MAX_BIO_LENGTH          = 500;
    public const int MAX_DISPLAY_NAME_LENGTH = 100;
    public const int MAX_AVATAR_URL_LENGTH   = 2048;
}
```

O comprimento é verificado com `mb_strlen()` (seguro para multi-byte):

```php
if (mb_strlen($displayName) > UserProfile::MAX_DISPLAY_NAME_LENGTH) {
    return [$displayName, $bio, $avatarUrl, 'display_name must not exceed 100 characters'];
}
```

---

## URL de avatar somente https

```php
private function isValidAvatarUrl(string $url): bool
{
    if (mb_strlen($url) > UserProfile::MAX_AVATAR_URL_LENGTH) {
        return false;
    }
    // Permitir apenas https para prevenir esquemas javascript: e data: URI
    if (!str_starts_with($url, 'https://')) {
        return false;
    }
    return filter_var($url, FILTER_VALIDATE_URL) !== false;
}
```

`str_starts_with('https://')` bloqueia `javascript:`, `data:` e `http://` antes de `filter_var` executar.

---

## Proteção de propriedade

Atualizações de perfil exigem que `X-User-Id` corresponda ao dono do perfil:

```php
$actorId = $this->resolveActorId($request); // do cabeçalho X-User-Id

if ($actorId !== $userId) {
    return $this->responseFactory->create(['error' => 'forbidden'], 403);
}
```

---

## O que NÃO fazer

| Anti-padrão | Risco |
|---|---|
| Sem validação de formato de email | Emails inválidos armazenados; envios downstream falham silenciosamente |
| Sem UNIQUE em `user_id` em profiles | Perfis duplicados possíveis; GET retorna linha imprevisível |
| Usar `strlen()` para limite de display_name | Caracteres multi-byte (emoji, CJK) contados erroneamente |
| Permitir URLs de avatar `http://` | Conteúdo misto passivo e superfície potencial de clickjacking |
| Permitir URIs `javascript:` ou `data:` | XSS se URL do avatar é renderizado como `<a href>` ou `<img src>` |
| Pular captura de `DatabaseConstraintException` | Violação de UNIQUE se torna 500 em vez de 409 |
| Permitir qualquer usuário atualizar qualquer perfil | IDOR — sempre verifique ator = dono antes da escrita |
