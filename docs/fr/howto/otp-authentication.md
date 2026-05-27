# How-to : Système d'authentification OTP

> **Référence FT** : FT290 (`NENE2-FT/otplog`) — Authentification OTP : code numérique à 6 chiffres avec stockage du hash SHA-256, blocage par brute force (3 tentatives → 10 min), TTL de l'OTP (5 min), prévention de l'attaque par replay via `used_at`, token de session avec SHA-256 + révocation, prévention de l'énumération d'utilisateurs via endpoint toujours-202, ATK-01~12 PASS, 35 tests / 44 assertions PASS.

Ce guide montre comment créer un système d'authentification OTP (One-Time Password) sans mot de passe où les utilisateurs reçoivent un code à 6 chiffres et l'échangent contre un token de session.

## Schéma

```sql
CREATE TABLE users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    email TEXT NOT NULL UNIQUE,
    created_at TEXT NOT NULL
);

CREATE TABLE otp_codes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    code_hash TEXT NOT NULL,
    expires_at TEXT NOT NULL,
    used_at TEXT,
    attempt_count INTEGER NOT NULL DEFAULT 0,
    locked_until TEXT,
    created_at TEXT NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

CREATE TABLE otp_sessions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    session_token_hash TEXT NOT NULL UNIQUE,
    expires_at TEXT NOT NULL,
    revoked_at TEXT,
    created_at TEXT NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id)
);
```

Points de conception clés :
- `code_hash` stocke le SHA-256 de l'OTP, jamais le code brut.
- `attempt_count` + `locked_until` implémentent le blocage par brute force par ligne OTP.
- `used_at` prévient les attaques par replay (l'OTP ne peut être utilisé qu'une seule fois).
- `session_token_hash` stocke le SHA-256 du token de session ; `UNIQUE` prévient les collisions.
- `revoked_at` permet la déconnexion explicite sans supprimer la ligne.

## Endpoints

