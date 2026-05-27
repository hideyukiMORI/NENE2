# How-to : API de sondage / enquête

Ce guide montre comment créer un système de sondage et d'enquête avec prévention des votes dupliqués en utilisant NENE2.
Pattern démontré par le field trial **polllog** (FT217).

## Fonctionnalités

- Créer des sondages avec 2–20 options (admin uniquement)
- Sondages publics et privés (privé : accès admin uniquement)
- Un vote par utilisateur par sondage (appliqué par contrainte UNIQUE)
- Agrégation de résultats en direct avec comptage des votes par option
- Somme totale des votes sur toutes les options

## Schéma

```sql
CREATE TABLE IF NOT EXISTS polls (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    question   TEXT    NOT NULL,
    is_public  INTEGER NOT NULL DEFAULT 1,
    created_at TEXT    NOT NULL
);

CREATE TABLE IF NOT EXISTS poll_options (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    poll_id    INTEGER NOT NULL,
    label      TEXT    NOT NULL,
    sort_order INTEGER NOT NULL DEFAULT 0,
    FOREIGN KEY (poll_id) REFERENCES polls(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS votes (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    poll_id    INTEGER NOT NULL,
    option_id  INTEGER NOT NULL,
    user_id    INTEGER NOT NULL,
    created_at TEXT    NOT NULL,
    UNIQUE (poll_id, user_id),  -- Un vote par utilisateur par sondage
    FOREIGN KEY (poll_id) REFERENCES polls(id) ON DELETE CASCADE,
    FOREIGN KEY (option_id) REFERENCES poll_options(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_votes_poll ON votes (poll_id, option_id);
```

## Endpoints

| Méthode | Chemin | Auth | Description |
|---------|--------|------|-------------|
| `POST` | `/polls` | Admin | Créer un sondage avec options |
| `GET` | `/polls/{id}` | Public | Obtenir le sondage (privé → 404 pour non-admin) |
| `POST` | `/polls/{id}/vote` | Utilisateur | Voter |
| `GET` | `/polls/{id}/results` | Public | Obtenir les comptages de résultats par option |

## Validation des options

```php
private const int MIN_OPTIONS   = 2;
private const int MAX_OPTIONS   = 20;
private const int MAX_LABEL_LEN = 100;

foreach ($rawOptions as $idx => $label) {
    if (!is_string($label) || trim($label) === '') {
        return $this->problem(422, 'validation-failed', "options[{$idx}] must not be empty.");
    }
    if (strlen($label) > self::MAX_LABEL_LEN) {
        return $this->problem(422, 'validation-failed', "options[{$idx}] too long (max 100).");
    }
}
```

## Prévention des votes dupliqués

```php
/** @return 'ok'|'already_voted'|'invalid_option' */
public function vote(int $pollId, int $userId, int $optionId): string
{
    // Vérifier que l'option appartient au sondage (prévient l'injection d'option inter-sondage)
    $stmt = $this->pdo->prepare(
        'SELECT id FROM poll_options WHERE id = :oid AND poll_id = :pid'
    );
    $stmt->execute([':oid' => $optionId, ':pid' => $pollId]);
    if ($stmt->fetch() === false) {
        return 'invalid_option'; // → 422
    }

    // Vérifier s'il existe déjà un vote
    $stmt2 = $this->pdo->prepare(
        'SELECT id FROM votes WHERE poll_id = :pid AND user_id = :uid'
    );
    if ($stmt2->fetch() !== false) {
        return 'already_voted'; // → 409
    }

    // INSERT — la contrainte UNIQUE(poll_id, user_id) est un filet de sécurité
    $this->pdo->prepare('INSERT INTO votes ...')->execute([...]);
    return 'ok';
}
```

## Agrégation des résultats

L'utilisation de `LEFT JOIN` garantit que les options sans votes apparaissent quand même dans les résultats :

```sql
SELECT o.id, o.label, o.sort_order,
       COUNT(v.id) AS votes
FROM poll_options o
LEFT JOIN votes v ON v.option_id = o.id AND v.poll_id = o.poll_id
WHERE o.poll_id = :pid
GROUP BY o.id, o.label, o.sort_order
ORDER BY o.sort_order ASC, o.id ASC
```

```php
$results    = $this->repo->results($id);
$totalVotes = array_sum(array_column($results, 'votes'));

return $this->json([
    'poll_id'     => $id,
    'total_votes' => $totalVotes,
    'results'     => $results,
]);
```

## Contrôle d'accès aux sondages privés

Les sondages privés retournent 404 pour les utilisateurs non-admin (masquage de l'existence) :

```php
// GET /polls/{id}
if (!(bool) $poll['is_public'] && !$this->isAdmin($req)) {
    return $this->problem(404, 'not-found', 'Poll not found.');
}
```

## Patterns de sécurité

- **Admin fail-closed** : `if ($this->adminKey === '') return false;` avant `hash_equals()`
- **`is_int()`** : Vérification de type stricte pour `option_id` — rejette les floats/chaînes
- **`ctype_digit()`** : Validation d'entier résistante aux ReDoS pour les IDs de chemin
- **Injection d'option inter-sondage** : `WHERE id = :oid AND poll_id = :pid` empêche l'utilisation d'une option d'un autre sondage
- **`is_bool()`** : Vérification stricte du flag `is_public` — rejette `1`/`0`/`"true"`, etc.
