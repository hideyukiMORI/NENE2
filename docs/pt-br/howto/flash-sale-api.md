# Como Fazer: API de Flash Sale

> **Referência FT**: FT304 (`NENE2-FT/salelog`) — API de flash sale: validação de janela de tempo (venda não iniciada → 422, encerrada → 422), UNIQUE(sale_id, user_id) previne compra dupla, verificação de estoque esgotado, preço negativo/quantidade zero → 422, datas invertidas rejeitadas, ATK-01〜12 todos BLOCKED, 29 testes / 42 asserções PASS.

Este guia mostra como construir um sistema de flash sale onde usuários compram produtos com estoque limitado dentro de uma janela de tempo, com proteção contra condições de corrida e prevenção de ataques.

## Schema

```sql
CREATE TABLE flash_sales (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    product_id INTEGER NOT NULL,
    price      INTEGER NOT NULL,
    quantity   INTEGER NOT NULL,
    starts_at  TEXT    NOT NULL,
    ends_at    TEXT    NOT NULL,
    created_at TEXT    NOT NULL,
    CHECK (quantity > 0),
    CHECK (price >= 0),
    FOREIGN KEY (product_id) REFERENCES products(id)
);

CREATE TABLE purchases (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    sale_id      INTEGER NOT NULL,
    user_id      INTEGER NOT NULL,
    purchased_at TEXT    NOT NULL,
    UNIQUE (sale_id, user_id),
    FOREIGN KEY (sale_id) REFERENCES flash_sales(id),
    FOREIGN KEY (user_id) REFERENCES users(id)
);
```

`CHECK (quantity > 0)` e `CHECK (price >= 0)` aplicam regras de negócio no nível do BD. `UNIQUE(sale_id, user_id)` previne que o mesmo usuário compre a mesma venda duas vezes — mesmo sob requisições concorrentes.

## Endpoints

| Método | Caminho | Auth | Descrição |
|--------|---------|------|-----------|
| `POST` | `/products` | — | Criar produto |
| `POST` | `/sales` | — | Criar flash sale |
| `GET` | `/sales` | — | Listar vendas ativas |
| `GET` | `/sales/{id}` | — | Obter detalhes da venda |
| `POST` | `/sales/{id}/purchase` | `X-User-Id` | Comprar (verificação de tempo) |

## Validação na Criação de Venda

```php
if (!is_int($price) || $price < 0) {
    return 422; // preço negativo rejeitado
}
if (!is_int($quantity) || $quantity <= 0) {
    return 422; // quantidade zero ou negativa rejeitada
}
if ($endsAt <= $startsAt) {
    return 422; // datas invertidas ou iguais rejeitadas
}
```

Três verificações no nível do BD respaldadas por validação no nível de aplicação:
- `price >= 0` — vendas gratuitas permitidas (`0`), preços negativos não
- `quantity > 0` — vendas com quantidade zero não podem ser criadas
- `ends_at > starts_at` — inversão de tempo rejeitada

## Compra — Verificação da Janela de Tempo

```php
$now = date('c');
if ($now < $sale['starts_at']) {
    return 422; // venda ainda não iniciou
}
if ($now > $sale['ends_at']) {
    return 422; // venda encerrou
}
```

Tentativas de compra fora da janela da venda retornam 422. A verificação usa `date('c')` no servidor — clientes não podem manipular o tempo.

## Verificação de Estoque

```php
$purchaseCount = $this->repo->countPurchases($saleId);
if ($purchaseCount >= $sale['quantity']) {
    return $this->json(['error' => 'sold out'], 422);
}
```

Contar compras existentes contra a `quantity` da venda antes de inserir. Se esgotado, retornar 422 com `"error": "sold out"`.

## UNIQUE(sale_id, user_id) — Prevenção de Compra Dupla

```php
// Constraint UNIQUE captura compras duplicadas concorrentes
try {
    $this->repo->createPurchase($saleId, $userId, $now);
} catch (\PDOException $e) {
    // Violação de constraint UNIQUE → já comprou
    return $this->json(['error' => 'already purchased'], 409);
}
```

A constraint `UNIQUE(sale_id, user_id)` do BD é a guarda final contra condições de corrida. A primeira compra tem sucesso (201); qualquer duplicata retorna 409 Conflict.

## Validação do ID de Usuário

```php
$actorIdRaw = $request->getHeaderLine('X-User-Id');
if ($actorIdRaw === '' || !ctype_digit($actorIdRaw)) {
    return $this->json(['error' => 'X-User-Id required'], 400);
}
$actorId = (int) $actorIdRaw;

$user = $this->repo->findUser($actorId);
if ($user === null) {
    return $this->json(['error' => 'user not found'], 404);
}
```

- `X-User-Id` ausente ou não numérico → 400
- User ID inexistente → 404 (prevenção de IDOR — não pode comprar como usuário fantasma)

---

## Avaliação ATK — Teste de Ataque com Mentalidade de Cracker

### ATK-01 — SQL Injection no Nome do Produto 🚫 BLOCKED

**Ataque**: `POST /products` com `name: "'; DROP TABLE products; --"`.
**Resultado**: BLOCKED — query parametrizada armazena a string de injeção verbatim (201). Requisições subsequentes ainda funcionam; tabela products intacta.

---

### ATK-02 — Compra Sem Header X-User-Id 🚫 BLOCKED

