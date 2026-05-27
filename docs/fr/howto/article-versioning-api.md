# How-to : API de versionnage d'articles

> **Référence FT** : FT249 (`NENE2-FT/contentvlog`) — API de versionnage d'articles
> **VULN** : FT249 — évaluation de vulnérabilité (V-01 à V-10)

Démontre un système de versionnage d'articles où une colonne entière `current_version`
sur la table `articles` trace la dernière version, chaque mise à jour s'ajoute à
`article_versions`, et le retour arrière crée une nouvelle version à partir du contenu historique.
Inclut une évaluation des vulnérabilités de la conception non authentifiée.

---

## Routes

| Méthode | Chemin | Description |
|--------|-----------------------------------|------------------------------------------------------|
| `POST` | `/articles` | Créer un article (version 1) |
| `GET` | `/articles/{id}` | Obtenir un article (contenu actuel) |
| `PUT` | `/articles/{id}` | Mettre à jour un article (crée une nouvelle version) |
| `GET` | `/articles/{id}/versions` | Lister l'historique des versions (métadonnées uniquement) |
| `GET` | `/articles/{id}/versions/{version}` | Obtenir une version spécifique |
| `POST` | `/articles/{id}/rollback` | Retour arrière à une version (crée une nouvelle version) |

---

## Schéma : colonne entière `current_version`

```sql
CREATE TABLE articles (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    title           TEXT    NOT NULL,
    body            TEXT    NOT NULL,
    current_version INTEGER NOT NULL DEFAULT 1,
    created_at      TEXT    NOT NULL,
    updated_at      TEXT    NOT NULL
);

CREATE TABLE article_versions (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    article_id INTEGER NOT NULL,
    version    INTEGER NOT NULL,
    title      TEXT    NOT NULL,
    body       TEXT    NOT NULL,
    created_at TEXT    NOT NULL,
    UNIQUE (article_id, version),
    FOREIGN KEY (article_id) REFERENCES articles(id)
);
```

La colonne `current_version` stocke le numéro de version du contenu actuel.
`UNIQUE(article_id, version)` empêche les numéros de version en doublon pour le même article.

**Comparaison avec l'approche du flag `is_current`** (voir `document-versioning.md`) :

| Approche | Entier `current_version` | Flag `is_current` |
|---|---|---|
| Schéma | Colonne sur la table `articles` | Colonne sur la table `versions` |
| Recherche de la version actuelle | `SELECT * FROM articles WHERE id = ?` (pas de JOIN) | `LEFT JOIN ... ON dv.is_current = 1` |
| Suivi du numéro de version | Entier explicite sur la ligne parente | Implicite depuis le comptage des lignes ou MAX |
| Atomicité | Mettre à jour l'article + insérer la version (2 écritures) | Mettre à jour le flag + INSERT (2 écritures) |

---

## Création : initialisation en deux écritures

Créer un article écrit dans les deux tables :

```php
$id = $this->db->insert(
    'INSERT INTO articles (title, body, current_version, created_at, updated_at) VALUES (?, ?, 1, ?, ?)',
    [$title, $body, $now, $now],
);
$this->db->insert(
    'INSERT INTO article_versions (article_id, version, title, body, created_at) VALUES (?, 1, ?, ?, ?)',
    [$id, $title, $body, $now],
);
```

Les deux écritures se produisent sans transaction englobante. Si la seconde insertion échoue,
la ligne `articles` existe mais `article_versions` n'a pas d'entrée correspondante — l'article
est à la version 1 sans enregistrement d'historique. Envelopper les deux dans `$txManager->transactional()`
pour un usage en production.

---

## Mise à jour : pattern lecture-puis-incrément

```php
public function update(int $id, string $title, string $body, string $now): bool
{
    $article = $this->find($id);
    if ($article === null) {
        return false;
    }
    $nextVersion = (int) $article['current_version'] + 1;

    $this->db->insert(
        'UPDATE articles SET title = ?, body = ?, current_version = ?, updated_at = ? WHERE id = ?',
        [$title, $body, $nextVersion, $now, $id],
    );
    $this->db->insert(
        'INSERT INTO article_versions (article_id, version, title, body, created_at) VALUES (?, ?, ?, ?, ?)',
        [$id, $nextVersion, $title, $body, $now],
    );
    return true;
}
```

Le numéro de version est lu, incrémenté en PHP, puis réécrit. Sans transaction,
les mises à jour concurrentes peuvent produire des numéros de version en doublon — la
contrainte `UNIQUE(article_id, version)` détectera ceci, mais l'`UPDATE` vers
`articles` peut réussir avant que l'`INSERT` vers `article_versions` échoue, laissant
le `current_version` de l'article en avance sur son historique.

---

## Retour arrière : non-destructif (copie comme nouvelle version)

