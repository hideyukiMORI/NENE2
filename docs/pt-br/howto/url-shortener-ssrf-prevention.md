# Como fazer: Encurtador de URL com Prevenção de SSRF

> **Referência FT**: FT337 (`NENE2-FT/shortlog`) — Encurtador de URL com bloqueio de SSRF (IPs privados, loopback, link-local, esquemas perigosos), validação de slug, prevenção de mass assignment, validação de data ISO 8601, parsing de limit seguro contra ReDoS, 50+ testes PASSAM.

Este guia mostra como construir um encurtador de URL que aceita apenas URLs públicas seguras, valida slugs, previne mass assignment e protege contra Server-Side Request Forgery (SSRF).

## Schema

```sql
CREATE TABLE links (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id      INTEGER NOT NULL,
    slug         TEXT    NOT NULL UNIQUE,
    original_url TEXT    NOT NULL,
    expires_at   TEXT,               -- ISO 8601, nullable
    click_count  INTEGER NOT NULL DEFAULT 0,
    created_at   TEXT    NOT NULL
);
```

## Endpoints

| Método | Caminho | Descrição |
|--------|---------|-----------|
| `POST` | `/links` | Criar link curto |
| `GET`  | `/links` | Listar próprios links |
| `GET`  | `/links/{slug}` | Obter link por slug |
| `DELETE` | `/links/{slug}` | Deletar próprio link |

## Criar Link Curto

```php
POST /links
X-User-Id: 1
{
  "original_url": "https://example.com/very/long/path",
  "slug": "my-link",
  "expires_at": "2030-12-31T23:59:59+09:00"
}
→ 201
{
  "id": 1,
  "user_id": 1,
  "slug": "my-link",
  "original_url": "https://example.com/very/long/path",
  "expires_at": "2030-12-31T23:59:59+09:00",
  "click_count": 0,
  "created_at": "..."
}
```

`slug` é opcional — gerado automaticamente (`[a-z0-9_-]+`) se omitido.

### Auth ausente

```php
POST /links  (sem cabeçalho X-User-Id)
→ 401
```

### Slug duplicado

```php
POST /links  {"slug": "my-link"}  // já existe
→ 409
```

## Validação de Slug

```
Válido: letras minúsculas, dígitos, hífens, sublinhados
Comprimento: 3–20 caracteres

Exemplos válidos: "abc", "my-link", "link123", "test-link-01"
```

```php
POST /links  {"slug": "ab"}          → 422  // muito curto (min 3)
POST /links  {"slug": "a".repeat(21)} → 422  // muito longo (max 20)
POST /links  {"slug": "MySlug"}       → 422  // maiúsculas não permitidas
POST /links  {"slug": "sl@g!"}        → 422  // caracteres especiais
POST /links  {"slug": "my slug"}      → 422  // espaço não permitido
POST /links  {"slug": 42}             → 422  // tipo deve ser string (VULN-B)
```

## Validação de URL

```php
POST /links  {"original_url": ""}              → 422  // vazia
POST /links  {}                                → 422  // ausente
POST /links  {"original_url": 42}              → 422  // não é string (VULN-B)
POST /links  {"original_url": true}            → 422  // bool (VULN-B)
POST /links  {"original_url": null}            → 422  // null (VULN-B)
POST /links  {"original_url": "https://..."+"x".repeat(2030)}  → 422  // muito longa
```

## Prevenção de SSRF

Bloquear URLs que fariam o servidor chamar infraestrutura interna:

### Esquemas Bloqueados

```php
POST /links  {"original_url": "javascript:alert(1)"}  → 422
POST /links  {"original_url": "file:///etc/passwd"}   → 422
POST /links  {"original_url": "ftp://example.com/"}   → 422
```

Apenas `http://` e `https://` são permitidos.

### Faixas de IP Bloqueadas

```php
// Loopback
POST /links  {"original_url": "http://127.0.0.1/admin"}     → 422
POST /links  {"original_url": "http://localhost/secret"}     → 422
POST /links  {"original_url": "http://internal.localhost/"}  → 422  // *.localhost

// Faixas privadas RFC 1918
POST /links  {"original_url": "http://10.0.0.1/metadata"}    → 422
POST /links  {"original_url": "http://192.168.1.1/router"}   → 422
POST /links  {"original_url": "http://172.16.0.1/internal"}  → 422

// Link-local (metadados AWS, etc.)
POST /links  {"original_url": "http://169.254.169.254/latest/meta-data/"}  → 422

// IP público — aceito
POST /links  {"original_url": "https://8.8.8.8/"}            → 201  ✅
```

### Prevenção de DNS Rebinding

Hostnames que resolvem para IPs privados também são bloqueados:

