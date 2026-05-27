# Como fazer: API de Preferências de Usuário

> **Referência FT**: FT329 (`NENE2-FT/preflog`) — Armazenamento de preferências por usuário com validação de valor tipado, fallback de padrão, rejeição de chaves desconhecidas, mutação somente pelo dono, 20 testes / 70 asserções PASSAM.

Este guia mostra como construir um sistema de preferências de usuário onde as configurações têm domínios tipados, padrões e flags `is_default` para distinguir valores personalizados de padrões.

## Schema

```sql
CREATE TABLE user_preferences (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    pref_key   TEXT    NOT NULL,
    pref_value TEXT    NOT NULL,
    updated_at TEXT    NOT NULL,
    UNIQUE(user_id, pref_key)
);
```

Os valores padrão ficam no código da aplicação, não no banco.

## Chaves e Validação de Preferências

| Chave | Tipo | Padrão | Valores Permitidos |
|-------|------|--------|-------------------|
| `theme` | enum | `"light"` | `light`, `dark`, `system` |
| `language` | enum | `"en"` | `en`, `ja`, `fr` |
| `notifications_enabled` | string booleana | `"true"` | `"true"`, `"false"` |
| `items_per_page` | string inteira | `"20"` | `"5"` – `"100"` |
| `timezone` | string | `"UTC"` | qualquer timezone IANA |

## Endpoints

| Método | Caminho | Descrição |
|--------|---------|-----------|
| `GET` | `/users/{id}/preferences` | Obter todas (com padrões) |
| `PUT` | `/users/{id}/preferences/{key}` | Definir uma (somente dono) |

## Obter Todas as Preferências

Retorna todas as 5 chaves — valor armazenado se definido, caso contrário padrão:

```php
GET /users/1/preferences
→ 200
{
  "user_id": 1,
  "preferences": [
    {"key": "theme",                 "value": "light", "is_default": true,  "updated_at": null},
    {"key": "language",              "value": "en",    "is_default": true,  "updated_at": null},
    {"key": "notifications_enabled", "value": "true",  "is_default": true,  "updated_at": null},
    {"key": "items_per_page",        "value": "20",    "is_default": true,  "updated_at": null},
    {"key": "timezone",              "value": "UTC",   "is_default": true,  "updated_at": null}
  ]
}

// Após definir theme como dark:
{"key": "theme", "value": "dark", "is_default": false, "updated_at": "2026-05-27T..."}
```

Usuário não encontrado → 404.

## Definir Preferência

```php
PUT /users/1/preferences/theme  X-User-Id: 1
{"value": "dark"}
→ 200  {"key": "theme", "value": "dark", "updated_at": "..."}

// Atualizar existente (UPSERT)
PUT /users/1/preferences/theme  X-User-Id: 1
{"value": "system"}
→ 200  // apenas uma linha por (user_id, pref_key)
```

### Chave Desconhecida

```php
PUT /users/1/preferences/invalid_key  X-User-Id: 1
{"value": "foo"}
→ 422
{"valid_keys": ["theme", "language", "notifications_enabled", "items_per_page", "timezone"]}
```

### Valor Inválido

```php
PUT /users/1/preferences/theme  X-User-Id: 1  {"value": "neon"}     → 422
PUT /users/1/preferences/notifications_enabled  {"value": "yes"}    → 422  // deve ser "true"/"false"
PUT /users/1/preferences/items_per_page  {"value": "200"}           → 422  // max 100
PUT /users/1/preferences/items_per_page  {"value": "1"}             → 422  // min 5
```

### Autorização

```php
// Outro usuário não pode mudar suas preferências
PUT /users/1/preferences/theme  X-User-Id: 2  {"value": "dark"}  → 403

// Usuário não encontrado
PUT /users/999/preferences/theme  X-User-Id: 999  {"value": "dark"}  → 404
```

## Padrão de Implementação

```php
private const SCHEMA = [
    'theme'                 => ['type' => 'enum',    'values' => ['light','dark','system']],
    'language'              => ['type' => 'enum',    'values' => ['en','ja','fr']],
    'notifications_enabled' => ['type' => 'bool_str','values' => ['true','false']],
    'items_per_page'        => ['type' => 'int_str', 'min' => 5, 'max' => 100],
    'timezone'              => ['type' => 'string'],
];

private function validate(string $key, string $value): ?string
{
    $schema = self::SCHEMA[$key] ?? null;
    if ($schema === null) {
        return null;  // chave desconhecida
    }

    return match ($schema['type']) {
        'enum'     => in_array($value, $schema['values'], true) ? $value : throw ValidationException,
        'bool_str' => in_array($value, ['true','false'], true) ? $value : throw ValidationException,
        'int_str'  => $this->validateIntStr($value, $schema['min'], $schema['max']),
        default    => $value,
    };
}
```

---

## O que NÃO fazer

| Anti-padrão | Risco |
|---|---|
| Aceitar qualquer string para `theme` | UI falha ao renderizar tema desconhecido; valide o enum |
| Armazenar padrões no banco | Cada novo usuário requer um insert no banco para cada padrão; use padrões no código |
| Retornar array vazio quando nenhuma preferência está armazenada | Cliente deve lidar com o caso "não definido"; retorne todas as chaves com padrões |
| Omitir flag `is_default` | Cliente não pode distinguir intenção do usuário do padrão do sistema |
| Permitir alterar preferências de outros usuários | Violação de privacidade; verificação de dono é obrigatória |
| Aceitar `"yes"/"no"` para preferência booleana | Inconsistente; normalize para strings `"true"/"false"` |
