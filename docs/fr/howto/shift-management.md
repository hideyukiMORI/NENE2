# How-to : API de gestion des plannings

> **Référence FT** : FT43 (`NENE2-FT/shiftlog`) — API de planification des horaires employés
> **VULN** : FT225 — audit de sécurité / vulnérabilité (V-01 à V-12)

Démontre une API de planification des horaires employés avec détection des chevauchements, vérifications transactionnelles, comparaisons de dates ISO 8601, et handlers d'exceptions personnalisés pour les erreurs de domaine.
La section VULN évalue systématiquement chaque surface d'attaque et enregistre les résultats.

---

## Routes

| Méthode | Chemin | Description |
|---------|--------|-------------|
| `GET` | `/employees` | Lister les employés (paginé) |
| `POST` | `/employees` | Créer un employé |
| `GET` | `/employees/{id}` | Obtenir un employé |
| `GET` | `/employees/{id}/shifts` | Lister les horaires d'un employé (paginé) |
| `POST` | `/shifts` | Planifier un horaire (avec vérification de chevauchement) |
| `GET` | `/shifts/{id}` | Obtenir un horaire |
| `DELETE` | `/shifts/{id}` | Supprimer un horaire |
| `GET` | `/schedule` | Horaires dans une fenêtre de dates (`?from=&to=`) |
| `GET` | `/summary/weekly` | Heures par employé par semaine |
| `GET` | `/summary/overtime` | Employés dépassant un seuil d'heures |

---

## Créer des employés

```php
// POST /employees
$body = [
    'name'        => 'Alice',    // requis, chaîne non vide
    'role'        => 'Barista',  // requis, chaîne non vide
    'hourly_rate' => 18.50,      // requis, numérique > 0
];
```

Des vérifications de type JSON strict `is_int()` / `is_string()` sont appliquées. Les chaînes vides sont
rejetées après `trim()`.

```php
if (!isset($body['hourly_rate'])
    || !is_numeric($body['hourly_rate'])
    || (float) $body['hourly_rate'] <= 0) {
    $errors[] = new ValidationError('hourly_rate', 'hourly_rate must be a positive number.', 'required');
}
```

> **Note** : Le schéma a aussi `CHECK(hourly_rate > 0)` au niveau DB comme filet de sécurité en profondeur.
> Valider au niveau applicatif d'abord pour retourner un 422 correct.

---

## Planifier des horaires avec détection de chevauchement

La détection de chevauchement s'exécute dans une transaction DB pour prévenir les conditions de course :

```php
return $this->txManager->transactional(
    function (DatabaseQueryExecutorInterface $tx) use ($employeeId, $startsAt, $endsAt, $location, $now): Shift {
        $txRepo   = new self($tx, $this->txManager);
        $employee = $txRepo->findEmployeeById($employeeId);

        // Chevauchement : tout horaire existant qui intersecte [$startsAt, $endsAt)
        $overlap = $tx->fetchOne(
            "SELECT id FROM shifts
             WHERE employee_id = ?
               AND starts_at < ?
               AND ends_at   > ?",
            [$employeeId, $endsAt, $startsAt],
        );

        if ($overlap !== null) {
            throw new ShiftOverlapException($employee->name, $startsAt, $endsAt);
        }

        $id = $tx->insert(
            'INSERT INTO shifts (employee_id, starts_at, ends_at, location, created_at) VALUES (?, ?, ?, ?, ?)',
            [$employeeId, $startsAt, $endsAt, $location, $now],
        );
        // ...
    },
);
```

La condition de chevauchement `starts_at < $endsAt AND ends_at > $startsAt` gère correctement les quatre
configurations de chevauchement (partiel à gauche, partiel à droite, contenu, et contenant).

**Pourquoi transactionnel ?** Sans transaction, deux requêtes concurrentes peuvent toutes deux passer
la vérification de chevauchement simultanément et créer des horaires en conflit. La transaction sérialise
la séquence lecture-vérification-écriture.

