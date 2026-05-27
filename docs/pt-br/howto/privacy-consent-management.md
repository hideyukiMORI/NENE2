# Como Construir Gerenciamento de Consentimento de Privacidade

> **Padrão comprovado pelo FT189 consentlog** — Rastreamento de consentimento no estilo GDPR com histórico imutável, prevenção de IDOR e resistência à enumeração de usuário. VULN-A~L todos Pass.

---

## O Que Este Guia Cobre

Um fluxo de gerenciamento de consentimento de privacidade:

1. **Conceder consentimento** — usuário concede consentimento para um propósito nomeado
2. **Retirar consentimento** — usuário retira o consentimento
3. **Listar consentimentos** — estado atual de consentimento para todos os propósitos
4. **Histórico** — log de auditoria imutável e somente adição por propósito

Garantias de segurança:

| Preocupação | Técnica |
|---|---|
| IDOR — consentimentos de outro usuário | Todas as queries aplicam `WHERE user_id = :user_id` |
| Atribuição em massa (campo granted) | `granted` é controlado pelo servidor; corpo não pode sobrescrever |
| Injeção SQL em purpose | `ctype_alnum()` — apenas alfanumérico |
| ReDoS em purpose | `ctype_alnum()` O(n) — sem regex |
| Confusão de tipo | `is_string()` antes de `ctype_alnum()` |
| Enumeração de usuário | Usuário desconhecido retorna array vazio, não 404 |
| Condição de corrida em grant/withdraw | Atomicidade do UPSERT em `UNIQUE(user_id, purpose)` |
| Repetição de consentimento | Histórico é somente adição; cada mudança é uma nova entrada |

---

## Schema

```sql
CREATE TABLE consents (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    purpose    TEXT    NOT NULL,  -- slug alfanumérico: 'marketing', 'analytics', etc.
    granted    INTEGER NOT NULL DEFAULT 1,  -- 1=concedido, 0=retirado
    created_at TEXT    NOT NULL,
    updated_at TEXT    NOT NULL,
    UNIQUE(user_id, purpose)
);

CREATE TABLE consent_history (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    purpose    TEXT    NOT NULL,
    granted    INTEGER NOT NULL,   -- 1=concedido, 0=retirado
    created_at TEXT    NOT NULL    -- quando esta mudança ocorreu
);
```

`UNIQUE(user_id, purpose)` habilita upsert atômico. `consent_history` é somente adição — nunca atualizado.

---

## API

| Método | Caminho | Header | Descrição |
|---|---|---|---|
| `POST` | `/consents` | `X-User-Id` | Conceder consentimento (201) |
| `DELETE` | `/consents/{purpose}` | `X-User-Id` | Retirar consentimento (200) |
| `GET` | `/consents` | `X-User-Id` | Listar consentimentos atuais |
| `GET` | `/consents/{purpose}/history` | `X-User-Id` | Histórico de auditoria (somente adição) |

---

## Padrão Principal: UPSERT Idempotente

```php
// Conceder — idempotente: re-conceder um propósito já concedido é seguro
INSERT INTO consents (user_id, purpose, granted, created_at, updated_at)
VALUES (:user_id, :purpose, 1, :now, :now)
ON CONFLICT(user_id, purpose) DO UPDATE
SET granted = 1, updated_at = :now

// Retirar — mesmo padrão
INSERT INTO consents (user_id, purpose, granted, created_at, updated_at)
VALUES (:user_id, :purpose, 0, :now, :now)
ON CONFLICT(user_id, purpose) DO UPDATE
SET granted = 0, updated_at = :now
```

UPSERT em `UNIQUE(user_id, purpose)` é atômico — previne condições de corrida onde grant+withdraw simultâneos poderiam criar uma linha duplicada.

---

## Padrão Principal: Histórico Imutável

```php
// Sempre adicionar ao histórico — até re-concessão é registrada
INSERT INTO consent_history (user_id, purpose, granted, created_at)
VALUES (:user_id, :purpose, 1, :now)
```

O histórico **nunca é atualizado** — é um log de auditoria de cada mudança de consentimento. Isso permite que reguladores verifiquem quando o consentimento foi dado e quando foi retirado.

---

## Padrão Principal: Validação de Propósito

