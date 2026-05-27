# Como Fazer: Sistema de Votação ao Vivo

## Visão Geral

Este guia cobre a construção de uma API de sistema de votação ao vivo com NENE2, incluindo criação de enquetes com controle de admin, deduplicação de votos por usuário, gerenciamento de ciclo de vida de enquetes e agregação de resultados.

**Implementação de referência**: `../NENE2-FT/polllog/`

---

## Design do Schema

```sql
CREATE TABLE IF NOT EXISTS polls (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    question   TEXT    NOT NULL,
    closed     INTEGER NOT NULL DEFAULT 0,
    created_at TEXT    NOT NULL
);

CREATE TABLE IF NOT EXISTS poll_options (
    id      INTEGER PRIMARY KEY AUTOINCREMENT,
    poll_id INTEGER NOT NULL,
    label   TEXT    NOT NULL,
    FOREIGN KEY (poll_id) REFERENCES polls(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS poll_votes (
    id        INTEGER PRIMARY KEY AUTOINCREMENT,
    poll_id   INTEGER NOT NULL,
    option_id INTEGER NOT NULL,
    user_id   INTEGER NOT NULL,
    voted_at  TEXT    NOT NULL,
    UNIQUE (poll_id, user_id),
    FOREIGN KEY (poll_id) REFERENCES polls(id) ON DELETE CASCADE,
    FOREIGN KEY (option_id) REFERENCES poll_options(id) ON DELETE CASCADE
);
```

Constraints principais:
- `UNIQUE (poll_id, user_id)` — impede um usuário de votar mais de uma vez por enquete.
- `ON DELETE CASCADE` — remove opções e votos quando uma enquete é deletada.

---

## Tabela de Rotas

| Método | Caminho | Auth | Descrição |
|--------|------|------|-------------|
| `POST` | `/polls` | Admin | Criar uma enquete com opções |
| `GET` | `/polls` | Nenhuma | Listar todas as enquetes |
| `GET` | `/polls/{id}` | Nenhuma | Obter enquete com contagens de votos |
| `POST` | `/polls/{id}/vote` | Usuário | Registrar um voto |
| `POST` | `/polls/{id}/close` | Admin | Fechar uma enquete |

---

## Padrão de Autenticação Admin

Passe um segredo compartilhado no header `X-Admin-Key`. Use lógica fail-closed:

```php
private function isAdmin(ServerRequestInterface $req): bool
{
    if ($this->adminKey === '') {
        return false;          // fail-closed: sem chave configurada → nunca admin
    }
    return hash_equals($this->adminKey, $req->getHeaderLine('X-Admin-Key'));
}
```

Retorne `403 Forbidden` quando não for admin:
```php
if (!$this->isAdmin($req)) {
    return $this->problem(403, 'forbidden', 'Admin key required.');
}
```

---

## Criando Enquetes com Opções

Valide pelo menos 2 opções; insira em uma transação:

```php
public function create(string $question, array $options): array
{
    $now  = $this->now();
    $stmt = $this->pdo->prepare('INSERT INTO polls (question, closed, created_at) VALUES (?, 0, ?)');
    $stmt->execute([$question, $now]);
    $pollId = (int) $this->pdo->lastInsertId();

    $ins = $this->pdo->prepare('INSERT INTO poll_options (poll_id, label) VALUES (?, ?)');
    foreach ($options as $label) {
        $ins->execute([$pollId, $label]);
    }

    return $this->findById($pollId);
}
```

---

## Voto com Deduplicação

Capture a violação de constraint UNIQUE para detectar votos duplicados:

```php
public function vote(int $pollId, int $optionId, int $userId): string
{
    $poll = $this->findById($pollId);
    if ($poll === null) return 'not_found';
    if ($poll['closed']) return 'poll_closed';

    // Verificar se a opção pertence a esta enquete
    $stmt = $this->pdo->prepare('SELECT id FROM poll_options WHERE id = ? AND poll_id = ?');
    $stmt->execute([$optionId, $pollId]);
    if ($stmt->fetch() === false) return 'invalid_option';

    try {
        $this->pdo->prepare(
            'INSERT INTO poll_votes (poll_id, option_id, user_id, voted_at) VALUES (?, ?, ?, ?)'
        )->execute([$pollId, $optionId, $userId, $this->now()]);
    } catch (\PDOException $e) {
        if (str_contains($e->getMessage(), 'UNIQUE')) return 'already_voted';
        throw $e;
    }

    return 'ok';
}
```

---

## Agregando Contagens de Votos

Use `LEFT JOIN` para incluir opções com zero votos:

```sql
SELECT po.id, po.label, COUNT(pv.id) AS votes
FROM poll_options po
LEFT JOIN poll_votes pv ON pv.option_id = po.id
WHERE po.poll_id = :poll_id
GROUP BY po.id, po.label
ORDER BY po.id ASC
```

---

## Códigos de Status HTTP

| Situação | Status |
|-----------|--------|
| Enquete criada | 201 |
| Voto registrado | 201 |
| Enquete encontrada / fechada | 200 |
| Enquete não encontrada | 404 |
| ID de opção inválido | 422 |
| Questão ausente ou < 2 opções | 422 |
| option_id não-inteiro | 422 |
| Já votou | 409 |
| Votando em enquete fechada | 409 |
| Sem chave admin | 403 |
| Sem header X-User-Id | 400 |

---

## Checklist de Validação

- `question`: string não-vazia
- `options`: array de ≥ 2 strings não-vazias
- `option_id`: deve ser `is_int()` (rejeitar strings como `'1'`)
- `X-User-Id`: `ctype_digit()` + inteiro positivo
- Enquete deve existir antes de votar ou fechar
- Opção deve pertencer à enquete alvo (injeção entre enquetes)

---

## Notas de Segurança

- **Admin key fail-closed**: chave vazia significa que ninguém é admin.
- **Use `hash_equals()`** para prevenir ataques de temporização na comparação da chave admin.
- **Constraint UNIQUE** é a guarda autoritativa de voto duplicado — verificação somente no nível da aplicação não é suficiente sob carga concorrente.
- **Verificação de propriedade de opção** previne votar com uma opção de uma enquete diferente.
