# Como Fazer: Teste de Penetração ao Vivo em Container

Este guia documenta como executar um teste de penetração adversarial ao vivo em container contra uma aplicação NENE2 — começando pelo setup, passando por todas as 30 fases de ataque — e registra os resultados canônicos da sessão de teste v1.5.329 (2026-05-31, 150+ casos).

O teste adota uma **mentalidade de cracker**: assuma que o atacante tem acesso total ao código-fonte (white-box), leu toda a documentação pública e tentará toda classe de ataque conhecida antes de desistir.

---

## Pré-requisitos

- Docker Compose disponível (`docker compose version`)
- `curl`, `nc` (netcat), `openssl`, `python3` instalados no host
- Um container NENE2 em execução com credenciais de teste

---

## 1. Setup do Container

Inicie um alvo de teste isolado. Use uma porta dedicada (nunca a porta de produção) e injete credenciais de teste:

```bash
# PHP built-in server target — fastest to spin up, tests raw NENE2 behaviour
NENE2_MACHINE_API_KEY=pentest-key docker compose run -d --rm \
  -e NENE2_LOCAL_JWT_SECRET=pentest-jwt-secret-32chars-min!! \
  -e APP_ENV=local \
  -e APP_DEBUG=false \
  -p 8299:80 \
  app php -S 0.0.0.0:80 -t public_html/

# Apache target — tests full stack including Apache config hardening
NENE2_MACHINE_API_KEY=pentest-key docker compose up -d app
# Available on :8200 (see port registry in CLAUDE.md §8)
```

Verificação de smoke baseline:

```bash
curl -si http://localhost:8299/
# Expected: 200 OK, security headers present, no Server/X-Powered-By
```

Enumere a superfície de ataque a partir do OpenAPI:

```bash
curl -s http://localhost:8299/openapi.php | grep -E "^  /"
# → /, /health, /machine/health, /examples/protected,
#   /examples/notes, /examples/notes/{id}, /examples/tags, /examples/tags/{id}
```

Gere credenciais de teste dentro do container:

```bash
CID=$(docker ps --filter "publish=8299" --format "{{.ID}}")
VALID_JWT=$(docker exec $CID php -r "
  require 'vendor/autoload.php';
  \$v = new Nene2\Auth\LocalBearerTokenVerifier('pentest-jwt-secret-32chars-min!!');
  echo \$v->issue(['sub'=>'tester','exp'=>time()+86400]);
")
```

---

## 2. Fases de Ataque

### Fase 1 — Confusão de Algoritmo JWT

| ID | Ataque | Esperado | v1.5.329 |
|----|--------|----------|----------|
| J-01 | `alg:none` (assinatura vazia) | 401 | ✅ BLOCKED |
| J-02 | `alg:NONE` (maiúsculas) | 401 | ✅ BLOCKED |
| J-03 | `alg:None` (caixa mista) | 401 | ✅ BLOCKED |
| J-04 | `alg:hs256` (minúsculas) | 401 | ✅ BLOCKED |
| J-05 | `alg:RS256` (confusão de chave) | 401 | ✅ BLOCKED |
| J-06 | Sem campo `alg` | 401 | ✅ BLOCKED |
| J-07 | `kid: ../../etc/passwd` | 200 (sig válida) | ✅ SAFE — campos de header extras ignorados |
| J-08 | `jku: http://evil.com` | 200 (sig válida) | ✅ SAFE — sem fetch de JWK |

```bash
# J-01: alg:none
H=$(echo -n '{"typ":"JWT","alg":"none"}' | base64 -w0 | tr '+/' '-_' | tr -d '=')
P=$(echo -n '{"sub":"admin","exp":9999999999}' | base64 -w0 | tr '+/' '-_' | tr -d '=')
curl -si -H "Authorization: Bearer $H.$P." http://localhost:8299/examples/protected
# → 401  detail: "Token algorithm must be HS256."
```

### Fase 1b — Manipulação de Payload JWT

