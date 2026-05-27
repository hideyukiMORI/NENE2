# Como Fazer: API de Cofre de Segredos Pessoais

Demonstra armazenamento chave-valor por usuário com integridade HMAC, prevenção de IDOR e acesso de metadados somente para admin.
Field trial: FT195 (`../NENE2-FT/vaultlog/`). Inclui auditoria de segurança VULN-A~L.

---

## Resumo do Padrão

| Preocupação | Abordagem |
|---|---|
| Isolamento de usuário | `WHERE user_id = :uid` em toda query — IDOR impossível |
| Admin nunca vê valores | Endpoints admin retornam apenas `user_id + key` |
| Integridade HMAC | `HMAC-SHA256(userId|key|value, secret)` armazenado por entrada |
| Validação de chave | `preg_match('/\A[a-z0-9_-]{1,64}\z/', $key)` — seguro, sem risco de ReDoS |
| Validação de ID de usuário | `ctype_digit()` + guarda de comprimento + verificação `> 0` |
| Chave admin | `hash_equals()` tempo constante, fail-closed em chave vazia |
| Upsert | `UNIQUE(user_id, key_name)` → armazenar primeiro (201) ou atualizar (200) |

---

## Rotas

| Método | Caminho | Auth | Descrição |
|---|---|---|---|
| `POST` | `/vault` | `X-User-Id` | Armazenar ou atualizar um segredo |
| `GET` | `/vault` | `X-User-Id` | Listar chaves de segredo do usuário (sem valores) |
| `GET` | `/vault/{key}` | `X-User-Id` | Obter valor de segredo do usuário |
| `DELETE` | `/vault/{key}` | `X-User-Id` | Excluir segredo do usuário |
| `GET` | `/admin/vault` | `X-Admin-Key` | Listar todos os usuários + chaves (sem valores) |
| `GET` | `/admin/vault/{userId}` | `X-Admin-Key` | Listar chaves de usuário específico |

---

## Schema do Banco de Dados

```sql
CREATE TABLE vault_entries (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    key_name   TEXT    NOT NULL,
    value      TEXT    NOT NULL,
    hmac       TEXT    NOT NULL,   -- tag de integridade HMAC-SHA256
    created_at TEXT    NOT NULL,
    updated_at TEXT    NOT NULL,
    UNIQUE (user_id, key_name)
);
```

A restrição `UNIQUE(user_id, key_name)` impõe uma entrada por par (usuário, chave).

---

## Integridade HMAC

```php
private function computeHmac(int $userId, string $key, string $value): string
{
    return hash_hmac('sha256', "{$userId}|{$key}|{$value}", $this->hmacSecret);
}
```

No GET, o handler verifica o HMAC armazenado:

```php
if (!$this->repo->verifyIntegrity($entry)) {
    return $this->problem(500, 'integrity-error', 'Secret integrity check failed.');
}
```

Isso detecta adulteração direta no banco (ex.: um DBA comprometido alterando valores sem passar pela API).

---

## Prevenção de IDOR

Toda query inclui `user_id = :uid`:

```sql
SELECT * FROM vault_entries WHERE user_id = :uid AND key_name = :key
```

O usuário 200 consultando a chave `private-key` do usuário 100 recebe 404 — idêntico a "não encontrado",
impedindo enumeração de quais chaves existem para outros usuários.

Os endpoints admin nunca retornam `value`:

```php
// Usuário vê seu próprio valor
public function toUserArray(): array
{
    return ['key' => ..., 'value' => $this->value, ...];
}

// Admin vê apenas metadados — sem valor
public function toAdminArray(): array
{
    return ['user_id' => ..., 'key' => ..., ...];
}
```

---

## Validação de Chave

```php
private const string KEY_PATTERN = '/\A[a-z0-9_-]{1,64}\z/';
```

As âncoras `\A` e `\z` impedem correspondências parciais. O conjunto de caracteres é mínimo:
alfanumérico minúsculo, hífen, underscore. O comprimento é limitado `{1,64}` — sem amplificação de backtracking.

Isso rejeita:
- Letras maiúsculas (`UPPER_CASE`)
- Espaços ou caracteres especiais
- Fragmentos de path traversal (`../etc/passwd`)
- Strings injetáveis em SQL (`' OR '1'='1`)
- String vazia ou strings > 64 chars

---

## Validação de ID de Usuário

```php
private function resolveUserId(ServerRequestInterface $request): ?int
{
    $raw = $request->getHeaderLine('X-User-Id');
    if ($raw === '' || !ctype_digit($raw) || strlen($raw) > 18) return null;
    $id = (int) $raw;
    return $id > 0 ? $id : null;
}
```

- `ctype_digit()` rejeita números negativos (o sinal `-` não é um dígito)
- `strlen > 18` impede overflow de inteiro (`PHP_INT_MAX` tem 19 dígitos)
- `> 0` rejeita `"0"` como ID de usuário inválido

---

## Padrão de Upsert

```php
public function store(int $userId, string $key, string $value): string
{
    $existing = $this->findEntry($userId, $key);
    if ($existing !== null) {
        // UPDATE ...
        return 'updated';  // → 200
    }
    // INSERT ...
    return 'stored';  // → 201
}
```

Retorna `'stored'` (201) na primeira escrita, `'updated'` (200) na sobrescrita.
O handler mapeia esses valores para códigos de status HTTP.

---

## Resultados VULN-A~L

| Verificação | Teste | Resultado |
|---|---|---|
| VULN-A | Injeção SQL no parâmetro de chave / corpo | PASS — validação da chave rejeita antes da query |
| VULN-B | IDOR: usuário lê/exclui chave de outro usuário | PASS — 404 no acesso entre usuários |
| VULN-C | Lista retorna apenas próprias entradas | PASS — WHERE com escopo de user_id |
| VULN-D | Força bruta / bypass da chave admin | PASS — hash_equals + fail-closed |
| VULN-E | XSS no valor | PASS — armazenado como-está, resposta JSON não é HTML |
| VULN-F | Idempotência de upsert de chave | PASS — última escrita vence, sem duplicatas |
| VULN-G | Path traversal na chave | PASS — padrão rejeita `..` e barras |
| VULN-H | user-id negativo / zero | PASS — guarda ctype_digit + > 0 |
| VULN-I | user-id muito grande (overflow) | PASS — guarda strlen > 18 |
| VULN-J | Byte nulo no caminho | PASS — roteador / padrão rejeita |
| VULN-K | Chave muito longa no corpo | PASS — validação 422 |
| VULN-L | Segredo HMAC vazio (sem pânico) | PASS — HMAC determinístico com chave vazia, sem crash |

---

## Notas de Teste

- `AppFactory::create(?PDO, ?string adminKey, ?string hmacSecret)` — todos injetáveis para testes unitários.
- `withParsedBody($body)` é obrigatório em helpers de teste (Nyholm PSR-7 não analisa JSON automaticamente).
- Testes IDOR: armazenar como usuário 100, tentar acesso como usuário 200 → deve obter 404.
- Testes admin: verificar que a chave `value` está ausente em todo array de resposta.
