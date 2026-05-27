# How-to : Gestion des slugs d'URL avec historique

> **Référence FT** : FT339 (`NENE2-FT/sluglog`) — Slugs auto-générés depuis les titres, compteur de collision, historique des slugs pour les redirections 301 des anciens slugs, override de slug explicite, évaluation de vulnérabilité, 17 tests / 50+ assertions PASS.

Ce guide montre comment générer des slugs d'URL propres depuis les titres de contenu, gérer les collisions avec des suffixes séquentiels, conserver les anciens slugs dans une table d'historique pour des redirections permanentes, et prévenir les vecteurs d'attaque courants.

## Schéma

```sql
CREATE TABLE articles (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    title      TEXT    NOT NULL,
    body       TEXT    NOT NULL,
    slug       TEXT    NOT NULL UNIQUE,
    created_at TEXT    NOT NULL,
    updated_at TEXT    NOT NULL
);

CREATE TABLE slug_history (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    article_id INTEGER NOT NULL REFERENCES articles(id),
    old_slug   TEXT    NOT NULL,
    created_at TEXT    NOT NULL
);
```

## Endpoints

| Méthode | Chemin | Description |
|---------|--------|-------------|
| `POST` | `/articles` | Créer un article (slug auto depuis le titre) |
| `PUT` | `/articles/{id}` | Mettre à jour l'article (slug régénéré si le titre change) |
| `GET` | `/articles/by-slug/{slug}` | Obtenir par slug actuel ou ancien |
| `GET` | `/articles/{id}/slug-history` | Lister l'historique des slugs |

## Génération de slug

### `SlugHelper::fromTitle()`

```php
SlugHelper::fromTitle('Hello World')          // → "hello-world"
SlugHelper::fromTitle('PHP 8.4: New Features!') // → "php-8-4-new-features"
SlugHelper::fromTitle('  --Hello--  ')        // → "hello"
SlugHelper::fromTitle('')                     // → "untitled"
SlugHelper::fromTitle('---')                  // → "untitled"
```

Règles :
1. Tout mettre en minuscules
2. Remplacer les caractères non alphanumériques par `-`
3. Réduire les tirets consécutifs
4. Supprimer les tirets en début/fin
5. Retourner `"untitled"` si le résultat est vide

```php
public static function fromTitle(string $title): string
{
    $slug = strtolower($title);
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
    $slug = trim($slug, '-');
    return $slug !== '' ? $slug : 'untitled';
}
```

### Résolution de collision

```php
POST /articles  {"title": "Hello", "body": "..."}  → 201  {"slug": "hello"}
POST /articles  {"title": "Hello", "body": "..."}  → 201  {"slug": "hello-2"}
POST /articles  {"title": "Hello", "body": "..."}  → 201  {"slug": "hello-3"}
```

```php
public static function makeUnique(string $base, callable $isTaken): string
{
    if (!$isTaken($base)) {
        return $base;
    }

    $i = 2;
    while ($isTaken("{$base}-{$i}")) {
        $i++;
    }

    return "{$base}-{$i}";
}
```

`$isTaken` est un callback de recherche DB : `fn(string $s): bool => (bool) $repo->findBySlug($s)`.

## Créer un article

```php
POST /articles
{"title": "My First Post", "body": "Content here."}
→ 201
{
  "id": 1,
  "title": "My First Post",
  "slug": "my-first-post",
  "body": "...",
  "created_at": "..."
}
```

## Mettre à jour un article

```php
PUT /articles/1
{"title": "New Title", "body": "Updated content."}
→ 200  {"slug": "new-title", ...}
```

Quand le titre change, le nouveau slug est dérivé et l'ancien slug est sauvegardé dans `slug_history`.

```php
// Même titre — slug inchangé, pas d'entrée dans l'historique
PUT /articles/1  {"title": "New Title", "body": "Different body."}
→ 200  {"slug": "new-title"}  // même slug

// Override de slug explicite
PUT /articles/1  {"title": "New Title", "body": "Body.", "slug": "custom-url-here"}
→ 200  {"slug": "custom-url-here"}

// Collision lors de la mise à jour — résolution automatique
// (si "popular" existe, renommer en "popular-2")
PUT /articles/2  {"title": "Popular", "body": "Body."}
→ 200  {"slug": "popular-2"}

// Article inconnu
PUT /articles/9999  {"title": "X", "body": "Y"}
→ 404
```

