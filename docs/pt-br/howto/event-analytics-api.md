# Como Fazer: API de Analytics de Eventos

> **Referência FT**: FT243 (`NENE2-FT/statslog`) — API de Analytics de Eventos
> **VULN**: FT243 — avaliação de vulnerabilidades (V-01 a V-10)

Demonstra uma API de ingestão e agregação de eventos onde eventos brutos de analytics são
registrados com um blob JSON `properties`, consultados com `json_extract()` do SQLite, e
agregados em estatísticas por dia / por tipo / usuários únicos. Inclui uma avaliação completa
de vulnerabilidades do design não autenticado.

---

## Rotas

| Método | Caminho                | Descrição                                          |
|--------|------------------------|----------------------------------------------------|
| `POST` | `/events`              | Registrar um evento de analytics                   |
| `GET`  | `/events`              | Listar eventos (paginado)                          |
| `GET`  | `/events/by-property`  | Filtrar eventos por chave+valor de propriedade JSON |
| `GET`  | `/events/{id}`         | Obter um único evento                              |
| `GET`  | `/stats/per-day`       | Contagem de eventos agrupada por dia               |
| `GET`  | `/stats/per-type`      | Contagem de eventos agrupada por tipo de evento    |
| `GET`  | `/stats/unique-users`  | Contagem de usuários únicos agrupada por dia       |

> **Rotas estáticas antes das parametrizadas**: `/events/by-property` é registrada antes
> de `/events/{id}` para que o roteador despache o caminho literal corretamente.

---

## Schema

```sql
CREATE TABLE IF NOT EXISTS events (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    event_type  TEXT    NOT NULL,
    user_id     TEXT    NOT NULL,
    session_id  TEXT    NOT NULL DEFAULT '',
    properties  TEXT    NOT NULL DEFAULT '{}',
    occurred_at TEXT    NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_events_type     ON events(event_type);
CREATE INDEX IF NOT EXISTS idx_events_occurred ON events(occurred_at);
CREATE INDEX IF NOT EXISTS idx_events_user     ON events(user_id);
```

`properties` é armazenado como uma string JSON (`TEXT`). `json_extract()` do SQLite permite
consultar dentro do blob em tempo de leitura sem um schema separado. Três índices cobrem os
padrões de acesso mais comuns: por tipo, por intervalo de tempo e por usuário.

---

## Criação de evento: blob JSON de propriedades

`POST /events` aceita um objeto `properties` flexível junto com os obrigatórios `event_type`
e `user_id`:

```php
$eventType  = trim((string) $body['event_type']);
$userId     = trim((string) $body['user_id']);
$sessionId  = isset($body['session_id']) && is_string($body['session_id']) ? $body['session_id'] : '';
$properties = isset($body['properties']) && is_array($body['properties'])
    ? json_encode($body['properties'], JSON_THROW_ON_ERROR)
    : '{}';
$occurredAt = isset($body['occurred_at']) && is_string($body['occurred_at'])
    ? $body['occurred_at']
    : (new \DateTimeImmutable())->format('Y-m-d\TH:i:s\Z');
```

- `properties` deve ser um objeto JSON (verificação `is_array()`) — valores escalares revertem para `'{}'`.
- `occurred_at` é fornecido pelo chamador ou padroniza para agora — sem imposição no servidor de que
  cai dentro de um intervalo válido.
- `JSON_THROW_ON_ERROR` garante que JSON intermediário malformado lance imediatamente em vez de
  produzir `false`.

Desserialização no momento da leitura:
```php
'properties' => json_decode($event->properties, true, 512, JSON_THROW_ON_ERROR),
```

---

## Busca de propriedade JSON com `json_extract()`

`GET /events/by-property?key=page&value=/home` filtra eventos por uma chave/valor de propriedade:

```php
$rows = $this->executor->fetchAll(
    'SELECT * FROM events WHERE json_extract(properties, ?) = ? ORDER BY occurred_at DESC LIMIT ? OFFSET ?',
    ['$.' . $propertyKey, $propertyValue, $limit, $offset],
);
```

