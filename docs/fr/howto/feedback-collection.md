# How-to : API de collecte de commentaires

## Vue d'ensemble

Un système de commentaires où les utilisateurs soumettent un score (1-5) et un commentaire pour une entité cible. L'admin peut lister tous les commentaires ; l'endpoint de statistiques public affiche les moyennes agrégées.

**Implémentation de référence** : `../NENE2-FT/feedbacklog/`

## Schéma

```sql
CREATE TABLE IF NOT EXISTS feedback (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    target     TEXT    NOT NULL,
    score      INTEGER NOT NULL,   -- 1-5
    comment    TEXT    NOT NULL DEFAULT '',
    created_at TEXT    NOT NULL,
    UNIQUE (user_id, target)
);
```

## Routes

| Méthode | Chemin             | Auth   | Description                    |
|---------|-------------------|--------|--------------------------------|
| `POST`  | `/feedback`       | Utilisateur | Soumettre un commentaire   |
| `GET`   | `/feedback`       | Admin  | Lister tous les commentaires   |
| `GET`   | `/feedback/stats` | Aucune | Statistiques agrégées          |

## Prévention des doublons

`UNIQUE (user_id, target)` impose un commentaire par utilisateur par cible au niveau DB. Vérification au niveau applicatif d'abord :

```php
$stmt = $this->pdo->prepare('SELECT id FROM feedback WHERE user_id = :uid AND target = :tgt');
$stmt->execute([...]);
if ($stmt->fetch() !== false) return 'already_submitted';
```

## Validation du score

```php
if (!is_int($score) || $score < 1 || $score > 5) {
    return $this->problem(422, 'validation-failed', 'score must be an integer 1-5.');
}
```

## Agrégation des statistiques

```sql
SELECT COUNT(*) AS cnt, AVG(score) AS avg FROM feedback WHERE target = :tgt
```

Retourner `null` pour la moyenne quand le compte est zéro pour éviter `NaN` en JSON.

## Codes de statut HTTP

| Situation | Statut |
|-----------|--------|
| Commentaire soumis | 201 |
| Statistiques / liste | 200 |
| Pas de X-User-Id | 400 |
| Cible vide / mauvais score | 422 |
| Pas de clé admin | 403 |
| Commentaire en double | 409 |
