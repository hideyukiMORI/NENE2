# Como Fazer: API de Segredos de Uso Único e Teste de Ataque Cracker ATK-01〜12

> **NENE2 Field Trial 184** — Ciclo de Teste de Ataque Cracker (ATK-01〜12).
> Token É a credencial. Consumo atômico previne condições de corrida.

---

## O Que Este Trial Comprova

Um segredo de uso único armazena uma mensagem criptografada que pode ser lida apenas uma vez.
Após a primeira leitura bem-sucedida, o segredo é permanentemente consumido.

Requisitos de segurança:
1. **Entropia de token de 256 bits** — força bruta é computacionalmente inviável
2. **Consumo atômico** — `UPDATE WHERE consumed=0` previne condições de corrida de leitura dupla
3. **Prevenção de IDOR** — exclusão requer tanto token quanto propriedade do usuário
4. **Mass assignment bloqueado** — consumed/token/created_at são apenas do lado do servidor
5. **Segurança de tipo** — V::str() / V::userId() / V::queryInt() rejeitam entradas não-string

---

## API

| Método | Caminho | Auth | Descrição |
|---|---|---|---|
| `POST` | `/secrets` | X-User-Id | Criar um segredo de uso único |
| `GET` | `/secrets` | X-User-Id | Listar próprios segredos (apenas metadados, sem mensagem) |
| `GET` | `/secrets/{token}` | — | Ler + consumir (token É a credencial) |
| `DELETE` | `/secrets/{token}` | X-User-Id | Cancelar antes de ler (deve ser proprietário) |

---

## Resultados ATK-01〜12

| ID | Vetor de Ataque | Defesa | Resultado |
|---|---|---|---|
| ATK-01 | SQL injection no token | Queries parametrizadas PDO | ✅ PASS |
| ATK-02 | IDOR entre usuários na exclusão | `WHERE token=? AND user_id=?` | ✅ PASS |
| ATK-03 | Mass assignment (`consumed=1` no corpo) | Apenas campos do lado do servidor | ✅ PASS |
| ATK-04 | Payload XSS na mensagem | API JSON — sem renderização HTML | ✅ PASS |
| ATK-05 | Token duplamente codificado / malformado | Verificação de formato `/^[0-9a-f]{64}$/` | ✅ PASS |
| ATK-06 | Bypass de autenticação na leitura | Token É a credencial — por design | ✅ PASS |
| ATK-07 | Mensagem/senha como não-string | `V::str()` aplica `is_string()` | ✅ PASS |
| ATK-08 | Overflow de 20 dígitos em limit/offset | Guarda `V::queryInt()` strlen > 18 | ✅ PASS |
| ATK-09 | ReDoS no parâmetro limit | `ctype_digit()` — O(n), sem backtracking | ✅ PASS |
| ATK-10 | Força bruta no token | `random_bytes(32)` = entropia 2^256 | ✅ PASS |
| ATK-11 | Condição de corrida de leitura dupla | `UPDATE WHERE consumed=0` + verificação rowCount | ✅ PASS |
| ATK-12 | Injeção de header em X-User-Id | `V::userId()` aplica `ctype_digit()` | ✅ PASS |

**12/12: PASS**

---

## Padrão Principal: Consumo Atômico

A invariante de segurança crítica — um segredo só pode ser lido uma vez:

```php
// SecretRepository::consumeByToken()

// Passo 1: Buscar segredo (SELECT comum — não é a guarda)
$row = $pdo->prepare('SELECT * FROM secrets WHERE token = :token');
$row->execute(['token' => $token]);
$secret = $row->fetch(PDO::FETCH_ASSOC);

// Passo 2: Verificar flag de consumido (saída antecipada para caso comum)
if ($secret['consumed']) return null;

// Passo 3: UPDATE atômico — esta é a guarda real
$update = $pdo->prepare(
    'UPDATE secrets SET consumed = 1 WHERE token = :token AND consumed = 0'
);
$update->execute(['token' => $token]);

// Passo 4: rowCount() === 0 significa que outro leitor ganhou a corrida
if ($update->rowCount() === 0) {
    return null; // alguém consumiu entre nosso SELECT e este UPDATE
}

// Passo 5: Vencemos — retornar o segredo
return Secret::fromRow($secret);
```

**Por que funciona:** SQLite e a maioria dos RDBMS garantem que `UPDATE WHERE consumed=0` é atômico.
Apenas um escritor concorrente pode alterar `consumed` de 0→1. O perdedor obtém `rowCount()` igual a 0.

---

## Geração de Token

```php
$token = bin2hex(random_bytes(32)); // 64 chars hex = 32 bytes = 256 bits
```