| ID | Ataque | Esperado | v1.5.329 |
|----|--------|----------|----------|
| J-09 | `exp: 0` (epoch 1970) | 401 expirado | ✅ BLOCKED |
| J-10 | `exp: null` | 401 deve ser numérico | ✅ BLOCKED |
| J-11 | `exp: "never"` | 401 deve ser numérico | ✅ BLOCKED |
| J-12 | `exp: 9999999999.9` (float) | 401 deve ser numérico | ✅ BLOCKED |
| J-13 | Payload é um array JSON | 401 deve ser numérico | ✅ BLOCKED |
| J-14 | Espaço duplo no valor Bearer | 401 | ✅ BLOCKED |
| J-15 | Sem esquema Bearer | 401 | ✅ BLOCKED |
| J-16 | Token de 4 segmentos (ponto extra) | 401 formato inválido | ✅ BLOCKED |
| J-17 | Apenas header + payload (sem sig) | 401 | ✅ BLOCKED |

> **Invariante-chave**: `exp` deve ser um inteiro presente — ausência ou tipo errado é rejeitado (corrigido em v1.5.329).

### Fase 2 — SQL Injection

Todos os repositórios usam queries parametrizadas com placeholder `?`. Nenhuma interpolação de string raw.

| ID | Ataque | Esperado | v1.5.329 |
|----|--------|----------|----------|
| S-01 | Clássico `' OR 1=1--` no title | 201 (armazenado como literal) | ✅ SAFE |
| S-02 | `UNION SELECT 1,2,3--` | 201 (armazenado como literal) | ✅ SAFE |
| S-03 | Boolean blind `AND 1=1--` | 201 (armazenado como literal) | ✅ SAFE |
| S-04 | Time-based `AND SLEEP(2)--` | 201 em <50ms | ✅ SAFE — SLEEP não executado |
| S-05 | SQLi em path param `/notes/1' OR '1'='1` | 200 (cast para int → 1) | ✅ SAFE |
| S-06 | Null byte `\0' OR '1'='1` | 201 (literal) | ✅ SAFE |
| S-07 | Segunda ordem: armazenar payload, depois ler | 200 (releitura literal) | ✅ SAFE |
| S-08 | SLEEP(5) em campo do corpo | 201 em <50ms | ✅ SAFE |
| S-10 | `limit=UNION SELECT...` na query string | 422 (validação) | ✅ SAFE |

```bash
# Verify parameterized queries: SLEEP is not executed
time curl -si -X POST -H "Content-Type: application/json" \
  -d '{"title":"x'\'' AND SLEEP(3)--","body":"x"}' \
  http://localhost:8299/examples/notes
# → 201 in < 100ms  (SLEEP never ran)
```

### Fase 3 — Path Traversal / LFI / PHP Wrappers

| ID | Ataque | Esperado | v1.5.329 |
|----|--------|----------|----------|
| P-01 | `../../etc/passwd` | 404 | ✅ BLOCKED |
| P-02 | Variantes URL-encoded `%2e%2e%2f` (5 formas) | 404 | ✅ BLOCKED |
| P-03 | Double-encoded `%252e%252e` | 404 | ✅ BLOCKED |
| P-04 | UTF-8 overlong `%c0%ae` | 404 | ✅ BLOCKED |
| P-05 | `php://input` / `php://filter` / `data://` | 404 | ✅ BLOCKED |
| P-06 | LFI via parâmetro `{id}` | 404 | ✅ BLOCKED |
| P-07 | Null byte `1%00.html` | 200 (cast para int → 1) | ✅ SAFE — registro do BD para id=1 retornado |
| P-08 | `.htaccess` no Apache | 403 | ✅ BLOCKED |
| P-08b | `.htaccess` no PHP built-in server | **200** | ⚠️ EXPOSED (ver VULN-01) |
| P-09 | `.git/HEAD` | 404 | ✅ BLOCKED |
| P-10 | Arquivos de backup (`.bak`, `.swp`, `~`, etc.) | 404 | ✅ BLOCKED |

### Fase 4 — Ataques de Protocolo HTTP

| ID | Ataque | Esperado | v1.5.329 |
|----|--------|----------|----------|
| H-01 | Request smuggling CL.TE | sem resposta (PHP built-in bloqueia) | ✅ |
| H-02 | Smuggling TE.CL | 405 (método incompatível na raiz) | ✅ |
| H-03 | TE.TE Transfer-Encoding ofuscado | sem resposta | ✅ |
| H-04 | Downgrade HTTP/1.0 | 200 (corpo correto) | ✅ |
| H-05 | Abuso de proxy via URI absoluta | 404 | ✅ |
| H-06 | Header folding HTTP | 500 (bug do PHP built-in) | ⚠️ VULN-02 |
| H-07 | HTTP pipelining | respostas intercaladas | ✅ SAFE |
| H-08 | 100 headers customizados simultâneos | 200 | ✅ SAFE |
| H-10 | Upgrade para WebSocket | 200 (upgrade ignorado) | ✅ SAFE |
| H-12 | Versão HTTP inválida (`HTTP/9.9`) | 200 (PHP built-in aceita) | ✅ SAFE |