```php
public function rollback(int $id, int $version, string $now): bool
{
    $target = $this->findVersion($id, $version);
    if ($target === null) {
        return false;
    }
    $article     = $this->find($id);
    $nextVersion = (int) $article['current_version'] + 1;
    $title       = (string) $target['title'];
    $body        = (string) $target['body'];

    $this->db->insert(
        'UPDATE articles SET title = ?, body = ?, current_version = ?, updated_at = ? WHERE id = ?',
        [$title, $body, $nextVersion, $now, $id],
    );
    $this->db->insert(
        'INSERT INTO article_versions (article_id, version, title, body, created_at) VALUES (?, ?, ?, ?, ?)',
        [$id, $nextVersion, $title, $body, $now],
    );
    return true;
}
```

Le retour arrière ne supprime pas les versions — il copie le contenu de la version cible
comme une nouvelle version (actuelle). L'historique est toujours préservé. Si un article est à
la version 5 et revient à la version 2 :

```
v1 → v2 → v3 → v4 → v5 → v6 (copie du contenu de v2)
```

---

## Liste de versions : métadonnées uniquement (sans body)

`GET /articles/{id}/versions` retourne les métadonnées des versions sans le body complet :

```php
$this->db->fetchAll(
    'SELECT id, article_id, version, title, created_at FROM article_versions
     WHERE article_id = ? ORDER BY version ASC',
    [$articleId],
);
```

`body` est exclu de la liste — les appelants doivent récupérer des versions individuelles avec
`GET /articles/{id}/versions/{version}` pour obtenir le contenu. Cela évite d'envoyer
un contenu potentiellement volumineux dans la réponse de liste.

---

## VULN — Évaluation des vulnérabilités (FT249)

### V-01 — Pas d'authentification : n'importe quel appelant peut mettre à jour ou supprimer n'importe quel article

**Risque** : Tous les endpoints ne sont pas authentifiés.

**Impact** : Un attaquant peut écraser n'importe quel article, revenir à une version précédente de son contenu,
ou énumérer tout l'historique des versions.

**Verdict** : **EXPOSÉ** — ajouter l'authentification (clé API, JWT ou session). La mise à jour/retour arrière
devrait nécessiter que le propriétaire de l'article soit authentifié.

---

### V-02 — Pas de propriété : n'importe quel utilisateur authentifié peut modifier n'importe quel article

**Risque** : Même avec l'authentification, il n'y a pas de requête scopée par propriétaire. Tout utilisateur authentifié
peut mettre à jour l'article de n'importe quel autre utilisateur.

**Impact** : Sans `WHERE id = ? AND owner_id = ?`, les IDs d'article sont énumérables et
modifiables par quiconque a un token valide.

**Verdict** : **EXPOSÉ** — ajouter une colonne `owner_id` à `articles`. Appliquer la propriété
avec `WHERE id = ? AND owner_id = ?` dans toutes les opérations d'écriture.

---

### V-03 — IDOR : lire l'historique des versions d'un autre utilisateur

**Risque** : `GET /articles/{id}/versions` retourne tout l'historique des versions pour n'importe quel ID d'article.

**Impact** : Un attaquant peut énumérer l'historique des brouillons que l'auteur n'avait peut-être pas
l'intention de rendre public.

**Verdict** : **EXPOSÉ** — scopez toutes les lectures par propriétaire : seul le propriétaire de l'article (ou les rôles
avec une permission de lecture explicite) devrait voir l'historique des versions.

---

### V-04 — Condition de course sur l'incrément du numéro de version

**Risque** : `update()` lit `current_version`, incrémente en PHP, puis réécrit.
Aucune transaction ni verrou de ligne n'enveloppe la séquence lecture-écriture.

**Attaque** : Deux requêtes `PUT /articles/1` concurrentes lisent toutes les deux `current_version = 3`.
Les deux calculent `nextVersion = 4`. L'une réussit (insère la version 4) ; l'autre échoue
à la contrainte `UNIQUE(article_id, version)` — mais l'`UPDATE articles` peut avoir
déjà réussi, définissant `current_version = 4` pour les deux, avec seulement un enregistrement de version
dans l'historique.

**Verdict** : **EXPOSÉ** — envelopper `find` + `UPDATE` + `INSERT` dans une transaction DB.
Utiliser `UPDATE articles SET current_version = current_version + 1` pour un incrément atomique.

---

### V-05 — Injection SQL via title ou body

**Attaque** : Intégrer des métacaractères SQL.

```json
{"title": "'; DROP TABLE articles; --", "body": "x"}
```

**Observé** : Les valeurs sont liées comme paramètres `?` paramétrés. L'injection est stockée
comme texte littéral.

**Verdict** : **BLOQUÉ** — les requêtes paramétrées empêchent l'injection SQL.

