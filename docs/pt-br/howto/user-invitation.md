# Sistema de Convite de Usuários

Convide novos usuários por email, aplique expiração e previna abusos com convites baseados em tokens.

## Visão Geral

Um sistema de convites permite que usuários existentes patrocinem a criação de novas contas. Os invariantes principais são:

- Tokens são criptograficamente aleatórios e não-adivinháveis.
- A expiração é verificada tanto na leitura quanto na escrita.
- Apenas o convidante original pode cancelar um convite.
- Tokens aceitos e cancelados não podem ser reutilizados.

## Schema do Banco de Dados

```sql
CREATE TABLE users (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    email      TEXT    NOT NULL UNIQUE,
    name       TEXT    NOT NULL,
    created_at TEXT    NOT NULL
);

CREATE TABLE invitations (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    inviter_id  INTEGER NOT NULL,
    email       TEXT    NOT NULL,
    token       TEXT    NOT NULL UNIQUE,
    status      TEXT    NOT NULL DEFAULT 'pending',
    expires_at  TEXT    NOT NULL,
    accepted_at TEXT,
    created_at  TEXT    NOT NULL,
    FOREIGN KEY (inviter_id) REFERENCES users(id)
);
```

## Geração de Token

Sempre use `bin2hex(random_bytes(32))` — 64 caracteres hex, 256 bits de entropia:

```php
$token = bin2hex(random_bytes(32));
```

Nunca use IDs sequenciais, UUIDs ou strings curtas como tokens de convite. Um token adivinhável permite que um atacante aceite qualquer convite pendente.

## Enviar um Convite

Antes de criar o convite, verifique se o email alvo já não está cadastrado:

```php
// Prevenir convite para usuários já registrados
if ($this->repo->findUserByEmail($email) !== null) {
    return $this->problems->create($request, 'conflict', 'Email already registered.', 409, '');
}

$expiresAt = (new \DateTimeImmutable())->modify('+24 hours')->format('Y-m-d H:i:s');
$token     = bin2hex(random_bytes(32));
$invite    = $this->repo->createInvitation($inviterId, $email, $token, $expiresAt, $now);
```

Retornar 409 ao convidar um email registrado revela o status de cadastro ao convidante. Isso é aceitável em sistemas somente por convite, onde os convidantes são usuários confiáveis. Em sistemas totalmente públicos, considere unificar a resposta para 202.

## Aceitar um Convite

Verifique a expiração **antes** de verificar o status — um convite pendente mas expirado deve retornar 410, não 409:

```php
$invite = $this->repo->findByTokenOrNull($token);

if ($invite === null) {
    return $this->problems->create($request, 'not-found', 'Invitation not found.', 404, '');
}

$now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

if ($invite->isExpired($now)) {
    return $this->problems->create($request, 'gone', 'Invitation has expired.', 410, '');
}

if (!$invite->isPending()) {
    return $this->problems->create($request, 'conflict', 'Invitation is no longer valid.', 409, '');
}
```

`isExpired` compara a string do timestamp atual diretamente — strings de datetime do SQLite ordenam lexicograficamente quando armazenadas como `Y-m-d H:i:s`:

```php
public function isExpired(string $now): bool
{
    return $now >= $this->expiresAt;
}

public function isPending(): bool
{
    return $this->status === 'pending';
}
```

## Cancelar um Convite

A propriedade é aplicada usando o `inviter_id` do corpo da requisição (já que não há middleware de sessão/JWT neste exemplo mínimo). Em produção, derive o ator de um token autenticado:

```php
if ($invite->inviterId !== $inviterId) {
    return $this->problems->create($request, 'forbidden', 'Only the inviter may cancel this invitation.', 403, '');
}

if (!$invite->isPending()) {
    return $this->problems->create($request, 'conflict', 'Invitation is already ' . $invite->status . '.', 409, '');
}
```

Retorne 403 (não 404) quando a verificação de propriedade falhar — obscurecer a existência do convite esconderia o fato de que o atacante encontrou um token real, mas 403 é a semântica correta aqui, já que o recurso foi encontrado mas a ação é proibida.

## Máquina de Estado

```
pending ──aceitar──► accepted
pending ──cancelar──► cancelled
```

Uma vez que um convite sai do estado `pending`, nenhuma transição adicional é permitida. Tentar aceitar um convite `accepted` ou `cancelled` retorna 409.

## Propriedades de Segurança

| Propriedade | Implementação |
|---|---|
| Entropia do token | `bin2hex(random_bytes(32))` — 256 bits |
| Unicidade do token | Restrição UNIQUE em `invitations.token` |
| Verificação de expiração na leitura | verificada no handler antes de qualquer escrita |
| Prevenção de reutilização | Guarda `isPending()` antes de aceitar/cancelar |
| Aplicação de propriedade | Verificação de igualdade de `inviter_id` → 403 |
| Sem vazamento de PII de email | Corpo do 409 não expõe o email convidado |
| Injeção SQL | Queries PDO parametrizadas em todo o código |

## Resumo das Rotas

| Método | Caminho | Descrição |
|---|---|---|
| `POST` | `/users` | Criar uma conta de usuário |
| `POST` | `/users/{id}/invitations` | Enviar um convite |
| `GET` | `/invitations/{token}` | Visualizar um convite |
| `POST` | `/invitations/{token}/accept` | Aceitar um convite |
| `DELETE` | `/invitations/{token}` | Cancelar um convite |
