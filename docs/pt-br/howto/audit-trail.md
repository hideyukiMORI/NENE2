# HOWTO: Trilha de Auditoria — Registrando Quem Alterou o Quê

> **Referência FT**: FT268 (`NENE2-FT/auditlog`) — trilha de auditoria somente de acréscimo: extração de ator via JWT, snapshots de payload antes/depois, tabela de auditoria imutável, lacuna de leitura de auditoria não autenticada
>
> **Avaliação ATK**: ATK-01 a ATK-12 incluídos no final deste documento.

Este guia mostra como implementar uma trilha de auditoria somente de acréscimo em uma aplicação NENE2.
Uma trilha de auditoria registra cada operação de criação, atualização e exclusão com o ator (a partir de claims JWT),
o recurso e um snapshot de payload. Esses registros são imutáveis: a API nunca expõe endpoints UPDATE ou DELETE
para a tabela de auditoria.

---

## Schema do banco de dados

```sql
-- Sem FK em actor_id ou resource_id:
-- registros de auditoria devem sobreviver à exclusão dos sujeitos que descrevem.
CREATE TABLE IF NOT EXISTS audit_log (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    actor_id      INTEGER NOT NULL,
    action        TEXT    NOT NULL,   -- 'created' | 'updated' | 'deleted'
    resource_type TEXT    NOT NULL,   -- ex. 'task', 'order', 'user'
    resource_id   INTEGER NOT NULL,
    occurred_at   TEXT    NOT NULL,
    payload       TEXT    NOT NULL DEFAULT '{}'
);

-- Adicionar índices para os padrões de consulta mais comuns
CREATE INDEX idx_audit_log_actor_id ON audit_log(actor_id);
CREATE INDEX idx_audit_log_resource ON audit_log(resource_type, resource_id);
```

Principais decisões de design:
- **Sem restrições FK** — registros de auditoria sobrevivem aos seus sujeitos. Se uma tarefa for excluída, seu histórico de auditoria deve permanecer.
- **Imutável por design** — nunca adicione caminhos SQL UPDATE ou DELETE para esta tabela.
- **`action` como verbo tipado** — use verbos no passado (`created`, `updated`, `deleted`) para tornar as entradas de log autodescritivas.

---

## DTO AuditEntry e AuditRepository

```php
final readonly class AuditEntry
{
    public function __construct(
        public int    $id,
        public int    $actorId,
        public string $action,
        public string $resourceType,
        public int    $resourceId,
        public string $occurredAt,
        public string $payload,
    ) {}
}
```

```php
final readonly class AuditRepository
{
    public function __construct(
        private DatabaseQueryExecutorInterface $executor,
    ) {}

    /** @param array<string, mixed> $payload */
    public function record(
        int    $actorId,
        string $action,
        string $resourceType,
        int    $resourceId,
        array  $payload,
    ): AuditEntry {
        $now         = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $payloadJson = json_encode($payload, JSON_THROW_ON_ERROR);

        $this->executor->execute(
            'INSERT INTO audit_log (actor_id, action, resource_type, resource_id, occurred_at, payload)
             VALUES (?, ?, ?, ?, ?, ?)',
            [$actorId, $action, $resourceType, $resourceId, $now, $payloadJson],
        );

        return $this->findById((int) $this->executor->lastInsertId())
            ?? throw new \RuntimeException('Failed to record audit entry.');
    }

    /** @return list<AuditEntry> */
    public function findByResource(string $resourceType, int $resourceId, int $limit = 50): array
    {
        $rows = $this->executor->fetchAll(
            // ORDER BY id DESC, não occurred_at DESC: timestamps com precisão de segundo colidem
            // quando duas operações acontecem no mesmo segundo.
            'SELECT * FROM audit_log
             WHERE resource_type = ? AND resource_id = ?
             ORDER BY id DESC LIMIT ?',
            [$resourceType, $resourceId, $limit],
        );
        return array_map(fn (array $row) => $this->hydrate($row), $rows);
    }
}
```

> **`ORDER BY id DESC` e não `occurred_at DESC`:** `occurred_at` tem precisão de segundos.
> Duas operações no mesmo segundo recebem timestamps idênticos, tornando a ordem de classificação imprevisível.
> O `id` auto-incremento preserva a ordem de inserção de forma confiável.

---

## Registrando auditorias no handler

Registre eventos de auditoria no handler (equivalente ao UseCase), não no Repository.
Registrar no Repository perde o contexto de negócio ("que operação disparou isso?").

### Criar — registrar o snapshot inicial

```php
$task = $this->tasks->create($title, $body, $actorId);

// Auditoria: NÃO incluir actor_id no payload — já está no próprio registro de auditoria.
$this->audit->record($actorId, 'created', 'task', $task->id, [
    'title'  => $task->title,
    'body'   => $task->body,
    'status' => $task->status,
]);
```

