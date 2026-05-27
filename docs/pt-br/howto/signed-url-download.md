# Como Fazer: URL Assinada para Downloads Seguros

> **Referência FT**: FT338 (`NENE2-FT/signedlog`) — Geração de URL assinada com HMAC-SHA256, TTL, detecção de adulteração (401), expiração (410 Gone), tokens vinculados a recursos e rejeição de secret errado, 16 testes / 40+ assertivas PASS.

Este guia mostra como gerar URLs assinadas com tempo limitado que permitem o download não autenticado de arquivos privados — sem expor credenciais de longa duração.

## Schema

```sql
CREATE TABLE files (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT    NOT NULL,
    owner_id   INTEGER NOT NULL,
    mime_type  TEXT    NOT NULL DEFAULT 'application/octet-stream',
    created_at TEXT    NOT NULL
);
```

## Endpoints

| Método | Caminho | Descrição |
|--------|---------|-----------|
| `POST` | `/files` | Registrar um arquivo |
| `POST` | `/files/{id}/sign` | Gerar uma URL de download assinada |
| `GET`  | `/download?token=...` | Download usando token assinado |

## Registrar Arquivo

```php
POST /files
{"name": "report.pdf", "owner_id": 1}
→ 201
{
  "id": 1,
  "name": "report.pdf",
  "owner_id": 1,
  "mime_type": "application/octet-stream",
  "created_at": "..."
}

// MIME personalizado
POST /files  {"name": "image.png", "owner_id": 2, "mime_type": "image/png"}
→ 201  {"mime_type": "image/png", ...}

// Validação
POST /files  {"owner_id": 1}     → 422  // name obrigatório
POST /files  {"name": "f.pdf"}   → 422  // owner_id obrigatório
```

## Gerar URL Assinada

```php
POST /files/1/sign
{"ttl_seconds": 300}
→ 200
{
  "token": "1|2026-05-27 09:05:00|a3f9e2...",
  "expires_at": "2026-05-27T09:05:00Z",
  "url": "/download?token=1%7C2026-05-27+09%3A05%3A00%7Ca3f9e2...",
  "ttl_seconds": 300
}

// TTL padrão = 3600 (1 hora) se omitido
POST /files/1/sign  {}
→ 200  {"ttl_seconds": 3600}

// Arquivo desconhecido
POST /files/999/sign  {"ttl_seconds": 60}
→ 404
```

## Download com Token

```php
GET /download?token=1|2026-05-27+09:05:00|a3f9e2...
→ 200  {"id": 1, "name": "report.pdf", "mime_type": "application/octet-stream"}

// Token ausente
GET /download
→ 401

// Token adulterado (últimos 4 chars alterados)
GET /download?token=1|2026-05-27+09:05:00|XXXX
→ 401

// Token expirado (expires_at no passado)
GET /download?token=1|2020-01-01+00:00:00|...hmac_válido...
→ 410 Gone

// Lixo aleatório
GET /download?token=lixo-completamente-invalido
→ 401
```

**410 Gone** (não 401) para tokens expirados: a URL existia e era válida — ela simplesmente expirou. Isso permite que clientes distingam "nunca foi válido" de "já foi válido, agora está obsoleto."

## Formato do Token — HMAC-SHA256

```
token = "{file_id}|{expires_at}|{hmac}"

hmac = HMAC-SHA256(key=server_secret, message="{file_id}|{expires_at}")
```

```php
class HmacSigner
{
    public function __construct(private readonly string $secret)
    {
    }

    public function sign(int $fileId, string $expiresAt): string
    {
        $payload = "{$fileId}|{$expiresAt}";
        $hmac    = hash_hmac('sha256', $payload, $this->secret);
        return "{$payload}|{$hmac}";
    }

    public function verify(string $token, string $now): ?int
    {
        $parts = explode('|', $token, 3);
        if (count($parts) !== 3) {
            return null;
        }

        [$fileIdStr, $expiresAt, $receivedHmac] = $parts;
        $fileId  = (int) $fileIdStr;
        $payload = "{$fileId}|{$expiresAt}";

        // Comparação em tempo constante
        $expected = hash_hmac('sha256', $payload, $this->secret);
        if (!hash_equals($expected, $receivedHmac)) {
            return null;  // adulterado ou secret errado
        }

        // Verificar expiração APÓS verificar o HMAC
        if ($expiresAt < $now) {
            return -1;  // expirado — o chamador retorna 410
        }

        return $fileId;
    }
}
```

**Ordem crítica**: sempre verifique o HMAC antes de verificar a expiração. Verificar a expiração primeiro com um token inválido permite que atacantes sondem o comportamento de expiração.

### Vinculação ao Recurso

Cada token codifica o `file_id`. Tokens para arquivos diferentes produzem digests HMAC diferentes:

```php
$token1 = $signer->sign(1, $future);
$token2 = $signer->sign(2, $future);
// $token1 !== $token2 — não é possível reutilizar token do arquivo 1 para acessar o arquivo 2
```

### Secret Errado

Um token assinado com um secret diferente retorna null em `verify()`:

```php
$otherSigner = new HmacSigner('different-secret');
$token = $otherSigner->sign(1, $future);
$signer->verify($token, $now);  // null — HMAC não confere
```

---

## O Que NÃO Fazer

| Antipadrão | Risco |
|---|---|
| Usar `===` em vez de `hash_equals()` para comparação de HMAC | Ataque de timing vaza o HMAC byte a byte |
| Verificar expiração antes de verificar o HMAC | Atacante sonda expiração em tokens forjados para descobrir o relógio do servidor |
| Incluir apenas `user_id` no payload do token, não `file_id` | Token do usuário 1 arquivo 1 pode ser reutilizado para acessar usuário 1 arquivo 2 |
| Usar `md5()` ou `sha1()` em vez de HMAC-SHA256 | Hash com chave é obrigatório; hash sem chave é trivialmente forjável |
| Retornar 401 para tokens expirados | 410 informa ao cliente "token era real mas está obsoleto"; permite fluxo adequado de re-assinatura |
| Registrar o valor do token em logs de acesso | Token concede acesso — trate como senha; mascare ou omita nos logs |
| Usar um secret fraco ou previsível | A chave deve ter pelo menos 32 bytes aleatórios; nunca derive de timestamp ou hostname |
