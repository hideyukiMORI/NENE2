# Gestion des slugs — Slugs d'URL uniques avec résolution de collision et historique

Générer des slugs sûrs pour les URL à partir de titres, résoudre les collisions automatiquement, et maintenir une **table d'historique des slugs** pour que les anciens slugs redirigent vers l'URL canonique sans casser les liens entrants.

**Implémentation de référence :** `FT174 sluglog` dans [hideyukiMORI/NENE2-examples](https://github.com/hideyukiMORI/NENE2-examples)

---

## Schéma

```sql
CREATE TABLE articles (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    title      TEXT    NOT NULL,
    slug       TEXT    NOT NULL UNIQUE,   -- slug canonique actuel
    body       TEXT    NOT NULL,
    created_at TEXT    NOT NULL,
    updated_at TEXT    NOT NULL
);

-- Anciens slugs conservés pour le support des redirections
CREATE TABLE slug_history (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    article_id  INTEGER NOT NULL,
    old_slug    TEXT    NOT NULL UNIQUE,  -- source de redirection ; UNIQUE empêche les doublons
    replaced_at TEXT    NOT NULL,
    FOREIGN KEY (article_id) REFERENCES articles(id)
);
```

---

## Génération de slug

```php
final class SlugHelper
{
    public static function fromTitle(string $title): string
    {
        $slug = mb_strtolower($title);
        $slug = (string) preg_replace('/[^a-z0-9]+/', '-', $slug);
        $slug = trim($slug, '-');
        return $slug !== '' ? $slug : 'untitled';
    }

    /**
     * @param callable(string): bool $exists  Retourne true si le slug est pris.
     */
    public static function makeUnique(string $base, callable $exists): string
    {
        if (!$exists($base)) {
            return $base;
        }
        $counter = 2;
        while ($exists("{$base}-{$counter}")) {
            $counter++;
        }
        return "{$base}-{$counter}";
    }
}
```

### Vérification d'unicité — Inclure les deux tables

Lors de la vérification qu'un slug est "pris", vérifier **à la fois** `articles.slug` et `slug_history.old_slug`. Sinon, un nouvel article pourrait revendiquer un slug encore utilisé comme source de redirection :

```php
private function slugExists(string $slug): bool
{
    return $this->db->fetchOne('SELECT id FROM articles WHERE slug = ?', [$slug]) !== null
        || $this->db->fetchOne('SELECT id FROM slug_history WHERE old_slug = ?', [$slug]) !== null;
}
```

---

## Recherche de slug avec indice de redirection

```php
public function findBySlugWithRedirect(string $slug): ?array
{
    // 1. Vérifier la colonne du slug actuel (200 OK)
    $article = $this->findBySlug($slug);
    if ($article !== null) {
        return ['found' => $article, 'redirect' => false];
    }

    // 2. Vérifier l'historique des slugs (indice de redirection 301)
    $row = $this->db->fetchOne(
        'SELECT article_id FROM slug_history WHERE old_slug = ?', [$slug],
    );
    if ($row === null) {
        return null;  // 404
    }

    $article = $this->findById((int) $row['article_id']);
    return $article !== null ? ['found' => $article, 'redirect' => true] : null;
}
```

Le handler retourne ensuite HTTP 301 avec `canonical_slug` et `data` :

```json
// GET /articles/by-slug/old-title  →  301
{
  "redirect": true,
  "canonical_slug": "new-title",
  "data": { "id": 1, "slug": "new-title", ... }
}
```

---

## Mise à jour du slug — Enregistrer l'historique

Quand un article est renommé, déplacer l'ancien slug vers `slug_history` :

```php
if ($newSlug !== $article->slug) {
    // Insérer uniquement si pas déjà dans l'historique (idempotent)
    $alreadyIn = $this->db->fetchOne(
        'SELECT id FROM slug_history WHERE old_slug = ?', [$article->slug],
    );
    if ($alreadyIn === null) {
        $this->db->insert(
            'INSERT INTO slug_history (article_id, old_slug, replaced_at) VALUES (?, ?, ?)',
            [$id, $article->slug, $now],
        );
    }
}
```

### Gestion des collisions lors de la mise à jour

Lors du calcul du nouveau slug pour un article mis à jour, exclure le slug **actuel** de l'article de la vérification "exists" — sinon il s'incrémenterait inutilement à `-2` :

```php
$newSlug = SlugHelper::makeUnique(
    $newSlugBase,
    fn (string $s): bool => $s !== $article->slug && $this->slugExists($s),
);
```

---

## Endpoints

| Méthode | Chemin | Description |
|---|---|---|
| `POST` | `/articles` | Créer un article — slug auto-dérivé du titre |
| `GET` | `/articles/{id}` | Obtenir par ID numérique |
| `GET` | `/articles/by-slug/{slug}` | Obtenir par slug (200 actuel / 301 historique / 404) |
| `PUT` | `/articles/{id}` | Mettre à jour titre/corps/slug ; ancien slug → historique |
| `GET` | `/articles/{id}/slug-history` | Lister les slugs historiques |

---

## Scénarios de collision

| Scénario | Résultat |
|---|---|
| Premier "Hello World" | `hello-world` |
| Deuxième "Hello World" | `hello-world-2` |
| Troisième "Hello World" | `hello-world-3` |
| Article renommé de `hello` vers un slug déjà pris | `taken-slug-2` |
| Même titre, pas de changement de slug | Pas d'entrée dans l'historique, slug inchangé |
| Ancien slug correspond à une entrée historique | Réponse de redirection 301 |

---

## Structure de la couche domaine

```
src/Article/
├── Article.php
├── ArticleRepository.php   # create / findBySlug / findBySlugWithRedirect / update / slugHistory
├── SlugHelper.php          # fromTitle() + makeUnique()
└── ArticleNotFoundException.php
```

---

## Voir aussi

- [Suppression douce](./soft-delete.md) — combiner l'historique des slugs avec les enregistrements soft-supprimés
- [Versionnement de contenu](./content-versioning.md) — historique des versions aux côtés de l'historique des slugs
- [Cycle de vie des brouillons](./content-draft-lifecycle.md) — comportement des slugs à travers les états de brouillon