- `random_bytes()` usa o CSPRNG do SO (equivalente a `/dev/urandom`)
- 2^256 tokens a 10^12 tentativas/segundo ≈ 10^60 anos para força bruta
- Tokens são únicos no banco de dados (constraint `UNIQUE`)

---

## Validação do Formato do Token

```php
private const TOKEN_PATTERN = '/^[0-9a-f]{64}$/';

// Rejeita: hex maiúsculo, traversal de caminho ../../, URL-encoded, inteiros, vazio
if (!preg_match(self::TOKEN_PATTERN, $rawToken)) {
    return $this->responseFactory->create(['error' => 'Secret not found.'], 404);
}
```

---

## Prevenção de IDOR (ATK-02)

```php
// DELETE requer TANTO propriedade do token QUANTO correspondência de user_id
$stmt = $pdo->prepare(
    'DELETE FROM secrets WHERE token = :token AND user_id = :user_id AND consumed = 0'
);
$stmt->execute(['token' => $token, 'user_id' => $userId]);

// Retorna 404 independentemente do motivo — evita oracle de enumeração
return $stmt->rowCount() > 0;
```

---

## Prevenção de Mass Assignment (ATK-03)

Campos do lado do servidor **nunca são lidos do corpo da requisição**:

```php
// Handler POST /secrets — apenas message, password, expires_at são aceitos do corpo
$token        = bin2hex(random_bytes(32));  // gerado pelo servidor
$consumed     = 0;                          // sempre começa não consumido
$createdAt    = (new DateTimeImmutable())->format(DateTimeInterface::ATOM); // hora do servidor
$passwordHash = $password !== null ? hash('sha256', $password) : null;     // hash no servidor

// body['consumed'], body['token'], body['user_id'], body['created_at'] são silenciosamente ignorados
```

---

## Cadeia de Validação V.php

```php
// ATK-07: message deve ser uma string (rejeita int, bool, null, array)
$message = V::str($body['message'] ?? null, 10000);

// ATK-12: X-User-Id deve ser ctype_digit + positivo + máx 18 chars
$userId = V::userId($request->getHeaderLine('X-User-Id'));

// ATK-08/09: limit deve ser numérico, máx 18 dígitos, no intervalo 1–100
$limit = V::queryInt($params, 'limit', 1, 100, 20);
```

---

## Proteção por Senha Opcional

```php
// Armazenamento: apenas hash SHA-256 (não texto simples)
$passwordHash = $password !== null ? hash('sha256', $password) : null;

// Verificação: comparação em tempo constante (segura contra temporização)
if (!hash_equals($secret->passwordHash, hash('sha256', $submittedPassword))) {
    return null; // senha errada → 404 silencioso (sem oracle)
}
```

> **Nota:** Senha errada retorna 404 (não 403) para prevenir ataques de oracle.
> O segredo NÃO é consumido com senha errada — apenas a senha correta o consome.

---

## Lista de Metadados (Sem Vazamento de Mensagem)

```php
// GET /secrets — retorna apenas metadados, nunca a mensagem
private function secretToMetadata(Secret $secret): array
{
    return [
        'token'        => $secret->token,
        'has_password' => $secret->passwordHash !== null,
        'consumed'     => $secret->consumed,
        'expires_at'   => $secret->expiresAt,
        'created_at'   => $secret->createdAt,
        // 'message' é intencionalmente omitido
    ];
}
```

---

## Resultados de Teste

```
85 testes / 209 asserções — todos PASS
PHPStan nível 8 — sem erros
PHP CS Fixer — limpo
```

---

## Principais Conclusões

| Padrão | Regra |
|---|---|
| Consumo atômico | `UPDATE WHERE consumed=0` + verificação `rowCount()` — não SELECT então UPDATE |
| Entropia do token | Mínimo `random_bytes(32)` (256 bits) — nunca IDs sequenciais |
| Formato do token | Regex allowlist ancorada em ambas as extremidades (`/^[0-9a-f]{64}$/`) |
| IDOR | Todas as operações de escrita têm escopo por `token AND user_id` |
| Mass assignment | Token, consumed, created_at — apenas do lado do servidor, nunca do corpo |
| Temporização de senha | `hash_equals()` para comparação em tempo constante |
| Senha errada | 404 não 403 — evita confirmar que o segredo existe |
| Lista de metadados | Omitir mensagem do endpoint de listagem — ler apenas no consumo |

Exemplo completo: [`../NENE2-FT/onetimelog/`](https://github.com/hideyukiMORI/NENE2-examples) no repositório de exemplos.
