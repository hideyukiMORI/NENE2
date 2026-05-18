# Adicionar um health check

Este guia mostra como estender o endpoint `GET /health` com verificações de dependências usando
`HealthCheckInterface`.

**Pré-requisito**: Você tem uma aplicação NENE2 funcionando. Caso contrário, comece pelo [Tutorial](../tutorial/first-api.md).

---

## Como os health checks funcionam

`GET /health` sempre retorna um payload base:

```json
{ "service": "NENE2", "status": "ok", "timestamp": "2026-05-18T12:00:00+00:00" }
```

Quando você registra implementações de `HealthCheckInterface`, o endpoint adiciona um mapa `checks`:

- Todas as verificações passam → `200 OK`, `"status": "ok"`, cada verificação mostra `"ok"`
- Qualquer verificação falha → `503 Service Unavailable`, `"status": "degraded"`, a verificação com falha mostra `"error"`

---

## Início rápido

```php
use Nene2\Http\HealthCheckInterface;
use Nene2\Http\RuntimeApplicationFactory;
use Nyholm\Psr7\Factory\Psr17Factory;

final class CacheHealthCheck implements HealthCheckInterface
{
    public function name(): string { return 'cache'; }
    public function check(): bool { return $this->ping(); }
    private function ping(): bool { return true; }
}

$psr17 = new Psr17Factory();
$app = (new RuntimeApplicationFactory(
    $psr17,
    $psr17,
    healthChecks: [new CacheHealthCheck()],
))->create();
```

Resposta saudável (200 OK):
```json
{ "service": "NENE2", "status": "ok", "timestamp": "...", "checks": { "cache": "ok" } }
```

Resposta degradada (503 Service Unavailable):
```json
{ "service": "NENE2", "status": "degraded", "timestamp": "...", "checks": { "cache": "error" } }
```

---

## Usar a implementação de referência DatabaseHealthCheck

`src/Example/Health/DatabaseHealthCheck` é uma verificação pronta para conectividade PDO com banco de dados.
Ela faz parte de `src/Example/` — copie e adapte-a para o seu próprio projeto.

```php
use Nene2\Example\Health\DatabaseHealthCheck;

$app = (new RuntimeApplicationFactory(
    $psr17,
    $psr17,
    healthChecks: [new DatabaseHealthCheck($pdoConnection)],
))->create();
```

> **Nota**: `DatabaseHealthCheck` está em `src/Example/` — é uma implementação de referência,
> não uma superfície de API estável. Copie-a para sua aplicação e adapte-a às suas necessidades.

---

## Múltiplos health checks

Passe quantas verificações forem necessárias. Qualquer falha degrada o status geral.

```php
$app = (new RuntimeApplicationFactory(
    $psr17,
    $psr17,
    healthChecks: [
        new DatabaseHealthCheck($pdoConnection),
        new CacheHealthCheck($redis),
        new ExternalApiHealthCheck($httpClient),
    ],
))->create();
```

---

## Tratar exceções nas verificações

Se `check()` lançar uma exceção, o framework a trata como `false` — o status se torna `"degraded"`,
a verificação mostra `"error"`. Você não precisa capturar exceções dentro de `check()`.

---

## Próximo passo

Consulte [Endpoints HTTP](../reference/http-endpoints.md) para o esquema completo de resposta do `/health`,
ou [Adicionar limitação de taxa](./add-rate-limiting.md) para proteção de taxa de requisições.
