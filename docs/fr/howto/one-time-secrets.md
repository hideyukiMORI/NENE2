# How-to : API de secrets à usage unique et test d'attaque cracker ATK-01~12

> **NENE2 Field Trial 184** — Cycle de test d'attaque cracker (ATK-01~12).
> Le token EST le credential. La consommation atomique prévient les conditions de course.

---

## Ce que ce trial prouve

Un secret à usage unique stocke un message chiffré qui ne peut être lu qu'une seule fois. Après la première lecture réussie, le secret est consommé définitivement.

Exigences de sécurité :
1. **Entropie de token 256 bits** — le brute force est computationnellement infaisable
2. **Consommation atomique** — `UPDATE WHERE consumed=0` prévient les conditions de course de double lecture
3. **Prévention IDOR** — la suppression nécessite à la fois le token + la propriété utilisateur
4. **Mass assignment bloqué** — consumed/token/created_at sont côté serveur uniquement
5. **Sûreté de type** — V::str() / V::userId() / V::queryInt() rejettent les entrées non-string

---

## API

| Méthode | Chemin | Auth | Description |
|---------|--------|------|-------------|
| `POST` | `/secrets` | X-User-Id | Créer un secret à usage unique |
| `GET` | `/secrets` | X-User-Id | Lister ses propres secrets (métadonnées uniquement, pas de message) |
| `GET` | `/secrets/{token}` | — | Lire + consommer (le token EST le credential) |
| `DELETE` | `/secrets/{token}` | X-User-Id | Annuler avant lecture (doit posséder) |

---

## Résultats ATK-01~12

| ID | Vecteur d'attaque | Défense | Résultat |
|----|---|---|---|
| ATK-01 | Injection SQL dans le token | Requêtes paramétrées PDO | ✅ PASS |
| ATK-02 | Suppression IDOR cross-utilisateur | `WHERE token=? AND user_id=?` | ✅ PASS |
| ATK-03 | Mass assignment (`consumed=1` dans body) | Champs côté serveur uniquement | ✅ PASS |
| ATK-04 | Payload XSS dans message | API JSON — pas de rendu HTML | ✅ PASS |
| ATK-05 | Token double-encodé / malformé | Vérification de format `/^[0-9a-f]{64}$/` | ✅ PASS |
| ATK-06 | Contournement d'auth à la lecture | Le token EST le credential — par conception | ✅ PASS |
| ATK-07 | Message/password comme non-string | `V::str()` applique `is_string()` | ✅ PASS |
| ATK-08 | Dépassement 20 chiffres dans limit/offset | Garde `V::queryInt()` strlen > 18 | ✅ PASS |
| ATK-09 | ReDoS dans paramètre limit | `ctype_digit()` — O(n), pas de retour arrière | ✅ PASS |
| ATK-10 | Brute force du token | `random_bytes(32)` = 2^256 entropie | ✅ PASS |
| ATK-11 | Condition de course double lecture | `UPDATE WHERE consumed=0` + vérification rowCount | ✅ PASS |
| ATK-12 | Injection d'en-tête dans X-User-Id | `V::userId()` applique `ctype_digit()` | ✅ PASS |

**12/12 : PASS**

---

## Pattern central : Consommation atomique

L'invariant de sécurité critique — un secret ne peut être lu qu'une seule fois :

```php
// SecretRepository::consumeByToken()

// Étape 1 : Récupérer le secret (SELECT ordinaire — pas le garde)
$row = $pdo->prepare('SELECT * FROM secrets WHERE token = :token');
$row->execute(['token' => $token]);
$secret = $row->fetch(PDO::FETCH_ASSOC);

// Étape 2 : Vérifier le flag consumed (sortie anticipée pour le cas courant)
if ($secret['consumed']) return null;

// Étape 3 : UPDATE atomique — c'est le vrai garde
$update = $pdo->prepare(
    'UPDATE secrets SET consumed = 1 WHERE token = :token AND consumed = 0'
);
$update->execute(['token' => $token]);

// Étape 4 : rowCount() === 0 signifie qu'un autre lecteur a gagné la course
if ($update->rowCount() === 0) {
    return null; // quelqu'un d'autre l'a consommé entre notre SELECT et ce UPDATE
}

// Étape 5 : Nous avons gagné — retourner le secret
return Secret::fromRow($secret);
```

**Pourquoi ça marche :** SQLite et la plupart des SGBDR garantissent que `UPDATE WHERE consumed=0` est atomique. Seul un écrivain concurrent peut changer `consumed` de 0→1. Le perdant a `rowCount()` = 0.

---

## Génération du token

```php
$token = bin2hex(random_bytes(32)); // 64 caractères hex = 32 octets = 256 bits
```

