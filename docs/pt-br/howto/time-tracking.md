# Como Fazer: API de Controle de Tempo

> **Referência FT**: FT246 (`NENE2-FT/timelog`) — API de Controle de Tempo

Demonstra uma API de controle de tempo estilo cronômetro onde uma entrada de timer tem um `start_time` e um `end_time` nullable (`NULL` = rodando, não-`NULL` = parado), apenas um timer pode rodar por vez, a duração é computada via `julianday()` do SQLite e resumos diários agregam o total de segundos rastreados por dia de calendário.

---

## Rotas

| Método   | Caminho              | Descrição                                                  |
|----------|----------------------|------------------------------------------------------------|
| `POST`   | `/timers/start`      | Iniciar um novo timer (falha se um já estiver rodando)     |
| `POST`   | `/timers/stop`       | Parar o timer atualmente rodando                           |
| `GET`    | `/timers/running`    | Obter o timer atualmente rodando (ou `running: false`)     |
| `GET`    | `/timers/summary`    | Resumo diário: total de segundos e contagem de entradas por dia |
| `GET`    | `/timers`            | Listar entradas (paginado, filtrável por label e data)     |
| `GET`    | `/timers/{id}`       | Obter uma única entrada de timer                           |
| `DELETE` | `/timers/{id}`       | Excluir uma entrada de timer (`204 No Content`)            |

> **Rotas estáticas primeiro**: `/timers/start`, `/timers/stop`, `/timers/running`, `/timers/summary` são todas registradas antes de `/timers/{id}` para que caminhos literais não sejam capturados como segmentos parametrizados.

---

## Schema

```sql
CREATE TABLE IF NOT EXISTS time_entries (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    label      TEXT NOT NULL,
    start_time TEXT NOT NULL,
    end_time   TEXT,              -- NULL = rodando
    created_at TEXT NOT NULL
);
```

`end_time` é nullable — `NULL` significa que o timer ainda está rodando. `NOT NULL` significa que foi parado. Não há coluna `status` separada; a presença ou ausência de `end_time` codifica o estado de execução.

---

## Estado de Execução: `end_time IS NULL`

O estado de execução do timer é detectado puramente a partir da coluna `end_time`:

```php
final readonly class TimeEntry
{
    public function isRunning(): bool
    {
        return $this->endTime === null;
    }

    public function durationSeconds(): ?int
    {
        if ($this->endTime === null) {
            return null;  // ainda rodando — sem duração ainda
        }
        $start = new \DateTimeImmutable($this->startTime);
        $end   = new \DateTimeImmutable($this->endTime);
        return (int) $end->getTimestamp() - (int) $start->getTimestamp();
    }
}
```

`isRunning()` retorna `true` quando `endTime` é `null`. `durationSeconds()` retorna `null` para timers rodando — a duração não pode ser computada até que o timer seja parado. A resposta inclui `"running": true` e `"duration_seconds": null` para entradas ativas.

---

## Timer Singleton: Apenas Um Pode Rodar por Vez

`start()` verifica um timer em execução antes de criar um novo:

```php
public function start(string $label, string $startTime, string $createdAt): TimeEntry
{
    $running = $this->findRunning();
    if ($running !== null) {
        throw new TimerAlreadyRunningException($running->id);
    }

    $this->executor->execute(
        'INSERT INTO time_entries (label, start_time, end_time, created_at) VALUES (?, ?, NULL, ?)',
        [$label, $startTime, $createdAt],
    );

    return $this->findById($this->executor->lastInsertId());
}
```

Se um timer já estiver rodando, `TimerAlreadyRunningException` é lançada → `409 Conflict`. `end_time` é inserido como o valor SQL literal `NULL`.

A busca do timer em execução:

```php
public function findRunning(): ?TimeEntry
{
    $row = $this->executor->fetchOne(
        'SELECT * FROM time_entries WHERE end_time IS NULL ORDER BY start_time DESC LIMIT 1',
        [],
    );
    return $row !== null ? $this->hydrate($row) : null;
}
```

`WHERE end_time IS NULL` — comparação padrão SQL `NULL` (não `= NULL`). `LIMIT 1` protege contra retornar múltiplas linhas se o invariante for violado.

---

## Parando um Timer: `stop()`

```php
public function stop(string $endTime): TimeEntry
{
    $running = $this->findRunning();
    if ($running === null) {
        throw new NoRunningTimerException();
    }

    $this->executor->execute(
        'UPDATE time_entries SET end_time = ? WHERE id = ?',
        [$endTime, $running->id],
    );

    return $this->findById($running->id);
}
```

