# How-to : API de préférences utilisateur

> **Référence FT** : FT329 (`NENE2-FT/preflog`) — Dépôt de préférences par utilisateur avec validation de valeur typée, fallback par défaut, rejet de clé inconnue, mutation réservée au propriétaire, 20 tests / 70 assertions PASS.

Ce guide montre comment construire un système de préférences utilisateur où les paramètres ont des domaines typés, des valeurs par défaut, et des flags `is_default` pour distinguer les valeurs personnalisées des valeurs par défaut.

## Schéma

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

Les valeurs par défaut vivent dans le code applicatif, pas dans la DB.

## Clés de préférence et validation

| Clé | Type | Défaut | Valeurs autorisées |
|-----|------|--------|--------------------|
| `theme` | enum | `"light"` | `light`, `dark`, `system` |
| `language` | enum | `"en"` | `en`, `ja`, `fr` |
| `notifications_enabled` | chaîne booléenne | `"true"` | `"true"`, `"false"` |
| `items_per_page` | chaîne entière | `"20"` | `"5"` – `"100"` |
| `timezone` | chaîne | `"UTC"` | n'importe quel fuseau horaire IANA |

## Endpoints

| Méthode | Chemin | Description |
|---------|--------|-------------|
| `GET` | `/users/{id}/preferences` | Obtenir toutes les préférences (avec valeurs par défaut) |
| `PUT` | `/users/{id}/preferences/{key}` | Définir une préférence (propriétaire uniquement) |

## Obtenir toutes les préférences

Retourne les 5 clés — valeur stockée si définie, sinon valeur par défaut :

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

// Après avoir défini le thème sur dark :
{"key": "theme", "value": "dark", "is_default": false, "updated_at": "2026-05-27T..."}
```

Utilisateur non trouvé → 404.

## Définir une préférence

```php
PUT /users/1/preferences/theme  X-User-Id: 1
{"value": "dark"}
→ 200  {"key": "theme", "value": "dark", "updated_at": "..."}

// Mettre à jour l'existant (UPSERT)
PUT /users/1/preferences/theme  X-User-Id: 1
{"value": "system"}
→ 200  // une seule ligne par (user_id, pref_key)
```

### Clé inconnue

```php
PUT /users/1/preferences/invalid_key  X-User-Id: 1
{"value": "foo"}
→ 422
{"valid_keys": ["theme", "language", "notifications_enabled", "items_per_page", "timezone"]}
```

### Valeur invalide

```php
PUT /users/1/preferences/theme  X-User-Id: 1  {"value": "neon"}     → 422
PUT /users/1/preferences/notifications_enabled  {"value": "yes"}    → 422  // doit être "true"/"false"
PUT /users/1/preferences/items_per_page  {"value": "200"}           → 422  // max 100
PUT /users/1/preferences/items_per_page  {"value": "1"}             → 422  // min 5
```

### Autorisation

```php
// Un autre utilisateur ne peut pas changer vos préférences
PUT /users/1/preferences/theme  X-User-Id: 2  {"value": "dark"}  → 403

// Utilisateur non trouvé
PUT /users/999/preferences/theme  X-User-Id: 999  {"value": "dark"}  → 404
```

## Pattern d'implémentation

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
        return null;  // clé inconnue
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

## Ce qu'il ne faut PAS faire

| Anti-pattern | Risque |
|---|---|
| Accepter n'importe quelle chaîne pour `theme` | L'UI plante lors du rendu d'un thème inconnu ; valider l'enum |
| Stocker les valeurs par défaut en DB | Chaque nouvel utilisateur nécessite un insert DB pour chaque défaut ; utiliser les valeurs par défaut côté code |
| Retourner un tableau vide quand aucune préférence n'est stockée | Le client doit gérer le cas "non défini" ; retourner toutes les clés avec les valeurs par défaut |
| Omettre le flag `is_default` | Le client ne peut pas distinguer l'intention utilisateur du défaut système |
| Autoriser le changement des préférences d'autres utilisateurs | Violation de la vie privée ; la vérification du propriétaire est obligatoire |
| Accepter `"yes"/"no"` pour la préférence booléenne | Incohérent ; normaliser vers les chaînes `"true"/"false"` |