### Fase 5 — Mass Assignment / IDOR / Lógica de Negócio

| ID | Ataque | Esperado | v1.5.329 |
|----|--------|----------|----------|
| B-01 | Mass assignment (`id`, `__proto__` no corpo) | 201 (campos extras ignorados) | ✅ SAFE |
| B-02 | IDOR: DELETE da note de outro usuário | 204 | ℹ️ Esperado (exemplos não têm propriedade) |
| B-04 | ID negativo / zero | 404 | ✅ SAFE |
| B-05 | ID com integer overflow | 404 | ✅ SAFE |
| B-06 | DELETE e depois re-acessar o mesmo ID | 404 | ✅ SAFE |
| B-07 | Condição de corrida em DELETE concorrente | todos 404 (idempotente) | ✅ SAFE |
| B-08 | Corpo no limite de 1MB | 413 | ✅ BLOCKED |

### Fase 6 — Bypass de API Key

| ID | Ataque | Esperado | v1.5.329 |
|----|--------|----------|----------|
| A-01 | Sem chave | 401 | ✅ BLOCKED |
| A-02 | Chave na query string (`?key=`, `?api_key=`) | 401 | ✅ BLOCKED |
| A-03 | Chave no corpo da requisição | 401 | ✅ BLOCKED |
| A-04 | Variações de caixa no nome do header | 200 (PSR-7 normaliza) | ✅ SAFE |
| A-05 | Espaços em branco iniciais/finais no valor | 200 (PSR-7 faz trim) | ✅ SAFE |
| A-06 | Barra dupla `//machine/health` | 401 sem chave, 200 com | ✅ SAFE |
| A-07 | `X-Original-URL` / `X-Rewrite-URL` | 200 (header ignorado) | ✅ SAFE |
| A-08 | Bypass via preflight OPTIONS | 405 | ✅ BLOCKED |
| A-09 | Método HEAD | 401 | ✅ BLOCKED |
| A-10 | Força bruta de senhas comuns | 401 todos | ✅ BLOCKED |
| A-11 | Path URL-encoded (`%6Dachine`) | 404 | ✅ BLOCKED |

```bash
# Timing attack: hash_equals used → constant-time comparison
time (for i in $(seq 1 10); do
  curl -so /dev/null -H "X-NENE2-API-Key: a" http://localhost:8299/machine/health
done)
time (for i in $(seq 1 10); do
  curl -so /dev/null -H "X-NENE2-API-Key: pentest-key" http://localhost:8299/machine/health
done)
# → timing difference < 5ms over 10 requests: SAFE
```

### Fase 7 — Injeção / XSS / SSTI / Execução de Código

| ID | Ataque | Esperado | v1.5.329 |
|----|--------|----------|----------|
| I-01 | XSS `<script>alert(1)</script>` armazenado | 201, retornado como string JSON | ✅ SAFE — codificação JSON neutraliza |
| I-02 | SSTI `{{7*7}}` / `${7*7}` | 201, armazenado literalmente | ✅ SAFE — sem template engine |
| I-03 | PHP `<?php system("id"); ?>` | 201, armazenado como literal | ✅ SAFE |
| I-04 | Log4Shell `${jndi:ldap://...}` | 200 (header ignorado) | ✅ SAFE — PHP, não Java |
| I-05 | JSON aninhado em 1000 níveis | 400 (limite de parse do PHP) | ✅ BLOCKED |
| I-06 | Caracteres de controle Unicode BiDi | 201 (armazenado) | ✅ SAFE — risco apenas de exibição |
| I-07 | Chaves JSON duplicadas | último valor vence (comportamento PHP) | ℹ️ INFO-01 |

> **Nota sobre XSS armazenado**: Payloads de XSS são armazenados e retornados verbatim nas respostas JSON. Como a API é apenas JSON (`Content-Type: application/json` + `X-Content-Type-Options: nosniff`), os navegadores não executarão o script. O risco só se materializa se outra aplicação renderizar esses dados em um contexto HTML sem escapar.

### Fase 8 — Desserialização / PHP Object Injection

