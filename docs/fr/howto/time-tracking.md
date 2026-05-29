# How-to : API de suivi du temps

> **Référence FT** : FT246 (`NENE2-FT/timelog`) — API de suivi du temps

Démontre une API de suivi du temps de type chronomètre où une entrée de minuterie a un `start_time`
et un `end_time` nullable (`NULL` = en cours, non-`NULL` = arrêtée). Une seule minuterie peut
tourner à la fois. La durée est calculée via `strftime('%s', ...)` de SQLite. Des résumés journaliers
agrègent le total des secondes suivies par jour calendaire.

---

## Routes

| Méthode   | Chemin              | Description                                                   |
|-----------|---------------------|---------------------------------------------------------------|
| `POST`    | `/timers/start`     | Démarrer une nouvelle minuterie (échoue si une tourne déjà)   |
| `POST`    | `/timers/stop`      | Arrêter la minuterie en cours                                 |
| `GET`     | `/timers/running`   | Obtenir la minuterie en cours (ou `running: false`)           |
| `GET`     | `/timers/summary`   | Résumé journalier : total de secondes et nombre d'entrées par jour |
| `GET`     | `/timers`           | Lister les entrées (paginé, filtrable par label et date)      |
| `GET`     | `/timers/{id}`      | Obtenir une seule entrée de minuterie                         |
| `DELETE`  | `/timers/{id}`      | Supprimer une entrée de minuterie (`204 No Content`)          |

> **Routes statiques d'abord** : `/timers/start`, `/timers/stop`, `/timers/running`,
> `/timers/summary` sont toutes enregistrées avant `/timers/{id}` pour que les chemins
> littéraux ne soient pas capturés comme segments paramétrés.

---

## Schéma

```sql
CREATE TABLE IF NOT EXISTS time_entries (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    label      TEXT NOT NULL,
    start_time TEXT NOT NULL,
    end_time   TEXT,              -- NULL = en cours
    created_at TEXT NOT NULL
);
```

`end_time` est nullable — `NULL` signifie que la minuterie tourne encore. `NOT NULL` signifie
qu'elle a été arrêtée. Il n'y a pas de colonne `status` séparée ; la présence ou l'absence de
`end_time` encode l'état de fonctionnement.

---

## État en cours : `end_time IS NULL`

L'état de fonctionnement de la minuterie est détecté uniquement à partir de la colonne `end_time` :

```php
final readonly class TimeEntry
{
    public function isRunning(): bool
    {
        return $this->endTime === null;
    }

    public function durationSeconds(): ?int
    {
        if ($this->endTime === null) {
            return null;  // toujours en cours — pas encore de durée
        }
        $start = new \DateTimeImmutable($this->startTime);
        $end   = new \DateTimeImmutable($this->endTime);
        return (int) $end->getTimestamp() - (int) $start->getTimestamp();
    }
}
```

`isRunning()` retourne `true` quand `endTime` est `null`. `durationSeconds()` retourne
`null` pour les minuteries en cours — la durée ne peut pas être calculée tant que la minuterie
n'est pas arrêtée. La réponse inclut `"running": true` et `"duration_seconds": null` pour
les entrées actives.

---

## Minuterie singleton : une seule peut tourner à la fois

`start()` vérifie s'il y a une minuterie en cours avant d'en créer une nouvelle :

```php
public function start(string $label, string $startTime, string $createdAt): TimeEntry
{
    $running = $this->findRunning();
    if ($running !== null) {
        throw new TimerAlreadyRunningException($running->id);
    }

    $this->executor->execute(
        'INSERT INTO time_entries (label, start_time, end_time, created_at) VALUES (?, ?, NULL, ?)',
        [$label, $startTime, $createdAt],
    );

    return $this->findById($this->executor->lastInsertId());
}
```

Si une minuterie tourne déjà, `TimerAlreadyRunningException` est levée → `409 Conflict`.
`end_time` est inséré comme valeur SQL littérale `NULL`.

La recherche de la minuterie en cours :

```php
public function findRunning(): ?TimeEntry
{
    $row = $this->executor->fetchOne(
        'SELECT * FROM time_entries WHERE end_time IS NULL ORDER BY start_time DESC LIMIT 1',
        [],
    );
    return $row !== null ? $this->hydrate($row) : null;
}
```

`WHERE end_time IS NULL` — comparaison SQL standard avec `NULL` (pas `= NULL`). `LIMIT 1`
protège contre le retour de plusieurs lignes si l'invariant est jamais violé.

---

## Arrêter une minuterie : `stop()`

```php
public function stop(string $endTime): TimeEntry
{
    $running = $this->findRunning();
    if ($running === null) {
        throw new NoRunningTimerException();
    }

    $this->executor->execute(
        'UPDATE time_entries SET end_time = ? WHERE id = ?',
        [$endTime, $running->id],
    );

    return $this->findById($running->id);
}
```

`stop()` trouve la minuterie en cours, définit `end_time`, et retourne l'entrée mise à jour avec
la durée calculée. `NoRunningTimerException` est levée si aucune minuterie ne tourne →
`409 Conflict`.