---

## Validation ends_at > starts_at

L'application valide l'ordre temporel avant la DB :

```php
if ($endsAt <= $startsAt) {
    throw new ValidationException([
        new ValidationError('ends_at', 'ends_at must be after starts_at.', 'invalid_range'),
    ]);
}
```

Le schéma ajoute `CHECK(ends_at > starts_at)` comme filet de sécurité. Les deux couches ensemble
assurent que les plages invalides n'atteignent jamais le stockage.

---

## Comparaison de chaînes de dates ISO 8601

Les heures de travail sont stockées comme chaînes ISO 8601 (`2026-05-27T09:00:00+09:00`) et comparées
lexicographiquement en SQL. Cela fonctionne correctement **uniquement quand tous les horaires utilisent le même
décalage de fuseau horaire ou UTC**. Les comparaisons avec décalages mixtes peuvent produire des résultats incorrects :

```
"2026-05-27T09:00:00+09:00" < "2026-05-27T01:00:00Z"  → incorrect (même instant)
```

**Recommandation** : Normaliser tous les datetimes en UTC avant le stockage :

```php
$utc      = new \DateTimeZone('UTC');
$startsAt = (new \DateTimeImmutable($raw))->setTimezone($utc)->format(\DateTimeInterface::ATOM);
```

---

## Mapping exception de domaine → réponse HTTP personnalisée

Les exceptions de domaine se mappent à des réponses Problem Details structurées via des handlers :

```php
final readonly class ShiftOverlapExceptionHandler implements DomainExceptionHandlerInterface
{
    public function supports(\Throwable $exception): bool
    {
        return $exception instanceof ShiftOverlapException;
    }

    public function handle(\Throwable $exception, ServerRequestInterface $request): ResponseInterface
    {
        return $this->factory->create(
            $request,
            'shift-overlap',
            'Shift overlaps with an existing shift.',
            409,
            $exception->getMessage(),
        );
    }
}
```

Des handlers séparés existent pour `ShiftNotFoundException` → 404, `EmployeeNotFoundException` → 404,
et `ShiftOverlapException` → 409. Les enregistrer dans `RuntimeApplicationFactory` libère
les contrôleurs du boilerplate `try/catch`.

---

## Requêtes agrégées : résumé hebdomadaire et heures supplémentaires

```php
// GET /summary/weekly?from=2026-05-19&to=2026-05-25
// GET /summary/overtime?from=2026-05-19&to=2026-05-25&threshold=40
```

Le seuil des heures supplémentaires est par défaut 40 heures :

```php
$threshold = (float) (QueryStringParser::int($request, 'threshold') ?? 40);
if ($threshold <= 0) {
    throw new ValidationException([...]);
}
```

Note : `QueryStringParser::int()` est utilisé en premier (rejette les chaînes non numériques), puis casté
en `float`. Cela empêche `NaN` / `Infinity` d'atteindre la couche métier.

---

## Schéma : suppression en cascade et contraintes DB

```sql
CREATE TABLE employees (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    name        TEXT    NOT NULL,
    role        TEXT    NOT NULL,
    hourly_rate REAL    NOT NULL CHECK(hourly_rate > 0),
    created_at  TEXT    NOT NULL
);

CREATE TABLE shifts (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    employee_id INTEGER NOT NULL REFERENCES employees(id) ON DELETE CASCADE,
    starts_at   TEXT    NOT NULL,
    ends_at     TEXT    NOT NULL,
    location    TEXT    NOT NULL DEFAULT '',
    created_at  TEXT    NOT NULL,
    CHECK(ends_at > starts_at)
);
```

`ON DELETE CASCADE` supprime les horaires d'un employé lors de la suppression de l'employé.
Les contraintes `CHECK` au niveau DB sont des filets de sécurité en profondeur, pas la couche
de validation principale — la validation au niveau app doit retourner 422 avant tout INSERT DB.

