# Como Validar Datetimes ISO 8601 com Fuso Horário

Aceitar strings de datetime controladas pelo usuário requer validação cuidadosa. Este guia cobre
as duas armadilhas mais importantes: **PHP silenciosamente aceitando offsets de fuso horário inválidos**, e
**comparação de strings falhando entre diferentes offsets de fuso horário**.

---

## V::isoDatetime — Validação de Formato

```php
V::isoDatetime(mixed $raw): ?string
```

Valida uma string de datetime no formato de offset `±HH:MM`:

```
✅ 2024-01-15T12:30:00+09:00   (JST)
✅ 2024-06-01T00:00:00+00:00   (UTC)
✅ 2024-12-31T23:59:59-05:00   (EST)
✅ 2026-06-15T09:00:00-14:00   (UTC−14, Ilha Howland)
✅ 2026-06-15T09:00:00+14:00   (UTC+14, Kiribati)

❌ 2024-01-15                   (apenas data, sem hora)
❌ 2024-01-15T12:00:00Z         (sufixo 'Z', não ±HH:MM)
❌ 2024-01-15T12:00:00          (sem offset algum)
❌ 2024-02-30T00:00:00+00:00   (30 de fev não existe)
❌ 2024-13-01T00:00:00+00:00   (mês 13 não existe)
❌ 2026-06-15T09:00:00+25:00   (offset inválido — excede +14:00)
```

### Implementação

```php
public static function isoDatetime(mixed $raw): ?string
{
    if (!is_string($raw)) return null;

    // Regex estrita: ±HH:MM obrigatório, sem Z, sem hora simples
    if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}([+-])(\d{2}):(\d{2})$/', $raw, $m)) {
        return null;
    }

    // Validar range de offset: offsets UTC válidos são −14:00 … +14:00.
    // DateTimeImmutable do PHP silenciosamente aceita +25:00 e offsets inválidos similares.
    $tzHours   = (int) $m[2];
    $tzMinutes = (int) $m[3];
    if ($tzHours > 14 || $tzMinutes > 59 || ($tzHours === 14 && $tzMinutes > 0)) {
        return null;
    }

    // DateTimeImmutable preserva o fuso horário da entrada — evita strtotime + date()
    // que silenciosamente reformata no fuso horário local do servidor.
    $dt = DateTimeImmutable::createFromFormat(DATE_ATOM, $raw);
    if ($dt === false) return null;

    // Comparação round-trip captura datas com overflow (30 de fev vira 1 de mar, etc.)
    return $dt->format(DATE_ATOM) === $raw ? $raw : null;
}
```

### Por que não usar `strtotime` + `date()`?

```php
// ❌ ERRADO — date() usa o fuso horário local do servidor
$ts = strtotime('2024-01-15T12:30:00+09:00');
$canonical = date('c', $ts);
// Se o servidor for UTC: '2024-01-15T03:30:00+00:00' — fuso horário perdido!
```

```php
// ✅ CORRETO — DateTimeImmutable preserva o offset original
$dt = DateTimeImmutable::createFromFormat(DATE_ATOM, '2024-01-15T12:30:00+09:00');
$dt->format(DATE_ATOM); // → '2024-01-15T12:30:00+09:00' ✓
```

---

## V::futureDatetime — Verificação de Futuro Entre Fusos Horários

```php
V::futureDatetime(mixed $raw, string $now): ?string
```

Retorna a string validada apenas se o datetime for **estritamente depois** de `$now`.

### O Bug Crítico: Comparação de Strings Falha Entre Fusos Horários

```php
$now  = '2026-06-01T10:00:00+00:00';  // UTC 10:00

// JST 18:00 = UTC 09:00 → 1 hora no PASSADO
$pastJst = '2026-06-01T18:00:00+09:00';

// ❌ ERRADO: comparação de string diz futuro ("T18" > "T10")
$pastJst > $now  // → TRUE   ← ERRADO! Está no passado!

// ✅ CORRETO: comparação DateTimeImmutable normaliza para UTC primeiro
$dtObj = new DateTimeImmutable('2026-06-01T18:00:00+09:00');  // UTC 09:00
$nowObj = new DateTimeImmutable('2026-06-01T10:00:00+00:00');  // UTC 10:00
$dtObj > $nowObj  // → FALSE ✓ (corretamente no passado)
```