---

## Calcul de durée : `strftime('%s', ...)` en SQL

Pour les résumés agrégés, la durée est calculée en SQL avec la fonction `strftime('%s', ...)` de SQLite, qui renvoie les secondes Unix epoch d'une chaîne datetime sous forme d'entier :

```sql
SUM(strftime('%s', end_time) - strftime('%s', start_time)) AS total_seconds
```

`strftime('%s', ...)` analyse la chaîne datetime ISO (y compris un décalage `±HH:MM`, normalisé en UTC)
et renvoie des secondes epoch entières. Soustraire les deux donne la durée exacte en secondes —
correspondant à la différence `getTimestamp()` côté PHP.

> **Piège — ne pas utiliser `julianday()` pour la précision à la seconde.** La formule
> `CAST((julianday(end_time) - julianday(start_time)) * 86400 AS INTEGER)` est tentante, mais la
> différence `julianday` est une valeur à virgule flottante juste en dessous de la seconde entière, donc
> le `CAST(... AS INTEGER)` tronque une entrée de 60 secondes à **59**. Utilisez plutôt
> `strftime('%s', ...)` (secondes epoch entières) — c'est exact. (Découvert par la suite PHPUnit de
> l'exemple `timelog` FT246.)

`SUM(...)` totalise toutes les entrées terminées pour la journée. `WHERE end_time IS NOT NULL`
exclut les minuteries encore en cours du résumé.

Le calcul côté PHP pour les entrées individuelles :

```php
$start = new \DateTimeImmutable($this->startTime);
$end   = new \DateTimeImmutable($this->endTime);
return (int) $end->getTimestamp() - (int) $start->getTimestamp();
```

Les deux approches produisent le même résultat pour les timestamps UTC. L'approche SQL est
utilisée pour l'agrégation (elle évite de récupérer toutes les lignes pour les additionner) ;
l'approche PHP est utilisée pour la sérialisation des entrées individuelles.

---

## Agrégation du résumé journalier

```php
$sql = 'SELECT date(start_time) AS day,
               SUM(strftime('%s', end_time) - strftime('%s', start_time)) AS total_seconds,
               COUNT(*) AS entry_count
          FROM time_entries
         WHERE ' . implode(' AND ', $where) . '
         GROUP BY day
         ORDER BY day DESC';
```

`date(start_time)` extrait la date calendaire de la chaîne ISO `start_time`.
`GROUP BY day` regroupe toutes les entrées terminées du même jour.
`ORDER BY day DESC` retourne les jours les plus récents en premier.

La clause `$where` commence toujours par `['end_time IS NOT NULL']` pour exclure les minuteries
en cours, puis ajoute optionnellement `date(start_time) >= ?` et `date(start_time) <= ?` pour
le filtre de plage de dates.

---

## Fonction `date()` pour le filtrage par date uniquement

Filtrer les entrées par date calendaire utilise la fonction `date()` de SQLite :

```php
if ($date !== null) {
    $where[]  = "date(start_time) = ?";
    $params[] = $date;
}
```

`date(start_time)` extrait uniquement `YYYY-MM-DD` de la chaîne datetime ISO.
`= ?` compare la date extraite à la valeur du filtre. Cela correspond correctement à toutes
les entrées démarrées le jour donné quelle que soit la composante horaire.

---

## Filtrage par label avec `LIKE`

```php
if ($label !== null) {
    $where[]  = 'label LIKE ?';
    $params[] = '%' . $label . '%';
}
```

`LIKE '%label%'` effectue une correspondance de sous-chaîne insensible à la casse dans le
collationnement par défaut de SQLite. Les caractères spéciaux `%` et `_` dans `$label` sont
interprétés comme des jokers LIKE — échappez-les si une correspondance littérale stricte
est requise.

---

## Contrat de réponse de `GET /timers/running`

L'endpoint running retourne une forme cohérente qu'une minuterie soit active ou non :

```php
if ($entry === null) {
    return $this->json->create(['running' => false, 'entry' => null]);
}
return $this->json->create(['running' => true, 'entry' => $this->serialize($entry)]);
```

`running: false, entry: null` — aucune minuterie active.
`running: true, entry: {...}` — minuterie active avec `end_time: null` et `duration_seconds: null`.

Cela évite un `404` pour "aucune minuterie en cours" — `404` implique que la ressource n'existe
pas, mais le concept de "minuterie en cours" existe toujours (il est juste vide). Utiliser
`running: false` est sémantiquement plus propre.

---

## Howtos liés

- [`shift-management.md`](shift-management.md) — pointage d'entrée/sortie avec end_time nullable
- [`scheduled-reminders.md`](scheduled-reminders.md) — validation datetime avec gestion des fuseaux horaires
- [`aggregate-reporting.md`](aggregate-reporting.md) — patterns d'agrégation `GROUP BY date`
- [`handle-timezones.md`](handle-timezones.md) — stockage UTC et conversion de fuseaux horaires
