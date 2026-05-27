# Vérification PIN et blocage

> **Référence FT** : FT252 (`NENE2-FT/pinverifylog`) — Vérification PIN avec blocage
> **ATK** : FT252 — test d'attaque mentalité cracker (ATK-01 à ATK-12)

Guide d'implémentation de la prévention de brute force pour PIN à 6 chiffres, contre-mesures contre les attaques de timing, et déblocage administrateur.
Explique le stockage par hash HMAC-SHA256, la comparaison à temps constant et le blocage par comptage d'échecs.

**Sécurité validée par FT192** : VULN-A~L tous Pass / ATK-01~12 tous Pass.

## Vue d'ensemble

- L'administrateur crée un PIN (stockage par hash HMAC-SHA256 — pas de stockage en clair)
- L'utilisateur vérifie le PIN (blocage à partir d'un nombre maximum d'échecs)
- L'administrateur débloque
- L'historique des tentatives est enregistré comme journal d'audit

## Endpoints

| Méthode | Chemin | Auth | Description |
|---------|--------|------|-------------|
| `POST` | `/pins` | `X-Admin-Key` | Créer un PIN |
| `POST` | `/pins/{id}/verify` | — | Vérifier un PIN |
| `GET` | `/pins/{id}` | `X-Admin-Key` | Vérifier l'état (tentatives restantes, expiration du blocage) |
| `POST` | `/pins/{id}/unlock` | `X-Admin-Key` | Débloquer |
| `DELETE` | `/pins/{id}` | `X-Admin-Key` | Supprimer un PIN |

## Conception de la base de données

```sql
CREATE TABLE IF NOT EXISTS pins (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    label        TEXT    NOT NULL,
    pin_hash     TEXT    NOT NULL,        -- HMAC-SHA256(pin, secret)
    attempts     INTEGER NOT NULL DEFAULT 0,
    max_attempts INTEGER NOT NULL DEFAULT 5,
    locked_until TEXT,                    -- ISO 8601 UTC, NULL = débloqué
    created_at   TEXT    NOT NULL,
    updated_at   TEXT    NOT NULL
);

CREATE TABLE IF NOT EXISTS pin_attempts (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    pin_id       INTEGER NOT NULL,
    success      INTEGER NOT NULL DEFAULT 0,
    attempted_at TEXT    NOT NULL
);
```

`locked_until` est stocké comme chaîne ISO 8601 et l'état de blocage est déterminé par comparaison de chaînes avec l'heure actuelle (`$lockedUntil > $now`). Aucun coût de conversion.

## Hash HMAC-SHA256 du PIN

Le PIN n'est pas stocké en clair mais haché avec HMAC-SHA256. Mélanger une clé secrète côté serveur (`$hmacSecret`) rend le brute force difficile en cas de fuite DB :

```php
private function hashPin(string $pin): string
{
    return hash_hmac('sha256', $pin, $this->hmacSecret);
}
```

## Comparaison à temps constant (VULN-E / ATK-02)

`===` court-circuite pendant la comparaison d'octets, permettant de deviner le hash correct par attaque de timing. `hash_equals()` compare toujours tous les octets :

```php
// ❌ Dangereux : devinable par attaque de timing
if ($stored === $provided) { ... }

// ✅ Sûr : comparaison à temps constant
$provided = $this->hashPin($pin);
$success  = hash_equals($pin1->pinHash, $provided);
```

## Prévention du brute force (ATK-01)

Quand le nombre d'échecs atteint `max_attempts`, définir `locked_until` et rejeter toutes les tentatives suivantes (y compris le PIN correct) avec 423 :

```php
public function verify(int $id, string $pin): string
{
    $now  = $this->now();
    $pin1 = $this->findById($id);

    // 1. Vérifier le blocage avant la tentative
    if ($pin1->isLocked($now)) {
        return 'locked'; // → 423
    }

    // 2. Comparaison à temps constant
    $provided = $this->hashPin($pin);
    $success  = hash_equals($pin1->pinHash, $provided);

    if ($success) {
        // Réinitialiser le compteur de tentatives en cas de succès
        $this->resetAttempts($id, $now);
        return 'success'; // → 200
    }

    // 3. Échec : incrémenter → bloquer à la limite
    $newAttempts = $pin1->attempts + 1;
    $lockedUntil = null;

    if ($newAttempts >= $pin1->maxAttempts) {
        $lockedUntil = $this->lockUntil($now); // dans 5 minutes
    }

    $this->incrementAttempts($id, $newAttempts, $lockedUntil, $now);

    return $newAttempts >= $pin1->maxAttempts ? 'locked' : 'wrong'; // → 423 ou 401
}
```

**Important** : Vérifier le blocage avant la tentative. Si on vérifie après, la dernière tentative qui atteint l'état de blocage pourrait passer.

## Clé admin fail-closed (VULN-H / ATK-03)

```php
private function isAdmin(ServerRequestInterface $request): bool
{
    if ($this->adminKey === '') {
        return false; // clé adminKey vide → toujours refuser
    }

    $provided = $request->getHeaderLine('X-Admin-Key');

    return $provided !== '' && hash_equals($this->adminKey, $provided);
}
```

