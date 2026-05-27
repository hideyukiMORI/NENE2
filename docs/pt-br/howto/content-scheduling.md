# Agendamento de Conteúdo — Publicação por Tempo com Estados de Ciclo de Vida

Agende conteúdo para publicar em uma data futura usando uma coluna `publish_at`,
uma máquina de estados (`draft → scheduled → published → archived`), e um
**endpoint de gatilho de publicação** que um cron job chama para publicar artigos vencidos.

**Implementação de referência:** `FT172 pubschedulelog` em
[hideyukiMORI/NENE2-examples](https://github.com/hideyukiMORI/NENE2-examples)

---

## Ciclo de Vida do Status

```
draft ──┬──► scheduled ──► published ──► archived
        │                               ▲
        └───────────────────────────────┘
        (também: scheduled → draft via unschedule)
```

| De | Transições permitidas |
|---|---|
| `draft` | `scheduled`, `published`, `archived` |
| `scheduled` | `published`, `draft`, `archived` |
| `published` | `archived` |
| `archived` | *(nenhuma)* |

---

## Schema

```sql
CREATE TABLE articles (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    author_id    INTEGER NOT NULL,
    title        TEXT    NOT NULL,
    body         TEXT    NOT NULL,
    status       TEXT    NOT NULL DEFAULT 'draft',
    -- 'draft' | 'scheduled' | 'published' | 'archived'
    publish_at   TEXT,    -- ISO 8601; definido quando agendado; NULL caso contrário
    published_at TEXT,    -- definido quando realmente publicado
    created_at   TEXT    NOT NULL,
    updated_at   TEXT    NOT NULL
);
```

---

## Endpoints

| Método | Caminho | Auth | Descrição |
|--------|---------|------|-----------|
| `POST` | `/articles` | X-User-Id | Criar um rascunho |
| `GET` | `/articles` | opcional | Listar (`?status=published` é público; outros status exigem auth + apenas artigos próprios) |
| `GET` | `/articles/{id}` | opcional | Obter um artigo (publicado = público, rascunho/agendado = somente o proprietário) |
| `PUT` | `/articles/{id}` | X-User-Id | Atualizar título/corpo (somente rascunho ou agendado) |
| `POST` | `/articles/{id}/schedule` | X-User-Id | Definir `publish_at` → move para `scheduled` |
| `POST` | `/articles/{id}/unschedule` | X-User-Id | Cancelar agendamento → reverte para `draft` |
| `POST` | `/articles/{id}/publish` | X-User-Id | Publicar imediatamente |
| `POST` | `/articles/{id}/archive` | X-User-Id | Arquivar |
| `POST` | `/articles/publish-due` | X-Admin-Key | Publicar em lote todos os artigos agendados vencidos |

---

## Padrões Principais

### Enum de Status com Guarda de Transição

```php
enum ArticleStatus: string {
    case Draft     = 'draft';
    case Scheduled = 'scheduled';
    case Published = 'published';
    case Archived  = 'archived';

    public function canTransitionTo(self $next): bool {
        return match ($this) {
            self::Draft     => in_array($next, [self::Scheduled, self::Published, self::Archived], true),
            self::Scheduled => in_array($next, [self::Published, self::Draft, self::Archived], true),
            self::Published => $next === self::Archived,
            self::Archived  => false,
        };
    }
}
```

### Agendamento: Validação de Data Futura

```php
$ts = strtotime($publishAt);
if ($ts === false || $ts === -1) {
    throw new ArticleScheduleException('publish_at is not a valid datetime.');
}
if ($ts <= strtotime($now)) {
    throw new ArticleScheduleException('publish_at must be in the future.');
}
```

### Gatilho de Publicação Vencida (seguro para cron, idempotente)

```php
public function publishDue(string $now): array
{
    $rows = $this->db->fetchAll(
        "SELECT id FROM articles WHERE status = ? AND publish_at <= ? ORDER BY publish_at",
        [ArticleStatus::Scheduled->value, $now],
    );

    $published = [];
    foreach ($rows as $row) {
        $id = (int) $row['id'];
        $this->db->execute(
            'UPDATE articles SET status = ?, published_at = ?, publish_at = NULL, updated_at = ? WHERE id = ?',
            [ArticleStatus::Published->value, $now, $now, $id],
        );
        $published[] = $id;
    }

    return $published;  // list<int>
}
```

Chame isso de um cron job a cada minuto. Idempotente: executar novamente imediatamente não
encontra novos artigos vencidos, pois `publish_at` é limpo para `NULL` na publicação.

### Prevenção de IDOR

Artigos em rascunho e agendados são **exclusivos do proprietário** — retorne 404 (não 403) para
evitar vazar a existência:

```php
if ($article->authorId !== $actorId) {
    throw new ArticleNotFoundException($id);  // 404, não 403
}
```

### Chave Admin — Comparação Segura Contra Timing Attack

```php
if ($apiKey === '' || !hash_equals($expected, $apiKey)) {
    return $this->responseFactory->create(['error' => 'unauthorized'], 401);
}
```

Nunca use `!==` para comparações de segredos — use `hash_equals()` para prevenir
ataques de timing.

---

## Notas de Segurança

| Risco | Mitigação |
|-------|-----------|
| Injeção de `publish_at` passado | `strtotime($publishAt) <= strtotime($now)` → 422 |
| Mutação de estado entre usuários | Verificação de propriedade antes de cada transição; 404 não 403 |
| Injeção de ID de autor via corpo | `authorId` obtido somente do header `X-User-Id` |
| Injeção de status via corpo | Campo `status` no corpo do PUT é ignorado; transições via endpoints de ação dedicados |
| Timing attack na chave admin | `hash_equals()` em vez de `!==` |
| Enumeração de artigos não publicados | Listagem pública sempre filtra por `status = published`; não publicados exigem auth + apenas artigos próprios |
| Edição após publicar | PUT rejeita artigos não draft/scheduled com 422 |
| Arquivamento duplo | Guarda de transição retorna 409 para transições inválidas |

---

## Integração com Cron

```bash
# /etc/cron.d/publish-due
* * * * * www-data curl -s -X POST https://api.example.com/articles/publish-due \
  -H "X-Admin-Key: $ADMIN_KEY"
```

Para workloads de maior volume, migre para uma fila de jobs (consulte
[job-queue.md](./job-queue.md)) e use o worker da fila para chamar `publishDue()`.

---

## Veja Também

- [Ciclo de Vida de Rascunho de Conteúdo](./content-draft-lifecycle.md) — draft/active/archived sem agendamento
- [Fila de Jobs](./job-queue.md) — processamento em background para gatilhos de publicação de alto volume
- [Soft Delete](./soft-delete.md) — complemento ao arquivamento
- [Trilha de Auditoria](./audit-trail.md) — registrando quem publicou o quê e quando