- `random_bytes()` utilise le CSPRNG de l'OS (équivalent à `/dev/urandom`)
- 2^256 tokens à 10^12 suppositions/seconde ≈ 10^60 ans pour brute forcer
- Les tokens sont uniques dans la DB (contrainte `UNIQUE`)

---

## Validation du format du token

```php
private const TOKEN_PATTERN = '/^[0-9a-f]{64}$/';

// Rejette : hex majuscules, traversée de chemin ../../, URL-encodé, entiers, vide
if (!preg_match(self::TOKEN_PATTERN, $rawToken)) {
    return $this->responseFactory->create(['error' => 'Secret not found.'], 404);
}
```

---

## Prévention IDOR (ATK-02)

```php
// DELETE nécessite à la fois la propriété du token ET la correspondance user_id
$stmt = $pdo->prepare(
    'DELETE FROM secrets WHERE token = :token AND user_id = :user_id AND consumed = 0'
);
$stmt->execute(['token' => $token, 'user_id' => $userId]);

// Retourne 404 quelle que soit la raison — évite l'oracle d'énumération
return $stmt->rowCount() > 0;
```

---

## Prévention de mass assignment (ATK-03)

Les champs côté serveur ne sont **jamais lus depuis le corps de la requête** :

```php
// Gestionnaire POST /secrets — seuls message, password, expires_at sont acceptés depuis le corps
$token        = bin2hex(random_bytes(32));  // généré côté serveur
$consumed     = 0;                          // commence toujours non consommé
$createdAt    = (new DateTimeImmutable())->format(DateTimeInterface::ATOM); // heure serveur
$passwordHash = $password !== null ? hash('sha256', $password) : null;     // hashé côté serveur

// body['consumed'], body['token'], body['user_id'], body['created_at'] sont silencieusement ignorés
```

---

## Chaîne de validation V.php

```php
// ATK-07: message doit être une chaîne (rejette int, bool, null, array)
$message = V::str($body['message'] ?? null, 10000);

// ATK-12: X-User-Id doit être ctype_digit + positif + max 18 caractères
$userId = V::userId($request->getHeaderLine('X-User-Id'));

// ATK-08/09: limit doit être numérique, max 18 chiffres, dans la plage 1–100
$limit = V::queryInt($params, 'limit', 1, 100, 20);
```

---

## Protection par mot de passe optionnelle

```php
// Stockage : hash SHA-256 uniquement (pas en clair)
$passwordHash = $password !== null ? hash('sha256', $password) : null;

// Vérification : comparaison à temps constant (sûre contre le timing)
if (!hash_equals($secret->passwordHash, hash('sha256', $submittedPassword))) {
    return null; // mauvais mot de passe → 404 silencieux (pas d'oracle)
}
```

> **Note :** Un mauvais mot de passe retourne 404 (pas 403) pour prévenir les attaques oracle.
> Le secret N'EST PAS consommé avec un mauvais mot de passe — seul le bon mot de passe le consomme.

---

## Liste de métadonnées (pas de fuite de message)

```php
// GET /secrets — retourne uniquement les métadonnées, jamais le message
private function secretToMetadata(Secret $secret): array
{
    return [
        'token'        => $secret->token,
        'has_password' => $secret->passwordHash !== null,
        'consumed'     => $secret->consumed,
        'expires_at'   => $secret->expiresAt,
        'created_at'   => $secret->createdAt,
        // 'message' est intentionnellement omis
    ];
}
```

---

## Résultats des tests

```
85 tests / 209 assertions — tous PASS
PHPStan level 8 — pas d'erreurs
PHP CS Fixer — propre
```

---

## Points clés à retenir

| Pattern | Règle |
|---------|-------|
| Consommation atomique | `UPDATE WHERE consumed=0` + vérification `rowCount()` — pas SELECT puis UPDATE |
| Entropie du token | `random_bytes(32)` minimum (256 bits) — jamais d'IDs séquentiels |
| Format du token | Regex liste blanche ancrée aux deux bouts (`/^[0-9a-f]{64}$/`) |
| IDOR | Toutes les opérations d'écriture scopées par `token AND user_id` |
| Mass assignment | Token, consumed, created_at — côté serveur uniquement, jamais depuis le corps |
| Timing du mot de passe | `hash_equals()` pour comparaison à temps constant |
| Mauvais mot de passe | 404 pas 403 — évite de confirmer l'existence du secret |
| Liste de métadonnées | Omettre le message de l'endpoint de liste — lecture uniquement à la consommation |

Exemple complet : [`../NENE2-FT/onetimelog/`](https://github.com/hideyukiMORI/NENE2-examples) dans le repository d'exemples.
