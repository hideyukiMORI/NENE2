# Como Fazer: Gerenciamento de Membros de Grupo

> **Referência FT**: FT291 (`NENE2-FT/grouplog`) — Membros de grupo: enum MemberRole (owner/admin/member), UNIQUE(group_id, user_id), guarda owner-não-pode-ser-removido, prevenção de IDOR entre grupos, hierarquia de papéis canManageMembers()/canChangeRoles(), VULN-A~L todos SAFE, 38 testes / 101 asserções PASS.

Este guia mostra como construir um sistema de gerenciamento de grupos com controle de membros baseado em papéis — proprietários, administradores e membros com permissões graduadas.

## Schema

```sql
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

`UNIQUE(group_id, user_id)` previne memberships duplicadas. `CHECK(role IN ...)` bloqueia papéis inválidos no nível do BD.

## Endpoints

| Método | Caminho | Auth | Descrição |
|--------|---------|------|-----------|
| `POST` | `/groups` | `X-User-Id` | Criar grupo (ator torna-se owner) |
| `GET` | `/groups/{groupId}/members` | `X-User-Id` (membro) | Listar membros |
| `POST` | `/groups/{groupId}/members` | `X-User-Id` (owner/admin) | Adicionar membro |
| `DELETE` | `/groups/{groupId}/members/{userId}` | `X-User-Id` | Remover membro |
| `PUT` | `/groups/{groupId}/members/{userId}/role` | `X-User-Id` (owner) | Alterar papel |

## Enum MemberRole

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

Capacidades por papel:
- **Owner**: pode adicionar/remover membros, alterar papéis, não pode ser removido
- **Admin**: pode adicionar/remover membros, não pode alterar papéis
- **Member**: só pode sair (remover a si mesmo)

## Resolução do Ator

```php
private function resolveActorId(ServerRequestInterface $request): int
{
    $header = $request->getHeaderLine('X-User-Id');
    return is_numeric($header) ? (int) $header : 0;
}
```

Headers não numéricos retornam 0 (inválido). Toda operação privilegiada valida o ator contra o BD antes de prosseguir.

## Verificação de Membership Antes de Qualquer Operação

```php
$actorMembership = $actorId > 0 ? $this->repo->findMembership($groupId, $actorId) : null;

if ($actorMembership === null) {
    return $this->responseFactory->create(['error' => 'not a member'], 403);
}
```

Não-membros recebem 403 em todas as operações de grupo — incluindo listagem de membros (prevenção de IDOR).

## Adicionar Membros — Hierarquia de Papéis

```php
$actorRole = MemberRole::tryFrom($actorMembership['role']) ?? MemberRole::Member;

if (!$actorRole->canManageMembers()) {
    return $this->responseFactory->create(['error' => 'only owner or admin can add members'], 403);
}

// Não é possível atribuir papel 'owner' via endpoint de adicionar-membro
$role = MemberRole::tryFrom($roleValue);
if ($role === null || $role === MemberRole::Owner) {
    return $this->responseFactory->create(['error' => 'role must be member or admin'], 422);
}
```

O papel `owner` não pode ser atribuído via API — é definido apenas na criação do grupo.

## Owner Não Pode Ser Removido

```php
$targetRole = MemberRole::tryFrom($targetMembership['role']) ?? MemberRole::Member;

if ($targetRole === MemberRole::Owner) {
    return $this->responseFactory->create(['error' => 'cannot remove the group owner'], 422);
}
```

O owner está protegido de remoção. Transferência de propriedade exigiria um endpoint dedicado.

## Saída Voluntária vs. Remoção por Admin

```php
$isSelfLeave = $actorId === $userId;

if (!$isSelfLeave && !$actorRole->canManageMembers()) {
    return $this->responseFactory->create(['error' => 'only owner or admin can remove members'], 403);
}
```

Membros podem se remover (saída voluntária) sem direitos de admin. Remover outro usuário requer `canManageMembers()`.

## Alteração de Papel — Apenas Owner

```php
if (!$actorRole->canChangeRoles()) {
    return $this->responseFactory->create(['error' => 'only owner can change roles'], 403);
}

