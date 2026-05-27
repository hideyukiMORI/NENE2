# Como Fazer: API de Feature Flags

> **Referência FT**: FT270 (`NENE2-FT/featureflaglog`) — API de feature flags: avaliação em cadeia de prioridades (alvo de usuário → alvo de tenant → globally_enabled → hash de rollout_pct), atribuição de bucket determinístico baseado em crc32, kill switches por usuário/tenant, constraint de nome UNIQUE na flag, 21 testes / 31 asserções PASS.

Feature flags permitem alternar funcionalidades em tempo de execução sem fazer deploy de código. As decisões centrais são: onde armazenar o estado (DB vs config), como avaliar prioridade quando múltiplas regras se aplicam, e como lidar com percentuais de rollout sem rastreamento por usuário.

---

## Rotas

| Método | Caminho | Descrição |
|--------|---------|-----------|
| `POST` | `/flags` | Criar uma nova feature flag |
| `GET` | `/flags/{name}` | Obter detalhes da flag com alvos |
| `POST` | `/flags/{name}/toggle` | Ativar/desativar globally_enabled |
| `PUT` | `/flags/{name}/rollout` | Definir percentual de rollout (0–100) |
| `PUT` | `/flags/{name}/targets` | Fazer upsert de override por usuário ou tenant |
| `DELETE` | `/flags/{name}/targets/{type}/{id}` | Remover override específico |
| `POST` | `/flags/{name}/evaluate` | Avaliar a flag para um usuário/tenant |

---

## Componentes principais

- **Registro de feature flag**: uma linha por flag com nome, switch global on/off e percentual de rollout.
- **Alvos de flag**: overrides por usuário ou tenant que vencem sobre o estado global.
- **Avaliador**: aplica a cadeia de prioridades e retorna um booleano para um dado usuário.

## Schema

```sql
CREATE TABLE feature_flags (
    id               INTEGER PRIMARY KEY AUTOINCREMENT,
    name             TEXT    NOT NULL UNIQUE,
    description      TEXT    NOT NULL DEFAULT '',
    globally_enabled INTEGER NOT NULL DEFAULT 0,
    rollout_pct      INTEGER NOT NULL DEFAULT 0,  -- 0-100
    created_at       TEXT    NOT NULL
);

CREATE TABLE flag_targets (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    flag_id     INTEGER NOT NULL,
    target_type TEXT    NOT NULL,  -- 'user' | 'tenant'
    target_id   TEXT    NOT NULL,
    enabled     INTEGER NOT NULL DEFAULT 1,
    UNIQUE (flag_id, target_type, target_id),
    FOREIGN KEY (flag_id) REFERENCES feature_flags(id)
);
```

## Prioridade de avaliação

```php
final readonly class FlagEvaluator
{
    /** @param FlagTarget[] $targets */
    public function evaluate(FeatureFlag $flag, array $targets, string $userId, ?string $tenantId): bool
    {
        // 1. Alvo no nível de usuário vence primeiro
        foreach ($targets as $target) {
            if ($target->targetType === 'user' && $target->targetId === $userId) {
                return $target->enabled;
            }
        }

        // 2. Alvo no nível de tenant
        if ($tenantId !== null) {
            foreach ($targets as $target) {
                if ($target->targetType === 'tenant' && $target->targetId === $tenantId) {
                    return $target->enabled;
                }
            }
        }

        // 3. Switch global
        if ($flag->globallyEnabled) {
            return true;
        }

        // 4. Percentual de rollout: bucket determinístico via hash crc32
        if ($flag->rolloutPct > 0) {
            $bucket = abs(crc32($userId . '.' . $flag->name)) % 100;
            return $bucket < $flag->rolloutPct;
        }

        // 5. Padrão desabilitado
        return false;
    }
}
```

Ordem de prioridade (maior vence):
1. Alvo no nível de usuário (`target_type = 'user'`)
2. Alvo no nível de tenant (`target_type = 'tenant'`)
3. `globally_enabled = 1`
4. `rollout_pct > 0` com bucket baseado em hash
5. `false`

## Percentual de rollout — bucket determinístico

`crc32($userId . '.' . $flagName) % 100` produz um bucket estável por par (usuário, flag). O mesmo usuário sempre cai no mesmo bucket, então sua experiência é consistente entre requisições. Concatenar o nome da flag evita que todas as flags façam rollout para os mesmos usuários com `pct = 10`.

Importante: `crc32()` pode retornar valores negativos em sistemas 64-bit — use `abs()`.

## Alvos como overrides