`stop()` encontra o timer em execução, define `end_time` e retorna a entrada atualizada com a duração computada. `NoRunningTimerException` é lançada se nenhum timer estiver rodando → `409 Conflict`.

---

## Cálculo de Duração: `julianday()` em SQL

Para resumos agregados, a duração é computada em SQL usando a função `julianday()` do SQLite:

```sql
SUM(CAST((julianday(end_time) - julianday(start_time)) * 86400 AS INTEGER)) AS total_seconds
```

`julianday()` converte uma string de datetime ISO para um Número de Dia Juliano (um número real representando dias desde o meio-dia de 1º de janeiro de 4713 a.C.). Subtrair dois Números de Dia Juliano dá a diferença em dias. Multiplicar por `86400` converte dias para segundos. `CAST(... AS INTEGER)` trunca para segundos inteiros.

`SUM(...)` totaliza todas as entradas concluídas do dia. `WHERE end_time IS NOT NULL` filtra quaisquer timers ainda em execução do resumo.

O cálculo do lado PHP para entradas individuais:

```php
$start = new \DateTimeImmutable($this->startTime);
$end   = new \DateTimeImmutable($this->endTime);
return (int) $end->getTimestamp() - (int) $start->getTimestamp();
```

Ambas as abordagens produzem o mesmo resultado para timestamps UTC. A abordagem SQL é usada para agregação (evita buscar todas as linhas para somar); a abordagem PHP é usada para serialização de entrada individual.

---

## Agregação de Resumo Diário

```php
$sql = 'SELECT date(start_time) AS day,
               SUM(CAST((julianday(end_time) - julianday(start_time)) * 86400 AS INTEGER)) AS total_seconds,
               COUNT(*) AS entry_count
          FROM time_entries
         WHERE ' . implode(' AND ', $where) . '
         GROUP BY day
         ORDER BY day DESC';
```

`date(start_time)` extrai a data de calendário da string ISO `start_time`. `GROUP BY day` agrupa todas as entradas concluídas do mesmo dia. `ORDER BY day DESC` retorna os dias mais recentes primeiro.

A cláusula `$where` sempre começa com `['end_time IS NOT NULL']` para excluir timers em execução, depois opcionalmente adiciona `date(start_time) >= ?` e `date(start_time) <= ?` para o filtro de intervalo de datas.

---

## Função `date()` para Filtragem Somente por Data

Filtrar entradas por uma data de calendário usa a função `date()` do SQLite:

```php
if ($date !== null) {
    $where[]  = "date(start_time) = ?";
    $params[] = $date;
}
```

`date(start_time)` extrai apenas `YYYY-MM-DD` da string de datetime ISO. `= ?` compara a data extraída ao valor de filtro. Isso corresponde corretamente a todas as entradas que começaram no dia fornecido independentemente do componente de tempo.

---

## Filtragem de Label com `LIKE`

```php
if ($label !== null) {
    $where[]  = 'label LIKE ?';
    $params[] = '%' . $label . '%';
}
```

`LIKE '%label%'` realiza uma correspondência de substring sem distinção de maiúsculas/minúsculas na collation padrão do SQLite. Caracteres especiais `%` e `_` em `$label` são interpretados como wildcards LIKE — escape-os se a correspondência literal estrita for necessária.

---

## Contrato de Resposta de `GET /timers/running`

O endpoint de execução retorna uma forma consistente independentemente de um timer estar ativo ou não:

```php
if ($entry === null) {
    return $this->json->create(['running' => false, 'entry' => null]);
}
return $this->json->create(['running' => true, 'entry' => $this->serialize($entry)]);
```

`running: false, entry: null` — nenhum timer ativo. `running: true, entry: {...}` — timer ativo com `end_time: null` e `duration_seconds: null`.

Isso evita um `404` para "sem timer rodando" — `404` implica que o recurso não existe, mas o conceito de "timer rodando" sempre existe (está apenas vazio). Usar `running: false` é semanticamente mais limpo.

---

## Howtos Relacionados

- [`shift-management.md`](shift-management.md) — clock-in/clock-out de turno com nullable end time
- [`scheduled-reminders.md`](scheduled-reminders.md) — validação de datetime com awareness de timezone
- [`aggregate-reporting.md`](aggregate-reporting.md) — padrões de agregação `GROUP BY date`
- [`handle-timezones.md`](handle-timezones.md) — armazenamento UTC e conversão de timezone
