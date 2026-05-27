# How-to : Code de vérification numérique

> **Pattern éprouvé par FT188 verifylog** — Code de vérification SMS/email à 6 chiffres avec protection contre le brute-force, comparaison à temps constant, et prévention du replay. ATK-01〜12 tous Pass.

---

## Ce que cela couvre

Un flux de vérification de contact (email ou téléphone) :

1. **Demander un code** — le serveur génère un code aléatoire à 6 chiffres, le livre hors bande
2. **Soumettre le code** — l'utilisateur soumet le code ; 3 tentatives max avant verrouillage
3. **Vérification du statut** — vérifier si une vérification a été complétée

Garanties de sécurité :

| Préoccupation | Technique |
|---|---|
| Brute force | 3 tentatives max → 429 Locked |
| Attaque temporelle | `hash_equals()` comparaison à temps constant |
| Replay du code | Code vérifié retourne 410 Gone |
| Énumération d'utilisateurs | `POST /verifications` retourne toujours 202 |
| Mass assignment | `code_hash/verified_at` définis côté serveur uniquement |
| Injection SQL | Paramètre de chemin entier uniquement (garde `ctype_digit` + `strlen > 18`) |
| Confusion de type | Vérification `is_string()` avant `ctype_digit()` |
| ReDoS | `ctype_digit()` O(n) — pas de regex |

---

## Schéma

```sql
CREATE TABLE verifications (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    contact        TEXT    NOT NULL,
    code_hash      TEXT    NOT NULL,   -- SHA-256 du code à 6 chiffres
    attempts_count INTEGER NOT NULL DEFAULT 0,
    max_attempts   INTEGER NOT NULL DEFAULT 3,
    verified_at    TEXT,               -- NULL = en attente
    expires_at     TEXT    NOT NULL,
    created_at     TEXT    NOT NULL
);
```

`code_hash` stocke `hash('sha256', $code)` — jamais le code en clair.

---

## API

| Méthode | Chemin | Description |
|---------|--------|-------------|
| `POST` | `/verifications` | Demander un code (toujours 202) |
| `POST` | `/verifications/{id}/check` | Soumettre le code (3 tentatives max) |
| `GET` | `/verifications/{id}` | Vérification du statut (aucun code révélé) |

---

## Pattern central : Génération de code et stockage par hash

```php
// Générer un code à 6 chiffres aléatoire cryptographiquement sûr
$plainCode = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
$codeHash  = hash('sha256', $plainCode);

// Stocker le hash — JAMAIS le code en clair
INSERT INTO verifications (contact, code_hash, expires_at, created_at)
VALUES (:contact, :code_hash, :expires_at, :now)

// Retourner plainCode à l'appelant (pour livraison) — ne jamais le stocker ni le journaliser
return ['verification' => $v, 'plainCode' => $plainCode];
```

`random_int(0, 999999)` utilise CSPRNG. `str_pad(..., 6, '0', STR_PAD_LEFT)` assure les zéros initiaux (ex: `000042`).

---

## Pattern central : Comparaison à temps constant

```php
// ATK-10: hash_equals prévient l'attaque temporelle
// $v->codeHash = SHA-256 stocké depuis la DB
// $submittedCode = entrée utilisateur (chaîne de 6 chiffres)
$valid = hash_equals($v->codeHash, hash('sha256', $submittedCode));
```

**Pourquoi pas `===` :** `===` court-circuite à la première discordance — un attaquant peut mesurer les différences de timing entre "premier byte erroné" et "tous les bytes erronés" pour affiner le code correct caractère par caractère. `hash_equals()` est à temps constant quelle que soit la discordance.

---

## Pattern central : Comptage des tentatives en premier

```php
public function check(int $id, string $submittedCode): string
{
    $v = $this->fetchById($id);

    if ($v === null)        return 'not_found';
    if ($v->isVerified())   return 'already';   // ATK-11: garde contre le replay
    if ($v->isLocked())     return 'locked';    // ATK-05: garde contre le brute force
    if ($v->isExpired())    return 'expired';

    // Incrémenter AVANT la vérification — prévient l'exploitation de condition de course
    UPDATE verifications SET attempts_count = attempts_count + 1 WHERE id = :id

    // ATK-10: comparaison à temps constant
    $valid = hash_equals($v->codeHash, hash('sha256', $submittedCode));

    if ($valid) {
        UPDATE verifications SET verified_at = :now WHERE id = :id
        return 'verified';
    }

    return 'wrong';
}
```