### Atualizar — registrar antes/depois para visibilidade do diff

```php
$before = $this->tasks->findById($id);
// ... verificação de propriedade, validação ...
$after  = $this->tasks->update($id, $title, $body, $status);

$this->audit->record($actorId, 'updated', 'task', $id, [
    'before' => ['title' => $before->title, 'body' => $before->body, 'status' => $before->status],
    'after'  => ['title' => $after->title,  'body' => $after->body,  'status' => $after->status],
]);
```

### Deletar — snapshot antes da exclusão

```php
$task = $this->tasks->findById($id);
// ... verificação de propriedade ...
$this->tasks->delete($id);

// Registrar APÓS a exclusão — a linha da tarefa foi removida, mas a auditoria persiste.
$this->audit->record($actorId, 'deleted', 'task', $id, [
    'title'  => $task->title,
    'status' => $task->status,
]);
```

---

## Ator a partir de claims JWT

Sempre derive o ator do JWT verificado, nunca do corpo da requisição.

```php
private function actorId(ServerRequestInterface $request): ?int
{
    /** @var array<string, mixed>|null $claims */
    $claims = $request->getAttribute('nene2.auth.claims');

    if (!is_array($claims) || !isset($claims['sub']) || !is_int($claims['sub'])) {
        return null;
    }

    return $claims['sub'];
}
```

`nene2.auth.claims` é definido por `BearerTokenMiddleware` após validar o token.
Um cliente não pode fornecer um `actor_id` falso no corpo da requisição e tê-lo registrado.

---

## Exclusão de campos sensíveis

**Nunca coloque senhas, tokens ou IDs internos no payload.**

```php
// ❌ Vaza dados sensíveis e é redundante
$this->audit->record($actorId, 'created', 'user', $user->id, [
    'email'         => $user->email,
    'password_hash' => $user->passwordHash,  // NUNCA incluir
    'actor_id'      => $actorId,              // redundante
]);

// ✅ Apenas atributos visíveis pelo negócio
$this->audit->record($actorId, 'created', 'user', $user->id, [
    'email' => $user->email,
    'role'  => $user->role,
]);
```

---

## API de auditoria imutável — sem endpoints de escrita

```php
public function register(Router $router): void
{
    $router->get('/audit', $this->list(...));
    $router->get('/audit/{resource_type}/{resource_id}', $this->byResource(...));
    // POST, PUT, DELETE estão intencionalmente ausentes
}
```

---

## Verificação de propriedade antes de cada escrita (e antes da auditoria)

```php
$task = $this->tasks->findById($id);
if ($task === null) {
    return $this->problems->create($request, 'not-found', 'Task not found.', 404);
}

// Retornar 404 em vez de 403 para evitar confirmar a existência do recurso para atores não autorizados.
if ($task->actorId !== $actorId) {
    return $this->problems->create($request, 'not-found', 'Task not found.', 404);
}

// Somente agora: modificar + auditar
```

---

## Consultar o log de auditoria

```php
// Histórico de um recurso específico
GET /audit/task/42

// Todos os eventos por ator
GET /audit?actor_id=7

// Todas as exclusões em tipos de recurso
GET /audit?action=deleted

// Pagina com segurança
GET /audit?limit=20&offset=40
```

---

## Considerações de segurança

| Risco | Mitigação |
|---|---|
| Exclusão do log de auditoria | Sem endpoint DELETE. No nível da tabela: negar permissão DELETE ao usuário do banco de dados do app, se possível |
| Spoofing de ator | O ator sempre vem de `nene2.auth.claims`, nunca do corpo da requisição |
| Payload sensível | Exclua senhas, tokens e chaves internas do payload explicitamente |
| IDOR (leituras de auditoria entre usuários) | Restrinja `GET /audit` a funções admin (combine com RBAC); ou no mínimo exija qualquer JWT válido |
| Ataque de temporização / enumeração de usuários | Use um hash Argon2id pré-computado real como dummy, não uma string malformada |
| DoS com `LIMIT -1` | Limite: `max(1, min((int) $limit, 100))` |

---

## Hash dummy deve ser um hash Argon2id real

Um hash dummy malformado faz com que `password_verify()` retorne `false` imediatamente (sem executar o KDF),
criando uma diferença de tempo de ~20.000× que permite a um atacante enumerar endereços de email válidos.

```php
// ❌ Malformado — KDF é ignorado, retorna false em ~0.001ms
$dummyHash = '$argon2id$v=19$m=65536,t=4,p=1$dummysaltdummysaltdummysalt$dummyhashvaluedummyhashvaluedummyh';

// ✅ Hash pré-computado real — KDF executa com custo completo (~180ms)
// Gere uma vez: password_hash('dummy-constant-value', PASSWORD_ARGON2ID)
$dummyHash = '$argon2id$v=19$m=65536,t=4,p=1$VkZVLkx3L3FPaVA5NndVSA$vwBHHeAqq1DpGTf7G55ZPAUad+CGLvEJle2m5NA8ulA';
```

