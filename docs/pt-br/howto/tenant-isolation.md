# Como Fazer: Isolamento de Tenant e Prevenção de IDOR Entre Tenants

**FT179 — isolationlog**

Prevenção de vazamento de dados entre tenants em APIs multi-tenant — queries SQL com escopo, identidade baseada em header e prevenção de injeção no corpo.

---

## A Ameaça: IDOR Entre Tenants

Em um sistema multi-tenant, cada recurso pertence a um tenant. Um atacante que controla uma conta de tenant sonda IDs de outros tenants:

```
GET /notes/42          X-Tenant-Id: 2   ← atacante é tenant 2
                                         nota 42 pertence ao tenant 1
```

Se o servidor retornar a nota, o atacante leu os dados de outro tenant — uma **Referência Direta Insegura a Objetos (IDOR)** no limite do tenant.

---

## O Padrão de Isolamento

### 1. Escopar todas as leituras no nível SQL

Nunca consultar apenas pelo ID. Sempre adicionar `AND tenant_id = ?`:

```php
// ❌ ERRADO — ID sozinho, legível entre tenants
'SELECT * FROM notes WHERE id = ?'

// ✅ CORRETO — ID + tenant aplicados no SQL
'SELECT * FROM notes WHERE id = ? AND tenant_id = ?'
```

Isso retorna `null` para acesso entre tenants, que se torna um 404. O atacante não aprende nada sobre a nota 42 — nem mesmo se ela existe.

### 2. Queries de listagem são sempre escopadas

```php
// ❌ ERRADO — poderia ser aumentado com injeção ?tenant_id=...
'SELECT * FROM notes ORDER BY id DESC LIMIT ?'

// ✅ CORRETO — WHERE tenant_id = ? nunca é opcional
'SELECT * FROM notes WHERE tenant_id = ? ORDER BY id DESC LIMIT ?'
```

### 3. Delete usa o mesmo padrão

```sql
DELETE FROM notes WHERE id = ? AND tenant_id = ?
```

`rowCount()` retorna 0 se a nota não pertence ao tenant → 404.

---

## Identidade de Tenant Baseada em Header

Use headers `X-Tenant-Id` + `X-User-Id` para endpoints com escopo de tenant. Valide ambos com `V::userId()` (ctype_digit + guarda de overflow + > 0):

```php
private function resolveTenantUser(ServerRequestInterface $request): array
{
    $tenantId = V::userId($request->getHeaderLine('X-Tenant-Id'));
    $userId   = V::userId($request->getHeaderLine('X-User-Id'));

    return [$tenantId, $userId];
}
```

`V::userId()` rejeita:
- String vazia (`ctype_digit('') === false`)
- Zero (`id <= 0`)
- Negativo (`'-'` falha em `ctype_digit`)
- String float (`'1.5'` falha em `ctype_digit`)
- Overflow de 20+ dígitos (guarda strlen > 18)
- Tentativas de injeção SQL (`'1 OR 1=1'` falha em `ctype_digit`)

---

## Prevenção de Injeção no Corpo

Atacantes podem incluir `tenant_id` no corpo POST para tentar atribuir um recurso a um tenant diferente:

```json
POST /notes
X-Tenant-Id: 1
{ "content": "Injeção", "tenant_id": 99 }
```

**Nunca leia `tenant_id` do corpo.** Sempre use o header validado pelo servidor:

```php
// ATK-04: body['tenant_id'] nunca é lido — sempre usa $tenantId do header
$note = $this->notes->create($tenantId, $userId, $content, date('c'));
//                            ^^^^^^^^^
//                            de V::userId(X-Tenant-Id), não de $body
```

---

## Verificação de Existência de Tenant na Escrita

Antes de criar um recurso, verifique se o tenant existe:

```php
if (!$this->tenants->exists($tenantId)) {
    return $this->responseFactory->create(['error' => 'Tenant não encontrado.'], 422);
}
```

Sem esta verificação, notas seriam criadas para IDs de tenant fantasma que não existem na tabela de tenants, quebrando a integridade referencial.

---

## Checklist de Ataques (ATK-01 a ATK-12)

| # | Teste | Expectativa |
|---|-------|-------------|
| ATK-01 | Sem headers de auth | 401 |
| ATK-02 | GET entre tenants (IDOR) | 404 — nota existe mas não para este tenant |
| ATK-03 | X-Tenant-Id: `"1"`, `1.5`, `+1`, `1 OR 1=1` | 401 — V::userId rejeita |
| ATK-04 | Corpo POST contém `tenant_id: 99` | 201 — tenant_id do corpo ignorado |
| ATK-05 | DELETE entre tenants | 404 — nota não excluída |
| ATK-06 | X-Tenant-Id: `0`, `-1` | 401 |
| ATK-07 | X-Tenant-Id: overflow de 20 dígitos | 401 |
| ATK-08 | Criação de tenant sem X-Admin-Key | 401 |
| ATK-09 | X-Admin-Key errada | 401 |
| ATK-10 | Nota para ID de tenant inexistente | 422 |
| ATK-11 | Lista: T1 vê apenas notas de T1, não de T2 | Aplicado por SQL WHERE tenant_id |
| ATK-12 | `?limit=-1`, `?limit=10.5`, limit de 20 dígitos | 422 — guardas V::queryInt |

---

## Estratégia de Resposta: 404 não 403

Quando um IDOR entre tenants é detectado, retorne **404** — não 403 Forbidden.

- `403` vaza existência: "o recurso existe mas você não pode acessá-lo"
- `404` não revela nada: "nenhum recurso desse tipo para este tenant"

Isso previne ataques de enumeração de tenant.
