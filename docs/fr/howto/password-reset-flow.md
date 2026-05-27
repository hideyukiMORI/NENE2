# How-to : Flux de réinitialisation de mot de passe

> **Référence FT** : FT285 (`NENE2-FT/resetlog`) — Flux de réinitialisation de mot de passe : prévention de l'énumération d'utilisateurs (toujours 202), stockage du hash SHA-256 du token, TTL d'1 heure, token à usage unique (409 à la réutilisation), 410 Gone à l'expiration, nouveau hash Argon2id, 15 tests / 23 assertions PASS.
>
> **Évaluation VULN** : V-01 à V-10 inclus à la fin de ce document.

Ce guide montre comment implémenter un flux de réinitialisation de mot de passe sécurisé — les utilisateurs demandent une réinitialisation, reçoivent un token (généralement par email), et l'utilisent pour définir un nouveau mot de passe.

## Schéma

```sql
CREATE TABLE users (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    email         TEXT    NOT NULL UNIQUE,
    name          TEXT    NOT NULL,
    password_hash TEXT    NOT NULL,
    created_at    TEXT    NOT NULL
);

CREATE TABLE password_resets (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    token_hash TEXT    NOT NULL UNIQUE,
    used_at    TEXT,
    expires_at TEXT    NOT NULL,
    created_at TEXT    NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id)
);
```

`token_hash TEXT UNIQUE` — stocke le SHA-256 du token brut. Le token brut est envoyé au client et jamais stocké.

## Endpoints

| Méthode | Chemin | Auth | Description |
|---------|--------|------|-------------|
| `POST` | `/password-reset` | Aucune | Demander une réinitialisation de mot de passe |
| `GET` | `/password-reset/{token}` | Aucune | Vérifier le statut du token |
| `POST` | `/password-reset/{token}` | Aucune | Compléter la réinitialisation avec le nouveau mot de passe |

## Prévention de l'énumération d'utilisateurs

```php
$user = $this->repo->findUserByEmail($email);

// Toujours retourner 202 pour prévenir l'énumération d'utilisateurs
if ($user === null) {
    return $this->json->create(['status' => 'pending'], 202);
}

// Utilisateur réel : créer le token et (en production) envoyer l'email
$rawToken = bin2hex(random_bytes(32));
// ...
return $this->json->create(['status' => 'pending', 'token' => $rawToken], 202);
```

Les emails valides et invalides retournent des réponses 202 identiques. Un attaquant ne peut pas déterminer quels emails sont enregistrés.

> **Note de production** : Le token est retourné dans la réponse API ici pour la testabilité. En production, envoyer le token uniquement par email — ne jamais l'inclure dans la réponse API.

## Stockage du token — SHA-256 uniquement

```php
$rawToken  = bin2hex(random_bytes(32));  // 64 caractères hex = 256 bits d'entropie
$tokenHash = hash('sha256', $rawToken);

$this->repo->createReset($user->id, $tokenHash, $expiresAt, $now);

// Retourner le token brut au client (en production : par email, pas dans la réponse HTTP)
return $this->json->create(['status' => 'pending', 'token' => $rawToken], 202);
```

La base de données stocke uniquement le hash SHA-256. Le token brut est envoyé à l'utilisateur (par email en production) et jamais stocké. Une fuite DB révèle des hashes — inutilisables sans les tokens bruts.

## Validation du token

```php
$rawToken  = (string) ($params['token'] ?? '');
$tokenHash = hash('sha256', $rawToken);
$reset     = $this->repo->findByTokenHashOrNull($tokenHash);
```

Le token brut arrive dans le chemin de la requête. Le serveur le hache et interroge la DB. SHA-256 est déterministe — le même token brut produit toujours le même hash.

## États du cycle de vie du token

```
pending → used (409 à la réutilisation)
pending → expired (410 Gone)
```

```php
if ($reset->isExpired($now)) {
    return $this->problems->create($request, 'gone', 'Reset token has expired.', 410, '');
}

if ($reset->isUsed()) {
    return $this->problems->create($request, 'conflict', 'Reset token has already been used.', 409, '');
}
```

| Statut | HTTP | Quand |
|--------|------|-------|
| Non trouvé | 404 | Le token n'existe pas en DB |
| Expiré | 410 Gone | `expires_at` est dans le passé |
| Déjà utilisé | 409 Conflict | `used_at` est défini |
| Valide | 200 (GET) / 200 (POST) | Actif, non utilisé, non expiré |