O erro oposto também ocorre com offsets negativos:

```php
// EST 08:00 = UTC 13:00 → 3 horas no FUTURO
$futureEst = '2026-06-01T08:00:00-05:00';

// ❌ ERRADO: comparação de string diz passado ("T08" < "T10")
$futureEst > $now  // → FALSE  ← ERRADO! Está no futuro!

// ✅ CORRETO: comparação por objeto
$dtObj = new DateTimeImmutable('2026-06-01T08:00:00-05:00');  // UTC 13:00
$dtObj > $nowObj  // → TRUE ✓ (corretamente no futuro)
```

### Implementação

```php
public static function futureDatetime(mixed $raw, string $now): ?string
{
    $dt = self::isoDatetime($raw);
    if ($dt === null) return null;

    $dtObj  = DateTimeImmutable::createFromFormat(DATE_ATOM, $dt);
    $nowObj = DateTimeImmutable::createFromFormat(DATE_ATOM, $now);

    if ($dtObj === false || $nowObj === false) return null;

    // Comparação por objeto normaliza ambos para UTC antes de comparar.
    return $dtObj > $nowObj ? $dt : null;
}
```

### Uso em um Handler de Rota

```php
private function handleCreate(ServerRequestInterface $request): ResponseInterface
{
    // ...
    $rawRemindAt = $body['remind_at'] ?? null;

    if (!is_string($rawRemindAt)) {
        return $this->responseFactory->create(
            ['error' => 'remind_at is required (ISO 8601 with timezone, e.g. 2026-06-01T09:00:00+09:00).'],
            422,
        );
    }

    // Usar DateTimeImmutable para um "now" preservando fuso horário
    $now      = (new DateTimeImmutable())->format(DATE_ATOM);
    $remindAt = V::futureDatetime($rawRemindAt, $now);

    if ($remindAt === null) {
        return $this->responseFactory->create(
            ['error' => 'remind_at must be a valid ISO 8601 datetime with timezone and must be in the future.'],
            422,
        );
    }

    // $remindAt agora é seguro para armazenar — a string exata submetida, fuso horário preservado.
    $reminder = $this->repository->create($userId, $message, $remindAt, $now);
    // ...
}
```

---

## Preservação do Fuso Horário

Armazene `remind_at` (ou qualquer datetime submetido pelo usuário) exatamente como validado — não converta para UTC.

```php
// ✅ Armazenar a string validada como está
'INSERT INTO reminders (remind_at, ...) VALUES (:remind_at, ...)'
// com :remind_at = '2026-06-15T09:00:00+09:00'

// Retornar sem alteração na resposta da API
$reminder->remindAt  // → '2026-06-15T09:00:00+09:00'
```

Isso respeita a intenção do usuário e evita conversão implícita de fuso horário. Se sua aplicação
precisa de normalização para UTC para ordenação/comparação em SQL, adicione uma coluna `remind_at_utc` separada
calculada no momento da escrita.

---

## Entradas Validadas → SQL Seguro

Após `V::isoDatetime()` / `V::futureDatetime()`, a string é segura para inserir via
query parametrizada. Nunca interpole strings de datetime brutas em SQL.

```php
// ✅ Seguro — pré-validado, parametrizado
$stmt->execute(['remind_at' => $remindAt]);

// ❌ Perigoso — entrada bruta do usuário interpolada
$sql = "INSERT INTO reminders (remind_at) VALUES ('{$_POST['remind_at']}')";
```

---

## Relacionados

- FT181 — reminderlog: Validação de Datetime ISO 8601 & API Consciente de Fuso Horário  
- [RFC 3339](https://www.rfc-editor.org/rfc/rfc3339) — Data e Hora na Internet  
- [IANA Time Zone Database](https://www.iana.org/time-zones) — Referência de offset UTC  
- `docs/howto/json-merge-patch.md` — também usa isoDatetime para created_at
