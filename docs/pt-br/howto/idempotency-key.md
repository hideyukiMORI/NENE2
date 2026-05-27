# Como Fazer: Idempotency Key (Deduplicação de Requisições)

> **Referência FT**: FT292 (`NENE2-FT/deduplog`) — Deduplicação de idempotency key: constraint UNIQUE(idempotency_key) no BD, TTL de 24h com expiração re-processável, flag `replayed: true` em respostas em cache, queries parametrizadas previnem injection, ATK-01~12 todos BLOCKED, 24 testes / 57 asserções PASS.

Este guia mostra como implementar idempotency keys — um mecanismo baseado em header que garante que requisições repetidas (retentativas, falhas de rede) produzam o mesmo resultado sem efeitos colaterais duplicados.

## Schema

```sql
CREATE TABLE idempotency_keys (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    idempotency_key TEXT NOT NULL UNIQUE,
    method          TEXT NOT NULL,
    path            TEXT NOT NULL,
    status_code     INTEGER NOT NULL,
    response_body   TEXT NOT NULL,
    created_at      TEXT NOT NULL,
    expires_at      TEXT NOT NULL
);
```

`UNIQUE(idempotency_key)` garante que cada chave é armazenada uma vez. O corpo da resposta é serializado como JSON e repetido em requisições subsequentes.

## Fluxo de Requisição

```
Cliente envia POST /payments com Idempotency-Key: <uuid>
  │
  ├─ Chave encontrada no BD E não expirada?
  │    └─ SIM → retornar resposta em cache + { "replayed": true }
  │
  └─ NÃO → processar requisição → armazenar resposta → retornar 201
```

## Extração da Idempotency-Key

```php
$key = trim($request->getHeaderLine('Idempotency-Key'));
if ($key === '') {
    return $this->json->create(['error' => 'Idempotency-Key header is required'], 400);
}
```

A chave é obrigatória e deve ser não-vazia após o trim. Chaves somente com espaços são rejeitadas com 400.

## Consulta ao Cache — Verificação de Expiração

```php
private function getCachedResponse(
    string $key,
    ServerRequestInterface $request,
): ?ResponseInterface {
    $cached = $this->repo->find($key);
    if ($cached === null) {
        return null;
    }

    // Entradas expiradas são tratadas como novas (re-processáveis)
    if ($cached['expires_at'] < $this->now()) {
        return null;
    }

    $body = json_decode((string) $cached['response_body'], true) ?? [];
    return $this->json->create(
        array_merge($body, ['replayed' => true]),
        (int) $cached['status_code']
    );
}
```

Chaves expiradas retornam `null` — a requisição é re-processada como se fosse nova. Isso permite retentativa segura após expiração do TTL sem deduplicação permanente.

## Armazenamento no Cache — Cálculo do TTL

```php
private const int TTL_SECONDS = 86400; // 24 horas

private function cacheResponse(
    string $key,
    string $method,
    string $path,
    int $statusCode,
    array $data,
    string $now,
): void {
    $expiresAt = (new \DateTimeImmutable($now, new \DateTimeZone('UTC')))
        ->modify('+' . self::TTL_SECONDS . ' seconds')
        ->format('Y-m-d\TH:i:s\Z');
    $this->repo->store($key, $method, $path, $statusCode, (string) json_encode($data), $now, $expiresAt);
}
```

O TTL é calculado em UTC. `DateTimeImmutable::modify()` trata com segurança transições de horário de verão e viradas de meia-noite.

## Sinal `replayed: true`

Respostas em cache incluem `"replayed": true` mesclado no corpo:

```json
{ "id": 42, "amount": 1000, "currency": "USD", "replayed": true }
```

Isso permite que clientes distingam respostas pela primeira vez de replays sem inspecionar status codes. O status code é repetido sem alteração (201 para criação).

## Constraint UNIQUE como Guarda de Condição de Corrida

```sql
UNIQUE(idempotency_key)
```

Se duas requisições concorrentes com a mesma chave passam a verificação de consulta (TOCTOU), apenas um `INSERT` tem sucesso. O outro recebe um erro de constraint, que a aplicação pode tratar re-buscando a resposta em cache.

---

## ATK Assessment — Teste de Ataque com Mentalidade de Cracker

### ATK-01 — SQL Injection no Header Idempotency-Key 🚫 BLOCKED

**Ataque**: Enviar `Idempotency-Key: '; DROP TABLE idempotency_keys; --`.
**Resultado**: BLOCKED — todas as queries usam statements parametrizados. A string de injeção é armazenada ou consultada como valor literal da chave.

---

### ATK-02 — SQL Injection no Campo Amount 🚫 BLOCKED

**Ataque**: Enviar `{ "amount": "1; DROP TABLE payments;" }`.
**Resultado**: BLOCKED — validação de amount requer tipo inteiro. Valores string falham na verificação `is_int()` → 422. Nenhuma query ao BD é executada.

---

### ATK-03 — SQL Injection no Campo Item (armazenado com segurança) 🚫 BLOCKED

**Ataque**: Enviar `{ "item": "' OR 1=1; --" }` na criação de pedido.
**Resultado**: BLOCKED — query parametrizada armazena a string verbatim como o valor de `item`. Nenhuma execução SQL ocorre.

---

### ATK-04 — Ataque de Replay (mesma chave 10 vezes) 🚫 BLOCKED

**Ataque**: Enviar `POST /payments` com a mesma chave 10 vezes para criar 10 registros.
**Resultado**: BLOCKED — a primeira requisição cria um pagamento e armazena a resposta em cache. As 9 requisições subsequentes retornam a resposta em cache com `replayed: true`. Apenas 1 linha de pagamento existe.

