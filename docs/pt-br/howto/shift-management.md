# Como Fazer: API de Gerenciamento de Turnos

> **Referência FT**: FT43 (`NENE2-FT/shiftlog`) — API de Agendamento de Turnos de Funcionários
> **VULN**: FT225 — avaliação de segurança/vulnerabilidade (V-01 a V-12)

Demonstra uma API de agendamento de turnos de funcionários com detecção de sobreposição, verificações com escopo de transação, comparações de datas ISO 8601 e handlers de exceção personalizados para erros de domínio.
A seção VULN avalia sistematicamente cada superfície de ataque e registra cada descoberta.

---

## Rotas

| Método   | Caminho                          | Descrição                                        |
|----------|----------------------------------|--------------------------------------------------|
| `GET`    | `/employees`                     | Listar funcionários (paginado)                   |
| `POST`   | `/employees`                     | Criar um funcionário                             |
| `GET`    | `/employees/{id}`                | Obter um único funcionário                       |
| `GET`    | `/employees/{id}/shifts`         | Listar turnos de um funcionário (paginado)       |
| `POST`   | `/shifts`                        | Agendar um turno (verificado para sobreposição)  |
| `GET`    | `/shifts/{id}`                   | Obter um único turno                             |
| `DELETE` | `/shifts/{id}`                   | Excluir um turno                                 |
| `GET`    | `/schedule`                      | Turnos dentro de uma janela de datas (`?from=&to=`) |
| `GET`    | `/summary/weekly`                | Horas por funcionário por semana                 |
| `GET`    | `/summary/overtime`              | Funcionários excedendo um limiar de horas        |

---

## Criando funcionários

```php
// POST /employees
$body = [
    'name'        => 'Alice',    // obrigatório, string não vazia
    'role'        => 'Barista',  // obrigatório, string não vazia
    'hourly_rate' => 18.50,      // obrigatório, numérico > 0
];
```

Verificações de tipo JSON estrito `is_int()` / `is_string()` são aplicadas. Strings vazias são
rejeitadas após `trim()`.

```php
if (!isset($body['hourly_rate'])
    || !is_numeric($body['hourly_rate'])
    || (float) $body['hourly_rate'] <= 0) {
    $errors[] = new ValidationError('hourly_rate', 'hourly_rate must be a positive number.', 'required');
}
```

> **Nota**: O schema também tem `CHECK(hourly_rate > 0)` no nível do banco como defesa em profundidade.
> Valide na camada de aplicação primeiro para retornar um 422 adequado.

---

## Agendamento de turnos com detecção de sobreposição

A detecção de sobreposição é executada dentro de uma transação de banco para prevenir condições de corrida:

```php
return $this->txManager->transactional(
    function (DatabaseQueryExecutorInterface $tx) use ($employeeId, $startsAt, $endsAt, $location, $now): Shift {
        $txRepo   = new self($tx, $this->txManager);
        $employee = $txRepo->findEmployeeById($employeeId);

        // Sobreposição: qualquer turno existente que intersecte [$startsAt, $endsAt)
        $overlap = $tx->fetchOne(
            "SELECT id FROM shifts
             WHERE employee_id = ?
               AND starts_at < ?
               AND ends_at   > ?",
            [$employeeId, $endsAt, $startsAt],
        );

        if ($overlap !== null) {
            throw new ShiftOverlapException($employee->name, $startsAt, $endsAt);
        }

        $id = $tx->insert(
            'INSERT INTO shifts (employee_id, starts_at, ends_at, location, created_at) VALUES (?, ?, ?, ?, ?)',
```

O SQLite usa bloqueio com modo WAL; MySQL/PostgreSQL usam isolamento `REPEATABLE READ` ou `SERIALIZABLE`
quando o gerenciador de transação está configurado corretamente. Ambos inserts concorrentes não podem
ambos passar pela verificação de sobreposição.

---

## Avaliação de Vulnerabilidade (FT225)

### V-01 — Sem autenticação

**Ataque**: Criar funcionários, agendar ou excluir turnos sem credenciais.
**Observado**: `201 Created` / `204 No Content` — nenhum token necessário.
**Veredicto**: **EXPOSED** (por design para demo FT43). Adicione autenticação em produção.

