# How-to : Système de sondage en direct

## Vue d'ensemble

Ce guide couvre la construction d'une API de système de sondage en direct avec NENE2, incluant la création de sondage gérée par admin, la déduplication de vote par utilisateur, la gestion du cycle de vie du sondage et l'agrégation des résultats.

**Implémentation de référence** : `../NENE2-FT/polllog/`

---

## Conception du schéma

```sql
CREATE TABLE IF NOT EXISTS polls (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    question   TEXT    NOT NULL,
    closed     INTEGER NOT NULL DEFAULT 0,
    created_at TEXT    NOT NULL
);

CREATE TABLE IF NOT EXISTS poll_options (
    id      INTEGER PRIMARY KEY AUTOINCREMENT,
    poll_id INTEGER NOT NULL,
    label   TEXT    NOT NULL,
    FOREIGN KEY (poll_id) REFERENCES polls(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS poll_votes (
    id        INTEGER PRIMARY KEY AUTOINCREMENT,
    poll_id   INTEGER NOT NULL,
    option_id INTEGER NOT NULL,
    user_id   INTEGER NOT NULL,
    voted_at  TEXT    NOT NULL,
    UNIQUE (poll_id, user_id),
    FOREIGN KEY (poll_id) REFERENCES polls(id) ON DELETE CASCADE,
    FOREIGN KEY (option_id) REFERENCES poll_options(id) ON DELETE CASCADE
);
```

Contraintes clés :
- `UNIQUE (poll_id, user_id)` — empêche un utilisateur de voter plus d'une fois par sondage.
- `ON DELETE CASCADE` — supprime les options et votes quand un sondage est supprimé.

---

## Table des routes

| Méthode | Chemin | Auth | Description |
|---------|--------|------|-------------|
| `POST` | `/polls` | Admin | Créer un sondage avec options |
| `GET` | `/polls` | Aucune | Lister tous les sondages |
| `GET` | `/polls/{id}` | Aucune | Obtenir le sondage avec comptages de votes |
| `POST` | `/polls/{id}/vote` | Utilisateur | Voter |
| `POST` | `/polls/{id}/close` | Admin | Fermer un sondage |

---

## Pattern d'authentification admin

Passer un secret partagé dans l'en-tête `X-Admin-Key`. Utiliser la logique à défaut fermé :

```php
private function isAdmin(ServerRequestInterface $req): bool
{
    if ($this->adminKey === '') {
        return false;          // à défaut fermé : pas de clé configurée → jamais admin
    }
    return hash_equals($this->adminKey, $req->getHeaderLine('X-Admin-Key'));
}
```

Retourner `403 Forbidden` quand non-admin :
```php
if (!$this->isAdmin($req)) {
    return $this->problem(403, 'forbidden', 'Admin key required.');
}
```

---

## Création de sondages avec options

Valider au moins 2 options ; insérer dans une transaction :

```php
public function create(string $question, array $options): array
{
    $now  = $this->now();
    $stmt = $this->pdo->prepare('INSERT INTO polls (question, closed, created_at) VALUES (?, 0, ?)');
    $stmt->execute([$question, $now]);
    $pollId = (int) $this->pdo->lastInsertId();

    $ins = $this->pdo->prepare('INSERT INTO poll_options (poll_id, label) VALUES (?, ?)');
    foreach ($options as $label) {
        $ins->execute([$pollId, $label]);
    }

    return $this->findById($pollId);
}
```

---

## Vote avec déduplication

Attraper la violation de contrainte UNIQUE pour détecter les votes dupliqués :

```php
public function vote(int $pollId, int $optionId, int $userId): string
{
    $poll = $this->findById($pollId);
    if ($poll === null) return 'not_found';
    if ($poll['closed']) return 'poll_closed';

    // Vérifier que l'option appartient à ce sondage
    $stmt = $this->pdo->prepare('SELECT id FROM poll_options WHERE id = ? AND poll_id = ?');
    $stmt->execute([$optionId, $pollId]);
    if ($stmt->fetch() === false) return 'invalid_option';

    try {
        $this->pdo->prepare(
            'INSERT INTO poll_votes (poll_id, option_id, user_id, voted_at) VALUES (?, ?, ?, ?)'
        )->execute([$pollId, $optionId, $userId, $this->now()]);
    } catch (\PDOException $e) {
        if (str_contains($e->getMessage(), 'UNIQUE')) return 'already_voted';
        throw $e;
    }

    return 'ok';
}
```

---

## Agrégation des comptages de votes

Utiliser `LEFT JOIN` pour inclure les options sans votes :

```sql
SELECT po.id, po.label, COUNT(pv.id) AS votes
FROM poll_options po
LEFT JOIN poll_votes pv ON pv.option_id = po.id
WHERE po.poll_id = :poll_id
GROUP BY po.id, po.label
ORDER BY po.id ASC
```

---

## Codes de statut HTTP

| Situation | Statut |
|-----------|--------|
| Sondage créé | 201 |
| Vote effectué | 201 |
| Sondage trouvé / fermé | 200 |
| Sondage non trouvé | 404 |
| ID d'option invalide | 422 |
| Question manquante ou < 2 options | 422 |
| option_id non entier | 422 |
| Déjà voté | 409 |
| Voter sur un sondage fermé | 409 |
| Pas de clé admin | 403 |
| Pas d'en-tête X-User-Id | 400 |

---

## Checklist de validation

- `question` : chaîne non vide
- `options` : tableau de ≥ 2 chaînes non vides
- `option_id` : doit être `is_int()` (rejeter les chaînes comme `'1'`)
- `X-User-Id` : `ctype_digit()` + entier positif
- Le sondage doit exister avant de voter ou fermer
- L'option doit appartenir au sondage cible (injection inter-sondage)

---

## Notes de sécurité

- **Clé admin à défaut fermé** : clé vide signifie que personne n'est admin.
- **Utiliser `hash_equals()`** pour prévenir les attaques temporelles sur la comparaison de clé admin.
- **La contrainte UNIQUE** est la garde faisant autorité contre les votes dupliqués — une vérification niveau application seule n'est pas suffisante sous charge concurrente.
- **Vérification de propriété d'option** empêche de voter avec une option d'un sondage différent.
