# Export de données personnelles

Un export de données style RGPD permet aux utilisateurs de télécharger toutes leurs données personnelles. Les préoccupations principales sont : l'exclusion de champs sensibles du payload d'export, les tokens de téléchargement sécurisés et l'application de l'expiration.

## Composants principaux

- **Job d'export** : un enregistrement liant un utilisateur à un token de téléchargement opaque, avec un statut (pending → ready) et un horodatage d'expiration.
- **Étape de traitement** : une opération côté worker qui construit le payload et marque le job comme prêt.
- **Téléchargement** : récupère le payload par token, en vérifiant l'expiration avant de servir.

## Schéma

```sql
CREATE TABLE data_exports (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    token      TEXT    NOT NULL UNIQUE,
    status     TEXT    NOT NULL DEFAULT 'pending',
    payload    TEXT,
    expires_at TEXT    NOT NULL,
    created_at TEXT    NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id)
);
```

## Génération du token

Utiliser `bin2hex(random_bytes(32))` — 64 caractères hex, 256 bits d'entropie. Les IDs séquentiels, horodatages ou tokens basés sur MD5 sont devinables et ne doivent pas être utilisés pour les tokens de téléchargement.

```php
$token = bin2hex(random_bytes(32));
```

## Exclusion des champs sensibles

Le payload d'export ne doit jamais contenir de credentials ni de champs pour lesquels l'utilisateur n'a pas explicitement consenti à l'export. Exclure au niveau du repository, pas au niveau HTTP :

```php
public function processExport(string $token, User $user, array $activities, string $now): DataExport
{
    $payload = json_encode([
        'exported_at' => $now,
        'user' => [
            'id'         => $user->id,
            'email'      => $user->email,
            'name'       => $user->name,
            'created_at' => $user->createdAt,
            // password_hash intentionnellement exclu
            // phone intentionnellement exclu (reconsentement requis pour les PII)
        ],
        'activities' => $activities,
    ], JSON_THROW_ON_ERROR);
    // ...
}
```

Appliquer la même exclusion à l'endpoint de profil public — `phone`, `password_hash` et tout champ interne ne doivent pas apparaître dans les réponses `GET /users/{id}` non plus.

## Application de l'expiration

Appliquer l'expiration dans **les deux** endpoints de téléchargement et de traitement :

```php
// Dans downloadExport :
if ($export->isExpired($now)) {
    return $this->problems->create($request, 'gone', 'Export has expired.', 410, '');
}

// Dans processExport — CRITIQUE : vérifier ici aussi
if ($export->isExpired($now)) {
    return $this->problems->create($request, 'gone', 'Export request has expired. Please request a new export.', 410, '');
}
```

Sans la vérification dans `processExport`, un worker qui reçoit un job périmé écrirait les données de l'utilisateur en DB même si la fenêtre de téléchargement est fermée, créant des enregistrements orphelins avec des données payload sensibles.

## Flux de statut

```
pending ──(process appelé, non expiré)──▶ ready ──(download appelé)──▶ [payload servi]
   │                                                      │
   └──(process appelé, expiré)──▶ 410                    └──(expiré)──▶ 410
```

## Téléchargement : 410 Gone vs 404 Not Found

- **404** : le token n'existe pas dans la base de données.
- **410 Gone** : le token existe mais a expiré. C'est le statut correct — la ressource existait et a depuis été supprimée. Les clients peuvent utiliser ce signal pour inviter l'utilisateur à demander un nouvel export.

## Décisions de conception

**Pourquoi une étape `process` séparée plutôt qu'une génération synchrone ?**
Les payloads d'export peuvent être volumineux (des années de données d'activité). Générer de façon synchrone dans le gestionnaire HTTP risque les timeouts et mobilise un worker. Le pattern asynchrone permet à l'utilisateur de demander et de vérifier plus tard. Pour ce FT, l'étape de traitement est exposée comme une API pour simuler l'invocation d'un worker.

**Pourquoi utiliser le token comme URL de téléchargement plutôt que l'ID d'export ?**
Un ID entier séquentiel est vulnérable aux IDOR — l'utilisateur 1 pourrait télécharger l'export de l'utilisateur 2 en incrémentant l'ID. Un token aléatoire opaque rend l'URL de téléchargement impossible à deviner.

**L'endpoint `process` devrait-il être public ?**
En production, non. L'endpoint de traitement ne devrait être appelé que par des workers internes (via clé API, réseau interne ou file d'attente). Dans ce FT il est exposé pour la testabilité. L'entropie du token offre une certaine protection mais ne remplace pas une authentification de worker appropriée.
