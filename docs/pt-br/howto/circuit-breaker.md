# Como Fazer: Circuit Breaker

> **Referência FT**: FT298 (`NENE2-FT/circuitlog`) — Padrão circuit breaker: máquina de três estados closed/open/half_open, limite de falhas configurável, transição automática half_open baseada em timeout, 503 Service Unavailable em circuito aberto, verificação readonly `isCallAllowed()`, 15 testes / 28 asserções PASS.

O padrão circuit breaker previne falhas em cascata ao chamar serviços externos. Em vez de deixar chamadas lentas ou com falha se acumularem, o circuito dispara aberto e imediatamente rejeita chamadas até que a dependência se recupere.

## Três estados

```
Closed ──(N falhas consecutivas)──▶ Open ──(timeout expirado)──▶ Half-Open
  ▲                                                                    │
  └──────────────────(sucesso)────────────────────────────────────────┘
  Half-Open ──(falha)──▶ Open
```

| Estado | Comportamento |
|---|---|
| **Closed** | Normal — chamadas passam. Contador de falhas incrementa a cada erro. |
| **Open** | Chamadas imediatamente rejeitadas com 503. Abre por `timeout_seconds` após `failure_threshold` falhas consecutivas. |
| **Half-Open** | Uma única chamada de sonda é permitida. Sucesso → Closed (reset). Falha → Open novamente. |

## Schema

```sql
CREATE TABLE IF NOT EXISTS circuits (
    id                INTEGER PRIMARY KEY AUTOINCREMENT,
    name              TEXT    NOT NULL UNIQUE,
    state             TEXT    NOT NULL DEFAULT 'closed',
    failure_count     INTEGER NOT NULL DEFAULT 0,
    failure_threshold INTEGER NOT NULL DEFAULT 5,
    open_until        TEXT,
    half_open_at      TEXT,
    last_failure_at   TEXT,
    updated_at        TEXT    NOT NULL
);
```

O nome do circuito é tipicamente o identificador do serviço externo (ex.: `payment-gateway`, `email-svc`). Múltiplos circuitos independentes podem coexistir.

## Registrando resultados

```php
// Após uma chamada bem-sucedida ao serviço externo:
$this->repo->recordSuccess($circuitName, $now);

// Após uma chamada com falha:
$this->repo->recordFailure($circuitName, $now, timeoutSeconds: 30);
```

`recordFailure()` decide a transição:
- Se `failure_count + 1 >= failure_threshold` → define estado como `open`, calcula `open_until = now + timeout`.
- Se ainda abaixo do limite → incrementa `failure_count`, permanece `closed`.
- Se em estado `half_open` → qualquer falha reabre imediatamente.

## Verificando se uma chamada é permitida

```php
$circuit = $this->repo->maybeTransitionToHalfOpen($name, $now);

if (!$circuit->isCallAllowed($now)) {
    // Retornar 503 imediatamente — não chamar o serviço externo
    return $problems->create($request, 'service-unavailable', 'Circuit is open.', 503);
}

// Tentar a chamada...
```

Chame `maybeTransitionToHalfOpen()` antes da verificação `isCallAllowed()` em cada requisição. Isso faz a transição `Open → Half-Open` uma vez que `open_until` tenha passado, permitindo a chamada de sonda.

```php
public function isCallAllowed(string $now): bool
{
    return match ($this->state) {
        CircuitState::Closed   => true,
        CircuitState::Open     => $now >= ($this->openUntil ?? ''),
        CircuitState::HalfOpen => true,
    };
}
```

## Temporização Half-Open

A transição `Open → Half-Open` é lazy: acontece da próxima vez que `maybeTransitionToHalfOpen()` é chamado após `open_until` ter expirado. Isso é intencional — evita timers em background e mantém as mudanças de estado vinculadas a requisições de entrada.

## Ajuste de limite de falhas e timeout

| Tipo de dependência | Limite recomendado | Timeout recomendado |
|---|---|---|
| Banco de dados (crítico) | 3–5 | 10–30s |
| API externa | 5–10 | 30–60s |
| Serviço não crítico | 10–20 | 60–120s |

Limites mais altos reduzem falsos positivos (picos temporários). Timeouts mais longos dão mais tempo de recuperação às dependências, mas prolongam a degradação visível para o cliente.

## Múltiplos circuitos por serviço

Use nomes de circuito distintos para domínios de falha distintos:

```
payment-gateway/charge
payment-gateway/refund
email-svc/transactional
email-svc/marketing
```

Isso previne que uma falha no endpoint de reembolso bloqueie as tentativas de cobrança.

## Resposta quando o circuito está Open

Retorne `503 Service Unavailable` com um cabeçalho `Retry-After` apontando para `open_until`:

```php
return $problems->create($request, 'service-unavailable', 'Circuit is open.', 503, null, [
    'open_until' => $circuit->openUntil,
]);
```

Clientes e balanceadores de carga que respeitam `503` podem parar de rotear para esta instância enquanto o circuito está aberto.

## Decisões de design

**Por que estado persistido em banco de dados em vez de memória?** O estado em memória é perdido na reinicialização e não é compartilhado entre workers do PHP-FPM. O estado no banco de dados é consistente entre todos os workers e sobrevive a reinicializações, ao custo de uma consulta extra por chamada protegida. Para caminhos de alto throughput, considere Redis com operações de incremento atômico.

**Por que transição Half-Open lazy?** Transições proativas em background requerem um agendador ou daemon. Transições lazy são mais simples, sem estado do ponto de vista do agendador, e suficientes para a maioria das APIs web onde o volume de requisições garante que a verificação seja executada prontamente.

**Por que `failure_count` reseta em qualquer sucesso?** Esta é a semântica de "falhas consecutivas". Uma alternativa é "taxa de falhas em uma janela deslizante" (ex.: >50% de falhas nos últimos 60 segundos). A janela deslizante é mais precisa para serviços com tráfego baixo mas constante; falhas consecutivas é mais simples e suficiente para serviços que estão ou ativos ou inativos.

---

## O Que NÃO Fazer

| Anti-padrão | Risco |
|---|---|
| Sem restrição `UNIQUE(name)` | Criações concorrentes produzem múltiplas linhas para o mesmo circuito |
| Sem timeout no circuito aberto | Circuito permanece aberto para sempre após violação do limite |
| Sem estado half_open | Circuito vai diretamente open → closed; sem sonda-então-verificar |
| Retornar 200 quando o circuito está aberto | Chamadores acham que a chamada teve sucesso; erros downstream ocultos |
| Sem `open_until` na resposta 503 | Chamadores tentam novamente imediatamente (thundering herd); inclua temporização de retry |
| Aceitar string `"true"` como sucesso | Confusão de tipo JSON; use `is_bool()` estritamente |
| Verificar `isCallAllowed()` sem `maybeTransitionToHalfOpen()` primeiro | Circuito aberto nunca se torna half_open; preso permanentemente |
| Somente estado em memória | Estado perdido na reinicialização do worker; sem compartilhamento entre workers PHP-FPM |
