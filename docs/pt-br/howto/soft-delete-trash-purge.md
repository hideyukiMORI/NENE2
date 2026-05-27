# Como Fazer: Soft Delete, Lixeira e Purga Permanente

> **Referência FT**: FT257 (`NENE2-FT/softdeletelog`) — Padrão de soft delete / lixeira / purga permanente com coluna `deleted_at`

Demonstra um ciclo de vida de três estágios para registros: ativo → soft-deleted (lixeira) → purgado permanentemente. Listas ativas excluem registros deletados automaticamente. Um endpoint dedicado de lixeira lista apenas registros deletados. Restauração retorna um registro da lixeira para ativo. Purga remove fisicamente o registro do banco de dados (permitido apenas enquanto na lixeira).

---

## Rotas

| Método   | Caminho                 | Descrição                                       |
|----------|-------------------------|-------------------------------------------------|
| `POST`   | `/notes`                | Criar uma nota                                  |
| `GET`    | `/notes`                | Listar notas ativas (exclui soft-deleted)       |
| `GET`    | `/notes/trash`          | Listar apenas notas na lixeira                  |
| `GET`    | `/notes/{id}`           | Obter uma única nota ativa                      |
| `DELETE` | `/notes/{id}`           | Soft-delete uma nota (move para lixeira)        |
| `POST`   | `/notes/{id}/restore`   | Restaurar da lixeira para ativo                 |
| `DELETE` | `/notes/{id}/purge`     | Excluir permanentemente (apenas da lixeira)     |

> **Ordem das rotas**: `/notes/trash` deve ser registrado antes de `/notes/{id}` para que o segmento literal `trash` não seja capturado como parâmetro de caminho.

---

## Schema

```sql
CREATE TABLE IF NOT EXISTS notes (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    title      TEXT NOT NULL,
    body       TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    deleted_at TEXT NULL
);
```

`deleted_at TEXT NULL` é o marcador de soft-delete. Quando `NULL` o registro está ativo; quando definido como um timestamp ISO o registro está na lixeira. Não é necessário um booleano `is_deleted` separado — o timestamp também registra _quando_ a exclusão ocorreu, o que é útil para trilhas de auditoria e jobs de purga baseados em TTL.

---

## Objeto de Domínio

```php
final readonly class Note
{
    public function __construct(
        public int     $id,
        public string  $title,
        public string  $body,
        public string  $createdAt,
        public string  $updatedAt,
        public ?string $deletedAt,     // null = ativo, não-null = na lixeira
    ) {}

    public function isDeleted(): bool
    {
        return $this->deletedAt !== null;
    }
}
```

`isDeleted()` encapsula a verificação de null para que os chamadores não precisem conhecer o detalhe de implementação.

---

## Repository: a flag `includeTrashed`

```php
public function findById(int $id, bool $includeTrashed = false): ?Note
{
    $sql = $includeTrashed
        ? 'SELECT * FROM notes WHERE id = ?'
        : 'SELECT * FROM notes WHERE id = ? AND deleted_at IS NULL';

    $rows = $this->executor->fetchAll($sql, [$id]);
    return $rows === [] ? null : $this->hydrate($rows[0]);
}
```

O padrão (`includeTrashed: false`) aplica o filtro `deleted_at IS NULL` para que os chamadores obtenham o comportamento seguro automaticamente. Apenas restauração e purga precisam ver registros na lixeira e passam `includeTrashed: true` explicitamente.

**Por que não um método separado `findByIdIncludingTrashed()`?**

Um parâmetro booleano nomeado é autodocumentado no local de chamada:
- `findById($id)` — claramente somente ativo
- `findById($id, includeTrashed: true)` — claramente ciente da lixeira

Um método separado duplicaria a lógica de hidratação ou exigiria um helper interno compartilhado.

---

## Listagem: ativo vs lixeira

```php
public function listActive(): array
{
    return $this->executor->fetchAll(
        'SELECT * FROM notes WHERE deleted_at IS NULL ORDER BY created_at DESC',
        [],
    );
}

public function listTrashed(): array
{
    return $this->executor->fetchAll(
        'SELECT * FROM notes WHERE deleted_at IS NOT NULL ORDER BY deleted_at DESC',
        [],
    );
}
```

Notas ativas são ordenadas por tempo de criação (mais recentes primeiro). Notas na lixeira são ordenadas por tempo de exclusão (excluídas mais recentemente primeiro), o que é natural para uma UI de "excluídos recentemente".

---

## Soft Delete

```php
public function softDelete(int $id, string $now): ?Note
{
    $note = $this->findById($id);   // busca somente ativo
    if ($note === null) {
        return null;   // não encontrado OU já na lixeira → 404
    }

    $this->executor->execute(
        'UPDATE notes SET deleted_at = ? WHERE id = ?',
        [$now, $id],
    );

    return new Note($note->id, $note->title, $note->body, $note->createdAt, $note->updatedAt, $now);
}
```

