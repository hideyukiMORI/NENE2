# Como Fazer: Gerenciamento de Check-out / Check-in de Ativos

Demonstra rastreamento exclusivo de posse de ativos com um log de auditoria somente de acréscimo.
Field trial: FT194 (`../NENE2-FT/assetlog/`).

---

## Resumo do padrão

| Preocupação | Abordagem |
|---|---|
| Posse exclusiva | `holder_id INTEGER` — NULL = disponível, não nulo = em posse |
| Conflito de checkout | 409 se `holder_id IS NOT NULL` antes da atualização |
| Check-in por não proprietário | 403 se `holder_id != userId` |
| Log de auditoria | Linhas `asset_history` somente de acréscimo em cada mudança de estado |
| Prevenção IDOR | API pública oculta `holder_id`; chave admin necessária para visualizá-lo |
| Chave admin | Comparação em tempo constante com `hash_equals()`, fail-closed para chave vazia |
| Identidade do usuário | Cabeçalho `X-User-Id`; guarda com `ctype_digit()` + tamanho, sem regex |

---

## Rotas

| Método | Caminho | Auth | Descrição |
|---|---|---|---|
| `POST` | `/assets` | `X-Admin-Key` | Criar ativo |
| `GET` | `/assets` | — | Listar todos os ativos |
| `GET` | `/assets/{id}` | — | Obter ativo único |
| `POST` | `/assets/{id}/checkout` | `X-User-Id` | Fazer check-out do ativo |
| `POST` | `/assets/{id}/checkin` | `X-User-Id` | Fazer check-in do ativo |
| `GET` | `/assets/{id}/history` | — | Histórico de auditoria |

---

## Schema do banco de dados

```sql
CREATE TABLE assets (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT    NOT NULL,
    holder_id  INTEGER,           -- NULL = disponível
    created_at TEXT    NOT NULL,
    updated_at TEXT    NOT NULL
);

CREATE TABLE asset_history (
    id       INTEGER PRIMARY KEY AUTOINCREMENT,
    asset_id INTEGER NOT NULL,
    user_id  INTEGER NOT NULL,
    action   TEXT    NOT NULL,   -- 'checkout' | 'checkin'
    acted_at TEXT    NOT NULL,
    FOREIGN KEY (asset_id) REFERENCES assets(id) ON DELETE CASCADE
);
```

---

## Padrão de checkout exclusivo

```php
public function checkout(int $assetId, int $userId): string
{
    $asset = $this->findById($assetId);
    if ($asset === null) return 'not_found';
    if (!$asset->isAvailable()) return 'unavailable';   // 409

    $now = $this->now();
    $this->pdo->prepare(
        'UPDATE assets SET holder_id = :uid, updated_at = :now WHERE id = :id AND holder_id IS NULL'
    )->execute([...]);

    $this->appendHistory($assetId, $userId, 'checkout', $now);
    return 'success';
}
```

A guarda `WHERE holder_id IS NULL` previne checkout duplo mesmo sob requisições concorrentes
(SQLite serializa escritas; MySQL/PgSQL precisam de uma transação ou `SELECT FOR UPDATE`).

---

## Prevenção IDOR

```php
// Resposta pública — sem holder_id
public function toPublicArray(): array
{
    return ['id' => $this->id, 'name' => $this->name, 'available' => $this->isAvailable(), ...];
}

// Resposta admin — inclui holder_id
public function toAdminArray(): array
{
    return [..., 'holder_id' => $this->holderId];
}
```

O handler verifica `isAdmin()` e escolhe a projeção correta:

```php
fn (Asset $a) => $isAdmin ? $a->toAdminArray() : $a->toPublicArray()
```

---

## Chave admin (fail-closed)

```php
private function isAdmin(ServerRequestInterface $request): bool
{
    if ($this->adminKey === '') return false;   // sem chave configurada → negar
    $provided = $request->getHeaderLine('X-Admin-Key');
    return $provided !== '' && hash_equals($this->adminKey, $provided);
}
```

---

## Validação de ID de usuário

```php
private function resolveUserId(ServerRequestInterface $request): ?int
{
    $raw = $request->getHeaderLine('X-User-Id');
    if ($raw === '' || !ctype_digit($raw) || strlen($raw) > 18) return null;
    $id = (int) $raw;
    return $id > 0 ? $id : null;
}
```

`ctype_digit()` é O(n) e seguro contra ReDoS. O limite de tamanho previne overflow de inteiro.

---

## Mapeamento de erros

| Resultado do repositório | Status HTTP |
|---|---|
| `success` | 200 / 201 |
| `not_found` | 404 |
| `unavailable` | 409 Conflict |
| `not_holder` | 403 Forbidden |
| `already_available` | 409 Conflict |

---

## Notas de teste

- `AppFactory::create(?PDO, ?string)` aceita SQLite em memória para testes unitários.
- `withParsedBody($body)` deve ser chamado em requisições de teste — o Nyholm PSR-7 não analisa JSON automaticamente.
- Asserções de listagem/obtenção pública verificam que a chave `holder_id` está ausente (`assertArrayNotHasKey`).
- Teste de ciclo de vida: checkout → conflito → checkin → re-checkout por usuário diferente.