$role = MemberRole::tryFrom($roleValue);
if ($role === null || $role === MemberRole::Owner) {
    return $this->responseFactory->create(['error' => 'role must be member or admin'], 422);
}
```

Apenas o owner pode promover/rebaixar membros. O papel `owner` não pode ser atribuído (prevenindo roubo silencioso de propriedade).

---

## Avaliação de Vulnerabilidades

### V-01 — IDOR: não-membro lê lista de membros ✅ SAFE

**Risco**: Não-membro chama `GET /groups/{id}/members` para enumerar usuários.
**Resultado**: SAFE — `findMembership(groupId, actorId) === null` → 403 antes de retornar qualquer dado.

---

### V-02 — IDOR: não-membro adiciona alguém a um grupo ✅ SAFE

**Risco**: Não-membro chama `POST /groups/{id}/members` para injetar usuários.
**Resultado**: SAFE — mesma verificação de membership; não-membro → 403.

---

### V-03 — Escalação de privilégio: membro regular adiciona outro membro ✅ SAFE

**Risco**: Membro regular (`role = 'member'`) tenta adicionar um novo usuário.
**Resultado**: SAFE — `canManageMembers()` retorna false para `Member` → 403.

---

### V-04 — Escalação de privilégio: admin promove para owner ✅ SAFE

**Risco**: Admin tenta atribuir `role = 'owner'` via endpoints de adicionar-membro ou alterar-papel.
**Resultado**: SAFE — ambos os endpoints rejeitam `MemberRole::Owner` como papel atribuível válido → 422.

---

### V-05 — Escalação de privilégio: membro promove a si mesmo ✅ SAFE

**Risco**: Membro regular chama `PUT /groups/{id}/members/{self}/role`.
**Resultado**: SAFE — `canChangeRoles()` retorna false para `Member` e `Admin` → 403.

---

### V-06 — Remoção de Owner ✅ SAFE

**Risco**: Admin tenta remover o owner do grupo.
**Resultado**: SAFE — `if ($targetRole === MemberRole::Owner)` → 422.

---

### V-07 — X-User-Id faltando na criação de grupo ✅ SAFE

**Risco**: Requisição sem `X-User-Id` cria um grupo sem owner válido.
**Resultado**: SAFE — `resolveActorId()` retorna 0 para header ausente/inválido → `findUserById(0)` retorna null → 404.

---

### V-08 — X-User-Id não numérico ✅ SAFE

**Risco**: Header `X-User-Id: admin` bypassa validação numérica do ator.
**Resultado**: SAFE — `is_numeric($header)` retorna false para strings não numéricas → retorna 0 → rejeitado.

---

### V-09 — SQL injection no nome do grupo ✅ SAFE

**Risco**: Nome do grupo `'; DROP TABLE user_groups; --` deleta dados.
**Resultado**: SAFE — todas as queries usam statements parametrizados. A string de injeção é armazenada verbatim como nome do grupo sem execução.

---

### V-10 — Operação de membro entre grupos (IDOR) ✅ SAFE

**Risco**: Owner do grupo A tenta remover um membro do grupo B.
**Resultado**: SAFE — `findMembership(groupId, actorId)` verifica membership no grupo *alvo*. Owner do grupo A não tem membership no grupo B → 403.

---

### V-11 — ID de grupo negativo ✅ SAFE

**Risco**: `GET /groups/-1/members` causa erro no BD ou comportamento inesperado.
**Resultado**: SAFE — `is_numeric($params['groupId']) ? (int)$params['groupId'] : 0` aceita `-1` como numérico, mas `findGroupById(-1)` retorna null → 404.

---

### V-12 — Admin não pode alterar papéis ✅ SAFE

**Risco**: Admin chama `PUT /groups/{id}/members/{userId}/role` para promover usuários.
**Resultado**: SAFE — `canChangeRoles()` é apenas para owner → admin recebe 403.

---

### Resumo VULN

| ID | Vulnerabilidade | Resultado |
|----|----------------|-----------|
| V-01 | IDOR: não-membro lê lista de membros | ✅ SAFE |
| V-02 | IDOR: não-membro adiciona membro | ✅ SAFE |
| V-03 | Escalação de privilégio: membro adiciona membro | ✅ SAFE |
| V-04 | Escalação de privilégio: admin → owner | ✅ SAFE |
| V-05 | Escalação de privilégio: membro promove a si mesmo | ✅ SAFE |
| V-06 | Remoção de owner | ✅ SAFE |
| V-07 | X-User-Id faltando na criação | ✅ SAFE |
| V-08 | X-User-Id não numérico | ✅ SAFE |
| V-09 | SQL injection no nome do grupo | ✅ SAFE |
| V-10 | IDOR entre grupos (owner de outro grupo) | ✅ SAFE |
| V-11 | ID de grupo negativo | ✅ SAFE |
| V-12 | Admin não pode alterar papéis | ✅ SAFE |

**12 SAFE, 0 EXPOSED**
Verificação de membership antes de toda operação, hierarquia de papéis `canManageMembers()`/`canChangeRoles()` e guarda de remoção de owner previnem todos os vetores de escalação de privilégio e IDOR.

---

## O Que NÃO Fazer

| Anti-padrão | Risco |
|---|---|
| Sem verificação de membership antes de listar membros | Não-membros enumeram todos os usuários do grupo (IDOR) |
| Permitir atribuição do papel `owner` via adicionar-membro | Qualquer admin pode silenciosamente assumir a propriedade |
| Permitir atribuição do papel `owner` via alterar-papel | O mesmo — roubo de propriedade com uma requisição |
| Pular verificação `canManageMembers()` | Membros regulares adicionam/removem qualquer pessoa |
| Permitir remoção do owner | Grupo perde seu usuário governante |
| Sem `UNIQUE(group_id, user_id)` | Mesmo usuário adicionado duas vezes; registros de membership duplicados |
| Verificação `is_numeric()` apenas para X-User-Id | `"1.5"` passa `is_numeric`; use cast `(int)` + valide contra o BD |
| Verificar membership no próprio grupo do ator (não no grupo alvo) | IDOR entre grupos: owner do grupo A modifica o grupo B |
| Permitir que admin altere papéis | Admin promove a si mesmo para owner; bypass da hierarquia de papéis |
