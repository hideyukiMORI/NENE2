# Como Fazer: API de Fila de Notificações

Este guia demonstra uma fila de notificações onde admins enviam notificações direcionadas a usuários, que podem listá-las, lê-las e excluí-las.

## Visão Geral do Padrão

- Admins enviam notificações para usuários específicos via `POST /notifications` (somente admin).
- Usuários recebem e gerenciam suas próprias notificações via `GET`, `POST /read`, `DELETE`.
- `unread_count` é retornado com toda resposta de listagem.
- `?unread=1` filtra para notificações apenas não lidas.
- Marcar como lido é idempotente (notificações já lidas retornam 200, não erro).

## Schema

```sql
CREATE TABLE IF NOT EXISTS notifications (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    type       TEXT    NOT NULL DEFAULT 'info',
    title      TEXT    NOT NULL,
    body       TEXT    NOT NULL DEFAULT '',
    is_read    INTEGER NOT NULL DEFAULT 0,
    created_at TEXT    NOT NULL,
    read_at    TEXT
);
```

## Allowlist de Tipo

```php
private const array ALLOWED_TYPES = ['info', 'warning', 'error', 'success'];
```

Tipos desconhecidos retornam 422. Nunca use um campo de texto livre para tipo sem validação.

## Marcar como Lido Idempotente

```php
public function markRead(int $id, int $userId): bool
{
    $notif = $this->findById($id);
    if ($notif === null || (int) $notif['user_id'] !== $userId) {
        return false;
    }
    if ((int) $notif['is_read'] === 1) {
        return true;  // Já lido — idempotente, retornar sucesso
    }
    $this->pdo->prepare(
        'UPDATE notifications SET is_read = 1, read_at = :now WHERE id = :id'
    )->execute([':now' => $this->now(), ':id' => $id]);
    return true;
}
```

## Filtro de Não Lidos

```php
if ($unreadOnly === true) {
    $stmt = $this->pdo->prepare(
        'SELECT * FROM notifications WHERE user_id = :uid AND is_read = 0 ORDER BY id DESC'
    );
}
```

O parâmetro de query `?unread=1` ativa este caminho; qualquer outro valor lista todos.

## IDOR: Escopo por Usuário

Todas as operações de leitura/exclusão/listagem verificam `user_id`:

```php
if (!$isAdmin && (int) $notif['user_id'] !== $userId) {
    return false;  // → 404
}
```

Usuários não-admin não podem ler, marcar ou excluir notificações de outros usuários.

## Envio Somente para Admin

```php
private function send(ServerRequestInterface $req): ResponseInterface
{
    if (!$this->isAdmin($req)) {
        return $this->problem(403, 'forbidden', 'Admin access required.');
    }
    ...
}
```

O `user_id` alvo é especificado no corpo da requisição, validado como `is_int() && >= 1`.

## Resumo de Validação

| Campo | Regra |
|---|---|
| `user_id` corpo | Inteiro >= 1 (não string/float) |
| `type` corpo | Um de: info, warning, error, success |
| `title` corpo | Não-vazio, máx 200 chars |
| Header `X-User-Id` | Obrigatório para leitura/exclusão; `ctype_digit`, >0 |
| Header `X-Admin-Key` | Obrigatório para enviar; fail-closed quando vazio |

## Rotas

```
POST   /notifications                  Enviar notificação (somente admin)
GET    /users/{userId}/notifications   Listar notificações (proprietário ou admin)
POST   /notifications/{id}/read        Marcar como lido (somente proprietário)
DELETE /notifications/{id}             Excluir notificação (proprietário ou admin)
```

## Veja Também

- Fonte FT214: `../NENE2-FT/notiflog/`
- Relacionado: `docs/howto/session-token-management.md` (FT208, padrão de chave admin)
