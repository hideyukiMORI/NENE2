# Gerenciamento de Lista de Desejos

Guia de implementação de lista de desejos com prioridade e notas.
Explica o padrão de ocultação de existência, adição idempotente e múltiplos parâmetros de caminho.

## Visão Geral

- Usuários criam listas de desejos com nome (pública/privada)
- Cada lista de desejos recebe produtos (com `priority`: high/medium/low e `note` opcional)
- Listas de desejos privadas retornam 404 para não-donos (padrão de ocultação de existência)
- Adição de produto é idempotente (200 se já existe, 201 se novo)
- Sem ordenação (sem gerenciamento de posição) — principal diferença do Content Collection (FT149)

## Endpoints

| Método | Caminho | Descrição |
|---|---|---|
| `POST` | `/wishlists` | Criar lista de desejos |
| `GET` | `/wishlists/{id}` | Obter lista de desejos (pública ou própria) |
| `PUT` | `/wishlists/{id}` | Alterar nome e configuração de privacidade |
| `DELETE` | `/wishlists/{id}` | Deletar lista de desejos |
| `POST` | `/wishlists/{id}/items` | Adicionar produto (idempotente) |
| `DELETE` | `/wishlists/{id}/items/{productId}` | Remover produto |

## Design do Banco de Dados

```sql
CREATE TABLE wishlists (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    name TEXT NOT NULL,
    is_public INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

CREATE TABLE wishlist_items (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    wishlist_id INTEGER NOT NULL,
    product_id INTEGER NOT NULL,
    priority TEXT NOT NULL DEFAULT 'medium',
    note TEXT,
    added_at TEXT NOT NULL,
    UNIQUE (wishlist_id, product_id),
    CHECK (priority IN ('high', 'medium', 'low')),
    FOREIGN KEY (wishlist_id) REFERENCES wishlists(id),
    FOREIGN KEY (product_id) REFERENCES products(id)
);
```

Ao contrário do Content Collection (FT149), não há coluna `position`.
`UNIQUE (wishlist_id, product_id)` é a defesa no nível do banco para adição idempotente.

## Padrão de Ocultação de Existência

```php
$isOwner = $actorId !== null && (int) $wishlist['user_id'] === $actorId;
$isPublic = (bool) $wishlist['is_public'];

if (!$isOwner && !$isPublic) {
    return $this->responseFactory->create(['error' => 'wishlist not found'], 404);
}
```

Apenas GET retorna 404. PUT/DELETE/POST items retornam 403 para informar ao dono que não tem permissão.

## Adição Idempotente de Item

```php
$existing = $this->repository->findItem($id, $productId);
if ($existing !== null) {
    return $this->responseFactory->create([
        'message' => 'already in wishlist',
        'product_id' => $productId,
        'priority' => $existing['priority'],
        'note' => $existing['note'],
    ], 200);
}
$now = date('c');
$this->repository->addItem($id, $productId, $priority, $note, $now);
return $this->responseFactory->create([...], 201);
```

## Validação de priority (valores inválidos fazem fallback para padrão)

```php
private const array VALID_PRIORITIES = ['high', 'medium', 'low'];

$priority = isset($body['priority']) && is_string($body['priority'])
    && in_array($body['priority'], self::VALID_PRIORITIES, true)
    ? $body['priority']
    : 'medium';
```

Valores de priority inválidos fazem fallback para `'medium'` em vez de erro.
Isso lida com segurança com valores de priority desconhecidos que o cliente pode enviar para compatibilidade futura.

## Exemplo de Resposta GET /wishlists/{id}

```json
{
  "id": 1,
  "user_id": 1,
  "name": "Lista de Aniversário",
  "is_public": true,
  "item_count": 2,
  "items": [
    {
      "product_id": 3,
      "product_name": "Fone de Ouvido Sem Fio",
      "priority": "high",
      "note": "Prefiro cor preta",
      "added_at": "2026-05-21T..."
    },
    {
      "product_id": 1,
      "product_name": "Caneca de Café",
      "priority": "low",
      "note": null,
      "added_at": "2026-05-21T..."
    }
  ],
  "created_at": "2026-05-21T...",
  "updated_at": "2026-05-21T..."
}
```

## Diferenças entre Collection e Lista de Desejos

| Aspecto | Collection (FT149) | Lista de Desejos (FT151) |
|---|---|---|
| Ordenação | Com gerenciamento de posição | Sem (ordem de adição) |
| Metadados do item | Nenhum | priority + note |
| Limite | 50 itens | Sem limite |
| Caso de uso | Lista de leitura, curadoria | Lista de desejos, registro de presentes |

## Padrão de Verificação de Propriedade

```php
if ((int) $wishlist['user_id'] !== $actorId) {
    return $this->responseFactory->create(['error' => 'access denied'], 403);
}
```

O mesmo padrão é usado em todos os endpoints PUT/DELETE/POST items.
