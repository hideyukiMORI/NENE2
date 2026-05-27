# Comment construire un classement (système de ranking) avec NENE2

Ce guide explique comment construire un leaderboard où les utilisateurs soumettent des scores, voient les classements et vérifient leur propre rang. Seul le meilleur score par utilisateur par leaderboard est conservé.

**Field Trial** : FT141  
**Version NENE2** : ^1.5  
**Sujets couverts** : pattern UPDATE meilleur score, calcul de rang avec COUNT(*), vérification de propriété de score, plafonnement de paramètre de requête, évaluation des vulnérabilités

---

## Ce que nous construisons

- `POST /leaderboards` — créer un leaderboard
- `POST /leaderboards/{id}/scores` — soumettre un score (conservé seulement si c'est un nouveau meilleur personnel)
- `GET /leaderboards/{id}/rankings` — top N classements (score descendant, `?limit=N`)
- `GET /leaderboards/{id}/rankings/me` — rang et score propre à l'appelant
- `DELETE /leaderboards/{id}/scores/{userId}` — supprimer son propre score (propriétaire seulement)

---

## Schéma de base de données

```sql
CREATE TABLE leaderboards (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT NOT NULL,
    created_at TEXT NOT NULL
);

CREATE TABLE scores (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    leaderboard_id INTEGER NOT NULL,
    user_id        INTEGER NOT NULL,
    score          INTEGER NOT NULL,
    submitted_at   TEXT    NOT NULL,
    UNIQUE (leaderboard_id, user_id),
    FOREIGN KEY (leaderboard_id) REFERENCES leaderboards(id),
    FOREIGN KEY (user_id)        REFERENCES users(id)
);
```

`UNIQUE (leaderboard_id, user_id)` — une ligne de score par utilisateur par leaderboard ; les mises à jour la remplacent.

---

## Pattern UPDATE meilleur score

```php
public function submitScore(int $leaderboardId, int $userId, int $score, string $now): bool
{
    $existing = $this->findScore($leaderboardId, $userId);

    if ($existing === null) {
        $this->executor->execute(
            'INSERT INTO scores (leaderboard_id, user_id, score, submitted_at) VALUES (?, ?, ?, ?)',
            [$leaderboardId, $userId, $score, $now],
        );
        return true;
    }

    if ($score > $existing['score']) {
        $this->executor->execute(
            'UPDATE scores SET score = ?, submitted_at = ? WHERE leaderboard_id = ? AND user_id = ?',
            [$score, $now, $leaderboardId, $userId],
        );
        return true;
    }

    return false;  // Pas un nouveau meilleur personnel
}
```

Retourne `true` quand le score est un nouveau meilleur personnel (utile pour le feedback UI), `false` quand ignoré.

---

## Calcul de rang avec COUNT(*)

Au lieu d'une fonction fenêtre (`RANK()` n'est pas disponible dans toutes les versions SQLite), compter combien de scores sont plus élevés :

```php
public function getUserRank(int $leaderboardId, int $userId): ?int
{
    $score = $this->findScore($leaderboardId, $userId);

    if ($score === null) {
        return null;
    }

    $row   = $this->executor->fetchOne(
        'SELECT COUNT(*) as cnt FROM scores WHERE leaderboard_id = ? AND score > ?',
        [$leaderboardId, $score['score']],
    );
    $ahead = isset($row['cnt']) ? (int) $row['cnt'] : 0;

    return $ahead + 1;
}
```

Si 0 utilisateurs ont un score plus élevé, le rang est 1. Si 5 utilisateurs ont un score plus élevé, le rang est 6. Les ex aequo obtiennent le même rang.

---

## Vérification de propriété de score (prévention IDOR)

```php
if ($actorId !== $userId) {
    return $this->responseFactory->create(['error' => 'cannot delete another user\'s score'], 403);
}
```

Toujours vérifier l'identité de l'appelant contre l'utilisateur cible avant DELETE. Sans cette vérification, tout utilisateur authentifié pourrait supprimer n'importe quel score.

---

## Plafonnement du paramètre de requête

```php
$limit = isset($query['limit']) && is_numeric($query['limit']) ? (int) $query['limit'] : 10;

if ($limit <= 0 || $limit > 100) {
    $limit = 10;
}
```

Plafonner la limite pour prévenir `?limit=99999` de scanner toute la table.

---

## Évaluation des vulnérabilités (FT141)

| ID | Attaque | Attendu | Résultat |
|----|---------|---------|----------|
| VULN-A | IDOR : supprimer le score d'un autre utilisateur | 403 | Pass |
| VULN-B | Soumettre un score pour un autre utilisateur | 200 (autorisé) | Pass |
| VULN-C | Injection SQL dans le nom de leaderboard | 201 (verbatim) | Pass |
| VULN-D | X-User-Id manquant sur /rankings/me | 400 | Pass |
| VULN-E | X-User-Id non numérique | pas 200 | Pass |
| VULN-F | ID de leaderboard négatif | pas 200 | Pass |
| VULN-G | PHP_INT_MAX comme score | 200 (entier valide) | Pass |
| VULN-H | Score float (confusion de type) | 422 | Pass |
| VULN-I | Score chaîne (confusion de type) | 422 | Pass |
| VULN-J | X-User-Id manquant sur DELETE | 400 | Pass |
| VULN-K | user_id=0 dans la soumission de score | 422 | Pass |
| VULN-L | `?limit=99999` (grande limite) | 200 + plafonné | Pass |

Les 12 tests de vulnérabilité passent. Aucune vulnérabilité trouvée.

---

## Pièges courants

| Piège | Correction |
|-------|------------|
| Stocker toutes les scores soumis au lieu du meilleur | Vérification `findScore()` avant INSERT ; UPDATE si plus élevé |
| Utiliser RANK() qui peut ne pas exister dans SQLite | `COUNT(*) WHERE score > ?` donne un rang équivalent |
| IDOR sur la suppression de score | Vérifier `$actorId !== $userId` → 403 |
| Paramètre de limite non plafonné cause un scan de table | Plafonner `limit` à la plage 1-100 |
| Score float/chaîne contourne `is_int()` | `!is_int($score)` rejette les floats et chaînes dans le décodage JSON PHP 8 |
