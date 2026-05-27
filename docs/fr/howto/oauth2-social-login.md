# How-to : Connexion sociale OAuth2

## Vue d'ensemble

Ce guide explique comment implémenter une connexion sociale via le flux Authorization Code OAuth2 avec NENE2. Inclut la prévention CSRF (paramètre state), la prévention du replay de code, la révocation de session et un test d'attaque cracker (ATK-01〜12).

---

## Schéma DB

```sql
CREATE TABLE users (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    provider   TEXT    NOT NULL,
    subject    TEXT    NOT NULL,  -- identifiant utilisateur émis par le fournisseur OAuth
    name       TEXT    NOT NULL,
    email      TEXT,
    created_at TEXT    NOT NULL,
    UNIQUE (provider, subject)
);

CREATE TABLE oauth_states (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    state      TEXT    NOT NULL UNIQUE,
    created_at TEXT    NOT NULL,
    expires_at TEXT    NOT NULL,
    used_at    TEXT    -- NULL = non utilisé, NOT NULL = utilisé (non réutilisable)
);

CREATE TABLE sessions (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    token      TEXT    NOT NULL UNIQUE,
    created_at TEXT    NOT NULL,
    expires_at TEXT    NOT NULL,
    revoked_at TEXT,   -- NULL = valide, NOT NULL = déconnecté
    FOREIGN KEY (user_id) REFERENCES users(id)
);

CREATE TABLE used_oauth_codes (
    id       INTEGER PRIMARY KEY AUTOINCREMENT,
    code     TEXT    NOT NULL UNIQUE,
    used_at  TEXT    NOT NULL
);
```

`oauth_states.used_at` et `used_oauth_codes` sont le cœur de la **prévention CSRF et du replay de code**.

---

## Design des endpoints

| Méthode | Chemin | Description |
|---------|--------|-------------|
| POST | `/auth/oauth/start` | Génération de state, retour de l'URL d'autorisation |
| POST | `/auth/oauth/callback` | Vérification state/code, création utilisateur, émission de session |
| POST | `/auth/logout` | Révocation de session |
| GET | `/me` | Obtenir les informations de l'utilisateur authentifié |

---

## Flux Authorization Code

```
Client                 Serveur                  Fournisseur OAuth
  |                      |                            |
  |-- POST /start -----→ |                            |
  |← {state, auth_url} --|                            |
  |                      |                            |
  |-- L'utilisateur accède à auth_url →→→→→→→→→→→→→→|
  |←←←←←←←←←←←←←←←←←←← redirect avec ?code=XXX&state=YYY |
  |                      |                            |
  |-- POST /callback ──→ |                            |
  |   {state, code}      |-- échange de code →→→→→→→ |
  |                      |← {subject, name, email} ---|
  |← {token, user} -----.|                            |
  |                      |                            |
  |-- GET /me ─────────→ |                            |
  |   Authorization: Bearer <token>                   |
  |← {id, name, email} - |                            |
```

---

## Points clés du design

### Prévention CSRF (paramètre state)

Le callback OAuth2 arrive via les paramètres URL, permettant à un attaquant de rediriger une victime vers une URL de callback malveillante (CSRF). Prévention avec `state` :

1. Générer un state aléatoire dans `/auth/oauth/start` et le stocker en DB
2. Vérifier le state dans le callback
3. **Rendre le state utilisé non réutilisable** (enregistrer `used_at`)

```php
if (!$this->repo->isStateValid($state, $now)) {
    return $this->json->create(['error' => 'Invalid, expired, or already used state'], 400);
}
```

### Prévention du replay de code

L'Authorization Code n'est utilisable qu'une seule fois (RFC 6749 §4.1.2). Enregistrer les codes utilisés dans `used_oauth_codes` et refuser la réutilisation :

```php
if ($this->repo->isCodeUsed($code)) {
    return $this->json->create(['error' => 'Authorization code already used'], 400);
}
// ... vérification du fournisseur ...
$this->repo->markCodeUsed($code, $now);
```

### Ordre de consommation state et code

Vérification state → vérification code → **interroger le fournisseur → marquer state et code comme utilisés simultanément**. Si le fournisseur échoue, ni le state ni le code ne sont consommés (possibilité de réessayer).

### Authentification par token Bearer

```php
private function bearerToken(ServerRequestInterface $request): ?string
{
    $header = $request->getHeaderLine('Authorization');
    if (!str_starts_with($header, 'Bearer ')) {
        return null;
    }
    return substr($header, 7) ?: null;
}
```

### Upsert utilisateur

Si le même subject du même fournisseur se reconnecte, mettre à jour l'utilisateur existant :

```php
public function upsertUser(array $info, string $now): int
{
    $row = $this->db->fetchOne(
        'SELECT id FROM users WHERE provider = ? AND subject = ?',
        [$info['provider'], $info['subject']],
    );
    if ($row !== null) {
        // Mettre à jour le nom et l'email à la dernière valeur
        $this->db->insert('UPDATE users SET name = ?, email = ? WHERE id = ?', [...]);
        return (int) $row['id'];
    }
    return $this->db->insert('INSERT INTO users ...', [...]);
}
```

### Expiration du state

Le state est valide pendant 5 minutes. Les states expirés sont rejetés par la vérification `expires_at > $now` :

```php
public function isStateValid(string $state, string $now): bool
{
    $row = $this->findState($state);
    if ($row === null || $row['used_at'] !== null) return false;
    return (string) $row['expires_at'] > $now;
}
```

---

## Test d'attaque cracker ATK-01〜12 (tous Pass)

| # | Scénario d'attaque | Contre-mesure | Statut attendu |
|---|---|---|---|
| ATK-01 | CSRF : paramètre state manquant | Validation required | 422 |
| ATK-02 | CSRF : valeur state falsifiée | Vérification DB → state inconnu rejeté | 400 |
| ATK-03 | Réutilisation d'un state utilisé | Après enregistrement `used_at`, non réutilisable | 400 |
| ATK-04 | Réutilisation d'un state légitime intercepté | Expiration immédiate après 1 utilisation | 400 |
| ATK-05 | Replay du code d'autorisation | Enregistrement dans `used_oauth_codes` | 400 |
| ATK-06 | Code d'autorisation invalide | Le mock fournisseur retourne null | 401 |
| ATK-07 | Injection de redirect ouvert | start n'accepte pas redirect_uri | auth_url sans domaine malveillant |
| ATK-08 | Réutilisation de session après déconnexion | `revoked_at` défini → findSession échoue | 401 |
| ATK-09 | Token de session invalide | Vérification DB → token non enregistré rejeté | 401 |
| ATK-10 | Accès à /me sans authentification | Bearer non défini → 401 | 401 |
| ATK-11 | Injection SQL dans le paramètre state | Neutralisé par prepared statement | 400/422 |
| ATK-12 | /me avec session d'un autre utilisateur | Le token est lié à user_id | user.id différent |

---

## Structure des tests

```
tests/
  OAuth/
    OAuthTest.php   — 10 tests fonctionnels
    AttackTest.php  — 12 tests d'attaque cracker (ATK-01〜12)
```

Total 22 tests / 36 assertions.

---

## Implémentation de référence

`../NENE2-FT/oauthlog/` — Field Trial FT160 (22 tests + 12 tests d'attaque cracker)
