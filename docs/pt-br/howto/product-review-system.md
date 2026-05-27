# Sistema de Avaliação e Comentários de Produto

Como implementar um sistema de avaliação e comentários de produto com NENE2.
Inclui restrição de 1 usuário / 1 produto / 1 comentário, agregação de avaliações (média, distribuição) e CRUD.

---

## Lista de Endpoints

| Método | Caminho | Descrição | Auth |
|---|---|---|---|
| `POST` | `/products/{productId}/reviews` | Publicar comentário | Obrigatório |
| `GET` | `/products/{productId}/reviews` | Listar comentários (cursor) | Obrigatório |
| `GET` | `/products/{productId}/reviews/summary` | Avaliação média e distribuição | Obrigatório |
| `PUT` | `/products/{productId}/reviews/{reviewId}` | Atualizar comentário | Apenas próprio |
| `DELETE` | `/products/{productId}/reviews/{reviewId}` | Excluir comentário | Apenas próprio |

---

## Schema do Banco de Dados

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

## Imposição de 1 Usuário / 1 Produto / 1 Comentário

A restrição `UNIQUE(product_id, user_id)` previne comentários duplicados na camada de dados.

Verificação prévia também na camada de aplicação, retornando 409 Conflict:

```php
if ($this->repository->findByProductAndUser($productId, $userId) !== null) {
    return $this->json->create(['error' => 'already reviewed'], 409);
}
```

Após a exclusão, é possível postar novamente (a restrição UNIQUE é liberada).

---

## Validação de Avaliação

```php
// is_int() rejeita números de ponto flutuante (4.5 em JSON é float → 422)
if (!isset($body['rating']) || !is_int($body['rating'])) {
    $errors[] = new ValidationError('rating', 'Rating must be an integer.', 'required');
} elseif ($body['rating'] < 1 || $body['rating'] > 5) {
    $errors[] = new ValidationError('rating', 'Rating must be between 1 and 5.', 'out_of_range');
}
```

| Valor | Resultado |
|---|---|
| `5` (int) | Passa |
| `4.5` (float) | 422 |
| `0` | 422 |
| `6` | 422 |
| Omitido | 422 |

---

## Agregação de Avaliações (Summary)

```sql
-- Total e média
SELECT COUNT(*) as total, AVG(rating) as avg_rating
FROM reviews WHERE product_id = ?

-- Distribuição por estrela
SELECT COUNT(*) as cnt FROM reviews WHERE product_id = ? AND rating = ?
```

Exemplo de resposta:

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

Quando não há comentários, `"avg_rating": null`.

---

## Verificação de Propriedade (Atualizar/Excluir)

```php
$review = $this->repository->findReviewById($reviewId);
if ($review === null || (int) $review['product_id'] !== $productId) {
    return $this->json->create(['error' => 'review not found'], 404);
}
if ((int) $review['user_id'] !== $userId) {
    return $this->json->create(['error' => 'forbidden'], 403);
}
```

A verificação cruzada de `product_id` também retorna 404 quando um ID de comentário de outro produto é especificado.

---

## Paginação por Cursor

```
GET /products/1/reviews?limit=20
→ { items: [...], next_cursor: 42 }

GET /products/1/reviews?limit=20&before_id=42
→ { items: [...], next_cursor: null }
```

`next_cursor: null` indica o fim da lista.

---

## Exemplo de Implementação (FT154)

`/home/xi/docker/NENE2-FT/reviewlog/`
