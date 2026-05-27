# Como Fazer: Concessões de Acesso Delegado

> **Referência FT**: FT282 (`NENE2-FT/grantlog`) — Concessões de acesso delegado: acesso a recursos com escopo (read/write/admin) e tempo limitado, UNIQUE(grantor, grantee, resource) + CHECK(grantor != grantee), IDOR → 404, revogação com soft-delete, rastreamento de contagem de uso, hierarquia GrantScope.satisfies(), 23 testes / 71 asserções PASS.
>
> Também validado em FT176 — implementação original.

Delegação de acesso por usuário, com limite de tempo e revogável — um grantor concede acesso
com escopo ao grantee para um recurso nomeado por uma janela de tempo delimitada.

---

## Visão Geral

Concessões de Acesso Delegado permitem que um usuário (`grantor`) dê a outro usuário (`grantee`)
acesso com escopo e limite de tempo a um identificador de recurso. Pense em "compartilhar document:42
como somente leitura com o usuário 7, expira em 24 horas, revogável a qualquer momento."

Propriedades principais:

- **Multi-parte** — grantor e grantee são sempre usuários diferentes; auto-concessões são rejeitadas.
- **Máquina de estados** — active → revoked (unidirecional); o estado expirado é computado a partir de `expires_at`.
- **Recurso opaco** — `resource` é uma string de forma livre; o servidor a armazena verbatim.
- **Unicidade idempotente** — uma concessão única por `(grantor_id, grantee_id, resource)`.
- **Seguro contra IDOR** — todas as verificações de propriedade retornam 404, não 403, para prevenir enumeração de existência.

---

## Schema

```sql
CREATE TABLE grants (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    grantor_id  INTEGER NOT NULL,
    grantee_id  INTEGER NOT NULL,
    resource    TEXT    NOT NULL,
    scope       TEXT    NOT NULL DEFAULT 'read',
    expires_at  TEXT    NOT NULL,
    revoked_at  TEXT,
    used_count  INTEGER NOT NULL DEFAULT 0,
    created_at  TEXT    NOT NULL,
    UNIQUE (grantor_id, grantee_id, resource),
    CHECK (scope IN ('read', 'write', 'admin')),
    CHECK (grantor_id != grantee_id)
);
```

O `CHECK (grantor_id != grantee_id)` é uma medida de defesa em profundidade —
a auto-concessão também deve ser rejeitada na camada de aplicação para uma resposta de erro clara.

---

## Camada de domínio

### Enum GrantScope com hierarquia

```php
enum GrantScope: string
{
    case Read  = 'read';
    case Write = 'write';
    case Admin = 'admin';

    public function satisfies(self $required): bool
    {
        $rank = [self::Read->value => 0, self::Write->value => 1, self::Admin->value => 2];
        return $rank[$this->value] >= $rank[$required->value];
    }
}
```

### Entidade Grant — métodos de estado computado

```php
final readonly class Grant
{
    public function isExpired(string $now): bool  { return $this->expiresAt <= $now; }
    public function isRevoked(): bool             { return $this->revokedAt !== null; }
    public function isActive(string $now): bool   { return !$this->isExpired($now) && !$this->isRevoked(); }
}
```

Verifique **revogado primeiro**, depois expiração — ambos os caminhos retornam 403 mas com corpos de erro distintos
para que os grantees entendam por que o acesso falhou sem expor internos do sistema.

---

## Endpoints HTTP

| Método | Caminho | Auth | Propósito |
|--------|---------|------|-----------|
| `POST` | `/grants` | `X-User-Id` (grantor) | Criar uma concessão |
| `GET` | `/grants/issued` | `X-User-Id` | Listar concessões emitidas pelo chamador |
| `GET` | `/grants/received` | `X-User-Id` | Listar concessões recebidas pelo chamador |
| `DELETE` | `/grants/{id}` | `X-User-Id` (deve ser grantor) | Revogar uma concessão |
| `POST` | `/grants/{id}/use` | `X-User-Id` (deve ser grantee) | Usar uma concessão |

---

## Regras de validação

| Campo | Regra |
|-------|-------|
| `grantee_id` | Deve ser um **inteiro JSON** > 0; string `"2"`, null, boolean, float rejeitados |
| `resource` | String não vazia; ≤ 500 chars UTF-8; armazenada verbatim (opaca) |
| `scope` | Deve ser um de `read` / `write` / `admin` |
| `expires_at` | ISO 8601 válido; deve ser no futuro; ≤ 30 dias a partir de agora |
| Auto-concessão | `grantee_id == grantor X-User-Id` → 422 |

### Parsing estrito de campo inteiro

Uma vulnerabilidade comum é coerção de tipo implícita — aceitar `"2"` (string JSON)
como `2` (int). Use verificação de tipo explícita:

```php
private function intField(array $body, string $key): ?int
{
    if (!array_key_exists($key, $body)) {
        return null;
    }
    // is_int() retorna false para "2", null, true, 2.5 — apenas true para PHP int
    return is_int($body[$key]) ? $body[$key] : null;
}
```

Nota: `2.0` (PHP float) é indistinguível de `2` (int) após `json_encode` — use `2.5`
para testar rejeição de float em testes unitários.

---

