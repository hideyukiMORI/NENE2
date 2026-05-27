# Como Fazer: API de Rastreador de Hábitos

> **Referência FT**: FT24 (`NENE2-FT/habitlog`) — API de rastreamento de hábitos com cálculo de sequência
> **ATK**: FT224 — teste de ataque com mentalidade de cracker (ATK-01 a ATK-12)

Demonstra uma API REST de rastreamento de hábitos com cálculo de sequência, proteção contra conclusões duplicadas (409 Conflict) e allowlisting de frequência. A seção ATK documenta cada superfície de ataque encontrada pela mentalidade de cracker e registra se cada uma está defendida ou exposta.

---

## Rotas

| Método | Caminho | Descrição |
|--------|---------|-----------|
| `GET` | `/habits` | Listar todos os hábitos (`?frequency=`) |
| `POST` | `/habits` | Criar um hábito |
| `GET` | `/habits/{id}` | Obter um único hábito |
| `DELETE` | `/habits/{id}` | Deletar um hábito (cascata) |
| `POST` | `/habits/{id}/completions` | Registrar uma conclusão (idempotente por data) |
| `GET` | `/habits/{id}/completions` | Listar conclusões de um hábito |
| `GET` | `/habits/{id}/streak` | Sequência atual (`?today=YYYY-MM-DD`) |

---

## Criando hábitos

```php
// POST /habits
$body = [
    'name'        => 'Corrida Matinal',     // obrigatório, string não vazia
    'description' => 'Correr 5 km',        // opcional
    'frequency'   => 'daily',              // 'daily' | 'weekly' | 'monthly'
];
```

`frequency` é validada contra uma allowlist explícita. Qualquer outro valor retorna 422.

```php
private function createHabit(ServerRequestInterface $req): mixed
{
    $body      = JsonRequestBodyParser::parse($req);
    $name      = isset($body['name']) ? trim((string) $body['name']) : '';
    $frequency = isset($body['frequency']) ? (string) $body['frequency'] : 'daily';

    $errors = [];
    if ($name === '') {
        $errors[] = new ValidationError('name', 'Name must not be empty.', 'required');
    }

    $validFrequencies = ['daily', 'weekly', 'monthly'];
    if (!in_array($frequency, $validFrequencies, true)) {
        $errors[] = new ValidationError('frequency', 'Frequency must be daily, weekly, or monthly.', 'invalid_value');
    }

    if ($errors !== []) {
        throw new ValidationException($errors);
    }
    // ...
}
```

---

## Registrar conclusões com proteção contra duplicatas

Conclusões são identificadas por `(habit_id, completed_on)` via constraint `UNIQUE`. Um segundo POST para a mesma data retorna **409 Conflict** sem tocar na linha do banco de dados.

```sql
-- schema.sql
UNIQUE(habit_id, completed_on)
```

```php
public function complete(int $habitId, string $completedOn, string $note): Completion
{
    try {
        $this->executor->execute(
            'INSERT INTO completions (habit_id, completed_on, note) VALUES (?, ?, ?)',
            [$habitId, $completedOn, $note],
        );
    } catch (DatabaseConnectionException $e) {
        $previous = $e->getPrevious();
        if ($previous !== null && str_contains($previous->getMessage(), 'UNIQUE constraint failed')) {
            throw new AlreadyCompletedException($habitId, $completedOn);
        }
        throw $e;
    }

    return new Completion($this->executor->lastInsertId(), $habitId, $completedOn, $note);
}
```

O controller mapeia `AlreadyCompletedException` → 409 antes que o handler de erros global do NENE2 o veja, então a resposta usa Problem Details corretamente.

---

## Cálculo de sequência

A sequência conta para trás a partir de `$today` através de conclusões diárias consecutivas.

```php
public function currentStreak(int $habitId, string $today): int
{
    $rows = $this->executor->fetchAll(
        'SELECT completed_on FROM completions WHERE habit_id = ? ORDER BY completed_on DESC',
        [$habitId],
    );

    $streak   = 0;
    $expected = new \DateTimeImmutable($today);

    foreach ($rows as $row) {
        $date = new \DateTimeImmutable((string) $row['completed_on']);
        if ($date->format('Y-m-d') !== $expected->format('Y-m-d')) {
            break;
        }
        $streak++;
        $expected = $expected->modify('-1 day');
    }

    return $streak;
}
```

