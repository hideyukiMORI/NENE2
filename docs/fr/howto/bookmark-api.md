# How-to : API de marque-pages

> **Référence FT** : FT295 (`NENE2-FT/bookmarklog`) — Gestion des marque-pages : UNIQUE(user_id, item_id) empêche les marque-pages en doublon, regroupement par collection avec filtre optionnel, accès scopé par utilisateur (prévention IDOR), 409 sur doublon, 22 tests / 64 assertions PASS.

Ce guide montre comment construire une API de marque-pages où les utilisateurs sauvegardent des éléments dans des collections nommées avec déduplication et contrôle d'accès scopé par utilisateur.

## Schéma

```sql
CREATE TABLE bookmarks (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    item_id    INTEGER NOT NULL,
    collection TEXT    NOT NULL DEFAULT 'default',
    created_at TEXT    NOT NULL,
    UNIQUE (user_id, item_id),
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (item_id) REFERENCES items(id)
);
```

`UNIQUE(user_id, item_id)` assure que chaque utilisateur ne peut mettre un élément en marque-page qu'une seule fois. Le champ `collection` regroupe les marque-pages en listes nommées (défaut : `'default'`).

## Endpoints

| Méthode | Chemin | Description |
|--------|------|-------------|
| `POST` | `/users/{userId}/bookmarks` | Ajouter un marque-page |
| `DELETE` | `/users/{userId}/bookmarks/{itemId}` | Supprimer un marque-page |
| `GET` | `/users/{userId}/bookmarks` | Lister les marque-pages (optionnellement filtrés par collection) |
| `GET` | `/users/{userId}/bookmarks/count` | Compter les marque-pages |
| `GET` | `/users/{userId}/bookmarks/{itemId}` | Obtenir un marque-page spécifique |

## Ordre d'enregistrement des routes

`/users/{userId}/bookmarks/count` doit être enregistrée **avant** `/users/{userId}/bookmarks/{itemId}` pour éviter que `count` soit capturé comme `{itemId}` :

```php
$router->get('/users/{userId}/bookmarks', $this->listBookmarks(...));
$router->get('/users/{userId}/bookmarks/count', $this->countBookmarks(...));  // statique avant dynamique
$router->get('/users/{userId}/bookmarks/{itemId}', $this->getBookmark(...));
```

## Ajout d'un marque-page

```php
private function addBookmark(ServerRequestInterface $request): ResponseInterface
{
    $params = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
    $userId = isset($params['userId']) && is_numeric($params['userId']) ? (int) $params['userId'] : 0;

    if ($userId <= 0 || !$this->repo->findUserById($userId)) {
        return $this->responseFactory->create(['error' => 'user not found'], 404);
    }

    $body       = JsonRequestBodyParser::parse($request);
    $itemId     = isset($body['item_id']) && is_int($body['item_id']) ? $body['item_id'] : 0;
    $collection = isset($body['collection']) && is_string($body['collection'])
        ? trim($body['collection']) : 'default';

    if ($itemId <= 0 || !$this->repo->findItemById($itemId)) {
        return $this->responseFactory->create(['error' => 'item not found'], 404);
    }

    if ($collection === '') {
        $collection = 'default';  // chaîne de collection vide → repli sur 'default'
    }

    $now      = date('Y-m-d H:i:s');
    $bookmark = $this->repo->add($userId, $itemId, $collection, $now);
    return $this->responseFactory->create($bookmark->toArray(), 201);
}
```

`item_id` nécessite `is_int()` — la chaîne JSON `"5"` est rejetée. La contrainte `UNIQUE` dans la DB capture les courses ; le repository devrait capturer la violation de contrainte et retourner 409.

## Filtre de collection sur la liste

```php
$query      = $request->getQueryParams();
$collection = isset($query['collection']) && is_string($query['collection']) && $query['collection'] !== ''
    ? $query['collection'] : null;

$items = $this->repo->listByUser($userId, $collection);
```

Sans `?collection=`, tous les marque-pages sont retournés. Avec `?collection=favorites`, seule cette collection est retournée. Le paramètre de requête de collection vide est traité comme "pas de filtre".

## Scope utilisateur — Prévention IDOR

Chaque endpoint valide `userId` contre la DB avant de retourner des données :

```php
if ($userId <= 0 || !$this->repo->findUserById($userId)) {
    return $this->responseFactory->create(['error' => 'user not found'], 404);
}
```

Demander `/users/999/bookmarks` en tant qu'utilisateur différent retourne 404 (pas les marque-pages de l'autre utilisateur). Toutes les requêtes sont scopées au `userId` du chemin.

---

## Ce qu'il ne faut PAS faire

| Anti-pattern | Risque |
|---|---|
| Pas de `UNIQUE(user_id, item_id)` | L'utilisateur met le même élément en marque-page plusieurs fois ; doublons déroutants |
| Retourner 200 sur un marque-page en doublon | Le client ne peut pas distinguer "ajouté" de "existe déjà" ; utiliser 409 |
| Accepter `item_id` comme chaîne depuis le corps | Confusion de type JSON : `"5"` ≠ `5` ; utiliser `is_int()` |
| Enregistrer `/{itemId}` avant `/count` | `GET /users/1/bookmarks/count` résout en `itemId = "count"` (mauvais gestionnaire) |
| Pas de vérification d'existence utilisateur | Un userId inexistant retourne une liste vide au lieu de 404 |
| Pas de scope utilisateur dans les requêtes | L'Utilisateur A voit les marque-pages de l'Utilisateur B (IDOR) |
| Pas de défaut de collection | Le champ `collection` manquant plante ou laisse `NULL` dans la DB |
