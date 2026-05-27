# Como Fazer: API de Catálogo de Produtos (ATK-01~12)

Este guia demonstra uma API de catálogo de produtos com operações de escrita apenas para admin, busca por palavra-chave e exclusão suave — cobrindo os vetores de ataque cracker ATK-01~12.

## Visão Geral do Padrão

- Leituras do catálogo são públicas; escritas (criar, excluir) requerem admin (`X-Admin-Key`).
- SKUs são alfanuméricos maiúsculos com hífens (`/\A[A-Z0-9\-]{1,32}\z/`).
- Exclusão suave (`active = 0`) oculta produtos sem perder o histórico.
- Busca por palavra-chave usa `LIKE` com guarda de comprimento para prevenir keyword bombs.

## Schema

```sql
CREATE TABLE IF NOT EXISTS products (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    sku         TEXT    NOT NULL UNIQUE,
    name        TEXT    NOT NULL,
    description TEXT    NOT NULL DEFAULT '',
    price_cents INTEGER NOT NULL,
    active      INTEGER NOT NULL DEFAULT 1,
    created_at  TEXT    NOT NULL
);
```

## ATK-01: Injeção SQL na Palavra-Chave de Busca

```php
$kw   = '%' . $keyword . '%';
$stmt = $this->pdo->prepare(
    'SELECT * FROM products WHERE active = 1 AND (name LIKE :kw OR ...) LIMIT :lim OFFSET :off'
);
$stmt->bindValue(':kw', $kw, PDO::PARAM_STR);
```

O wildcard `%` faz parte do valor literal passado a uma query parametrizada — sem interpolação ocorre.

## ATK-02: Admin Fail-Closed

```php
private function isAdmin(ServerRequestInterface $req): bool
{
    if ($this->adminKey === '') {
        return false;
    }
    return hash_equals($this->adminKey, $req->getHeaderLine('X-Admin-Key'));
}
```

Chave admin vazia → sempre 403. Chave errada → `hash_equals()` evita vazamentos de timing.

## ATK-03: Overflow de Inteiro no ID do Produto

```php
if (!ctype_digit($raw) || strlen($raw) > 18) {
    return null;  // → 404
}
```

Uma string de ID com 20 dígitos excede 18 chars e é rejeitada antes de qualquer cast `(int)` ou query no banco.

## ATK-04: ID Negativo

`ctype_digit()` em `-1` falha (char não-dígito) → 404.

## ATK-05: Preço Float

```php
if (!is_int($priceCents) || $priceCents < 0) {
    return $this->problem(422, ...);
}
```

`is_int(9.99)` retorna `false` — preços float são rejeitados.

## ATK-06: Injeção de SKU

O regex de SKU `/\A[A-Z0-9\-]{1,32}\z/` rejeita `; DROP TABLE`, aspas, espaços e minúsculas. Apenas o formato exato é aceito.

## ATK-07: Injeção de Wildcard na Busca

`%` em uma palavra-chave de busca é tratado como wildcard SQL LIKE — corresponde a tudo. Isso é intencional (usuários podem buscar tudo). O LIKE é parametrizado então `%; DROP TABLE products; --` não é executado como SQL:

```sql
WHERE name LIKE '%%; DROP TABLE products; --%'
```

O resultado é apenas uma correspondência LIKE mais ampla, não uma injeção.

## ATK-08: Exclusão Dupla

O método `delete()` do repositório verifica `findById()` (apenas active=1) primeiro. Um produto soft-deleted retorna null → 404 na segunda exclusão.

## ATK-09: SKU Muito Longo

O quantificador do regex `{1,32}` rejeita SKUs com mais de 32 chars antes de chegar ao banco.

## ATK-10: Chave Admin Errada

A comparação `hash_equals()` sempre leva o mesmo tempo independente de quantos caracteres correspondem.

## Guarda de Comprimento de Palavra-Chave

```php
if ($keyword !== null && strlen($keyword) > 100) {
    return $this->problem(422, 'validation-failed', 'q too long (max 100).');
}
```

Previne enviar um padrão LIKE de 10 MB ao banco de dados.

## Exclusão Suave

```php
$this->pdo->prepare('UPDATE products SET active = 0 WHERE id = :id')->execute([':id' => $id]);
```

Todas as leituras incluem `WHERE active = 1`. Produtos excluídos tornam-se invisíveis sem remoção física.

## Rotas

```
POST   /products      Criar produto (apenas admin)
GET    /products      Listar/buscar produtos (público)
GET    /products/{id} Obter produto (público)
DELETE /products/{id} Soft-delete do produto (apenas admin)
```

## Veja Também

- Fonte FT212: `../NENE2-FT/productlog/`
- Relacionado: `docs/howto/inventory-management.md` (FT203, estoque baseado em SKU)
- Relacionado: `docs/howto/session-token-management.md` (FT208, também ATK)
