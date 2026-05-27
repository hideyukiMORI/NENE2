# Como lidar com fusos horários

O tratamento de fusos horários em PHP tem vários modos de falha silenciosos. Este guia cobre os padrões e armadilhas encontrados em field trials reais do NENE2.

## Sempre especifique o fuso horário ao criar `DateTimeImmutable`

`new DateTimeImmutable('now')` usa a configuração `date.timezone` do servidor, que difere entre ambientes. Sempre passe `UTC` explicitamente para timestamps do servidor:

```php
// Frágil — depende do date.timezone do servidor
$now = new \DateTimeImmutable('now');

// Correto — sempre UTC
$now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
```

Para timestamps armazenados, formate como ISO8601 UTC:

```php
$now->format('Y-m-d\TH:i:s\Z') // → "2026-05-20T15:00:00Z"
```

## Valide identificadores de fuso horário IANA explicitamente

O construtor `DateTimeZone` do PHP aceita abreviações de fuso horário como `"EST"` sem lançar exceção, mas não são identificadores IANA canônicos. `"America/New_York"` é a forma IANA correta.

```php
// Isso tem sucesso — mas "EST" não é um identificador IANA
$tz = new \DateTimeZone('EST'); // sem exceção!

// Validação correta:
try {
    $tz = new \DateTimeZone($input);
} catch (\Exception) {
    throw new InvalidTimezoneException("Unknown timezone: $input");
}

// Verificação adicional de membros para abreviações não-IANA:
if (!in_array($input, \DateTimeZone::listIdentifiers(), true)) {
    throw new InvalidTimezoneException("Unknown timezone: $input");
}
```

Sem a verificação `listIdentifiers()`, `"EST"`, `"PST"` e abreviações similares passam silenciosamente.

## Parse strings de datetime local com `createFromFormat`

Ao aceitar datetime local da entrada do usuário (sem offset de fuso horário), use `createFromFormat` com o formato explícito e fuso horário:

```php
$tz    = new \DateTimeZone('Asia/Tokyo');
$local = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s', '2026-06-01T10:00:00', $tz);

if ($local === false) {
    // Formato inválido — '2026/06/01 10:00', '2026-06-01', etc. retornam false
    throw new \InvalidArgumentException('Invalid datetime format. Expected YYYY-MM-DDTHH:mm:ss.');
}
```

Prefira `createFromFormat` em vez de `new DateTimeImmutable($str, $tz)` — o construtor é permissivo e aceita muitos formatos silenciosamente.

## Converta hora local para UTC para armazenamento

```php
$utc = $local->setTimezone(new \DateTimeZone('UTC'));
// Armazene como: $utc->format('Y-m-d\TH:i:s\Z')
```

Sempre armazene UTC no banco de dados. Armazene o nome do fuso horário original junto para que você possa reconstruir a hora local ao recuperar.

## Transição de horário de verão: horários de relógio ambíguos

Durante transições de "adiantar o relógio" (ex.: `America/New_York` no primeiro domingo de novembro), alguns horários de relógio ocorrem duas vezes:

- `2026-11-01 01:30 AM` existe tanto em EDT (UTC-4) quanto em EST (UTC-5)

O PHP resolve a ambiguidade escolhendo a **primeira ocorrência** (horário de verão):

```php
$dt = \DateTimeImmutable::createFromFormat(
    'Y-m-d\TH:i:s',
    '2026-11-01T01:30:00',
    new \DateTimeZone('America/New_York'),
);
// → 05:30 UTC (EDT = UTC-4), não 06:30 UTC (EST = UTC-5)
```

Isso corresponde ao padrão IANA. Se sua aplicação precisa distinguir as duas ocorrências (ex.: para sistemas de calendário), você deve lidar com isso no nível de aplicação — o PHP não expõe uma API para selecionar a segunda ocorrência.

## Padrão completo de conversão local→UTC

```php
use Schedule\Event\InvalidTimezoneException;

function localToUtc(string $localDatetime, string $ianaTimezone): \DateTimeImmutable
{
    try {
        $tz = new \DateTimeZone($ianaTimezone);
    } catch (\Exception) {
        throw new InvalidTimezoneException("Unknown timezone: $ianaTimezone");
    }

    // Rejeitar abreviações não-IANA (ex.: "EST")
    if (!in_array($ianaTimezone, \DateTimeZone::listIdentifiers(), true)) {
        throw new InvalidTimezoneException("Unknown timezone: $ianaTimezone");
    }

    $local = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s', $localDatetime, $tz);

    if ($local === false) {
        throw new \InvalidArgumentException("Cannot parse datetime: $localDatetime");
    }

    return $local->setTimezone(new \DateTimeZone('UTC'));
}
```

