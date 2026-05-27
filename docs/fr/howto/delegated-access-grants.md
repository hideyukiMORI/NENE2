# How-to : Délégations d'accès

> **Référence FT** : FT282 (`NENE2-FT/grantlog`) — Délégations d'accès : accès ressource limité dans le temps et scopé (read/write/admin), UNIQUE(grantor, grantee, resource) + CHECK(grantor != grantee), IDOR → 404, révocation soft-delete, suivi use-count, hiérarchie GrantScope.satisfies(), 23 tests / 71 assertions PASS.
>
> Aussi validé en FT176 — implémentation originale.

Délégation d'accès par utilisateur, limitée dans le temps, révocable — un grantor donne à un grantee un accès scopé à une ressource nommée pour une fenêtre temporelle bornée.

---

## Vue d'ensemble

Les délégations d'accès permettent à un utilisateur (`grantor`) de donner à un autre utilisateur (`grantee`) un accès limité dans le temps et scopé à un identifiant de ressource. Pensez "partager document:42 en lecture seule avec l'utilisateur 7, expire dans 24 heures, révocable à tout moment."

Propriétés clés :

- **Multi-partie** — grantor et grantee sont toujours des utilisateurs différents ; les auto-délégations sont rejetées.
- **Machine à états** — active → révoquée (sens unique) ; l'état expiré est calculé depuis `expires_at`.
- **Ressource opaque** — `resource` est une chaîne libre ; le serveur la stocke verbatim.
- **Unicité idempotente** — une délégation unique par `(grantor_id, grantee_id, resource)`.
- **Sûr contre IDOR** — toutes les vérifications de propriété retournent 404, pas 403, pour éviter l'énumération d'existence.

---

## Schéma

```sql
CREATE TABLE grants (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    grantor_id  INTEGER NOT NULL,
    grantee_id  INTEGER NOT NULL,
    resource    TEXT    NOT NULL,
    scope       TEXT    NOT NULL DEFAULT 'read',
    expires_at  TEXT    NOT NULL,
    revoked_at  TEXT,
    used_count  INTEGER NOT NULL DEFAULT 0,
    created_at  TEXT    NOT NULL,
    UNIQUE (grantor_id, grantee_id, resource),
    CHECK (scope IN ('read', 'write', 'admin')),
    CHECK (grantor_id != grantee_id)
);
```

Le `CHECK (grantor_id != grantee_id)` est une mesure de défense en profondeur —
l'auto-délégation doit aussi être rejetée au niveau applicatif pour une réponse d'erreur claire.

---

## Couche domaine

### Enum GrantScope avec hiérarchie

```php
enum GrantScope: string
{
    case Read  = 'read';
    case Write = 'write';
    case Admin = 'admin';

    public function satisfies(self $required): bool
    {
        $rank = [self::Read->value => 0, self::Write->value => 1, self::Admin->value => 2];
        return $rank[$this->value] >= $rank[$required->value];
    }
}
```

### Entité Grant — méthodes d'état calculé

```php
final readonly class Grant
{
    public function isExpired(string $now): bool  { return $this->expiresAt <= $now; }
    public function isRevoked(): bool             { return $this->revokedAt !== null; }
    public function isActive(string $now): bool   { return !$this->isExpired($now) && !$this->isRevoked(); }
}
```

Vérifier **révoqué en premier**, puis l'expiration — les deux chemins retournent 403 mais avec des corps d'erreur distincts pour que les grantees comprennent pourquoi l'accès a échoué sans exposer les détails internes du système.

---

## Endpoints HTTP

| Méthode | Chemin | Auth | Objectif |
|---------|--------|------|---------|
| `POST` | `/grants` | `X-User-Id` (grantor) | Créer une délégation |
| `GET` | `/grants/issued` | `X-User-Id` | Lister les délégations émises par l'appelant |
| `GET` | `/grants/received` | `X-User-Id` | Lister les délégations reçues par l'appelant |
| `DELETE` | `/grants/{id}` | `X-User-Id` (doit être grantor) | Révoquer une délégation |
| `POST` | `/grants/{id}/use` | `X-User-Id` (doit être grantee) | Utiliser une délégation |

---

## Règles de validation

| Champ | Règle |
|-------|-------|
| `grantee_id` | Doit être un **entier JSON** > 0 ; la chaîne `"2"`, null, booléen, float rejetés |
| `resource` | Chaîne non vide ; ≤ 500 caractères UTF-8 ; stocké verbatim (opaque) |
| `scope` | Doit être l'un de `read` / `write` / `admin` |
| `expires_at` | ISO 8601 valide ; doit être dans le futur ; ≤ 30 jours à partir de maintenant |
| Auto-délégation | `grantee_id == grantor X-User-Id` → 422 |

### Parsing strict de champ entier

Une vulnérabilité courante est la coercition de type implicite — accepter `"2"` (chaîne JSON) comme `2` (int). Utiliser une vérification de type explicite :

```php
private function intField(array $body, string $key): ?int
{
    if (!array_key_exists($key, $body)) {
        return null;
    }
    // is_int() retourne false pour "2", null, true, 2.5 — uniquement true pour int PHP
    return is_int($body[$key]) ? $body[$key] : null;
}
```

Note : `2.0` (float PHP) est indiscernable de `2` (int) après `json_encode` — utiliser `2.5` pour tester le rejet des floats dans les tests unitaires.

---

## Machine à états

