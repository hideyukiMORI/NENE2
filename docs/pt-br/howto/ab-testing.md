# Como Fazer: Framework de Testes A/B

> **Referência FT**: FT293 (`NENE2-FT/ablog`) — Framework de experimentos A/B: atribuição determinística de variante ponderada via seed crc32, máquina de estados draft→active→stopped, atribuição idempotente com UNIQUE(experiment_id, user_id), agregação CVR em SQL, 16 testes / 26 asserções PASS.

Execute experimentos controlados atribuindo usuários a variantes e coletando eventos de conversão.

## Schema

```sql
CREATE TABLE experiments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL UNIQUE,
    description TEXT NOT NULL DEFAULT '',
    status TEXT NOT NULL DEFAULT 'draft' CHECK(status IN ('draft', 'active', 'stopped')),
    created_at TEXT NOT NULL, updated_at TEXT NOT NULL
);
CREATE TABLE experiment_variants (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    experiment_id INTEGER NOT NULL REFERENCES experiments(id) ON DELETE CASCADE,
    name TEXT NOT NULL, weight INTEGER NOT NULL DEFAULT 100,
    UNIQUE(experiment_id, name)
);
CREATE TABLE experiment_assignments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    experiment_id INTEGER NOT NULL REFERENCES experiments(id) ON DELETE CASCADE,
    user_id TEXT NOT NULL, variant_id INTEGER NOT NULL REFERENCES experiment_variants(id),
    assigned_at TEXT NOT NULL, UNIQUE(experiment_id, user_id)
);
CREATE TABLE experiment_events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    experiment_id INTEGER NOT NULL REFERENCES experiments(id) ON DELETE CASCADE,
    assignment_id INTEGER NOT NULL REFERENCES experiment_assignments(id),
    event_type TEXT NOT NULL, created_at TEXT NOT NULL
);
```

## Rotas

| Método | Caminho | Descrição |
|--------|---------|-----------|
| `POST` | `/experiments` | Criar experimento (começa em `draft`) |
| `GET` | `/experiments` | Listar todos os experimentos |
| `GET` | `/experiments/{id}` | Obter experimento + variantes |
| `PUT` | `/experiments/{id}/status` | Transicionar status |
| `POST` | `/experiments/{id}/variants` | Adicionar uma variante |
| `POST` | `/experiments/{id}/assign` | Atribuir usuário a uma variante (idempotente) |
| `POST` | `/experiments/{id}/events` | Registrar um evento de conversão |
| `GET` | `/experiments/{id}/results` | CVR agregado por variante |

## Ciclo de Vida do Status

```
draft → active → stopped
```

Rejeite transições inválidas com 422:

```php
private const array VALID_TRANSITIONS = [
    'draft'   => ['active'],
    'active'  => ['stopped'],
    'stopped' => [],
];

$allowed = self::VALID_TRANSITIONS[$current] ?? [];
if (!in_array($status, $allowed, true)) {
    throw new ValidationException([...]);
}
```

## Atribuição Determinística de Variante

Os usuários devem sempre cair na mesma variante — use `crc32` para um bucket reproduzível e sem estado:

```php
class VariantAssigner
{
    /** @param list<array<string, mixed>> $variants */
    public function assign(array $variants, string $userId, int $experimentId): ?array
    {
        $totalWeight = array_sum(array_column($variants, 'weight'));
        $seed        = abs(crc32($userId . ':' . $experimentId));
        $bucket      = $seed % $totalWeight;

        $cumulative = 0;
        foreach ($variants as $v) {
            $cumulative += (int) $v['weight'];
            if ($bucket < $cumulative) {
                return $v;
            }
        }
        return $variants[0];
    }
}
```

O banco de dados armazena a atribuição na primeira chamada; chamadas subsequentes retornam a variante armazenada — determinismo + verdade do banco de dados.

## Atribuição Idempotente

```php
// Retornar atribuição existente sem re-sortear
$existing = $this->repo->findAssignment($id, $userId);
if ($existing !== null) {
    return $this->json->create($existing);   // 200, não 201
}
// Primeira vez: calcular e armazenar
$variant      = $this->assigner->assign($variants, $userId, $id);
$assignmentId = $this->repo->createAssignment($id, $userId, $variant['id'], $now);
return $this->json->create($assignment, 201);
```

## Agregação de Resultados (CVR)

```sql
SELECT ev.id AS variant_id, ev.name AS variant_name,
       COUNT(DISTINCT ea.id) AS assignments,
       COUNT(ee.id) AS events
FROM experiment_variants ev
LEFT JOIN experiment_assignments ea ON ea.variant_id = ev.id
LEFT JOIN experiment_events ee ON ee.assignment_id = ea.id
WHERE ev.experiment_id = ?
GROUP BY ev.id, ev.name, ev.weight
ORDER BY ev.id ASC
```

Em seguida, calcule o CVR no PHP:

```php
$row['cvr'] = $assignments > 0 ? round($events / $assignments, 4) : 0.0;
```

## Salvaguardas

- Apenas experimentos `active` aceitam atribuições (409 caso contrário).
- Eventos exigem que o usuário esteja atribuído (404 caso contrário).
- `UNIQUE(experiment_id, user_id)` previne atribuição dupla no nível do banco de dados.
- Os pesos devem ser inteiros positivos; variantes com peso zero são rejeitadas (422).

---

## O Que NÃO Fazer

| Anti-padrão | Risco |
|---|---|
| Atribuição aleatória (não determinística) | O mesmo usuário recebe variantes diferentes em cada chamada; experiência inconsistente |
| Sem `UNIQUE(experiment_id, user_id)` | Atribuições concorrentes criam linhas duplicadas; o usuário acaba em múltiplas variantes |
| Permitir atribuição em status `draft` ou `stopped` | Experimentos em rascunho não têm variantes válidas; experimentos parados não devem coletar novos dados |
| Permitir transições de status para trás | `stopped → active` reabre um experimento encerrado; dados históricos contaminados |
| Sem validação de peso (permitir 0) | Peso total zero causa divisão por zero no cálculo do bucket |
| Calcular CVR na aplicação com todas as linhas | Buscar todas as linhas e iterar; use agregação SQL com `GROUP BY` |
| Sem validação de evento → atribuição | Eventos sem atribuição válida distorcem as taxas de conversão por variante |