## Queries de listagem multi-fuso horário

Ao listar eventos armazenados em UTC, converta para o fuso horário do visualizador na saída:

```php
$viewTz = QueryStringParser::string($request, 'timezone');

if ($viewTz !== null) {
    try {
        $tz    = new \DateTimeZone($viewTz);
        $local = (new \DateTimeImmutable($event->startUtc, new \DateTimeZone('UTC')))->setTimezone($tz);
        $data['start_local'] = $local->format('Y-m-d\TH:i:s');
    } catch (\Exception) {
        // Fuso horário inválido solicitado — omitir silenciosamente a conversão
    }
}
```

---

## SQLite específico: `datetime('now')` sempre retorna UTC

As funções nativas de data/hora do SQLite sempre operam em **UTC**, independente do fuso horário do SO do servidor ou da configuração `date.timezone` do PHP.

```sql
SELECT datetime('now');          -- → "2026-05-27 11:30:00"  (UTC)
SELECT date('now');              -- → "2026-05-27"            (data UTC)
SELECT date('now', '+1 day');    -- → "2026-05-28"            (UTC + 1 dia)
SELECT datetime('now', '-9 hours'); -- → aproximação local JST (offset manual — evite isso)
```

**Isso é geralmente o que você quer**: armazenar timestamps como UTC TEXT e comparar em UTC.

### Filtrar por "hoje" em UTC

```sql
-- Registros criados hoje (UTC)
SELECT * FROM events WHERE DATE(created_at) = DATE('now');

-- Registros nos próximos 30 dias (UTC)
SELECT * FROM reminders WHERE reminder_at <= DATE('now', '+30 days');

-- Registros de um mês específico (UTC)
SELECT * FROM logs WHERE STRFTIME('%Y-%m', created_at) = '2026-05';
```

### A armadilha: "hoje" difere por fuso horário

Se seus usuários estão em JST (UTC+9), "hoje" em JST começa 9 horas antes de "hoje" em UTC. `DATE('now')` no SQLite retorna a data UTC — isso é uma incompatibilidade.

```php
// Errado: SQLite DATE('now') = data UTC, não a data local do usuário
$rows = $this->db->fetchAll("SELECT * FROM tasks WHERE DATE(due_date) = DATE('now')");

// Correto: calcule o "hoje" do usuário em PHP e passe como parâmetro
$todayUtc = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d');
$rows = $this->db->fetchAll(
    "SELECT * FROM tasks WHERE DATE(due_date) = ?",
    [$todayUtc],
);
```

Para um serviço onde "hoje" significa UTC, `DATE('now')` está bem. Para recursos de "vence hoje" voltados ao usuário, calcule o limite em PHP usando o fuso horário do usuário e passe como parâmetro vinculado.

### Intervalo dinâmico com valor de coluna

O SQLite permite combinar `date()` com uma string construída a partir de um valor de coluna:

```sql
-- Registros onde next_review_at = hoje baseado na coluna interval_days
SELECT * FROM cards WHERE next_review_at <= DATE('now');

-- Calcular próxima data de revisão dinamicamente (armazenar resultado, não confiar nisso no SELECT)
SELECT DATE('now', '+' || interval_days || ' days') AS next_date FROM cards;
```

Isso é útil em uma instrução `UPDATE` ao avançar um agendamento:

```php
$this->db->execute(
    "UPDATE cards SET next_review_at = DATE('now', '+' || interval_days || ' days') WHERE id = ?",
    [$cardId],
);
```

### Referência de formato `STRFTIME`

| Padrão | Saída | Uso |
|--------|-------|-----|
| `%Y-%m-%d` | `2026-05-27` | Data completa |
| `%Y-%m` | `2026-05` | Agrupamento por ano-mês |
| `%Y-%W` | `2026-22` | Ano + número de semana (começando no **domingo**, 0–53) |
| `%H:%M:%S` | `11:30:00` | Apenas hora |
| `%s` | Unix timestamp | Segundos inteiros desde a época |

**`%W` começa no domingo**, não ISO 8601 (começa na segunda). Para números de semana começando na segunda, calcule o limite da semana em PHP:

```php
// Obter a segunda-feira da semana ISO atual
$monday = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
    ->modify('Monday this week')
    ->format('Y-m-d');

$sunday = (new \DateTimeImmutable($monday))->modify('+6 days')->format('Y-m-d');

$rows = $this->db->fetchAll(
    "SELECT * FROM workouts WHERE workout_date BETWEEN ? AND ?",
    [$monday, $sunday],
);
```
