# Como Fazer: API de Gerenciamento de Assinatura / Plano (VULN-A~L)

Este guia demonstra uma API de gerenciamento de assinaturas onde usuários assinam planos, com prevenção de duplicatas, cancelamento e proteção IDOR.

## Visão Geral do Padrão

- Planos semeados são inseridos no momento do schema (`free`, `starter`, `pro`, `annual`).
- Usuários assinam via `POST /subscriptions` com um `plan_id`.
- Cada par (usuário, plano) pode ter no máximo uma assinatura ativa.
- Cancelamento muda o status para `'cancelled'`; assinaturas canceladas não podem ser canceladas novamente.

## Schema

```sql
CREATE TABLE IF NOT EXISTS plans (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    name        TEXT    NOT NULL UNIQUE,
    price_cents INTEGER NOT NULL,
    interval    TEXT    NOT NULL DEFAULT 'monthly'
);

CREATE TABLE IF NOT EXISTS subscriptions (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id      INTEGER NOT NULL,
    plan_id      INTEGER NOT NULL,
    status       TEXT    NOT NULL DEFAULT 'active',
    started_at   TEXT    NOT NULL,
    cancelled_at TEXT,
    FOREIGN KEY (plan_id) REFERENCES plans(id),
    UNIQUE (user_id, plan_id, status)
);
```

## VULN-A: Injeção SQL

Todas as queries usam prepared statements PDO. Nomes de planos e IDs de usuário nunca são interpolados.

## VULN-C: IDOR

Usuários não-admin podem acessar apenas suas próprias assinaturas. Acessar a assinatura de outro usuário retorna 404 (não 403):

```php
if (!$isAdmin && (int) $sub['user_id'] !== $uid) {
    return $this->problem(404, 'not-found', 'Assinatura não encontrada.');
}
```

## VULN-D: Admin Fail-Closed

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

IDs de caminho usam `ctype_digit()` + limite de comprimento. Caminhos não-numéricos (`/subscriptions/abc`) retornam 404 imediatamente.

## VULN-J: Confusão de Tipo

```php
$planId = $body['plan_id'] ?? null;
if (!is_int($planId) || $planId < 1) {
    return $this->problem(422, 'validation-failed', 'plan_id deve ser um inteiro positivo.');
}
```

String `"2"`, float `2.5` e zero retornam 422.

## Prevenção de Duplicatas

```php
$stmt = $this->pdo->prepare(
    "SELECT id FROM subscriptions WHERE user_id = :uid AND plan_id = :pid AND status = 'active'"
);
```

Tentar assinar um plano já ativo retorna 409.

## Idempotência de Cancelamento

O método `cancel()` verifica o status antes de atualizar. Uma segunda tentativa de cancelamento em uma assinatura `'cancelled'` retorna `'already_cancelled'` → 409 (não 204).

## JOIN para Resposta Enriquecida

O detalhe de assinatura inclui informações do plano via JOIN:

```sql
SELECT s.*, p.name AS plan_name, p.price_cents, p.interval AS plan_interval
FROM subscriptions s JOIN plans p ON p.id = s.plan_id
WHERE s.id = :id
```

## Rotas

```
GET    /plans                           Listar planos disponíveis (público)
POST   /subscriptions                   Assinar um plano (X-User-Id obrigatório)
GET    /subscriptions/{id}              Obter assinatura (proprietário ou admin)
POST   /subscriptions/{id}/cancel       Cancelar assinatura (proprietário ou admin)
GET    /users/{userId}/subscriptions    Listar assinaturas do usuário (proprietário ou admin)
```

## Veja Também

- Fonte FT213: `../NENE2-FT/subscriptionlog/`
- Relacionado: `docs/howto/coupon-redemption.md` (FT204, também limites stateful por usuário)
- Relacionado: `docs/howto/wish-list-api.md` (FT207, padrão VULN)