## Obtenir par slug

```php
// Slug actuel → 200
GET /articles/by-slug/new-title
→ 200  {"id": 1, "slug": "new-title", "title": "New Title", ...}

// Ancien slug → redirection 301
GET /articles/by-slug/my-first-post
→ 301
{
  "redirect": true,
  "canonical_slug": "new-title"
}

// Inconnu → 404
GET /articles/by-slug/does-not-exist
→ 404
```

Les réponses 301 indiquent aux crawlers/clients de mettre à jour leurs liens vers le slug canonique.

## Historique des slugs

```php
GET /articles/1/slug-history
→ 200
{
  "current_slug": "new-title",
  "slug_history": [
    {"old_slug": "my-first-post", "created_at": "..."}
  ]
}

// Nouvel article — historique vide
{"current_slug": "fresh", "slug_history": []}

// Article inconnu → 404
GET /articles/9999/slug-history → 404
```

Les entrées d'historique ne s'accumulent que lorsque le slug change réellement. Mettre à jour le corps sans changer le titre laisse l'historique intact.

---

## Évaluation de vulnérabilité

### V-01 — Path Traversal via slug ✅ SAFE

**Risque** : L'attaquant envoie `GET /articles/by-slug/../../../etc/passwd` pour traverser les répertoires du serveur.
**Constat** : SAFE — Les recherches par slug sont des `WHERE slug = ?` SQL avec un paramètre lié. Le segment de chemin n'est jamais interprété comme un chemin de système de fichiers. Le routage analyse le chemin avant qu'il n'atteigne le contrôleur ; `../` dans un chemin URL est canonicalisé par la couche HTTP.

---

### V-02 — Injection SQL via slug dans l'URL ✅ SAFE

**Risque** : `GET /articles/by-slug/' OR '1'='1` fuite tous les articles.
**Constat** : SAFE — Le slug est passé comme paramètre lié dans `WHERE slug = ?`. L'injection SQL est impossible quelle que soit la valeur du slug.

---

### V-03 — Énumération de slugs (découverte par force brute) ⚠️ EXPOSED

**Risque** : L'attaquant itère les slugs courants (`/articles/by-slug/admin`, `/articles/by-slug/secret-doc`) pour découvrir des articles privés.
**Constat** : EXPOSED — Les slugs sont des dérivations prévisibles de titres lisibles par l'homme. Aucune limitation de débit ni authentification n'est appliquée sur `GET /articles/by-slug/{slug}`. Atténuation : exiger l'authentification pour le contenu privé ; ajouter une limitation de débit par IP ; envisager des IDs opaques pour les ressources sensibles.

---

### V-04 — IDOR sur l'historique des slugs ✅ SAFE

**Risque** : L'attaquant appelle `GET /articles/{id}/slug-history` pour l'article d'un autre utilisateur pour découvrir des titres passés.
**Constat** : SAFE — L'historique des slugs est des métadonnées publiques. Si les articles sont publics, leur historique l'est aussi. Si les articles nécessitent une autorisation, appliquer le même contrôle d'auth à l'endpoint `/slug-history` de manière cohérente.

---

### V-05 — Boucle de redirection infinie via l'historique des slugs ✅ SAFE

**Risque** : L'article A se renomme en slug B ; l'article B se renomme en slug A — `GET /by-slug/a` → redirection vers B → redirection vers A (boucle infinie).
**Constat** : SAFE — L'implémentation recherche le slug **actuel** dans `articles.slug`, puis vérifie `slug_history` uniquement pour les anciens slugs. Une réponse 301 pointe toujours vers le canonique actuel. Les clients suivant les redirections atteignent le canonique en un seul saut.

---

### V-06 — Abus de collision de slug (épuisement du compteur séquentiel) ⚠️ EXPOSED

**Risque** : L'attaquant crée des milliers d'articles intitulés "popular" pour réserver "popular-2" à "popular-9999", puis les supprime — ou pour forcer un scan de compteur coûteux.
**Constat** : EXPOSED — Pas de limitation de débit sur la création d'articles. Le scan de compteur `makeUnique` est O(n) requêtes DB. Atténuation : limiter le débit de POST /articles par utilisateur ; plafonner le compteur de slug à une limite raisonnable (ex. 99) ; utiliser un suffixe aléatoire après le seuil.