---

## VULN — Audit de sécurité (FT225)

Chaque résultat enregistre le vecteur d'attaque, le résultat observé, et le verdict :
**BLOCKED** (sécurisé), **EXPOSED** (vulnérabilité réelle), **PARTIALLY EXPOSED**,
ou **ACCEPTED BY DESIGN**.

### V-01 — Pas d'authentification sur aucun endpoint

**Attaque** : Créer des employés, planifier des horaires, ou supprimer des horaires sans credentials.

```http
POST /employees
{"name": "Attacker", "role": "Ghost", "hourly_rate": 0.01}

DELETE /shifts/1
```

**Observé** : Les deux réussissent. Aucun token, session ou clé API n'est requis.

**Verdict** : **EXPOSED** (par conception pour la démo FT43).
Les systèmes de planification en production DOIVENT protéger les mutations derrière l'authentification.
Utiliser `MachineApiKeyMiddleware` (env : `NENE2_MACHINE_API_KEY`) ou JWT Bearer.

---

### V-02 — Pas d'autorisation : n'importe qui peut supprimer n'importe quel horaire

**Attaque** : Supprimer un horaire appartenant à un autre employé sans vérification de propriété.

```http
DELETE /shifts/1   # réussit pour tout appelant authentifié ou non
```

**Observé** : `204 No Content` quelle que soit l'identité de l'appelant.

**Verdict** : **EXPOSED** (par conception pour la démo FT43).
Ajouter une vérification de rôle manager/admin avant la suppression, ou lier les horaires à un utilisateur demandeur.

---

### V-03 — Injection SQL via requêtes paramétrées

**Attaque** : Injecter du SQL via `name`, `role`, `starts_at`, ou `location`.

```json
{"name": "x'; DROP TABLE employees; --", "role": "Admin", "hourly_rate": 1}
{"starts_at": "2026-01-01' OR '1'='1", "ends_at": "2026-01-02", "employee_id": 1}
```

**Observé** : L'employé est créé avec la chaîne d'injection comme nom. `starts_at` du horaire est
utilisé dans une requête paramétrée, donc aucune injection SQL ne se produit.

**Verdict** : **BLOCKED** — toutes les requêtes utilisent des requêtes paramétrées PDO. La chaîne stockée
est inoffensive dans la DB ; le seul risque serait si elle était rendue comme HTML plus tard.

---

### V-04 — Condition de course dans la détection de chevauchement

**Attaque** : Envoyer deux requêtes `POST /shifts` concurrentes avec des fenêtres temporelles qui se chevauchent
pour le même employé.

**Observé** : La vérification de chevauchement s'exécute à l'intérieur de `transactional()`. SQLite sérialise
les écritures avec le verrouillage WAL-mode ; MySQL/PostgreSQL utilisent l'isolation `REPEATABLE READ` ou `SERIALIZABLE`
quand le gestionnaire de transaction est correctement configuré. Les deux insertions concurrentes ne peuvent pas toutes deux
passer la vérification de chevauchement.

**Verdict** : **BLOCKED** — la vérification de chevauchement transactionnelle prévient la double réservation sous
concurrence. Vérifier que le niveau d'isolation correspond au moteur DB ; le WAL par défaut de SQLite est
suffisant pour les déploiements mono-nœud.

---

### V-05 — ends_at ≤ starts_at accepté

**Attaque** : Soumettre un horaire où l'heure de fin est avant ou égale à l'heure de début.

```json
{"employee_id": 1, "starts_at": "2026-05-27T10:00:00Z", "ends_at": "2026-05-27T09:00:00Z"}
{"employee_id": 1, "starts_at": "2026-05-27T10:00:00Z", "ends_at": "2026-05-27T10:00:00Z"}
```

