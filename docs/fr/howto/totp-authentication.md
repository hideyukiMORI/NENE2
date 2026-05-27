# Guide d'implémentation de l'authentification à deux facteurs TOTP

## Vue d'ensemble

Ce guide explique comment implémenter l'authentification à deux facteurs TOTP (Time-based One-Time Password) RFC 6238 avec NENE2.
Il couvre la génération de secrets compatibles Google Authenticator et Authy, la vérification de code, la prévention des attaques par rejeu et le verrouillage en cas de force brute.

---

## Schéma DB

```sql
CREATE TABLE totp_secrets (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id         INTEGER NOT NULL UNIQUE,
    secret          TEXT    NOT NULL,
    is_enabled      INTEGER NOT NULL DEFAULT 0,
    failed_attempts INTEGER NOT NULL DEFAULT 0,
    locked_until    TEXT,
    created_at      TEXT    NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

CREATE TABLE used_totp_steps (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    time_step  INTEGER NOT NULL,
    used_at    TEXT    NOT NULL,
    UNIQUE (user_id, time_step),
    FOREIGN KEY (user_id) REFERENCES users(id)
);
```

La table `used_totp_steps` est le cœur de la **prévention des attaques par rejeu**. Elle enregistre les étapes temporelles déjà utilisées.

---

## Conception des endpoints

