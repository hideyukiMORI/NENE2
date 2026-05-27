# Como Fazer: API de Reações com Emoji

> **Referência FT**: FT306 (`NENE2-FT/emojilog`) — Reações com emoji: UNIQUE(post_id, user_id, emoji) permite o mesmo emoji por múltiplos usuários mas impede que um usuário reaja com o mesmo emoji duas vezes, mb_strlen máx 8 caracteres, urldecode() para emoji no caminho DELETE, user_reactions mostra as reações do ator atual, reações ordenadas por count DESC, 18 testes / 28 asserções PASS.

Este guia mostra como implementar um sistema de reações com emoji onde múltiplos usuários podem reagir a um post com qualquer emoji, mas cada usuário pode usar um emoji específico apenas uma vez por post.

## Schema

```sql
CREATE TABLE reactions (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    post_id    INTEGER NOT NULL,
    user_id    INTEGER NOT NULL,
    emoji      TEXT    NOT NULL,
    created_at TEXT    NOT NULL,
    UNIQUE (post_id, user_id, emoji),
    FOREIGN KEY (post_id) REFERENCES posts(id),
    FOREIGN KEY (user_id) REFERENCES users(id)
);
```

`UNIQUE(post_id, user_id, emoji)` permite:
- Mesmo emoji por múltiplos usuários: Alice e Bob podem ambos reagir com `👍`
- Emojis diferentes pelo mesmo usuário: Alice pode usar tanto `👍` quanto `❤️`

Mas impede:
- Mesmo usuário + mesmo emoji duas vezes: Alice não pode usar `👍` no mesmo post duas vezes

## Endpoints

| Método | Caminho | Auth | Descrição |
|--------|---------|------|-----------|
| `GET` | `/posts/{id}/reactions` | `X-User-Id` (opcional) | Obter contagens de reações + reações do ator |
| `POST` | `/posts/{id}/reactions` | `X-User-Id` | Adicionar reação |
| `DELETE` | `/posts/{id}/reactions/{emoji}` | `X-User-Id` | Remover reação |

## Adicionar reação — Validação estrita

```php
if (!isset($body['emoji']) || !is_string($body['emoji']) || trim($body['emoji']) === '') {
    return $this->responseFactory->create(['error' => 'emoji is required'], 422);
}
$emoji = trim($body['emoji']);
if (mb_strlen($emoji) > 8) {
    return $this->responseFactory->create(['error' => 'emoji too long'], 422);
}

$added = $this->repository->addReaction($postId, $actorId, $emoji, date('c'));
if (!$added) {
    return $this->responseFactory->create(['error' => 'already reacted with this emoji'], 409);
}
```

- Verificação `is_string()` rejeita tipos não-string
- `trim()` antes da verificação de vazio previne emoji apenas de espaço em branco
- `mb_strlen()` — não `strlen()` — para contagem correta de caracteres multibyte
- Adição duplicada → 409 Conflict (não 422)

## Remover reação — Decodificação de URL para emoji no caminho

```php
$emoji = isset($params['emoji']) && is_string($params['emoji']) ? urldecode($params['emoji']) : '';
if ($emoji === '') {
    return $this->responseFactory->create(['error' => 'invalid emoji'], 404);
}
```

Caracteres emoji em segmentos de caminho URL devem ser URL-encoded pelos clientes. `urldecode()` restaura o emoji original para consulta no DB. Exemplo: `DELETE /posts/1/reactions/%F0%9F%91%8D` → consulta `👍`.

## Resposta de contagens de reações

```php
// Agrupar por emoji, contar, ordenar por count DESC
$counts = $this->repository->getReactionCounts($postId);

// Se o ator for fornecido, mostrar quais emojis ele usou
$userReactions = [];
if ($actorId !== null) {
    $userReactions = $this->repository->getUserReactions($postId, $actorId);
}

return $this->responseFactory->create([
    'post_id'        => $postId,
    'reactions'      => $counts,        // [{emoji, count}, ...] ordenado por count DESC
    'user_reactions' => $userReactions, // ['👍', '❤️', ...] para o ator atual
]);
```

`user_reactions` está vazio quando nenhum header `X-User-Id` é fornecido — este campo mostra as reações do visualizador atual para ajudar os frontends a destacar suas reações ativas.

---

## O Que NÃO Fazer

| Anti-padrão | Risco |
|---|---|
| `UNIQUE(post_id, user_id)` (sem coluna emoji) | Um usuário só pode usar um emoji por post |
| `strlen()` para verificação de comprimento de emoji | Emojis multibyte como `🎉` (4 bytes) seriam contados errado |
| Sem `urldecode()` no emoji do caminho | `👍` como `%F0%9F%91%8D` nunca corresponde ao `👍` armazenado |
| Retornar 404 para reação duplicada | Esconde a semântica 409 — reações duplicadas são conflitos, não recursos ausentes |
| Sem limite de comprimento de emoji | Strings de comprimento arbitrário armazenadas como coluna emoji |
| `user_reactions` vazio sem ator, mas ainda inclui a chave | Omita ou retorne `[]` — ambos são válidos, mas documente o comportamento |
| `trim()` após verificação de vazio | `"  "` com apenas espaço em branco passa como válido |