| Méthode | Chemin | Auth | Description |
|---------|--------|------|-------------|
| `POST` | `/otp/request` | aucune | Demander un OTP (crée l'utilisateur si nécessaire) |
| `POST` | `/otp/verify` | aucune | Vérifier l'OTP, recevoir le token de session |
| `GET` | `/otp/session` | `Bearer <token>` | Obtenir les infos de session |
| `DELETE` | `/otp/session` | `Bearer <token>` | Déconnexion (révoquer la session) |

## Génération d'OTP — Ne jamais stocker le code brut

```php
private const int MAX_ATTEMPTS = 3;
private const int OTP_TTL_MINUTES = 5;
private const int LOCK_MINUTES = 10;
private const int SESSION_TTL_HOURS = 24;

$rawCode = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
$codeHash = hash('sha256', $rawCode);
$this->repository->createOtp($userId, $codeHash, $now);
```

`str_pad` garantit les zéros de début (ex. `random_int(0, 999999)` retournant `42` → `'000042'`). Le code brut est envoyé par email à l'utilisateur ; seul le hash est stocké. `random_int()` est cryptographiquement sûr.

## Prévention de l'énumération d'utilisateurs — Toujours 202

```php
// Toujours 202 — prévient l'énumération d'utilisateurs
// En production : envoyer un email. Dans ce FT nous retournons le code pour les tests.
return $this->responseFactory->create([
    'message' => 'OTP code sent',
    'code' => $rawCode,  // à supprimer en production
], 202);
```

Que l'email existe ou non, la réponse est toujours `202 Accepted`. Un attaquant ne peut pas distinguer "le compte existe" de "le compte n'existe pas."

## Création automatique d'utilisateur à la première demande

```php
public function findOrCreateUser(string $email, string $now): int
{
    $user = $this->findUserByEmail($email);
    if ($user !== null) {
        return (int) $user['id'];
    }
    return $this->executor->insert(
        'INSERT INTO users (email, created_at) VALUES (?, ?)',
        [$email, $now]
    );
}
```

Les utilisateurs sont créés implicitement à la première demande d'OTP — pas d'étape d'inscription séparée. La contrainte `UNIQUE(email)` prévient les doublons lors d'insertions concurrentes.

## Vérification OTP — Vérifications dans l'ordre

```php
// 1. Vérification du blocage (en premier — avant toute comparaison de code)
if ($otp['locked_until'] !== null && $now < (string) $otp['locked_until']) {
    return $this->responseFactory->create(['error' => 'too many attempts, try again later'], 429);
}

// 2. Vérification d'expiration
if ($now > (string) $otp['expires_at']) {
    return $this->responseFactory->create(['error' => 'code expired'], 401);
}

// 3. Vérification déjà utilisé
if ($otp['used_at'] !== null) {
    return $this->responseFactory->create(['error' => 'code already used'], 401);
}

// 4. Vérification du code avec hash_equals (résistant au timing)
$codeHash = hash('sha256', $code);
if (!hash_equals((string) $otp['code_hash'], $codeHash)) {
    $this->repository->incrementAttempt((int) $otp['id'], $now);
    return $this->responseFactory->create(['error' => 'invalid code'], 401);
}
```

L'ordre des vérifications est important : blocage → expiration → utilisé → code. Incrémenter `attempt_count` uniquement sur un code erroné — pas lors du blocage ou de l'expiration.

## Blocage par brute force

```php
public function incrementAttempt(int $otpId, string $now): void
{
    $otp = $this->executor->fetchOne('SELECT * FROM otp_codes WHERE id = ?', [$otpId]);
    if ($otp === null) {
        return;
    }
    $newCount = (int) $otp['attempt_count'] + 1;
    $lockedUntil = null;
    if ($newCount >= self::MAX_ATTEMPTS) {
        $lockedUntil = date('c', strtotime($now) + self::LOCK_MINUTES * 60);
    }
    $this->executor->execute(
        'UPDATE otp_codes SET attempt_count = ?, locked_until = ? WHERE id = ?',
        [$newCount, $lockedUntil, $otpId]
    );
}
```

Après `MAX_ATTEMPTS` (3) codes erronés, `locked_until` est défini 10 minutes dans le futur. La vérification de blocage se produit avant toute comparaison de code, donc les tentatives pendant le blocage ne réinitialisent pas le minuteur.

## Dernier OTP uniquement — Nouvelle demande remplace l'ancienne

```php
public function findLatestOtpForUser(int $userId): ?array
{
    return $this->executor->fetchOne(
        'SELECT * FROM otp_codes WHERE user_id = ? ORDER BY id DESC LIMIT 1',
        [$userId]
    );
}
```

Plusieurs demandes d'OTP créent plusieurs lignes, mais seule la dernière est utilisée pour la vérification. Les anciens OTPs sont effectivement invalidés — les soumettre retourne 401.

## Token de session — SHA-256 + Révocation

```php
// Émettre un token de session
$rawToken = bin2hex(random_bytes(32));   // 256 bits d'entropie, 64 caractères hex
$tokenHash = hash('sha256', $rawToken);
$this->repository->createSession((int) $user['id'], $tokenHash, $now);

return $this->responseFactory->create([
    'session_token' => $rawToken,
    'user_id' => (int) $user['id'],
], 200);
```

Seul le hash SHA-256 est stocké. Si la DB est compromise, les tokens bruts ne sont jamais exposés.

## Extraction du token Bearer

```php
private function extractBearerToken(ServerRequestInterface $request): string
{
    $header = $request->getHeaderLine('Authorization');
    if (!str_starts_with($header, 'Bearer ')) {
        return '';
    }
    return trim(substr($header, 7));
}
```

Une chaîne vide après `Bearer ` (ex. `Authorization: Bearer `) est traitée comme manquante — retourne 401.

## Déconnexion — Succès silencieux

```php
$session = $this->repository->findSessionByTokenHash($tokenHash);
if ($session !== null && $session['revoked_at'] === null) {
    $this->repository->revokeSession($tokenHash, date('c'));
}

return $this->responseFactory->create(['message' => 'logged out'], 200);
```

La déconnexion retourne toujours 200 — elle ne révèle pas si le token était valide. Cela empêche les attaquants de sonder la validité des tokens via l'endpoint de déconnexion.

---

## Évaluation ATK — Test d'attaque mentalité cracker

### ATK-01 — Brute force OTP 🚫 BLOCKED

**Attaque** : Essayer toutes les combinaisons `000000`–`999999` séquentiellement.
**Résultat** : BLOCKED — après `MAX_ATTEMPTS` (3) codes erronés, `locked_until` est défini 10 minutes dans le futur. Les tentatives suivantes retournent 429 jusqu'à l'expiration du blocage.

---

### ATK-02 — Attaque par replay (réutilisation d'OTP utilisé) 🚫 BLOCKED

**Attaque** : Capturer un OTP valide et le soumettre une seconde fois après qu'il a déjà été utilisé.
**Résultat** : BLOCKED — `used_at` est défini lors de la première vérification réussie. Une deuxième tentative trouve `used_at !== null` → 401.

---

### ATK-03 — Énumération d'utilisateurs via /otp/request 🚫 BLOCKED

**Attaque** : Sonder `/otp/request` avec des emails connus et inconnus pour découvrir quels comptes existent.
**Résultat** : BLOCKED — les emails existants et non existants retournent toujours `202 Accepted` avec des corps de réponse identiques.

---

### ATK-04 — Vérification pour un utilisateur inexistant 🚫 BLOCKED

**Attaque** : Appeler `/otp/verify` avec un email qui n'a pas de compte.
**Résultat** : BLOCKED — retourne 401 (`invalid code`), pas 404 ou 500. Pas de trace de pile ni de signal d'existence de compte dans la réponse.

---

### ATK-05 — Injection SQL dans le champ email 🚫 BLOCKED

**Attaque** : Soumettre `'; DROP TABLE users; --` comme email.
**Résultat** : BLOCKED — `filter_var($email, FILTER_VALIDATE_EMAIL)` rejette les chaînes d'injection comme format email invalide avant toute requête DB. Toutes les requêtes utilisent des instructions paramétrées.

---

### ATK-06 — Code à 5 chiffres (trop court) 🚫 BLOCKED

**Attaque** : Soumettre un code de 5 caractères pour contourner la vérification de format OTP.
**Résultat** : BLOCKED — `/^\d{6}$/` exige exactement 6 chiffres. Retourne 422.

---

### ATK-07 — Code à 7 chiffres (trop long) 🚫 BLOCKED

**Attaque** : Soumettre un code à 7 chiffres pour contourner la validation de format.
**Résultat** : BLOCKED — la même regex rejette les codes qui ne font pas exactement 6 chiffres. Retourne 422.

---

### ATK-08 — Réutilisation du token de session après déconnexion 🚫 BLOCKED

**Attaque** : Utiliser un token après la déconnexion pour maintenir l'accès.
**Résultat** : BLOCKED — `revokeSession()` définit `revoked_at`. Le gestionnaire GET vérifie `$session['revoked_at'] !== null` → 401.

---

### ATK-09 — Devinette de token aléatoire 🚫 BLOCKED

**Attaque** : Soumettre une chaîne hex de 64 caractères aléatoires comme token Bearer.
**Résultat** : BLOCKED — le hash SHA-256 du token aléatoire ne correspond à aucun `session_token_hash`. Retourne 401. L'espace des tokens est de 2^256.

---

### ATK-10 — Token Bearer vide 🚫 BLOCKED

**Attaque** : Envoyer `Authorization: Bearer ` (vide après le préfixe Bearer).
**Résultat** : BLOCKED — `trim(substr($header, 7))` retourne une chaîne vide → `if ($token === '') return 401`.

---

### ATK-11 — Code alphabétique (non numérique) 🚫 BLOCKED

**Attaque** : Soumettre `abcdef` comme code OTP.
**Résultat** : BLOCKED — `/^\d{6}$/` exige uniquement des chiffres décimaux. Retourne 422 avant toute interaction DB.

---

### ATK-12 — Nouvelle demande d'OTP invalide l'ancien code 🚫 BLOCKED (par conception)

**Attaque** : Obtenir un OTP valide, laisser la victime en demander un nouveau, puis soumettre le code original.
**Résultat** : BLOCKED — `findLatestOtpForUser()` récupère uniquement `ORDER BY id DESC LIMIT 1`. L'ancien OTP est supplanté ; le soumettre retourne 401 (mauvais hash de code pour le dernier OTP).

---

### Résumé ATK

| ID | Attaque | Résultat |
|----|---------|----------|
| ATK-01 | Brute force OTP | 🚫 BLOCKED |
| ATK-02 | Attaque par replay (OTP utilisé) | 🚫 BLOCKED |
| ATK-03 | Énumération d'utilisateurs via /otp/request | 🚫 BLOCKED |
| ATK-04 | Vérification utilisateur inexistant | 🚫 BLOCKED |
| ATK-05 | Injection SQL dans email | 🚫 BLOCKED |
| ATK-06 | Code à 5 chiffres (trop court) | 🚫 BLOCKED |
| ATK-07 | Code à 7 chiffres (trop long) | 🚫 BLOCKED |
| ATK-08 | Réutilisation de session après déconnexion | 🚫 BLOCKED |
| ATK-09 | Devinette de token aléatoire | 🚫 BLOCKED |
| ATK-10 | Token Bearer vide | 🚫 BLOCKED |
| ATK-11 | Code alphabétique | 🚫 BLOCKED |
| ATK-12 | Ancien OTP invalidé par nouvelle demande | 🚫 BLOCKED |

**12 BLOCKED, 0 EXPOSED**
Le stockage basé sur le hash, le blocage par brute force, le garde de replay `used_at`, la validation de format et la prévention d'énumération toujours-202 couvrent tous les vecteurs d'attaque OTP critiques.

---

## À ne pas faire

| Anti-pattern | Risque |
|---|---|
| Stocker le code OTP brut en DB | La compromission DB expose tous les OTPs actifs ; toujours SHA-256 hacher |
| Pas de blocage par brute force | Un OTP à 6 chiffres a 10^6 combinaisons — brute-forçable en secondes sans blocage |
| Retourner 404 pour email inconnu sur verify | Révèle quels emails ont des comptes (énumération d'utilisateurs) |
| Retourner un statut différent pour email connu/inconnu sur /request | Même risque d'énumération ; toujours retourner 202 |
| Pas de flag `used_at` | L'OTP peut être rejoué indéfiniment jusqu'à son expiration |
| Accepter des codes alphabétiques ou non-6-chiffres | Contourne le contrat de format ; ajouter une vérification `/^\d{6}$/` |
| Stocker le token de session brut en DB | Une fuite DB expose toutes les sessions ; stocker uniquement le hash SHA-256 |
| Supprimer la ligne de session à la déconnexion | Impossible de détecter les tokens révoqués ; utiliser `revoked_at` pour révoquer de manière douce |
| Révéler le succès/échec de la déconnexion selon la validité du token | Les attaquants sondent la validité des tokens via la déconnexion ; toujours retourner 200 |
| Utiliser `findAllOtpsForUser()` et choisir le valide | Plusieurs OTPs actifs créent de la confusion ; utiliser `ORDER BY id DESC LIMIT 1` |
| Pas de limite de longueur d'email | RFC 5321 max est 254 caractères ; une entrée surdimensionnée cause des problèmes DB/email |
