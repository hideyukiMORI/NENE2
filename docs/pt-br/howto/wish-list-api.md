# Como fazer: API de Lista de Desejos (Avaliação de Segurança VULN-A~L)

Este guia demonstra uma API de lista de desejos pessoal com CRUD completo, override de admin e hardening de segurança cobrindo VULN-A a VULN-L.

## Visão Geral do Padrão

- Usuários gerenciam listas de desejos privadas via `POST /wishes`, `GET /wishes/{id}`, `PATCH /wishes/{id}`, `DELETE /wishes/{id}`.
- `GET /users/{userId}/wishes` lista os desejos de um usuário (somente dono ou admin).
- IDOR: não-donos sempre recebem 404 (não 403) para evitar revelar existência do recurso.
- Admins identificados pelo cabeçalho `X-Admin-Key`; fail-closed quando chave está vazia.

## Schema

```sql
CREATE TABLE IF NOT EXISTS wishes (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    title      TEXT    NOT NULL,
    url        TEXT    NOT NULL DEFAULT '',
    priority   INTEGER NOT NULL DEFAULT 0,
    fulfilled  INTEGER NOT NULL DEFAULT 0,
    created_at TEXT    NOT NULL,
    updated_at TEXT    NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_wishes_user ON wishes (user_id, priority DESC, id DESC);
```

## VULN-A: Injeção SQL

Todas as queries usam PDO prepared statements com placeholders nomeados. O título `'; DROP TABLE wishes; --` é armazenado verbatim sem danos:

```php
$this->pdo->prepare(
    'INSERT INTO wishes (user_id, title, ...) VALUES (:uid, :title, ...)'
)->execute([':uid' => $userId, ':title' => $title, ...]);
```

## VULN-B: Mass Assignment

O handler `update()` mantém uma allowlist explícita de campos. Campos como `user_id`, `created_at` ou `id` enviados pelo cliente são silenciosamente ignorados:

```php
$allowed = ['title', 'url', 'priority', 'fulfilled'];
foreach ($allowed as $field) {
    if (array_key_exists($field, $fields)) { ... }
}
```

## VULN-C: IDOR

Leituras e exclusões por não-donos retornam 404 (não 403) para ocultar existência do recurso:

```php
if (!$isAdmin && (int) $wish['user_id'] !== $uid) {
    return $this->problem(404, 'not-found', 'Wish not found.');
}
```

O endpoint de lista similarmente oculta as listas de outros usuários:

```php
if (!$isAdmin && $callerUid !== $targetUid) {
    return $this->problem(404, 'not-found', 'User not found.');
}
```

## VULN-D: Admin Fail-Closed

Um `adminKey` vazio nunca concede privilégios de admin. Sem essa proteção, um deployment não configurado trataria todo cabeçalho `X-Admin-Key: ` como válido:

```php
private function isAdmin(ServerRequestInterface $req): bool
{
    if ($this->adminKey === '') {
        return false;
    }
    return hash_equals($this->adminKey, $req->getHeaderLine('X-Admin-Key'));
}
```

## VULN-G: ReDoS

IDs de parâmetros de caminho são validados com `ctype_digit()` em vez de padrões regex que poderiam ser sujeitos a ReDoS:

```php
if (!ctype_digit($raw) || strlen($raw) > 18) {
    return $this->problem(404, 'not-found', 'Wish not found.');
}
```

## VULN-I: Valores Negativos

Priority deve ser 0–100. Valores negativos e valores acima de 100 retornam 422:

```php
if (!is_int($priorityRaw) || $priorityRaw < 0 || $priorityRaw > 100) {
    return $this->problem(422, 'validation-failed', 'priority must be an integer 0–100.');
}
```

## VULN-J: Confusão de Tipo JSON

`is_int()` rejeita números codificados como string (`"5"`) e floats (`1.5`) para o campo `priority`. `is_bool()` rejeita inteiros `1`/`0` para `fulfilled`:

```php
$p = $body['priority'];
if (!is_int($p) || $p < 0 || $p > 100) { return 422; }

$f = $body['fulfilled'];
if (!is_bool($f)) { return 422; }
```

## Rotas

```
POST   /wishes                 Criar desejo (X-User-Id obrigatório)
GET    /wishes/{id}            Obter desejo por ID (dono ou admin)
PATCH  /wishes/{id}            Atualizar campos do desejo (somente dono)
DELETE /wishes/{id}            Deletar desejo (dono ou admin)
GET    /users/{userId}/wishes  Listar desejos do usuário (dono ou admin)
```

## Resumo de Validação

| Campo | Regra |
|---|---|
| `X-User-Id` | Obrigatório para POST/PATCH; `ctype_digit`, >0 |
| `title` | Não vazio, máx 200 chars |
| `url` | Opcional, máx 500 chars |
| `priority` | Inteiro 0–100 (não string/float); padrão 0 |
| `fulfilled` | Apenas booleano (não 1/0) no PATCH |
| Caminho `{id}` | `ctype_digit`, máx 18 chars, >0; caso contrário 404 |

## Veja Também

- Fonte FT207: `../NENE2-FT/wishlistlog/`
- Relacionado: `docs/howto/booking-resource.md` (FT201, também VULN)
- Relacionado: `docs/howto/coupon-redemption.md` (FT204, VULN + ATK)
