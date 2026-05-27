# Verrouillage optimiste

Le verrouillage optimiste prévient le **problème de mise à jour perdue** — quand deux écrivains concurrents lisent tous les deux le même enregistrement, font des modifications indépendantes, et le second écrivain écrase silencieusement les changements du premier.

Utilisez le verrouillage optimiste quand :
- Les conflits sont rares (la plupart des mises à jour réussissent)
- Vous avez besoin de lectures non bloquantes (pas de SELECT FOR UPDATE)
- L'enregistrement a un champ `version` ou `updated_at` pour suivre son état

## Le problème de mise à jour perdue

Sans verrouillage :

```
temps | Écrivain A              | Écrivain B
------|------------------------|-------------------
  1   | GET /articles/1        | GET /articles/1
      | ← version: 1           | ← version: 1
  2   | [édite le titre]       | [édite le corps]
  3   | PATCH /articles/1      |
      | title = "Titre de A"   |
      | ← version: 1, 200 OK   |
  4   |                        | PATCH /articles/1
      |                        | body = "Corps de B"
      |                        | ← version: 1, 200 OK  ← Le titre de A est PERDU
```

L'écrivain B écrase le changement de titre de l'écrivain A parce qu'aucun des deux n'a vérifié la modification concurrente.

## Schéma

Ajoutez une colonne `version` qui s'incrémente à chaque mise à jour :

```sql
CREATE TABLE articles (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    title      TEXT NOT NULL,
    body       TEXT NOT NULL,
    version    INTEGER NOT NULL DEFAULT 1,
    updated_at TEXT NOT NULL
);
```

## Implémentation du Repository

```php
/**
 * @throws ConflictException si un autre écrivain a mis à jour l'enregistrement en premier
 * @throws \RuntimeException si l'article n'existe pas
 */
public function update(int $id, string $title, string $body, int $expectedVersion): Article
{
    $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

    // WHERE version = $expectedVersion est la vérification du verrou optimiste.
    // Si un autre écrivain a déjà incrémenté la version, cet UPDATE correspond à 0 lignes.
    $affected = $this->executor->execute(
        'UPDATE articles SET title = ?, body = ?, version = version + 1, updated_at = ? WHERE id = ? AND version = ?',
        [$title, $body, $now, $id, $expectedVersion],
    );

    if ($affected === 0) {
        // 0 lignes mises à jour : soit non trouvé SOIT conflit de version — les distinguer
        $current = $this->findById($id);
        if ($current === null) {
            throw new \RuntimeException("Article {$id} n'existe pas.");
        }
        throw new ConflictException($id, $expectedVersion);
    }

    return new Article(id: $id, title: $title, body: $body, version: $expectedVersion + 1, updatedAt: $now);
}
```

### Pourquoi `version = version + 1` en SQL (pas en PHP)

```php
// ❌ Condition de course : deux écrivains lisent tous les deux version=1, calculent tous les deux version=2
$newVersion = $article->version + 1;
$this->executor->execute('UPDATE ... SET version = ? ...', [$newVersion, $id, $expectedVersion]);

// ✅ Atomique : la base de données incrémente — la version est toujours correcte
$this->executor->execute('UPDATE ... SET version = version + 1 ...', [$id, $expectedVersion]);
```

La vérification `WHERE version = $expectedVersion` est le garde ; `version = version + 1` garantit que la nouvelle valeur est exactement une de plus que ce qui a passé le garde.

## Intégration dans le Controller

Le client doit lire la `version` courante et la renvoyer à chaque mise à jour :

```php
private function update(ServerRequestInterface $request): ResponseInterface
{
    $id   = (int) Router::param($request, 'id');
    $body = json_decode((string) $request->getBody(), true);

    if (!is_array($body) || !is_int($body['version'] ?? null)) {
        return $this->problems->create($request, 'invalid-body', 'version (int) is required.', 400);
    }

    try {
        $article = $this->repo->update($id, $body['title'], $body['body'], $body['version']);
        return $this->json->create($this->serialize($article));
    } catch (ConflictException $e) {
        $current = $this->repo->findById($id);
        return $this->problems->create(
            $request,
            'conflict',
            'Optimistic lock conflict.',
            409,
            $e->getMessage(),
            $current !== null ? ['current_version' => $current->version] : [],
        );
    } catch (\RuntimeException) {
        return $this->problems->create($request, 'not-found', 'Article not found.', 404);
    }
}
```

## Flux client

```
POST /articles            → 201 { id: 1, version: 1, ... }
GET /articles/1           → 200 { id: 1, version: 1, ... }

PATCH /articles/1         → 200 { id: 1, version: 2, ... }
  { title: "...", version: 1 }

PATCH /articles/1         → 409 { type: "conflict", current_version: 2 }
  { title: "...", version: 1 }   (version périmée — conflit !)

PATCH /articles/1         → 200 { id: 1, version: 3, ... }
  { title: "...", version: 2 }   (re-fetch ou utiliser current_version du 409)
```

Inclure `current_version` dans la réponse 409 permet au client de réessayer sans GET supplémentaire.

## Payload de réponse

Toujours inclure `version` dans chaque réponse pour que les clients aient toujours la dernière valeur :

```php
/** @return array<string, mixed> */
private function serialize(Article $article): array
{
    return [
        'id'         => $article->id,
        'title'      => $article->title,
        'body'       => $article->body,
        'version'    => $article->version,  // ← le client en a besoin pour renvoyer
        'updated_at' => $article->updatedAt,
    ];
}
```

## Verrouillage optimiste vs pessimiste

| | Optimiste | Pessimiste |
|---|---|---|
| Mécanisme | `WHERE version = ?` + vérification 0 lignes | `SELECT ... FOR UPDATE` |
| Blocage en lecture | Aucun | Bloque les autres lecteurs |
| Taux de conflit | Faible (la plupart des mises à jour réussissent) | Haute contention OK |
| Coût de réessai | Le client réessaie sur 409 | Attend la libération du verrou |
| Support SQLite | ✅ | ❌ (non supporté) |
| Idéal pour | Conflits rares, réessais pilotés par UX | Haute contention, opérations devant réussir |

## Liste de vérification pour la revue de code

- [ ] UPDATE inclut `AND version = ?` dans la clause WHERE
- [ ] La valeur de retour de `execute()` (lignes affectées) est vérifiée — 0 signifie conflit ou non trouvé
- [ ] Le cas 0 lignes distingue "non trouvé" de "conflit de version" (`findById` supplémentaire sur le chemin de conflit)
- [ ] `version = version + 1` est calculé en SQL, pas dans le code PHP applicatif
- [ ] Chaque payload de réponse inclut `version` pour que le client ait toujours la dernière valeur
- [ ] La réponse 409 inclut `current_version` pour le réessai client sans GET supplémentaire
- [ ] `version` dans le corps de la requête est validé comme `int`, pas `string` (vérification `is_int()`)
- [ ] Les tests couvrent : mise à jour réussie, mises à jour successives, conflit concurrent, réessai après conflit, 404, version manquante