`json_extract(properties, '$.page')` extrai o campo `page` do blob JSON.
O caminho `'$.' . $propertyKey` é construído por concatenação, **não** parametrizado
como o próprio caminho — `json_extract()` do SQLite aceita apenas uma string de caminho literal, não
um parâmetro bound para a expressão de caminho. A chave vem de uma query string mas não é
validada adicionalmente (veja V-05).

`= ?` compara o valor extraído com o `$propertyValue` fornecido como um binding parametrizado
— SQL injection via o valor está bloqueada. A concatenação de caminho é o limite a auditar.

---

## Consultas de agregação

### Contagem de eventos por dia

```php
$rows = $this->executor->fetchAll(
    "SELECT strftime('%Y-%m-%d', occurred_at) AS day, COUNT(*) AS count
     FROM events
     WHERE occurred_at >= ? AND occurred_at < ?
     GROUP BY strftime('%Y-%m-%d', occurred_at)
     ORDER BY day ASC",
    [$from, $to],
);
```

`strftime('%Y-%m-%d', occurred_at)` trunca o timestamp para uma data. `GROUP BY`
na mesma expressão agrupa todos os eventos do mesmo dia juntos. Tanto `$from` quanto
`$to` são parametrizados — sem concatenação de string no SQL.

### Contagem de eventos por tipo

```php
$rows = $this->executor->fetchAll(
    'SELECT event_type, COUNT(*) AS count
     FROM events
     WHERE occurred_at >= ? AND occurred_at < ?
     GROUP BY event_type
     ORDER BY count DESC',
    [$from, $to],
);
```

`ORDER BY count DESC` mostra os tipos de evento mais frequentes primeiro.

### Usuários únicos por dia

```php
$rows = $this->executor->fetchAll(
    "SELECT strftime('%Y-%m-%d', occurred_at) AS day, COUNT(DISTINCT user_id) AS unique_users
     FROM events
     WHERE occurred_at >= ? AND occurred_at < ?
     GROUP BY strftime('%Y-%m-%d', occurred_at)
     ORDER BY day ASC",
    [$from, $to],
);
```

`COUNT(DISTINCT user_id)` conta cada `user_id` apenas uma vez por dia.

### Padrões de intervalo de data

```php
private function parseDateRange(ServerRequestInterface $request): array
{
    $from = QueryStringParser::string($request, 'from') ?? '2000-01-01T00:00:00Z';
    $to   = QueryStringParser::string($request, 'to') ?? '2100-01-01T00:00:00Z';

    return [$from, $to];
}
```

Padrões amplos (`2000-01-01` a `2100-01-01`) garantem que estatísticas sem intervalo de data incluam
todos os eventos. Em produção, limite o intervalo padrão a uma janela razoável (ex.: últimos 30
dias) para evitar full-table scans em conjuntos de dados grandes.

---

## VULN — Avaliação de vulnerabilidades (FT243)

### V-01 — Sem autenticação: qualquer um pode registrar eventos

**Risco**: Qualquer chamador pode enviar eventos com `event_type` e `user_id` arbitrários. Não há
verificação de API key, sessão ou token.

**Impacto**: Um atacante pode poluir o dataset de analytics com milhões de eventos falsos,
distorcer estatísticas e se passar por qualquer ID de usuário.

**Veredicto**: **EXPOSTO** — adicione autenticação por API key ou JWT para o endpoint de escrita.
Estatísticas somente-leitura podem permanecer públicas, mas a ingestão deve ser autenticada.

---

### V-02 — Sem autorização nas estatísticas: estatísticas são lidas por todos

**Risco**: `GET /stats/per-day`, `/stats/per-type`, `/stats/unique-users` retornam
dados agregados sem nenhuma autenticação.

**Impacto**: Concorrentes ou crawlers podem monitorar tendências de uso do produto, usuários ativos diários
e adoção de funcionalidades.

