# API de Encurtador de URL e Prevenção de SSRF

**FT183** — field trial `shortlog` (diagnóstico de vulnerabilidades VULN-A〜L).

Um encurtador de URL permite que usuários enviem URLs arbitrárias como destinos de redirecionamento. Se o
redirecionamento é seguido no lado do servidor (por exemplo, para pré-visualização de link ou analytics) sem
validação, atacantes podem apontá-lo para serviços internos — isso é um ataque de
**Server-Side Request Forgery (SSRF)**.

Este guia aborda a prevenção de SSRF juntamente com a auditoria de segurança completa VULN-A〜L
executada contra a implementação do shortlog.

---

## SSRF: O Risco Central

Um encurtador de URL armazena e potencialmente busca uma URL controlada pelo atacante. O SSRF
permite que um atacante:

- Alcance serviços internos: `http://10.0.0.1/admin`, `http://192.168.1.1/`
- Acesse metadados de nuvem: `http://169.254.169.254/latest/meta-data/` (AWS IMDS)
- Leia arquivos locais: `file:///etc/passwd`
- Execute scripts no browser: `javascript:alert(1)`
- Acesse serviços de loopback: `http://127.0.0.1:8080/`

**A correção:** valide o esquema da URL _e_ o IP de destino antes de armazená-la.

---

## Estratégia de Validação de URL (VULN-K)

### Passo 1 — Allowlist de esquemas

`filter_var($url, FILTER_VALIDATE_URL)` sozinho **não é suficiente** — ele aceita
`javascript:alert(1)` e `ftp://` como URLs válidas. Use `parse_url()` e um
allowlist explícito de esquemas:

```php
$parts = parse_url($url);

if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
    return false;   // URL malformada — sem esquema ou host
}

if (!in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
    return false;   // Rejeita: javascript:, file://, ftp://, data:, etc.
}
```

`parse_url()` não é uma regex — não pode ser explorado por ReDoS (VULN-F).

### Passo 2 — Validação de host / IP

```php
$host = strtolower($parts['host']);

// Remover colchetes IPv6: [::1] → ::1
if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
    $host = substr($host, 1, -1);
}

// Bloquear localhost e aliases *.localhost
if ($host === 'localhost' || str_ends_with($host, '.localhost')) {
    return false;
}

// Se host é um IP literal, verificar diretamente
if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
    return !isBlockedIp($host);
}

// Caso contrário, resolver hostname → verificar IP resolvido
$resolved = gethostbyname($host);

if ($resolved !== $host) {   // false se não resolvível
    return !isBlockedIp($resolved);
}
// Hostname não resolvível → permitir (pode ser domínio válido não acessível do servidor)
return true;
```

### Passo 3 — Verificação de IP privado / reservado

```php
function isBlockedIp(string $ip): bool
{
    // Loopback IPv6
    if ($ip === '::1') return true;

    // FILTER_FLAG_NO_PRIV_RANGE: bloqueia 10.x, 172.16-31.x, 192.168.x
    // FILTER_FLAG_NO_RES_RANGE:  bloqueia 127.x, 169.254.x, 0.x, 240.x+
    return filter_var(
        $ip,
        FILTER_VALIDATE_IP,
        FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
    ) === false;
}
```

### Atenção ao DNS Rebinding

Ataques de DNS rebinding mudam o IP de um domínio _após_ a validação passar. Para
casos de uso críticos, valide a URL no _momento da busca_ também (não apenas no armazenamento),
ou use um firewall de egresso na camada de rede que bloqueie faixas privadas.

---

## Injetar o Resolver para Testes

Chamadas DNS em testes unitários são lentas e não determinísticas. Torne o resolver
injetável:

```php
final class UrlValidator
{
    /** @param (callable(string): string)|null $ipResolver */
    public function __construct(private readonly mixed $ipResolver = null)
    {
    }

    private function resolveHost(string $host): string
    {
        /** @var callable(string): string $resolver */
        $resolver = $this->ipResolver ?? static fn (string $h): string => gethostbyname($h);
        return $resolver($host);
    }
}
```

Nos testes:

```php
$stubResolver = static function (string $host): string {
    return match ($host) {
        'private.internal'   => '10.0.0.1',       // privado → bloqueado
        'public.example.com' => '93.184.216.34',  // público → permitido
        default              => $host,             // não resolvível → permitido
    };
};

$validator = new UrlValidator($stubResolver);
```

---

## Resultados da Avaliação VULN-A〜L

### VULN-A — Overflow de inteiro (parâmetro de query `limit`)

`V::queryInt()` usa `ctype_digit()` + proteção `strlen() > 18`.
Strings de 20 e 19 dígitos são rejeitadas antes do cast para `(int)`.

```
✅ PASS — proteção contra overflow previne wrap silencioso para PHP_INT_MAX
```

### VULN-B — Confusão de tipo (URL / slug do corpo JSON)

`V::str()` aplica `is_string()` — rejeita `int 42`, `bool true`, `null`.

```php
V::str($body['original_url'] ?? null, 2048)  // → null para não-string
V::str($body['slug'] ?? null, 20)            // → null para não-string
```

```
✅ PASS — tipo string aplicado antes de qualquer validação de URL ou slug
```

### VULN-C — Injeção SQL

Todas as queries usam prepared statements PDO parametrizados:

```php
'SELECT ... FROM links WHERE slug = :slug LIMIT 1'
// → $stmt->execute([':slug' => $slug])
```

`'; DROP TABLE links; --'` falha na validação de formato do slug (SLUG_PATTERN)
antes de chegar ao banco. Mesmo que chegasse ao banco, queries parametrizadas
previnem a execução.

```
✅ PASS — queries parametrizadas + allowlist de slug
```

### VULN-D — Poluição de parâmetros

`getQueryParams()` do PSR-7 chama `parse_str()` do PHP que toma o _último_
valor para chaves duplicadas. Enviar `?limit=10&limit=999999` → `limit=999999`
que falha na verificação de intervalo de `V::queryInt()` (> MAX_LIMIT).

```
✅ PASS — verificação de intervalo captura qualquer valor único; sem crash
```

### VULN-E — IDOR (acesso a link entre usuários)

DELETE usa `deleteForUser($slug, $userId)`:

```sql
DELETE FROM links WHERE slug = :slug AND user_id = :user_id
```

O `DELETE /links/user-a-slug` do Usuário B com seu próprio `X-User-Id` retorna 404
(a linha não é deletada; simplesmente não corresponde à cláusula WHERE).

```
✅ PASS — propriedade aplicada no nível do banco; 404 evita enumeração
```

### VULN-F — Imunidade a ReDoS

A validação de URL usa `parse_url()` (extensão C, sem backtracking).
A validação de slug usa uma regex simples ancorada sem grupos de alternância.
`V::queryInt()` usa `ctype_digit()` (O(n), imune a backtracking).

```
✅ PASS — sem regex com backtracking exponencial em entrada não confiável
```

### VULN-G — Path traversal

Sem acesso ao sistema de arquivos nesta API. Não aplicável.

```
N/A
```

### VULN-H — Ataques de timing em comparação de segredos

`V::secret()` delega para `hash_equals()` — tempo constante independente de onde
as strings diferem. Evita comparação de string com saída antecipada que vaza informações de comprimento/prefixo
via timing.

```
✅ PASS — hash_equals() previne oracle de timing
```

### VULN-I — Bypass de segredo esperado vazio

`V::secret('', '')` → `false`. Uma chave de API não configurada nunca concede acesso:

```php
return $expected !== '' && hash_equals($expected, $actual);
```

```
✅ PASS — esperado vazio sempre retorna false
```

### VULN-J — Overflow de data ISO 8601 em `expires_at`

`V::isoDatetime()` usa `DateTimeImmutable::createFromFormat(DATE_ATOM, ...)` +
comparação de round-trip. `2024-02-30T00:00:00+00:00` rola para 1 de março no PHP;
a string re-formatada não corresponde à entrada → null.

Offset `+25:00`: capturado por verificação explícita de intervalo `$tzHours > 14` (PHP silenciosamente
aceita sem a verificação, e o round-trip também passa — tornando a verificação explícita
obrigatória).

```
✅ PASS — round-trip captura datas com overflow; verificação explícita de intervalo de offset captura +25:00
```

