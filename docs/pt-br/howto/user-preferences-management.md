# Gerenciamento de Preferências de Usuário

Guia de implementação para gerenciamento de preferências de usuário.
Permite salvar, atualizar e resetar valores de configuração com validação de tipo para um conjunto predefinido de chaves.

## Visão Geral

- Chaves de preferência são gerenciadas via enum (chaves desconhecidas retornam 422)
- Valores têm validação de tipo por chave
- Alterar preferências de outro usuário retorna 403 (verificação de propriedade)
- Chaves não definidas retornam o valor padrão (`is_default: true`)
- DELETE reseta para o valor padrão

## Endpoints

| Método | Caminho | Descrição |
|---|---|---|
| `GET` | `/users/{id}/preferences` | Listar preferências (todas as chaves, incluindo padrões) |
| `PUT` | `/users/{id}/preferences/{key}` | Atualizar valor de preferência (upsert) |
| `DELETE` | `/users/{id}/preferences/{key}` | Resetar preferência (voltar ao padrão) |

## Design do Banco de Dados

```sql
CREATE TABLE user_preferences (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    pref_key TEXT NOT NULL,
    pref_value TEXT NOT NULL,   -- sempre armazenado como string
    updated_at TEXT NOT NULL,
    UNIQUE (user_id, pref_key), -- chave única por usuário
    FOREIGN KEY (user_id) REFERENCES users(id)
);
```

Os valores são sempre armazenados como `TEXT`. A interpretação de tipo é feita no lado do cliente
(`items_per_page: "20"` → `parseInt()` no frontend).

## Enum de Chaves de Preferência

```php
enum PreferenceKey: string
{
    case Theme = 'theme';
    case Language = 'language';
    case NotificationsEnabled = 'notifications_enabled';
    case ItemsPerPage = 'items_per_page';
    case Timezone = 'timezone';

    public function defaultValue(): string
    {
        return match ($this) {
            self::Theme => 'light',
            self::Language => 'en',
            self::NotificationsEnabled => 'true',
            self::ItemsPerPage => '20',
            self::Timezone => 'UTC',
        };
    }

    public function validate(string $value): bool
    {
        return match ($this) {
            self::Theme => in_array($value, ['light', 'dark', 'system'], true),
            self::Language => preg_match('/^[a-z]{2}(-[A-Z]{2})?$/', $value) === 1,
            self::NotificationsEnabled => in_array($value, ['true', 'false'], true),
            self::ItemsPerPage => ctype_digit($value) && (int) $value >= 5 && (int) $value <= 100,
            self::Timezone => strlen($value) <= 64 && strlen($value) > 0,
        };
    }
}
```

## Resposta de GET /users/{id}/preferences

```json
{
  "preferences": [
    {"key": "theme", "value": "dark", "is_default": false, "updated_at": "2026-05-21T10:00:00+00:00"},
    {"key": "language", "value": "en", "is_default": true, "updated_at": null},
    {"key": "notifications_enabled", "value": "true", "is_default": true, "updated_at": null},
    {"key": "items_per_page", "value": "20", "is_default": true, "updated_at": null},
    {"key": "timezone", "value": "UTC", "is_default": true, "updated_at": null}
  ]
}
```

Retorna todas as chaves (valores armazenados para os que foram definidos, valores padrão para os não definidos).

## Padrão Upsert

```php
public function upsertPreference(int $userId, string $key, string $value, string $now): void
{
    $existing = $this->findPreference($userId, $key);
    if ($existing !== null) {
        $this->executor->execute(
            'UPDATE user_preferences SET pref_value = ?, updated_at = ? WHERE user_id = ? AND pref_key = ?',
            [$value, $now, $userId, $key]
        );
    } else {
        $this->executor->execute(
            'INSERT INTO user_preferences (user_id, pref_key, pref_value, updated_at) VALUES (?, ?, ?, ?)',
            [$userId, $key, $value, $now]
        );
    }
}
```

Combinado com a restrição UNIQUE(user_id, pref_key), garante uma linha por usuário por chave.

## Verificação de Propriedade (Prevenção de IDOR)

```php
$actorId = (int) $request->getHeaderLine('X-User-Id');
if ($actorId !== $userId) {
    return $this->responseFactory->create(['error' => 'cannot modify another user\'s preferences'], 403);
}
```

Alterar ou deletar preferências de outro usuário não é permitido. A leitura é acessível a todos (as preferências são geralmente públicas).

## DELETE = Reset (Exclusão Física)

DELETE remove a linha de preferência do banco, e GET retornará o valor padrão novamente:

```php
$this->repository->deletePreference($userId, $prefKey->value);
return $this->responseFactory->create([
    'key' => $prefKey->value,
    'value' => $prefKey->defaultValue(),
    'is_default' => true,
], 200);
```

Retorna 200 também quando a preferência ainda não estava definida (ao primeiro DELETE), garantindo idempotência.

## Resposta para Chave Desconhecida

```json
{
  "error": "unknown preference key",
  "valid_keys": ["theme", "language", "notifications_enabled", "items_per_page", "timezone"]
}
```

Retornar a lista de chaves válidas aumenta a auto-explicabilidade da API.

## Padrões de Extensão

- **Categorização**: Adicionar enum `PreferenceCategory` para agrupar por UI, notificações, exibição, etc.
- **Padrões por tipo de usuário**: Ramificar com `defaultValue(UserType $type)` por tipo de usuário
- **Log de auditoria**: Rastrear alterações de preferências com `updated_at` + tabela de histórico de alterações
- **Atualização em lote**: `PATCH /users/{id}/preferences` para atualizar múltiplas configurações de uma vez
