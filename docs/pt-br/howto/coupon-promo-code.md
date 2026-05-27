# Gerenciamento de Cupons e Códigos Promocionais

Guia de implementação de sistema de cupons com RBAC admin, rastreamento de uso por usuário, controle de expiração e limite.

## Visão Geral

- Apenas o papel admin pode criar cupons, desativá-los e visualizar o histórico de uso
- Usuários comuns podem usar cada cupom apenas 1 vez (`UNIQUE (coupon_id, user_id)`)
- `discount_pct`: inteiro de 1 a 100 (validação obrigatória)
- `max_uses = 0` significa sem limite
- `expires_at` é uma string ISO 8601 (NULL = sem prazo de validade)
- user_id é obtido **somente do header X-User-Id** (injeção via corpo não é possível)

## Endpoints

| Método | Caminho | Descrição | Permissão |
|--------|---------|-----------|-----------|
| `POST` | `/coupons` | Criar cupom | admin |
| `GET` | `/coupons/{code}` | Obter informações do cupom | Qualquer pessoa |
| `POST` | `/coupons/{code}/use` | Usar cupom (1 uso por usuário) | Autenticado |
| `GET` | `/coupons/{code}/uses` | Listar histórico de uso | admin |
| `DELETE` | `/coupons/{code}` | Desativar cupom | admin |

## Design do Banco de Dados

```sql
CREATE TABLE users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    role TEXT NOT NULL DEFAULT 'user',
    created_at TEXT NOT NULL,
    CHECK (role IN ('user', 'admin'))
);

CREATE TABLE coupons (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    code TEXT NOT NULL UNIQUE,
    discount_pct INTEGER NOT NULL CHECK (discount_pct >= 1 AND discount_pct <= 100),
    max_uses INTEGER NOT NULL DEFAULT 0,
    use_count INTEGER NOT NULL DEFAULT 0,
    is_active INTEGER NOT NULL DEFAULT 1,
    expires_at TEXT,
    created_by INTEGER NOT NULL,
    created_at TEXT NOT NULL,
    FOREIGN KEY (created_by) REFERENCES users(id)
);

CREATE TABLE coupon_uses (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    coupon_id INTEGER NOT NULL,
    user_id INTEGER NOT NULL,
    used_at TEXT NOT NULL,
    UNIQUE (coupon_id, user_id),
    FOREIGN KEY (coupon_id) REFERENCES coupons(id),
    FOREIGN KEY (user_id) REFERENCES users(id)
);
```

`UNIQUE (coupon_id, user_id)` previne uso duplo pelo mesmo usuário no nível do BD.

## Padrão de Verificação Admin

```php
private function requireUserId(ServerRequestInterface $request): ?int
{
    $val = $request->getHeaderLine('X-User-Id');
    return $val !== '' ? (int) $val : null;
}

private function isAdmin(ServerRequestInterface $request): bool
{
    return $request->getHeaderLine('X-User-Role') === 'admin';
}

// No início de handleCreate / handleDeactivate / handleListUses
$actorId = $this->requireUserId($request);
if ($actorId === null) {
    return $this->responseFactory->create(['error' => 'authentication required'], 401);
}
if (!$this->isAdmin($request)) {
    return $this->responseFactory->create(['error' => 'admin role required'], 403);
}
```

## Ordem de Verificação no Uso do Cupom

```php
// 1. Verificação de autenticação
if ($actorId === null) { return 401; }

// 2. Confirmar existência do cupom
$coupon = $this->repository->findByCode($code);
if ($coupon === null) { return 404; }

// 3. Verificação is_active
if (!(bool) $coupon['is_active']) { return 422 'not active'; }

// 4. Verificação de expiração
$now = date('c');
if ($coupon['expires_at'] !== null && $now > $coupon['expires_at']) { return 422 'expired'; }

// 5. Verificação max_uses (0 = sem limite)
if ($maxUses > 0 && $coupon['use_count'] >= $maxUses) { return 422 'limit reached'; }

// 6. Verificação de duplicata por usuário (confirmação na camada de aplicação da constraint UNIQUE)
$existing = $this->repository->findUse($coupon['id'], $actorId);
if ($existing !== null) { return 422 'already used'; }

// 7. Registrar uso + incrementar use_count
$this->repository->recordUse($coupon['id'], $actorId, $now);
return 201;
```

## Registro de Uso de Cupom

```php
public function recordUse(int $couponId, int $userId, string $now): int
{
    $id = $this->executor->insert(
        'INSERT INTO coupon_uses (coupon_id, user_id, used_at) VALUES (?, ?, ?)',
        [$couponId, $userId, $now]
    );
    $this->executor->execute(
        'UPDATE coupons SET use_count = use_count + 1 WHERE id = ?',
        [$couponId]
    );
    return $id;
}
```

O incremento de `use_count` é executado na mesma operação que o INSERT.
No MySQL, `use_count = use_count + 1` funciona de forma atômica sob acesso concorrente.

## Validação de discount_pct

```php
$discountPct = isset($body['discount_pct']) && is_int($body['discount_pct']) ? $body['discount_pct'] : null;
if ($discountPct === null || $discountPct < 1 || $discountPct > 100) {
    return $this->responseFactory->create(['error' => 'discount_pct must be 1-100'], 422);
}
```

`CHECK (discount_pct >= 1 AND discount_pct <= 100)` também garante no lado do BD,
mas a camada de aplicação rejeita primeiro e retorna o 422 apropriado.

## Exemplos de Resposta

### POST /coupons (criação)
```json
{
  "id": 1,
  "code": "SUMMER20",
  "discount_pct": 20,
  "max_uses": 100,
  "use_count": 0,
  "is_active": true,
  "expires_at": "2026-08-31T23:59:59+00:00",
  "created_by": 1,
  "created_at": "2026-05-21T..."
}
```

### POST /coupons/{code}/use (uso)
```json
{
  "id": 42,
  "coupon_id": 1,
  "code": "SUMMER20",
  "discount_pct": 20,
  "user_id": 7,
  "used_at": "2026-05-21T..."
}
```

## Prevenção de Injeção de user_id

O user_id deve ser obtido sempre do header `X-User-Id`.
O campo `user_id` do corpo da requisição é ignorado.

```php
// Incorreto: $userId = (int) $body['user_id'];  // pode ser manipulado pelo atacante
// Correto:
$actorId = $this->requireUserId($request);  // somente do header X-User-Id
```
