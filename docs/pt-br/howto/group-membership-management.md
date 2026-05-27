# Como Construir Gerenciamento de Membros de Grupo com NENE2

Este guia percorre a construção de um sistema de grupos onde usuários criam grupos, convidam membros com papéis (owner/admin/member), gerenciam memberships e controlam promoção de papéis.

**Field Trial**: FT138  
**Versão do NENE2**: ^1.5  
**Tópicos abordados**: memberships baseadas em papéis, auto-entrada do owner, saída voluntária, armadilha de palavra reservada no MySQL (`groups`), avaliação de vulnerabilidades

---

## O que estamos construindo

- `POST /groups` — criar um grupo (criador torna-se owner)
- `GET /groups/{groupId}/members` — listar membros (apenas membros)
- `POST /groups/{groupId}/members` — adicionar um membro (apenas owner/admin, papel: member ou admin)
- `DELETE /groups/{groupId}/members/{userId}` — remover membro (owner/admin pode remover outros; qualquer um pode sair voluntariamente)
- `PUT /groups/{groupId}/members/{userId}/role` — alterar papel (apenas owner)

---

## Schema do banco de dados — IMPORTANTE: evitar `groups` como nome de tabela

`groups` é uma **palavra reservada no MySQL** (usada em `GROUP BY`). Use `user_groups` em vez disso.

```sql
-- SQLite
CREATE TABLE user_groups (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT    NOT NULL,
    owner_id   INTEGER NOT NULL,
    created_at TEXT    NOT NULL,
    FOREIGN KEY (owner_id) REFERENCES users(id)
);

CREATE TABLE memberships (
    id        INTEGER PRIMARY KEY AUTOINCREMENT,
    group_id  INTEGER NOT NULL,
    user_id   INTEGER NOT NULL,
    role      TEXT    NOT NULL DEFAULT 'member',
    joined_at TEXT    NOT NULL,
    UNIQUE (group_id, user_id),
    CHECK (role IN ('owner', 'admin', 'member')),
    FOREIGN KEY (group_id) REFERENCES user_groups(id),
    FOREIGN KEY (user_id)  REFERENCES users(id)
);
```

```sql
-- MySQL
CREATE TABLE user_groups (
    id         INT          NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name       VARCHAR(255) NOT NULL,
    owner_id   INT          NOT NULL,
    created_at VARCHAR(32)  NOT NULL,
    FOREIGN KEY (owner_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE memberships (
    id        INT         NOT NULL AUTO_INCREMENT PRIMARY KEY,
    group_id  INT         NOT NULL,
    user_id   INT         NOT NULL,
    role      VARCHAR(16) NOT NULL DEFAULT 'member',
    joined_at VARCHAR(32) NOT NULL,
    UNIQUE KEY uq_group_user (group_id, user_id),
    CONSTRAINT chk_role CHECK (role IN ('owner', 'admin', 'member')),
    FOREIGN KEY (group_id) REFERENCES user_groups(id),
    FOREIGN KEY (user_id)  REFERENCES users(id)
) ENGINE=InnoDB;
```

---

## Enum de papel com métodos de capacidade

```php
enum MemberRole: string
{
    case Owner  = 'owner';
    case Admin  = 'admin';
    case Member = 'member';

    public function canManageMembers(): bool
    {
        return $this === self::Owner || $this === self::Admin;
    }

    public function canChangeRoles(): bool
    {
        return $this === self::Owner;
    }
}
```

Métodos de capacidade no enum mantêm a lógica de autorização fora dos handlers.

---

## Auto-entrada do owner na criação do grupo

Quando um grupo é criado, o owner é automaticamente adicionado como membro com o papel `owner`:

