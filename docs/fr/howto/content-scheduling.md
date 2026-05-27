# Planification de contenu — Publication temporisée avec états de cycle de vie

Planifiez la publication de contenu à une date/heure future en utilisant une colonne `publish_at`, une machine à états (`draft → scheduled → published → archived`) et un endpoint **déclencheur de publication** qu'un job cron appelle pour basculer les articles échus.

**Implémentation de référence :** `FT172 pubschedulelog` dans [hideyukiMORI/NENE2-examples](https://github.com/hideyukiMORI/NENE2-examples)

---

## Cycle de vie du statut

```
draft ──┬──► scheduled ──► published ──► archived
        │                               ▲
        └───────────────────────────────┘
        (aussi : scheduled → draft via unschedule)
```

| Depuis | Transitions autorisées |
|---|---|
| `draft` | `scheduled`, `published`, `archived` |
| `scheduled` | `published`, `draft`, `archived` |
| `published` | `archived` |
| `archived` | *(aucune)* |

---

## Schéma

```sql
CREATE TABLE articles (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    author_id    INTEGER NOT NULL,
    title        TEXT    NOT NULL,
    body         TEXT    NOT NULL,
    status       TEXT    NOT NULL DEFAULT 'draft',
    -- 'draft' | 'scheduled' | 'published' | 'archived'
    publish_at   TEXT,    -- ISO 8601 ; défini lors de la planification ; NULL sinon
    published_at TEXT,    -- défini lors de la publication effective
    created_at   TEXT    NOT NULL,
    updated_at   TEXT    NOT NULL
);
```

---

## Endpoints

| Méthode | Chemin | Auth | Description |
|---------|--------|------|-------------|
| `POST` | `/articles` | X-User-Id | Créer un brouillon |
| `GET` | `/articles` | optionnel | Lister (`?status=published` est public ; les autres statuts nécessitent auth + ses propres articles uniquement) |
| `GET` | `/articles/{id}` | optionnel | Obtenir un article (published = public, draft/scheduled = propriétaire uniquement) |
| `PUT` | `/articles/{id}` | X-User-Id | Mettre à jour titre/corps (draft ou scheduled uniquement) |
| `POST` | `/articles/{id}/schedule` | X-User-Id | Définir `publish_at` → passe à `scheduled` |
| `POST` | `/articles/{id}/unschedule` | X-User-Id | Annuler la planification → revient à `draft` |
| `POST` | `/articles/{id}/publish` | X-User-Id | Publier immédiatement |
| `POST` | `/articles/{id}/archive` | X-User-Id | Archiver |
| `POST` | `/articles/publish-due` | X-Admin-Key | Publication en masse de tous les articles planifiés échus |

---

## Patterns principaux

### Enum de statut avec garde de transition

```php
enum ArticleStatus: string {
    case Draft     = 'draft';
    case Scheduled = 'scheduled';
    case Published = 'published';
    case Archived  = 'archived';

    public function canTransitionTo(self $next): bool {
        return match ($this) {
            self::Draft     => in_array($next, [self::Scheduled, self::Published, self::Archived], true),
            self::Scheduled => in_array($next, [self::Published, self::Draft, self::Archived], true),
            self::Published => $next === self::Archived,
            self::Archived  => false,
        };
    }
}
```

### Planification : Validation uniquement dans le futur

```php
$ts = strtotime($publishAt);
if ($ts === false || $ts === -1) {
    throw new ArticleScheduleException('publish_at is not a valid datetime.');
}
if ($ts <= strtotime($now)) {
    throw new ArticleScheduleException('publish_at must be in the future.');
}
```

### Déclencheur de publication échus (sûr pour cron, idempotent)

```php
public function publishDue(string $now): array
{
    $rows = $this->db->fetchAll(
        "SELECT id FROM articles WHERE status = ? AND publish_at <= ? ORDER BY publish_at",
        [ArticleStatus::Scheduled->value, $now],
    );

    $published = [];
    foreach ($rows as $row) {
        $id = (int) $row['id'];
        $this->db->execute(
            'UPDATE articles SET status = ?, published_at = ?, publish_at = NULL, updated_at = ? WHERE id = ?',
            [ArticleStatus::Published->value, $now, $now, $id],
        );
        $published[] = $id;
    }

    return $published;  // list<int>
}
```

À appeler depuis un job cron toutes les minutes. Idempotent : réexécuter immédiatement ne trouve pas de nouveaux articles échus puisque `publish_at` est mis à `NULL` lors de la publication.

### Prévention IDOR

Les articles en brouillon et planifiés sont **réservés au propriétaire** — retourner 404 (pas 403) pour éviter de révéler l'existence :

```php
if ($article->authorId !== $actorId) {
    throw new ArticleNotFoundException($id);  // 404, pas 403
}
```

### Clé admin — Comparaison sûre dans le temps

```php
if ($apiKey === '' || !hash_equals($expected, $apiKey)) {
    return $this->responseFactory->create(['error' => 'unauthorized'], 401);
}
```

Ne jamais utiliser `!==` pour les comparaisons de secrets — utiliser `hash_equals()` pour empêcher les attaques par timing.

---

## Notes de sécurité

| Risque | Atténuation |
|---|---|
| Injection de `publish_at` dans le passé | `strtotime($publishAt) <= strtotime($now)` → 422 |
| Mutation d'état inter-utilisateurs | Vérification de propriété avant chaque transition ; 404 pas 403 |
| Injection d'ID d'auteur via le corps | `authorId` pris uniquement depuis l'en-tête `X-User-Id` |
| Injection de statut via le corps | Le champ `status` dans le corps PUT est ignoré ; transitions via des endpoints d'action dédiés |
| Attaque par timing sur la clé admin | `hash_equals()` au lieu de `!==` |
| Énumération d'articles non publiés | Le listing public filtre toujours par `status = published` ; non-publié nécessite auth + ses propres articles uniquement |
| Édition après publication | PUT rejette les articles non-draft/scheduled avec 422 |
| Double archivage | La garde de transition retourne 409 pour les transitions invalides |

---

## Intégration cron

```bash
# /etc/cron.d/publish-due
* * * * * www-data curl -s -X POST https://api.example.com/articles/publish-due \
  -H "X-Admin-Key: $ADMIN_KEY"
```

Pour des workloads à plus grand volume, passer à une file de jobs (voir [job-queue.md](./job-queue.md)) et laisser le worker de file appeler `publishDue()`.

---

## Voir aussi

- [Cycle de vie de brouillon de contenu](./content-draft-lifecycle.md) — draft/actif/archivé sans planification
- [File de jobs](./job-queue.md) — traitement en arrière-plan pour les déclencheurs de publication à grand volume
- [Suppression douce](./soft-delete.md) — complément à l'archivage
- [Piste d'audit](./audit-trail.md) — enregistrer qui a publié quoi et quand
