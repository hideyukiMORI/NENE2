# Como Construir Gerenciamento de Anúncios do Sistema

> **Padrão comprovado pelo FT190 announcelog** — Anúncios do sistema baseados em tempo com autenticação de chave admin, dispensa por usuário e ordenação por prioridade. 38 testes / 93 assertivas PASS.

---

## O Que Este Guia Cobre

Uma API de anúncios do sistema para transmitir avisos de manutenção, atualizações de funcionalidades e alertas:

1. **Criar/Atualizar/Excluir** — operações apenas para admin via comparação de chave em tempo constante
2. **Listar ativos** — filtrado por tempo UTC via `starts_at` / `ends_at`
3. **Dispensar** — opt-out por usuário persistido como UPSERT idempotente

---

## Schema

```sql
CREATE TABLE announcements (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    title      TEXT    NOT NULL,
    body       TEXT    NOT NULL,
    starts_at  TEXT    NOT NULL,   -- ISO 8601 UTC
    ends_at    TEXT    NOT NULL,   -- ISO 8601 UTC
    priority   INTEGER NOT NULL DEFAULT 0,  -- maior = mostrado primeiro
    created_at TEXT    NOT NULL,
    updated_at TEXT    NOT NULL
);

CREATE TABLE announcement_dismissals (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id         INTEGER NOT NULL,
    announcement_id INTEGER NOT NULL,
    dismissed_at    TEXT    NOT NULL,
    UNIQUE(user_id, announcement_id)
);
```

`UNIQUE(user_id, announcement_id)` permite dispensa idempotente. `starts_at` / `ends_at` são strings ISO 8601 — comparação lexicográfica funciona corretamente para datetimes UTC.

---

## API

| Método | Caminho | Auth | Descrição |
|---|---|---|---|
| `POST` | `/announcements` | `X-Admin-Key` | Criar anúncio (201) |
| `PUT` | `/announcements/{id}` | `X-Admin-Key` | Atualizar anúncio (200) |
| `DELETE` | `/announcements/{id}` | `X-Admin-Key` | Excluir anúncio (200) |
| `GET` | `/announcements` | `X-User-Id` opcional | Listar anúncios atualmente ativos |
| `POST` | `/announcements/{id}/dismiss` | `X-User-Id` | Dispensar para este usuário (200) |

---

## Padrão Principal: Verificação de Chave Admin em Tempo Constante

```php
private function isAdmin(ServerRequestInterface $request): bool
{
    // Configuração adminKey vazia significa sem acesso admin — fail closed
    if ($this->adminKey === '') {
        return false;
    }

    $provided = $request->getHeaderLine('X-Admin-Key');

    // hash_equals: tempo constante — previne ataques de timing na comparação de chave
    return $provided !== '' && hash_equals($this->adminKey, $provided);
}
```

**Por que não `===`:** Comparação de string tem curto-circuito no primeiro mismatch. Um atacante pode medir diferenças de timing para encontrar correspondências de prefixo parcial e depois fazer força bruta caractere por caractere. `hash_equals()` leva tempo constante independentemente de onde está o mismatch.

**Fail-closed:** Uma configuração `adminKey` vazia sempre retorna `false` — não há modo "admin aberto" acidental.

---

## Padrão Principal: Filtragem Baseada em Tempo UTC

```php
// Listar anúncios ativos agora
$now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM);

SELECT ... FROM announcements
WHERE starts_at <= :now AND ends_at > :now
ORDER BY priority DESC, id DESC
```

Strings ISO 8601 em UTC ordenam lexicograficamente de forma correta — `'2025-06-01T...' > '2025-05-01T...'`. Sempre use UTC no banco de dados.

O `ends_at > :now` (maior que estrito) significa que um anúncio expira precisamente em `ends_at`, não um segundo depois.

---

## Padrão Principal: Dispensa por Usuário (Idempotente)

```php
// UNIQUE(user_id, announcement_id) permite chamadas de dispensa repetidas de forma segura
INSERT INTO announcement_dismissals (user_id, announcement_id, dismissed_at)
VALUES (:user_id, :announcement_id, :now)
ON CONFLICT(user_id, announcement_id) DO NOTHING
```

Um usuário chamando `POST /announcements/5/dismiss` duas vezes é seguro — a segunda chamada tem sucesso silenciosamente. O cliente nunca precisa verificar primeiro.

---

## Padrão Principal: Contexto de Usuário Opcional na Listagem

```php
// Sem X-User-Id: mostrar todos os anúncios ativos
// Com X-User-Id: excluir os dispensados para aquele usuário

// Sem usuário:
WHERE a.starts_at <= :now AND a.ends_at > :now

// Com usuário (LEFT JOIN + filtro IS NULL):
LEFT JOIN announcement_dismissals d
  ON d.announcement_id = a.id AND d.user_id = :user_id
WHERE a.starts_at <= :now AND a.ends_at > :now
  AND d.id IS NULL
```

Este único endpoint `GET /announcements` trata tanto casos não autenticados (monitoramento, visão admin) quanto autenticados (UI mostrando banners relevantes).

---

## Padrão Principal: ends_at Deve Ser Após starts_at

```php
// Validação do lado do servidor — não apenas confiança do cliente
if ($body['ends_at'] <= $body['starts_at']) {
    return 'ends_at deve ser após starts_at.';
}
```

Um anúncio com `ends_at <= starts_at` fica invisível imediatamente após a criação — valide e rejeite em vez de aceitar silenciosamente dados quebrados.

---

## Design da Resposta

| Cenário | Status | Corpo |
|---|---|---|
| Criar com sucesso | 201 | `{announcement: {id, title, body, starts_at, ends_at, priority}}` |
| Atualizar com sucesso | 200 | `{announcement: {...}}` |
| Excluir com sucesso | 200 | `{deleted: true}` |
| Listar ativos | 200 | `{data: [...], total: N}` |
| Dispensar | 200 | `{dismissed: true}` |
| Chave admin ausente/errada | 401 | `{error: "Chave admin obrigatória."}` |
| Não encontrado | 404 | `{error: "Anúncio não encontrado."}` |
| Validação falhou | 422 | `{error: "..."}` |

`created_at` / `updated_at` **não** estão na resposta pública — são metadados internos.

---

## Resultados dos Testes (FT190)

```
38 testes / 93 assertivas — todos PASS
PHPStan nível 8 — sem erros
PHP CS Fixer — limpo
```

Fonte: [`../NENE2-FT/announcelog/`](https://github.com/hideyukiMORI/NENE2-examples/tree/main/announcelog)
