# How-to : Planification d'événements avec gestion des fuseaux horaires

> **Référence FT** : FT286 (`NENE2-FT/schedulelog`) — Planification avec fuseaux horaires : stockage UTC + conversion en heure locale, validation de timezone IANA via `DateTimeZone::listIdentifiers()`, `InvalidTimezoneException`, paramètre de requête dynamique `?timezone`, 19 tests / 39 assertions PASS.

Ce guide montre comment construire une API de planification d'événements qui stocke les heures en UTC et les présente dans n'importe quel fuseau horaire demandé par le client.

## Pourquoi stocker en UTC ?

UTC est le point de référence universel. Les heures locales sont ambiguës (changements d'heure, changements de règles de fuseau horaire) et varient selon l'emplacement du client. En stockant en UTC :
- Le tri et la comparaison sont toujours corrects
- Les clients peuvent afficher dans leur fuseau horaire local
- Les transitions DST ne créent pas d'ambiguïté dans les données historiques

## Schéma

```sql
CREATE TABLE IF NOT EXISTS events (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    title       TEXT    NOT NULL,
    timezone    TEXT    NOT NULL,      -- Fuseau horaire IANA du créateur d'événement
    start_utc   TEXT    NOT NULL,      -- UTC ISO 8601 : 2026-05-20T15:00:00Z
    start_local TEXT    NOT NULL,      -- Local ISO 8601 : 2026-05-20T10:00:00
    created_at  TEXT    NOT NULL
);
```

`start_utc` et `start_local` sont tous deux stockés. `start_utc` fait autorité ; `start_local` est un cache de commodité pour le fuseau horaire du créateur.

## Endpoints

| Méthode | Chemin | Description |
|---------|--------|-------------|
| `POST` | `/events` | Créer un événement (fuseau horaire + heure locale → UTC) |
| `GET` | `/events` | Lister les événements (optionnel `?timezone=America/New_York`) |
| `GET` | `/events/{id}` | Obtenir un événement (optionnel `?timezone=`) |

## Validation de fuseau horaire IANA

Le constructeur `DateTimeZone` de PHP accepte silencieusement certains identifiants invalides. Valider explicitement :

```php
final class TimezoneConverter
{
    public static function localToUtc(string $localDatetime, string $ianaTimezone): \DateTimeImmutable
    {
        try {
            $tz = new \DateTimeZone($ianaTimezone);
        } catch (\Exception) {
            throw new InvalidTimezoneException("Unknown timezone: $ianaTimezone");
        }

        // PHP accepte des abréviations invalides comme "EST" dans certaines versions —
        // valider explicitement par rapport à la liste IANA canonique.
        $valid = \DateTimeZone::listIdentifiers();
        if (!in_array($ianaTimezone, $valid, true)) {
            throw new InvalidTimezoneException("Unknown timezone: $ianaTimezone");
        }

        $local = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s', $localDatetime, $tz);

        if ($local === false) {
            throw new \InvalidArgumentException("Cannot parse datetime: $localDatetime");
        }

        return $local->setTimezone(new \DateTimeZone('UTC'));
    }
}
```

`DateTimeZone::listIdentifiers()` retourne la liste des identifiants IANA compilée par PHP. Les chaînes non-IANA (comme `EST`, `GMT+5`) sont rejetées.

## Créer un événement : heure locale → UTC

```php
try {
    $utc = TimezoneConverter::localToUtc($start, $timezone);
} catch (InvalidTimezoneException) {
    return $this->problems->create($request, 'validation-failed', 'Validation Failed', 422, null, [
        'errors' => [['field' => 'timezone', 'code' => 'invalid', 'message' => "Unknown timezone: $timezone"]],
    ]);
} catch (\InvalidArgumentException) {
    return $this->problems->create($request, 'validation-failed', 'Validation Failed', 422, null, [
        'errors' => [['field' => 'start', 'code' => 'invalid', 'message' => "Cannot parse datetime: $start"]],
    ]);
}

$startUtc   = TimezoneConverter::formatUtc($utc);                              // "2026-05-20T15:00:00Z"
$startLocal = TimezoneConverter::formatLocal($utc->setTimezone(new \DateTimeZone($timezone)));  // "2026-05-20T10:00:00"
```

## Lister les événements : conversion dynamique de fuseau horaire

Le paramètre de requête `?timezone=` convertit tous les événements dans le fuseau horaire du client à la volée :

```php
$viewTz = isset($params['timezone']) && $params['timezone'] !== '' ? $params['timezone'] : null;

$items = array_map(static function (Event $e) use ($viewTz): array {
    $data = $e->toArray();
    if ($viewTz !== null) {
        try {
            $local = TimezoneConverter::utcToLocal($e->startUtc, $viewTz);
            $data['start_local'] = TimezoneConverter::formatLocal($local);
            $data['view_timezone'] = $viewTz;
        } catch (InvalidTimezoneException) {
            // Fuseau horaire de vue invalide : retourner silencieusement en UTC
            $data['view_timezone'] = 'UTC';
        }
    }
    return $data;
}, $events);
```

Les valeurs `?timezone=` invalides retombent silencieusement sur le `start_local` stocké plutôt que de retourner une erreur — un choix de conception approprié pour les vues en lecture seule.

## Format UTC : ISO 8601 avec suffixe Z

```php
public static function formatUtc(\DateTimeImmutable $dt): string
{
    return $dt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    //                                                                           ^ Z littéral
}
```

Le suffixe `Z` indique explicitement UTC (selon ISO 8601 / RFC 3339). Utiliser `+00:00` ou omettre le décalage sont des alternatives acceptables, mais `Z` est plus compact et universellement reconnu.

## Conversion sécurisée pour le passage à l'heure d'été (DST)

```
Exemple : Asia/Tokyo est UTC+9 (pas de changement d'heure)
Local : 2026-05-20T10:00:00  Asia/Tokyo
UTC :   2026-05-20T01:00:00Z

Exemple : America/New_York (avec changement d'heure)
Local : 2026-05-20T10:00:00  America/New_York (EDT = UTC-4 en été)
UTC :   2026-05-20T14:00:00Z

Local : 2026-01-20T10:00:00  America/New_York (EST = UTC-5 en hiver)
UTC :   2026-01-20T15:00:00Z
```

`DateTimeImmutable` avec un fuseau horaire IANA nommé gère automatiquement le changement d'heure. Il utilise le décalage actif à cette date spécifique, pas un décalage fixe.

---

## Ce qu'il ne faut PAS faire

| Anti-pattern | Risque |
|---|---|
| Stocker l'heure locale sans colonne de fuseau horaire | Impossible de convertir en UTC plus tard ; les données historiques deviennent ambiguës après les changements d'heure |
| Accepter `EST`, `PST`, `GMT+5` comme fuseau horaire | Abréviations ambiguës ; certaines correspondent à plusieurs zones IANA ; `DateTimeZone::listIdentifiers()` les rejette |
| Utiliser `new DateTimeZone($tz)` sans vérifier `listIdentifiers()` | PHP accepte silencieusement certains identifiants invalides ou dépréciés ; la validation canonique les détecte |
| Stocker le décalage UTC (`+09:00`) au lieu du nom IANA | Le décalage seul ne peut pas gérer le changement d'heure ; `Asia/Tokyo` est toujours +9 mais `America/New_York` varie |
| Trier les événements par `start_local` | Le tri lexicographique sur les heures locales ignore les différences de fuseau horaire ; toujours trier par `start_utc` |
| Convertir le fuseau horaire à chaque requête | Coûteux pour les grands datasets ; envisager la mise en cache ou le précalcul des fuseaux horaires de vue courants |
| Retourner 422 pour `?timezone=` invalide dans GET | Les requêtes en lecture seule doivent se dégrader gracieusement ; revenir en UTC plutôt qu'en erreur |
| Utiliser `date()` au lieu de `DateTimeImmutable` | `date()` utilise le fuseau horaire par défaut du serveur ; `DateTimeImmutable` avec des zones explicites est prévisible |
