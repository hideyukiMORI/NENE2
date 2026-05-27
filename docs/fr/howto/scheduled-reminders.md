# How-to : API de rappels programmés

> **Référence FT** : FT235 (`NENE2-FT/reminderlog`) — API de rappels programmés

Démontre une API de planification de rappels avec validation de datetime future tenant compte des fuseaux horaires, identification légère par utilisateur via en-tête, prévention IDOR via requêtes scopées par propriété, et distinction 404/409 lors de l'annulation d'un rappel.

---

## Routes

| Méthode | Chemin | Description |
|---------|--------|-------------|
| `POST` | `/reminders` | Créer un rappel (`remind_at` futur requis) |
| `GET` | `/reminders` | Lister les rappels de l'appelant (filtrable par statut) |
| `PATCH` | `/reminders/{id}/cancel` | Annuler un rappel en attente |

Toutes les routes nécessitent l'en-tête `X-User-Id`.

---

## Identification légère de l'utilisateur via en-tête

Plutôt que Bearer JWT, cette API utilise un en-tête entier `X-User-Id` comme mécanisme d'authentification/identification minimal :

```php
$userId = V::userId($request->getHeaderLine('X-User-Id'));

if ($userId === null) {
    return $this->responseFactory->create(
        ['error' => 'X-User-Id header must be a positive integer.'],
        401,
    );
}
```

`V::userId()` valide la valeur de l'en-tête :

```php
public static function userId(string $header): ?int
{
    // ctype_digit('') === false — la chaîne vide est déjà rejetée.
    if (!ctype_digit($header) || strlen($header) > 18) {
        return null;
    }

    $id = (int) $header;

    return $id > 0 ? $id : null;
}
```

Propriétés clés :
- `ctype_digit()` — immunisé aux ReDoS, rejette `0`, `-1`, `1.5`, `abc`, chaîne vide.
- `strlen > 18` — garde de débordement avant le cast `(int)` (PHP_INT_MAX est à 19 chiffres).
- `$id > 0` — rejette le zéro entier analysé.

Pour la production, remplacer par une validation JWT ou de session. Le pattern `X-User-Id` est adapté aux services internes où la passerelle en amont a déjà authentifié l'utilisateur et transmet son ID.

---

## Validation de datetime future (tenant compte des fuseaux horaires)

`remind_at` doit être un datetime ISO 8601 valide avec un décalage de fuseau horaire explicite **et** doit être strictement dans le futur par rapport à maintenant :

```php
$now      = (new DateTimeImmutable())->format(DATE_ATOM);
$remindAt = V::futureDatetime($rawRemindAt, $now);

if ($remindAt === null) {
    return $this->responseFactory->create(
        ['error' => 'remind_at must be a valid ISO 8601 datetime with timezone offset and must be in the future.'],
        422,
    );
}
```

`V::futureDatetime()` compose deux vérifications :

```php
public static function futureDatetime(mixed $raw, string $now): ?string
{
    $dt = self::isoDatetime($raw);   // Étape 1 : validation format + plage

    if ($dt === null) {
        return null;
    }

    // Étape 2 : vérification future tenant compte du fuseau horaire
    $dtObj  = DateTimeImmutable::createFromFormat(DATE_ATOM, $dt);
    $nowObj = DateTimeImmutable::createFromFormat(DATE_ATOM, $now);

    if ($dtObj === false || $nowObj === false) {
        return null;
    }

    return $dtObj > $nowObj ? $dt : null;  // La comparaison d'objets normalise en UTC
}
```

`V::isoDatetime()` effectue d'abord la vérification de format :

```php
public static function isoDatetime(mixed $raw): ?string
{
    // Regex strict : nécessite le décalage ±HH:MM — rejette 'Z', date seule, décalage manquant.
    if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}([+-])(\d{2}):(\d{2})$/', $raw, $m)) {
        return null;
    }

    // Valider la plage du décalage de fuseau horaire : les décalages UTC valides sont −14:00 … +14:00.
    $tzHours   = (int) $m[2];
    $tzMinutes = (int) $m[3];

    if ($tzHours > 14 || $tzMinutes > 59 || ($tzHours === 14 && $tzMinutes > 0)) {
        return null;
    }
    // ... validation aller-retour pour les dates de débordement (30 fév etc.)
}
```

La comparaison d'objets `DateTimeImmutable` (`>`) convertit les deux côtés en UTC avant
de comparer — donc `2026-06-01T09:00:00+09:00` (00:00 UTC) est correctement comparé à
`2026-06-01T01:00:00+01:00` (00:00 UTC) comme égaux.

---

## Prévention IDOR : recherche scopée par propriété

Toutes les opérations qui touchent un rappel spécifique utilisent `WHERE id = ? AND user_id = ?` :

```php
public function findForUser(int $id, int $userId): ?Reminder
{
    $stmt = $this->pdo->prepare('SELECT * FROM reminders WHERE id = ? AND user_id = ?');
    $stmt->execute([$id, $userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $this->hydrate($row) : null;
}
```