**Veredicto**: **EXPOSTO** — restrinja endpoints de estatísticas a papéis autenticados (admin,
visualizador de analytics). Se as estatísticas forem intencionalmente públicas, documente isso como uma decisão de design.

---

### V-03 — `user_id` é fornecido pelo usuário: sem verificação de identidade

**Risco**: `user_id` é extraído diretamente do corpo da requisição sem nenhuma prova de que o
chamador detém essa identidade.

```json
{"event_type": "login", "user_id": "alice", "occurred_at": "2026-01-01T00:00:00Z"}
```

**Impacto**: Um atacante pode fabricar atividade para qualquer ID de usuário, manipulando
estatísticas por usuário e contagens de usuários únicos.

**Veredicto**: **EXPOSTO** — para contextos autenticados, derive `user_id` da
identidade verificada no token/sessão, nunca do corpo da requisição.

---

### V-04 — `occurred_at` é fornecido pelo usuário: backdating e future-dating de eventos

**Risco**: O campo `occurred_at` é aceito do chamador sem validação de intervalo.

```json
{"event_type": "purchase", "user_id": "alice", "occurred_at": "2020-01-01T00:00:00Z"}
```

**Impacto**: Atacantes podem inserir eventos em qualquer slot de tempo histórico (backdating) ou
no futuro distante, distorcendo estatísticas de série temporal.

**Veredicto**: **EXPOSTO** — valide que `occurred_at` cai dentro de uma janela aceitável
(ex.: últimas 24 horas a +5 minutos) e rejeite timestamps fora do intervalo.

---

### V-05 — Concatenação de caminho `json_extract()`: injeção de caminho JSON

**Risco**: A chave de propriedade é concatenada diretamente na expressão de caminho JSON:
`'$.' . $propertyKey`. Não há validação de que `$propertyKey` é um identificador seguro.

**Ataque**:
```
GET /events/by-property?key=x%22%5D+OR+1%3D1+--&value=y
```
Fica: `json_extract(properties, '$.x"] OR 1=1 --')` — SQLite interpreta o argumento de caminho
como uma string literal passada para `json_extract`, não como SQL. O caminho não é
executado como SQL — é tratado pelas funções JSON do SQLite como uma string. Caminhos inválidos
retornam `NULL`, então a consulta não retorna linhas em vez de todas as linhas.

**Observado**: `json_extract()` trata o segundo argumento inteiro como uma expressão de caminho.
Caminhos malformados (`$.x"] OR 1=1 --`) retornam `NULL` para cada linha — sem SQL injection.
No entanto, o comportamento depende da implementação JSON do SQLite — uma abordagem de defesa em profundidade
validaria `$propertyKey` com `preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $key)`.

**Veredicto**: **PARCIALMENTE BLOQUEADO** — `json_extract()` do SQLite isola o argumento de caminho.
Adicione validação explícita de chave (`[a-zA-Z_][a-zA-Z0-9_]*`) para defesa em profundidade.

---

### V-06 — event_type ilimitado: sem lista de permissões

**Risco**: `event_type` aceita qualquer string não vazia. Strings muito longas ou tipos de alta cardinalidade
inflam o conjunto de resultados de `countPerType`.

```json
{"event_type": "aaaa....(10000 chars)", "user_id": "x"}
```

**Impacto**: Cardinalidade ilimitada em `GROUP BY event_type` pode causar pressão de memória.
Crescimento de armazenamento por strings muito longas.

**Veredicto**: **EXPOSTO** — adicione uma verificação de comprimento máximo (ex.: 100 caracteres) e opcionalmente
uma lista de permissões de tipo de evento ou limite de comprimento.

---

### V-07 — SQL injection via parâmetros de data `from`/`to`

**Ataque**: Passe metacaracteres SQL no intervalo de data.

```
GET /stats/per-day?from=2000-01-01%27+OR+%271%27%3D%271&to=2100-01-01
```