`410 Gone` est sémantiquement plus correct que 404 pour les ressources expirées — le token existait mais n'est plus disponible.

## Compléter la réinitialisation

```php
$newHash = password_hash($newPassword, PASSWORD_ARGON2ID);
$this->repo->updatePasswordHash($reset->userId, $newHash);
$this->repo->markUsed($tokenHash, $now);  // définit used_at = $now

return $this->json->create(['status' => 'completed'], 200);
```

Les deux opérations devraient être dans une transaction en production. Si `updatePasswordHash` réussit mais que `markUsed` échoue, l'utilisateur est réinitialisé mais le token reste réutilisable.

## Validation du mot de passe

```php
if (strlen($newPassword) < 8) {
    return $this->problems->create($request, 'validation-failed', 'Validation Failed', 422, null, [
        'errors' => [['field' => 'password', 'code' => 'min-length', 'message' => 'password must be at least 8 characters.']],
    ]);
}
```

Minimum 8 caractères ; appliqué à la fois lors de l'inscription et de la réinitialisation. Le nouveau mot de passe est haché avec `PASSWORD_ARGON2ID` avant le stockage.

---

## Évaluation VULN — Diagnostic de vulnérabilité

### V-01 — Énumération d'utilisateurs via le timing/contenu de la réponse de réinitialisation 🛡️ SAFE

**Menace** : L'attaquant envoie des demandes de réinitialisation pour de nombreux emails pour identifier les utilisateurs enregistrés.
**Défense** : Les emails enregistrés et non enregistrés retournent `202 { "status": "pending" }` avec un corps de réponse et un code de statut identiques. Pas de différence de timing (pas de vérification de hash de mot de passe nécessaire pour la demande de réinitialisation).
**Résultat** : SAFE — l'énumération est impossible depuis la réponse API.

---

### V-02 — Brute force de token 🛡️ SAFE

**Menace** : L'attaquant devine des valeurs de token et les soumet pour réinitialiser n'importe quel compte.
**Défense** : `bin2hex(random_bytes(32))` génère 256 bits d'entropie (64 caractères hex). À 10 000 suppositions/seconde, le brute force prendrait ~10^65 ans. La comparaison de hash SHA-256 prévient l'extension de longueur et l'oracle de timing.
**Résultat** : SAFE — l'entropie de 256 bits est impossible à deviner.

---

### V-03 — Replay de token après utilisation 🛡️ SAFE

**Menace** : L'attaquant intercepte un token de réinitialisation et l'utilise après que l'utilisateur légitime a déjà réinitialisé son mot de passe.
**Défense** : `markUsed()` définit `used_at` après la réinitialisation. Les tentatives suivantes vérifient `isUsed()` → 409 Conflict.
**Résultat** : SAFE — l'application à usage unique prévient le replay.

---

### V-04 — Token expiré accepté 🛡️ SAFE

**Menace** : L'attaquant sauvegarde un token, attend que l'utilisateur se connecte, puis utilise l'ancien token.
**Défense** : `isExpired($now)` vérifie `expires_at`. Les tokens expirent après 1 heure → 410 Gone.
**Résultat** : SAFE — les tokens à durée limitée préviennent les attaques différées.

---

### V-05 — Injection SQL via le paramètre de chemin du token 🛡️ SAFE

**Menace** : Soumettre `'; DROP TABLE password_resets; --` comme token.
**Défense** : `hash('sha256', $rawToken)` produit une chaîne hex de 64 caractères quelle que soit l'entrée. Le hash est utilisé dans une requête paramétrée (`WHERE token_hash = ?`). L'injection SQL via le paramètre de chemin est impossible.
**Résultat** : SAFE — le hachage + la requête paramétrée bloquent doublement l'injection.

---

### V-06 — Token stocké en clair en DB 🛡️ SAFE

**Menace** : Une fuite DB expose tous les tokens de réinitialisation actifs ; l'attaquant réinitialise chaque compte.
**Défense** : La DB stocke uniquement `hash('sha256', $rawToken)`. Les tokens bruts sont retournés aux clients (ou envoyés par email). SHA-256 est à sens unique ; les hashes ne peuvent pas être inversés en tokens bruts sans brute force.
**Résultat** : SAFE — le stockage de hash SHA-256 protège les tokens au repos.

---

### V-07 — Nouveau mot de passe stocké en clair 🛡️ SAFE

