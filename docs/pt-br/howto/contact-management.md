# Como Fazer: API de Gerenciamento de Contatos

> **Referência FT**: FT238 (`NENE2-FT/contactlog`) — API de Gerenciamento de Contatos

Demonstra uma API de gerenciamento de contatos com CRUD com escopo por proprietário, um sistema de
grupos de contatos many-to-many, pesquisa full-text com `LIKE` combinada com filtragem por grupo
via `EXISTS`, e operações de associação a grupos idempotentes respaldadas por tratamento de
`DatabaseConstraintException`.

---

## Rotas

| Método   | Caminho                                                   | Descrição                            |
|----------|-----------------------------------------------------------|--------------------------------------|
| `POST`   | `/owners/{ownerId}/contacts`                              | Criar um contato                     |
| `GET`    | `/owners/{ownerId}/contacts`                              | Buscar contatos (opcionais `?q=`, `?group_id=`) |
| `GET`    | `/owners/{ownerId}/contacts/{id}`                         | Obter um contato                     |
| `PUT`    | `/owners/{ownerId}/contacts/{id}`                         | Atualizar um contato (substituição completa) |
| `DELETE` | `/owners/{ownerId}/contacts/{id}`                         | Deletar um contato                   |
| `POST`   | `/owners/{ownerId}/groups`                                | Criar um grupo                       |
| `PUT`    | `/owners/{ownerId}/contacts/{contactId}/groups/{groupId}` | Adicionar contato ao grupo           |
| `DELETE` | `/owners/{ownerId}/contacts/{contactId}/groups/{groupId}` | Remover contato do grupo             |

`{ownerId}` escopa todas as operações a um proprietário — contatos e grupos criados por um
proprietário são invisíveis para outros.

---

## Schema: contacts, groups, contact_groups

```sql
CREATE TABLE IF NOT EXISTS contacts (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    owner_id   TEXT    NOT NULL,
    name       TEXT    NOT NULL,
    email      TEXT    NOT NULL DEFAULT '',
    phone      TEXT    NOT NULL DEFAULT '',
    notes      TEXT    NOT NULL DEFAULT '',
    created_at TEXT    NOT NULL,
    updated_at TEXT    NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_contacts_owner ON contacts (owner_id);

CREATE TABLE IF NOT EXISTS groups (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    owner_id   TEXT    NOT NULL,
    name       TEXT    NOT NULL,
    created_at TEXT    NOT NULL,
    UNIQUE(owner_id, name)
);

CREATE TABLE IF NOT EXISTS contact_groups (
    contact_id INTEGER NOT NULL,
    group_id   INTEGER NOT NULL,
    PRIMARY KEY (contact_id, group_id)
);
```

Escolhas de design principais:
- `contact_groups` usa `PRIMARY KEY (contact_id, group_id)` composta — pode existir no
  máximo uma linha por par (contato, grupo). Tentar inserir uma duplicata gera um erro de
  constraint.
- `groups.UNIQUE(owner_id, name)` previne nomes de grupo duplicados dentro de um mesmo proprietário.
- `email`, `phone`, `notes` têm padrão `''` — sem necessidade de tratar NULL para campos opcionais.

---

## Prevenção de IDOR: owner_id em todas as consultas

Todas as operações de leitura e escrita incluem `owner_id` na cláusula `WHERE`:

```php
public function findById(int $id, string $ownerId): ?Contact
{
    $rows = $this->db->fetchAll(
        'SELECT * FROM contacts WHERE id = ? AND owner_id = ?',
        [$id, $ownerId],
    );

    return $rows !== [] ? $this->hydrateWithGroups($rows[0]) : null;
}
```

Uma requisição para `/owners/alice/contacts/5` onde o contato 5 pertence a `bob` retorna
`null` → `404 Not Found`. O chamador não consegue distinguir "não existe" de "não é seu"
— isso previne a confirmação da existência do ID.

---

## Pesquisa: filtro dinâmico LIKE + EXISTS

O endpoint de listagem constrói uma cláusula `WHERE` dinâmica com base nos parâmetros de query opcionais:

```php
public function search(string $ownerId, ?string $query, ?string $groupId): array
{
    $conditions = ['c.owner_id = ?'];
    $bindings   = [$ownerId];

    if ($query !== null) {
        $conditions[] = '(c.name LIKE ? OR c.email LIKE ?)';
        $bindings[]   = "%{$query}%";
        $bindings[]   = "%{$query}%";
    }

    if ($groupId !== null) {
        $conditions[] = 'EXISTS (SELECT 1 FROM contact_groups cg WHERE cg.contact_id = c.id AND cg.group_id = ?)';
        $bindings[]   = (int) $groupId;
    }

    $where = 'WHERE ' . implode(' AND ', $conditions);
    $rows  = $this->db->fetchAll(
        "SELECT c.* FROM contacts c {$where} ORDER BY c.name ASC",
        $bindings,
    );

    return array_map(fn (array $row) => $this->hydrateWithGroups($row), $rows);
}
```

