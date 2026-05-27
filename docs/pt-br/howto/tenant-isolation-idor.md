# Como Fazer: Isolamento de Tenant e Prevenção de IDOR

> **Referência FT**: FT318 (`NENE2-FT/isolationlog`) — Isolamento de dados multi-tenant, prevenção de IDOR entre tenants, hardening de confusão de tipo em headers, prevenção de injeção de tenant_id no corpo, 34 testes / 133 assertivas PASS.

Este guia mostra como aplicar isolamento estrito de dados no nível de tenant para que nenhum tenant possa ler, modificar ou enumerar os dados de outro tenant — mesmo que manipulem headers ou corpos de requisição.

## Schema

```sql
CREATE TABLE tenants (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT    NOT NULL,
    created_at TEXT    NOT NULL
);

CREATE TABLE notes (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id  INTEGER NOT NULL REFERENCES tenants(id),
    user_id    INTEGER NOT NULL,
    content    TEXT    NOT NULL,
    created_at TEXT    NOT NULL
);
```

## Modelo de Autenticação

```
Endpoints admin   → X-Admin-Key: <server_secret>       (ex.: env ADMIN_KEY)
Endpoints tenant  → X-Tenant-Id: <int>  X-User-Id: <int>
```

### Regras de Validação de Header

`X-Tenant-Id` e `X-User-Id` devem passar pela validação de **inteiro positivo estrito**:

| Entrada | Resultado |
|---------|-----------|
| `"1"` (válido) | ✅ Aceito |
| `"0"` | ❌ 401 — deve ser > 0 |
| `"-1"` | ❌ 401 — negativo rejeitado |
| `"1.5"` | ❌ 401 — float rejeitado |
| `"+1"` | ❌ 401 — prefixo de sinal rejeitado |
| `"1 OR 1=1"` | ❌ 401 — tentativa de injeção SQL rejeitada |
| `""` (ausente) | ❌ 401 — header ausente |
| `"99999999999999999999"` (20 dígitos) | ❌ 401 — overflow rejeitado |

```php
// Padrão de validação usando ctype_digit + verificação de intervalo
$raw = $request->getHeaderLine('X-Tenant-Id');
if (!ctype_digit($raw) || ($id = (int) $raw) <= 0 || strlen($raw) > 10) {
    return $this->json->create(['error' => 'Não autorizado'], 401);
}
```

## Endpoints Admin

```php
POST /tenants   X-Admin-Key: admin-secret
{"name": "Acme Corp"}
→ 201  {"id": 1, "name": "Acme Corp", "created_at": "..."}

GET  /tenants   X-Admin-Key: admin-secret
→ 200  {"total": 2, "tenants": [...]}

GET  /tenants/1  X-Admin-Key: admin-secret
→ 200  {"id": 1, "name": "Acme Corp", ...}

// Sem chave admin
POST /tenants  (sem X-Admin-Key)   → 401
POST /tenants  X-Admin-Key: errada → 401
```

## Endpoints de Tenant — Prevenção de IDOR

### Criar Nota (tenant atribuído pelo servidor)

```php
POST /notes  X-Tenant-Id: 1  X-User-Id: 42
{"content": "Olá"}
→ 201  {"id": 1, "tenant_id": 1, "content": "Olá", ...}
```

**O `tenant_id` no corpo da requisição é SEMPRE ignorado.** O servidor usa apenas o valor do header:

```php
// Atacante envia X-Tenant-Id: 1 mas o corpo tenta injetar tenant 2
POST /notes  X-Tenant-Id: 1
{"content": "Injeção", "tenant_id": 2}  // ← ignorado

→ 201  {"tenant_id": 1, ...}   // atribuído do header, não do corpo
```

### IDOR Entre Tenants — Retorna 404

```php
// Nota 5 pertence ao Tenant 1
GET  /notes/5  X-Tenant-Id: 2  → 404   // IDOR bloqueado
DELETE /notes/5  X-Tenant-Id: 2 → 404  // IDOR bloqueado

// Proprietário ainda pode acessar
GET  /notes/5  X-Tenant-Id: 1  → 200   ✅
```

Todas as queries incluem `WHERE tenant_id = $tenantId`. Uma linha ausente retorna 404 — **não 403** — para prevenir enumeração de existência.

### Isolamento de Listagem

```php
// T1 tem 2 notas, T2 tem 1 nota
GET /notes  X-Tenant-Id: 1  → {"data": [nota_A, nota_B], "tenant_id": 1}
GET /notes  X-Tenant-Id: 2  → {"data": [nota_X],         "tenant_id": 2}
// T2 nunca vê as notas de T1
```

```sql
SELECT * FROM notes WHERE tenant_id = ? ORDER BY id DESC LIMIT ?
-- Sempre filtrar por tenant_id do header validado
```

### Validação de Parâmetro de Query

```php
GET /notes?limit=-1       → 422  // negativo
GET /notes?limit=10.5     → 422  // float
GET /notes?limit=999999   → 422  // excede o máximo (ex.: 100)
GET /notes?limit=99999999999999999999  → 422  // overflow
GET /notes                → 200  // limit padrão aplicado
```

## Criação de Nota para Tenant Inexistente

```php
POST /notes  X-Tenant-Id: 9999  X-User-Id: 1
{"content": "test"}
→ 422  // tenant 9999 não existe
```

---

## O Que NÃO Fazer

| Antipadrão | Risco |
|---|---|
| Confiar em `tenant_id` do corpo da requisição | Atacante atribui notas a qualquer tenant |
| Retornar 403 em vez de 404 no IDOR | 403 revela que o recurso existe; 404 previne enumeração |
| Converter header diretamente: `(int) $header` sem ctype_digit | `-1`, `+1`, `1.5`, overflow todos produzem inteiros inesperados |
| Sem `WHERE tenant_id = ?` nas queries de lista | Vazamento completo de dados entre tenants |
| Compartilhar chave admin nas respostas do cliente | Chave admin deve permanecer apenas no servidor |
| Permitir `X-Tenant-Id: 0` | Zero é frequentemente um estado padrão/não definido; aceite apenas inteiros positivos |
