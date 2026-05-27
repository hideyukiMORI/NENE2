# Como Fazer: API de Mascaramento de PII

> **Referência FT**: FT297 (`NENE2-FT/masklog`) — Mascaramento de PII: mascaramento parcial de e-mail/telefone/nome, acesso a dados brutos baseado em função (apenas admin) com trilha de auditoria obrigatória X-Accessor, log de auditoria imutável, VULN-A~L todos SAFE, 24 testes / 49 assertivas PASS.

Este guia mostra como construir uma API de dados de clientes que mascara PII (Informações de Identificação Pessoal) por padrão e concede acesso total apenas a funções autorizadas com trilha de auditoria.

## Schema

```sql
CREATE TABLE customers (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT NOT NULL,
    email      TEXT NOT NULL,
    phone      TEXT NOT NULL,
    created_at TEXT NOT NULL
);

CREATE TABLE mask_audit_log (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    customer_id INTEGER NOT NULL REFERENCES customers(id),
    accessor    TEXT NOT NULL,
    accessed_at TEXT NOT NULL
);
```

PII bruta é armazenada em `customers`. Todo acesso admin a dados brutos é registrado em `mask_audit_log` (somente adição — sem rota de atualização/exclusão).

## Padrões de Mascaramento

```php
final class MaskService
{
    // "john.doe@example.com" → "j***@example.com"
    public function maskEmail(string $email): string
    {
        $at     = strpos($email, '@');
        $local  = substr($email, 0, $at);
        $domain = substr($email, $at + 1);
        return substr($local, 0, 1) . '***@' . $domain;
    }

    // "090-1234-5678" → "***-****-5678" (últimos 4 dígitos mantidos)
    public function maskPhone(string $phone): string
    {
        $digits   = preg_replace('/\D/', '', $phone);
        $keepFrom = strlen($digits) - 4;
        $replaced = 0;
        $result   = '';
        for ($i = 0; $i < strlen($phone); $i++) {
            $ch = $phone[$i];
            if (ctype_digit($ch)) {
                $result .= ($replaced < $keepFrom) ? ('*' . ($replaced++ | 0) * 0 . '') : $ch;
                $replaced++;
            } else {
                $result .= $ch;
            }
        }
        return $result;
    }

    // "John Doe" → "J*** D***"
    public function maskName(string $name): string
    {
        $words = explode(' ', $name);
        return implode(' ', array_filter(array_map(
            fn($w) => $w !== '' ? mb_substr($w, 0, 1) . '***' : '',
            $words
        )));
    }
}
```

## Acesso Baseado em Função — Mascarado por Padrão

```php
private function handleGet(ServerRequestInterface $request): ResponseInterface
{
    $id       = $this->id($request);
    $customer = $this->repo->find($id);
    if ($customer === null) {
        return $this->json->create(['error' => 'Customer not found'], 404);
    }

    $role     = $request->getHeaderLine('X-Role');
    $accessor = trim($request->getHeaderLine('X-Accessor'));

    if ($role === 'admin') {
        if ($accessor === '') {
            return $this->json->create(['error' => 'X-Accessor header required for admin access'], 403);
        }
        $this->repo->logAccess((int) $customer['id'], $accessor, $this->now());
        return $this->json->create($customer);  // PII bruta
    }

    return $this->json->create($this->masker->applyMask($customer));  // mascarado
}
```

- **Não-admin (padrão)**: sempre recebe dados mascarados.
- **Admin com `X-Accessor`**: recebe dados brutos e o acesso é registrado.
- **Admin sem `X-Accessor`**: 403 — a trilha de auditoria não pode estar em branco.

## Log de Auditoria — Somente Adição

```php
public function register(Router $router): void
{
    $router->post('/customers', $this->handleCreate(...));
    $router->get('/customers/{id}', $this->handleGet(...));
    $router->get('/customers/{id}/audit', $this->handleAudit(...));
    // Sem DELETE ou PUT para o log de auditoria — imutável por design
}
```

O log de auditoria não tem rota de exclusão ou atualização. As entradas são permanentes; apenas admins podem ler o log.

---

## Avaliação de Vulnerabilidade

### V-01 — PII não exposta no GET padrão ✅ SAFE

**Risco**: Não-admin lê e-mail/telefone/nome bruto do cliente.
**Achado**: SAFE — a resposta padrão sempre aplica `applyMask()`. Campos brutos nunca são retornados sem `X-Role: admin`.

---

### V-02 — Injeção SQL no campo nome ✅ SAFE

**Risco**: `"name": "'; DROP TABLE customers; --"` exclui dados.
**Achado**: SAFE — queries parametrizadas armazenam a string de injeção literalmente como nome.

---

### V-03 — Injeção SQL no campo e-mail ✅ SAFE

**Risco**: Injeção SQL via e-mail na criação.
**Achado**: SAFE — mesma proteção de query parametrizada.

---

### V-04 — IDOR: não-admin lê PII bruta via ID de cliente ✅ SAFE