```php
// "private.internal" resolve para 10.0.0.1 → bloqueado
POST /links  {"original_url": "http://private.internal/data"}  → 422

// "public.example.com" resolve para 93.184.216.34 → permitido
POST /links  {"original_url": "https://public.example.com/page"}  → 201  ✅
```

### Implementação

```php
private const BLOCKED_RANGES = [
    '127.',          // loopback
    '10.',           // RFC 1918
    '172.16.', '172.17.', '172.18.', '172.19.',
    '172.20.', '172.21.', '172.22.', '172.23.',
    '172.24.', '172.25.', '172.26.', '172.27.',
    '172.28.', '172.29.', '172.30.', '172.31.',  // RFC 1918
    '192.168.',      // RFC 1918
    '169.254.',      // link-local
];

private const ALLOWED_SCHEMES = ['http', 'https'];

public function validate(string $url): bool
{
    $parsed = parse_url($url);
    if (!$parsed || !in_array($parsed['scheme'] ?? '', self::ALLOWED_SCHEMES, true)) {
        return false;
    }

    $host = $parsed['host'] ?? '';

    // Bloquear *.localhost
    if ($host === 'localhost' || str_ends_with($host, '.localhost')) {
        return false;
    }

    // Resolver hostname para IP
    $ip = ($this->dnsResolver)($host);

    foreach (self::BLOCKED_RANGES as $prefix) {
        if (str_starts_with($ip, $prefix)) {
            return false;
        }
    }

    return true;
}
```

## Prevenção de Mass Assignment

```php
// Atacante tenta definir click_count ou created_at
POST /links
{
  "original_url": "https://example.com",
  "slug": "attack",
  "click_count": 999999,
  "created_at": "2000-01-01T00:00:00+00:00"
}
→ 201  {"click_count": 0, "created_at": "2026-..."}  // campos ignorados
```

Apenas `original_url`, `slug`, `expires_at` do corpo da requisição entram na whitelist. Nunca leia `click_count`, `created_at` ou `user_id` do corpo.

## Validação de Data ISO 8601

```php
// Datas de calendário inválidas
POST /links  {"expires_at": "2024-02-30T00:00:00+00:00"}  → 422  // 30 de fevereiro
POST /links  {"expires_at": "2024-13-01T00:00:00+00:00"}  → 422  // mês 13
POST /links  {"expires_at": "2030-06-01T00:00:00+25:00"}  → 422  // offset +25:00

// Válido
POST /links  {"expires_at": "2030-06-01T00:00:00+09:00"}  → 201  ✅
```

Padrão de validação: parsear com `DateTimeImmutable::createFromFormat()` e verificar o round-trip:

```php
$dt = DateTimeImmutable::createFromFormat(DATE_RFC3339, $value);
if ($dt === false) return false;
// Verificação de round-trip captura "2024-02-30" que o PHP normaliza para "2024-03-01"
return $dt->format(DATE_RFC3339) === $value;
```

## Validação de Limit Segura contra ReDoS

```php
// ctype_digit para O(n) — imune a ReDoS
GET /links?limit=10       → 200  ✅
GET /links?limit=999999   → 422  // excede MAX_LIMIT
GET /links?limit=9...9 (19 dígitos)  → 422  // proteção contra overflow
GET /links?limit=111...1x (51 chars com x)  → 422, <100ms  // payload ReDoS
```

## Prevenção de IDOR

```php
// Usuário 2 tenta deletar link do usuário 1
DELETE /links/user1-link
X-User-Id: 2
→ 404  // NÃO 403 — previne enumeração
```

O link existe mas a busca tem escopo com `WHERE slug = ? AND user_id = ?`. Uma divergência retorna 404 como se o link não existisse.

---

## O que NÃO fazer

| Anti-padrão | Risco |
|---|---|
| Permitir `http://localhost` ou `http://127.0.0.1` | Servidor busca seu próprio endpoint admin via link curto |
| Pular verificação de resolução DNS | Atacante registra `evil.example.com` → registro A `10.0.0.1` para contornar verificação de IP literal |
| Permitir esquema `javascript:` | XSS via shortlink em qualquer browser que abre o redirect |
| Permitir esquema `file://` | Servidor lê `/etc/passwd` se o encurtador busca a URL na criação |
| Aceitar `click_count` do corpo da requisição | Atacante infla métricas de clique |
| Sem restrição de comprimento/charset no slug | `slug = "' OR 1=1--"` passa na validação, chega ao SQL |
| Usar regex `/^\d+$/` para validação de limit | ReDoS em payloads longos com dígitos mistos |
| Retornar `created_at` do corpo da requisição | Falsificação de tempo corrompe trilha de auditoria |
