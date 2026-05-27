# Como Fazer: Isolamento Multi-Tenant com JWT

> **Referência FT**: FT342 (`NENE2-FT/tenantlog`) — API de notas multi-tenant com autenticação JWT Bearer, tenant_id embutido nos claims do token, escopo de query estrito por tenant, IDOR entre tenants bloqueado com 404, tenant_id nunca exposto em respostas, 13 testes / 30+ asserções PASS.

Este guia mostra como usar tokens JWT para carregar `tenant_id` como um claim, escopo de todas as queries para o tenant autenticado e prevenir acesso a dados entre tenants.

## Schema

```sql
CREATE TABLE tenants (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT    NOT NULL,
    created_at TEXT    NOT NULL
);

CREATE TABLE users (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id     INTEGER NOT NULL REFERENCES tenants(id),
    email         TEXT    NOT NULL UNIQUE,
    password_hash TEXT    NOT NULL,
    created_at    TEXT    NOT NULL
);

CREATE TABLE notes (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id  INTEGER NOT NULL REFERENCES tenants(id),
    user_id    INTEGER NOT NULL REFERENCES users(id),
    title      TEXT    NOT NULL,
    body       TEXT    NOT NULL DEFAULT '',
    created_at TEXT    NOT NULL
);
```

## Autenticação

```
POST /auth/login  →  Bearer token (JWT)
Todos os outros endpoints → Authorization: Bearer <token>
```

### Login

```php
POST /auth/login
{"email": "alice@acme.com", "password": "password"}
→ 200  {"token": "eyJhbGci..."}

// Credenciais erradas ou email desconhecido
→ 401  {"type": ".../invalid-credentials", "detail": "Invalid email or password"}
// Ambas as falhas retornam a mesma mensagem (prevenção de enumeração de usuário)
```

### Claims JWT

```php
// Payload do token (decodificado)
{
  "sub": 1,           // user_id
  "tenant_id": 1,     // tenant ao qual o usuário pertence
  "exp": 1748427600
}
```

O claim `tenant_id` é a fonte autoritativa de identidade do tenant — nunca confie em `tenant_id` do corpo da requisição ou headers.

### Verificação

```php
$verifier = new LocalBearerTokenVerifier($secret);
$claims   = $verifier->verify($token);
// $claims['tenant_id'] é o escopo de tenant confiável
```

Um token adulterado (assinatura inválida) → 401.

## Endpoints com Escopo de Tenant

Todas as operações de nota requerem um token Bearer válido. O `tenant_id` é extraído dos claims JWT verificados.

### Criar Nota

```php
POST /notes
Authorization: Bearer <alice_token>
{"title": "Alice Note", "body": "Acme content"}
→ 201
{
  "id": 1,
  "title": "Alice Note",
  "body": "Acme content",
  "created_at": "..."
  // tenant_id NÃO é retornado — nunca vazado para o cliente
}

// Sem token → 401
// Token inválido → 401
```

**`tenant_id` é sempre obtido do claim JWT, não do corpo da requisição.**

### Listar Notas

```php
GET /notes
Authorization: Bearer <alice_token>
→ 200  [{"id": 1, "title": "Alice Note", ...}]

// Token do Bob só vê notas do Bob — notas da Alice nunca aparecem
GET /notes
Authorization: Bearer <bob_token>
→ 200  [{"id": 2, "title": "Bob Note", ...}]
```

```sql
SELECT * FROM notes WHERE tenant_id = ? ORDER BY created_at DESC
-- tenant_id vinculado dos claims JWT, nunca da requisição
```

### Obter Nota (Prevenção de IDOR)

```php
// Nota da Alice
GET /notes/1
Authorization: Bearer <alice_token>
→ 200  {"id": 1, "title": "Alice Note", ...}

// Bob tenta acessar a nota da Alice (nota id 1 pertence ao tenant 1)
GET /notes/1
Authorization: Bearer <bob_token>
→ 404  // NÃO 403 — previne enumeração de existência entre tenants
```

**Retorne 404, não 403, para acesso entre tenants.** Um 403 revela que o recurso existe em outro tenant.

### Deletar Nota

```php
DELETE /notes/1
Authorization: Bearer <alice_token>
→ 204

// Delete entre tenants
DELETE /notes/1
Authorization: Bearer <bob_token>
→ 404  // nota intacta; token do Bob não pode alcançá-la
```

## Padrão de Implementação

```php
// Middleware extrai e verifica JWT
$claims = $verifier->verify($bearerToken);
$request = $request->withAttribute('tenant_id', $claims['tenant_id']);
$request = $request->withAttribute('user_id', $claims['sub']);

// Controller lê de atributos da requisição (nunca do corpo)
$tenantId = (int) $request->getAttribute('tenant_id');

// Repositório sempre aplica escopo de tenant
public function findById(int $id, int $tenantId): ?array
{
    $stmt = $this->db->prepare(
        'SELECT id, title, body, created_at FROM notes WHERE id = ? AND tenant_id = ?'
    );
    $stmt->execute([$id, $tenantId]);
    return $stmt->fetch() ?: null;
}

// Retorno null → resposta 404 (nunca 403)
if ($note === null) {
    return $this->json->create(['error' => 'Not found'], 404);
}
```

## Rejeição de Adulteração de Token

```php
// Atacante cria manualmente um token com tenant_id diferente
$fakeToken = 'eyJhbGciOiJIUzI1NiJ9.tampered.invalidsignature';

GET /notes/1
Authorization: Bearer $fakeToken
→ 401  // verificação de assinatura falha
```

O servidor rejeita qualquer token cuja assinatura HMAC-SHA256 não corresponda ao segredo do servidor.

---

## O Que NÃO Fazer

| Anti-padrão | Risco |
|---|---|
| Ler `tenant_id` do corpo ou query params da requisição | Atacante define `tenant_id=2` para acessar dados de outro tenant |
| Retornar 403 para acesso entre tenants | Confirma que o recurso existe em outro tenant — vazamento de informação |
| Incluir `tenant_id` em respostas de nota | Expõe topologia interna de tenant; desnecessário para o cliente |
| Pular `AND tenant_id = ?` nas queries | Vazamento entre tenants — atacante com token válido vê dados de todos os tenants |
| Armazenar segredo JWT em config junto com dados | Comprometimento do segredo permite falsificar tokens para qualquer tenant |
| Confiar em `tenant_id` do header `X-Tenant-Id` | Header pode ser definido por qualquer cliente; confie apenas nos claims JWT verificados |
