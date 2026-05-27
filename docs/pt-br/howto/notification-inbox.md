# Como Fazer: API de Caixa de Entrada de Notificações

> **Referência FT**: FT271 (`NENE2-FT/notificationlog`) — Caixa de entrada de notificações: criação de notificação com allowlist de tipo, proteção IDOR por usuário (404 não 403), padrão admin fail-closed, marcar todos como lido em massa, idempotência de is_read, limitação de paginação com vinculação PDO::PARAM_INT, 31 testes / 98 asserções PASS.
>
> Também validado em FT222 (`NENE2-FT/notificationlog`) — avaliação VULN no mesmo padrão.

Este guia mostra como construir um sistema de caixa de entrada de notificações com notificações push com allowlist de tipo,
proteção IDOR por usuário e marcar como lido em massa usando NENE2.

## Funcionalidades

- Criação de notificação somente para admin com allowlist de tipo
- Proteção IDOR por usuário: usuários veem apenas suas próprias notificações (404 em acesso não autorizado)
- Marcar como lido único e em massa com verificação de propriedade
- Contagem de não lidos retornada em toda listagem
- Filtro opcional de apenas não lidos e paginação
- Admin fail-closed

## Schema

```sql
CREATE TABLE IF NOT EXISTS notifications (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    type       TEXT    NOT NULL,
    title      TEXT    NOT NULL,
    body       TEXT    NOT NULL DEFAULT '',
    is_read    INTEGER NOT NULL DEFAULT 0,
    created_at TEXT    NOT NULL,
    read_at    TEXT
);

CREATE INDEX IF NOT EXISTS idx_notifications_user ON notifications (user_id, id DESC);
```

Sem tabela `users` separada — a API confia no header `X-User-Id` (substitua por autenticação real em produção).

## Endpoints

| Método | Caminho | Auth | Descrição |
|--------|---------|------|-----------|
| `POST` | `/notifications` | Admin | Criar notificação para um usuário |
| `GET` | `/users/{userId}/notifications` | Próprio / Admin | Listar notificações |
| `POST` | `/notifications/{id}/read` | Próprio / Admin | Marcar uma notificação como lida |
| `POST` | `/users/{userId}/notifications/read-all` | Próprio / Admin | Marcar todas como lidas |

## Allowlist de Tipo

Strings de tipo de forma livre são rejeitadas para prevenir ataques de injeção e enumeração:

```php
public const array ALLOWED_TYPES = [
    'system',
    'promotion',
    'social',
    'account',
    'security',
    'reminder',
];
```

Handler de rota valida antes de qualquer acesso ao banco de dados:

```php
if (!in_array($type, NotificationRepository::ALLOWED_TYPES, true)) {
    $allowed = implode(', ', NotificationRepository::ALLOWED_TYPES);
    return $this->problem(422, 'validation-failed', "type must be one of: {$allowed}.");
}
```

## Proteção IDOR

Usuários podem ler apenas suas próprias notificações. Um 404 (não 403) é retornado em acesso não autorizado
para prevenir enumeração de ID de usuário:

```php
private function isSelfOrAdmin(ServerRequestInterface $req, int $ownerId): bool
{
    if ($this->isAdmin($req)) {
        return true;
    }
    $uid = $this->requestUserId($req);
    return $uid !== null && $uid === $ownerId;
}
```

Marcar como lido também verifica propriedade antes de agir:

```php
// Handler POST /notifications/{id}/read
$notification = $this->repo->findById($id);
if ($notification === null) {
    return $this->problem(404, 'not-found', 'Notification not found.');
}
// IDOR: apenas o proprietário ou admin pode marcar como lido
if (!$this->isSelfOrAdmin($req, (int) $notification['user_id'])) {
    return $this->problem(404, 'not-found', 'Notification not found.');
}
```

## Admin Fail-Closed

```php
private function isAdmin(ServerRequestInterface $req): bool
{
    if ($this->adminKey === '') {
        return false;   // fail-closed: sem admin se a chave não estiver configurada
    }
    $key = $req->getHeaderLine('X-Admin-Key');
    return $key !== '' && hash_equals($this->adminKey, $key);
}
```

## Paginação

`limit` e `offset` são limitados no repositório — nunca confiados diretamente do cliente:

```php
private const int MAX_LIMIT = 100;

$limit  = max(1, min(self::MAX_LIMIT, $limit));
$offset = max(0, $offset);
```

Vinculação de inteiro PDO previne SQL injection em LIMIT / OFFSET:

```php
$stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
$stmt->bindValue(':off', $offset, PDO::PARAM_INT);
```

## Idempotência de Marcar como Lido

```php
/** @return 'ok'|'not_found'|'already_read' */
public function markAsRead(int $id): string
{
    $notification = $this->findById($id);
    if ($notification === null) return 'not_found';
    if ((bool) $notification['is_read']) return 'already_read';

    // ... UPDATE SET is_read = 1, read_at = :now ...
    return 'ok';
}
```

O handler de rota retorna 200 tanto para `ok` quanto para `already_read` — tornando o endpoint seguro para chamar
múltiplas vezes sem efeitos colaterais.

## Padrões de Segurança

| Padrão | Implementação |
|--------|---------------|
| **Allowlist de tipo** | `in_array($type, ALLOWED_TYPES, true)` — correspondência estrita |
| **IDOR → 404** | Retornar 404 (não 403) para ocultar existência de usuário/notificação |
| **Verificação de propriedade** | Buscar notificação, verificar `user_id` antes de marcar como lido |
| **Admin fail-closed** | `if ($this->adminKey === '') return false;` |
| **`ctype_digit()`** | Validação de ID de parâmetro de caminho — seguro contra ReDoS |
| **Limitação de paginação** | `max(1, min(100, $limit))` + vinculação `PDO::PARAM_INT` |
| **`is_int()` + `> 0`** | Verificação estrita de user_id — rejeita floats, strings, negativos |

---

## O Que NÃO Fazer

| Anti-padrão | Risco |
|---|---|
| Aceitar string `type` de forma livre | Tipos não validados poluem a caixa de entrada; sem forma de filtrar por categorias significativas |
| Retornar 403 em acesso não autorizado à notificação | Revela se a notificação ou usuário existe — vazamento de informação IDOR |
| Retornar 404 de marcar-como-lido antes da verificação de propriedade | Um atacante aprende que a notificação existe e pertence a alguém |
| Permitir que `adminKey` vazio signifique "admin permitido" | Fail-open; qualquer requisição se torna admin se nenhuma chave estiver configurada |
| Confiar em `limit` bruto da string de query | Uma requisição com `limit=999999` causa varredura de tabela completa |
| Usar interpolação de string em LIMIT/OFFSET | `"LIMIT {$limit}"` com entrada não validada habilita SQL injection |
