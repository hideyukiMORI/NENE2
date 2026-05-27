# Como Fazer: API de Analytics de Eventos

> **Referência FT**: FT51 (`NENE2-FT/statslog`) — API de Analytics de Eventos com filtragem de propriedades JSON e consultas de agregação

Demonstra uma API de rastreamento de eventos que armazena eventos de analytics com propriedades JSON arbitrárias
e expõe endpoints de agregação para contagens por dia, breakdowns por tipo e métricas de usuários únicos.
Padrões principais: filtragem de propriedades com `json_extract()`, agrupamento de datas com `strftime()`,
rotas estáticas antes das parametrizadas, e IDs de usuário do tipo string.

---

## Rotas

| Método | Caminho                      | Descrição                                           |
|--------|------------------------------|-----------------------------------------------------|
| `POST` | `/events`                    | Registrar um evento                                 |
| `GET`  | `/events`                    | Listar eventos (paginado)                           |
| `GET`  | `/events/by-property`        | Filtrar por chave/valor de propriedade JSON         |
| `GET`  | `/events/{id}`               | Obter um único evento                               |
| `GET`  | `/stats/per-day`             | Contagem de eventos por dia do calendário (`?from=&to=`) |
| `GET`  | `/stats/per-type`            | Contagem de eventos por tipo de evento (`?from=&to=`) |
| `GET`  | `/stats/unique-users`        | Contagem de usuários únicos por dia (`?from=&to=`) |

---

## Registrando eventos

```php
// POST /events
$body = [
    'event_type'  => 'page_view',          // obrigatório, string não vazia
    'user_id'     => 'usr_abc123',          // obrigatório, string (UUID ou ID opaco)
    'session_id'  => 'sess_xyz789',         // opcional
    'properties'  => ['path' => '/pricing', 'referrer' => 'google'],  // objeto opcional
    'occurred_at' => '2026-05-27T09:00:00Z', // opcional, ISO 8601 (padrão: hora do servidor)
];
```

`properties` é armazenado como uma string JSON. Na saída é decodificado de volta para um objeto:

```php
'properties' => json_decode($event->properties, true, 512, JSON_THROW_ON_ERROR),
```

Quando `occurred_at` é omitido, o servidor o preenche com o horário UTC atual:

```php
$occurredAt = isset($body['occurred_at']) && is_string($body['occurred_at'])
    ? $body['occurred_at']
    : (new \DateTimeImmutable())->format('Y-m-d\TH:i:s\Z');
```

---

## Ordenação de rotas: estáticas antes das parametrizadas

O roteador corresponde rotas na ordem de registro. Um caminho estático como `/events/by-property`
deve ser registrado **antes** do parametrizado `/events/{id}`, caso contrário o segmento
`by-property` seria capturado como `{id}`:

```php
public function register(Router $router): void
{
    $router->post('/events', $this->createEvent(...));
    $router->get('/events', $this->listEvents(...));

    // ✓ Rota estática primeiro — ou "by-property" é engolido por {id}
    $router->get('/events/by-property', $this->eventsByProperty(...));
    $router->get('/events/{id}', $this->showEvent(...));

    $router->get('/stats/per-day', $this->statsPerDay(...));
    $router->get('/stats/per-type', $this->statsPerType(...));
    $router->get('/stats/unique-users', $this->statsUniqueUsers(...));
}
```

**Regra**: sempre registre segmentos de caminho concretos antes de segmentos wildcard na
mesma profundidade.

---

## Filtragem de propriedades JSON com `json_extract()`

SQLite (≥ 3.38) e MySQL suportam `json_extract()` para consultar dentro de colunas JSON armazenadas.
A chave é passada como uma expressão JSONPath parametrizada:

```php
$rows = $this->executor->fetchAll(
    'SELECT * FROM events WHERE json_extract(properties, ?) = ? ORDER BY occurred_at DESC LIMIT ? OFFSET ?',
    ['$.' . $propertyKey, $propertyValue, $limit, $offset],
);
```

O prefixo JSONPath `$.` é acrescentado em PHP, então `key = "path"` fica
`json_extract(properties, '$.path')`. Como ambos os argumentos são parametrizados,
não há risco de SQL injection mesmo que `$propertyKey` contenha caracteres especiais.

> **Limite de profundidade**: `$.path` acessa o nível superior. Para acesso aninhado
> (`$.browser.name`) o chamador passa `browser.name` como chave. Caminhos profundos podem
> ser surpreendentes — documente as formas de chave suportadas na sua spec OpenAPI.

---

## Agregação de datas com `strftime()`

```sql
SELECT strftime('%Y-%m-%d', occurred_at) AS day,
       COUNT(*) AS count
FROM events
WHERE occurred_at >= ? AND occurred_at < ?
GROUP BY strftime('%Y-%m-%d', occurred_at)
ORDER BY day ASC
```