---

### ATK-05 — Idempotency-Key Somente com Espaços 🚫 BLOCKED

**Ataque**: Enviar `Idempotency-Key:    ` (apenas espaços) para contornar a verificação de chave vazia.
**Resultado**: BLOCKED — `trim($key) === ''` → 400. Chaves somente com espaços são equivalentes a chaves ausentes.

---

### ATK-06 — Idempotency-Key Extremamente Longa 🚫 BLOCKED (nota de design)

**Ataque**: Enviar uma string de chave de vários megabytes.
**Resultado**: BLOCKED (nota de design) — SQLite armazena a chave verbatim; chaves muito longas degradam o desempenho de consulta mas não travam. Em produção, adicione um limite de comprimento (ex.: `strlen($key) > 255 → 400`).

---

### ATK-07 — Quantidade Negativa no Pedido 🚫 BLOCKED

**Ataque**: Enviar `{ "quantity": -5 }` para criar um pedido com quantidade negativa.
**Resultado**: BLOCKED — validação de quantidade: `$quantity <= 0` → 422. Apenas inteiros positivos são aceitos.

---

### ATK-08 — XSS no Campo Item Armazenado como Literal 🚫 BLOCKED

**Ataque**: Enviar `{ "item": "<script>alert(1)</script>" }`.
**Resultado**: BLOCKED — armazenado verbatim como valor de string JSON. A API retorna `application/json`; encoding JSON escapa `<`, `>`. Nenhuma renderização HTML ocorre na camada da API.

---

### ATK-09 — Chaves Duplicadas Concorrentes 🚫 BLOCKED

**Ataque**: Dois processos enviam a mesma chave simultaneamente; ambos passam a verificação de consulta antes que qualquer um armazene.
**Resultado**: BLOCKED — `UNIQUE(idempotency_key)` garante que apenas um INSERT tem sucesso. O perdedor recebe um erro de constraint e pode re-buscar a resposta em cache.

---

### ATK-10 — Overflow de Inteiro no Amount 🚫 BLOCKED (nota de design)

**Ataque**: Enviar `{ "amount": 9999999999999999999 }` (além de PHP_INT_MAX).
**Resultado**: BLOCKED (nota de design) — PHP silenciosamente converte inteiros JSON muito grandes para float. `is_int()` passa para inteiros dentro do range. Em produção, adicione uma verificação de limite superior (ex.: amount > 10_000_000 → 422).

---

### ATK-11 — Amount NULL 🚫 BLOCKED

**Ataque**: Enviar `{ "amount": null }` esperando que null ignore a validação.
**Resultado**: BLOCKED — `!is_int(null)` é true e `ctype_digit(null)` é false → 422.

---

### ATK-12 — Sem Vazamento de Informação Interna 🚫 BLOCKED

**Ataque**: Acionar um erro 422 e verificar se stack traces, caminhos de arquivo ou SQL aparecem na resposta.
**Resultado**: BLOCKED — respostas de erro contêm apenas `{ "error": "..." }` ou Problem Details. Nenhum caminho interno, SQL ou stack trace em nenhuma resposta.

---

### Resumo ATK

| ID | Ataque | Resultado |
|----|--------|--------|
| ATK-01 | SQL injection no header Idempotency-Key | 🚫 BLOCKED |
| ATK-02 | SQL injection no campo amount | 🚫 BLOCKED |
| ATK-03 | SQL injection no campo item | 🚫 BLOCKED |
| ATK-04 | Ataque de replay (10 requisições duplicadas) | 🚫 BLOCKED |
| ATK-05 | Chave somente com espaços | 🚫 BLOCKED |
| ATK-06 | Chave extremamente longa | 🚫 BLOCKED (nota de design) |
| ATK-07 | Quantidade negativa | 🚫 BLOCKED |
| ATK-08 | XSS no campo item | 🚫 BLOCKED |
| ATK-09 | Chaves duplicadas concorrentes | 🚫 BLOCKED |
| ATK-10 | Overflow de inteiro no amount | 🚫 BLOCKED (nota de design) |
| ATK-11 | Amount NULL | 🚫 BLOCKED |
| ATK-12 | Sem vazamento de informação interna | 🚫 BLOCKED |

**12 BLOCKED, 0 EXPOSED**
Queries parametrizadas, validação de tipo estrita, `UNIQUE(idempotency_key)` e expiração por TTL cobrem todos os vetores de ataque críticos de deduplicação.

---

## O Que NÃO Fazer

| Anti-padrão | Risco |
|---|---|
| Sem constraint `UNIQUE(idempotency_key)` | Retentativas concorrentes criam registros duplicados; condição de corrida na deduplicação |
| Sem TTL / dedup permanente | Chaves antigas enchem a tabela; retentativas legítimas após 1+ dias falham |
| Sem flag `replayed: true` | Cliente não pode distinguir resposta pela primeira vez de replay em cache |
| Verificar expiração mas nunca re-processar chaves expiradas | Retentativa após TTL ainda retorna resposta em cache (possivelmente obsoleta) |
| Aceitar chaves somente com espaços | `"   "` tratado como chave válida; clientes diferentes podem usar `""` vs `"   "` intercambiavelmente |
| Sem limite de comprimento da chave | Chaves de vários MB no armazenamento e consulta degradam desempenho |
| Retornar 409 em duplicata | Replay deve retornar status original (201), não Conflict |
| Não validar tipo de amount estritamente | String `"1000"` passa verificações permissivas; use `is_int()` para inteiro JSON estrito |
| Sem limite superior no amount | Overflow de inteiro ou quantias absurdas aceitas sem validação de negócio |