---

### V-02 — Sem autorização / qualquer turno excluível

**Ataque**: Excluir o turno de outro funcionário.
**Observado**: `204 No Content` — sem verificação de propriedade.
**Veredicto**: **EXPOSED** (por design). Adicione autorização baseada em papel (admin/gerente) para mutações.

---

### V-03 — Injeção SQL

**Ataque**: Enviar payloads SQL em campos de string.
**Observado**: Queries parametrizadas previnem execução. Strings maliciosas são armazenadas/comparadas como literais.
**Veredicto**: **BLOCKED** — queries parametrizadas PDO impedem injeção SQL.

---

### V-04 — Condição de corrida na sobreposição

**Ataque**: Enviar dois inserts de turno sobrepostos simultâneos.
**Observado**: A verificação de sobreposição transacional impede ambos de ter sucesso simultaneamente.
**Veredicto**: **BLOCKED** — verificação de sobreposição transacional impede reservas duplas sob concorrência.

---

### V-05 — ends_at ≤ starts_at aceito

**Ataque**: Enviar um turno onde o horário de término é antes ou igual ao horário de início.

```json
{"employee_id": 1, "starts_at": "2026-05-27T10:00:00Z", "ends_at": "2026-05-27T09:00:00Z"}
{"employee_id": 1, "starts_at": "2026-05-27T10:00:00Z", "ends_at": "2026-05-27T10:00:00Z"}
```

**Observado**: `422 Unprocessable Entity` — o app compara strings (`$endsAt <= $startsAt`)
antes de inserir. O `CHECK(ends_at > starts_at)` do banco é um suporte.
**Veredicto**: **BLOCKED** — validação de duas camadas (app + restrição do banco).

---

### V-06 — Lacuna de validação de hourly_rate

**Ataque**: Enviar um valor negativo, zero ou string para `hourly_rate`.

```json
{"name": "X", "role": "Y", "hourly_rate": -10}
{"name": "X", "role": "Y", "hourly_rate": 0}
{"name": "X", "role": "Y", "hourly_rate": "free"}
```

**Observado**:
- Negativo/zero: A aplicação NÃO valida `hourly_rate > 0` na camada do controlador.
  Um valor negativo contorna a verificação do app e atinge o `CHECK(hourly_rate > 0)` do banco,
  que lança uma exceção do banco. Sem um handler explícito, isso vira um 500.
- String `"free"`: `is_numeric()` retorna false, então isso é rejeitado com 422.

**Veredicto**: **PARTIALLY EXPOSED** — adicione validação na camada de aplicação antes do insert no banco:
```php
if (!isset($body['hourly_rate'])
    || !is_numeric($body['hourly_rate'])
    || (float) $body['hourly_rate'] <= 0) {
    $errors[] = new ValidationError('hourly_rate', 'hourly_rate must be a positive number.', 'out_of_range');
}
```

---

### V-07 — Datetime ISO 8601 semanticamente inválido

**Ataque**: Enviar um turno com um datetime estruturalmente plausível mas inválido no calendário.

```json
{"starts_at": "2026-02-30T00:00:00Z", "ends_at": "2026-02-30T08:00:00Z", "employee_id": 1}
```

**Observado**: Aceito e armazenado. `DateTimeImmutable` normaliza silenciosamente `2026-02-30` para
`2026-03-02`, corrompendo o valor armazenado.

**Veredicto**: **EXPOSED** — adicione uma verificação de round-trip em ambos `starts_at` e `ends_at`:
```php
$dt = DateTimeImmutable::createFromFormat(DateTimeInterface::ATOM, $raw);
if ($dt === false || $dt->format(DateTimeInterface::ATOM) !== $raw) {
    $errors[] = new ValidationError('starts_at', 'starts_at must be a valid ISO 8601 datetime.', 'invalid_format');
}
```

---

### V-08 — Intervalo de datas ilimitado em queries agregadas

**Ataque**: Solicitar um resumo em um intervalo de datas arbitrariamente grande para esgotar memória ou causar uma query lenta.

