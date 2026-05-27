# Como Fazer: API de Metadados de Upload de Arquivo (VULN-A~L)

Este guia demonstra gerenciamento seguro de metadados de upload de arquivo cobrindo VULN-A a VULN-L.

## Visão Geral do Padrão

Os arquivos não são armazenados por esta API — apenas seus metadados (nome do arquivo, tipo MIME, tamanho) são registrados. A transferência real do arquivo é tratada separadamente (ex.: direto para S3). Este é um padrão comum para rastrear histórico de uploads e aplicar restrições.

## Schema

```sql
CREATE TABLE IF NOT EXISTS uploads (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id     INTEGER NOT NULL,
    filename    TEXT    NOT NULL,
    mime_type   TEXT    NOT NULL,
    size_bytes  INTEGER NOT NULL,
    is_public   INTEGER NOT NULL DEFAULT 0,
    created_at  TEXT    NOT NULL
);
```

## VULN-A: SQL Injection

Todas as queries usam prepared statements do PDO. Nomes de arquivo e tipos MIME enviados por usuários nunca são interpolados em strings SQL.

## VULN-B: Mass Assignment + Allowlist de MIME

Apenas uma allowlist explícita de tipos MIME é aceita:

```php
private const array ALLOWED_MIMES = [
    'image/jpeg', 'image/png', 'image/gif', 'image/webp',
    'application/pdf', 'text/plain', 'text/csv',
];
```

Tipos MIME desconhecidos (ex.: `application/x-msdownload`, `application/x-sh`) são rejeitados com 422.

## VULN-C: IDOR

Usuários não-admin só podem acessar seus próprios uploads. Uploads de outros usuários retornam 404 (não 403):

```php
if (!$isAdmin && (int) $upload['user_id'] !== $uid) {
    return $this->problem(404, 'not-found', 'Upload not found.');
}
```

## VULN-D: Admin Fail-Closed

```php
private function isAdmin(ServerRequestInterface $req): bool
{
    if ($this->adminKey === '') {
        return false;
    }
    return hash_equals($this->adminKey, $req->getHeaderLine('X-Admin-Key'));
}
```

## VULN-F: Path Traversal

Separadores de diretório e `..` são rejeitados em nomes de arquivo:

```php
if (str_contains($filename, '/') || str_contains($filename, '\\') || str_contains($filename, '..')) {
    return $this->problem(422, 'validation-failed', 'filename must not contain path separators.');
}
```

Isso previne nomes de arquivo como `../etc/passwd`, `C:\Windows\cmd.exe` ou `subdir/evil.php`.

## VULN-G: ReDoS

IDs em parâmetros de caminho são validados com `ctype_digit()`, nunca com regex.

## VULN-I: Valores Negativos / Zero

```php
if (!is_int($sizeBytes) || $sizeBytes < 1 || $sizeBytes > self::MAX_SIZE) {
    return $this->problem(422, ...);
}
```

Tamanhos zero e negativos são rejeitados.

## VULN-J: Confusão de Tipo

- `mime_type` deve ser `is_string()` — inteiro `123` é rejeitado.
- `size_bytes` deve ser `is_int()` — string `"1024"` e float `100.5` são rejeitados.
- `is_public` deve ser `is_bool()` — string `"true"` e inteiro `1` são rejeitados.

## Resumo de Validação

| Campo | Regra |
|-------|-------|
| `X-User-Id` | Obrigatório para POST/DELETE; `ctype_digit`, >0 |
| `filename` | Não vazio, máximo 255 chars, sem `/`, `\`, `..` |
| `mime_type` | String; deve estar na allowlist |
| `size_bytes` | Inteiro de 1 a 104.857.600 (100 MiB) |
| `is_public` | Apenas booleano |

## Rotas

```
POST   /uploads              Registrar metadados de upload (X-User-Id obrigatório)
GET    /uploads/{id}         Obter metadados (proprietário ou admin)
DELETE /uploads/{id}         Deletar registro (proprietário ou admin)
GET    /users/{userId}/uploads  Listar uploads do usuário (proprietário ou admin)
```

## Veja Também

- Fonte FT210: `../NENE2-FT/uploadlog/`
- Relacionado: `docs/howto/wish-list-api.md` (FT207, também VULN)
