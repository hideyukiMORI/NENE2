# Como Fazer: API de Página de Status de Serviço

> **NENE2 Field Trial 185** — Rastreamento de saúde de componentes, gerenciamento do ciclo de vida de incidentes,
> proteção de chave admin com `V::secret()` + `hash_equals()`.

---

## O Que Este Trial Prova

Uma API de página de status de serviço precisa de:
1. **Rastreamento de status de componentes** — operational / degraded / partial_outage / major_outage
2. **Ciclo de vida de incidentes** — investigating → identified → monitoring → resolved
3. **Guarda de imutabilidade** — incidentes resolvidos não podem ser atualizados (impede reabertura)
4. **Proteção de chave admin** — `V::secret()` aplica comparação de tempo constante para operações de escrita
5. **Imposição de enum de status** — allowlist `V::enum()` impede injeção de valor desconhecido

---

## API

| Método | Caminho | Auth | Descrição |
|---|---|---|---|
| `GET` | `/components` | — | Listar todos os componentes (público) |
| `POST` | `/components` | X-Admin-Key | Criar um componente |
| `PATCH` | `/components/{id}` | X-Admin-Key | Atualizar status do componente |
| `GET` | `/incidents` | — | Listar incidentes (público, `?open=1` para ativos) |
| `GET` | `/incidents/{id}` | — | Detalhe do incidente com linha do tempo de atualizações |
| `POST` | `/incidents` | X-Admin-Key | Criar um incidente |
| `PATCH` | `/incidents/{id}` | X-Admin-Key | Atualizar status do incidente |
| `POST` | `/incidents/{id}/updates` | X-Admin-Key | Adicionar mensagem de atualização |

---

## Padrão Principal: Auth de Chave Admin com `V::secret()`

```php
// V::secret() verifica: $expected !== '' && hash_equals($expected, $actual)
private function requireAdmin(ServerRequestInterface $request): bool
{
    return V::secret($this->adminKey, $request->getHeaderLine('X-Admin-Key'));
}

// Uso em todo handler de escrita:
if (!$this->requireAdmin($request)) {
    return $this->responseFactory->create(['error' => 'X-Admin-Key is required.'], 401);
}
```

**Por que `V::secret()` e não `=== $key`:**
- `===` é curto-circuito: o timing varia com o comprimento da correspondência → oráculo de timing
- `hash_equals()` é de tempo constante independentemente de onde as strings diferem
- A guarda `$expected !== ''` impede aceitar acidentalmente chaves vazias

---

## Imposição de Enum de Status com `V::enum()`

```php
// V::enum(mixed $raw, string $enumClass): ?\BackedEnum
// Passa o nome da classe — retorna instância de enum tipado ou null

$statusEnum = V::enum($body['status'] ?? null, ComponentStatus::class);

if (!$statusEnum instanceof ComponentStatus) {
    return $this->responseFactory->create(
        ['error' => 'status must be one of: ' . implode(', ', ComponentStatus::values()) . '.'],
        422,
    );
}

// $statusEnum já é o enum tipado correto — sem necessidade de ::from()
$component = $this->repository->updateComponentStatus($id, $statusEnum);
```

**Por que a imposição de enum importa:**
- Sem ela, strings arbitrárias chegam ao banco
- Vetores de injeção SQL `ORDER BY status` são bloqueados
- A allowlist são os próprios casos do enum — sempre sincronizada

---

## Ciclo de Vida do Incidente e Guarda de Transição

```php
enum IncidentStatus: string
{
    case Investigating = 'investigating';
    case Identified    = 'identified';
    case Monitoring    = 'monitoring';
    case Resolved      = 'resolved';

    public function isResolved(): bool
    {
        return $this === self::Resolved;
    }
}
```

**Guarda de transição em todo handler de escrita:**
```php
$incident = $this->repository->findIncidentById($id);

// Incidentes resolvidos são imutáveis — impede reabertura acidental
if ($incident->status->isResolved()) {
    return $this->responseFactory->create(
        ['error' => 'Resolved incidents cannot be updated.'],
        409,
    );
}
```