`?today=YYYY-MM-DD` sobrescreve a data de referência para que os testes sejam determinísticos sem mockar `date()`.

---

## Validação de formato de data

O campo `completed_on` é validado por regex, não por parsing semântico:

```php
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $completedOn)) {
    throw new ValidationException([
        new ValidationError('completed_on', 'Date must be in YYYY-MM-DD format.', 'invalid_format'),
    ]);
}
```

Isso rejeita corretamente `"not-a-date"` mas aceita `"2026-02-30"`. Para validação semântica estrita, adicione uma verificação de round-trip `DateTimeImmutable`:

```php
// Validação mais estrita (recomendada para produção):
$dt = DateTimeImmutable::createFromFormat('Y-m-d', $completedOn);
if ($dt === false || $dt->format('Y-m-d') !== $completedOn) {
    throw new ValidationException([...]);
}
```

---

## Segurança de parâmetros de caminho

O `{id}` do caminho é convertido para `int` com fallback zero:

```php
$id = (int) ($req->getAttribute(Router::PARAMETERS_ATTRIBUTE, [])['id'] ?? 0);
```

Strings não numéricas viram `0`. Nenhum hábito com `id = 0` existe, então o handler cai na verificação `null` e retorna 404. Isso evita a necessidade de `ctype_digit()` aqui, mas note que `(int) "9abc"` produz `9` — uma rota que deve rejeitar caminhos não dígitos deve usar `ctype_digit()` em vez disso.

---

## Schema: delete em cascata

```sql
CREATE TABLE completions (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    habit_id     INTEGER NOT NULL REFERENCES habits(id) ON DELETE CASCADE,
    completed_on TEXT    NOT NULL,
    note         TEXT    NOT NULL DEFAULT '',
    UNIQUE(habit_id, completed_on)
);
```

`ON DELETE CASCADE` garante que as conclusões sejam removidas quando o hábito pai é deletado. Habilite a aplicação de foreign keys com `PRAGMA foreign_keys = ON` ao usar SQLite.

---

## ATK — Teste de ataque de cracker (FT224)

Cada resultado abaixo documenta um vetor de ataque, o resultado observado e o veredicto: **BLOCKED** (seguro), **EXPOSED** (vulnerabilidade real) ou **ACCEPTED BY DESIGN** (trade-off intencional documentado).

### ATK-01 — Sem autenticação em nenhum endpoint

**Ataque**: Criar, ler ou deletar hábitos sem nenhuma credencial.

```http
POST /habits
Content-Type: application/json

{"name": "Hábito do Atacante", "frequency": "daily"}
```

**Observado**: `201 Created` — sucesso sem token, sessão ou chave.

**Veredicto**: **EXPOSED** (por design para demo FT24).
Rastreadores de hábitos em produção DEVEM proteger mutações por trás de autenticação.
O `MachineApiKeyMiddleware` ou middleware Bearer JWT do NENE2 cobre isso.

---

### ATK-02 — Sem propriedade: ler / deletar qualquer hábito

**Ataque**: Sem saber de quem é o hábito, enumerar e deletar todos os hábitos.

```http
GET /habits         → lista todos os hábitos do sistema
DELETE /habits/1    → deleta o hábito #1 independente de quem criou
```

**Observado**: `200 OK` na listagem, `200 OK` no delete.

**Veredicto**: **EXPOSED** (por design para demo FT24).
Adicionar coluna `user_id`, verificação de propriedade em caminhos de escrita, e 404 (não 403) em acesso não autorizado (proteção IDOR — veja FT222 `notificationlog`).

---

### ATK-03 — SQL injection via queries parametrizadas

**Ataque**: Injetar SQL via `name`, `frequency` ou `completed_on`.

```json
{"name": "x' OR '1'='1", "frequency": "daily"}
{"completed_on": "2026-01-01' OR '1'='1"}
```