**Observado**: Tanto `$from` quanto `$to` são vinculados como valores parametrizados (placeholders `?`).
O engine SQL os trata como strings literais, não como fragmentos SQL.

**Veredicto**: **BLOQUEADO** — consultas parametrizadas previnem SQL injection via parâmetros de data.

---

### V-08 — Tamanho de properties: sem limite no blob JSON

**Risco**: `properties` é armazenado como `TEXT` sem validação de tamanho. Um atacante pode
enviar objetos JSON de vários megabytes.

```json
{"event_type": "x", "user_id": "y", "properties": {"data": "AAAA....(1MB)"}}
```

**Impacto**: Cada evento grande consome armazenamento significativo. Inserção em massa de eventos grandes
pode esgotar o espaço em disco.

**Veredicto**: **EXPOSTO** — adicione uma verificação de tamanho no valor bruto de `properties`
(ex.: `strlen($raw) > 65535 → 422`). Confie no middleware de tamanho de requisição como limite externo.

---

### V-09 — Flood de eventos: sem rate limiting em POST /events

**Risco**: Não há rate limiting no endpoint de ingestão.

**Impacto**: Um único cliente pode enviar milhões de eventos por segundo, sobrecarregando o
banco de dados e o armazenamento.

**Veredicto**: **EXPOSTO** — aplique `ThrottleMiddleware` ou rate limiting por IP / por API-key
no endpoint de escrita.

---

### V-10 — Exposição de estatísticas: `COUNT(DISTINCT user_id)` vaza contagem de usuários

**Risco**: `GET /stats/unique-users` retorna a contagem de IDs de usuário distintos por dia.

**Impacto**: Sem autenticação, isso vaza contagens de usuários ativos diários — uma métrica
de negócios sensível.

**Veredicto**: **EXPOSTO** (mesma raiz que V-02). Restrinja ou autentique endpoints de estatísticas.

---

## Resumo VULN

| # | Vulnerabilidade | Veredicto |
|---|----------------|-----------|
| V-01 | Sem autenticação no endpoint de escrita | EXPOSTO |
| V-02 | Endpoints de estatísticas lidos por todos | EXPOSTO |
| V-03 | `user_id` não verificado (falsificação de identidade) | EXPOSTO |
| V-04 | `occurred_at` fornecido pelo usuário (backdate/future-date) | EXPOSTO |
| V-05 | Concatenação de caminho `json_extract()` | PARCIALMENTE BLOQUEADO |
| V-06 | `event_type` sem lista de permissões / limite de comprimento | EXPOSTO |
| V-07 | SQL injection via parâmetros de intervalo de data | BLOQUEADO |
| V-08 | Sem limite de tamanho no blob JSON `properties` | EXPOSTO |
| V-09 | Sem rate limiting em POST /events | EXPOSTO |
| V-10 | Contagem de usuários únicos vaza métricas DAU | EXPOSTO |

**Correções críticas antes da produção**:
1. **V-01 / V-02 / V-10** — Adicionar autenticação (API key ou JWT) para endpoints de escrita e estatísticas
2. **V-03** — Derivar `user_id` da identidade verificada, não do corpo da requisição
3. **V-04** — Validar que `occurred_at` cai dentro de uma janela de tempo aceitável
4. **V-05** — Adicionar validação `preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $key)`
5. **V-06** — Adicionar verificação de comprimento máximo para `event_type` (ex.: 100 chars)
6. **V-08** — Adicionar limite de tamanho para `properties` (ex.: 64 KB)
7. **V-09** — Aplicar rate limiting em POST /events

---

## Howtos relacionados

- [`event-sourcing.md`](event-sourcing.md) — padrão de log de eventos imutável
- [`api-usage-metering.md`](api-usage-metering.md) — API medida com imposição de quota
- [`quota-management.md`](quota-management.md) — quota por recurso com QuotaWindow
- [`cursor-pagination.md`](cursor-pagination.md) — paginação eficiente para feeds de eventos de alto volume