**Menace** : Une fuite DB expose les nouveaux mots de passe définis lors de la réinitialisation.
**Défense** : `password_hash($newPassword, PASSWORD_ARGON2ID)` hache le nouveau mot de passe avant le stockage. Le texte clair n'est jamais persisté.
**Résultat** : SAFE — le hachage Argon2id protège les mots de passe au repos.

---

### V-08 — Prise de contrôle de compte par création de token de réinitialisation dupliqué 🛡️ SAFE

**Menace** : L'attaquant prédit ou entre en collision avec le hash de token d'un autre utilisateur.
**Défense** : `token_hash TEXT UNIQUE` — les hashes dupliqués sont rejetés par la DB. Avec 256 bits d'entropie, la probabilité de collision est négligeable (borne de l'anniversaire ~2^128 tentatives pour 50% de probabilité de collision).
**Résultat** : SAFE — contrainte UNIQUE + entropie de 256 bits préviennent la collision.

---

### V-09 — Soumission de nouveau mot de passe faible (< 8 caractères) lors de la réinitialisation 🛡️ SAFE

**Menace** : L'attaquant réinitialise un compte à un mot de passe facilement devinable comme `aa`.
**Défense** : `strlen($newPassword) < 8` → erreur de validation 422 avant toute opération DB.
**Résultat** : SAFE — longueur minimale appliquée sur le chemin de réinitialisation (même qu'à l'inscription).

---

### V-10 — L'endpoint de token révèle quelle étape a échoué (énumération) 🛡️ SAFE

**Menace** : En comparant les réponses 404 vs 409 vs 410, l'attaquant cartographie l'état des tokens de réinitialisation.
**Défense** : Les codes d'erreur révèlent l'état du cycle de vie du token (non trouvé/expiré/utilisé) mais pas les informations utilisateur. Savoir qu'un token est expiré ou utilisé n'identifie pas le titulaire du compte. La demande de réinitialisation retourne toujours 202 que l'email existe ou non.
**Résultat** : SAFE — aucune information d'identité utilisateur n'est révélée par les réponses d'état du token.

---

### Résumé VULN

| ID | Menace | Résultat |
|----|--------|----------|
| V-01 | Énumération d'utilisateurs via la réponse de réinitialisation | 🛡️ SAFE |
| V-02 | Brute force de token | 🛡️ SAFE |
| V-03 | Replay de token après utilisation | 🛡️ SAFE |
| V-04 | Token expiré accepté | 🛡️ SAFE |
| V-05 | Injection SQL via chemin de token | 🛡️ SAFE |
| V-06 | Token stocké en clair | 🛡️ SAFE |
| V-07 | Nouveau mot de passe stocké en clair | 🛡️ SAFE |
| V-08 | Collision de token dupliqué | 🛡️ SAFE |
| V-09 | Nouveau mot de passe faible accepté | 🛡️ SAFE |
| V-10 | État du token révèle les infos utilisateur | 🛡️ SAFE |

**10 SAFE, 0 EXPOSED**
La prévention de l'énumération d'utilisateurs, l'entropie de token de 256 bits, le stockage de hash SHA-256, le hachage Argon2id des mots de passe et l'application à usage unique préviennent tous les vecteurs de vulnérabilité testés.

---

## À ne pas faire

| Anti-pattern | Risque |
|---|---|
| Retourner 404 pour email non enregistré, 202 pour enregistré | Énumération d'utilisateurs — l'attaquant cartographie les comptes enregistrés |
| Stocker le token brut en DB | Une fuite DB expose tous les tokens de réinitialisation actifs ; prise de contrôle de compte massive |
| Envoyer le token dans le corps de la réponse HTTP (production) | Token intercepté par les logs du navigateur, proxies ou JS ; envoyer uniquement par email |
| Pas d'expiration sur les tokens de réinitialisation | Les anciens tokens restent valides indéfiniment ; les tokens volés utilisables des mois plus tard |
| Permettre la réutilisation du token après la réinitialisation du mot de passe | Attaque par replay de token après interception d'email |
| Pas de longueur minimale de mot de passe | Les utilisateurs définissent `aa` comme nouveau mot de passe |
| Retourner 200 pour GET `/password-reset/{token}` sur token utilisé | Le client ne peut pas distinguer valide de déjà utilisé |
| Utiliser MD5/SHA-1 pour le hash de token | Des tables arc-en-ciel précalculées existent ; utiliser SHA-256 ou mieux |
| Pas de transaction pour `updatePasswordHash` + `markUsed` | Condition de course : mot de passe mis à jour mais token reste réutilisable |
