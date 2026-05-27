# How-to : API de contenu multilingue

> **Référence FT** : FT232 (`NENE2-FT/i18nlog`) — API de contenu multilingue
> **ATK** : FT232 — test d'attaque mentalité cracker (ATK-01 à ATK-12)

Démontre une API d'articles multilingues où le contenu est stocké sous forme de traductions indexées par locale séparées de l'enregistrement article lui-même. Supporte la validation de locale BCP 47, la sémantique upsert pour les traductions, le fallback de locale pour la négociation de contenu, et l'état publié/brouillon par article.

---

## Routes

| Méthode | Chemin | Description |
|---------|--------|-------------|
| `POST` | `/articles` | Créer un article (brouillon ou publié) |
| `GET` | `/articles` | Lister les articles publiés (`?locale=` optionnel) |
| `GET` | `/articles/{id}` | Obtenir un seul article (`?locale=` optionnel) |
| `PUT` | `/articles/{id}/translations/{locale}` | Créer ou mettre à jour une traduction (upsert) |

---

## Création d'un article

```json
{
  "default_locale": "en",
  "published": false
}
```

`default_locale` définit la langue de fallback quand une locale demandée n'est pas disponible. `published` contrôle la visibilité dans la liste — seuls les articles publiés apparaissent dans `GET /articles`.

```php
$defaultLocale = isset($body['default_locale']) && is_string($body['default_locale'])
    ? trim($body['default_locale']) : 'en';
$published = isset($body['published']) && $body['published'] === true;

if (!preg_match('/^[a-z]{2}(-[A-Z]{2})?$/', $defaultLocale)) {
    return $this->problems->create($request, 'validation-failed', 'Validation Failed', 422, null, [
        'errors' => [['field' => 'default_locale', 'code' => 'invalid',
                      'message' => 'default_locale must be a BCP 47 language tag (e.g. en, ja, fr-FR).']],
    ]);
}
```

`$body['published'] === true` (égalité stricte) signifie que JSON `true` définit le flag — toute autre valeur (chaîne `"true"`, entier `1`, absent) laisse l'article en brouillon.

---

## Validation de locale BCP 47

```php
preg_match('/^[a-z]{2}(-[A-Z]{2})?$/', $locale)
```

Accepte :
- Deux lettres minuscules : `en`, `ja`, `fr`, `de`
- Deux minuscules + tiret + deux majuscules : `fr-FR`, `zh-TW`, `pt-BR`

Rejette :
- Mauvaise casse : `EN`, `en_US`, `En`
- Tirets bas : `en_US` (BCP 47 utilise des tirets)
- Sous-tags au-delà de la région : `zh-Hant-TW`
- Traversée de chemin : `../../etc/passwd`
- Chaîne vide : `""`

Cette regex est suffisante pour les formes courantes `language` et `language-REGION`. Pour le support BCP 47 complet (codes de script, balises de variante) une bibliothèque dédiée est nécessaire.

---

## Upsert d'une traduction

`PUT /articles/{id}/translations/{locale}` crée la traduction si elle n'existe pas ou la met à jour si elle existe — idempotent avec sémantique last-write-wins :

```php
public function upsertTranslation(int $articleId, string $locale, string $title, string $body, string $now): Translation
{
    $existing = $this->executor->fetchAll(
        'SELECT * FROM article_translations WHERE article_id = ? AND locale = ?',
        [$articleId, $locale],
    );

    if ($existing !== []) {
        $this->executor->execute(
            'UPDATE article_translations SET title = ?, body = ?, updated_at = ? WHERE article_id = ? AND locale = ?',
            [$title, $body, $now, $articleId, $locale],
        );
    } else {
        $this->executor->execute(
            'INSERT INTO article_translations (article_id, locale, title, body, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)',
            [$articleId, $locale, $title, $body, $now, $now],
        );
    }
    // ... récupérer et retourner la ligne
}
```

La contrainte `UNIQUE(article_id, locale)` dans le schéma agit comme filet de sécurité ; le SELECT-then-INSERT/UPDATE au niveau application évite la résolution silencieuse de conflit et permet le retour explicite de la ligne persistée.

La validation du corps rejette le titre ou corps vide :

```php
$title = isset($body['title']) && is_string($body['title']) ? trim($body['title']) : '';
$text  = isset($body['body'])  && is_string($body['body'])  ? trim($body['body'])  : '';

$errors = [];
if ($title === '') {
    $errors[] = ['field' => 'title', 'code' => 'required', 'message' => 'title is required.'];
}
if ($text === '') {
    $errors[] = ['field' => 'body', 'code' => 'required', 'message' => 'body is required.'];
}
```