## Máquina de estados

```
         revoke()
active ─────────────→ revoked   (409 no segundo revoke)
  │
  │ expires_at ≤ now
  ↓
expired

revoked + expired → revoked vence (verificar revogado primeiro)
```

A dupla revogação deve ser rejeitada com **409**, não aceita silenciosamente.
O timestamp `revoked_at` não deve mudar na segunda chamada.

---

## Padrão de proteção IDOR

```php
// DELETE /grants/{id}
$grant = $this->repository->find($id);

// Retornar 404 tanto para "não encontrado" quanto para "não é sua concessão"
// Nunca retornar 403 aqui — isso vazaria a existência
if ($grant === null || $grant->grantorId !== $callerId) {
    return $this->responseFactory->create(['error' => "Grant #{$id} not found."], 404);
}
```

O mesmo padrão se aplica a `POST /grants/{id}/use` — retornar 404 se o chamador não for o grantee.

---

## Prevenção de confusão multi-parte

| Cenário | Esperado |
|---------|---------|
| Grantor chama `POST /grants/{id}/use` (própria concessão) | 404 — grantor não é o grantee |
| Grantee chama `DELETE /grants/{id}` | 404 — grantee não é o grantor |
| Usuário 3 chama qualquer um numa concessão entre usuários 1 e 2 | 404 — IDOR |
| `X-User-Id: 0` ou `X-User-Id: -1` | 401 — IDs não positivos rejeitados |
| `X-User-Id` ausente | 401 |

---

## Checklist de segurança (ATK-01 a ATK-12)

| # | Vetor de ataque | Mitigação |
|---|---|---|
| ATK-01 | Concessão expirada (limite de clock) | Comparação `isExpired()`; `expires_at` retroativo no BD em teste |
| ATK-02 | Bypass de estado de concessão revogada | Verificação `isRevoked()` antes do uso |
| ATK-03 | Auto-concessão (grantor == grantee) | 422 na camada de app + `CHECK` do BD |
| ATK-04 | Grantee errado usa concessão (IDOR) | 404, não 403 |
| ATK-05 | Não-grantor revoga concessão (IDOR) | 404, não 403; concessão original permanece ativa |
| ATK-06 | `expires_at` passado na criação | `strtotime($expiresAt) <= strtotime($now)` → 422 |
| ATK-07 | Confusão de tipo em `grantee_id` | Verificação estrita `is_int()`; rejeita `"2"`, `null`, `true`, `2.5` |
| ATK-08 | Path traversal em `resource` | Armazenamento opaco; sem acesso ao sistema de arquivos |
| ATK-09 | SQL injection em `resource`/`scope` | Consultas parametrizadas; enum de escopo rejeita valor injetado |
| ATK-10 | Unicode/BIDI em `resource` | Armazenado verbatim; homoglifos e BIDI são recursos distintos |
| ATK-11 | Dupla revogação (máquina de estados) | 409 na segunda revogação; `revoked_at` imutável após o primeiro |
| ATK-12 | Grantor usa própria concessão como grantee | 404 — papéis de parte aplicados estritamente |

---

## Abordagem de teste

- **ATK-01, ATK-02**: Force o estado do BD diretamente (`UPDATE grants SET expires_at/revoked_at`)
  para simular viagem no tempo sem sleep.
- **ATK-07**: Teste `"2"` (string), `null`, `true`, `2.5` (float) — não `2.0` (indistinguível
  de int após PHP json_encode).
- **ATK-10**: Use `"\u{202E}"` (BIDI override) e homoglifos cirílicos para confirmar armazenamento verbatim.
- **ATK-11**: Afirme que o valor de `revoked_at` não mudou no BD após a segunda tentativa de revogação.

---

## O Que NÃO Fazer

| Anti-padrão | Risco |
|---|---|
| Sem `UNIQUE (grantor_id, grantee_id, resource)` | Mesmo par pode criar concessões duplicadas; grantee tem concessões antigas e ativas para o mesmo recurso |
| Hard delete na revogação | Perde histórico de auditoria; não é possível saber quando o acesso foi removido ou quantas vezes foi usado |
| Retornar 403 em vez de 404 para verificação de propriedade | Revela existência da concessão para chamadores não autorizados; superfície de enumeração IDOR |
| Sem `CHECK (grantor_id != grantee_id)` | Falta defesa em profundidade; auto-concessões podem passar se a verificação da camada de app for contornada |
| Aceitar string de escopo livre | Erros de digitação silenciosamente padrão para `read`; use `GrantScope::tryFrom()` para rejeitar valores desconhecidos |
| Verificação de escopo sem hierarquia `satisfies()` | Usuário `write` deve passar verificações `read` separadamente; use hierarquia para verificar todos os níveis menores |
| Sem TTL máximo em `expires_at` | Grantor cria concessões de 100 anos; acesso efetivamente permanente sem revisão |
| Sem limite de comprimento de recurso | String de recurso de 10 MB causa lookups lentos no índice e alocação de memória |
| Verificar expiração antes da revogação | Concessão revogada + expirada deve mostrar "revogada" — revogação vence na máquina de estados |
| Rastrear `used_count` no cliente | Cliente reporta contagem de uso; servidor deve ser dono do contador |
