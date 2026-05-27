# Como fazer: Sistema de Lista de Espera

> **Referência FT**: FT287 (`NENE2-FT/waitlistlog`) — Sistema de lista de espera: restrição UNIQUE(user_id) de uma entrada, máquina de estados waiting→approved/declined, proteção isTerminal(), /waitlist/me registrado antes de /{id} para prevenir captura de rota, autenticação X-Admin-Key, rastreamento de posição na fila, 39 testes / 98 asserções PASSAM.

Este guia mostra como construir um sistema de lista de espera onde usuários entram em uma fila e administradores aprovam ou recusam entradas.

## Schema

```sql
CREATE TABLE IF NOT EXISTS waitlist_entries (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL UNIQUE,   -- uma entrada por usuário
    status     TEXT    NOT NULL DEFAULT 'waiting',  -- waiting | approved | declined
    note       TEXT,                               -- nota opcional do usuário (max 500 chars)
    created_at TEXT    NOT NULL,
    updated_at TEXT    NOT NULL
);
```

`user_id UNIQUE` aplica uma entrada por usuário no nível do banco — sem necessidade de verificação na camada de aplicação para condições de corrida.

## Endpoints

| Método | Caminho | Auth | Descrição |
|--------|---------|------|-----------|
| `POST` | `/waitlist` | `X-User-Id` | Entrar na lista de espera |
| `GET` | `/waitlist/me` | `X-User-Id` | Obter próprio status + posição |
| `DELETE` | `/waitlist/me` | `X-User-Id` | Sair da lista de espera |
| `GET` | `/waitlist` | `X-Admin-Key` | Admin: listar todas as entradas |
| `POST` | `/waitlist/{id}/approve` | `X-Admin-Key` | Admin: aprovar entrada |
| `POST` | `/waitlist/{id}/decline` | `X-Admin-Key` | Admin: recusar entrada |

## Ordem de Registro das Rotas

`/waitlist/me` deve ser registrado **antes** de `/waitlist/{id}` para prevenir que o parâmetro de caminho capture a string literal `"me"`:

```php
// CORRETO: caminho estático antes do caminho dinâmico
$this->router->get('/waitlist/me', $this->handleMe(...));
$this->router->post('/waitlist/{id}/approve', $this->handleApprove(...));

// ERRADO: {id} capturaria "me"
$this->router->post('/waitlist/{id}/approve', $this->handleApprove(...));
$this->router->get('/waitlist/me', $this->handleMe(...));  // nunca alcançado
```

## Ciclo de Vida do Status

```
waiting ──────→ approved (terminal)
       └──────→ declined (terminal)
```

Uma vez aprovado ou recusado, uma entrada não pode transitar para outro estado. O método `isTerminal()` protege isso:

```php
enum WaitlistStatus: string
{
    case Waiting  = 'waiting';
    case Approved = 'approved';
    case Declined = 'declined';

    public function isTerminal(): bool
    {
        return $this !== self::Waiting;
    }
}
```

## Entrar com 409 em Duplicata

```php
$entry = $this->repository->join($userId, $note);

if ($entry === null) {
    return $this->responseFactory->create(['error' => 'Already on the waitlist.'], 409);
}
```

O repositório retorna `null` quando `user_id` já existe (capturado de `DatabaseConstraintException`). A resposta é 409 Conflict.

## Rastreamento de Posição

```php
$position = $this->repository->positionOf($entry);

// positionOf() conta entradas com status='waiting' e id <= $entry->id
// SELECT COUNT(*) FROM waitlist_entries WHERE status = 'waiting' AND id <= ?
```

A posição é o rank baseado em 1 na fila `waiting`. Entradas aprovadas/recusadas não contam. Isso dá aos usuários um lugar significativo na fila.

## Transição Admin com match

```php
private function handleTransition(int $id, WaitlistStatus $newStatus): ResponseInterface
{
    $result = $this->repository->transition($id, $newStatus);

    return match ($result) {
        'ok'               => $this->responseFactory->create(['status' => $newStatus->value]),
        'not_found'        => $this->responseFactory->create(['error' => 'Entry not found.'], 404),
        'already_terminal' => $this->responseFactory->create(['error' => 'Entry is already approved or declined.'], 409),
        default            => $this->responseFactory->create(['error' => 'Unexpected error.'], 500),
    };
}
```

`match` é exaustivo — o caso `default` captura quaisquer valores de retorno inesperados do repositório.

## Sair (Somente Enquanto em Espera)

```php
return match ($result) {
    'removed'     => $this->responseFactory->create(['removed' => true], 200),
    'not_found'   => $this->responseFactory->create(['error' => 'Not on the waitlist.'], 404),
    'not_waiting' => $this->responseFactory->create(['error' => 'Cannot leave — status is no longer waiting.'], 409),
    default       => $this->responseFactory->create(['error' => 'Unexpected error.'], 500),
};
```

Uma vez aprovado ou recusado, um usuário não pode sair — sua decisão está registrada. Isso previne manipulação do sistema (aprovar e sair para evitar rastreamento).

## Autenticação de Admin

```php
private function isAdmin(ServerRequestInterface $request): bool
{
    if ($this->adminKey === '') {
        return false;  // fail-closed: sem chave configurada → sem acesso admin
    }
    return hash_equals($this->adminKey, $request->getHeaderLine('X-Admin-Key'));
}
```

`hash_equals()` previne ataques de timing. Chave de admin vazia sempre retorna false (fail-closed).

## Validação de Nota

```php
private const int MAX_NOTE_LEN = 500;

private function resolveNote(mixed $raw): ?string
{
    if (!is_string($raw) || trim($raw) === '') {
        return null;
    }
    return mb_strlen($raw) > self::MAX_NOTE_LEN ? mb_substr($raw, 0, self::MAX_NOTE_LEN) : $raw;
}
```

Notas são opcionais (null se ausente/vazia), máx 500 caracteres, truncadas (não rejeitadas) se muito longas.

---

## O que NÃO fazer

| Anti-padrão | Risco |
|---|---|
| Sem restrição `UNIQUE(user_id)` | Entradas concorrentes criam entradas duplicadas; condição de corrida |
| Registrar `/{id}` antes de `/me` | `/waitlist/me` fica inacessível — correspondido por `{id}` capturando `"me"` |
| Permitir transição de estado terminal | Entrada aprovada recusada após acesso concedido; máquina de estados quebrada |
| Permitir saída de estado terminal | Usuário aprovado sai; concessão de acesso fica órfã |
| Retornar posição baseada em `id ASC` contando todas as entradas | Conta usuários aprovados/recusados; número de posição é enganoso |
| Armazenar chave de admin no banco | Rotação de chave requer atualização no banco; use variável de ambiente |
| Usar `==` em vez de `hash_equals()` para chave de admin | Ataque de timing revela a chave um caractere por vez |
| Sem fail-closed para admin | Chave vazia no env permite acesso admin não autenticado |
| Rejeitar nota se acima do limite | UX: truncar é mais amigável do que rejeitar para metadados suaves como notas |