`trim()` avant la vérification de chaîne vide garantit que les chaînes contenant uniquement des espaces échouent aussi à la validation.

---

## Fallback de locale pour la négociation de contenu

Quand l'appelant passe `?locale=fr`, l'entité `Article` recherche la locale demandée et se replie sur `default_locale` si aucune traduction n'existe :

```php
public function getTranslationWithFallback(string $locale): ?Translation
{
    return $this->getTranslation($locale)
        ?? $this->getTranslation($this->defaultLocale);
}

public function toArray(?string $locale = null): array
{
    $translation = $locale !== null
        ? $this->getTranslationWithFallback($locale)
        : null;

    return [
        'id'             => $this->id,
        'default_locale' => $this->defaultLocale,
        'published'      => $this->published,
        'title'          => $translation?->title,    // null si pas de traduction stockée
        'body'           => $translation?->body,
        'locale'         => $translation?->locale,   // indique quelle locale a été servie
        'translations'   => array_map(fn (Translation $t) => $t->toArray(), $this->translations),
        'created_at'     => $this->createdAt,
        'updated_at'     => $this->updatedAt,
    ];
}
```

Le champ `locale` dans la réponse indique à l'appelant quelle locale a effectivement été servie — utile quand un fallback a eu lieu (`?locale=zh` → l'article sert la traduction `en` parce qu'aucune traduction chinoise n'existe encore).

---

## Schéma

```sql
CREATE TABLE IF NOT EXISTS articles (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    default_locale TEXT    NOT NULL DEFAULT 'en',
    published      INTEGER NOT NULL DEFAULT 0,
    created_at     TEXT    NOT NULL,
    updated_at     TEXT    NOT NULL
);

CREATE TABLE IF NOT EXISTS article_translations (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    article_id INTEGER NOT NULL REFERENCES articles(id),
    locale     TEXT    NOT NULL,
    title      TEXT    NOT NULL,
    body       TEXT    NOT NULL,
    created_at TEXT    NOT NULL,
    updated_at TEXT    NOT NULL,
    UNIQUE(article_id, locale)
);
```

Choix de design clés :
- `published` est stocké comme `INTEGER` (booléen SQLite : 0/1) ; PHP le lit via `(bool) $row['published']`.
- `UNIQUE(article_id, locale)` applique au plus une traduction par locale par article.
- Pas de validation de langue dans la DB — la couche application applique le format BCP 47.
- `article_translations.body` est du texte brut ; les appelants de l'API JSON sont responsables de l'assainissement avant le rendu en HTML.

---

## ATK — Test d'attaque mentalité cracker (FT232)

### ATK-01 — Pas d'authentification sur aucun endpoint

**Attaque** : Créer ou modifier des articles sans aucune accréditation.

```bash
curl -s -X POST http://localhost:8080/articles \
  -H 'Content-Type: application/json' \
  -d '{"default_locale":"en","published":true}'
```

**Observé** : `201 Created` — pas de token requis. N'importe quel appelant peut créer, traduire ou publier des articles.

**Verdict** : **EXPOSED** (par conception pour la démo FT232). Ajouter authentification et autorisation en production. Protéger `POST /articles` et `PUT .../translations/{locale}` derrière un rôle rédacteur ou admin.

---

### ATK-02 — Traversée de chemin dans le paramètre locale

**Attaque** : Utiliser des chaînes de traversée de chemin ou métacaractères shell comme paramètre de chemin `{locale}`.

```
PUT /articles/1/translations/../../etc/passwd
PUT /articles/1/translations/../admin
PUT /articles/1/translations/%2F%2Fetc
```