| ID | Ataque | Esperado | v1.5.329 |
|----|--------|----------|----------|
| D-01 | Wrapper `phar://` em path param | 404 | ✅ BLOCKED |
| D-02 | Payload de serialização PHP `O:8:"stdClass":...` | 400 (corpo inválido) | ✅ BLOCKED |
| D-03 | Form URL-encoded com payload de serialização | 400 (Content-Type errado) | ✅ BLOCKED |

### Fase 9 — Injeção de Header / Response Splitting

| ID | Ataque | Esperado | v1.5.329 |
|----|--------|----------|----------|
| R-01 | Injeção de header Location via id da note criada | `/examples/notes/<int>` | ✅ SAFE — apenas int |
| R-02 | CRLF em WWW-Authenticate via erro de JWT | mensagem fixa sanitizada | ✅ SAFE |
| R-03 | Content-Type sniffing | `X-Content-Type-Options: nosniff` | ✅ SAFE |
| R-04 | Clickjacking | `X-Frame-Options: SAMEORIGIN` | ✅ SAFE |

### Fase 10 — Bypass de CORS / SOP

| ID | Ataque | Esperado | v1.5.329 |
|----|--------|----------|----------|
| C-01 | `Origin: null` (iframe sandboxed) | Vary: Origin, sem header ACAO | ✅ SAFE |
| C-02 | CRLF no header Origin | sanitizado pela camada curl/http | ✅ SAFE |
| C-03 | Cache poisoning via header Vary | `Vary: Origin` presente | ✅ SAFE |
| C-04 | Preflight com método injetado | método ignorado pelo PHP | ✅ SAFE |
| C-05 | `Access-Control-Allow-Origin: *` | header ausente (allowlist vazia) | ✅ SAFE |

### Fases 11-20 — Codificação / Protocolo / Timing

| ID | Ataque | Resultado |
|----|--------|-----------|
| E-01 | Emoji / Unicode alto em JSON | ✅ 201 (armazenado corretamente) |
| E-02 | Override BiDi RTL (risco de spoofing) | ✅ 201 (apenas exibição) |
| E-05 | SQLi de paginação via query params | ✅ 422 (validado como inteiro) |
| H-06b | Header Authorization com folding | ⚠️ 500 (bug do PHP built-in) |
| 20 | X-Request-Id de 129 caracteres rejeitado | ✅ Servidor gera novo ID aleatório |
| 21 | Log injection via X-Request-Id `%0a` | ✅ Rejeitado (caracteres inválidos) |
| 22 | Apache ServerTokens/ServerSignature | ✅ apenas `Server: Apache` |
| 23 | Escalada de privilégio via JWT sub=admin | ✅ Claims não usadas para authz |
| 26 | Replay de JWT (expirado há 2s) | ✅ 401 `Token has expired.` |
| 27 | Divulgação de stack trace em 500 | ✅ Apenas mensagem genérica |
| 28 | XSS em `instance` do Problem Details | ✅ URL-encoded (seguro) |
| 29 | SSRF via endpoint de health check | ✅ Nenhuma URL aceita |
| 15 | Oráculo de timing de API key | ✅ `hash_equals` — diff < 5ms |

---

## 3. Descobertas

### VULN-01 — `.htaccess` legível a partir do PHP built-in server ⚠️ MEDIUM

**Gatilho**: `curl http://localhost:8299/.htaccess`  
**Resposta**: 200 + conteúdo completo do arquivo (regras de rewrite do Apache)  
**Causa raiz**: O servidor built-in do PHP (`php -S`) não aplica restrições de acesso a `.htaccess` — ele trata `.htaccess` como um arquivo estático.  
**Impacto**: Revela as regras de URL rewrite. O conteúdo não é secreto (sem senhas/tokens), mas confirma o padrão de rewrite-para-index-php.  
**Mitigação**: Use o container Apache (`docker compose up -d app`) em vez de `php -S` para testes sensíveis à segurança. O Apache retorna corretamente 403.

```bash
# Apache (correct): 403 Forbidden
curl -si http://localhost:8200/.htaccess | head -1

# PHP built-in server (exposed): 200 OK
curl -si http://localhost:8299/.htaccess | head -1
```

### VULN-02 — Header folding HTTP derruba o PHP built-in server ⚠️ LOW