---

### V-06 — Énumération des versions : accès à l'historique non limité

**Risque** : `GET /articles/{id}/versions` retourne l'historique complet des versions sans
pagination ni limite.

**Impact** : Un article avec des milliers de versions retourne toutes les lignes dans une seule réponse,
causant une pression mémoire et des requêtes lentes.

**Verdict** : **EXPOSÉ** — ajouter la pagination (`LIMIT ? OFFSET ?`) à l'endpoint de liste
des versions. Envisager de plafonner le nombre maximum de versions par article.

---

### V-07 — Opérations à deux écritures non transactionnelles

**Risque** : `create()` et `update()` effectuent deux écritures séquentielles sans
transaction DB englobante.

**Impact** : Si la seconde écriture échoue (ex. violation de contrainte, erreur de connexion),
le système se retrouve dans un état incohérent : `articles.current_version` peut différer du
comptage des lignes `article_versions`, ou un article peut exister sans enregistrement de version.

**Verdict** : **EXPOSÉ** — envelopper les écritures appariées dans `DatabaseTransactionManagerInterface::transactional()`.

---

### V-08 — Retour arrière vers une version d'un autre article

**Attaque** : Soumettre un retour arrière avec un numéro de `version` qui existe pour un
article différent.

```bash
# L'article 1 a les versions 1-3 ; L'article 2 a la version 1
POST /articles/1/rollback  {"version": 1}
```

**Observé** : `findVersion(articleId=1, version=1)` utilise `WHERE article_id = ? AND version = ?`
— il ne trouve que les versions appartenant à l'article 1. Une version qui existe pour l'article 2
n'est pas retournée.

**Verdict** : **BLOQUÉ** — la recherche de version est scopée par `article_id`.

---

### V-09 — Corps volumineux : pas de limite de taille sur le contenu de l'article

**Risque** : `body` accepte des chaînes de longueur arbitraire sans validation.

**Impact** : Les corps de plusieurs mégaoctets consomment du stockage et de la mémoire à chaque lecture.

**Verdict** : **EXPOSÉ** — ajouter une vérification de longueur de corps (ex. `strlen($body) > 1_000_000 → 422`).
S'appuyer sur le middleware de taille de requête comme limite externe.

---

### V-10 — Retour arrière vers `version = 0` ou version négative

**Attaque** : Soumettre un retour arrière avec la version 0 ou -1.

```json
{"version": 0}
{"version": -1}
```

**Observé** : `(int) $body['version']` accepte n'importe quel entier. `findVersion($id, 0)` et
`findVersion($id, -1)` retournent `null` (pas une telle version) → `404 Not Found`. Aucune version 0
n'est jamais stockée (les versions commencent à 1).

**Verdict** : **BLOQUÉ** — `findVersion` retourne `null` pour les versions inexistantes ;
aucun cas particulier n'est nécessaire.

---

## Résumé VULN

| # | Vulnérabilité | Verdict |
|---|---------------|---------|
| V-01 | Pas d'authentification sur les endpoints d'écriture | EXPOSÉ |
| V-02 | Pas de vérification de propriété (n'importe quel utilisateur peut modifier n'importe quel article) | EXPOSÉ |
| V-03 | IDOR sur l'historique des versions | EXPOSÉ |
| V-04 | Condition de course sur l'incrément du numéro de version | EXPOSÉ |
| V-05 | Injection SQL via title/body | BLOQUÉ |
| V-06 | Liste de versions non limitée (pas de pagination) | EXPOSÉ |
| V-07 | Écritures appariées non transactionnelles | EXPOSÉ |
| V-08 | Retour arrière vers la version d'un autre article | BLOQUÉ |
| V-09 | Pas de limite de taille pour le body | EXPOSÉ |
| V-10 | Retour arrière vers la version 0 / négative | BLOQUÉ |

**Corrections critiques avant la production** :
1. **V-01 / V-02 / V-03** — Ajouter l'authentification et l'application de la propriété `owner_id`
2. **V-04 / V-07** — Envelopper toutes les opérations multi-écriture dans `transactional()` ; utiliser un incrément de version atomique
3. **V-06** — Ajouter la pagination `LIMIT ? OFFSET ?` à la liste des versions
4. **V-09** — Ajouter la validation de la taille du body

---

## Howtos associés

- [`document-versioning.md`](document-versioning.md) — approche du flag `is_current` avec `DatabaseTransactionManagerInterface`
- [`content-versioning.md`](content-versioning.md) — versionnage de contenu avec des numéros de version linéaires
- [`transactions.md`](transactions.md) — patterns DatabaseTransactionManagerInterface
- [`optimistic-locking.md`](optimistic-locking.md) — prévention des conditions de course avec colonne de version + UPDATE conditionnel