```php
private function resolvePurpose(mixed $raw): ?string
{
    // VULN-G: confusão de tipo — deve ser uma string
    if (!is_string($raw)) {
        return null;
    }

    $len = strlen($raw);

    if ($len === 0 || $len > self::MAX_PURPOSE_LEN) {
        return null;
    }

    // VULN-I: ctype_alnum é O(n) — sem regex, sem ReDoS
    // VULN-D: apenas alfanumérico — sem HTML, sem chars especiais SQL
    if (!ctype_alnum($raw)) {
        return null;
    }

    return $raw;
}
```

`ctype_alnum()` aceita apenas `[a-zA-Z0-9]` — rejeitando espaços, hífens, metacaracteres SQL e tags HTML em uma única passagem O(n).

---

## Padrão Principal: Prevenção de Enumeração de Usuário

```php
// VULN-F: retornar array vazio para usuário desconhecido — não 404
public function listForUser(int $userId): array
{
    $stmt = $this->pdo->prepare(
        'SELECT ... FROM consents WHERE user_id = :user_id ORDER BY purpose ASC',
    );
    $stmt->execute(['user_id' => $userId]);

    return array_map(fn(array $r) => $this->hydrateConsent($r), $stmt->fetchAll(PDO::FETCH_ASSOC));
}
```

Retornar 404 para um usuário desconhecido vaza "este user_id não existe". Sempre retorne 200 com dados vazios.

---

## Padrão Principal: Prevenção de IDOR

```php
// VULN-B: todas as leituras e escritas aplicam escopo ao usuário autenticado
// Mesmo que um atacante envie X-User-Id: 999, ele vê apenas dados do usuário 999
WHERE user_id = :user_id AND purpose = :purpose
```

Nenhuma query entre usuários toca o registro de outro usuário.

---

## Padrão Principal: Campo granted Controlado pelo Servidor

```php
// VULN-C/E: granted é controlado pelo endpoint — nunca do corpo
// POST /consents → sempre concede (granted = 1)
// DELETE /consents/{purpose} → sempre retira (granted = 0)
// Corpo { "granted": false } em POST é silenciosamente ignorado
```

O próprio endpoint determina o valor de `granted`. Um campo no corpo nunca pode sobrescrevê-lo.

---

## Design da Resposta

| Cenário | Status | Corpo |
|---|---|---|
| Concessão bem-sucedida | 201 | `{consent: {id, purpose, granted: true, updated_at}}` |
| Retirada bem-sucedida | 200 | `{consent: {id, purpose, granted: false, updated_at}}` |
| Listar consentimentos | 200 | `{data: [...], total: N}` |
| Histórico | 200 | `{data: [{id, purpose, granted, created_at}, ...], total: N}` |
| Usuário desconhecido | 200 | `{data: [], total: 0}` — não 404 |

`user_id` **nunca** é incluído em nenhuma resposta — é implícito pelo `X-User-Id`.

---

## VULN-A~L todos Pass

| VULN | Ataque | Defesa |
|---|---|---|
| A | Injeção SQL em X-User-Id | `ctype_digit()` + guarda strlen > 18 |
| B | IDOR — manipular consentimento de outro usuário | Todas as queries com `WHERE user_id = :user_id` |
| C | Atribuição em massa (falsificação do campo granted) | granted é determinado pelo endpoint — corpo não é usado |
| D | XSS em purpose | `ctype_alnum()` — apenas alfanumérico |
| E | Sobrescrita direta do estado de consentimento | grant/withdraw são endpoints independentes |
| F | Enumeração de usuário | user_id desconhecido retorna array vazio 200 |
| G | Confusão de tipo (purpose como int/array/null) | `is_string()` + `ctype_alnum()` |
| H | Repetição de consentimento | histórico é somente adição, re-concessão é nova entrada |
| I | ReDoS em purpose | `ctype_alnum()` O(n) |
| J | Overflow de inteiro em X-User-Id | guarda strlen > 18 |
| K | Condição de corrida grant+withdraw simultâneos | Atomicidade UPSERT em `UNIQUE(user_id, purpose)` |
| L | Injeção CRLF em headers | PSR-7 rejeita na camada HTTP |

---

## Resultados de Teste (FT189)

```
51 testes / 142 assertivas — todos PASS
PHPStan nível 8 — sem erros
PHP CS Fixer — limpo
VULN-A~L todos Pass
```

Fonte: [`../NENE2-FT/consentlog/`](https://github.com/hideyukiMORI/NENE2-examples/tree/main/consentlog)