**Observado**: Nome armazenado verbatim. Conclusão rejeitada pela regex de formato de data antes de chegar à camada de BD.

**Veredicto**: **BLOCKED** — todas as queries usam statements parametrizados PDO. A allowlist de frequência bloqueia injeção via aquele campo na camada de aplicação.

---

### ATK-04 — Data semanticamente inválida aceita

**Ataque**: Enviar uma data estruturalmente correta mas inválida no calendário.

```json
{"completed_on": "2026-02-30"}
{"completed_on": "2026-13-01"}
{"completed_on": "0000-00-00"}
```

**Observado**: `201 Created` — regex `^\d{4}-\d{2}-\d{2}$` passa; PDO armazena a string verbatim; `DateTimeImmutable` normaliza silenciosamente (ex.: `2026-02-30` vira `2026-03-02`), corrompendo contagens de sequência.

**Veredicto**: **EXPOSED** — adicionar verificação de round-trip:
```php
$dt = DateTimeImmutable::createFromFormat('Y-m-d', $completedOn);
if ($dt === false || $dt->format('Y-m-d') !== $completedOn) {
    throw new ValidationException([...]);
}
```

---

### ATK-05 — IDs de caminho não numéricos

**Ataque**: Enviar valores não dígitos ou negativos como `{id}`.

```http
GET  /habits/abc
GET  /habits/-1
GET  /habits/0
GET  /habits/1.5
```

**Observado**: Todos retornam `404 Not Found`. `(int) "abc"` = `0`, `(int) "-1"` = `-1`, `(int) "1.5"` = `1`. Nenhum hábito existe com esses IDs, então `findById()` retorna `null`.

**Veredicto**: **BLOCKED** na prática (nenhum hábito com ID ≤ 0 existe). Porém `(int) "9abc"` = `9` — se um hábito com ID 9 existir, seria retornado. Use `ctype_digit()` para validação estrita de ID de caminho quando a diferença importar.

---

### ATK-06 — Conclusão duplicada na mesma data

**Ataque**: POST do mesmo `(habit_id, completed_on)` duas vezes para inflar sequências.

```http
POST /habits/1/completions {"completed_on": "2026-05-20"}
POST /habits/1/completions {"completed_on": "2026-05-20"}
```

**Observado**: Segunda requisição retorna `409 Conflict` — a constraint UNIQUE na camada do BD é acionada, `AlreadyCompletedException` é capturada e uma resposta Problem Details é retornada.

**Veredicto**: **BLOCKED** — a constraint do BD é a guarda autorizada; a camada de aplicação mapeia para 409 bem-formado.

---

### ATK-07 — Payload XSS em name/note

**Ataque**: Armazenar tag de script em `name` ou `note`.

```json
{"name": "<script>alert(document.cookie)</script>", "frequency": "daily"}
```

**Observado**: `201 Created`. O payload é armazenado verbatim e retornado como está nas respostas JSON.

**Veredicto**: **ACCEPTED BY DESIGN** — esta é uma API JSON; escapamento é responsabilidade do cliente de renderização. O servidor não produz HTML a partir desses campos. Documente claramente este contrato na especificação da API.

---

### ATK-08 — Nome de hábito extremamente longo

**Ataque**: Enviar um nome com dezenas de milhares de caracteres para exaurir armazenamento ou causar serialização lenta.

```php
'name' => str_repeat('A', 50_000)
```

**Observado**: `201 Created` — nenhum limite de comprimento é aplicado na camada de aplicação. SQLite TEXT não tem limite; a linha é inserida.

**Veredicto**: **EXPOSED** — adicionar verificação de comprimento máximo (ex.: 200 chars) no bloco de validação do controller e retornar 422:
```php
if (mb_strlen($name) > 200) {
    $errors[] = new ValidationError('name', 'Name must not exceed 200 characters.', 'max_length');
}
```

---

### ATK-09 — Nome de hábito somente com espaços

**Ataque**: Enviar um nome que seja todo espaço em branco.

```json
{"name": "   "}
```

**Observado**: `422 Unprocessable Entity` — `trim()` colapsa o valor para `''`, o que aciona o erro de validação `required`.