Si le rappel appartient à un autre utilisateur, `findForUser()` retourne `null` — l'appelant
reçoit `404 Not Found`, indiscernable de "le rappel n'existe pas". Retourner
`403 Forbidden` confirmerait que l'ID existe, révélant des informations d'énumération.

---

## 404 vs 409 : annulation avec récupération préalable

Le handler d'annulation récupère le rappel avant de vérifier le statut. Cette approche en deux étapes
permet de retourner le statut HTTP correct pour chaque mode d'échec :

```php
// Récupérer d'abord pour distinguer 404 (non trouvé/mauvais propriétaire) de 409 (mauvais statut)
$reminder = $this->repository->findForUser($id, $userId);

if ($reminder === null) {
    return $this->responseFactory->create(['error' => 'Reminder not found.'], 404);
}

if ($reminder->status !== ReminderStatus::Pending) {
    return $this->responseFactory->create(
        ['error' => sprintf('Cannot cancel a reminder with status "%s".', $reminder->status->value)],
        409,
    );
}

$this->repository->cancel($id, $userId);
```

L'annulation au niveau DB inclut la garde de statut comme filet de sécurité :

```php
public function cancel(int $id, int $userId): bool
{
    $stmt = $this->pdo->prepare(
        "UPDATE reminders SET status = 'cancelled' WHERE id = ? AND user_id = ? AND status = 'pending'"
    );
    $stmt->execute([$id, $userId]);

    return $stmt->rowCount() > 0;
}
```

`WHERE status = 'pending'` dans le UPDATE assure qu'une condition de course (deux requêtes d'annulation concurrentes) résulte en seulement une ligne mise à jour.

---

## Validation des paramètres de requête (`?limit=` et `?status=`)

`limit` utilise `V::queryInt()` qui distingue clé absente (utiliser la valeur par défaut) de valeur invalide (retourner 422) :

```php
$limit = V::queryInt(
    $params,
    'limit',
    ReminderRepository::MIN_LIMIT,   // 1
    ReminderRepository::MAX_LIMIT,   // 100
    ReminderRepository::DEFAULT_LIMIT, // 20 — retourné quand la clé est absente
);

if ($limit === null) {
    return $this->responseFactory->create(
        ['error' => sprintf('limit must be between %d and %d.', MIN_LIMIT, MAX_LIMIT)],
        422,
    );
}
```

`?status=` utilise `V::enum()` pour valider contre le backed enum :

```php
$status = V::enum($rawStatus, ReminderStatus::class);

if ($status === null) {
    return $this->responseFactory->create(
        ['error' => 'status must be one of: pending, triggered, cancelled.'],
        422,
    );
}
```

`V::enum()` appelle `BackedEnum::tryFrom()` en interne, retournant `null` pour les valeurs inconnues.

---

## Schéma

```sql
CREATE TABLE reminders (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    message    TEXT    NOT NULL,
    remind_at  TEXT    NOT NULL,  -- ISO 8601 avec décalage de fuseau horaire, stocké tel quel
    status     TEXT    NOT NULL DEFAULT 'pending',
    created_at TEXT    NOT NULL,
    CHECK (status IN ('pending', 'triggered', 'cancelled'))
);

CREATE INDEX idx_reminders_user   ON reminders (user_id, id);
CREATE INDEX idx_reminders_status ON reminders (status, id);
```

`remind_at` est stocké comme la chaîne ISO 8601 originale avec le décalage de fuseau horaire du soumetteur
(ex. `2026-06-01T09:00:00+09:00`). La DB ne normalise pas en UTC — l'application est responsable de la comparaison correcte (voir `V::futureDatetime()`).

Deux index :
- `(user_id, id)` — couvre la liste par utilisateur et les recherches d'annulation
- `(status, id)` — couvre une requête de scruteur qui récupère les rappels `pending` prêts à se déclencher

---

## Enum de statut

```php
enum ReminderStatus: string
{
    case Pending   = 'pending';
    case Triggered = 'triggered';
    case Cancelled = 'cancelled';
}
```

Seuls les rappels `pending` peuvent être annulés (`409` sinon). `triggered` est défini par
un job en arrière-plan quand le rappel se déclenche — cette API n'inclut pas l'endpoint de déclenchement,
qui s'exécuterait sur une tâche planifiée en dehors du serveur HTTP.

---

## Howtos connexes

- [`iso-datetime-validation.md`](iso-datetime-validation.md) — Patterns de validation de datetime ISO 8601
- [`content-scheduling.md`](content-scheduling.md) — Publication programmée avec `publish_at` futur
- [`approval-workflow.md`](approval-workflow.md) — Distinction 404/409 dans les transitions de statut
- [`enforce-resource-ownership.md`](enforce-resource-ownership.md) — Patterns de prévention IDOR
