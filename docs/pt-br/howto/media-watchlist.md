# Como Fazer: API de Lista de Assistidos (Media Watchlist)

> **Referência FT**: FT59 (`NENE2-FT/watchlog`) — API de Lista de Assistidos

Demonstra uma lista de assistidos pessoal com enums string backed para status e tipo,
campos opcionais anuláveis usando `array_key_exists`, endpoints de ação para arquivar/restaurar
via POST, e avaliação de 1–5 por inteiro. Toda a validação de status e tipo usa
`BackedEnum::tryFrom()` do PHP para garantir que apenas valores conhecidos sejam aceitos.

---

## Rotas

| Método   | Caminho                      | Descrição                                        |
|----------|------------------------------|--------------------------------------------------|
| `GET`    | `/watch`                     | Listar entradas (filtradas e paginadas)          |
| `POST`   | `/watch`                     | Adicionar uma entrada à lista de assistidos      |
| `GET`    | `/watch/{id}`                | Obter uma única entrada                          |
| `PATCH`  | `/watch/{id}/status`         | Atualizar status (e opcionalmente nota/avaliação)|
| `POST`   | `/watch/{id}/archive`        | Mover entrada para o arquivo                     |
| `POST`   | `/watch/{id}/restore`        | Restaurar uma entrada arquivada                  |
| `DELETE` | `/watch/{id}`                | Excluir permanentemente uma entrada              |

---

## Validação com enum backed

Status e tipo de mídia são validados com `BackedEnum::tryFrom()`. O enum também
serve como tipo na serialização, então o valor string gravado no banco de dados e
o valor string na resposta JSON ficam sincronizados automaticamente.

```php
enum WatchStatus: string
{
    case WantToWatch = 'want-to-watch';
    case Watching    = 'watching';
    case Completed   = 'completed';
    case Dropped     = 'dropped';
}

enum MediaType: string
{
    case Movie = 'movie';
    case Tv    = 'tv';
}
```

No controller, `tryFrom()` retorna `null` para valores desconhecidos, o que resulta em 422:

```php
$statusRaw = isset($body['status']) && is_string($body['status']) ? $body['status'] : null;
$status    = $statusRaw !== null ? WatchStatus::tryFrom($statusRaw) : null;

if ($statusRaw === null) {
    $errors[] = new ValidationError('status', 'status is required.', 'required');
} elseif ($status === null) {
    $errors[] = new ValidationError('status', 'Invalid status value.', 'invalid_value');
}
```

A verificação em duas etapas distingue "campo ausente" (required) de "campo presente mas
inválido" (invalid_value), produzindo mensagens de erro mais claras.

---

## Listagem com filtros tipados por enum

Parâmetros de query são analisados via `QueryStringParser`, depois validados via `tryFrom()`:

```php
$statusRaw = QueryStringParser::string($request, 'status');   // null se ausente
$status    = $statusRaw !== null ? WatchStatus::tryFrom($statusRaw) : null;

if ($statusRaw !== null && $status === null) {
    $errors[] = new ValidationError('status', 'Invalid status value.', 'invalid_value');
}
```

Este padrão — analisar, tentar conversão de enum, validar — mantém a lógica de roteamento
fora do código de domínio. O repositório aceita `?WatchStatus` e `?MediaType` e
filtra de acordo.

**Filtros suportados**:
- `?status=watching` — filtrar por status
- `?media_type=movie` — filtrar por tipo de mídia
- `?include_archived=1` — incluir entradas arquivadas (excluídas por padrão)
- `?limit=20&offset=0` — paginação

---

## Campos anuláveis com `array_key_exists`

`rating` e `note` são anuláveis — os chamadores podem defini-los explicitamente como `null` para
limpá-los. Usar `isset()` perderia um `null` enviado explicitamente. Use `array_key_exists()`:

```php
// ✓ Correto: distingue ausente de explicitamente null
$rating = array_key_exists('rating', $body) ? $body['rating'] : null;

// ✗ Errado: array_key_exists($body, 'rating') ignora null intencional
if ($rating !== null) {
    if (!is_int($rating) || $rating < 1 || $rating > 5) {
        $errors[] = new ValidationError('rating', 'rating must be an integer from 1 to 5.', 'out_of_range');
    }
}
```

`is_int($rating)` rejeita floats JSON (`4.0` → PHP `float`) e strings (`"4"`).
Apenas um literal inteiro JSON (`4`) passa na verificação de tipo estrito.

---

## Arquivar / restaurar via endpoints de ação POST