Si `adminKey` est une chaîne vide, retourner inconditionnellement `false` (empêche de devenir administrateur ouvert si la variable d'environnement n'est pas définie).

## Validation de l'ID (VULN-A / ATK-07)

```php
private function resolveId(ServerRequestInterface $request): ?int
{
    $raw = Router::param($request, 'id');

    if ($raw === null || !ctype_digit($raw) || strlen($raw) > 18) {
        return null; // → 422
    }

    $id = (int) $raw;

    return $id > 0 ? $id : null;
}
```

La garde `strlen($raw) > 18` prévient le dépassement d'entier 64 bits (`PHP_INT_MAX` a 19 chiffres mais avec marge de sécurité).

## Validation du PIN (VULN-D)

Utiliser `ctype_digit()`. Les expressions régulières (`/^[0-9]+$/`) peuvent causer des ReDoS en O(n²), mais `ctype_digit()` est O(n) et sûr :

```php
private function validatePin(mixed $pin): ?string
{
    if (!is_string($pin)) {
        return 'pin must be a string.'; // VULN-G : prévention de confusion de type
    }

    $len = strlen($pin);
    if ($len < self::MIN_PIN_LEN || $len > self::MAX_PIN_LEN) {
        return 'pin must be between 4 and 8 digits.';
    }

    if (!ctype_digit($pin)) { // O(n), pas de ReDoS
        return 'pin must contain only digits.';
    }

    return null;
}
```

## Conception de la réponse

**Ne jamais inclure le hash PIN dans la réponse.** Même pour les réponses admin :

```php
public function toAdminArray(): array
{
    return [
        'id'                 => $this->id,
        'label'              => $this->label,
        'attempts'           => $this->attempts,
        'max_attempts'       => $this->maxAttempts,
        'locked_until'       => $this->lockedUntil,
        'remaining_attempts' => $this->remainingAttempts(),
        'created_at'         => $this->createdAt,
        // pin_hash non inclus
        // updated_at non inclus (information interne)
    ];
}
```

## Exemples de réponse

```json
// POST /pins (201)
{
    "pin": {
        "id": 1,
        "label": "vault",
        "attempts": 0,
        "max_attempts": 5,
        "locked_until": null,
        "remaining_attempts": 5,
        "created_at": "2026-05-26T10:00:00+00:00"
    }
}

// POST /pins/1/verify — succès (200)
{ "success": true, "locked": false }

// POST /pins/1/verify — échec (401)
{ "success": false, "locked": false }

// POST /pins/1/verify — bloqué (423)
{ "success": false, "locked": true, "error": "PIN is locked due to too many failed attempts." }

// POST /pins/1/unlock (200)
{ "unlocked": true }
```

## Points de sécurité (VULN-A~L / ATK-01~12 tous Pass)

| Menace | Catégorie | Contre-mesure |
|--------|-----------|--------------|
| Brute force | ATK-01 | Limite `max_attempts` → blocage 5 min avec `locked_until` |
| Attaque de timing (PIN) | ATK-02 / VULN-E | Comparaison à temps constant `hash_equals()` |
| Contournement clé admin | ATK-03 / VULN-H | `adminKey = ''` → false (fail-closed) |
| Énumération d'IDs | ATK-04 | ID inexistant → 404 (pas de fuite d'information) |
| Injection SQL (valeur PIN) | ATK-05 / VULN-B | `ctype_digit` ne laisse passer que les chiffres → PDO prepared statement |
| Injection SQL (ID) | ATK-06 / VULN-B | Garde `ctype_digit + strlen > 18` → 422 |
| Dépassement d'entier | ATK-07 / VULN-A / VULN-J | Garde `strlen > 18` |
| Contournement du blocage | ATK-08 | Vérification du blocage avant la tentative, persistance DB |
| Re-attaque après déblocage | ATK-09 | Remise à 0 de `attempts` après déblocage (comportement normal) |
| Injection de corps | ATK-10 / VULN-I | N'accepter que les champs explicites |
| Timing de la clé admin | ATK-11 | Comparaison à temps constant `hash_equals()` |
| Labels BIDI/Unicode | ATK-12 / VULN-L | Vérification de longueur avec `mb_strlen`, stockage sûr avec PDO |
| ReDoS | VULN-D | `ctype_digit()` O(n), pas de regex |
| Confusion de type | VULN-G | Vérification `!is_string($pin)` |
| Dépassement de max_attempts | VULN-F | Vérification de plage 1~20 |
| SSRF | VULN-K | Pas de communication HTTP externe (N/A) |
| Path traversal | VULN-C | Pas d'opérations sur fichiers (N/A) |

## Guides associés

- [Blocage de compte](account-lockout.md) — comptage d'échecs par compte, conception 423
- [Système d'authentification OTP](otp-authentication.md) — pattern de blocage similaire (seul le dernier OTP est valide)
- [Vérification de signature Webhook](webhook-signature.md) — pattern `hash_equals()`
- [Code de vérification numérique](numeric-verification-code.md) — flux de génération/vérification de code à 6 chiffres
