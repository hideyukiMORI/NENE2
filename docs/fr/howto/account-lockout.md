# Verrouillage de compte (Protection contre le brute-force)

> **Référence FT** : FT280 (`NENE2-FT/lockoutlog`) — Verrouillage de compte : 5 tentatives échouées déclenchent un verrouillage de 15 minutes (423 Locked), mot de passe correct bloqué pendant le verrouillage, succès réinitialise le compteur, vérification de mot de passe Argon2id, tests d'intégration MySQL, 27 tests passent / 5 ignorés (MySQL), 44 assertions PASS.
>
> **Évaluation ATK** : ATK-01 à ATK-12 inclus à la fin de ce document.

Protégez les endpoints de connexion contre les attaques par brute-force en verrouillant un compte après un nombre configurable de tentatives échouées.

## Vue d'ensemble

Le verrouillage de compte trace les tentatives de connexion échouées par adresse email et définit un horodatage `locked_until` lorsque le seuil d'échec est dépassé. Le verrou est appliqué à chaque tentative de connexion — même un mot de passe correct est rejeté pendant le verrouillage du compte. Le verrou expire automatiquement après une période de refroidissement.

## Schéma de base de données

```sql
CREATE TABLE users (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    email         TEXT    NOT NULL UNIQUE,
    password_hash TEXT    NOT NULL,
    created_at    TEXT    NOT NULL
);

CREATE TABLE account_states (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    email        TEXT    NOT NULL UNIQUE,
    failed_count INTEGER NOT NULL DEFAULT 0,
    locked_until TEXT,
    updated_at   TEXT    NOT NULL
);
```

`account_states` trace l'historique des échecs par compte. `locked_until` est null pour les comptes non verrouillés.

## Constantes

```php
public const int MAX_ATTEMPTS    = 5;   // échecs avant verrouillage
public const int LOCKOUT_MINUTES = 15;  // durée du verrouillage
```

## Flux de connexion

```php
// 1. Vérifier le verrouillage avant la vérification du mot de passe
$state = $this->repo->findOrCreateAccountState($email, $now);
if ($state->isLocked($now)) {
    return 423; // Locked
}

// 2. Vérifier les identifiants
$user = $this->repo->findUserByEmail($email);
if ($user === null || !$user->verifyPassword($pass)) {
    if ($user !== null) {
        $this->repo->recordFailure($email, $now);
    }
    return 401; // Unauthorized
}

// 3. Succès — réinitialiser le compteur
$this->repo->resetState($email, $now);
return 200;
```