`strftime('%Y-%m-%d', ...)` trunca uma string de datetime ISO 8601 para seu componente de data.
Isso funciona no SQLite quando `occurred_at` é armazenado como UTC (ex.: `2026-05-27T09:00:00Z`).
Horários armazenados com offsets não-UTC serão agrupados pela sua string bruta, não convertidos para
hora local — normalize para UTC no momento da escrita se semânticas de limite de dia forem importantes.

---

## Contagem de usuários únicos por dia

```sql
SELECT strftime('%Y-%m-%d', occurred_at) AS day,
       COUNT(DISTINCT user_id) AS unique_users
FROM events
WHERE occurred_at >= ? AND occurred_at < ?
GROUP BY strftime('%Y-%m-%d', occurred_at)
ORDER BY day ASC
```

`COUNT(DISTINCT user_id)` retorna o número de valores `user_id` distintos que aparecem
em cada bucket. Esta é uma aproximação de Usuários Ativos Diários (DAU) quando `user_id` é
um identificador externo estável (UUID, ID de dispositivo hash, etc.).

---

## user_id do tipo string

`user_id` é armazenado como `TEXT NOT NULL`, não como uma chave estrangeira inteira. Este design
acomoda:

- UUID (`usr_01HQ...`)
- Identificadores de string opacos de um provedor de identidade
- Tokens de sessão anônimos antes da criação da conta

Como o campo é texto livre, a camada de analytics não se acopla ao
modelo de dados do usuário. Não há chave estrangeira `REFERENCES users(id)` — eventos podem ser registrados
antes ou depois de uma conta de usuário ser criada.

---

## Fallback de intervalo de data padrão

Endpoints de agregação aceitam parâmetros de consulta `?from=` e `?to=`. Quando omitidos, os padrões
abrangem um intervalo muito amplo:

```php
$from = QueryStringParser::string($request, 'from') ?? '2000-01-01T00:00:00Z';
$to   = QueryStringParser::string($request, 'to')   ?? '2100-01-01T00:00:00Z';
```

Isso é conveniente para uso em demo mas pode ser caro em um dataset de produção grande.
Em produção, exija intervalos de data explícitos e limite o intervalo máximo (veja
[`shift-management.md`](shift-management.md) para um padrão de limitação).

---

## Schema e índices

```sql
CREATE TABLE events (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    event_type  TEXT    NOT NULL,
    user_id     TEXT    NOT NULL,
    session_id  TEXT    NOT NULL DEFAULT '',
    properties  TEXT    NOT NULL DEFAULT '{}',
    occurred_at TEXT    NOT NULL
);

CREATE INDEX idx_events_type     ON events(event_type);
CREATE INDEX idx_events_occurred ON events(occurred_at);
CREATE INDEX idx_events_user     ON events(user_id);
```

Três índices cobrem os três formatos principais de consulta:
- `idx_events_occurred` — agregações por intervalo de data (`WHERE occurred_at >= ? AND < ?`)
- `idx_events_type` — filtro por tipo (`WHERE event_type = ?`)
- `idx_events_user` — consulta de histórico por usuário (`WHERE user_id = ?`)

Consultas `json_extract()` em `properties` não são suportadas por índice no SQLite sem uma
coluna gerada. Para filtragem de propriedades de alto volume, considere adicionar uma coluna gerada:

```sql
ALTER TABLE events ADD COLUMN prop_path TEXT GENERATED ALWAYS AS (json_extract(properties, '$.path')) STORED;
CREATE INDEX idx_events_prop_path ON events(prop_path);
```

---

## Codificação de properties em PHP

O campo `properties` aceita qualquer objeto JSON do chamador e o armazena como uma string:

```php
$properties = isset($body['properties']) && is_array($body['properties'])
    ? json_encode($body['properties'], JSON_THROW_ON_ERROR)
    : '{}';
```

`is_array($body['properties'])` rejeita escalares e arrays JSON (que decodificariam
para um array PHP mas não são um objeto). Armazenar com `JSON_THROW_ON_ERROR` garante que falhas de encode
apareçam como exceções em vez de `false` silencioso.

Na serialização, properties são decodificadas de volta para um array PHP e incorporadas como um objeto aninhado
na resposta:

```php
'properties' => json_decode($event->properties, true, 512, JSON_THROW_ON_ERROR),
```

---

## Howtos relacionados

- [`admin-report-aggregation.md`](admin-report-aggregation.md) — padrões de agregação SQL para relatórios de admin
- [`shift-management.md`](shift-management.md) — limitação de intervalo de data, consultas de agregação
- [`pagination.md`](pagination.md) — `PaginationQueryParser` e `PaginationResponse`
- [`iso-datetime-validation.md`](iso-datetime-validation.md) — validação round-trip ISO 8601 para `occurred_at`