**Gatilho**:
```
GET / HTTP/1.1\r\nHost: localhost\r\nX-NENE2-API-Key:\r\n <key>\r\n\r\n
```
**Resposta**: `HTTP/1.0 500 Internal Server Error` (corpo vazio)  
**Causa raiz**: O servidor HTTP built-in do PHP não suporta header folding RFC 7230 (depreciado, mas ainda válido em HTTP/1.1). O código do framework NENE2 não está envolvido.  
**Impacto**: Apenas em desenvolvimento (PHP built-in server). O Apache trata headers com folding corretamente.

### INFO-01 — Chaves JSON duplicadas: último valor vence

`{"title":"first","title":"INJECTED"}` → `title = "INJECTED"`  
Comportamento padrão do `json_decode` do PHP. A validação se aplica ao valor final (último), então não há caminho de bypass de validação. Anotado por consciência.

---

## 4. Invariantes de Segurança Verificadas

Estas garantias se mantiveram em todos os 150+ casos de teste:

| Invariante | Verificação |
|------------|-------------|
| Todas as queries SQL parametrizadas | SLEEP não executado; payloads de injeção armazenados como literais |
| JWT deve ser HS256 + sig válida + exp inteiro | Todas as 17 variantes de ataque JWT bloqueadas |
| API key verificada com `hash_equals` | Diferença de timing < 5ms em 10 iterações |
| Overflow de `Content-Length` tratado | 413 com headers corretos, sem vazamento de warning do PHP |
| Security headers em toda resposta | CSP / XCTO / XFO / Referrer-Policy / Permissions-Policy confirmados |
| `Server:` / `X-Powered-By:` removidos | Nenhum dos headers presente nas respostas do Apache |
| Stack traces nunca no corpo do 500 | Apenas o genérico `"The server encountered an unexpected condition."` |
| Path traversal bloqueado | Todas as 15 variantes de codificação retornam 404 |
| Arquivos `.env` / `.git` / backup | Todos 404 no document root |
| CORS padrão: sem origens permitidas | `Access-Control-Allow-Origin` ausente para origens arbitrárias |

---

## 5. Executando a Suíte de Testes

Repetição mínima viável das verificações-chave (< 5 minutos):

```bash
TARGET=http://localhost:8299
APIKEY=pentest-key
SECRET=pentest-jwt-secret-32chars-min!!
CID=$(docker ps --filter "publish=8299" --format "{{.ID}}")

# 1. JWT alg:none
H=$(echo -n '{"typ":"JWT","alg":"none"}' | base64 -w0 | tr '+/' '-_' | tr -d '=')
P=$(echo -n '{"sub":"admin","exp":9999999999}' | base64 -w0 | tr '+/' '-_' | tr -d '=')
curl -si -H "Authorization: Bearer $H.$P." $TARGET/examples/protected | grep "HTTP/"
# expected: 401

# 2. SQL injection time-based
time curl -so /dev/null -X POST -H "Content-Type: application/json" \
  -d '{"title":"x'\'' AND SLEEP(3)--","body":"x"}' $TARGET/examples/notes
# expected: < 500ms total

# 3. Path traversal
curl -si "$TARGET/%2e%2e/%2e%2e/etc/passwd" | grep "HTTP/"
# expected: 404

# 4. Content-Length overflow
curl -si -X POST -H "Content-Length: 9999999999999" $TARGET/ | head -3
# expected: 413 Request Entity Too Large (not 200 + PHP warning)

# 5. API key timing
time (for i in $(seq 1 10); do
  curl -so /dev/null -H "X-NENE2-API-Key: a" $TARGET/machine/health
done)
# expected: similar timing to correct key (hash_equals)

# 6. .htaccess exposure (Apache only)
curl -si http://localhost:8200/.htaccess | grep "HTTP/"
# expected: 403

# 7. JWT exp required
NEXP=$(docker exec $CID php -r "
  require 'vendor/autoload.php';
  \$v = new Nene2\Auth\LocalBearerTokenVerifier('$SECRET');
  echo \$v->issue(['sub'=>'user1']);
")
curl -si -H "Authorization: Bearer $NEXP" $TARGET/examples/protected | grep "detail"
# expected: "Token must contain a numeric exp claim."
```

---

## Relacionados

- [Pagination Boundary & Limit Injection](pagination-boundary-attack.md)
- [Webhook Signature Verification](webhook-signature-verification.md)
- [Add JWT Authentication](add-jwt-authentication.md)
- ADR 0011: Security Review Policy