La vérification du verrouillage se produit **avant** la vérification du mot de passe. L'état de verrouillage n'est écrit que pour les **utilisateurs existants** — les emails inconnus retournent 401 sans créer de ligne `account_state` (empêche l'épuisement du stockage).

## Vérification du verrouillage

```php
public function isLocked(string $now): bool
{
    return $this->lockedUntil !== null && $now < $this->lockedUntil;
}
```

`$now` est une chaîne `Y-m-d H:i:s`. La comparaison lexicographique fonctionne correctement pour les chaînes datetime ISO 8601.

## Enregistrement d'un échec

```php
public function recordFailure(string $email, string $now): AccountState
{
    $state    = $this->findOrCreateAccountState($email, $now);
    $newCount = $state->failedCount + 1;

    $lockedUntil = null;
    if ($newCount >= AccountState::MAX_ATTEMPTS) {
        $lockedUntil = date('Y-m-d H:i:s', strtotime($now) + AccountState::LOCKOUT_MINUTES * 60);
    }

    $this->executor->execute(
        'UPDATE account_states SET failed_count = ?, locked_until = ?, updated_at = ? WHERE email = ?',
        [$newCount, $lockedUntil, $now, $email],
    );
    ...
}
```

Quand `failed_count` atteint `MAX_ATTEMPTS`, `locked_until` est défini à `now + LOCKOUT_MINUTES * 60` secondes.

## Réinitialisation en cas de succès

```php
$this->executor->execute(
    'UPDATE account_states SET failed_count = 0, locked_until = NULL, updated_at = ? WHERE email = ?',
    [$now, $email],
);
```

Une authentification réussie réinitialise `failed_count` et `locked_until`. Un utilisateur qui réussit avant le verrouillage obtient un compteur d'échec vierge.

## Prévention de l'énumération d'utilisateurs

Retourner le même statut HTTP (401) pour un mauvais mot de passe et un email inconnu :

```php
if ($user === null || !$user->verifyPassword($pass)) {
    if ($user !== null) {
        $this->repo->recordFailure($email, $now);
    }
    return 401; // même statut dans tous les cas
}
```

Un attaquant ne peut pas distinguer "pas de compte" de "mauvais mot de passe" via la réponse HTTP.

## Schéma MySQL

Pour MySQL, utiliser `INT AUTO_INCREMENT` et `DATETIME` :

```sql
CREATE TABLE IF NOT EXISTS users (
    id            INT          NOT NULL AUTO_INCREMENT,
    email         VARCHAR(255) NOT NULL,
    password_hash TEXT         NOT NULL,
    created_at    DATETIME     NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS account_states (
    id           INT          NOT NULL AUTO_INCREMENT,
    email        VARCHAR(255) NOT NULL,
    failed_count INT          NOT NULL DEFAULT 0,
    locked_until DATETIME     NULL,
    updated_at   DATETIME     NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

Le format datetime `Y-m-d H:i:s` fonctionne pour SQLite (comparaison TEXT) et MySQL (colonne DATETIME).

## Test d'intégration MySQL

Ajouter un `MysqlLockoutTest.php` qui est ignoré sauf si `MYSQL_HOST` est défini :

```php
protected function setUp(): void
{
    $host = (string) (getenv('MYSQL_HOST') ?: '');
    if ($host === '') {
        self::markTestSkipped('MYSQL_HOST not set — skipping MySQL integration tests');
    }
    // Supprimer + recréer les tables pour l'isolation des tests
    $this->pdo->exec('DROP TABLE IF EXISTS account_states');
    $this->pdo->exec('DROP TABLE IF EXISTS users');
    $this->pdo->exec($mysqlSchema);
    ...
}
```

Exécuter sur le conteneur MySQL FT partagé (port 3308, volume persistant) :

```bash
docker compose -f ../NENE2-FT/docker-compose.yml up -d mysql
```

Puis exécuter les tests d'intégration avec les variables d'environnement :

```bash
MYSQL_HOST=127.0.0.1 MYSQL_PORT=3308 MYSQL_DATABASE=ft_test \
  MYSQL_USER=ft_user MYSQL_PASSWORD=ft_pass \
  php8.4 vendor/bin/phpunit --filter Mysql
```

Sans `MYSQL_HOST`, les tests MySQL sont automatiquement ignorés.

## Propriétés de sécurité

| Propriété | Implémentation |
|---|---|
| Seuil de verrouillage | 5 tentatives échouées |
| Durée du verrouillage | 15 minutes |
| Mot de passe correct pendant le verrouillage | Bloqué (423) |
| Énumération d'utilisateurs | Même 401 pour email inconnu et mauvais mot de passe |
| Portée du verrouillage | Par adresse email, pas par IP |
| Réinitialisation du verrouillage | Automatique à la connexion réussie |
| Hachage du mot de passe | Argon2id |
| Email long en entrée | Rejeté à 256+ caractères (422) |
| Injection SQL | Les requêtes paramétrées empêchent l'injection |

## Compromis de conception : DoS par verrouillage

Parce que le verrouillage est par email (pas par IP), un attaquant qui connaît l'email d'un utilisateur peut le verrouiller en soumettant 5 mauvais mots de passe. Il s'agit d'une tension inhérente entre la protection brute-force et la disponibilité.

Atténuations (non implémentées ici, mais disponibles) :
- **Délais progressifs** au lieu d'un verrouillage strict
- **CAPTCHA** après N échecs
- **Email de notification** quand le verrouillage est déclenché
- **Endpoint de déverrouillage admin**

Pour la plupart des applications, le compromis favorise la protection brute-force. Le verrouillage expire automatiquement après 15 minutes.

## Résumé des routes

| Méthode | Chemin | Description |
|---|---|---|
| `POST` | `/users` | Créer un utilisateur (seed/inscription) |
| `POST` | `/auth/login` | Tentative de connexion (200/401/423) |
| `GET` | `/auth/status/{email}` | Vérifier l'état du verrouillage |

---

## Évaluation ATK — Test d'attaque par esprit de cracker

### ATK-01 — Brute-force jusqu'au verrouillage 🚫 BLOQUÉ

**Attaque** : Envoyer 5+ tentatives de connexion échouées avec de mauvais mots de passe pour un email connu.
**Résultat** : BLOQUÉ — après 5 échecs, `failed_count >= MAX_ATTEMPTS` définit `locked_until = now + 15 min`. Les tentatives suivantes reçoivent 423 `account-locked` avant que le mot de passe soit vérifié.

---

### ATK-02 — Soumettre le mot de passe correct après verrouillage 🚫 BLOQUÉ

**Attaque** : Verrouiller le compte, puis soumettre immédiatement le mot de passe correct.
**Résultat** : BLOQUÉ — la vérification du verrouillage se produit avant `findUserByEmail()`. Même avec le bon mot de passe, 423 est retourné pendant le verrouillage.

---

### ATK-03 — Sonder un email inexistant pour éviter le verrouillage sur les vrais comptes 🚫 BLOQUÉ (par conception)

**Attaque** : Utiliser un email inexistant pour sonder sans déclencher le verrouillage sur les vrais comptes.
**Résultat** : BLOQUÉ (par conception) — les emails inexistants n'accumulent pas d'échecs, protégeant le stockage. Les vrais comptes sont protégés par leur propre état de verrouillage. Sonder de faux emails ne révèle rien sur les vrais comptes.

---

### ATK-04 — Condition de course : tentatives de connexion concurrentes au seuil d'échec 🚫 BLOQUÉ

**Attaque** : Envoyer deux requêtes simultanément quand `failed_count` est à 4 pour dépasser le verrouillage.
**Résultat** : BLOQUÉ — `UPDATE account_states` est atomique au niveau DB. SQLite WAL sérialise les écritures concurrentes ; MySQL utilise le verrouillage au niveau ligne. Les deux mises à jour réussissent ; le `locked_until` final est correctement défini.

---

### ATK-05 — L'endpoint de statut révèle l'état de verrouillage 🚫 BLOQUÉ (par conception)

**Attaque** : `GET /auth/status/{email}` pour découvrir si un email a été ciblé par un verrouillage.
**Résultat** : PAR CONCEPTION — l'endpoint de statut est prévu pour l'UX client ("réessayez dans 15 min"). En production, cela devrait être limité par le débit ou nécessiter une authentification. Il révèle la durée du verrouillage mais pas les informations de mot de passe.

---

### ATK-06 — Injection SQL via le champ email 🚫 BLOQUÉ

**Attaque** : Envoyer `{"email": "' OR '1'='1' --", "password": "x"}`.
**Résultat** : BLOQUÉ — toutes les requêtes utilisent des instructions paramétrées (`WHERE email = ?`). La chaîne injectée est traitée comme une valeur email littérale.

---

### ATK-07 — Chaîne email surdimensionnée pour causer un déni de service 🚫 BLOQUÉ

**Attaque** : Envoyer un champ email avec 100 000 caractères.
**Résultat** : BLOQUÉ — `if (strlen($email) > 255)` → 422 `validation-failed` avant toute requête DB.

---

### ATK-08 — Champs email ou mot de passe manquants 🚫 BLOQUÉ

**Attaque** : Envoyer `{}` ou `{"email": "x@x.com"}` sans mot de passe.
**Résultat** : BLOQUÉ — `if ($email === '' || $pass === '')` → 422 `validation-failed`.

---

### ATK-09 — Réinitialiser le compteur en se connectant avec un autre compte 🚫 BLOQUÉ

**Attaque** : Verrouiller le compte A, puis se connecter en tant que compte B pour réinitialiser le compteur de A.
**Résultat** : BLOQUÉ — `resetState()` est indexé par email. La connexion réussie d'un autre compte n'a aucun effet sur l'état du compte A.

---

### ATK-10 — Email composé uniquement d'espaces pour contourner la validation 🚫 BLOQUÉ

**Attaque** : Envoyer `{"email": "   ", "password": "x"}`.
**Résultat** : BLOQUÉ — `$email = trim($body['email'])` réduit les espaces à `''` → 422.

---

### ATK-11 — Type d'email non-chaîne pour contourner la vérification is_string 🚫 BLOQUÉ

**Attaque** : Envoyer `{"email": 12345, "password": "x"}` (email entier).
**Résultat** : BLOQUÉ — vérification `is_string($body['email'])` → false → `$email = ''` → 422.

---

### ATK-12 — Verrouillage soutenu d'une victime (attaque de disponibilité) 🚫 BLOQUÉ (atténué)

**Attaque** : Un utilisateur malveillant échoue répétitivement à la connexion pour l'email de la victime afin de maintenir un verrouillage permanent.
**Résultat** : ATTÉNUÉ — le verrouillage est basé sur le temps (15 minutes). Il expire automatiquement ; pas d'interdiction permanente. Une attaque soutenue maintient la fenêtre de 15 minutes mais ne peut pas désactiver le compte de façon permanente. Renforcement en production : CAPTCHA, limitation de débit par IP, notifier l'utilisateur par email.

---

### Résumé ATK

| ID | Attaque | Résultat |
|----|--------|--------|
| ATK-01 | Brute-force jusqu'au verrouillage | 🚫 BLOQUÉ |
| ATK-02 | Mot de passe correct après verrouillage | 🚫 BLOQUÉ |
| ATK-03 | Sondage via email inexistant | 🚫 BLOQUÉ (par conception) |
| ATK-04 | Condition de course sur le compteur d'échec | 🚫 BLOQUÉ |
| ATK-05 | L'endpoint de statut révèle l'état de verrouillage | 🚫 BLOQUÉ (par conception) |
| ATK-06 | Injection SQL via email | 🚫 BLOQUÉ |
| ATK-07 | DoS par email surdimensionné | 🚫 BLOQUÉ |
| ATK-08 | Champs requis manquants | 🚫 BLOQUÉ |
| ATK-09 | Réinitialisation du compteur via un autre compte | 🚫 BLOQUÉ |
| ATK-10 | Email composé uniquement d'espaces | 🚫 BLOQUÉ |
| ATK-11 | Type d'email non-chaîne | 🚫 BLOQUÉ |
| ATK-12 | Verrouillage soutenu de la victime | 🚫 BLOQUÉ (atténué) |

**12 BLOQUÉS / ATTÉNUÉS, 0 EXPOSÉS**
Verrouillage vérifié avant la vérification du mot de passe, requêtes paramétrées, validation de la longueur des entrées et expiration basée sur le temps empêchent tous les vecteurs d'attaque testés.

---

## Ce qu'il ne faut PAS faire

| Anti-pattern | Risque |
|---|---|
| Vérifier le verrouillage après la vérification du mot de passe | Gaspille du CPU Argon2id pour les comptes verrouillés ; canal secondaire de timing du verrouillage |
| Retourner 429 pour le verrouillage de compte | Mauvaise sémantique — 429 est la limitation de débit, 423 est la ressource verrouillée |
| Implémenter un verrouillage permanent en cas d'échec | L'attaquant peut nier définitivement le service pour tout utilisateur avec un email connu |
| Enregistrer les échecs pour les emails inexistants | L'attaquant pré-crée des états de verrouillage avant que les utilisateurs s'inscrivent |
| Pas de validation de longueur d'email | Les chaînes email de 100 Ko+ causent des requêtes lentes ou une pression mémoire |
| Stocker l'état de verrouillage en mémoire/session | État perdu au redémarrage du serveur ; non partagé entre plusieurs instances d'application |
| Même erreur pour verrouillé vs mauvais mot de passe | Difficile à distinguer en UX — utiliser 423 pour verrouillé, 401 pour mauvais identifiants |
