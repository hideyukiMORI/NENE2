# Como Fazer: Sistema de Denúncia de Conteúdo

> **Referência FT**: FT289 (`NENE2-FT/reportlog`) — Denúncia de conteúdo: motivos com allowlist (enum ReportReason), UNIQUE(reporter_id, article_id) com idempotente 200 em duplicata, máquina de estados pending→resolved/dismissed, list/resolve/dismiss exclusivos para moderadores, constraints CHECK no nível do BD, 32 testes / 58 asserções PASS.

Este guia mostra como construir um sistema de denúncia de conteúdo onde usuários sinalizam conteúdo e moderadores revisam e resolvem denúncias.

## Schema

```sql
CREATE TABLE users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    role TEXT NOT NULL DEFAULT 'user',
    created_at TEXT NOT NULL,
    CHECK (role IN ('user', 'moderator'))
);

CREATE TABLE reports (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    reporter_id INTEGER NOT NULL,
    article_id INTEGER NOT NULL,
    reason TEXT NOT NULL,
    details TEXT,
    status TEXT NOT NULL DEFAULT 'pending',
    resolved_by INTEGER,
    resolved_at TEXT,
    resolution_note TEXT,
    created_at TEXT NOT NULL,
    UNIQUE (reporter_id, article_id),
    CHECK (status IN ('pending', 'resolved', 'dismissed')),
    CHECK (reason IN ('spam', 'harassment', 'misinformation', 'other')),
    FOREIGN KEY (reporter_id) REFERENCES users(id),
    FOREIGN KEY (article_id) REFERENCES articles(id),
    FOREIGN KEY (resolved_by) REFERENCES users(id)
);
```

As constraints `CHECK` no nível do BD forçam valores de enum mesmo que a validação da aplicação seja contornada.

## Endpoints

| Método | Caminho | Auth | Descrição |
|--------|---------|------|-----------|
| `POST` | `/reports` | `X-User-Id` | Enviar uma denúncia |
| `GET` | `/reports` | Moderador | Listar todas as denúncias |
| `GET` | `/reports/{id}` | Denunciante ou Moderador | Obter denúncia |
| `PUT` | `/reports/{id}/resolve` | Moderador | Resolver denúncia |
| `PUT` | `/reports/{id}/dismiss` | Moderador | Rejeitar denúncia |

## Enum ReportReason

```php
enum ReportReason: string
{
    case Spam         = 'spam';
    case Harassment   = 'harassment';
    case Misinformation = 'misinformation';
    case Other        = 'other';
}
```

`ReportReason::tryFrom($reasonStr)` rejeita valores desconhecidos. O handler retorna motivos válidos na resposta de erro:

```php
$reason = ReportReason::tryFrom($reasonStr);
if ($reason === null) {
    $validReasons = array_map(fn(ReportReason $r) => $r->value, ReportReason::cases());
    return $this->responseFactory->create(['error' => 'invalid reason', 'valid_reasons' => $validReasons], 422);
}
```

## Envio de Denúncia Idempotente

Se um usuário já denunciou o mesmo artigo, retornar a denúncia existente com 200 (não 201):

```php
$existing = $this->repository->findReportByReporterAndArticle($actorId, $articleId);
if ($existing !== null) {
    return $this->responseFactory->create($this->formatReport($existing), 200);
}

// Primeira vez: 201 Created
$id = $this->repository->createReport(...);
return $this->responseFactory->create($this->formatReport(...), 201);
```

`UNIQUE(reporter_id, article_id)` garante isso no nível do BD. A aplicação verifica primeiro para retornar uma resposta amigável, mas a constraint UNIQUE é a rede de segurança.

## Ciclo de Vida do Status

```
pending ──→ resolved (ação do moderador)
       └──→ dismissed (ação do moderador)
```

Uma vez resolvida ou rejeitada, uma denúncia não pode mais fazer transições. Tentar alterar uma denúncia não pendente retorna 422:

```php
if ($report['status'] !== 'pending') {
    return $this->responseFactory->create([
        'error' => 'report is not pending',
        'current_status' => $report['status'],
    ], 422);
}
```

## Verificação de Papel de Moderador

```php
$actor = $this->repository->findUserById($actorId);
if ($actor === null || $actor['role'] !== 'moderator') {
    return $this->responseFactory->create(['error' => 'moderator role required'], 403);
}
```

O papel é armazenado na tabela `users` e verificado em cada operação privilegiada. Um `CHECK (role IN ('user', 'moderator'))` no nível do BD previne que papéis inválidos sejam inseridos.

## Controle de Acesso: Denunciante vs Moderador

GET `/reports/{id}` é acessível tanto pelo denunciante original quanto por moderadores:

```php
$isModerator = $actor['role'] === 'moderator';
$isReporter  = (int)$report['reporter_id'] === $actorId;

if (!$isModerator && !$isReporter) {
    return $this->responseFactory->create(['error' => 'access denied'], 403);
}
```

Denunciantes podem visualizar suas próprias denúncias para acompanhar o status. Moderadores veem todas as denúncias.

## Resolução com Trilha de Auditoria

```php
$this->repository->updateReportStatus($id, $newStatus, $actorId, date('c'), $note);
```

`resolved_by` (ID do moderador), `resolved_at` (timestamp) e `resolution_note` (opcional) criam uma trilha de auditoria para cada ação de moderação.

---

## O Que NÃO Fazer

| Anti-padrão | Risco |
|---|---|
| Aceitar string de motivo de forma livre | Erros de digitação, injeção, categorias infinitas; use allowlist com enum |
| Sem `UNIQUE(reporter_id, article_id)` | Mesmo usuário envia dezenas de denúncias para o mesmo artigo; fila inflada |
| Retornar 409 para denúncia duplicada | Segurança para retry com idempotência: duplicata → 200 com denúncia existente, não erro |
| Permitir transição de resolved/dismissed | Denúncia resolvida reaberta; trilha de auditoria fica não confiável |
| Sem verificação de papel de moderador em list/resolve | Qualquer usuário lê todas as denúncias; violação de privacidade + bypass de auditoria |
| Retornar denúncia própria do denunciante para outro usuário | IDOR — sempre verifique reporter === actor ou actor é moderador |
| Sem campo `resolution_note` | Moderadores não conseguem comunicar por que uma denúncia foi rejeitada vs resolvida |
| Sem campo `resolved_by` | Não é possível auditar qual moderador tomou a ação |
| Apenas CHECK no BD, sem validação na aplicação | BD lança exceção para motivo inválido; usuário recebe 500 em vez de 422 |
