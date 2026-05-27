# Como Fazer: API de Enquete / Pesquisa

Este guia mostra como construir um sistema de enquete e pesquisa com prevenção de votos duplicados usando NENE2.
Padrão demonstrado pelo field trial **polllog** (FT217).

## Funcionalidades

- Criar enquetes com 2–20 opções (apenas admin)
- Enquetes públicas e privadas (privada: acesso apenas para admin)
- Um voto por usuário por enquete (aplicado por restrição UNIQUE)
- Agregação de resultados em tempo real com contagem de votos por opção
- Soma total de votos em todas as opções

## Schema

```sql
CREATE TABLE IF NOT EXISTS polls (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    question   TEXT    NOT NULL,
    is_public  INTEGER NOT NULL DEFAULT 1,
    created_at TEXT    NOT NULL
);

CREATE TABLE IF NOT EXISTS poll_options (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    poll_id    INTEGER NOT NULL,
    label      TEXT    NOT NULL,
    sort_order INTEGER NOT NULL DEFAULT 0,
    FOREIGN KEY (poll_id) REFERENCES polls(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS votes (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    poll_id    INTEGER NOT NULL,
    option_id  INTEGER NOT NULL,
    user_id    INTEGER NOT NULL,
    created_at TEXT    NOT NULL,
    UNIQUE (poll_id, user_id),  -- Um voto por usuário por enquete
    FOREIGN KEY (poll_id) REFERENCES polls(id) ON DELETE CASCADE,
    FOREIGN KEY (option_id) REFERENCES poll_options(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_votes_poll ON votes (poll_id, option_id);
```

## Endpoints

| Método | Caminho | Auth | Descrição |
|--------|---------|------|-----------|
| `POST` | `/polls` | Admin | Criar enquete com opções |
| `GET` | `/polls/{id}` | Público | Obter enquete (privada → 404 para não-admin) |
| `POST` | `/polls/{id}/vote` | Usuário | Votar |
| `GET` | `/polls/{id}/results` | Público | Obter contagem de resultados por opção |

## Validação de Opções

```php
private const int MIN_OPTIONS   = 2;
private const int MAX_OPTIONS   = 20;
private const int MAX_LABEL_LEN = 100;

foreach ($rawOptions as $idx => $label) {
    if (!is_string($label) || trim($label) === '') {
        return $this->problem(422, 'validation-failed', "options[{$idx}] must not be empty.");
    }
    if (strlen($label) > self::MAX_LABEL_LEN) {
        return $this->problem(422, 'validation-failed', "options[{$idx}] too long (max 100).");
    }
}
```

## Prevenção de Voto Duplicado

```php
/** @return 'ok'|'already_voted'|'invalid_option' */
public function vote(int $pollId, int $userId, int $optionId): string
{
    // Verificar se a opção pertence à enquete (previne injeção de opção entre enquetes)
    $stmt = $this->pdo->prepare(
        'SELECT id FROM poll_options WHERE id = :oid AND poll_id = :pid'
    );
    $stmt->execute([':oid' => $optionId, ':pid' => $pollId]);
    if ($stmt->fetch() === false) {
        return 'invalid_option'; // → 422
    }

    // Verificar voto existente
    $stmt2 = $this->pdo->prepare(
        'SELECT id FROM votes WHERE poll_id = :pid AND user_id = :uid'
    );
    if ($stmt2->fetch() !== false) {
        return 'already_voted'; // → 409
    }

    // INSERT — restrição UNIQUE(poll_id, user_id) é a rede de segurança
    $this->pdo->prepare('INSERT INTO votes ...')->execute([...]);
    return 'ok';
}
```

## Agregação de Resultados

Usar `LEFT JOIN` garante que opções com zero votos ainda apareçam nos resultados:

```sql
SELECT o.id, o.label, o.sort_order,
       COUNT(v.id) AS votes
FROM poll_options o
LEFT JOIN votes v ON v.option_id = o.id AND v.poll_id = o.poll_id
WHERE o.poll_id = :pid
GROUP BY o.id, o.label, o.sort_order
ORDER BY o.sort_order ASC, o.id ASC
```

```php
$results    = $this->repo->results($id);
$totalVotes = array_sum(array_column($results, 'votes'));

return $this->json([
    'poll_id'     => $id,
    'total_votes' => $totalVotes,
    'results'     => $results,
]);
```

## Controle de Acesso para Enquetes Privadas

Enquetes privadas retornam 404 para usuários não-admin (ocultação de existência):

```php
// GET /polls/{id}
if (!(bool) $poll['is_public'] && !$this->isAdmin($req)) {
    return $this->problem(404, 'not-found', 'Poll not found.');
}
```

## Padrões de Segurança

- **Admin fail-closed**: `if ($this->adminKey === '') return false;` antes de `hash_equals()`
- **`is_int()`**: Verificação estrita de tipo para `option_id` — rejeita floats/strings
- **`ctype_digit()`**: Validação de inteiro segura contra ReDoS para IDs de caminho
- **Injeção de opção entre enquetes**: `WHERE id = :oid AND poll_id = :pid` previne usar opção de enquete diferente
- **`is_bool()`**: Verificação estrita para flag `is_public` — rejeita `1`/`0`/`"true"` etc.
