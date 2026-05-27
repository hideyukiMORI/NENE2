# How-to : Gestion des fuseaux horaires

Ce guide montre comment stocker, valider et afficher correctement les horodatages avec fuseaux horaires dans une API NENE2 — application de l'UTC en stockage, validation de la liste IANA, et gestion des transitions DST.

## Règle principale : Toujours stocker en UTC

```php
$utc = new DateTimeZone('UTC');
$now = new DateTimeImmutable('now', $utc);
$stored = $now->format('c'); // ISO 8601 : "2024-01-15T09:30:00+00:00"
```

Ne jamais stocker des heures locales dans la DB. Le stockage UTC élimine l'ambiguïté lors du passage à l'heure d'été (DST).

## Validation du fuseau horaire IANA

```php
$validZones = DateTimeZone::listIdentifiers();

if (!in_array($timezone, $validZones, strict: true)) {
    $errors[] = new ValidationError(
        'timezone',
        'timezone must be a valid IANA timezone identifier',
        'invalid',
    );
}
```

`DateTimeZone::listIdentifiers()` retourne tous les identifiants IANA valides (ex: `"America/New_York"`, `"Europe/Paris"`, `"Asia/Tokyo"`). Les abréviations comme `"EST"` ou `"PST"` sont ambiguës et ne doivent pas être acceptées.

## createFromFormat() vs constructeur

Préférer `createFromFormat()` pour l'analyse de format strict :

```php
// Strict — rejette les dates invalides
$dt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $input, $utc);
if ($dt === false) {
    // Format invalide
}

// Laxiste — accepte des formats ambigus
$dt = new DateTimeImmutable($input); // éviter pour les entrées utilisateur
```

`createFromFormat()` retourne `false` si la chaîne ne correspond pas exactement au format. Le constructeur est plus tolérant et peut interpréter des chaînes ambiguës de façon inattendue.

## Résolution du retour DST (heure dupliquée)

Lors du passage à l'heure normale (ex: 2h→1h), une heure apparaît deux fois. PHP résout ceci :

```php
// Période DST ambiguë : 2024-11-03 01:30:00 America/New_York
// Apparaît deux fois : une en EDT (+−4), une en EST (−5)
$dt = DateTimeImmutable::createFromFormat(
    'Y-m-d H:i:s',
    '2024-11-03 01:30:00',
    new DateTimeZone('America/New_York'),
);
// PHP choisit la première occurrence (heure d'été)
// Toujours stocker en UTC pour éviter ce problème
$utc = $dt->setTimezone(new DateTimeZone('UTC'));
```

La règle pratique : convertir en UTC immédiatement après l'analyse, stocker l'UTC, reconvertir au moment de l'affichage.

## SQLite et UTC

SQLite stocke les datetimes comme TEXT. `datetime('now')` retourne toujours UTC :

```sql
SELECT datetime('now');        -- "2024-01-15 09:30:00" (toujours UTC)
SELECT datetime('now', 'localtime');  -- ÉVITER : dépend des paramètres système
```

Utiliser `strftime('%Y-%m-%dT%H:%M:%SZ', created_at)` pour formater en ISO 8601 UTC depuis SQLite.

## Patterns STRFTIME — Attention à %W

SQLite `strftime()` suit les conventions C :

| Pattern | Signification | Piège |
|---------|---------------|-------|
| `%Y` | Année (4 chiffres) | — |
| `%m` | Mois (01-12) | — |
| `%d` | Jour (01-31) | — |
| `%H` | Heure (00-23) | — |
| `%M` | Minute (00-59) | — |
| `%S` | Seconde (00-59) | — |
| `%W` | Semaine ISO (commence dimanche) | Commence le **dimanche**, pas lundi |
| `%j` | Jour de l'année (001-366) | — |

`%W` compte les semaines commençant le **dimanche**. Pour des semaines commençant le lundi (standard ISO 8601), utiliser une logique PHP côté application.

## Conversion de fuseau horaire à l'affichage

```php
public function toUserTimezone(string $utcDatetime, string $timezone): string
{
    $utc = new DateTimeImmutable($utcDatetime, new DateTimeZone('UTC'));
    $userTz = new DateTimeZone($timezone);
    return $utc->setTimezone($userTz)->format('c');
}
```

Stocker en UTC, afficher dans le fuseau de l'utilisateur. Ne jamais stocker le fuseau horaire avec l'heure — stocker séparément et convertir à la demande.

## Validation des entrées de date-heure

```php
private function parseDateTime(string $input): ?DateTimeImmutable
{
    // Accepter ISO 8601 avec offset
    $dt = DateTimeImmutable::createFromFormat(DateTimeInterface::ATOM, $input);
    if ($dt !== false) {
        return $dt->setTimezone(new DateTimeZone('UTC'));
    }

    // Accepter date seule YYYY-MM-DD (interpréter comme minuit UTC)
    $dt = DateTimeImmutable::createFromFormat('Y-m-d', $input, new DateTimeZone('UTC'));
    if ($dt !== false) {
        return $dt->setTime(0, 0, 0);
    }

    return null;
}
```

Toujours normaliser en UTC après l'analyse.

## À ne pas faire

| Anti-pattern | Risque |
|---|---|
| Stocker les heures locales en DB | Ambiguïté DST ; impossible de trier correctement |
| Accepter `"EST"` ou `"PST"` comme identifiant de fuseau | Ambigu — `"EST"` peut être UTC-5 ou UTC+10 selon la région |
| Utiliser `new DateTimeImmutable($input)` pour les entrées utilisateur | Analyse laxiste ; peut accepter des formats inattendus |
| `strftime('%W', ...)` pour les semaines commençant le lundi | `%W` commence le dimanche ; utiliser PHP côté application |
| `datetime('now', 'localtime')` dans SQLite | Dépend des paramètres TZ du serveur ; non déterministe |
| Ne pas convertir en UTC immédiatement | Les heures DST ambiguës restent ambiguës en DB |
| Stocker le fuseau horaire dans la colonne datetime | Mélange de responsabilités ; utiliser une colonne `timezone` séparée |
