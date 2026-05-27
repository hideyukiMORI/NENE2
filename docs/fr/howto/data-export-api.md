# How-to : API d'export de données

> **Référence FT** : FT312 (`NENE2-FT/exportlog`) — Export de données (style RGPD) : machine à états async `pending→ready` via téléchargement basé sur token, exclusion PII via `toPublicArray()` (password_hash et phone jamais dans la réponse GET ni dans le payload d'export), hachage de mot de passe ARGON2ID, token d'export 64 caractères hex, 410 Gone pour les exports expirés, 409 pour tentative de téléchargement en attente, 19 tests / 32 assertions PASS.

Ce guide montre comment construire un système d'export de données utilisateur (portabilité selon l'Article 20 du RGPD) où les exports sont asynchrones, protégés par des tokens, et où les champs sensibles PII ne sont jamais divulgués.

## Schéma

```sql
CREATE TABLE users (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    email         TEXT    NOT NULL UNIQUE,
    name          TEXT    NOT NULL,
    phone         TEXT    NOT NULL DEFAULT '',
    password_hash TEXT    NOT NULL,
    created_at    TEXT    NOT NULL
);

CREATE TABLE data_exports (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    token      TEXT    NOT NULL UNIQUE,  -- 64 caractères hex
    status     TEXT    NOT NULL DEFAULT 'pending',
    payload    TEXT,                     -- JSON, défini quand status='ready'
    expires_at TEXT    NOT NULL,
    created_at TEXT    NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id)
);
```

`token` est une chaîne hex de 64 caractères pour l'URL de téléchargement. `payload` est null jusqu'à ce que l'export soit traité.

## Endpoints

| Méthode | Chemin | Description |
|---------|--------|-------------|
| `POST` | `/users` | Inscrire un utilisateur |
| `GET` | `/users/{id}` | Obtenir un utilisateur (PII exclu) |
| `POST` | `/users/{id}/export` | Demander un export de données → 202 |
| `POST` | `/exports/{token}/process` | Traiter l'export (worker async) |
| `GET` | `/exports/{token}` | Télécharger l'export terminé |

## Exclusion PII — toPublicArray()

```php
final class User
{
    public function toPublicArray(): array
    {
        return [
            'id'         => $this->id,
            'email'      => $this->email,
            'name'       => $this->name,
            'created_at' => $this->createdAt,
            // phone et password_hash intentionnellement exclus de la vue publique
        ];
    }
}
```

La réponse `GET /users/{id}` appelle `toPublicArray()` — jamais le tableau complet. `phone` et `password_hash` sont stockés mais jamais retournés via API.

La même exclusion s'applique au payload d'export : l'export est construit depuis `toPublicArray()` (ou équivalent), pas depuis une ligne DB brute.

## Hachage de mot de passe — ARGON2ID

```php
$passwordHash = password_hash($password, PASSWORD_ARGON2ID);
```

ARGON2ID est l'algorithme moderne recommandé (résistant à la mémoire, résistant aux attaques GPU). `PASSWORD_BCRYPT` est acceptable mais plus faible contre le crackage GPU.

## Export async — pending → ready

```
POST /users/{id}/export  →  202 Accepted
  → crée une ligne data_exports : status='pending', token='<64hex>'

POST /exports/{token}/process  →  200 OK
  → construit le payload, définit status='ready'

GET /exports/{token}  →  200 OK (téléchargement)
  → retourne le payload si status='ready'
```

**Génération du token d'export :**
```php
$token = bin2hex(random_bytes(32)); // 64 caractères hex
```

**Gestionnaire de traitement :**
```php
if ($export->status === 'ready') {
    return 200; // déjà traité, idempotent
}
if ($export->expiresAt < date('c')) {
    return 410; // expiré — ne pas traiter
}
// Construire et stocker le payload
$this->repo->markReady($export->token, json_encode($export->user->toPublicArray()));
```

## Vérifications de statut — 409 et 410

```php
// Gestionnaire de téléchargement
if ($export->expiresAt < date('c')) {
    return $this->problems->create($request, 'gone', 'Export has expired.', 410, '');
}

if ($export->status !== 'ready') {
    return $this->problems->create($request, 'conflict', 'Export is not yet ready.', 409, '');
}
```

| État | Réponse de téléchargement |
|---|---|
| `pending` | 409 Conflict |
| `ready` (non expiré) | 200 OK avec payload |
| `ready` (expiré) | 410 Gone |

410 Gone est utilisé pour les ressources expirées (RGPD : les données d'export ne devraient pas persister indéfiniment).

---

## Ce qu'il ne faut PAS faire

| Anti-pattern | Risque |
|---|---|
| Inclure `password_hash` dans la réponse GET | Hash de mot de passe exposé ; permet le crackage hors ligne |
| Inclure `phone` dans la réponse GET sans auth | Fuite PII ; numéros de téléphone exposés à quiconque connaît l'ID utilisateur |
| Inclure `password_hash` dans le payload d'export | Violation du RGPD ; l'export est un document de portabilité des données côté utilisateur |
| Utiliser `PASSWORD_MD5` ou `PASSWORD_DEFAULT` | Hachage de mot de passe faible ; passer à ARGON2ID |
| Retourner 404 pour les exports expirés (pas 410) | 404 cache la distinction entre "n'a jamais existé" et "expiré" |
| Retourner 200 pour le téléchargement en attente | Le client pense que l'export est prêt ; reçoit un payload vide ou cassé |
| Token d'export court (< 64 caractères) | Token devinable ; n'importe qui peut télécharger l'export de n'importe quel utilisateur |
| Pas de `expires_at` sur les exports | Les exports persistent indéfiniment ; problème de conformité RGPD |