> Este padrão de hash dummy foi documentado pela primeira vez em [password-hashing.md](password-hashing.md).
> **O mesmo princípio se aplica em qualquer lugar que `password_verify()` é chamado em um usuário potencialmente ausente.**

---

## Avaliação ATK (FT268)

Teste de ataque com mentalidade de cracker contra `NENE2-FT/auditlog`. A superfície: CRUD de tarefas autenticado por JWT + leitura de log de auditoria não autenticada.

### ATK-01 — Ataque de Algoritmo JWT None 🚫 BLOCKED

**Ataque**: Forge um JWT com `"alg":"none"` e sem assinatura, claim `sub` arbitrário.
```
Header: {"alg":"none","typ":"JWT"}
Payload: {"sub":1,"email":"admin@x.com","iat":9999999999,"exp":9999999999}
Signature: (vazia)
```
**Resultado**: `LocalBearerTokenVerifier` valida usando HMAC-HS256 contra o segredo configurado. Tokens sem assinatura válida são rejeitados — `alg:none` não é aceito. → **401 Unauthorized**

---

### ATK-02 — Adulteração de Assinatura JWT 🚫 BLOCKED

**Ataque**: Pegue um JWT válido, modifique o campo `sub` para o ID de outro usuário (ex.: `1` → `2`), re-codifique sem re-assinar.
**Resultado**: A assinatura HMAC-HS256 não corresponde mais ao payload modificado. `LocalBearerTokenVerifier` rejeita o token. → **401 Unauthorized**

---

### ATK-03 — Replay de Token JWT Expirado 🚫 BLOCKED

**Ataque**: Replaye um JWT capturado após seu timestamp `exp` ter passado.
**Resultado**: `BearerTokenMiddleware` / `LocalBearerTokenVerifier` verifica `exp`. Tokens com expiração passada são rejeitados. → **401 Unauthorized**

---

### ATK-04 — IDOR: Acessar Tarefa de Outro Usuário por ID ✅ BLOCKED

**Ataque**: Autentique como Usuário A (sub=1), então chame `PUT /tasks/3` onde a tarefa 3 pertence ao Usuário B (sub=2).
**Resultado**: O handler de rota de tarefas lê `task->actorId` e compara com `actorId` dos claims JWT. Incompatibilidade retorna → **404 Not Found** (existência do recurso não confirmada ao atacante).

---

### ATK-05 — IDOR: Deletar Tarefa de Outro Usuário ✅ BLOCKED

**Ataque**: Autentique como Usuário A, chame `DELETE /tasks/7` onde a tarefa 7 pertence ao Usuário B.
**Resultado**: Mesma guarda de propriedade do ATK-04. `task->actorId !== $actorId` → **404 Not Found**.

---

### ATK-06 — Injeção de ID de Ator via Corpo da Requisição ✅ BLOCKED

**Ataque**: `POST /tasks` com corpo `{"title":"Injected","actor_id":999}`.
**Resultado**: O controller ignora `body['actor_id']` completamente. O registro de auditoria usa `actorId` de `nene2.auth.claims['sub']` (JWT). A tarefa é criada sob o ator autenticado — `actor_id:999` não tem efeito.

---

### ATK-07 — Leitura Não Autenticada do Log de Auditoria ⚠️ EXPOSED

**Ataque**: `GET /audit` sem cabeçalho Authorization.
**Resultado**: Os endpoints de leitura do log de auditoria (`GET /audit`, `GET /audit/{type}/{id}`) **não são protegidos pelo `BearerTokenMiddleware`**. O middleware exclui apenas `/auth/login`; no entanto, o registrar de rotas de auditoria associa rotas sem exigir auth. Qualquer chamador não autenticado pode ler o histórico completo de auditoria de todos os atores e todos os recursos.

**Impacto**: Divulgação completa de: quem fez o quê, quando, em qual recurso, incluindo snapshots de payload antes/depois. Para um app multi-tenant, isso é uma divulgação crítica de informações.

**Recomendação**: Restrinja os endpoints de auditoria a JWT com escopo admin (ex.: `claims['role'] === 'admin'`), ou no mínimo exija qualquer JWT válido. Adicione o prefixo de auditoria às rotas protegidas por `BearerTokenMiddleware`.

---

### ATK-08 — Enumeração Entre Atores no Log de Auditoria via ?actor_id ⚠️ EXPOSED