**Observé** : `422 Unprocessable Entity` — l'app compare les chaînes (`$endsAt <= $startsAt`)
avant d'insérer. Le `CHECK(ends_at > starts_at)` DB est un filet de sécurité.

**Verdict** : **BLOCKED** — validation en deux couches (app + contrainte DB).

---

### V-06 — Lacune de validation hourly_rate

**Attaque** : Soumettre une valeur négative, nulle ou chaîne pour `hourly_rate`.

```json
{"name": "X", "role": "Y", "hourly_rate": -10}
{"name": "X", "role": "Y", "hourly_rate": 0}
{"name": "X", "role": "Y", "hourly_rate": "free"}
```

**Observé** :
- Négatif/nul : L'application NE valide PAS `hourly_rate > 0` au niveau contrôleur.
  Une valeur négative contourne la vérification app et atteint le `CHECK(hourly_rate > 0)` DB,
  qui lève une exception DB. Sans handler explicite, cela devient un 500.
- Chaîne `"free"` : `is_numeric()` retourne false, donc rejetée avec 422.

**Verdict** : **PARTIALLY EXPOSED** — ajouter la validation au niveau app avant l'INSERT DB :
```php
if (!isset($body['hourly_rate'])
    || !is_numeric($body['hourly_rate'])
    || (float) $body['hourly_rate'] <= 0) {
    $errors[] = new ValidationError('hourly_rate', 'hourly_rate must be a positive number.', 'out_of_range');
}
```

---

### V-07 — Datetime ISO 8601 sémantiquement invalide

**Attaque** : Soumettre un horaire avec un datetime structurellement plausible mais calendriquement invalide.

```json
{"starts_at": "2026-02-30T00:00:00Z", "ends_at": "2026-02-30T08:00:00Z", "employee_id": 1}
```

**Observé** : Accepté et stocké. L'application vérifie `trim() === ''` mais ne parse pas la date.
`DateTimeImmutable` normalise silencieusement `2026-02-30` en `2026-03-02`,
corrompant la valeur stockée.

**Verdict** : **EXPOSED** — ajouter une vérification aller-retour sur `starts_at` et `ends_at` :
```php
$dt = DateTimeImmutable::createFromFormat(DateTimeInterface::ATOM, $raw);
if ($dt === false || $dt->format(DateTimeInterface::ATOM) !== $raw) {
    $errors[] = new ValidationError('starts_at', 'starts_at must be a valid ISO 8601 datetime.', 'invalid_format');
}
```

---

### V-08 — Plage de dates non bornée dans les requêtes agrégées

**Attaque** : Demander un résumé sur une plage de dates arbitrairement large pour épuiser la mémoire
ou causer une requête lente.

```http
GET /summary/weekly?from=1900-01-01&to=2099-12-31
```

**Observé** : La requête s'exécute sur toutes les lignes de la table. Avec un grand dataset, cela peut causer
une utilisation mémoire excessive ou une réponse de plusieurs secondes.

**Verdict** : **EXPOSED** — limiter la plage maximale autorisée (ex. 90 jours) au niveau
contrôleur :
```php
$maxDays = 90;
$diff    = (new DateTimeImmutable($to))->diff(new DateTimeImmutable($from));
if ($diff->days > $maxDays) {
    return $this->json->create(['error' => "Date range must not exceed {$maxDays} days."], 422);
}
```

---

### V-09 — Longueur non bornée du nom / rôle employé

**Attaque** : Créer un employé avec un nom ou rôle de dizaines de milliers de caractères.

```json
{"name": "AAAA... (50000 chars)", "role": "Y", "hourly_rate": 10}
```

**Observé** : `201 Created` — SQLite TEXT est non borné ; la ligne est insérée.

**Verdict** : **EXPOSED** — ajouter des vérifications `mb_strlen()` et retourner 422 :
```php
if (mb_strlen($name) > 100) {
    $errors[] = new ValidationError('name', 'name must not exceed 100 characters.', 'max_length');
}
```