Incrémenter les tentatives **avant** la comparaison garantit qu'une course concurrente pour vérifier le même code ne peut pas contourner la limite.

---

## Pattern central : Prévention de l'énumération d'utilisateurs

```php
// POST /verifications — retourne TOUJOURS 202
// Même si le contact est invalide ou si la livraison échoue
private function handleRequest(ServerRequestInterface $request): ResponseInterface
{
    $contact = V::str($body['contact'] ?? null, self::MAX_CONTACT_LEN);

    if ($contact === null || $contact === '') {
        return $this->responseFactory->create(['error' => '...'], 422); // uniquement pour vide/null
    }

    // le succès ou l'échec de livraison est invisible pour l'appelant
    $this->repository->create($contact);

    return $this->responseFactory->create(['id' => $v->id, 'expires_in' => 600], 202);
}
```

Un 404 ou 422 pour un contact inconnu révèle "ce contact n'est pas enregistré." Toujours 202.

---

## Pattern central : Validation du type et format du code

```php
$raw = $body['code'] ?? null;

// ATK-07: confusion de type — le code doit être une chaîne
if (!is_string($raw)) {
    return $this->responseFactory->create(['error' => 'code must be a 6-digit string.'], 422);
}

// ATK-09: ReDoS — ctype_digit est O(n), pas une regex
// ATK-09: vérification de longueur exacte — pas "au moins 6"
if (!ctype_digit($raw) || strlen($raw) !== 6) {
    return $this->responseFactory->create(['error' => 'code must be exactly 6 digits.'], 422);
}
```

`is_string()` avant `ctype_digit()` rejette les entiers JSON, booléens et tableaux. `ctype_digit()` est sûr contre le ReDoS (temps linéaire).

---

## Design des réponses

| Scénario | Statut | Corps |
|----------|--------|-------|
| Code correct | 200 | `{verified: true}` |
| Code erroné, tentatives restantes | 422 | `{error: "Incorrect code.", attempts_left: N}` |
| Tentatives max atteintes | 429 | `{error: "Too many failed attempts. Request a new code."}` |
| Déjà vérifié (replay) | 410 | `{error: "This verification has already been completed."}` |
| Expiré | 410 | `{error: "Verification has expired. Request a new code."}` |
| Non trouvé | 404 | `{error: "Verification not found."}` |

---

## ATK-01〜12 tous Pass

| ATK | Attaque | Défense |
|-----|---------|---------|
| 01 | Injection SQL dans `{id}` | `ctype_digit()` + garde strlen > 18 |
| 02 | IDOR — check avec l'ID de vérification d'un autre | Même 404 — pas d'oracle de propriété |
| 03 | Mass assignment (code_hash/verified_at depuis body) | Défini côté serveur uniquement |
| 04 | XSS dans contact | Sortie JSON uniquement — pas de rendu HTML. Contact non retourné dans la réponse |
| 05 | Brute force du code à 6 chiffres | 3 échecs → 429 Locked |
| 06 | Contournement d'auth | `verified_at` défini par le serveur uniquement |
| 07 | Confusion de type (code comme int/bool/array) | `is_string()` + `ctype_digit()` |
| 08 | Dépassement d'entier dans `{id}` | Garde strlen > 18 |
| 09 | Entrée de code style ReDoS | `ctype_digit()` O(n) |
| 10 | Attaque temporelle sur la comparaison du code | `hash_equals()` temps constant |
| 11 | Replay du code après succès | 410 Gone |
| 12 | Injection CRLF dans les en-têtes | PSR-7 rejette au niveau HTTP |

---

## Résultats des tests (FT188)

```
48 tests / 103 assertions — tous PASS
PHPStan level 8 — pas d'erreurs
PHP CS Fixer — propre
ATK-01〜12 tous Pass
```

Source : [`../NENE2-FT/verifylog/`](https://github.com/hideyukiMORI/NENE2-examples/tree/main/verifylog)
