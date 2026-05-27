# Entrega de Webhook de Saída

Webhooks de saída notificam sistemas de terceiros quando eventos ocorrem na sua aplicação. As principais preocupações de segurança são SSRF (enviar requisições para infraestrutura interna), vazamento de segredo e integridade de assinatura.

## Componentes principais

- **Registro de endpoints**: armazena a URL, filtro de evento e um segredo em hash por assinante.
- **Fila de entregas**: um registro por par (endpoint, evento), rastreando contagem de tentativas e status.
- **Assinador**: gera assinaturas HMAC-SHA256 que o receptor pode verificar.
- **Validador de URL**: bloqueia alvos SSRF antes de armazenar endpoints.

## Schema

```sql
CREATE TABLE webhook_endpoints (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    url         TEXT    NOT NULL,
    event_type  TEXT    NOT NULL,
    secret_hash TEXT    NOT NULL,       -- SHA-256 do segredo bruto; segredo bruto nunca armazenado
    max_retries INTEGER NOT NULL DEFAULT 3,
    active      INTEGER NOT NULL DEFAULT 1,
    created_at  TEXT    NOT NULL
);

CREATE TABLE webhook_deliveries (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    endpoint_id   INTEGER NOT NULL,
    event_type    TEXT    NOT NULL,
    payload       TEXT    NOT NULL,
    status        TEXT    NOT NULL DEFAULT 'pending',  -- pending | delivered | failed
    attempt_count INTEGER NOT NULL DEFAULT 0,
    last_status   INTEGER,                             -- último código de resposta HTTP
    last_error    TEXT,
    delivered_at  TEXT,
    created_at    TEXT    NOT NULL,
    updated_at    TEXT    NOT NULL
);
```

Apenas o hash SHA-256 do segredo é armazenado. O segredo bruto nunca é persistido — se o banco for comprometido, hashes não podem ser revertidos para falsificar assinaturas (SHA-256 sem HMAC não é reversível para um segredo aleatório de 32 bytes).

## Formato de assinatura

```
X-Webhook-Signature: sha256={hex}
X-Webhook-Timestamp: {unix_timestamp}
```

Conteúdo assinado: `{timestamp}.{body}` — vinculando a assinatura ao payload e a um ponto no tempo.

```php
public function sign(string $rawSecret, string $body, string $timestamp): string
{
    $payload = $timestamp . '.' . $body;
    $mac     = hash_hmac('sha256', $payload, $rawSecret);

    return 'sha256=' . $mac;
}
```

Incluir o timestamp no conteúdo assinado previne ataques de replay: um atacante que captura um webhook válido não pode reutilizá-lo mais tarde porque o timestamp seria antigo. Os receptores devem rejeitar assinaturas com mais de um limite (por exemplo, 5 minutos).

## Prevenção de SSRF

Valide cada URL de webhook antes de armazená-la. No mínimo, bloqueie:

```php
final class UrlValidator
{
    public function validate(string $url): ?string
    {
        // Bloquear injeção CRLF/null byte
        if (str_contains($url, "\n") || str_contains($url, "\r") || str_contains($url, "\0")) {
            return 'URL contains illegal control characters.';
        }

        // Apenas HTTPS
        if (strtolower(parse_url($url, PHP_URL_SCHEME) ?? '') !== 'https') {
            return 'Only HTTPS URLs are allowed.';
        }

        // Bloquear IPs privados/loopback e hostnames reservados
        // ...
    }
}
```

Faixas IPv4 privadas a bloquear: `127.0.0.0/8`, `10.0.0.0/8`, `172.16.0.0/12`, `192.168.0.0/16`, `169.254.0.0/16`, `0.0.0.0`.

Hostnames a bloquear: `localhost`, `*.local`, `*.internal`, `*.test`, `*.invalid`.

IPv6: `::1`, `fc00::/7` (ULA), `fe80::/10` (link-local).

**DNS rebinding**: validar a URL no momento do registro não é suficiente — o registro DNS pode mudar entre o registro e a entrega para apontar para um IP interno. Para produção, também valide o IP resolvido no momento da entrega antes de abrir a conexão TCP.

## Filtragem de resposta — nunca exponha segredos

O método `toArray()` em `WebhookEndpoint` deve omitir tanto `secret` quanto `secret_hash`:

```php
public function toArray(): array
{
    return [
        'id', 'url', 'event_type', 'max_retries', 'active', 'created_at',
        // secret_hash intencionalmente ausente
    ];
}
```

Isso se aplica a: GET /webhooks/{id}, listar endpoints e qualquer log de auditoria que registre metadados de endpoint.

## Lógica de retry

```php
public function markFailed(int $id, string $error, ?int $httpStatus, string $now, int $maxRetries): ?WebhookDelivery
{
    $newCount  = $delivery->attemptCount + 1;
    $newStatus = $newCount >= $maxRetries ? 'failed' : 'pending';

    $this->executor->execute(
        'UPDATE webhook_deliveries SET status = ?, attempt_count = ?, last_error = ?, updated_at = ? WHERE id = ?',
        [$newStatus, $newCount, $error, $now, $id],
    );
}
```

- `attempt_count < max_retries` → status permanece `pending` → worker busca novamente.
- `attempt_count >= max_retries` → status vira `failed` → sem mais retentativas.

Workers devem implementar backoff exponencial (por exemplo, `2^attempt_count` segundos) para evitar sobrecarregar um receptor com dificuldades.

## Desativação

Endpoints desativados (`active = 0`) são excluídos da query de fan-out no momento do despacho:

```sql
SELECT * FROM webhook_endpoints WHERE event_type = ? AND active = 1
```

Isso dá aos assinantes uma forma de pausar a entrega sem deletar o registro.

## Decisões de design

**Por que armazenar `secret_hash` em vez do segredo bruto?**
Se o banco for comprometido, o atacante não pode extrair segredos para falsificar assinaturas de webhook enviadas aos receptores. O segredo bruto é retornado uma vez na criação e deve ser armazenado com segurança pelo chamador.

**Por que incluir timestamp na assinatura?**
Assinaturas sem timestamps são reproduzíveis indefinidamente. Incluir `{timestamp}.{body}` no HMAC significa que um atacante que intercepta um webhook não pode reenviá-lo — os receptores podem rejeitar timestamps fora de uma janela de ±5 minutos.

**Por que validar URL no registro, não no despacho?**
Bloquear URLs inválidas no registro dá feedback imediato ao assinante e previne que dados ruins entrem na fila de entrega. Ataques de DNS rebinding requerem validação adicional no momento do despacho.
