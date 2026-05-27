# Como Fazer: API de Feed de Atividades / Timeline

> **Referência FT**: FT277 (`NENE2-FT/feedlog`) — Feed de atividades: eventos com tipos em allowlist (9 tipos), payload JSON por evento, feed com escopo por usuário com IDOR → 404, limitação de paginação (máx 100), admin fail-closed, 24 testes / 37 asserções PASS.
>
> Também validado em FT219 (`NENE2-FT/feedlog` precursor) — avaliação VULN no mesmo padrão.

Este guia mostra como construir um sistema de feed de atividades com eventos tipados, escopo por usuário e paginação usando NENE2.

## Funcionalidades

- Publicar eventos de atividade tipados (tipos estritamente em allowlist)
- Armazenamento de payload JSON (metadados arbitrários por tipo de evento)
- Feed com escopo por usuário com proteção IDOR (retorna 404 para acesso não autorizado)
- Filtragem de tipo de evento via parâmetro de query
- Paginação decrescente por timestamp (mais recente primeiro)
- Admin pode publicar eventos em nome de usuários

## Schema

```sql
CREATE TABLE IF NOT EXISTS events (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    type       TEXT    NOT NULL,
    payload    TEXT    NOT NULL DEFAULT '{}',
    created_at TEXT    NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_events_user ON events (user_id, id DESC);
CREATE INDEX IF NOT EXISTS idx_events_type ON events (type, id DESC);
```

## Endpoints

| Método | Caminho | Auth | Descrição |
|--------|---------|------|-----------|
| `POST` | `/events` | Usuário | Publicar um evento de atividade |
| `GET` | `/users/{userId}/feed` | Usuário (próprio ou admin) | Obter feed com filtro de tipo opcional |

## Allowlist de Tipos de Evento (VULN-B)

A allowlist estrita de tipos de evento previne mass assignment e injeção arbitrária de eventos:

```php
private const array ALLOWED_TYPES = [
    'post_created', 'post_liked', 'post_commented',
    'user_followed', 'user_unfollowed',
    'item_purchased', 'item_reviewed',
    'badge_earned', 'level_up',
];

$type = trim((string) ($body['type'] ?? ''));
if (!in_array($type, self::ALLOWED_TYPES, true)) {
    return $this->problem(422, 'validation-failed', 'type must be one of: ...');
}
```

## Armazenamento de Payload

Os payloads são armazenados como strings JSON e decodificados na recuperação:

```php
public function create(int $userId, string $type, array $payload): array
{
    $payloadJson = (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    // INSERT ... payload = :payloadJson
}

private function decode(array $row): array
{
    $decoded = json_decode((string) $row['payload'], true);
    $row['payload'] = is_array($decoded) ? $decoded : [];
    return $row;
}
```

## Proteção IDOR (VULN-C)

O acesso ao feed retorna 404 (não 403) quando um usuário não autorizado tenta visualizar o feed de outro usuário:

```php
$callerUid = $this->uid($req);
$isAdmin   = $this->isAdmin($req);
if (!$isAdmin && $callerUid !== $targetUid) {
    return $this->problem(404, 'not-found', 'User not found.');
}
```

## Paginação com Filtragem de Tipo

```php
$type   = isset($qs['type']) && in_array($qs['type'], self::ALLOWED_TYPES, true) ? $qs['type'] : null;
$limit  = $this->clampInt((string) ($qs['limit'] ?? ''), self::DEFAULT_LIMIT, 1, self::MAX_LIMIT);
$offset = $this->clampInt((string) ($qs['offset'] ?? ''), 0, 0, PHP_INT_MAX);
```

Tipos desconhecidos no parâmetro `?type=` são ignorados silenciosamente (null = sem filtro aplicado).

## Resultados da Avaliação VULN (FT219)

- **VULN-B**: `in_array(..., strict: true)` previne qualquer tipo de evento fora da lista
- **VULN-C**: IDOR retorna 404 para ocultar a existência do feed de chamadores não autorizados
- **VULN-D**: Admin fail-closed — chave de admin vazia sempre retorna false
- **VULN-F**: `is_array($payload)` garante que o payload seja sempre um objeto JSON, não um escalar
- **VULN-G**: `ctype_digit()` protege o parâmetro de caminho `userId`
- **VULN-I**: `clampInt()` limita `limit` (1–100) e `offset` (0–MAX_INT)

## Padrões de Segurança

- **`ctype_digit()`**: Validação de inteiro segura contra ReDoS para params de caminho
- **`is_array()`**: O payload deve ser um objeto JSON (array no PHP) — não string, número, null
- **Consultas parametrizadas**: Todo SQL usa parâmetros `:named` — sem concatenação de strings
- **`in_array(..., true)`**: Comparação estrita previne bypass por coerção de tipo

---

## O Que NÃO Fazer

| Anti-padrão | Risco |
|---|---|
| Aceitar string de tipo de evento livre | Tipos não controlados poluem o feed; difícil construir consultas específicas por tipo |
| Armazenar payload como TEXT sem validação JSON | `is_array($payload)` garante um objeto JSON; escalares/arrays quebram consumidores downstream |
| Confiar no `limit` bruto da query string | Sem limite superior → varredura completa da tabela em grandes datasets |
| Usar `in_array($type, TYPES)` sem `true` | Comparação frouxa; `0 == 'post_created'` em algumas versões do PHP |
| Retornar 403 no acesso ao feed para usuário errado | Revela que o usuário existe; use 404 para ocultar enumeração de usuários |
| Indexar apenas em `user_id` | `id DESC` ausente no índice composto causa ORDER BY lento em feeds grandes |