**Ataque**: `POST /sales/{id}/purchase` sem header `X-User-Id`.
**Resultado**: BLOCKED — header ausente retorna 400.

---

### ATK-03 — Header X-User-Id Não Numérico 🚫 BLOCKED

**Ataque**: `X-User-Id: admin` (valor string).
**Resultado**: BLOCKED — verificação `ctype_digit()` rejeita valores não numéricos; não retorna 201.

---

### ATK-04 — Sale ID Negativo na URL 🚫 BLOCKED

**Ataque**: `POST /sales/-1/purchase`.
**Resultado**: BLOCKED — ID negativo resolve para venda não encontrada; não retorna 201.

---

### ATK-05 — Compra Antes do Início da Venda 🚫 BLOCKED

**Ataque**: Criar uma venda que começa 1 hora no futuro; tentar comprar imediatamente.
**Resultado**: BLOCKED — verificação `$now < $sale['starts_at']` → 422.

---

### ATK-06 — Compra Após Encerramento da Venda 🚫 BLOCKED

**Ataque**: Criar uma venda que encerrou 1 hora atrás; tentar comprar.
**Resultado**: BLOCKED — verificação `$now > $sale['ends_at']` → 422.

---

### ATK-07 — Compra Dupla da Mesma Venda 🚫 BLOCKED

**Ataque**: Mesmo usuário compra a mesma venda duas vezes em rápida sucessão.
**Resultado**: BLOCKED — primeira compra 201; segunda compra 409 (constraint UNIQUE ou verificação no nível de aplicação).

---

### ATK-08 — Esgotar Estoque e Tentar Comprar 🚫 BLOCKED

**Ataque**: Criar venda com `quantity=1`; Alice compra; Bob tenta comprar.
**Resultado**: BLOCKED — verificação de estoque `purchaseCount >= quantity` → 422 `"sold out"` para Bob.

---

### ATK-09 — Criar Venda Com quantity=0 🚫 BLOCKED

**Ataque**: `POST /sales` com `quantity: 0`.
**Resultado**: BLOCKED — validação `quantity <= 0` + `CHECK (quantity > 0)` do BD → 422.

---

### ATK-10 — Criar Venda Com Preço Negativo 🚫 BLOCKED

**Ataque**: `POST /sales` com `price: -999`.
**Resultado**: BLOCKED — validação `price < 0` + `CHECK (price >= 0)` do BD → 422.

---

### ATK-11 — Comprar Como Usuário Inexistente 🚫 BLOCKED

**Ataque**: `X-User-Id: 99999` (ID que não existe na tabela users).
**Resultado**: BLOCKED — `findUser($actorId) === null` → 404.

---

### ATK-12 — Datas de Venda Invertidas (ends_at antes de starts_at) 🚫 BLOCKED

**Ataque**: `starts_at: "+2 hours"`, `ends_at: "+1 hour"`.
**Resultado**: BLOCKED — validação `$endsAt <= $startsAt` → 422.

---

### Resumo ATK

| ID | Ataque | Resultado |
|----|--------|-----------|
| ATK-01 | SQL injection no nome do produto | 🚫 BLOCKED |
| ATK-02 | Compra sem X-User-Id | 🚫 BLOCKED |
| ATK-03 | X-User-Id não numérico | 🚫 BLOCKED |
| ATK-04 | Sale ID negativo na URL | 🚫 BLOCKED |
| ATK-05 | Compra antes do início da venda | 🚫 BLOCKED |
| ATK-06 | Compra após encerramento da venda | 🚫 BLOCKED |
| ATK-07 | Compra dupla da mesma venda | 🚫 BLOCKED |
| ATK-08 | Esgotar estoque e tentar comprar | 🚫 BLOCKED |
| ATK-09 | Criar venda com quantity=0 | 🚫 BLOCKED |
| ATK-10 | Criar venda com preço negativo | 🚫 BLOCKED |
| ATK-11 | Comprar como usuário inexistente | 🚫 BLOCKED |
| ATK-12 | Datas de venda invertidas | 🚫 BLOCKED |

**12 BLOCKED, 0 EXPOSED**
Verificação de janela de tempo no servidor, guarda de contagem de estoque, constraint UNIQUE e validação estrita de entrada previnem todos os vetores de ataque conhecidos.

---

## O Que NÃO Fazer

| Anti-padrão | Risco |
|---|---|
| Confiar no timestamp fornecido pelo cliente para verificação de tempo | Clientes enviam timestamps passados/futuros para bypass da janela |
| Sem `UNIQUE(sale_id, user_id)` | Requisições concorrentes permitem que o mesmo usuário compre duas vezes sob carga |
| Verificar estoque sem guarda contra condição de corrida | Entre verificação de estoque e inserção, outra requisição pode esgotar o estoque |
| Aceitar criação de venda com `quantity: 0` | Venda com quantidade zero nunca pode ser comprada; caso extremo confuso |
| Aceitar `price: -999` | Compra com preço negativo credita o comprador em vez de cobrar |
| Sem verificação de existência do usuário | IDs de usuário fantasma (não no BD) bypassam trilhas de auditoria |
| `$endsAt >= $startsAt` (permitir iguais) | Início/fim iguais criam janela de duração zero — expirada imediatamente |
| X-User-Id não numérico aceito | String `"admin"` convertida para `(int)` vira `0`; bypassa autenticação |
| Retornar 409 para erros de janela de tempo | Violações de tempo são falhas de validação de negócio (422), não conflitos de estado |