```http
GET /summary/weekly?from=1900-01-01&to=2099-12-31
```

**Observado**: A query é executada em todas as linhas da tabela. Com um conjunto de dados grande pode causar uso excessivo de memória ou uma resposta de vários segundos.

**Veredicto**: **EXPOSED** — limite o intervalo máximo permitido (ex.: 90 dias) na camada do controlador:
```php
$maxDays = 90;
$diff    = (new DateTimeImmutable($to))->diff(new DateTimeImmutable($from));
if ($diff->days > $maxDays) {
    return $this->json->create(['error' => "Date range must not exceed {$maxDays} days."], 422);
}
```

---

### V-09 — Comprimento ilimitado de nome/papel de funcionário

**Ataque**: Criar um funcionário com um nome ou papel de dezenas de milhares de caracteres.
**Observado**: `201 Created` — SQLite TEXT é ilimitado; a linha é inserida.
**Veredicto**: **EXPOSED** — adicione verificações `mb_strlen()` e retorne 422.

---

### V-10 — String de local ilimitada

**Ataque**: Agendar um turno com uma string de local de comprimento arbitrário.
**Observado**: `201 Created` — sem limite de comprimento imposto.
**Veredicto**: **EXPOSED** — adicione verificação `mb_strlen($location) <= 200`.

---

### V-11 — Payload XSS em nome / papel / local

**Ataque**: Armazenar uma tag `<script>` em qualquer campo de texto livre.
**Observado**: `201 Created`. Valor retornado verbatim em respostas JSON.
**Veredicto**: **ACCEPTED BY DESIGN** — esta é uma API JSON; o escape é responsabilidade do cliente de renderização HTML. O servidor não emite HTML desses campos.

---

### V-12 — IDs de caminho não-numéricos

**Ataque**: Passar valores não-dígito ou negativos como `{id}`.
**Observado**: `404 Not Found` em cada caso.
**Veredicto**: **BLOCKED** na prática. Nota: `(int) "9abc"` = `9` — se um registro com ID 9 existir, seria retornado. Use `ctype_digit()` para validação estrita de ID de caminho quando a diferença importar.

---

## Resumo VULN

| # | Vetor de ataque | Veredicto |
|---|-----------------|-----------|
| V-01 | Sem autenticação | EXPOSED (por design) |
| V-02 | Sem autorização / qualquer turno excluível | EXPOSED (por design) |
| V-03 | Injeção SQL | BLOCKED |
| V-04 | Condição de corrida na sobreposição | BLOCKED |
| V-05 | ends_at ≤ starts_at | BLOCKED |
| V-06 | hourly_rate negativo contorna verificação do app | PARTIALLY EXPOSED |
| V-07 | Datetime ISO 8601 semanticamente inválido | EXPOSED |
| V-08 | Intervalo de datas ilimitado em queries agregadas | EXPOSED |
| V-09 | Nome/papel de funcionário ilimitado | EXPOSED |
| V-10 | String de local ilimitada | EXPOSED |
| V-11 | Armazenamento de payload XSS | ACCEPTED BY DESIGN |
| V-12 | IDs de caminho não-numéricos | BLOCKED |

**Vulnerabilidades reais a corrigir antes de produção**:
1. **V-01/02** — Adicionar autenticação e autorização baseada em papel
2. **V-06** — Adicionar validação `hourly_rate > 0` na camada de aplicação
3. **V-07** — Adicionar validação de round-trip ISO 8601 para campos de datetime
4. **V-08** — Limitar o intervalo máximo de datas em endpoints agregados (ex.: 90 dias)
5. **V-09/10** — Adicionar verificações de comprimento máximo `mb_strlen()` em todos os campos de texto livre

---

## Howtos relacionados

- [`notification-inbox.md`](notification-inbox.md) — padrão de proteção IDOR (404 em leitura/escrita não autorizada)
- [`prevent-double-booking.md`](prevent-double-booking.md) — prevenção transacional de reservas duplas
- [`expense-tracker.md`](expense-tracker.md) — validação de data ISO 8601 com round-trip
- [`resource-booking.md`](resource-booking.md) — limitação de intervalo de datas e queries de janela de tempo