**Observé** : La regex BCP 47 `/^[a-z]{2}(-[A-Z]{2})?$/` rejette tous ces cas — aucun ne correspond à deux lettres minuscules (optionnellement suivi d'un tiret et deux lettres majuscules). Réponse : `422 Unprocessable Entity`.

**Verdict** : **BLOCKED** — regex stricte ancrée avec `^` et `$` rejette les séquences de traversée.

---

### ATK-03 — Injection SQL via paramètre locale

**Attaque** : Incorporer des métacaractères SQL dans la valeur `{locale}`.

```
PUT /articles/1/translations/en'; DROP TABLE articles; --
PUT /articles/1/translations/en" OR "1"="1
```

**Observé** :
1. La regex BCP 47 rejette immédiatement ces chaînes → `422` avant qu'aucun SQL ne s'exécute.
2. Même si la regex était contournée, la locale est passée comme valeur `?` paramétrée — pas de concaténation de chaîne avec SQL.

**Verdict** : **BLOCKED** — double couche : liste blanche regex + requêtes paramétrées.

---

### ATK-04 — IDOR : traduire l'article d'un autre utilisateur

**Attaque** : Écrire une traduction pour un article que l'attaquant n'a pas créé.

```bash
# L'attaquant connaît l'ID d'article 1 créé par un autre utilisateur
curl -s -X PUT http://localhost:8080/articles/1/translations/fr \
  -H 'Content-Type: application/json' \
  -d '{"title":"Hacked","body":"Attacker content"}'
```

**Observé** : `200 OK` — la traduction est acceptée et écrase toute traduction française existante. Aucune vérification de propriété n'existe.

**Verdict** : **EXPOSED** — pas de modèle de propriété. Ajouter une colonne `created_by` et comparer avec l'appelant authentifié avant d'autoriser les écritures.

---

### ATK-05 — Titre ou corps contenant uniquement des espaces

**Attaque** : Envoyer un titre ou corps qui est vide après trimming.

```json
{"title": "   ", "body": "\t\n"}
```

**Observé** : `trim()` réduit les deux à des chaînes vides. Les deux champs sont ajoutés à `$errors`. Réponse : `422 Unprocessable Entity` avec erreurs de champ structurées.

**Verdict** : **BLOCKED** — `trim()` avant la vérification de chaîne vide gère l'entrée contenant uniquement des espaces.

---

### ATK-06 — Payload XSS dans le titre ou corps

**Attaque** : Stocker une balise script dans un champ de traduction.

```json
{"title": "<script>alert(1)</script>", "body": "<img src=x onerror=alert(1)>"}
```

**Observé** : Le contenu est stocké tel quel et retourné verbatim en JSON. L'API elle-même n'encode pas la sortie en HTML — c'est une API JSON, pas un moteur de rendu HTML.

**Verdict** : **ACCEPTED BY DESIGN** — les APIs JSON retournent du contenu brut ; la couche de rendu (navigateur, application mobile) est responsable de l'échappement HTML. Documenter cela clairement dans la spécification API pour que les consommateurs ne rendent pas de contenu non fiable sans assainissement.

---

### ATK-07 — Longueur illimitée du titre ou corps

**Attaque** : Envoyer un titre ou corps de plusieurs mégaoctets.

```python
{"title": "A" * 1_000_000, "body": "B" * 5_000_000}
```

**Observé** : Aucune limite de longueur n'est appliquée — les très grands payloads sont stockés et retournés. L'utilisation mémoire et I/O évolue avec la taille du payload. TEXT SQLite n'a pas de limite de taille pratique.

**Verdict** : **EXPOSED** — ajouter une vérification `maxlength` :
```php
if (mb_strlen($title) > 500) {
    $errors[] = ['field' => 'title', 'code' => 'too_long', 'message' => 'title must not exceed 500 characters.'];
}
if (mb_strlen($text) > 50000) {
    $errors[] = ['field' => 'body', 'code' => 'too_long', 'message' => 'body must not exceed 50 000 characters.'];
}
```
Appliquer aussi un middleware de taille de requête pour plafonner les octets de corps totaux avant l'analyse.

---

### ATK-08 — Contournement de casse et séparateur BCP 47

**Attaque** : Essayer des variantes sémantiquement similaires mais syntaxiquement incorrectes.

```
PUT /articles/1/translations/EN        → code de langue en majuscules
PUT /articles/1/translations/en_US     → séparateur tiret bas (style POSIX)
PUT /articles/1/translations/en-us     → région en minuscules
PUT /articles/1/translations/EN-us     → casse mixte
PUT /articles/1/translations/fra       → code ISO 639-2 à trois lettres
```

**Observé** : Tous rejetés par `/^[a-z]{2}(-[A-Z]{2})?$/` :
- `EN` — échoue `[a-z]`
- `en_US` — `_` échoue `(-[A-Z]{2})?`
- `en-us` — `us` échoue `[A-Z]`
- `fra` — trois caractères échouent `{2}` exactement

**Verdict** : **BLOCKED** — la regex est précise ; seules les formes BCP 47 exactes `ll` ou `ll-RR` passent.

---

### ATK-09 — Traduction pour un article inexistant

**Attaque** : Cibler un ID d'article qui n'existe pas.

```bash
curl -s -X PUT http://localhost:8080/articles/99999/translations/en \
  -H 'Content-Type: application/json' \
  -d '{"title":"Ghost","body":"Body"}'
```

**Observé** : `findById(99999)` retourne `null`. Le handler retourne `404 Not Found` avant de traiter le corps.

**Verdict** : **BLOCKED** — l'existence de l'article est vérifiée avant que la traduction ne soit écrite.

---

### ATK-10 — Manipulation de publication sans auth

**Attaque** : Créer un article comme publié pour contourner la revue brouillon.

```json
{"default_locale": "en", "published": true}
```

**Observé** : `201 Created` — `published: true` est accepté immédiatement. Aucune revue de brouillon ni validation d'approbation n'existe ; n'importe quel appelant peut publier.

**Verdict** : **EXPOSED** (même cause racine qu'ATK-01). Une action de publication devrait nécessiter au minimum un rôle rédacteur. Séparer le flag `published` du payload de création — nécessiter une action explicite `POST /articles/{id}/publish` protégée par autorisation.

---

### ATK-11 — `?locale=` avec locale inconnue se replie silencieusement

**Attaque** : Demander un article avec une locale pour laquelle aucune traduction n'est stockée.

```
GET /articles/1?locale=zh-TW
```

**Observé** : `getTranslationWithFallback('zh-TW')` ne trouve pas de traduction chinoise et se replie sur `default_locale` (ex: `en`). Le champ `locale` dans la réponse affiche `en` — indiquant qu'un fallback a eu lieu. Aucun 404 ni erreur n'est retourné.

**Verdict** : **ACCEPTED BY DESIGN** — le fallback silencieux est correct pour la livraison de contenu. Les appelants peuvent détecter le fallback en comparant la locale demandée avec `locale` dans la réponse. Si l'application stricte de locale est nécessaire, ajouter un paramètre `?strict=1`.

---

### ATK-12 — ID d'article non numérique

**Attaque** : Passer une chaîne ou float comme ID d'article.

```
GET /articles/abc
GET /articles/1.5
GET /articles/0x10
```

**Observé** :
- `GET /articles/abc` → Le Router correspond au paramètre `{id}` ; `(int) 'abc'` = `0`. `findById(0)` retourne `null` → `404 Not Found`.
- `GET /articles/1.5` → `(int) '1.5'` = `1`. Si l'article 1 existe, il est retourné. C'est une troncation silencieuse, pas une erreur.

**Verdict** : **PARTIELLEMENT BLOCKED** — les chaînes non numériques se résolvent en 0 et retournent 404. Les floats sont silencieusement tronqués. Pour une validation stricte, ajouter :
```php
if (!ctype_digit((string) ($params['id'] ?? ''))) {
    return $this->problems->create($request, 'validation-failed', 'Validation Failed', 422, null, [
        'errors' => [['field' => 'id', 'code' => 'invalid', 'message' => 'id must be a positive integer.']],
    ]);
}
```

---

## Résumé ATK

| # | Vecteur d'attaque | Verdict |
|---|-------------------|---------|
| ATK-01 | Pas d'authentification | EXPOSED |
| ATK-02 | Traversée de chemin dans locale | BLOCKED |
| ATK-03 | Injection SQL via locale | BLOCKED |
| ATK-04 | IDOR : traduire un autre article | EXPOSED |
| ATK-05 | Titre/corps contenant uniquement des espaces | BLOCKED |
| ATK-06 | XSS dans titre/corps | ACCEPTED BY DESIGN |
| ATK-07 | Longueur illimitée titre/corps | EXPOSED |
| ATK-08 | Contournement casse/séparateur BCP 47 | BLOCKED |
| ATK-09 | Traduction pour article inexistant | BLOCKED |
| ATK-10 | Publication sans auth | EXPOSED |
| ATK-11 | `?locale=` inconnu se replie silencieusement | ACCEPTED BY DESIGN |
| ATK-12 | ID d'article non numérique | PARTIELLEMENT BLOCKED |

**Vraies vulnérabilités à corriger avant la production** :
1. **ATK-01 / ATK-04 / ATK-10** — Ajouter authentification, vérifications de propriété et une action de publication séparée
2. **ATK-07** — Ajouter des limites de longueur pour le titre et le corps
3. **ATK-12** — Ajouter un garde `ctype_digit()` pour les paramètres d'ID

---

## Howtos associés

- [`approval-workflow.md`](approval-workflow.md) — machine à états pour la revue de contenu avant publication
- [`bulk-status-update.md`](bulk-status-update.md) — patterns de mutation en masse avec succès partiel
- [`media-watchlist.md`](media-watchlist.md) — statut backed par enum et champs nullable optionnels