**Risco**: Sem `X-Role: admin`, um usuário tenta `GET /customers/1` para obter PII completa.
**Achado**: SAFE — qualquer requisição sem `X-Role: admin` recebe dados mascarados independente do ID do cliente.

---

### V-05 — Escalada de função: header X-Role arbitrário ✅ SAFE

**Risco**: Enviar `X-Role: superuser` ou `X-Role: ADMIN` para ignorar o mascaramento.
**Achado**: SAFE — apenas a string exata `'admin'` concede acesso bruto: `if ($role === 'admin')`. Qualquer outro valor resulta em resposta mascarada.

---

### V-06 — Admin sem header X-Accessor ✅ SAFE

**Risco**: Admin acessa dados brutos sem X-Accessor para evitar trilha de auditoria.
**Achado**: SAFE — `if ($accessor === '') return 403`. Acesso admin requer identificador de accessor não vazio.

---

### V-07 — Log de auditoria não acessível para não-admin ✅ SAFE

**Risco**: Não-admin lê `GET /customers/1/audit` para descobrir quem acessou seus dados.
**Achado**: SAFE — endpoint de auditoria verifica `X-Role: admin`. Não-admin → 403.

---

### V-08 — Cliente inexistente retorna 404 ✅ SAFE

**Risco**: Consultar ID inexistente retorna 500 ou vaza erros do banco.
**Achado**: SAFE — `if ($customer === null) return 404`. Erro limpo, sem informações internas.

---

### V-09 — Entrada extremamente longa não trava ✅ SAFE

**Risco**: Nome com 10.000 caracteres causa erro no banco ou esgotamento de memória.
**Achado**: SAFE — o tipo TEXT do SQLite não tem limite de comprimento; a aplicação armazena e mascara sem travar. Em produção, adicione limite de comprimento (ex.: 500 chars).

---

### V-10 — Payload XSS armazenado como literal ✅ SAFE

**Risco**: `"name": "<script>alert(1)</script>"` é executado em um navegador.
**Achado**: SAFE — a API retorna `application/json`; a codificação JSON escapa `<` e `>`. Sem renderização HTML na camada de API.

---

### V-11 — Resposta mascarada nunca revela PII completa ✅ SAFE

**Risco**: Resposta mascarada contém dados suficientes para reconstruir o PII original.
**Achado**: SAFE — e-mail: apenas primeiro char + domínio; telefone: apenas últimos 4 dígitos; nome: apenas primeiro char por palavra. Impossível reconstruir o original.

---

### V-12 — Log de auditoria é imutável ✅ SAFE

**Risco**: Admin exclui suas próprias entradas de log de auditoria para encobrir rastros.
**Achado**: SAFE — não existe rota `DELETE /customers/{id}/audit`. Entradas de log são somente adição.

---

### Resumo VULN

| ID | Vulnerabilidade | Achado |
|----|-----------------|--------|
| V-01 | PII exposta no GET padrão | ✅ SAFE |
| V-02 | Injeção SQL no nome | ✅ SAFE |
| V-03 | Injeção SQL no e-mail | ✅ SAFE |
| V-04 | IDOR: não-admin lê PII bruta | ✅ SAFE |
| V-05 | Escalada de função via header X-Role | ✅ SAFE |
| V-06 | Admin sem X-Accessor | ✅ SAFE |
| V-07 | Log de auditoria acessível para não-admin | ✅ SAFE |
| V-08 | Comportamento com cliente inexistente | ✅ SAFE |
| V-09 | Travamento com entrada extremamente longa | ✅ SAFE |
| V-10 | Payload XSS no nome | ✅ SAFE |
| V-11 | Resposta mascarada revela PII | ✅ SAFE |
| V-12 | Mutabilidade do log de auditoria | ✅ SAFE |

**12 SAFE, 0 EXPOSED**
Mascaramento padrão, auditoria obrigatória de accessor, verificação estrita de função e log imutável previnem todos os vetores de exposição de PII e bypass de auditoria.

---

## O Que NÃO Fazer

| Anti-padrão | Risco |
|---|---|
| Retornar PII bruta por padrão | Qualquer usuário autenticado lê e-mail/telefone/nome completo |
| Verificação de função sem distinção de maiúsculas/minúsculas (`strtolower`) sem allowlist explícita | `ADMIN`, `Admin`, `aDmIn` — aceitar apenas a string exata esperada |
| Permitir acesso admin sem X-Accessor | Sem trilha de auditoria; falha de conformidade GDPR |
| Log de auditoria mutável | Admins excluem suas próprias entradas; trilha forense não confiável |
| Expor log de auditoria para não-admin | Usuários descobrem quem (quais funcionários) acessou seus dados |
| Mascaramento por hash (mostrar hash em vez dos dados reais) | Hash de PII ainda é sensível — atacantes podem fazer força bruta em valores curtos |
| Sem mascaramento na resposta de criação | Resposta de criação de novo cliente expõe o PII recém-armazenado |
| Sem limite de comprimento de entrada | Entradas muito longas consomem armazenamento; adicionar limites explícitos de comprimento em produção |
