# How-To : API de page de statut de service

> **NENE2 Field Trial 185** — Suivi de la santé des composants, gestion du cycle de vie des incidents,
> protection par clé admin avec `V::secret()` + `hash_equals()`.

---

## Ce que ce trial démontre

Une API de page de statut de service nécessite :
1. **Suivi du statut des composants** — operational / degraded / partial_outage / major_outage
2. **Cycle de vie des incidents** — investigating → identified → monitoring → resolved
3. **Garde d'immuabilité** — les incidents résolus ne peuvent pas être mis à jour (empêche la réouverture)
4. **Protection par clé admin** — `V::secret()` impose la comparaison en temps constant pour les opérations d'écriture
5. **Enforcement d'enum de statut** — la liste blanche `V::enum()` empêche l'injection de valeurs inconnues

---

## API

| Méthode | Chemin | Auth | Description |
|---|---|---|---|
| `GET` | `/components` | — | Lister tous les composants (public) |
| `POST` | `/components` | X-Admin-Key | Créer un composant |
| `PATCH` | `/components/{id}` | X-Admin-Key | Mettre à jour le statut d'un composant |
| `GET` | `/incidents` | — | Lister les incidents (public, `?open=1` pour les actifs) |
| `GET` | `/incidents/{id}` | — | Détail d'incident avec timeline de mises à jour |
| `POST` | `/incidents` | X-Admin-Key | Créer un incident |
| `PATCH` | `/incidents/{id}` | X-Admin-Key | Mettre à jour le statut d'un incident |
| `POST` | `/incidents/{id}/updates` | X-Admin-Key | Ajouter un message de mise à jour |

---

## Pattern principal : Auth par clé admin avec `V::secret()`

```php
// V::secret() vérifie : $expected !== '' && hash_equals($expected, $actual)
private function requireAdmin(ServerRequestInterface $request): bool
{
    return V::secret($this->adminKey, $request->getHeaderLine('X-Admin-Key'));
}

// Utilisation dans chaque handler d'écriture :
if (!$this->requireAdmin($request)) {
    return $this->responseFactory->create(['error' => 'X-Admin-Key is required.'], 401);
}
```

**Pourquoi `V::secret()` et non `=== $key` :**
- `===` est court-circuit : le timing varie selon la longueur de correspondance → oracle de timing
- `hash_equals()` est en temps constant quel que soit l'endroit où les chaînes diffèrent
- La garde `$expected !== ''` empêche d'accepter accidentellement des clés vides

---

## Enforcement d'enum de statut avec `V::enum()`

```php
// V::enum(mixed $raw, string $enumClass): ?\BackedEnum
// Passe le nom de classe — retourne une instance d'enum typée ou null

$statusEnum = V::enum($body['status'] ?? null, ComponentStatus::class);

if (!$statusEnum instanceof ComponentStatus) {
    return $this->responseFactory->create(
        ['error' => 'status must be one of: ' . implode(', ', ComponentStatus::values()) . '.'],
        422,
    );
}

// $statusEnum est déjà l'enum typé correct — pas besoin de ::from()
$component = $this->repository->updateComponentStatus($id, $statusEnum);
```

**Pourquoi l'enforcement d'enum est important :**
- Sans lui, des chaînes arbitraires atteignent la DB
- Les vecteurs d'injection via `ORDER BY status` SQL sont bloqués
- La liste blanche est constituée des cas de l'enum lui-même — toujours synchronisée

---

## Cycle de vie des incidents et garde de transition

```php
enum IncidentStatus: string
{
    case Investigating = 'investigating';
    case Identified    = 'identified';
    case Monitoring    = 'monitoring';
    case Resolved      = 'resolved';

    public function isResolved(): bool
    {
        return $this === self::Resolved;
    }
}
```

**Garde de transition dans chaque handler d'écriture :**
```php
$incident = $this->repository->findIncidentById($id);

// Les incidents résolus sont immuables — empêche la réouverture accidentelle
if ($incident->status->isResolved()) {
    return $this->responseFactory->create(
        ['error' => 'Resolved incidents cannot be updated.'],
        409,
    );
}
```

