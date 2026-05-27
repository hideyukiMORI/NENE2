# Fluxo de Redefinição de Senha

Implementando uma redefinição de senha segura baseada em token: solicitar → verificar → concluir.

## Visão Geral

Um fluxo de redefinição de senha tem três etapas:
1. O usuário solicita uma redefinição — um token com tempo limitado é gerado e enviado (ex.: por e-mail).
2. O usuário verifica se o token ainda é válido antes de apresentar o formulário de redefinição.
3. O usuário submete uma nova senha — o token é consumido e a senha é atualizada.

## Schema do Banco de Dados

```sql
CREATE TABLE password_resets (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    token_hash TEXT    NOT NULL UNIQUE,
    used_at    TEXT,
    expires_at TEXT    NOT NULL,
    created_at TEXT    NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id)
);
```

`token_hash` armazena o hash SHA-256 do token bruto. O token bruto nunca é armazenado no banco de dados.

## Geração e Armazenamento do Token

Gere o token bruto com `random_bytes`, depois armazene apenas o hash SHA-256:

```php
$rawToken  = bin2hex(random_bytes(32)); // entropia de 256 bits, 64 chars hex
$tokenHash = hash('sha256', $rawToken);

$this->repo->createReset($userId, $tokenHash, $expiresAt, $now);

// Retornar $rawToken ao usuário (via e-mail ou resposta da API)
```

Ao verificar, faça hash do token recebido da mesma forma:

```php
$tokenHash = hash('sha256', $rawToken);
$reset     = $this->repo->findByTokenHashOrNull($tokenHash);
```

Armazenar um hash significa que uma violação do banco não expõe tokens de redefinição utilizáveis — um atacante precisaria reverter SHA-256 em um valor aleatório de 256 bits, o que é computacionalmente inviável.

## Prevenção de Enumeração de Usuário

`POST /password-reset` deve sempre retornar 202, mesmo para endereços de e-mail desconhecidos:

```php
$user = $this->repo->findUserByEmail($email);

// Sempre 202 — não revelar se o e-mail está registrado
if ($user === null) {
    return $this->json->create(['status' => 'pending'], 202);
}

// ... gerar token para usuário real
return $this->json->create(['status' => 'pending', 'token' => $rawToken], 202);
```

Retornar 404 para e-mails desconhecidos permitiria que um atacante enumerasse contas registradas sondando endereços de e-mail.

## Uso Único

Defina `used_at` quando a redefinição for concluída. Rejeite qualquer token que tenha `used_at IS NOT NULL`:

```php
if ($reset->isUsed()) {
    return $this->problems->create($request, 'conflict', 'Reset token has already been used.', 409, '');
}

$this->repo->markUsed($tokenHash, $now);
```

```php
public function isUsed(): bool
{
    return $this->usedAt !== null;
}
```

## Expiração

Aplique a expiração tanto no GET (verificação de status) quanto no POST (conclusão). Sempre verifique a expiração antes de verificar `isUsed()`:

```php
if ($reset->isExpired($now)) {
    return 410; // Gone — distinto de "não encontrado" (404) e "usado" (409)
}
if ($reset->isUsed()) {
    return 409;
}
```

410 (Gone) distingue "expirado" de "usado" (409), fornecendo ao usuário informação acionável.

## Invalidação de Token Antigo

Quando um usuário solicita uma nova redefinição, invalide todos os tokens não usados anteriores para aquele usuário:

```php
$this->executor->execute(
    "UPDATE password_resets SET used_at = ? WHERE user_id = ? AND used_at IS NULL",
    [$now, $userId],
);
```

Sem isso, um usuário que perdeu um e-mail de redefinição e solicita um novo teria dois tokens válidos em circulação simultaneamente — ambos poderiam ser usados para redefinir a senha.

## Sanitização da Resposta

`GET /password-reset/{token}` não deve expor `user_id` ou `token_hash` na resposta:

```php
public function toArray(): array
{
    return [
        'id'         => $this->id,
        'expires_at' => $this->expiresAt,
        'created_at' => $this->createdAt,
    ];
}
```

Expor `user_id` vincularia o token de redefinição a um ID de conta de usuário, o que é desnecessário já que o token em si é a credencial de autorização.

## Propriedades de Segurança

| Propriedade | Implementação |
|---|---|
| Entropia do token | `bin2hex(random_bytes(32))` — 256 bits |
| Armazenamento do token | Apenas hash SHA-256 — token bruto nunca no banco |
| Enumeração de usuário | Sempre 202 de `POST /password-reset` |
| Expiração | 1 hora; verificada no GET e POST |
| Uso único | `used_at` definido na conclusão; 409 em reutilização |
| Invalidação de token antigo | Tokens não usados anteriores marcados como usados na nova solicitação |
| Vazamento na resposta | `user_id` e `token_hash` excluídos de todas as respostas |
| Hashing de senha | Argon2id |

## Resumo de Rotas

| Método | Caminho | Descrição |
|---|---|---|
| `POST` | `/password-reset` | Solicitar uma redefinição (sempre 202) |
| `GET` | `/password-reset/{token}` | Verificar validade do token |
| `POST` | `/password-reset/{token}` | Concluir redefinição com nova senha |
