# How-to : API de panier d'achat

> **Référence FT** : FT269 (`NENE2-FT/cartlog`) — Panier d'achat : UNIQUE (user_id, product_id) par utilisateur, upsert d'ajout d'article (accumulation de quantité), sémantique d'auto-suppression à quantité=0, prix/sous-total en entier, identification via en-tête X-User-Id
>
> Également validé dans FT155 (`NENE2-FT/cartlog` précurseur) — même pattern de panier, SQLite, PHP 8.4.

Démontre un panier d'achat avec état par utilisateur : ajouter des articles (avec accumulation de quantité si re-ajouté), mettre à jour les quantités, supprimer des articles et afficher un total en cours. Tous les prix sont stockés en entiers (centimes ou unités de base) — jamais en flottants.

---

## Routes

| Méthode | Chemin | Description |
|---------|--------|-------------|
| `GET` | `/cart` | Lister le contenu du panier avec sous-totaux et total |
| `POST` | `/cart/items` | Ajouter un produit (la quantité s'accumule si déjà dans le panier) |
| `PUT` | `/cart/items/{productId}` | Définir la quantité (0 = supprimer l'article) |
| `DELETE` | `/cart/items/{productId}` | Supprimer un article spécifique |
| `DELETE` | `/cart` | Vider l'intégralité du panier |

---

## Schéma

```sql
CREATE TABLE users (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT    NOT NULL,
    created_at TEXT    NOT NULL
);

CREATE TABLE products (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT    NOT NULL,
    price      INTEGER NOT NULL CHECK (price >= 0),
    stock      INTEGER NOT NULL DEFAULT 0 CHECK (stock >= 0),
    created_at TEXT    NOT NULL
);

CREATE TABLE cart_items (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    product_id INTEGER NOT NULL,
    quantity   INTEGER NOT NULL CHECK (quantity > 0),
    added_at   TEXT    NOT NULL,
    updated_at TEXT    NOT NULL,
    UNIQUE (user_id, product_id),
    FOREIGN KEY (user_id)    REFERENCES users(id),
    FOREIGN KEY (product_id) REFERENCES products(id)
);
```

Choix de conception clés :
- `UNIQUE (user_id, product_id)` — une ligne par paire (utilisateur, produit). Re-ajouter le même produit accumule la quantité plutôt que d'insérer une ligne dupliquée.
- `price INTEGER` — stocké dans la plus petite unité monétaire (ex. centimes). Ne jamais utiliser `FLOAT` pour les montants.
- `quantity INTEGER CHECK (quantity > 0)` — les lignes à quantité zéro sont supprimées, pas stockées.
- Pas de FK sur `cart_items.price` — le prix est lu depuis `products.price` au moment de la requête (JOIN), pas stocké dans le panier. Si le prix du produit change, le panier reflète le nouveau prix.

---

## Pattern upsert d'ajout d'article

Ajouter un article qui existe déjà dans le panier accumule la quantité :

```php
public function addItem(int $userId, int $productId, int $quantity, string $now): void
{
    $existing = $this->db->fetchOne(
        'SELECT id, quantity FROM cart_items WHERE user_id = ? AND product_id = ?',
        [$userId, $productId],
    );

    if ($existing !== null) {
        $newQty = (int) $existing['quantity'] + $quantity;
        $this->db->execute(
            'UPDATE cart_items SET quantity = ?, updated_at = ? WHERE id = ?',
            [$newQty, $now, $existing['id']],
        );
    } else {
        $this->db->execute(
            'INSERT INTO cart_items (user_id, product_id, quantity, added_at, updated_at)
             VALUES (?, ?, ?, ?, ?)',
            [$userId, $productId, $quantity, $now, $now],
        );
    }
}
```

Le pattern SELECT-puis-INSERT/UPDATE évite `INSERT OR REPLACE` (qui change l'`id` et `added_at`) et `ON CONFLICT DO UPDATE` (non portable sur tous les moteurs DB). La contrainte `UNIQUE (user_id, product_id)` protège toujours contre un INSERT dupliqué en cas de concurrence.

Statut de réponse : `201 Created` si l'article était nouveau ; `200 OK` si la quantité a été accumulée sur un article existant.

---

## Sémantique d'auto-suppression à quantité=0

`PUT /cart/items/{productId}` avec `quantity: 0` supprime l'article plutôt que de stocker une ligne à quantité zéro :

```php
if ($quantity === 0) {
    $this->repo->removeItem($userId, $productId);
    return $this->json->createEmpty(204);
}

$this->repo->updateQuantity($userId, $productId, $quantity, $now);
```

Cela correspond à l'UX courante des paniers : faire glisser le compteur à zéro supprime l'article. Le `CHECK (quantity > 0)` en DB applique aussi cela au niveau du stockage.

---

## Total du panier : calcul JOIN + boucle

La réponse du panier inclut un total en temps réel calculé depuis le résultat du JOIN :

```php
public function getCart(int $userId): array
{
    return $this->db->fetchAll(
        'SELECT ci.id, ci.product_id, ci.quantity, ci.added_at, ci.updated_at,
                p.name AS product_name, p.price
         FROM cart_items ci
         JOIN products p ON p.id = ci.product_id
         WHERE ci.user_id = ?
         ORDER BY ci.added_at ASC, ci.id ASC',
        [$userId],
    );
}
```

```php
$items = $this->repo->getCart($userId);
$total = 0;
$formatted = [];

foreach ($items as $item) {
    $subtotal = (int) $item['price'] * (int) $item['quantity'];
    $total   += $subtotal;
    $formatted[] = $this->formatItem($item, $subtotal);
}

return $this->json->create([
    'items' => $formatted,
    'total' => $total,
    'count' => count($formatted),
]);
```

`price` et `subtotal` sont tous deux des entiers. Le consommateur de l'API divise par 100 pour l'affichage (ex. `1999` → `19,99 €`).

---

## Identification de l'utilisateur via l'en-tête X-User-Id

Le FT utilise un en-tête simple `X-User-Id` (sans JWT) pour identifier le propriétaire du panier :

```php
private function requireUserId(ServerRequestInterface $request): ?int
{
    $header = $request->getHeaderLine('X-User-Id');
    if ($header === '') {
        return null;
    }
    $id = (int) $header;
    return $id > 0 ? $id : null;
}
```

Le handler vérifie que l'utilisateur existe dans la table `users` avant de continuer :
```php
if ($this->repo->findUserById($userId) === null) {
    return $this->json->create(['error' => 'User not found'], 404);
}
```

**Note de production** : Remplacer `X-User-Id` par un JWT ou token de session vérifié. L'en-tête est trivialement falsifiable — tout appelant peut revendiquer n'importe quel `user_id`. Utiliser `X-User-Id` uniquement dans des contextes de service-à-service internes de confiance, jamais pour les API publiques.

---

## Validation

```php
// Validation du corps POST /cart/items
private function parseAddBody(array $body): array
{
    $errors = [];

    if (!isset($body['product_id']) || !is_int($body['product_id'])) {
        $errors[] = new ValidationError('product_id', 'product_id must be an integer', 'invalid_type');
    }

    $productId = isset($body['product_id']) && is_int($body['product_id']) ? $body['product_id'] : 0;
    if ($productId <= 0 && $errors === []) {
        $errors[] = new ValidationError('product_id', 'product_id must be positive', 'invalid_value');
    }

    if (!isset($body['quantity']) || !is_int($body['quantity'])) {
        $errors[] = new ValidationError('quantity', 'quantity must be an integer', 'invalid_type');
    }

    $quantity = isset($body['quantity']) && is_int($body['quantity']) ? $body['quantity'] : 0;
    if ($quantity <= 0 && !isset($errors[1])) {
        $errors[] = new ValidationError('quantity', 'quantity must be positive', 'invalid_value');
    }

    return [$productId, $quantity, $errors];
}
```

Les vérifications de type (`is_int`) rejettent les quantités flottantes ou en chaîne — `"3"` et `3.0` sont tous deux invalides.

---

## Exemples de réponses

**GET /cart** :
```json
{
    "items": [
        {
            "id": 1,
            "product_id": 5,
            "product_name": "Widget",
            "price": 999,
            "quantity": 2,
            "subtotal": 1998,
            "added_at": "2026-01-01T10:00:00Z",
            "updated_at": "2026-01-01T10:00:00Z"
        }
    ],
    "total": 1998,
    "count": 1
}
```

---

## Exemple de câblage AppFactory

Initialiser l'application pour les tests ou un point d'entrée léger :

```php
class AppFactory
{
    public static function createSqlite(string $dbFile): RequestHandlerInterface
    {
        $dbConfig = new DatabaseConfig(
            url: null,
            environment: 'test',
            adapter: 'sqlite',
            host: '',
            port: 1,
            name: $dbFile,
            user: '',
            password: '',
            charset: '',
        );
        return self::build($dbConfig);
    }

    private static function build(DatabaseConfig $dbConfig): RequestHandlerInterface
    {
        $factory    = new PdoConnectionFactory($dbConfig);
        $executor   = new PdoDatabaseQueryExecutor($factory);
        $psr17      = new Psr17Factory();
        $repo       = new CartRepository($executor);
        $json       = new JsonResponseFactory($psr17, $psr17);
        $registrar  = new RouteRegistrar($repo, $json);

        return (new RuntimeApplicationFactory(
            $psr17,
            $psr17,
            routeRegistrars: [static fn (Router $router) => $registrar->register($router)],
        ))->create();
    }
}
```

L'utilisation de `RuntimeApplicationFactory` fournit automatiquement : mappage exception-de-validation → 422, gestion des erreurs, et en-têtes de sécurité.

---

## Patterns de test

```php
// Re-ajouter le même produit accumule la quantité
$this->req('POST', '/cart/items', ['X-User-Id' => '1'], ['product_id' => 1, 'quantity' => 2]);
$res = $this->req('POST', '/cart/items', ['X-User-Id' => '1'], ['product_id' => 1, 'quantity' => 3]);
$this->assertSame(200, $res->getStatusCode());
$data = $this->json($res);
$this->assertSame(5, $data['quantity']);

// Le panier de chaque utilisateur est isolé
$this->req('POST', '/cart/items', ['X-User-Id' => '1'], ['product_id' => 1, 'quantity' => 3]);
$res = $this->req('GET', '/cart', ['X-User-Id' => '2']);
$this->assertSame(0, $this->json($res)['count']);
```

> **Mise en garde SQLite FK** : `PdoConnectionFactory` définit `PRAGMA foreign_keys = ON`. Lors de l'ensemencement des données de test via une instance PDO séparée, définir le même pragma sur cette connexion — sinon les JOINs suppriment silencieusement les lignes dont les cibles FK ont été insérées via un autre handle de connexion.

---

## Ce qu'il ne faut PAS faire

| Anti-pattern | Risque |
|---|---|
| Stocker `price` dans `cart_items` au moment de l'ajout | Prix périmé si le prix du produit change ; litiges de remboursement / surfacturation |
| Utiliser `FLOAT` pour le prix | Erreurs d'arrondi en virgule flottante dans les totaux financiers |
| Utiliser `X-User-Id` dans une API publique | Trivialement falsifiable ; utiliser JWT/session à la place |
| Autoriser `quantity: 0` à stocker une ligne zéro | Viole `CHECK (quantity > 0)` ; sémantique confuse |
| Utiliser `INSERT OR REPLACE` pour l'upsert | Réinitialise `id` et `added_at` ; casse le tri par ordre de préservation |
| Pas de contrainte `UNIQUE (user_id, product_id)` | La condition de concurrence crée des lignes de panier dupliquées |