**Veredicto**: **BLOCKED** — `trim()` antes da verificação de string vazia cobre isso.

---

### ATK-10 — Manipulação de sequência via parâmetro `?today=`

**Ataque**: Sobrescrever a data de referência para reivindicar uma sequência histórica.

```http
GET /habits/1/streak?today=2099-12-31
GET /habits/1/streak?today=not-a-date
```

**Observado**: `today=2099-12-31` → sequência = 0 (sem conclusões no futuro). `today=not-a-date` → PHP `DateTimeImmutable` lança uma exceção interna no valor malformado (vira 500 no handler de erro padrão).

**Veredicto**: **PARCIALMENTE EXPOSED** — validar `today` com regex ou verificação de round-trip antes de passar para `currentStreak()`:
```php
$today = QueryStringParser::string($req, 'today') ?? date('Y-m-d');
$dt    = DateTimeImmutable::createFromFormat('Y-m-d', $today);
if ($dt === false || $dt->format('Y-m-d') !== $today) {
    $today = date('Y-m-d'); // fallback para data do servidor
}
```

---

### ATK-11 — Conclusão em hábito inexistente

**Ataque**: POST de conclusão para um ID de hábito que não existe.

```http
POST /habits/99999/completions
{"completed_on": "2026-05-20"}
```

**Observado**: `404 Not Found` — `findById(99999)` retorna `null` e o controller retorna a resposta de não-encontrado antes de tentar o INSERT.

**Veredicto**: **BLOCKED** — a verificação de existência acontece antes da escrita no BD.

---

### ATK-12 — Path traversal / injection em parâmetros de query

**Ataque**: Injetar strings de path-traversal ou injeção de shell via filtro `frequency`.

```http
GET /habits?frequency=../../../etc/passwd
GET /habits?frequency='; DROP TABLE habits; --
```

**Observado**: Ambos retornam `200 OK` com array `habits` vazio. O valor de `frequency` é usado apenas em `array_filter` com comparação estrita `===` contra valores armazenados. Nenhuma query SQL é construída a partir dele.

**Veredicto**: **BLOCKED** — filtro por parâmetro de query é aplicado em memória PHP, não como cláusula SQL `WHERE` raw. Nenhum I/O de arquivo ou execução de shell é acionado.

---

## Resumo ATK

| # | Vetor | Veredicto |
|---|-------|-----------|
| ATK-01 | Sem autenticação | EXPOSED (por design) |
| ATK-02 | Sem propriedade / IDOR | EXPOSED (por design) |
| ATK-03 | SQL injection | BLOCKED |
| ATK-04 | Data semanticamente inválida | EXPOSED |
| ATK-05 | ID de caminho não numérico | BLOCKED |
| ATK-06 | Conclusão duplicada | BLOCKED |
| ATK-07 | Armazenamento de payload XSS | ACCEPTED BY DESIGN |
| ATK-08 | Comprimento de nome ilimitado | EXPOSED |
| ATK-09 | Nome somente com espaços | BLOCKED |
| ATK-10 | Manipulação `?today=` | PARTIALLY EXPOSED |
| ATK-11 | Conclusão em hábito inexistente | BLOCKED |
| ATK-12 | Path traversal / injection em QS | BLOCKED |

**Vulnerabilidades reais a corrigir antes de produção**:
1. **ATK-01/02** — Adicionar autenticação e propriedade
2. **ATK-04** — Adicionar validação semântica de data (round-trip via `DateTimeImmutable`)
3. **ATK-08** — Adicionar verificação de comprimento máximo `mb_strlen()` em `name`/`note`
4. **ATK-10** — Validar `?today=` antes de passar para a lógica de negócio

---

## Howtos relacionados

- [`notification-inbox.md`](notification-inbox.md) — padrão de proteção IDOR (404 em leitura não autorizada)
- [`expense-tracker.md`](expense-tracker.md) — verificações de tipo `is_int()` estritas e validação de round-trip de data ISO
- [`session-management.md`](session-management.md) — camada de autenticação para adicionar sobre este padrão