| Méthode | Chemin | Description |
|---------|--------|-------------|
| POST | `/users/{id}/totp/setup` | Générer un secret TOTP (à enregistrer dans l'application après retour) |
| POST | `/users/{id}/totp/enable` | Vérifier le code et activer la 2FA |
| POST | `/users/{id}/totp/verify` | Vérifier le code (flux de connexion) |
| DELETE | `/users/{id}/totp` | Désactiver la 2FA (code valide requis) |
| GET | `/users/{id}/totp` | Obtenir le statut de la 2FA |

---

## Implémentation de TOTP RFC 6238

```php
class TotpGenerator
{
    private const int DIGITS = 6;
    private const int PERIOD = 30; // secondes

    public function computeCode(string $base32Secret, int $timeStep): string
    {
        $secret = $this->base32Decode($base32Secret);

        // Empaqueter l'étape temporelle en big-endian 8 octets
        $msg = pack('N*', 0) . pack('N*', $timeStep);
        $hash = hash_hmac('sha1', $msg, $secret, true);

        // Troncature dynamique (RFC 4226 §5.4)
        $offset = ord($hash[19]) & 0x0F;
        $code = ((ord($hash[$offset]) & 0x7F) << 24)
              | ((ord($hash[$offset + 1]) & 0xFF) << 16)
              | ((ord($hash[$offset + 2]) & 0xFF) << 8)
              | (ord($hash[$offset + 3]) & 0xFF);

        return str_pad((string) ($code % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    public function verify(string $base32Secret, string $code, int $window = 1): ?int
    {
        $t = (int) floor(time() / self::PERIOD);
        for ($offset = -$window; $offset <= $window; $offset++) {
            $step = $t + $offset;
            $expected = $this->computeCode($base32Secret, $step);
            if (hash_equals($expected, $code)) {   // prévention des attaques de timing
                return $step;
            }
        }
        return null;
    }
}
```

---

## Points clés de conception

### Prévention des attaques par rejeu

Un code TOTP est valide pendant 30 secondes. Si le même code est utilisé deux fois, une usurpation d'identité est possible.
La table `used_totp_steps` enregistre les time_steps déjà utilisés et refuse leur réutilisation.

```php
$matchedStep = $this->totp->verify($secret, $code);
if ($matchedStep === null) {
    // Code invalide
    return 401;
}
if ($this->repo->isStepUsed($userId, $matchedStep)) {
    // Le code de ce time_step a déjà été utilisé → attaque par rejeu
    return 401;
}
// Enregistrer comme utilisé
$this->repo->markStepUsed($userId, $matchedStep, $now);
```

### Prévention des attaques de timing

Utiliser `hash_equals()` pour comparer les codes TOTP. `===` et `strcmp()` terminent la comparaison de chaîne prématurément, ce qui permet de deviner le nombre de caractères correspondants à partir du temps de réponse.

```php
// Incorrect : vulnérable aux attaques de timing
if ($expected === $inputCode) { ... }

// Correct : comparaison en temps constant
if (hash_equals($expected, $inputCode)) { ... }
```

### Largeur de fenêtre (tolérance de décalage horaire)

`window = 1` autorise l'étape courante ± 1 (= ±30 secondes).
Le décalage horaire des smartphones entre presque toujours dans cette plage.
Augmenter la fenêtre réduit la sécurité, donc 1 est recommandé.

### Verrouillage en cas de force brute

3 échecs entraînent un verrouillage de 15 minutes (423 Locked).
Pendant le verrouillage, même un code correct est refusé (prévention d'oracle de timing) :

```php
if ($this->repo->isLocked($userId, $now)) {
    return 423; // Verrouillé — ne pas vérifier si le code est correct
}
```

### Flux de configuration

1. `POST /users/{id}/totp/setup` génère le secret
2. Enregistrer le `secret` (Base32) ou l'`otpauth_uri` de la réponse dans l'application Authenticator
3. `POST /users/{id}/totp/enable` vérifie le premier code et active la 2FA
4. Avant l'activation, le secret est stocké en DB mais avec `is_enabled = false`

```
otpauth://totp/NENE2:alice?secret=JBSWY3DPEHPK3PXP&issuer=NENE2&algorithm=SHA1&digits=6&period=30
```

### Invalidation de l'ancien secret lors de la reconfiguration

Appeler à nouveau `POST /users/{id}/totp/setup` écrase l'ancien secret et
supprime également les `used_totp_steps`. Les codes de l'ancien secret ne peuvent plus authentifier.

---

## Liste de vérification de sécurité (12 contrôles de vulnérabilité, tous PASS)

| # | Élément à vérifier | Mesure |
|---|---|---|
| A | Attaque par rejeu | Enregistrer les time_steps utilisés dans `used_totp_steps` |
| B | Force brute | Verrouillage de 15 minutes après 3 échecs (423) |
| C | Code valide pendant le verrouillage | Vérifier d'abord le verrouillage, ne pas vérifier le code du tout |
| D | Désactivation 2FA non autorisée | DELETE exige également un code valide |
| E | Activation 2FA non autorisée | La vérification du code est obligatoire pour l'activation |
| F | Exploitation de l'ancien secret | La reconfiguration supprime l'ancien secret et les étapes utilisées |
| G | IDOR | Les codes sont vérifiés avec un secret indépendant par utilisateur |
| H | Exposition du secret | Ne pas inclure le secret dans les réponses verify/enable |
| I | Code au format invalide | Non-correspondance → 401 (la validation du format est optionnelle) |
| J | Code vide | Validation required → 422 |
| K | Verify sans activation | Vérification `is_enabled` → 409 |
| L | Utilisateur inexistant | `findUser()` → null → 404 |

---

## Notes sur les tests

Les codes TOTP dépendent de l'heure, donc utiliser le même code consécutivement est traité comme un rejeu.
Dans les tests, utiliser `TotpGenerator::computeCode($secret, $gen->currentTimeStep() + N)` pour générer des codes avec des étapes différentes :

```php
$enableCode  = $gen->computeCode($secret, $gen->currentTimeStep());     // utilisé pour enable
$verifyCode  = $gen->computeCode($secret, $gen->currentTimeStep() + 1); // utilisé pour verify
$disableCode = $gen->computeCode($secret, $gen->currentTimeStep() + 2); // utilisé pour disable
```

---

## Implémentation de référence

`../NENE2-FT/totplog/` — FT159 field trial (21 tests + 12 contrôles de vulnérabilité = 32 tests)
