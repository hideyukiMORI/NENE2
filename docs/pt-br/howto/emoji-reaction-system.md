# Como Construir um Sistema de Reações com Emoji com NENE2

Este guia percorre a construção de um sistema de reações onde usuários reagem a posts com emojis, com contagens agrupadas e rastreamento de reações por usuário.

**Field Trial**: FT143  
**Versão do NENE2**: ^1.5  
**Tópicos abordados**: constraint UNIQUE(post_id, user_id, emoji), contagens GROUP BY emoji, rastreamento de reações por usuário, validação de comprimento de emoji, testes de integração MySQL

---

## O que estamos construindo

- `POST /posts` — criar um post
- `POST /posts/{id}/reactions` — adicionar uma reação (string emoji, uma por emoji por usuário)
- `DELETE /posts/{id}/reactions/{emoji}` — remover uma reação (apenas própria)
- `GET /posts/{id}/reactions` — obter contagens de reações e as reações do usuário atual

---

## Schema do banco de dados

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

`UNIQUE (post_id, user_id, emoji)` — uma linha por emoji por usuário por post. O mesmo usuário pode reagir com emojis diferentes (👍 e ❤️ = 2 linhas). Múltiplos usuários podem usar o mesmo emoji (cada um recebe sua própria linha).

---

## Reação duplicada → 409

```php
public function addReaction(int $postId, int $userId, string $emoji, string $now): bool
{
    try {
        $this->executor->execute(
            'INSERT INTO reactions (post_id, user_id, emoji, created_at) VALUES (?, ?, ?, ?)',
            [$postId, $userId, $emoji, $now],
        );
        return true;
    } catch (DatabaseConstraintException) {
        return false;
    }
}
```

O handler retorna 409 quando `addReaction()` retorna `false`. Nenhuma verificação de existência separada é necessária.

---

## Contagens de reações agrupadas com GROUP BY

```sql
SELECT emoji, COUNT(*) as cnt
FROM reactions
WHERE post_id = ?
GROUP BY emoji
ORDER BY cnt DESC, emoji ASC
```

Ordenado por contagem decrescente (emoji mais popular primeiro), depois alfabeticamente como desempate. O resultado mapeia diretamente para um `array<string, int>` PHP:

```php
$counts = [];
foreach ($rows as $row) {
    $arr = (array) $row;
    if (isset($arr['emoji']) && is_string($arr['emoji'])) {
        $counts[$arr['emoji']] = isset($arr['cnt']) ? (int) $arr['cnt'] : 0;
    }
}
```

---

## Reações por usuário (ator opcional)

O endpoint `GET /reactions` aceita um header opcional `X-User-Id`. Quando presente, a resposta inclui a lista de emojis que o chamador utilizou:

```php
$actorId       = (int) $request->getHeaderLine('X-User-Id');
$userReactions = $actorId > 0 ? $this->repository->getUserReactions($postId, $actorId) : [];
```

Isso permite que a UI mostre quais emojis o usuário atual já utilizou para reagir.

---

## Validação de emoji

```php
if (mb_strlen($emoji) > 8) {
    return $this->responseFactory->create(['error' => 'emoji too long'], 422);
}
```

`mb_strlen` conta code points Unicode, não bytes. Um único emoji como 🧑‍💻 (pessoa: tecnologista) tem 3 code points; um limite de 8 chars acomoda a maioria das sequências de emoji. Ajuste conforme seus requisitos.

---

## Testes de integração MySQL (FT143)

A ordem de teardown no MySQL importa:

```php
$this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
$this->pdo->exec('DROP TABLE IF EXISTS reactions');
$this->pdo->exec('DROP TABLE IF EXISTS posts');
$this->pdo->exec('DROP TABLE IF EXISTS users');
$this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
```

O schema MySQL usa `VARCHAR(32)` para emoji (não `TEXT`) para permitir a coluna em uma chave UNIQUE sem comprimento de prefixo. `VARCHAR(32)` armazena até 32 caracteres, o que cobre todas as sequências de emoji.

---

## Armadilhas comuns

| Armadilha | Correção |
|-----------|----------|
| Permitir reações de emoji duplicadas | `UNIQUE (post_id, user_id, emoji)` + capturar `DatabaseConstraintException` |
| Usar `strlen()` para comprimento de emoji | Use `mb_strlen()` — emojis são Unicode multi-byte |
| Coluna de contagem mutável fica dessincronizada | Contar da tabela `reactions` com `GROUP BY emoji` |
| Falta de suporte a emoji no MySQL | Use charset `utf8mb4` e `VARCHAR` (não `CHAR`) para a coluna de emoji |
| `is_array()` no resultado de `fetchAll` é sempre true | Pule a verificação; `fetchAll` já retorna `array<int, array<string, mixed>>` |
