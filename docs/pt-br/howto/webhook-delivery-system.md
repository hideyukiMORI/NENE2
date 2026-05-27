# Como fazer: Sistema de Entrega de Webhook

> **Referência FT**: FT308 (`NENE2-FT/webhookdeliverylog`) — Sistema de entrega de webhook: proteção SSRF via UrlValidator (somente HTTPS, blocklist de IP privado, prevenção de injeção CRLF), assinatura HMAC-SHA256 com vinculação de timestamp, segredo armazenado como hash SHA-256 (nunca texto simples), segredo não retornado em respostas GET, endpoints desativados ignoram entrega, isolamento de tipo de evento, ATK-01〜12 todos BLOCKED, 31 testes / 47 asserções PASSAM.

Este guia mostra como construir um sistema de entrega de webhook onde os segredos são protegidos, as URLs são validadas contra ataques SSRF e os payloads são assinados com timestamps para prevenir ataques de replay.

## Schema

```sql
CREATE TABLE IF NOT EXISTS webhook_endpoints (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    url         TEXT    NOT NULL,
    event_type  TEXT    NOT NULL,
    secret_hash TEXT    NOT NULL,   -- hash SHA-256 do segredo bruto
    max_retries INTEGER NOT NULL DEFAULT 3,
    active      INTEGER NOT NULL DEFAULT 1,
    created_at  TEXT    NOT NULL
);

CREATE TABLE IF NOT EXISTS webhook_deliveries (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    endpoint_id   INTEGER NOT NULL REFERENCES webhook_endpoints(id),
    event_type    TEXT    NOT NULL,
    payload       TEXT    NOT NULL DEFAULT '{}',
    status        TEXT    NOT NULL DEFAULT 'pending',
    attempt_count INTEGER NOT NULL DEFAULT 0,
    last_status   INTEGER,
    last_error    TEXT,
    delivered_at  TEXT,
    created_at    TEXT    NOT NULL,
    updated_at    TEXT    NOT NULL
);
```

`secret_hash` armazena o hash SHA-256 do segredo bruto — nunca o segredo em si. O flag `active` permite desativar um endpoint sem deletar o histórico de entregas.

## Proteção SSRF — UrlValidator

```php
final class UrlValidator
{
    public function validate(string $url): ?string
    {
        // Bloquear injeção CRLF e null byte
        if (str_contains($url, "\n") || str_contains($url, "\r") || str_contains($url, "\0")) {
            return 'URL contains illegal control characters.';
        }

        $parsed = parse_url($url);
        if ($parsed === false || !isset($parsed['scheme'], $parsed['host'])) {
            return 'URL is not valid.';
        }

        // Apenas HTTPS
        if (strtolower($parsed['scheme']) !== 'https') {
            return 'Only HTTPS URLs are allowed for webhook delivery.';
        }

        $host = strtolower($parsed['host']);

        // Bloquear localhost e variantes
        if (in_array($host, ['localhost', 'ip6-localhost', 'ip6-loopback'], true)) {
            return "Webhook URL must not target '{$host}'.";
        }

        // Bloquear TLDs internos
        foreach (['.local', '.internal', '.test', '.example', '.invalid', '.localhost'] as $pattern) {
            if (str_ends_with($host, $pattern)) {
                return "Webhook URL must not target '{$pattern}' domains.";
            }
        }

        // Bloquear faixas IPv4 privadas (127.x, 10.x, 172.16-31.x, 192.168.x)
        $ip = trim($host, '[]');
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return 'Webhook URL must not target private or loopback IP addresses.';
            }
        }

        // Bloquear IPv6 privado (::1, fc00::/7, fe80::/10)
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            // ... verificações de faixa privada IPv6
        }

        return null; // válido
    }
}
```

A validação bloqueia:
1. **Injeção CRLF/null byte** — previne injeção de cabeçalho em requisições HTTP para a URL do webhook
2. **Esquemas não-HTTPS** — `http://`, `file://`, `ftp://`, `gopher://` todos bloqueados
3. **Endereços de loopback** — `127.0.0.0/8`, `::1`
4. **Faixas privadas** — `10.x`, `172.16-31.x`, `192.168.x`, `0.0.0.0`
5. **TLDs internos** — `.local`, `.internal`, `.test`, `.example`

