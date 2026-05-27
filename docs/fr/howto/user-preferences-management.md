# Gestion des préférences utilisateur

Guide d'implémentation pour la gestion des préférences utilisateur.
Permet de stocker, mettre à jour et réinitialiser des valeurs de configuration avec validation typée pour un ensemble de clés prédéfinies.

## Vue d'ensemble

- Les clés de préférence sont gérées par enum (les clés inconnues retournent 422)
- Les valeurs sont validées par type selon la clé
- La modification des préférences d'autres utilisateurs retourne 403 (vérification de propriété)
- Les clés non définies retournent la valeur par défaut (`is_default: true`)
- DELETE réinitialise à la valeur par défaut

## Endpoints

| Méthode | Chemin | Description |
|---------|--------|-------------|
| `GET` | `/users/{id}/preferences` | Obtenir la liste des préférences (toutes les clés, y compris les valeurs par défaut) |
| `PUT` | `/users/{id}/preferences/{key}` | Mettre à jour une valeur de préférence (upsert) |
| `DELETE` | `/users/{id}/preferences/{key}` | Réinitialiser une préférence (retour à la valeur par défaut) |

## Conception de la base de données

```sql
CREATE TABLE user_preferences (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    pref_key TEXT NOT NULL,
    pref_value TEXT NOT NULL,   -- toujours stocké comme chaîne
    updated_at TEXT NOT NULL,
    UNIQUE (user_id, pref_key), -- clé unique par utilisateur
    FOREIGN KEY (user_id) REFERENCES users(id)
);
```

Les valeurs sont toujours stockées comme `TEXT`. L'interprétation du type est effectuée côté client
(`items_per_page: "20"` → `parseInt()` côté frontend).

## Enum des clés de préférence

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

## Réponse GET /users/{id}/preferences

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

Retourne toutes les clés (valeur stockée pour celles définies, valeur par défaut pour les autres).

## Pattern Upsert

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

Combiné avec la contrainte `UNIQUE(user_id, pref_key)`, cela garantit une ligne par utilisateur par clé.

## Vérification de propriété (prévention IDOR)

```php
$actorId = (int) $request->getHeaderLine('X-User-Id');
if ($actorId !== $userId) {
    return $this->responseFactory->create(['error' => 'cannot modify another user\'s preferences'], 403);
}
```

La modification et la suppression des préférences d'autres utilisateurs sont interdites. La lecture est accessible à tous (les préférences sont généralement publiques).

## DELETE = Réinitialisation (suppression physique)

DELETE supprime la ligne de préférence de la DB, et GET retourne à nouveau la valeur par défaut :

```php
$this->repository->deletePreference($userId, $prefKey->value);
return $this->responseFactory->create([
    'key' => $prefKey->value,
    'value' => $prefKey->defaultValue(),
    'is_default' => true,
], 200);
```

Retourne 200 même si la préférence n'était pas définie (idempotence).

## Réponse pour clé inconnue

```json
{
  "error": "unknown preference key",
  "valid_keys": ["theme", "language", "notifications_enabled", "items_per_page", "timezone"]
}
```

Retourner la liste des clés valides améliore l'auto-description de l'API.

## Patterns d'extension

- **Catégorisation** : Ajouter un enum `PreferenceCategory` pour grouper UI, notifications, affichage, etc.
- **Valeurs par défaut par type d'utilisateur** : Utiliser `defaultValue(UserType $type)` avec des conditions
- **Journal d'audit** : `updated_at` + table d'historique des modifications pour suivre les changements de préférences
- **Mise à jour en lot** : `PATCH /users/{id}/preferences` pour mettre à jour plusieurs préférences à la fois