Padrões utilizados:
- **Acumulação dinâmica de condições**: comece com as condições obrigatórias (`owner_id`) e
  acrescente as opcionais. `implode(' AND ', $conditions)` as une com segurança.
- **`LIKE ? OR LIKE ?`**: LIKE parametrizado — sem injeção SQL. Os wildcards `%` ficam na
  string PHP, não no input do usuário. Porém, se `$query` contiver `%` ou `_`, esses
  caracteres são interpretados como wildcards pelo SQLite — escape-os com
  `str_replace(['%', '_'], ['\\%', '\\_'], $query)` se for necessário correspondência literal.
- **`EXISTS (SELECT 1 ...)`**: subconsulta correlacionada que filtra contatos pertencentes a um
  determinado grupo sem JOIN (evita linhas duplicadas quando um contato pertence a múltiplos grupos).

---

## Criação de grupo: nome duplicado → 409

`UNIQUE(owner_id, name)` em `groups` torna nomes de grupo duplicados dentro de um proprietário
um erro de constraint. O repositório o captura e retorna `null`:

```php
public function createGroup(string $ownerId, string $name): ?array
{
    try {
        $id = $this->db->insert(
            'INSERT INTO groups (owner_id, name, created_at) VALUES (?, ?, ?)',
            [$ownerId, $name, $now],
        );
    } catch (DatabaseConstraintException) {
        return null;  // nome do grupo já existe para este proprietário
    }
    // ...
}
```

O controller mapeia `null` para `409 Conflict`:

```php
$group = $this->repo->createGroup($ownerId, $name);

if ($group === null) {
    return $this->problems->create($request, 'conflict', 'Group Already Exists', 409,
        "Group {$name} already exists.");
}
```

`409` é o status correto — a requisição é válida, mas conflita com um recurso existente.

---

## Associação de grupo: adição idempotente via captura de constraint

Adicionar um contato a um grupo é idempotente — chamadas repetidas têm sucesso sem erro:

```php
public function addToGroup(int $contactId, int $groupId, string $ownerId): bool
{
    // Verificar se tanto o contato quanto o grupo pertencem a este proprietário
    $contact = $this->db->fetchOne('SELECT id FROM contacts WHERE id = ? AND owner_id = ?', [$contactId, $ownerId]);
    $group   = $this->db->fetchOne('SELECT id FROM groups WHERE id = ? AND owner_id = ?', [$groupId, $ownerId]);

    if ($contact === null || $group === null) {
        return false;  // → 404 Not Found
    }

    try {
        $this->db->execute(
            'INSERT INTO contact_groups (contact_id, group_id) VALUES (?, ?)',
            [$contactId, $groupId],
        );
    } catch (DatabaseConstraintException) {
        // Violação de PRIMARY KEY — contato já está no grupo. Tratar como sucesso (idempotente).
    }

    return true;
}
```

A `PRIMARY KEY (contact_id, group_id)` composta garante unicidade na camada de BD.
O padrão catch-and-ignore torna a operação segura para múltiplas chamadas — uma
associação já existente não é um erro do ponto de vista do chamador.

Tanto o `contact` quanto o `group` são verificados para pertencerem a `$ownerId` antes
de inserir a associação. Associações entre proprietários distintos (contato da Alice adicionado
ao grupo do Bob) são prevenidas.

---

## Remoção de associação de grupo

A remoção verifica a propriedade do contato e deleta se a associação existir:

```php
public function removeFromGroup(int $contactId, int $groupId, string $ownerId): bool
{
    $contact = $this->db->fetchOne('SELECT id FROM contacts WHERE id = ? AND owner_id = ?', [$contactId, $ownerId]);
    if ($contact === null) {
        return false;  // → 404
    }

    $count = $this->db->execute(
        'DELETE FROM contact_groups WHERE contact_id = ? AND group_id = ?',
        [$contactId, $groupId],
    );

    return $count > 0;  // false se a associação não existia → 404
}
```

Retornar `false` quando a associação não existe resulta em `404`, que é o correto:
o chamador tentou remover algo que não está lá.

---

## Howtos relacionados

- [`group-membership-management.md`](group-membership-management.md) — padrões de associação a grupos com base em papéis
- [`tagging-system.md`](tagging-system.md) — relacionamentos many-to-many de tags
- [`enforce-resource-ownership.md`](enforce-resource-ownership.md) — padrões de prevenção de IDOR
- [`use-fts5-search.md`](use-fts5-search.md) — busca full-text para datasets maiores
