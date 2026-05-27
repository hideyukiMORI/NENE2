# Système d'avis et d'évaluation de produits

Comment implémenter un système d'avis et d'évaluation de produits avec NENE2.
Inclut la contrainte 1 utilisateur / 1 produit / 1 avis, l'agrégation des évaluations (moyenne, distribution) et les opérations CRUD.

---

## Liste des endpoints

| Méthode | Chemin | Description | Auth |
|---|---|---|---|
| `POST` | `/products/{productId}/reviews` | Publier un avis | Requis |
| `GET` | `/products/{productId}/reviews` | Liste des avis (curseur) | Requis |
| `GET` | `/products/{productId}/reviews/summary` | Évaluation moyenne et distribution | Requis |
| `PUT` | `/products/{productId}/reviews/{reviewId}` | Mettre à jour un avis | Propriétaire uniquement |
| `DELETE` | `/products/{productId}/reviews/{reviewId}` | Supprimer un avis | Propriétaire uniquement |

---

## Schéma DB

```sql
CREATE TABLE reviews (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    product_id INTEGER NOT NULL,
    user_id INTEGER NOT NULL,
    rating INTEGER NOT NULL CHECK (rating >= 1 AND rating <= 5),
    body TEXT,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    UNIQUE (product_id, user_id),
    FOREIGN KEY (product_id) REFERENCES products(id),
    FOREIGN KEY (user_id) REFERENCES users(id)
);
```

---

## Contrainte 1 utilisateur / 1 produit / 1 avis

La contrainte `UNIQUE(product_id, user_id)` prévient les avis dupliqués au niveau de la couche données.

La couche applicative vérifie également en amont et retourne un 409 Conflict :

```php
if ($this->repository->findByProductAndUser($productId, $userId) !== null) {
    return $this->json->create(['error' => 'already reviewed'], 409);
}
```

Après suppression, la republication est possible (la contrainte UNIQUE est levée).

---

## Validation de l'évaluation

```php
// is_int() rejette les nombres flottants (4.5 en JSON est un float → 422)
if (!isset($body['rating']) || !is_int($body['rating'])) {
    $errors[] = new ValidationError('rating', 'Rating must be an integer.', 'required');
} elseif ($body['rating'] < 1 || $body['rating'] > 5) {
    $errors[] = new ValidationError('rating', 'Rating must be between 1 and 5.', 'out_of_range');
}
```

| Valeur | Résultat |
|---|---|
| `5` (int) | Accepté |
| `4.5` (float) | 422 |
| `0` | 422 |
| `6` | 422 |
| Absent | 422 |

---

## Agrégation des évaluations (Summary)

```sql
-- Total et moyenne
SELECT COUNT(*) as total, AVG(rating) as avg_rating
FROM reviews WHERE product_id = ?

-- Distribution par étoile
SELECT COUNT(*) as cnt FROM reviews WHERE product_id = ? AND rating = ?
```

Exemple de réponse :

```json
GET /products/1/reviews/summary

{
    "total": 150,
    "avg_rating": 4.23,
    "distribution": {
        "1": 5,
        "2": 8,
        "3": 20,
        "4": 52,
        "5": 65
    }
}
```

S'il n'y a aucun avis, `"avg_rating": null`.

---

## Vérification de propriété (mise à jour / suppression)

```php
$review = $this->repository->findReviewById($reviewId);
if ($review === null || (int) $review['product_id'] !== $productId) {
    return $this->json->create(['error' => 'review not found'], 404);
}
if ((int) $review['user_id'] !== $userId) {
    return $this->json->create(['error' => 'forbidden'], 403);
}
```

La vérification croisée du `product_id` retourne également 404 si un ID d'avis d'un autre produit est spécifié.

---

## Pagination par curseur

```
GET /products/1/reviews?limit=20
→ { items: [...], next_cursor: 42 }

GET /products/1/reviews?limit=20&before_id=42
→ { items: [...], next_cursor: null }
```

`next_cursor: null` indique que la fin est atteinte.

---

## Exemple d'implémentation (FT154)

`/home/xi/docker/NENE2-FT/reviewlog/`