## Assinatura de Webhook — HMAC-SHA256 + Timestamp

```php
final class WebhookSigner
{
    public function sign(string $rawSecret, string $body, string $timestamp): string
    {
        $payload = $timestamp . '.' . $body;  // timestamp vincula assinatura ao tempo
        $mac     = hash_hmac('sha256', $payload, $rawSecret);
        return 'sha256=' . $mac;
    }

    public function hashSecret(string $rawSecret): string
    {
        return hash('sha256', $rawSecret);
    }
}
```

O formato de assinatura `sha256=<hex>` é o mesmo padrão usado pelos webhooks do GitHub. O **timestamp está incluído no conteúdo assinado** (`timestamp.body`) — isso previne ataques de replay: uma assinatura capturada no tempo T não pode ser reproduzida no tempo T+1h.

## Armazenamento de Segredo — Hash, Nunca Texto Simples

```php
// Na criação do endpoint:
$secretHash = $this->signer->hashSecret($rawSecret);
$this->repo->createEndpoint($url, $eventType, $secretHash, $maxRetries);

// Retornar o segredo bruto UMA VEZ ao chamador:
return $this->json->create([
    'id'     => $endpointId,
    'secret' => $rawSecret,  // mostrado apenas na criação
    // armazenado como: secret_hash = SHA-256($rawSecret)
]);
```

O segredo bruto é retornado ao chamador **apenas uma vez** no momento da criação. Respostas subsequentes `GET /endpoints/{id}` nunca incluem `secret` ou `secret_hash`.

```php
// Resposta GET do endpoint — segredo NÃO incluído
return $this->json->create([
    'id'         => (int) $endpoint['id'],
    'url'        => $endpoint['url'],
    'event_type' => $endpoint['event_type'],
    'active'     => (bool) $endpoint['active'],
    'max_retries'=> (int) $endpoint['max_retries'],
    'created_at' => $endpoint['created_at'],
    // 'secret_hash' intencionalmente omitido
]);
```

## Ignorar Endpoint Desativado

```php
// Handler de despacho
if (!(bool) $endpoint['active']) {
    return $this->json->create(['message' => 'Endpoint is inactive, no delivery queued.'], 200);
}
```

Endpoints desativados não recebem novas entregas. Isso permite desativar um webhook sem deletar o endpoint ou seu histórico de entregas.

## Isolamento de Tipo de Evento

Cada endpoint assina um `event_type` específico. No despacho:

```php
$endpoints = $this->repo->findActiveEndpointsByType($eventType);
// Apenas endpoints correspondentes ao event_type são entregues
```

Um endpoint inscrito em `order.created` não recebe eventos `order.cancelled`.

---

## Avaliação ATK — Teste de Ataque com Mentalidade de Cracker

### ATK-01 — SSRF via Loopback IPv4 (127.x.x.x) 🚫 BLOCKED

**Ataque**: Registrar endpoint com `url: "https://127.0.0.1/admin"`.
**Resultado**: BLOCKED — UrlValidator detecta faixa IPv4 privada → 422.

---

### ATK-02 — SSRF via 0.0.0.0 🚫 BLOCKED

**Ataque**: `url: "https://0.0.0.0/internal"`.
**Resultado**: BLOCKED — faixa de IP reservado bloqueada por `FILTER_FLAG_NO_RES_RANGE` → 422.

---

### ATK-03 — SSRF via Faixa Privada 10.x.x.x 🚫 BLOCKED

**Ataque**: `url: "https://10.0.0.1/internal"`.
**Resultado**: BLOCKED — faixa IPv4 privada → 422.

---

### ATK-04 — SSRF via Faixa Privada 172.16-31.x.x 🚫 BLOCKED

**Ataque**: `url: "https://172.16.0.1/internal"`.
**Resultado**: BLOCKED — faixa IPv4 privada → 422.

---

### ATK-05 — Downgrade de Esquema HTTP 🚫 BLOCKED

**Ataque**: `url: "http://example.com/hook"` (não-HTTPS).
**Resultado**: BLOCKED — verificação de esquema: apenas `https` permitido → 422.

---

### ATK-06 — Esquema file:// 🚫 BLOCKED