Arquivar e restaurar são mutações (elas mudam o estado e registram um timestamp), portanto
usam `POST`, não `DELETE` ou `PATCH`. Isto segue o padrão de endpoint de ação:

```php
// POST /watch/{id}/archive
private function archive(ServerRequestInterface $request): ResponseInterface
{
    $id    = (int) ($request->getAttribute(Router::PARAMETERS_ATTRIBUTE)['id'] ?? 0);
    $entry = $this->repository->archive($id, (new \DateTimeImmutable())->format('Y-m-d\TH:i:s\Z'));

    return $this->json->create($this->serialize($entry));
}

// POST /watch/{id}/restore
private function restore(ServerRequestInterface $request): ResponseInterface
{
    $id    = (int) ($request->getAttribute(Router::PARAMETERS_ATTRIBUTE)['id'] ?? 0);
    $entry = $this->repository->restore($id, (new \DateTimeImmutable())->format('Y-m-d\TH:i:s\Z'));

    return $this->json->create($this->serialize($entry));
}
```

`archive()` define `archived_at` como o timestamp atual; `restore()` o define de volta para
`null`. O endpoint de listagem oculta entradas arquivadas por padrão (`include_archived=false`).

Por que `POST` e não `DELETE` para arquivar? `DELETE` implica remoção permanente. Arquivar
é uma mudança de estado suave — a entrada permanece no banco de dados e pode ser recuperada. Nomear os
endpoints de acordo com a ação (`/archive`, `/restore`) torna a intenção explícita.

---

## Schema: constraints CHECK correspondem aos valores do enum

```sql
CREATE TABLE watch_entries (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    title       TEXT NOT NULL,
    media_type  TEXT NOT NULL CHECK(media_type IN ('movie', 'tv')),
    status      TEXT NOT NULL DEFAULT 'want-to-watch'
                              CHECK(status IN ('want-to-watch', 'watching', 'completed', 'dropped')),
    rating      INTEGER CHECK(rating IS NULL OR (rating >= 1 AND rating <= 5)),
    note        TEXT NOT NULL DEFAULT '',
    created_at  TEXT NOT NULL,
    updated_at  TEXT NOT NULL,
    archived_at TEXT
);
```

As constraints `CHECK` do banco de dados espelham os casos do enum — se um novo status for adicionado ao enum
sem atualizar o `CHECK`, o insert falha na camada do banco de dados. Mantenha ambos em sincronia:
adicione o novo caso ao enum, ao `CHECK` e a qualquer migração.

`rating CHECK(rating IS NULL OR ...)` permite corretamente que a coluna seja `NULL` enquanto
ainda aplica o intervalo 1–5 quando um valor está presente.

`archived_at TEXT` (anulável) atua como flag de arquivamento: `NULL` = ativo,
não-nulo = arquivado. Este é o padrão mínimo de soft-archive — sem coluna
`is_archived BOOLEAN` separada necessária.

---

## Índices para desempenho de listagem

```sql
CREATE INDEX idx_watch_status      ON watch_entries (status);
CREATE INDEX idx_watch_archived_at ON watch_entries (archived_at);
```

`idx_watch_archived_at` suporta o filtro comum `WHERE archived_at IS NULL`
(entradas ativas). O SQLite pode usar este índice para condições `IS NULL` via padrão de índice parcial,
mas um índice simples é suficiente para a maioria das listas de assistidos.

---

## Serialização

```php
/** @return array<string, mixed> */
private function serialize(WatchEntry $entry): array
{
    return [
        'id'          => $entry->id,
        'title'       => $entry->title,
        'media_type'  => $entry->mediaType->value,  // enum → string
        'status'      => $entry->status->value,      // enum → string
        'rating'      => $entry->rating,             // int|null
        'note'        => $entry->note,
        'created_at'  => $entry->createdAt,
        'updated_at'  => $entry->updatedAt,
        'archived_at' => $entry->archivedAt,         // string|null
    ];
}
```

`->value` em um enum backed retorna o valor string do caso (por ex. `'want-to-watch'`).
Serialize enums desta forma em vez de chamar `->name` — o nome é o identificador PHP
(`WantToWatch`), não o valor do contrato da API.

---

## Howtos relacionados

- [`content-draft-lifecycle.md`](content-draft-lifecycle.md) — máquina de estados com transições de status
- [`soft-delete.md`](soft-delete.md) — soft delete com timestamp `deleted_at`
- [`implement-patch-endpoint.md`](implement-patch-endpoint.md) — atualizações parciais com `array_key_exists`
- [`add-custom-route.md`](add-custom-route.md) — padrão de endpoint de ação POST (`/archive`, `/restore`, `/publish`)
