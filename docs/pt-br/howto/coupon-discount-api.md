# Como Fazer: API de Código de Desconto com Cupom

> **Referência FT**: FT302 (`NENE2-FT/couponlog`) — API de código de desconto com cupom: criação exclusiva para admin com `X-Admin-Key` (hash_equals), CODE_PATTERN `[A-Z0-9]{4,32}` com normalização automática para maiúsculas, UNIQUE(coupon_id, user_id) previne resgate duplo, expirado/esgotado/duplicata → 409, 26 testes / 50 asserções PASS.

Este guia mostra como construir um sistema de cupons onde admins criam códigos de desconto e usuários os resgatam sujeitos a limites de uso e datas de expiração.

## Schema

```sql
CREATE TABLE IF NOT EXISTS coupons (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    code        TEXT    NOT NULL UNIQUE,
    discount    INTEGER NOT NULL,          -- em centavos, ex.: 500 = R$5,00
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

CREATE INDEX IF NOT EXISTS idx_coupons_code ON coupons (code);
```

`UNIQUE(coupon_id, user_id)` previne que o mesmo usuário resgate o mesmo cupom duas vezes. O índice em `code` acelera buscas pelo código.

## Endpoints

| Método | Caminho | Auth | Descrição |
|--------|---------|------|-----------|
| `POST` | `/coupons` | `X-Admin-Key` | Criar cupom (somente admin) |
| `GET` | `/coupons/{code}` | — | Obter detalhes do cupom |
| `POST` | `/coupons/{code}/redeem` | `X-User-Id` | Resgatar cupom |
| `GET` | `/coupons/{code}/redemptions` | `X-Admin-Key` | Listar resgates (somente admin) |

## Autenticação Admin — hash_equals

```php
private function isAdmin(ServerRequestInterface $req): bool
{
    if ($this->adminKey === '') {
        return false;
    }
    return hash_equals($this->adminKey, $req->getHeaderLine('X-Admin-Key'));
}
```

`hash_equals()` previne ataques de timing-side-channel na comparação de chaves. Se `adminKey` for string vazia (mal configurada), `isAdmin()` retorna false — falha fechada.

## Formato do Código de Cupom — CODE_PATTERN

```php
private const string CODE_PATTERN = '/\A[A-Z0-9]{4,32}\z/';
```

- Apenas alfanumérico maiúsculo
- 4–32 caracteres
- Âncoras `\A` / `\z` (correspondência com string inteira, não apenas substring)

Códigos de entrada são normalizados para maiúsculas antes da validação:

```php
$code = strtoupper(trim((string) ($body['code'] ?? '')));
if ($code === '') {
    // Gerar automaticamente se não fornecido
    $code = strtoupper(bin2hex(random_bytes(6)));
}
if (!preg_match(self::CODE_PATTERN, $code)) {
    return $this->problem(422, 'validation-failed', 'code must be 4–32 uppercase alphanumeric chars.');
}
```

Um usuário enviando `"summer50"` obtém o mesmo cupom que `"SUMMER50"` — o sistema normaliza para maiúsculas automaticamente. `pathCode()` também normaliza parâmetros de caminho para maiúsculas, então `GET /coupons/summer50` e `GET /coupons/SUMMER50` resolvem para o mesmo cupom.

## Validação de Criação de Cupom

```php
$discount = $body['discount'] ?? null;
if (!is_int($discount) || $discount < 1 || $discount > 10000) {
    return $this->problem(422, 'validation-failed', 'discount must be integer 1–10000 (cents).');
}

$maxUses = $body['max_uses'] ?? 1;
if (!is_int($maxUses) || $maxUses < 1 || $maxUses > 100000) {
    return $this->problem(422, 'validation-failed', 'max_uses must be integer 1–100000.');
}

if (!preg_match(self::ISO_DATE_PATTERN, $expiresAt)) {
    return $this->problem(422, 'validation-failed', 'expires_at must be ISO 8601 datetime.');
}
```

- `discount`: `is_int()` estrito — floats como `9.99` são rejeitados
- `max_uses`: padrão `1` se não fornecido
- `expires_at`: deve corresponder ao prefixo ISO 8601 `\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}`

## Resgatar — Quatro Modos de Falha

```php
$result = $this->repo->redeem($code, $uid);

return match ($result) {
    'not_found'        => $this->problem(404, 'not-found', 'Coupon not found.'),
    'expired'          => $this->problem(409, 'conflict', 'Coupon has expired.'),
    'exhausted'        => $this->problem(409, 'conflict', 'Coupon usage limit reached.'),
    'already_redeemed' => $this->problem(409, 'conflict', 'You have already redeemed this coupon.'),
    default            => $this->json(['message' => 'Coupon redeemed successfully.']),
};
```

Todas as falhas de regras de negócio retornam **409 Conflict** (não 422). A expressão `match` é exaustiva — o branch padrão só é acionado com uma string `'redeemed'` bem-sucedida do repositório.

## Validação do ID do Usuário

```php
private function uid(ServerRequestInterface $req): ?int
{
    $raw = $req->getHeaderLine('X-User-Id');
    if ($raw === '' || !ctype_digit($raw) || strlen($raw) > 18) {
        return null;
    }
    $id = (int) $raw;
    return $id > 0 ? $id : null;
}
```

- `ctype_digit()` — apenas strings de dígitos puros aceitas (sem `-`, `+`, espaços)
- `strlen > 18` — previne overflow de inteiro em PHP 64-bit (`PHP_INT_MAX` tem 19 dígitos)
- `$id > 0` — ID zero não é válido

Retorna `null` → 400 Bad Request se o header estiver ausente ou malformado.

## UNIQUE(coupon_id, user_id) — Resgate Idempotente

A constraint do BD previne o duplo-resgate no nível de armazenamento. A aplicação também verifica via repositório antes de inserir, retornando `'already_redeemed'` em vez de depender de uma exceção do BD.

Múltiplos usuários diferentes podem resgatar o mesmo cupom (até `max_uses`). Apenas o mesmo usuário tentando o mesmo cupom duas vezes é bloqueado.

---

## O Que NÃO Fazer

| Anti-padrão | Risco |
|---|---|
| `==` simples para comparação de chave admin | Timing attack revela comprimento/correspondências parciais da chave |
| `adminKey` vazio permite acesso admin | Chave admin mal configurada vira acesso aberto — falhe fechado |
| Lookup de código sensível a maiúsculas | `"summer50"` e `"SUMMER50"` tratados como cupons diferentes |
| `discount` sem `is_int()` | Float `9.99` aceito; centavos fracionários corrompem o ledger |
| 422 para expirado/esgotado | Esses são conflitos de estado de negócio, não erros de validação — use 409 |
| Sem UNIQUE(coupon_id, user_id) | Condição de corrida permite ao mesmo usuário resgatar duas vezes de forma concorrente |
| Sem limite superior em `max_uses` | Atacante cria cupom com `max_uses: 999999999` para desconto efetivamente ilimitado |
| Ignorar `strlen > N` no ID de usuário | Strings inteiras muito grandes causam overflow silencioso no cast `(int)` |
| Sem índice na coluna `code` | Varredura completa da tabela em cada lookup de cupom |
| Retornar lista de resgates a não-admin | Revela quais IDs de usuário resgataram — vazamento de privacidade |
