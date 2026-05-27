# Como Fazer: Agendamento de Eventos com Awareness de Timezone

> **Referência FT**: FT286 (`NENE2-FT/schedulelog`) — Agendamento com awareness de timezone: armazenamento UTC + conversão de hora local, validação de timezone IANA via `DateTimeZone::listIdentifiers()`, `InvalidTimezoneException`, parâmetro de query dinâmico `?timezone`, 19 testes / 39 assertivas PASS.

Este guia mostra como construir uma API de agendamento de eventos que armazena horários em UTC e os apresenta em qualquer timezone que o cliente solicitar.

## Por Que Armazenar em UTC?

UTC é o ponto de referência universal. Horários locais são ambíguos (mudanças de horário de verão, mudanças de regras de timezone) e variam de acordo com a localização do cliente. Ao armazenar em UTC:
- Ordenação e comparação são sempre corretas
- Clientes podem exibir em seu timezone local
- Transições de horário de verão não criam ambiguidade em dados históricos

## Schema

```sql
CREATE TABLE IF NOT EXISTS events (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    title       TEXT    NOT NULL,
    timezone    TEXT    NOT NULL,      -- Timezone IANA do criador do evento
    start_utc   TEXT    NOT NULL,      -- UTC ISO 8601: 2026-05-20T15:00:00Z
    start_local TEXT    NOT NULL,      -- Local ISO 8601: 2026-05-20T10:00:00
    created_at  TEXT    NOT NULL
);
```

Tanto `start_utc` quanto `start_local` são armazenados. `start_utc` é autoritativo; `start_local` é um cache de conveniência para o timezone do criador.

## Endpoints

| Método | Caminho | Descrição |
|--------|---------|-----------|
| `POST` | `/events` | Criar evento (timezone + início local → UTC) |
| `GET` | `/events` | Listar eventos (opcional `?timezone=America/Sao_Paulo`) |
| `GET` | `/events/{id}` | Obter evento (opcional `?timezone=`) |

## Validação de Timezone IANA

O construtor `DateTimeZone` do PHP aceita alguns identificadores inválidos silenciosamente. Valide explicitamente:

```php
final class TimezoneConverter
{
    public static function localToUtc(string $localDatetime, string $ianaTimezone): \DateTimeImmutable
    {
        try {
            $tz = new \DateTimeZone($ianaTimezone);
        } catch (\Exception) {
            throw new InvalidTimezoneException("Timezone desconhecido: $ianaTimezone");
        }

        // PHP aceita abreviações inválidas como "EST" em algumas versões —
        // valide contra a lista IANA canônica explicitamente.
        $valid = \DateTimeZone::listIdentifiers();
        if (!in_array($ianaTimezone, $valid, true)) {
            throw new InvalidTimezoneException("Timezone desconhecido: $ianaTimezone");
        }

        $local = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s', $localDatetime, $tz);

        if ($local === false) {
            throw new \InvalidArgumentException("Não é possível analisar o datetime: $localDatetime");
        }

        return $local->setTimezone(new \DateTimeZone('UTC'));
    }
}
```

`DateTimeZone::listIdentifiers()` retorna a lista de identificadores IANA compilada pelo PHP. Strings não-IANA (como `EST`, `GMT+5`) são rejeitadas.

## Criar Evento: Local → UTC

```php
try {
    $utc = TimezoneConverter::localToUtc($start, $timezone);
} catch (InvalidTimezoneException) {
    return $this->problems->create($request, 'validation-failed', 'Validation Failed', 422, null, [
        'errors' => [['field' => 'timezone', 'code' => 'invalid', 'message' => "Timezone desconhecido: $timezone"]],
    ]);
} catch (\InvalidArgumentException) {
    return $this->problems->create($request, 'validation-failed', 'Validation Failed', 422, null, [
        'errors' => [['field' => 'start', 'code' => 'invalid', 'message' => "Não é possível analisar o datetime: $start"]],
    ]);
}

$startUtc   = TimezoneConverter::formatUtc($utc);                              // "2026-05-20T15:00:00Z"
$startLocal = TimezoneConverter::formatLocal($utc->setTimezone(new \DateTimeZone($timezone)));  // "2026-05-20T10:00:00"
```

## Listar Eventos: Conversão de Timezone Dinâmica

O parâmetro de query `?timezone=` converte todos os eventos para o timezone do cliente em tempo real:

```php
$viewTz = isset($params['timezone']) && $params['timezone'] !== '' ? $params['timezone'] : null;

$items = array_map(static function (Event $e) use ($viewTz): array {
    $data = $e->toArray();
    if ($viewTz !== null) {
        try {
            $local = TimezoneConverter::utcToLocal($e->startUtc, $viewTz);
            $data['start_local'] = TimezoneConverter::formatLocal($local);
            $data['view_timezone'] = $viewTz;
        } catch (InvalidTimezoneException) {
            // Timezone de visualização inválido: retorna UTC silenciosamente
            $data['view_timezone'] = 'UTC';
        }
    }
    return $data;
}, $events);
```

Valores inválidos de `?timezone=` silenciosamente retornam ao `start_local` armazenado em vez de retornar um erro — uma escolha de design apropriada para views somente leitura.

## Formato UTC: ISO 8601 com Sufixo Z

```php
public static function formatUtc(\DateTimeImmutable $dt): string
{
    return $dt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    //                                                                           ^ Z literal
}
```

O sufixo `Z` indica explicitamente UTC (por ISO 8601 / RFC 3339). Usar `+00:00` ou omitir o offset são alternativas aceitáveis, mas `Z` é mais compacto e universalmente reconhecido.

## Conversão Segura com Horário de Verão

```
Exemplo: Asia/Tokyo é UTC+9 (sem horário de verão)
Local: 2026-05-20T10:00:00  Asia/Tokyo
UTC:   2026-05-20T01:00:00Z

Exemplo: America/Sao_Paulo (horário de verão)
Local: 2026-05-20T10:00:00  America/Sao_Paulo (BRT = UTC-3)
UTC:   2026-05-20T13:00:00Z
```

`DateTimeImmutable` com um timezone IANA nomeado lida automaticamente com o horário de verão. Ele usa o offset ativo naquela data específica, não um offset fixo.

---

## O Que NÃO Fazer

| Antipadrão | Risco |
|---|---|
| Armazenar hora local sem coluna de timezone | Não é possível converter para UTC depois; dados históricos ficam ambíguos após mudanças de horário |
| Aceitar `EST`, `PST`, `GMT+5` como timezone | Abreviações ambíguas; algumas mapeiam para múltiplas zonas IANA; `DateTimeZone::listIdentifiers()` rejeita esses |
| Usar `new DateTimeZone($tz)` sem verificar `listIdentifiers()` | PHP aceita silenciosamente alguns identificadores inválidos ou obsoletos; validação canônica os captura |
| Armazenar offset UTC (`+09:00`) em vez do nome IANA | Offset sozinho não pode lidar com horário de verão; `Asia/Tokyo` sempre +9 mas `America/New_York` varia |
| Ordenar eventos por `start_local` | Ordenação lexicográfica em horários locais ignora diferenças de timezone; sempre ordene por `start_utc` |
| Converter timezone em cada query | Caro para grandes conjuntos de dados; considere caching ou pré-computação de timezones de visualização comuns |
| Retornar 422 para `?timezone=` inválido em GET | Queries somente leitura devem degradar graciosamente; retorne ao UTC em vez de gerar erro |
| Usar `date()` em vez de `DateTimeImmutable` | `date()` usa o timezone padrão do servidor; `DateTimeImmutable` com zonas explícitas é previsível |
