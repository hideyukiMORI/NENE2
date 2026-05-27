# Como Fazer: API de Resgate de Cupom / Código de Desconto

Este guia mostra como construir um sistema de resgate de cupons com limites de uso e expiração usando o NENE2.
Padrão demonstrado pelo field trial **couponlog** (FT218).

## Funcionalidades

- Criar códigos de cupom com valor de desconto, limite de uso e expiração (somente admin)
- Geração automática opcional de códigos aleatórios (`bin2hex(random_bytes(6))`)
- Um resgate por usuário por cupom (`UNIQUE(coupon_id, user_id)`)
- Aplicação de limite de uso (`max_uses`)
- Verificação de expiração em relação ao horário UTC atual
- Listagem de resgates exclusiva para admin

## Schema

```sql
CREATE TABLE IF NOT EXISTS coupons (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    code        TEXT    NOT NULL UNIQUE,
    discount    INTEGER NOT NULL,
    max_uses    INTEGER NOT NULL DEFAULT 1,
    used_count  INTEGER NOT NULL DEFAULT 0,
    expires_at  TEXT    NOT NULL,
    created_at  TEXT    NOT NULL
);

CREATE TABLE IF NOT EXISTS redemptions (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    coupon_id   INTEGER NOT NULL,
    user_id     INTEGER NOT NULL,
    redeemed_at TEXT    NOT NULL,
    UNIQUE (coupon_id, user_id),
    FOREIGN KEY (coupon_id) REFERENCES coupons(id) ON DELETE CASCADE
);
```

## Endpoints

| Método | Caminho | Auth | Descrição |
|--------|---------|------|-----------|
| `POST` | `/coupons` | Admin | Criar cupom |
| `GET` | `/coupons/{code}` | Público | Obter info do cupom |
| `POST` | `/coupons/{code}/redeem` | Usuário | Resgatar cupom |
| `GET` | `/coupons/{code}/redemptions` | Admin | Listar resgates |

## Validação de Código

Códigos de cupom usam um padrão estrito para prevenir injeção:

```php
/** Código de cupom: alfanumérico maiúsculo, 4–32 chars */
private const string CODE_PATTERN = '/\A[A-Z0-9]{4,32}\z/';
```

Parâmetro de caminho normalizado para maiúsculas antes da validação:

```php
private function pathCode(ServerRequestInterface $req): ?string
{
    $params = $req->getAttribute(Router::PARAMETERS_ATTRIBUTE, []);
    $code   = strtoupper(trim($params['code'] ?? ''));
    if (!preg_match(self::CODE_PATTERN, $code)) {
        return null; // → 404
    }
    return $code;
}
```

## Lógica de Resgate

```php
/** @return 'ok'|'not_found'|'expired'|'exhausted'|'already_redeemed' */
public function redeem(string $code, int $userId): string
{
    $coupon = $this->findByCode($code);
    if ($coupon === null) return 'not_found';

    // Verificar expiração
    if ($coupon['expires_at'] < $this->now()) return 'expired';

    // Verificar limite de uso
    if ((int) $coupon['used_count'] >= (int) $coupon['max_uses']) return 'exhausted';

    // Verificar limite por usuário
    $stmt = $this->pdo->prepare(
        'SELECT id FROM redemptions WHERE coupon_id = :cid AND user_id = :uid'
    );
    if ($stmt->fetch() !== false) return 'already_redeemed';

    // Registrar + incrementar contador
    $this->pdo->prepare('INSERT INTO redemptions ...')->execute([...]);
    $this->pdo->prepare('UPDATE coupons SET used_count = used_count + 1 WHERE id = :id')
        ->execute([':id' => $coupon['id']]);

    return 'ok';
}
```

O handler de rota usa expressão `match` para ramificação limpa:

```php
return match ($result) {
    'not_found'        => $this->problem(404, 'not-found', 'Coupon not found.'),
    'expired'          => $this->problem(409, 'conflict', 'Coupon has expired.'),
    'exhausted'        => $this->problem(409, 'conflict', 'Coupon usage limit reached.'),
    'already_redeemed' => $this->problem(409, 'conflict', 'You have already redeemed this coupon.'),
    default            => $this->json(['message' => 'Coupon redeemed successfully.']),
};
```

## Geração Automática de Código

Quando nenhum `code` é fornecido no corpo da requisição, um é gerado:

```php
$code = strtoupper(trim((string) ($body['code'] ?? '')));
if ($code === '') {
    $code = strtoupper(bin2hex(random_bytes(6))); // 12 caracteres hex maiúsculos
}
```

## Padrões de Segurança

- **Admin falha fechado**: `if ($this->adminKey === '') return false;` antes de `hash_equals()`
- **Padrão de código**: equivalente a `ctype_digit()` para códigos — regex `/\A[A-Z0-9]{4,32}\z/`
- **`is_int()`**: verificação de tipo estrita para `discount` e `max_uses` — rejeita floats
- **Expiração ISO 8601**: validação via regex + comparação lexicográfica (strings UTC)
- **Incremento atômico**: `UPDATE SET used_count = used_count + 1` previne condições de corrida
- **Constraint UNIQUE**: rede de segurança no nível do banco de dados para prevenção de duplicatas
