# How-to : Suivi des habitudes

> **Référence FT** : FT24 / ATK FT224 — Suivi des habitudes : fréquences allowlistées (daily/weekly/monthly), calcul de streak en arrière à partir d'aujourd'hui, `UNIQUE(habit_id, completed_on)` pour la déduplication, `ON DELETE CASCADE` pour le nettoyage des compléments.

Ce guide montre comment construire un tracker d'habitudes avec suivi de streak, fréquences validées et dates de complétion déduplicatées.

## Schéma

```sql
CREATE TABLE habits (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id       INTEGER NOT NULL,
    name          TEXT    NOT NULL,
    frequency     TEXT    NOT NULL DEFAULT 'daily',
    created_at    TEXT    NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

CREATE TABLE habit_completions (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    habit_id     INTEGER NOT NULL,
    completed_on TEXT    NOT NULL,
    created_at   TEXT    NOT NULL,
    UNIQUE (habit_id, completed_on),
    FOREIGN KEY (habit_id) REFERENCES habits(id) ON DELETE CASCADE
);
```

`UNIQUE(habit_id, completed_on)` empêche les doublons si un utilisateur marque la même habitude deux fois le même jour. `ON DELETE CASCADE` supprime automatiquement toutes les compléments quand une habitude est supprimée.

## Endpoints

| Méthode    | Chemin                                   | Description                         |
|------------|------------------------------------------|-------------------------------------|
| `POST`     | `/habits`                                | Créer une habitude                  |
| `GET`      | `/habits`                                | Lister les habitudes de l'utilisateur |
| `GET`      | `/habits/{id}`                           | Obtenir une habitude                |
| `DELETE`   | `/habits/{id}`                           | Supprimer une habitude              |
| `POST`     | `/habits/{id}/completions`               | Marquer comme complété              |
| `GET`      | `/habits/{id}/completions`               | Lister les compléments              |
| `GET`      | `/habits/{id}/streak`                    | Obtenir la streak actuelle          |

## Allowlist de fréquence

Seules trois fréquences sont acceptées :

```php
private const array VALID_FREQUENCIES = ['daily', 'weekly', 'monthly'];

if (!in_array($frequency, self::VALID_FREQUENCIES, strict: true)) {
    $errors[] = new ValidationError(
        'frequency',
        'frequency must be daily, weekly, or monthly',
        'invalid',
    );
}
```

Utiliser `strict: true` avec `in_array()` empêche les correspondances de type (`"0"` ≠ `0`).

## Format de date de complétion

Les dates de complétion utilisent le format `YYYY-MM-DD` (pas de composante heure) :

```php
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $completedOn)) {
    $errors[] = new ValidationError(
        'completed_on',
        'completed_on must be a valid date in YYYY-MM-DD format',
        'invalid',
    );
}
```

Stocker uniquement la date — pas de timestamp — simplifie la déduplication et le calcul de streak.

## Déduplication avec UNIQUE(habit_id, completed_on)

Quand un utilisateur soumet une complétion en double, la contrainte DB lève une exception :

```php
try {
    $this->repo->addCompletion($habitId, $completedOn, date('c'));
} catch (DatabaseConstraintException) {
    // La complétion existe déjà — retourner idempotent 200
    return $this->responseFactory->create([
        'habit_id'     => $habitId,
        'completed_on' => $completedOn,
        'duplicate'    => true,
    ], 200);
}
```

Retourner 200 (pas 422) pour les doublons — la complétion est déjà enregistrée, donc la requête a réussi d'une certaine façon.

## Calcul de streak — En arrière à partir d'aujourd'hui

La streak est calculée en arrière depuis aujourd'hui, vérifiant les jours consécutifs :

```php
public function calculateStreak(int $habitId, string $today): int
{
    $completions = $this->repo->getCompletionDates($habitId); // trié DESC
    $completionSet = array_flip($completions); // pour O(1) lookup

    $streak = 0;
    $current = new DateTimeImmutable($today);

    while (isset($completionSet[$current->format('Y-m-d')])) {
        $streak++;
        $current = $current->modify('-1 day');
    }

    return $streak;
}
```

La boucle compte jusqu'à trouver un jour sans complétion. Si aujourd'hui n'est pas complété, la streak est 0.

## Paramètre ?today= pour les tests

L'endpoint streak accepte un paramètre `?today=YYYY-MM-DD` pour les tests déterministes :

```php
$today = $queryParams['today'] ?? date('Y-m-d');

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $today)) {
    return $this->responseFactory->create(['error' => 'invalid today format'], 422);
}
```

Sans ce paramètre, la streak dépend de la date courante — difficile à tester. Avec `?today=2024-01-15`, les tests sont reproductibles.

## Filtrage par user_id

Toutes les requêtes sur les habitudes filtrent par `user_id` pour isoler les données :

```php
$habits = $this->repo->findByUserId($actorId);
```

Un utilisateur ne peut jamais voir ou modifier les habitudes d'un autre utilisateur.

---

## ATK Assessment — Test d'attaque mentalité cracker (FT224)

### ATK-01 — Pas d'en-tête X-User-Id ⚠️ EXPOSED

**Attaque** : Envoyer `POST /habits` sans en-tête `X-User-Id`.
**Résultat** : EXPOSED — Sans middleware d'auth, `resolveActorId()` retourne 0. Un utilisateur `id=0` n'existe pas, donc `findUserById(0)` retourne null → 404. Mais l'API accepte la requête et révèle un comportement. Un vrai middleware d'auth devrait être utilisé en production.

---

### ATK-02 — Date invalide dans completed_on ⚠️ EXPOSED