`findById($id)` sem `includeTrashed` significa que chamar `DELETE /notes/{id}` em uma nota já na lixeira retorna `null` → 404. Isso previne confusão de double-delete: um cliente não pode saber por um 404 se a nota estava ativa e ausente, ou já na lixeira.

---

## Restaurar

```php
public function restore(int $id): ?Note
{
    $note = $this->findById($id, includeTrashed: true);
    if ($note === null || !$note->isDeleted()) {
        return null;   // não encontrado OU já ativo → 404
    }

    $this->executor->execute(
        'UPDATE notes SET deleted_at = NULL WHERE id = ?',
        [$id],
    );

    return new Note($note->id, $note->title, $note->body, $note->createdAt, $note->updatedAt, null);
}
```

`includeTrashed: true` é necessário aqui — a nota ESTÁ deletada, então o filtro padrão a ocultaria. A guarda `!$note->isDeleted()` rejeita uma nota ativa: chamar restore em uma nota ativa retorna `null` → 404. Isso torna a restauração idempotente no caminho "já restaurada": um cliente que chama restore duas vezes obtém 200 na primeira chamada e 404 na segunda.

---

## Purga (exclusão permanente)

```php
public function purge(int $id): bool
{
    $note = $this->findById($id, includeTrashed: true);
    if ($note === null || !$note->isDeleted()) {
        return false;   // não encontrado OU ainda ativo → 404
    }

    $this->executor->execute('DELETE FROM notes WHERE id = ?', [$id]);
    return true;
}
```

`purge()` funciona apenas em registros na lixeira (`isDeleted()` deve ser true). Chamar `DELETE /notes/{id}/purge` em uma nota ativa retorna `false` → 404. Isso protege contra a destruição acidental de dados via endpoint errado — um cliente deve fazer soft-delete explicitamente antes de poder purgar.

---

## Máquina de Estado

```
           POST /notes
               │
               ▼
           [ativo]  ←──────── POST /notes/{id}/restore ────────┐
               │                                                  │
    DELETE /notes/{id}                                           │
               │                                                  │
               ▼                                                  │
           [lixeira]  ──────────────────────────────────────────┘
               │
    DELETE /notes/{id}/purge
               │
               ▼
          [desaparecido — DELETE físico]
```

`ativo → lixeira` é reversível. `lixeira → desaparecido` é irreversível. Não há caminho direto de `ativo → desaparecido`: purga requer um passo de soft-delete anterior.

---

## Controller: ordem de registro de rotas

```php
public function register(Router $router): void
{
    $router->post('/notes',              $this->create(...));
    $router->get('/notes',               $this->listActive(...));
    $router->get('/notes/trash',         $this->listTrashed(...));   // ← deve vir antes de {id}
    $router->get('/notes/{id}',          $this->get(...));
    $router->delete('/notes/{id}',       $this->softDelete(...));
    $router->post('/notes/{id}/restore', $this->restore(...));
    $router->delete('/notes/{id}/purge', $this->purge(...));
}
```

`/notes/trash` deve ser registrado antes de `/notes/{id}`. Se a ordem fosse invertida, uma requisição `GET /notes/trash` corresponderia a `{id}` com `id = "trash"`, falharia na conversão para inteiro e retornaria 404 ou 200 com corpo vazio em vez da lista da lixeira.

---

## Semântica HTTP

| Ação         | Método   | Motivo                                                             |
|--------------|----------|--------------------------------------------------------------------|
| Soft delete  | `DELETE` | Cliente pretende remover o recurso de sua visão                   |
| Restaurar    | `POST`   | Não é idempotente (segunda chamada retorna 404); `POST` é adequado |
| Purgar       | `DELETE` | Cliente pretende remoção permanente                               |

`PATCH /notes/{id}` com `{"deleted_at": null}` é uma alternativa para restaurar, mas `POST /restore` é mais explícito e evita vazar o nome interno da coluna no contrato da API.

---

## Comparação de Design

| Abordagem | Filtro ativo | Marcador de exclusão | Restaurar | Purgar |
|---|---|---|---|---|
| Timestamp `deleted_at` | `WHERE deleted_at IS NULL` | Timestamp + trilha de auditoria | `SET deleted_at = NULL` | `DELETE` físico |
| Booleano `is_deleted` | `WHERE is_deleted = 0` | Somente booleano | `SET is_deleted = 0` | `DELETE` físico |
| Tabela `deleted_notes` separada | Sem filtro necessário | Mover linha para outra tabela | Mover linha de volta | Delete de `deleted_notes` |

`deleted_at` é o padrão mais comum: uma coluna, mudança mínima no schema, e um timestamp de auditoria integrado sem custo extra.

---

## Howtos Relacionados

- [`article-versioning-api.md`](article-versioning-api.md) — histórico de versão para conteúdo (padrão de trilha de auditoria)
- [`mass-assignment-defence.md`](mass-assignment-defence.md) — whitelist explícita de DTO para prevenir injeção de campo
- [`transaction-scope-pattern.md`](transaction-scope-pattern.md) — operações multi-escrita atômicas
