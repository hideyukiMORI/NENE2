# How-to : Limiteur de débit à fenêtre glissante

## Vue d'ensemble

Ce guide couvre la construction d'un limiteur de débit à fenêtre glissante par utilisateur et par endpoint avec NENE2. Les requêtes sont comptées dans une fenêtre de temps mobile ; une fois la limite atteinte, les requêtes suivantes sont rejetées avec `429 Too Many Requests`.

**Implémentation de référence** : `../NENE2-FT/ratelog/`

---

## Conception du schéma

```sql
CREATE TABLE IF NOT EXISTS rate_events (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    endpoint   TEXT    NOT NULL,
    created_at TEXT    NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_rate_events_user_endpoint
    ON rate_events (user_id, endpoint, created_at);
```

L'index sur `(user_id, endpoint, created_at)` rend la requête COUNT rapide à l'échelle.

---

## Table des routes

| Méthode | Chemin | Auth | Description |
|---------|--------|------|-------------|
| `POST` | `/rate/check` | Utilisateur | Enregistrer une requête ; retourne 429 si au-dessus de la limite |
| `GET` | `/rate/status` | Utilisateur | Utilisation actuelle pour un utilisateur/endpoint |
| `DELETE` | `/rate/reset/{userId}` | Admin | Réinitialiser les compteurs d'un utilisateur |

---

## Algorithme principal

```php
private const int LIMIT = 10;
private const int WINDOW_SECONDS = 60;

public function check(int $userId, string $endpoint): string
{
    $since = $this->windowStart();   // now() - 60s
    $count = $this->countInWindow($userId, $endpoint, $since);

    if ($count >= self::LIMIT) {
        return 'rate_limited';
    }

    $this->recordEvent($userId, $endpoint);
    return 'ok';
}
```

**Fenêtre glissante** : chaque `check()` regarde exactement `WINDOW_SECONDS` en arrière depuis le moment actuel, donc les anciens événements tombent naturellement hors de portée.

---

## Réinitialisation admin avec pattern fail-closed

```php
private function isAdmin(ServerRequestInterface $req): bool
{
    if ($this->adminKey === '') {
        return false;     // fail-closed : clé non configurée bloque tout accès admin
    }
    return hash_equals($this->adminKey, $req->getHeaderLine('X-Admin-Key'));
}
```

Réinitialiser tous les compteurs d'un utilisateur (tous les endpoints) :
```sql
DELETE FROM rate_events WHERE user_id = :uid
```

Réinitialiser pour un endpoint spécifique :
```sql
DELETE FROM rate_events WHERE user_id = :uid AND endpoint = :ep
```

---

## Extraction de paramètre de chemin (sans Router::param())

Quand `Router::param()` n'est pas disponible dans la version installée, utiliser l'attribut directement :

```php
/** @var array<string, string> $params */
$params = $req->getAttribute(Router::PARAMETERS_ATTRIBUTE, []);
$raw    = $params['userId'] ?? '';
```

---

## Validation

- `endpoint` : chaîne non vide, max 128 caractères
- `X-User-Id` : `ctype_digit()` + entier positif
- Chemin `userId` : `ctype_digit()` + entier positif (échec → 404)
- Clé admin : comparaison `hash_equals()` (échec → 403)

---

## Codes de statut HTTP

| Situation | Statut |
|-----------|--------|
| Requête autorisée | 200 |
| Statut récupéré | 200 |
| Compteur réinitialisé | 200 |
| Pas de X-User-Id | 400 |
| Pas de corps | 400 |
| Endpoint vide / manquant | 422 |
| Endpoint trop long | 422 |
| Pas de clé admin | 403 |
| Mauvaise clé admin | 403 |
| userId invalide dans le chemin | 404 |
| Limite de débit dépassée | 429 |

---

## Patterns d'attaque ATK couverts

| ATK | Pattern | Défense |
|-----|---------|---------|
| ATK-01 | X-User-Id manquant | 400 avec message |
| ATK-02 | Chaîne endpoint vide | Validation 422 |
| ATK-03 | Endpoint de 129 caractères (DoS) | Limite de longueur 422 |
| ATK-04 | Injection SQL dans l'endpoint | Requêtes paramétrées |
| ATK-05 | Tentative de réinitialisation non-admin | 403 fail-closed |
| ATK-06 | Mauvaise clé admin | 403 hash_equals() |
| ATK-07 | userId négatif dans le chemin | 404 |
| ATK-08 | userId zéro | 404 |
| ATK-09 | userId non-numérique (`abc`) | 404 ctype_digit |
| ATK-10 | Statut sans paramètre endpoint | 422 |
| ATK-11 | Check sans corps | 400 |
| ATK-12 | Corps sans clé endpoint | 422 |

---

## Notes

- **Concurrence** : La fenêtre glissante a une petite fenêtre TOCTOU. Pour une utilisation en production à forte concurrence, envisager des compteurs atomiques (Redis INCR + EXPIRE) ou un verrouillage au niveau de la base de données.
- **Dérive d'horloge** : Tous les timestamps doivent utiliser UTC pour éviter les surprises liées à l'heure d'été ou aux fuseaux horaires.
- **Croissance du stockage** : Les anciens événements s'accumulent. Ajouter un job de nettoyage périodique : `DELETE FROM rate_events WHERE created_at < :cutoff`.