**Por que 409 (Conflict) e não 422 (Unprocessable):**
- A requisição é sintaticamente válida
- O conflito é com o estado atual do recurso
- 409 comunica "requisição válida, momento errado"

---

## Valores de Status do Componente

```php
enum ComponentStatus: string
{
    case Operational   = 'operational';    // todos os sistemas OK
    case Degraded      = 'degraded';       // desempenho reduzido
    case PartialOutage = 'partial_outage'; // algumas funcionalidades indisponíveis
    case MajorOutage   = 'major_outage';   // falha total do serviço
}
```

---

## Timestamp Automático de `resolved_at`

```php
public function updateIncidentStatus(int $id, IncidentStatus $status): ?Incident
{
    $now        = $this->now();
    $resolvedAt = $status->isResolved() ? $now : null;

    $stmt = $this->pdo->prepare(
        'UPDATE incidents SET status = :status, resolved_at = :resolved_at, updated_at = :now WHERE id = :id'
    );
    $stmt->execute(['status' => $status->value, 'resolved_at' => $resolvedAt, ...]);
}
```

O timestamp `resolved_at` é definido pelo servidor — nunca do corpo da requisição.

---

## Análise de ID Inteiro (Sem Injeção)

```php
private function parseId(ServerRequestInterface $request, string $param): ?int
{
    $raw = Router::param($request, $param);

    // ctype_digit: rejeita negativos, floats, strings, path traversal
    if ($raw === null || !ctype_digit($raw)) {
        return null;
    }

    $id = (int) $raw;

    return $id > 0 ? $id : null; // também rejeita zero
}
```

---

## Filtro de Incidente Aberto

```php
// ?open=1 filtra incidentes resolvidos
$openOnly = isset($params['open']) && $params['open'] === '1';

if ($openOnly) {
    $stmt = $pdo->prepare(
        "SELECT * FROM incidents WHERE status != 'resolved' ORDER BY created_at DESC"
    );
} else {
    $stmt = $pdo->query('SELECT * FROM incidents ORDER BY created_at DESC');
}
```

---

## Exemplo Completo do Ciclo de Vida do Incidente

```
POST /incidents          → 201 {status: "investigating", impact: "major"}
POST /incidents/1/updates → 201 {message: "Root cause identified."}
PATCH /incidents/1       → 200 {status: "identified"}
PATCH /incidents/1       → 200 {status: "monitoring"}
PATCH /incidents/1       → 200 {status: "resolved", resolved_at: "2026-05-26T..."}
PATCH /incidents/1       → 409 Resolved incidents cannot be updated.
GET /incidents?open=1    → 200 {count: 0}  — resolvido não aparece mais
```

---

## Resultados dos Testes

```
46 testes / 93 assertivas — todos PASS
PHPStan nível 8 — sem erros
PHP CS Fixer — limpo
```

---

## Principais Aprendizados

| Padrão | Regra |
|---|---|
| Auth de chave admin | `V::secret()` — `hash_equals()` de tempo constante, guarda chave vazia |
| Validação de enum | `V::enum($raw, EnumClass::class)` — retorna enum tipado ou null |
| Guarda de transição | Verificar estado atual antes de aplicar mudança — 409 para resolvido |
| `resolved_at` | Timestamp definido pelo servidor, nunca do corpo da requisição |
| IDs inteiros | `ctype_digit()` + guarda `> 0` — rejeita strings, negativos, zero |
| Leitura pública | Sem auth para endpoints GET — páginas de status são para ser públicas |
| Histórico imutável | Atualizações de incidente são somente adição — sem edição/exclusão |

Exemplo completo: [`../NENE2-FT/statuslog/`](https://github.com/hideyukiMORI/NENE2-examples) no repositório de exemplos.