```php
public function createGroup(string $name, int $ownerId, string $now): int
{
    $this->executor->execute(
        'INSERT INTO user_groups (name, owner_id, created_at) VALUES (?, ?, ?)',
        [$name, $ownerId, $now],
    );

    $groupId = (int) $this->executor->lastInsertId();

    // Owner é automaticamente membro com papel 'owner'
    $this->executor->execute(
        'INSERT INTO memberships (group_id, user_id, role, joined_at) VALUES (?, ?, ?, ?)',
        [$groupId, $ownerId, 'owner', $now],
    );

    return $groupId;
}
```

---

## Handler de adicionar membro — validação de papel

O papel `owner` não pode ser atribuído via API de adicionar-membro. Padrão `TokenScope::tryFrom()` aplicado a `MemberRole::tryFrom()`:

```php
$role = MemberRole::tryFrom($roleValue);

if ($role === null || $role === MemberRole::Owner) {
    return $this->responseFactory->create(['error' => 'role must be member or admin'], 422);
}
```

---

## Remover membro — saída voluntária e remoção por admin

Um membro pode sair do próprio grupo (saída voluntária) sem direitos de admin. Admins podem remover outros. O owner nunca pode ser removido:

```php
$isSelfLeave = $actorId === $userId;

if (!$isSelfLeave && !$actorRole->canManageMembers()) {
    return $this->responseFactory->create(['error' => 'only owner or admin can remove members'], 403);
}

$targetRole = MemberRole::tryFrom($targetMembership['role']) ?? MemberRole::Member;

if ($targetRole === MemberRole::Owner) {
    return $this->responseFactory->create(['error' => 'cannot remove the group owner'], 422);
}
```

---

## Teardown de FK no MySQL — a ordem importa

Ao resetar o MySQL em testes, dropar tabelas dependentes de FK primeiro com `FOREIGN_KEY_CHECKS = 0`:

```php
$this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
$this->pdo->exec('DROP TABLE IF EXISTS memberships');
$this->pdo->exec('DROP TABLE IF EXISTS user_groups');
$this->pdo->exec('DROP TABLE IF EXISTS users');
$this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
```

---

## Avaliação de vulnerabilidades (FT138)

Doze testes de vulnerabilidade verificam:

| ID | Ataque | Esperado | Resultado |
|----|--------|---------|-----------|
| VULN-A | IDOR: não-membro lê lista de membros | 403 | Pass |
| VULN-B | IDOR: não-membro adiciona um membro | 403 | Pass |
| VULN-C | Membro regular tenta adicionar alguém | 403 | Pass |
| VULN-D | Admin tenta definir papel de owner | não 200 | Pass |
| VULN-E | Membro tenta se autopromover para admin | 403 | Pass |
| VULN-F | Remover owner do grupo | 422 | Pass |
| VULN-G | X-User-Id faltando na criação | não 201 | Pass |
| VULN-H | X-User-Id não numérico | não 200 | Pass |
| VULN-I | SQL injection no nome do grupo | 201 (verbatim) | Pass |
| VULN-J | Operação de membro entre grupos | 403 | Pass |
| VULN-K | ID de grupo negativo | 404 | Pass |
| VULN-L | Admin não pode alterar papéis | 403 | Pass |

Todos os 12 testes de vulnerabilidade passam. Nenhuma vulnerabilidade encontrada.

---

## Armadilhas comuns

| Armadilha | Correção |
|-----------|---------|
| Usar `groups` como nome de tabela no MySQL | Usar `user_groups` — `groups` é palavra reservada no MySQL |
| Owner não adicionado automaticamente às memberships | Fazer INSERT da membership do owner em `createGroup()` |
| Admin conseguindo alterar papéis | `canChangeRoles()` retorna true apenas para `Owner` |
| Permitir papel `owner` via API de adicionar-membro | Rejeitar `role === MemberRole::Owner` → 422 |
| Não-membro bypassa 403 via ator ausente | Verificar `findMembership(groupId, actorId) !== null` |
| DROP TABLE no MySQL falha com constraints FK | `SET FOREIGN_KEY_CHECKS = 0` antes do DROP |