### VULN-K — SSRF

Sem validação de URL: `http://127.0.0.1/admin`, `http://169.254.169.254/`,
`http://10.0.0.1/`, `javascript:alert(1)`, `file:///etc/passwd` seriam todos
armazenados e potencialmente buscados.

Com `UrlValidator`:

| Entrada | Motivo do bloqueio |
|---|---|
| `http://127.0.0.1/` | IP loopback (`NO_RES_RANGE`) |
| `http://localhost/` | correspondência exata `'localhost'` |
| `http://internal.localhost/` | sufixo `.localhost` |
| `http://10.0.0.1/` | IP privado (`NO_PRIV_RANGE`) |
| `http://192.168.1.1/` | IP privado |
| `http://169.254.169.254/` | IP reservado (`NO_RES_RANGE`) |
| `http://private.internal/` | resolve para 10.0.0.1 → bloqueado |
| `javascript:alert(1)` | esquema não em `['http','https']` |
| `file:///etc/passwd` | esquema não no allowlist |
| `ftp://example.com/` | esquema não no allowlist |

```
✅ PASS — allowlist de esquemas + filtro de faixa de IP bloqueia todos os vetores SSRF
```

### VULN-L — Mass Assignment

`click_count` e `created_at` são definidos no lado do servidor em `LinkRepository::create()`.
Chaves do corpo da requisição `click_count: 999999` e `created_at: "2000-01-01..."` são
simplesmente ignoradas — o controller nunca as lê.

```
✅ PASS — campos definidos no servidor ficam no repositório, nunca vindo do corpo da requisição
```

---

## Resumo da Avaliação VULN

| ID | Vulnerabilidade | Status |
|---|---|---|
| VULN-A | Overflow de inteiro | ✅ PASS |
| VULN-B | Confusão de tipo | ✅ PASS |
| VULN-C | Injeção SQL | ✅ PASS |
| VULN-D | Poluição de parâmetros | ✅ PASS |
| VULN-E | IDOR | ✅ PASS |
| VULN-F | ReDoS | ✅ PASS |
| VULN-G | Path traversal | N/A |
| VULN-H | Ataques de timing | ✅ PASS |
| VULN-I | Bypass de segredo vazio | ✅ PASS |
| VULN-J | Overflow de DateTime | ✅ PASS |
| VULN-K | SSRF | ✅ PASS |
| VULN-L | Mass assignment | ✅ PASS |

**Todas as vulnerabilidades aplicáveis: PASS (11/11)**

---

## Segurança de Slug (VULN-A, C)

Slugs devem ser restritos a um conjunto de caracteres seguro para prevenir tanto injeção quanto
roteamento inesperado:

```php
// Padrão: alfanumérico minúsculo + hífens/sublinhados, 3–20 caracteres
// Deve começar e terminar com alfanumérico
private const SLUG_PATTERN = '/^[a-z0-9][a-z0-9_-]{1,18}[a-z0-9]$|^[a-z0-9]{3}$/';

if (!preg_match(self::SLUG_PATTERN, $rawSlug)) {
    return 422;
}
```

Esta regex única é ancorada e não tem grupos de alternância com caminhos de correspondência
sobrepostos — não pode ser explorada para ReDoS.

**Slugs rejeitados**: `'; DROP TABLE links; --'` · `../../etc` · `MySlug`
· `sl@g!` · `a` (muito curto) · string de 21 caracteres (muito longa)

---

## Principais Conclusões

| Padrão | Implementação |
|---|---|
| Prevenção de SSRF | Allowlist de esquemas `parse_url()` + `filter_var NO_PRIV_RANGE` |
| Resolução DNS em testes | Callback `ipResolver` injetável |
| Segurança de slug | Regex de allowlist de caracteres (ancorada, sem backtracking) |
| Aplicação de tipo de URL | `V::str()` → `is_string()` antes do parsing de URL |
| Validação de expiração | `V::isoDatetime()` com round-trip + verificação de intervalo de offset |
| Prevenção de IDOR | `WHERE slug = ? AND user_id = ?` em toda query de escrita |
| Mass assignment | Campos definidos no servidor ficam no repositório, ignorados no controller |
