# Como Fazer: API de Feature Flag

> **Referência FT**: FT313 (`NENE2-FT/flaglog`) — Gerenciamento de feature flags: flags por ambiente, rollout percentual gradual com rollout_percent, overrides por usuário, endpoint de avaliação com resolução de override, validação de chave snake_case, 18 testes / 29 asserções PASS.

Este guia mostra como construir um sistema de feature flags que suporta configuração por ambiente, rollouts graduais por percentual e overrides por usuário.

## Schema

```sql
CREATE TABLE feature_flags (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    key             TEXT    NOT NULL,
    environment     TEXT    NOT NULL DEFAULT 'production',
    enabled         INTEGER NOT NULL DEFAULT 0,
    rollout_percent INTEGER NOT NULL DEFAULT 100,
    created_at      TEXT    NOT NULL,
    updated_at      TEXT    NOT NULL,
    UNIQUE (key, environment)
);

CREATE TABLE flag_overrides (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    flag_key   TEXT    NOT NULL,
    environment TEXT   NOT NULL DEFAULT 'production',
    user_id    TEXT    NOT NULL,
    enabled    INTEGER NOT NULL,
    created_at TEXT    NOT NULL,
    UNIQUE (flag_key, environment, user_id)
);
```

`key` deve corresponder a `^[a-z][a-z0-9_]*$` (snake_case). `rollout_percent` vai de 0 a 100.

## Endpoints

| Método | Caminho | Descrição |
|--------|---------|-----------|
| `PUT` | `/flags/{key}` | Criar ou atualizar uma flag |
| `GET` | `/flags` | Listar todas as flags (opcional `?environment=`) |
| `GET` | `/flags/{key}/evaluate` | Avaliar flag para um usuário (`?user_id=`) |
| `PUT` | `/flags/{key}/overrides/{userId}` | Definir override por usuário |
| `DELETE` | `/flags/{key}/overrides/{userId}` | Remover override por usuário |

## Upsert de Flag — PUT /flags/{key}

```php
// Corpo da requisição
{
    "enabled": true,
    "rollout_percent": 50,   // opcional, padrão 100
    "environment": "staging" // opcional, padrão "production"
}

// Resposta 200
{
    "key": "dark_mode",
    "enabled": true,
    "rollout_percent": 50,
    "environment": "staging",
    "created_at": "...",
    "updated_at": "..."
}
```

O mesmo endpoint cria ou atualiza (UPSERT por `key + environment`). Enviar `PUT` duas vezes com valores diferentes atualiza a flag.

## Validação de Chave

```php
// Chaves válidas (snake_case: a-z, 0-9, underscore, começa com letra)
dark_mode, beta_ui, new_feature_v2

// Inválidas — retorna 422
Dark-Mode   // maiúsculas + hífen
123flag     // começa com dígito
my flag     // espaço
```

```php
if (!preg_match('/^[a-z][a-z0-9_]*$/', $key)) {
    throw new ValidationException([
        ['field' => 'key', 'message' => 'Key must be snake_case.', 'code' => 'invalid-format'],
    ]);
}
```

## Validação do Rollout Percent

```php
if ($rolloutPercent < 0 || $rolloutPercent > 100) {
    throw new ValidationException([
        ['field' => 'rollout_percent', 'message' => 'Must be 0–100.', 'code' => 'out-of-range'],
    ]);
}
```

## Flags por Ambiente

```php
// Mesma chave, ambientes diferentes
PUT /flags/beta_ui  {"enabled": true,  "environment": "staging"}
PUT /flags/beta_ui  {"enabled": false, "environment": "production"}

// Listar por ambiente
GET /flags?environment=staging     → [{"key": "beta_ui", "enabled": true, ...}]
GET /flags?environment=production  → [{"key": "beta_ui", "enabled": false, ...}]
```

## Avaliar — Rollout + Override

```
GET /flags/{key}/evaluate?user_id={userId}
```

Ordem de resolução:
1. **Override vence**: se existir uma linha em `flag_overrides` para `(key, environment, user_id)` → usar valor do override
2. **Flag desabilitada**: se `enabled = false` → retornar `false` independente do rollout
3. **Verificação de rollout**: hash do `user_id` deterministicamente → comparar com `rollout_percent`

```php
// 1. Verificar override
$override = $this->repo->findOverride($key, $environment, $userId);
if ($override !== null) {
    return new EvaluateResult(enabled: $override->enabled, override: $override->enabled);
}

// 2. Flag desabilitada
if (!$flag->enabled) {
    return new EvaluateResult(enabled: false, override: null);
}

// 3. Rollout percent
$hash = abs(crc32($userId)) % 100;
$enabled = $hash < $flag->rolloutPercent;
return new EvaluateResult(enabled: $enabled, override: null);
```

Resposta:
```json
{"enabled": true, "override": null}   // decisão de rollout
{"enabled": true, "override": true}   // override habilitado
{"enabled": false, "override": false} // override desabilitado
```

## Overrides por Usuário

```php
// Habilitar para um usuário específico (mesmo se a flag estiver off / rollout 0%)
PUT /flags/beta_feature/overrides/alice  {"enabled": true}

// Desabilitar para um usuário específico (mesmo se a flag estiver on / rollout 100%)
PUT /flags/global_flag/overrides/bob  {"enabled": false}

// Remover override — reverte para lógica de flag global + rollout
DELETE /flags/my_flag/overrides/alice
```

Override requer o campo `enabled` (booleano). Campo ausente → 422.
Override em flag inexistente → 404.
Deletar override inexistente → 404.

---

## O Que NÃO Fazer

| Anti-padrão | Risco |
|---|---|
| Permitir formato de chave arbitrário (ex: hífens, maiúsculas) | Chaves inconsistentes entre times; difícil de fazer grep/referenciar no código |
| Rollout percent > 100 | Erro lógico; rollout de 110% significa sempre habilitado mesmo quando planejado como gradual |
| Sem separação por ambiente | Flags de staging sangram para produção; deploys canary quebram |
| Avaliar sem verificar `user_id` | `crc32(null)` ou string vazia dá bucketing determinístico mas incorreto |
| Retornar 200 para avaliar em flag inexistente | Chamador assume que a flag existe; silenciosamente trata como desabilitada em vez de levantar alerta |
| Estado global de flag em memória/cache sem TTL | Flags desatualizadas após mudança de rollout percent; alterações não propagam |