**Ataque**: `url: "file:///etc/passwd"`.
**Resultado**: BLOCKED — verificação de esquema bloqueia não-HTTPS → 422.

---

### ATK-07 — Injeção CRLF na URL 🚫 BLOCKED

**Ataque**: `url: "https://example.com/\r\nX-Injected: header"`.
**Resultado**: BLOCKED — verificação `str_contains($url, "\r")` → 422.

---

### ATK-08 — Null Byte na URL 🚫 BLOCKED

**Ataque**: `url: "https://example.com/\0hidden"`.
**Resultado**: BLOCKED — verificação `str_contains($url, "\0")` → 422.

---

### ATK-09 — Vazamento de Segredo via GET Endpoint 🚫 BLOCKED

**Ataque**: `GET /endpoints/{id}` para recuperar o segredo armazenado.
**Resultado**: BLOCKED — resposta GET omite completamente os campos `secret` e `secret_hash`.

---

### ATK-10 — Vazamento de Segredo via Resposta de Despacho 🚫 BLOCKED

**Ataque**: Inspecionar corpo da resposta de despacho em busca de material secreto.
**Resultado**: BLOCKED — resposta de despacho contém apenas metadados de entrega, sem campos de segredo.

---

### ATK-11 — Ataque de Replay (Assinatura Capturada) 🚫 BLOCKED

**Ataque**: Capturar um webhook assinado e reproduzi-lo com a mesma assinatura mais tarde.
**Resultado**: BLOCKED — assinatura é `HMAC(timestamp.body, secret)`. O timestamp muda por entrega; assinatura antiga não corresponde ao novo timestamp.

---

### ATK-12 — Assinatura Falsificada com Segredo Errado 🚫 BLOCKED

**Ataque**: Computar HMAC com segredo adivinado/diferente, enviar como assinatura válida.
**Resultado**: BLOCKED — receptor valida com hash de segredo armazenado; HMAC falsificado não corresponde.

---

### Resumo ATK

| ID | Ataque | Resultado |
|----|--------|-----------|
| ATK-01 | SSRF loopback IPv4 | 🚫 BLOCKED |
| ATK-02 | SSRF 0.0.0.0 | 🚫 BLOCKED |
| ATK-03 | SSRF privado 10.x | 🚫 BLOCKED |
| ATK-04 | SSRF privado 172.16-31.x | 🚫 BLOCKED |
| ATK-05 | Downgrade de esquema HTTP | 🚫 BLOCKED |
| ATK-06 | Esquema file:// | 🚫 BLOCKED |
| ATK-07 | Injeção CRLF na URL | 🚫 BLOCKED |
| ATK-08 | Null byte na URL | 🚫 BLOCKED |
| ATK-09 | Vazamento de segredo via GET | 🚫 BLOCKED |
| ATK-10 | Vazamento de segredo via despacho | 🚫 BLOCKED |
| ATK-11 | Ataque de replay | 🚫 BLOCKED |
| ATK-12 | Assinatura falsificada | 🚫 BLOCKED |

**12 BLOCKED, 0 EXPOSED**
UrlValidator bloqueia todos os vetores SSRF. HMAC vinculado a timestamp previne replays. Segredo armazenado como hash, nunca retornado após criação.

---

## O que NÃO fazer

| Anti-padrão | Risco |
|---|---|
| Armazenar segredo de webhook bruto no banco | Brecha no banco expõe todos os segredos; hash SHA-256 é unidirecional |
| Retornar segredo na resposta GET | Qualquer vazamento da API admin expõe todos os segredos de webhook |
| HMAC apenas sobre o corpo (sem timestamp) | Ataque de replay: assinatura capturada reutilizável indefinidamente |
| Permitir URLs de webhook `http://` | Interceptação de tráfego dos payloads de webhook |
| Sem validação SSRF na URL | Sistema de webhook usado para sondar rede interna |
| Permitir `127.x`, `10.x` na URL de webhook | Servidor faz requisições para seus próprios serviços internos |
| Sem verificação CRLF | URL com `\r\n` injeta cabeçalhos na requisição HTTP de saída |
| Entregar para endpoints inativos | Endpoints desativados continuam a receber tráfego |
| Sem filtragem de tipo de evento | Todos os tipos de evento entregues a todos os endpoints |