Um alvo com `enabled = false` é um kill switch: ele desabilita a flag para aquele usuário ou tenant mesmo quando `globally_enabled = 1`. Esta é a forma canônica de excluir um usuário específico de um rollout já globalmente habilitado.

```php
// Kill switch no nível de usuário (sobrescreve habilitação global)
$repo->upsertTarget($flag->id, 'user', 'problem-user', false);

// Acesso antecipado para tenant (sobrescreve desabilitação global)
$repo->upsertTarget($flag->id, 'tenant', 'beta-tenant', true);
```

## Padrão de upsert para alvos

Alvos usam semântica de `INSERT OR REPLACE` / upsert — chamar o mesmo endpoint duas vezes com valores `enabled` diferentes atualiza a linha existente em vez de criar uma duplicata:

```php
$existing = $this->executor->fetchOne(
    'SELECT * FROM flag_targets WHERE flag_id = ? AND target_type = ? AND target_id = ?',
    [$flagId, $targetType, $targetId],
);

if ($existing !== null) {
    $this->executor->execute('UPDATE flag_targets SET enabled = ? WHERE id = ?', ...);
} else {
    $this->executor->execute('INSERT INTO flag_targets ...', ...);
}
```

A constraint UNIQUE em `(flag_id, target_type, target_id)` garante que exista no máximo um override por par (flag, alvo).

## Resposta de conflito para nomes de flag duplicados

`feature_flags.name` possui uma constraint UNIQUE. Em criação duplicada, o BD lança uma `RuntimeException`. Capture-a e retorne 409 Conflict em vez de 500:

```php
try {
    $this->executor->execute('INSERT INTO feature_flags ...', [...]);
} catch (\RuntimeException) {
    return null; // chamador mapeia null → 409
}
```

## Decisões de design

**Por que usar DB em vez de arquivo de config?**
Arquivos de config requerem deploy para alterar uma flag. Flags respaldadas por DB podem ser alternadas ao vivo sem tocar no código ou reiniciar processos.

**Por que hash determinístico para rollout em vez de aleatório?**
Seleção aleatória faz o mesmo usuário alternar entre habilitado/desabilitado entre requisições. Um hash estável dá a cada usuário uma experiência consistente durante toda a vida da flag.

**Por que permitir alvos com `enabled = false`?**
Um sistema de flags sem kill switches está incompleto. `enabled = false` é a forma mais segura de excluir um usuário de um rollout já globalmente habilitado — sem mudança de código, sem deploy.

**Por que separar `globally_enabled` e `rollout_pct`?**
`globally_enabled = 1` é um switch explícito de tudo-ou-nada. `rollout_pct` é para exposição gradual. Mantê-los separados evita sobrecarregar um campo com dois significados diferentes.

---

## Exemplos de respostas

**POST /flags** (201 Created):
```json
{
    "id": 1,
    "name": "new-checkout",
    "description": "New checkout flow",
    "globally_enabled": false,
    "rollout_pct": 0,
    "created_at": "2026-05-27 10:00:00"
}
```

**GET /flags/{name}** (200 OK):
```json
{
    "flag": {
        "id": 1,
        "name": "new-checkout",
        "globally_enabled": false,
        "rollout_pct": 30
    },
    "targets": [
        {
            "id": 1,
            "flag_id": 1,
            "target_type": "user",
            "target_id": "user-42",
            "enabled": true
        }
    ]
}
```

**POST /flags/{name}/evaluate** (200 OK):
```json
{
    "flag": "new-checkout",
    "user_id": "user-42",
    "enabled": true
}
```

---

## O Que NÃO Fazer

| Anti-padrão | Risco |
|---|---|
| Usar número aleatório para rollout por requisição | O mesmo usuário alterna entre habilitado/desabilitado entre requisições — UX inconsistente |
| Esquecer `abs()` em `crc32()` | crc32 pode retornar valores negativos em PHP 64-bit — módulo dá bucket errado |
| Permitir valores arbitrários de `target_type` | Enum não controlado torna a lógica de avaliação ilimitada; restringir a `'user'` e `'tenant'` |
| Sem `UNIQUE (flag_id, target_type, target_id)` | Alvos duplicados tornam a avaliação ambígua — primeira linha vence arbitrariamente |
| Usar nome da flag como `target_id` | Nome da flag pode mudar; usar IDs estáveis para segmentação de usuário/tenant |
| Retornar 500 em nome de flag duplicado | A violação de unicidade do nome é um erro de domínio, não erro de servidor; mapear para 409 Conflict |