```
         revoke()
active ─────────────→ révoquée   (409 sur seconde révocation)
  │
  │ expires_at ≤ now
  ↓
expirée

révoquée + expirée → révoquée gagne (vérifier révoqué en premier)
```

La double révocation doit être rejetée avec **409**, pas silencieusement acceptée.
L'horodatage `revoked_at` ne doit pas changer lors du second appel.

---

## Pattern de protection IDOR

```php
// DELETE /grants/{id}
$grant = $this->repository->find($id);

// Retourner 404 pour "introuvable" ET "pas votre délégation"
// Ne jamais retourner 403 ici — cela fuiterait l'existence
if ($grant === null || $grant->grantorId !== $callerId) {
    return $this->responseFactory->create(['error' => "Grant #{$id} not found."], 404);
}
```

Même pattern s'applique à `POST /grants/{id}/use` — retourner 404 si l'appelant n'est pas le grantee.

---

## Prévention de la confusion multi-partie

| Scénario | Attendu |
|----------|---------|
| Le grantor appelle `POST /grants/{id}/use` (sa propre délégation) | 404 — le grantor n'est pas le grantee |
| Le grantee appelle `DELETE /grants/{id}` | 404 — le grantee n'est pas le grantor |
| L'utilisateur 3 appelle l'un ou l'autre sur une délégation entre utilisateurs 1 et 2 | 404 — IDOR |
| `X-User-Id: 0` ou `X-User-Id: -1` | 401 — IDs non positifs rejetés |
| `X-User-Id` manquant | 401 |

---

## Liste de contrôle de sécurité (ATK-01 à ATK-12)

| # | Vecteur d'attaque | Mitigation |
|---|---|---|
| ATK-01 | Délégation expirée (limite d'horloge) | Comparaison `isExpired()` ; DB `expires_at` antidatée dans le test |
| ATK-02 | Contournement d'état de délégation révoquée | Vérification `isRevoked()` avant utilisation |
| ATK-03 | Auto-délégation (grantor == grantee) | 422 niveau applicatif + `CHECK` DB |
| ATK-04 | Mauvais grantee utilise la délégation (IDOR) | 404, pas 403 |
| ATK-05 | Non-grantor révoque la délégation (IDOR) | 404, pas 403 ; la délégation originale reste active |
| ATK-06 | `expires_at` passé à la création | `strtotime($expiresAt) <= strtotime($now)` → 422 |
| ATK-07 | Confusion de type sur `grantee_id` | Vérification stricte `is_int()` ; rejette `"2"`, `null`, `true`, `2.5` |
| ATK-08 | Path traversal dans `resource` | Stockage opaque ; pas d'accès filesystem |
| ATK-09 | Injection SQL dans `resource`/`scope` | Requêtes paramétrisées ; enum scope rejette la valeur injectée |
| ATK-10 | Unicode/BIDI dans `resource` | Stocké verbatim ; homoglyphes et BIDI sont des ressources distinctes |
| ATK-11 | Double révocation (machine à états) | 409 sur seconde révocation ; `revoked_at` immuable après le premier |
| ATK-12 | Le grantor utilise sa propre délégation comme grantee | 404 — rôles de partie strictement imposés |

---

## Approche de test

- **ATK-01, ATK-02** : Forcer l'état DB directement (`UPDATE grants SET expires_at/revoked_at`) pour simuler le voyage dans le temps sans attendre.
- **ATK-07** : Tester `"2"` (chaîne), `null`, `true`, `2.5` (float) — pas `2.0` (indiscernable de int après PHP json_encode).
- **ATK-10** : Utiliser `"\u{202E}"` (BIDI override) et des homoglyphes cyrilliques pour confirmer le stockage verbatim.
- **ATK-11** : Vérifier que la valeur `revoked_at` est inchangée en DB après la deuxième tentative de révocation.

---

## Ce qu'il ne faut PAS faire

| Anti-pattern | Risque |
|---|---|
| Pas de `UNIQUE (grantor_id, grantee_id, resource)` | La même paire peut créer des délégations en double ; le grantee a des délégations obsolètes et actives pour la même ressource |
| Suppression physique lors de la révocation | Perd l'historique d'audit ; impossible de dire quand l'accès a été supprimé ou combien de fois il a été utilisé |
| Retourner 403 au lieu de 404 pour la vérification de propriété | Révèle l'existence de la délégation aux appelants non autorisés ; surface d'énumération IDOR |
| Pas de `CHECK (grantor_id != grantee_id)` | Défense en profondeur manquante ; les auto-délégations pourraient passer si la vérification niveau applicatif est contournée |
| Accepter une chaîne libre pour le scope | Les fautes de frappe se retrouvent silencieusement à `read` ; utiliser `GrantScope::tryFrom()` pour rejeter les valeurs inconnues |
| Vérification de scope sans hiérarchie `satisfies()` | Un utilisateur `write` doit passer séparément les vérifications `read` ; utiliser la hiérarchie pour vérifier tous les niveaux inférieurs |
| Pas de TTL maximum sur `expires_at` | Le grantor crée des délégations de 100 ans ; accès effectivement permanent sans révision |
| Pas de limite de longueur sur resource | Une chaîne resource de 10 Mo cause des recherches d'index lentes et une allocation mémoire |
| Vérifier l'expiration avant la révocation | Une délégation révoquée + expirée devrait afficher "révoquée" — la révocation gagne dans la machine à états |
| Suivre `used_count` côté client | Le client rapporte le compteur d'utilisation ; le serveur doit posséder le compteur |