**Pourquoi 409 (Conflict) et non 422 (Unprocessable) :**
- La requête est syntaxiquement valide
- Le conflit est avec l'état actuel de la ressource
- 409 communique "requête valide, mauvais timing"

---

## Valeurs de statut des composants

```php
enum ComponentStatus: string
{
    case Operational   = 'operational';    // tous les systèmes fonctionnent
    case Degraded      = 'degraded';       // performances réduites
    case PartialOutage = 'partial_outage'; // certaines fonctionnalités indisponibles
    case MajorOutage   = 'major_outage';   // panne de service complète
}
```

---

## Timestamp `resolved_at` automatique

```php
public function updateIncidentStatus(int $id, IncidentStatus $status): ?Incident
{
    $now        = $this->now();
    $resolvedAt = $status->isResolved() ? $now : null;

    $stmt = $this->pdo->prepare(
        'UPDATE incidents SET status = :status, resolved_at = :resolved_at, updated_at = :now WHERE id = :id'
    );
    $stmt->execute(['status' => $status->value, 'resolved_at' => $resolvedAt, ...]);
}
```

Le timestamp `resolved_at` est défini côté serveur — jamais depuis le body de la requête.

---

## Parsing des IDs entiers (sans injection)

```php
private function parseId(ServerRequestInterface $request, string $param): ?int
{
    $raw = Router::param($request, $param);

    // ctype_digit : rejette les négatifs, flottants, chaînes, traversées de chemin
    if ($raw === null || !ctype_digit($raw)) {
        return null;
    }

    $id = (int) $raw;

    return $id > 0 ? $id : null; // rejette aussi le zéro
}
```

---

## Filtre d'incidents ouverts

```php
// ?open=1 filtre les incidents résolus
$openOnly = isset($params['open']) && $params['open'] === '1';

if ($openOnly) {
    $stmt = $pdo->prepare(
        "SELECT * FROM incidents WHERE status != 'resolved' ORDER BY created_at DESC"
    );
} else {
    $stmt = $pdo->query('SELECT * FROM incidents ORDER BY created_at DESC');
}
```

---

## Exemple complet de cycle de vie d'incident

```
POST /incidents          → 201 {status: "investigating", impact: "major"}
POST /incidents/1/updates → 201 {message: "Root cause identified."}
PATCH /incidents/1       → 200 {status: "identified"}
PATCH /incidents/1       → 200 {status: "monitoring"}
PATCH /incidents/1       → 200 {status: "resolved", resolved_at: "2026-05-26T..."}
PATCH /incidents/1       → 409 Resolved incidents cannot be updated.
GET /incidents?open=1    → 200 {count: 0}  — le résolu n'est plus affiché
```

---

## Résultats des tests

```
46 tests / 93 assertions — tous PASS
PHPStan level 8 — aucune erreur
PHP CS Fixer — propre
```

---

## Points clés

| Pattern | Règle |
|---|---|
| Auth par clé admin | `V::secret()` — `hash_equals()` en temps constant, garde la clé vide |
| Validation d'enum | `V::enum($raw, EnumClass::class)` — retourne l'enum typé ou null |
| Garde de transition | Vérifier l'état actuel avant d'appliquer le changement — 409 pour résolu |
| `resolved_at` | Timestamp défini côté serveur, jamais depuis le body de la requête |
| IDs entiers | `ctype_digit()` + garde `> 0` — rejette chaînes, négatifs, zéro |
| Lecture publique | Pas d'auth pour les endpoints GET — les pages de statut sont censées être publiques |
| Historique immuable | Les mises à jour d'incident sont en append-only — pas d'édition/suppression |

Exemple complet : [`../NENE2-FT/statuslog/`](https://github.com/hideyukiMORI/NENE2-examples) dans le dépôt d'exemples.