---

### V-10 — Chaîne de location non bornée

**Attaque** : Planifier un horaire avec une chaîne de location de longueur arbitraire.

```json
{"employee_id": 1, "starts_at": "...", "ends_at": "...", "location": "BBBB... (50000 chars)"}
```

**Observé** : `201 Created` — aucune limite de longueur n'est imposée.

**Verdict** : **EXPOSED** — ajouter la vérification `mb_strlen($location) <= 200`.

---

### V-11 — Payload XSS dans name / role / location

**Attaque** : Stocker une balise `<script>` dans n'importe quel champ texte libre.

```json
{"name": "<script>alert(1)</script>", "role": "Admin", "hourly_rate": 1}
```

**Observé** : `201 Created`. La valeur est retournée telle quelle dans les réponses JSON.

**Verdict** : **ACCEPTED BY DESIGN** — c'est une API JSON ; l'échappement est la responsabilité
du client de rendu HTML. Le serveur n'émet pas d'HTML depuis ces champs.
Documenter le contrat dans la spec OpenAPI.

---

### V-12 — IDs de chemin non numériques

**Attaque** : Passer des valeurs non numériques ou négatives comme `{id}`.

```http
GET /shifts/abc
GET /shifts/-1
DELETE /employees/0
```

**Observé** : `404 Not Found` dans chaque cas. `(int) "abc"` = `0` ; aucun horaire/employé
avec ID 0 ou négatif n'existe, donc `findShiftById(0)` lève `ShiftNotFoundException`,
que le handler mappe en 404.

**Verdict** : **BLOCKED** en pratique. Note : `(int) "9abc"` = `9` — si un enregistrement avec
l'ID 9 existe il serait retourné. Utiliser `ctype_digit()` pour la validation stricte des IDs de chemin
quand la différence est importante.

---

## Résumé VULN

| # | Vecteur d'attaque | Verdict |
|---|-------------------|---------|
| V-01 | Pas d'authentification | EXPOSED (par conception) |
| V-02 | Pas d'autorisation / tout horaire supprimable | EXPOSED (par conception) |
| V-03 | Injection SQL | BLOCKED |
| V-04 | Condition de course de chevauchement | BLOCKED |
| V-05 | ends_at ≤ starts_at | BLOCKED |
| V-06 | hourly_rate négatif contourne la vérification app | PARTIALLY EXPOSED |
| V-07 | Datetime ISO 8601 sémantiquement invalide | EXPOSED |
| V-08 | Plage de dates non bornée dans les requêtes agrégées | EXPOSED |
| V-09 | Nom/rôle employé non borné | EXPOSED |
| V-10 | Chaîne de location non bornée | EXPOSED |
| V-11 | Stockage payload XSS | ACCEPTED BY DESIGN |
| V-12 | IDs de chemin non numériques | BLOCKED |

**Vulnérabilités réelles à corriger avant la production** :
1. **V-01/02** — Ajouter l'authentification et l'autorisation basée sur les rôles
2. **V-06** — Ajouter la validation `hourly_rate > 0` au niveau app
3. **V-07** — Ajouter la validation aller-retour ISO 8601 pour les champs datetime
4. **V-08** — Limiter la plage de dates maximale dans les endpoints agrégés (ex. 90 jours)
5. **V-09/10** — Ajouter des vérifications de longueur maximale `mb_strlen()` sur tous les champs texte libres

---

## Howtos connexes

- [`notification-inbox.md`](notification-inbox.md) — Pattern de protection IDOR (404 sur lecture/écriture non autorisée)
- [`prevent-double-booking.md`](prevent-double-booking.md) — Prévention transactionnelle de la double réservation
- [`expense-tracker.md`](expense-tracker.md) — Validation aller-retour de date ISO 8601
- [`resource-booking.md`](resource-booking.md) — Limitation de plage de dates et requêtes de fenêtre temporelle
