# How-to : Imposer la propriété des ressources (prévention IDOR)

L'IDOR (Insecure Direct Object Reference) est la vulnérabilité API #1 (OWASP API Security Top 10). Elle se produit quand un utilisateur peut accéder ou modifier les ressources d'un autre utilisateur en devinant ou énumérant des IDs.

NENE2 ne fournit aucune imposition automatique de propriété — chaque repository et gestionnaire doit l'implémenter explicitement. Ce guide montre les patterns recommandés.

---

## 1. La règle fondamentale : 404, pas 403

Quand un utilisateur accède à une ressource qui appartient à un autre utilisateur, retourner `404 Not Found` — **pas** `403 Forbidden`.

- **403** dit à l'attaquant : "cette ressource existe, mais vous ne pouvez pas y accéder." — fuite d'information
- **404** dit à l'attaquant : "cette ressource n'existe pas." — aucune confirmation

```php
// INCORRECT — fuite d'existence
if ($note->ownerId !== $authUserId) {
    return $this->problems->create($request, 'forbidden', 'Forbidden', 403, '');
}

// CORRECT — ne révèle rien
if ($resource === null) {
    return $this->problems->create($request, 'not-found', 'Not Found', 404, '');
}
```

La façon pratique d'y parvenir : faire en sorte que le repository soit **incapable de retourner une ressource qui n'appartient pas à l'appelant** — voir la section suivante.

---

## 2. Imposer la propriété au niveau SQL

Le pattern le plus sûr est d'inclure `owner_id` dans chaque requête. La méthode est littéralement incapable de retourner les données d'un autre utilisateur, indépendamment de la façon dont l'appelant utilise le résultat.

```php
public function findByIdAndOwner(int $id, string $ownerId): ?Resource
{
    $row = $this->db->fetchOne(
        'SELECT * FROM resources WHERE id = ? AND owner_id = ?',
        [$id, $ownerId],
    );
    return $row !== null ? $this->hydrate($row) : null;
}

public function update(int $id, string $ownerId, string $newValue): bool
{
    $updated = $this->db->execute(
        'UPDATE resources SET value = ? WHERE id = ? AND owner_id = ?',
        [$newValue, $id, $ownerId],
    );
    return $updated > 0;
}

public function delete(int $id, string $ownerId): bool
{
    return $this->db->execute(
        'DELETE FROM resources WHERE id = ? AND owner_id = ?',
        [$id, $ownerId],
    ) > 0;
}
```

**Pourquoi le niveau SQL est meilleur que le niveau applicatif :**
- Une vérification au niveau applicatif peut être contournée si un développeur oublie de l'appeler
- Une vérification au niveau SQL ne peut pas être ignorée — la ligne du mauvais propriétaire ne sera simplement pas retournée
- Retourner `null` pour "introuvable" et "mauvais propriétaire" empêche l'appelant de brancher accidentellement sur un cas qu'il ne devrait pas connaître

---

## 3. Pattern de gestionnaire

```php
private function show(ServerRequestInterface $request): ResponseInterface
{
    $authUserId = $this->resolveAuthUser($request);
    if ($authUserId === null) {
        return $this->unauthorized($request);
    }

    $id       = $this->resolveId($request);
    $resource = $this->repo->findByIdAndOwner($id, $authUserId);

    if ($resource === null) {
        // 404 couvre à la fois "introuvable" et "appartient à un autre utilisateur"
        return $this->problems->create($request, 'not-found', 'Not Found', 404, '');
    }

    return $this->json->create($resource->toArray());
}
```

---

## 4. Listing : filtrer par propriétaire dans la requête

```php
public function listByOwner(string $ownerId): array
{
    return $this->db->fetchAll(
        'SELECT * FROM resources WHERE owner_id = ? ORDER BY id DESC',
        [$ownerId],
    );
}
```

Ne jamais récupérer toutes les lignes et filtrer en PHP. Cela fuit les données d'autres utilisateurs si la logique de filtrage est incorrecte, et c'est aussi un problème N+1.

---

## 5. Tester explicitement l'accès cross-owner

Ajouter des tests dédiés qui vérifient que l'IDOR est prévenu :

```php
public function testCannotReadAnotherUsersResource(): void
{
    $bobId = $this->decode($this->create('bob', 'Bob content'))['id'];

    // Alice essaie de lire la ressource de Bob — doit obtenir 404
    $res = $this->request('GET', '/resources/' . $bobId, authUser: 'alice');
    self::assertSame(404, $res->getStatusCode());
    // Spécifiquement pas 403 — ce qui révélerait l'existence de la ressource
    self::assertNotSame(403, $res->getStatusCode());
}

public function testListDoesNotLeakCrossTenantData(): void
{
    $this->create('alice', 'Alice content');
    $this->create('bob', 'Bob content');

    $aliceList = $this->decode($this->request('GET', '/resources', authUser: 'alice'));
    $titles    = array_column($aliceList['items'], 'content');

    self::assertNotContains('Bob content', $titles);
}
```

---

## Notes

- **Pourquoi 404 semble incorrect** : Retourner 404 pour une ressource que vous pouvez voir dans l'URL semble "malhonnête". C'est le cas — mais l'OWASP le recommande explicitement pour éviter les attaques d'énumération d'ID. Le compromis est une pratique de sécurité acceptée.
- **Contournement admin** : Si vous avez des routes admin qui peuvent voir n'importe quelle ressource, gardez-les sur un préfixe de chemin séparé avec une vérification de propriété séparée (ou pas de vérification). Ne pas compliquer les méthodes de propriété avec des drapeaux "est admin".
- **Schéma de base de données** : toujours ajouter un index sur `owner_id` (et sur `(owner_id, id)` pour les recherches composées). Sans index, chaque requête par utilisateur est un scan complet de table.