**Attaque** : Envoyer `{ "completed_on": "not-a-date" }`.
**Résultat** : EXPOSED — La validation regex rejette le format, mais `"2024-13-45"` (mois/jour invalide) passe le regex et est accepté. Une validation stricte de date via `DateTime::createFromFormat()` devrait être ajoutée.

---

### ATK-03 — Nom d'habitude sans limite de longueur ⚠️ EXPOSED

**Attaque** : Envoyer un nom d'habitude de 100 000 caractères.
**Résultat** : EXPOSED — Pas de limite de longueur maximale sur le champ `name`. Cela peut causer des problèmes de performance ou de stockage. Ajouter une validation `strlen($name) <= 255`.

---

### ATK-04 — Manipulation de ?today= ⚠️ EXPOSED

**Attaque** : Envoyer `GET /habits/1/streak?today=1970-01-01` pour obtenir une streak de 0.
**Résultat** : EXPOSED — Le paramètre `?today=` est intentionnel pour les tests, mais n'est pas limité en production. Un attaquant peut manipuler la date pour obtenir des résultats faux. Considérer la désactivation en production ou la restriction à `APP_ENV=test`.

---

### ATK-05 — Fréquence invalide 🚫 BLOCKED

**Attaque** : Envoyer `{ "frequency": "hourly" }`.
**Résultat** : BLOCKED — `in_array($frequency, self::VALID_FREQUENCIES, true)` rejette les fréquences non listées → 422.

---

### ATK-06 — Injection SQL dans le nom d'habitude 🚫 BLOCKED

**Attaque** : Envoyer `{ "name": "'; DROP TABLE habits; --" }`.
**Résultat** : BLOCKED — Requêtes paramétrées. La chaîne est stockée verbatim.

---

### ATK-07 — Doublon de complétion 🚫 BLOCKED

**Attaque** : Envoyer `POST /habits/1/completions` deux fois le même jour.
**Résultat** : BLOCKED — `UNIQUE(habit_id, completed_on)` + `DatabaseConstraintException` → retour idempotent 200.

---

### ATK-08 — IDOR : Accéder aux habitudes d'un autre utilisateur 🚫 BLOCKED

**Attaque** : `GET /habits/99` quand l'habitude 99 appartient à un autre utilisateur.
**Résultat** : BLOCKED — Vérification de propriété : `if ($habit['user_id'] !== $actorId) return 404`.

---

### ATK-09 — DELETE d'une habitude appartenant à un autre 🚫 BLOCKED

**Attaque** : `DELETE /habits/99` quand l'habitude appartient à un autre utilisateur.
**Résultat** : BLOCKED — Même vérification de propriété que ATK-08 → 404.

---

### ATK-10 — Fréquence vide 🚫 BLOCKED

**Attaque** : Envoyer `{ "frequency": "" }`.
**Résultat** : BLOCKED — La chaîne vide n'est pas dans l'allowlist → 422.

---

### ATK-11 — ID d'habitude négatif 🚫 BLOCKED

**Attaque** : `GET /habits/-1/streak`.
**Résultat** : BLOCKED — `findHabitById(-1)` retourne null → 404.

---

### ATK-12 — Streak avec complétion future 🚫 BLOCKED

**Attaque** : Insérer une complétion avec `completed_on = "2099-12-31"` et appeler `/streak`.
**Résultat** : BLOCKED — La boucle de streak commence à `$today` et va en arrière. Les dates futures ne font pas partie de la streak courante car elles sont après `$today`.

---

### Résumé ATK

| ID | Attaque | Résultat |
|----|---------|----------|
| ATK-01 | Pas de X-User-Id | ⚠️ EXPOSED |
| ATK-02 | Date invalide (format bon, valeur mauvaise) | ⚠️ EXPOSED |
| ATK-03 | Nom sans limite de longueur | ⚠️ EXPOSED |
| ATK-04 | Manipulation de ?today= | ⚠️ EXPOSED |
| ATK-05 | Fréquence invalide | 🚫 BLOCKED |
| ATK-06 | Injection SQL dans le nom | 🚫 BLOCKED |
| ATK-07 | Doublon de complétion | 🚫 BLOCKED |
| ATK-08 | IDOR lecture | 🚫 BLOCKED |
| ATK-09 | IDOR suppression | 🚫 BLOCKED |
| ATK-10 | Fréquence vide | 🚫 BLOCKED |
| ATK-11 | ID négatif | 🚫 BLOCKED |
| ATK-12 | Streak avec date future | 🚫 BLOCKED |

**8 BLOCKED, 4 EXPOSED**
Les 4 expositions (ATK-01~04) sont des lacunes de validation acceptables pour un FT basique sans middleware d'auth. En production : ajouter un middleware d'auth obligatoire, valider les dates via `DateTime::createFromFormat()`, limiter la longueur du nom, et désactiver `?today=` hors test.

---

## À ne pas faire

| Anti-pattern | Risque |
|---|---|
| Accepter n'importe quelle chaîne comme `frequency` | Données invalides en DB ; logique de streak cassée |
| Pas de `UNIQUE(habit_id, completed_on)` | Doublons de complétion ; streak gonflée |
| Calculer la streak à partir de la première complétion | Streak continue même avec des jours manqués |
| Stocker `completed_at` avec heure | Déduplication difficile ; complétion AM/PM compte comme deux jours |
| Pas de `ON DELETE CASCADE` | Complétion orphelines restent après suppression de l'habitude |
| Pas de vérification de propriété | N'importe quel utilisateur peut lire/supprimer les habitudes d'un autre (IDOR) |