**Ataque**: `GET /audit?actor_id=2` (ou enumerar 1..N) — lê todas as entradas de auditoria para qualquer actor_id.
**Resultado**: Sem verificação de autorização no filtro `actor_id`. O atacante enumera todos os IDs de usuário e recupera seu histórico completo de auditoria. Encadeado com ATK-07 (acesso não autenticado).
**Recomendação**: Se a auditoria for restrita apenas a usuários autenticados (não admin), filtre pelo `sub` do usuário autenticado — chamadores não podem consultar logs de outros atores. Admins veem tudo.

---

### ATK-09 — SQL Injection nos Parâmetros de Busca de Auditoria 🚫 BLOCKED

**Ataque**: `GET /audit?action=deleted';DROP TABLE audit_log;--&resource_type=task`
**Resultado**: `$action` e `$resourceType` são vinculados como parâmetros `?` na consulta SQL. Sem interpolação de string. O SQLite recebe `WHERE action = ?` com a string injetada literal como valor — o que simplesmente retorna 0 linhas. A tabela está segura. → **200 OK (vazio)**

---

### ATK-10 — DoS com Limit -1 / Limit Grande ✅ BLOCKED

**Ataque**: `GET /audit?limit=-1` ou `GET /audit?limit=99999`.
**Resultado**: `max(1, min((int) ($q['limit'] ?? 50), 100))` limita para `[1, 100]`. Limites negativos e excessivamente grandes são silenciosamente limitados. → **200 OK (máximo 100 entradas)**

---

### ATK-11 — Força Bruta no Login (Sem Rate Limiting) ⚠️ EXPOSED

**Ataque**: Tentativas sequenciais rápidas de `POST /auth/login` com o mesmo email e senhas diferentes.
**Resultado**: Sem rate limiting, sem bloqueio, sem CAPTCHA. Um atacante pode iterar senhas indefinidamente. O KDF Argon2id retarda cada tentativa para ~180ms, tornando a força bruta impraticável para senhas fortes, mas ainda viável para senhas fracas.
**Recomendação**: Adicione `ThrottleMiddleware` em `/auth/login` (ex.: 5 tentativas / 15 min por IP). Registre tentativas falhas com request_id para monitoramento.

---

### ATK-12 — Injeção de Valor de Status Arbitrário ⚠️ EXPOSED

**Ataque**: `PUT /tasks/1` com corpo `{"status":"<script>alert(1)</script>"}` ou `{"status":"admin_override"}`.
**Resultado**: O handler aceita qualquer string não vazia como `status`. O repositório escreve literalmente. A tarefa é atualizada com `status="<script>alert(1)</script>"`. Sem validação de enum, sem allowlist.
**Impacto**: XSS armazenado se o status for renderizado em um navegador sem escape. Modelo de domínio corrompido se a lógica de negócio assume status em `{open, closed, in_progress}`.
**Recomendação**: Valide o status contra uma allowlist ou uma BackedEnum do PHP:
```php
$validStatuses = ['open', 'in_progress', 'closed'];
if (!in_array($status, $validStatuses, true)) {
    return $this->problems->create($request, 'validation-failed', 'Validation Failed', 422, null, [
        'errors' => [['field' => 'status', 'code' => 'invalid', 'message' => 'status must be one of: open, in_progress, closed']],
    ]);
}
```

---

### Resumo ATK

| ID | Ataque | Resultado |
|----|--------|-----------|
| ATK-01 | JWT `alg:none` | 🚫 BLOCKED |
| ATK-02 | Adulteração de assinatura JWT | 🚫 BLOCKED |
| ATK-03 | Replay de JWT expirado | 🚫 BLOCKED |
| ATK-04 | IDOR: acessar tarefa de outro usuário | ✅ BLOCKED |
| ATK-05 | IDOR: deletar tarefa de outro usuário | ✅ BLOCKED |
| ATK-06 | Injeção de ID de ator via corpo | ✅ BLOCKED |
| ATK-07 | Leitura não autenticada do log de auditoria | ⚠️ EXPOSED |
| ATK-08 | Enumeração de auditoria entre atores | ⚠️ EXPOSED |
| ATK-09 | SQL injection na busca de auditoria | 🚫 BLOCKED |
| ATK-10 | DoS com limit -1 / limit grande | ✅ BLOCKED |
| ATK-11 | Força bruta no login (sem rate limit) | ⚠️ EXPOSED |
| ATK-12 | Injeção de valor de status arbitrário | ⚠️ EXPOSED |

**9 BLOCKED / SAFE, 4 EXPOSED** (ATK-07, 08 encadeados a partir da mesma lacuna de leitura de auditoria não autenticada).

A descoberta crítica é **ATK-07**: os endpoints do log de auditoria não têm guarda de autenticação, expondo o histórico completo de atividades do ator a qualquer chamador não autenticado. ATK-12 (allowlist de status) e ATK-11 (rate limiting) são lacunas de proteção padrão. Nenhum vetor de SQL injection ou falsificação de JWT foi encontrado.