---

### V-07 — Injection de slug explicite (Écraser le slug d'un autre article) ✅ SAFE

**Risque** : L'attaquant utilise `PUT /articles/2  {"slug": "popular"}` où "popular" appartient à l'article 1.
**Constat** : SAFE — `articles.slug` a une contrainte `UNIQUE`. Tenter de définir un slug déjà revendiqué par un autre article déclenche une violation de contrainte DB, traduite en 409 Conflict.

---

### V-08 — Attaque homographe Unicode/slug ⚠️ EXPOSED

**Risque** : L'attaquant crée un article avec un titre Unicode qui se normalise aux mêmes octets qu'un slug ASCII existant (ex. `café` → `caf-`) pour créer une URL visuellement confuse.
**Constat** : EXPOSED — `SlugHelper::fromTitle()` utilise `preg_replace('/[^a-z0-9]+/', '-', strtolower($title))`. Les caractères non-ASCII sont remplacés par `-`, ce qui peut causer des collisions inattendues ou des slugs vides. Atténuation : normaliser Unicode en translittération ASCII (ex. `iconv`) avant la génération de slug ; traiter tous les non-ASCII comme `-` après normalisation.

---

### V-09 — XSS via titre stocké dans le slug ✅ SAFE

**Risque** : Le titre `<script>alert(1)</script>` produit le slug `script-alert-1-script` — sortie alphanumérique sûre.
**Constat** : SAFE — `SlugHelper::fromTitle()` supprime tous les caractères non alphanumériques en `-`. La sortie du slug est toujours `[a-z0-9-]`, rendant l'injection HTML impossible via le slug.

---

### V-10 — La recherche d'ancien slug révèle le contenu renommé ⚠️ EXPOSED

**Risque** : Article renommé de "secret-plan-v1" à "public-announcement" ; l'attaquant utilise l'ancien slug pour découvrir le titre original via le `canonical_slug` de la réponse de redirection.
**Constat** : EXPOSED — La réponse 301 expose le nouveau slug canonique, qui peut révéler le contenu renommé. L'endpoint d'historique des slugs révèle également tous les anciens noms. Pour les renommages sensibles, mettre les anciens slugs en tombstone sans révéler le nouvel emplacement ; ou utiliser des slugs opaques.

---

### Résumé VULN

| ID | Vulnérabilité | Constat |
|----|---------------|---------|
| V-01 | Path traversal via slug | ✅ SAFE |
| V-02 | Injection SQL via slug | ✅ SAFE |
| V-03 | Énumération de slugs | ⚠️ EXPOSED |
| V-04 | IDOR sur l'historique des slugs | ✅ SAFE |
| V-05 | Boucle de redirection infinie | ✅ SAFE |
| V-06 | Épuisement du compteur de collision | ⚠️ EXPOSED |
| V-07 | Écrasement de slug explicite | ✅ SAFE |
| V-08 | Attaque homographe Unicode | ⚠️ EXPOSED |
| V-09 | XSS via titre | ✅ SAFE |
| V-10 | Ancien slug révèle le contenu renommé | ⚠️ EXPOSED |

**6 SAFE, 4 EXPOSED** — Limiter le débit de création d'articles ; ajouter l'authentification pour le contenu privé ; normaliser Unicode avant la génération de slug ; envisager un historique de slug tombstone uniquement pour les renommages sensibles.

---

## Ce qu'il ne faut PAS faire

| Anti-pattern | Risque |
|---|---|
| Interpoler le slug directement dans SQL | Injection SQL via le paramètre de chemin slug |
| Supprimer physiquement l'historique des slugs à la suppression d'article | Les anciennes URLs retournent 404 au lieu de 301 ; pourrissement des liens et SEO |
| Pas de contrainte `UNIQUE` sur `articles.slug` | Les insertions concurrentes créent des slugs dupliqués |
| Retourner l'ancien slug inchangé lors de la mise à jour du titre | Dérive du slug — l'URL ne reflète plus le contenu |
| Pas de plafond du compteur dans `makeUnique` | L'attaquant épuise le compteur via des créations en masse |
| Utiliser `!==` pour comparer les slugs existants | Surprises de coercition de type ; toujours utiliser `===` pour la comparaison de slugs |
