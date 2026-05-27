# Como Construir Código de Verificação Numérico

> **Padrão comprovado por FT188 verifylog** — código de verificação SMS/email de 6 dígitos com proteção contra força bruta, comparação em tempo constante e prevenção de replay. ATK-01〜12 todos aprovados.

---

## O Que Isso Cobre

Um fluxo de verificação de contato (email ou telefone):

1. **Solicitar código** — servidor gera um código aleatório de 6 dígitos, entrega fora de banda
2. **Enviar código** — usuário envia o código; máximo 3 tentativas antes do bloqueio
3. **Verificação de status** — verificar se uma verificação foi concluída

Garantias de segurança:

| Preocupação | Técnica |
|---|---|
| Força bruta | Máx 3 tentativas → 429 Bloqueado |
| Ataque de temporização | Comparação em tempo constante `hash_equals()` |
| Replay de código | Código verificado retorna 410 Gone |
| Enumeração de usuário | `POST /verifications` sempre retorna 202 |
| Mass assignment | `code_hash/verified_at` definidos apenas pelo servidor |
| SQL injection | Parâmetro de caminho somente inteiro (guarda ctype_digit + strlen > 18) |
| Confusão de tipo | Verificação `is_string()` antes de `ctype_digit()` |
| ReDoS | `ctype_digit()` O(n) — sem regex |

---

## Schema

```sql
CREATE TABLE verifications (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    contact        TEXT    NOT NULL,
    code_hash      TEXT    NOT NULL,   -- SHA-256 do código de 6 dígitos
    attempts_count INTEGER NOT NULL DEFAULT 0,
    max_attempts   INTEGER NOT NULL DEFAULT 3,
    verified_at    TEXT,               -- NULL = pendente
    expires_at     TEXT    NOT NULL,
    created_at     TEXT    NOT NULL
);
```

`code_hash` armazena `hash('sha256', $code)` — nunca o código em texto simples.

---

## API

| Método | Caminho | Descrição |
|---|---|---|
| `POST` | `/verifications` | Solicitar um código (sempre 202) |
| `POST` | `/verifications/{id}/check` | Enviar código (máx 3 tentativas) |
| `GET` | `/verifications/{id}` | Verificação de status (sem código revelado) |

---

## Padrão Principal: Geração de Código e Armazenamento de Hash

```php
// Gerar código de 6 dígitos aleatório criptograficamente seguro
$plainCode = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
$codeHash  = hash('sha256', $plainCode);

// Armazenar hash — NUNCA o texto simples
INSERT INTO verifications (contact, code_hash, expires_at, created_at)
VALUES (:contact, :code_hash, :expires_at, :now)

// Retornar plainCode ao chamador (para entrega) — nunca armazenar ou registrar em log
return ['verification' => $v, 'plainCode' => $plainCode];
```

`random_int(0, 999999)` usa CSPRNG. `str_pad(..., 6, '0', STR_PAD_LEFT)` garante zeros à esquerda (por ex., `000042`).

---

## Padrão Principal: Comparação em Tempo Constante

```php
// ATK-10: hash_equals previne ataque de temporização
// $v->codeHash = SHA-256 armazenado do banco de dados
// $submittedCode = entrada do usuário (string de 6 dígitos)
$valid = hash_equals($v->codeHash, hash('sha256', $submittedCode));
```

**Por que não `===`:** `===` faz curto-circuito no primeiro não-correspondência — um atacante pode medir diferenças de temporização entre "primeiro byte errado" e "todos os bytes errados" para descobrir o código correto caractere por caractere. `hash_equals()` é tempo constante independentemente de onde ocorre o não-correspondência.

---

## Padrão Principal: Contagem de Tentativas Falha Primeiro

```php
public function check(int $id, string $submittedCode): string
{
    $v = $this->fetchById($id);

    if ($v === null)        return 'not_found';
    if ($v->isVerified())   return 'already';   // ATK-11: guarda de replay
    if ($v->isLocked())     return 'locked';    // ATK-05: guarda de força bruta
    if ($v->isExpired())    return 'expired';

    // Incrementar ANTES de verificar — previne exploração de condição de corrida
    UPDATE verifications SET attempts_count = attempts_count + 1 WHERE id = :id

    // ATK-10: comparação em tempo constante
    $valid = hash_equals($v->codeHash, hash('sha256', $submittedCode));

    if ($valid) {
        UPDATE verifications SET verified_at = :now WHERE id = :id
        return 'verified';
    }

    return 'wrong';
}
```

Incrementar tentativas **antes** da comparação garante que uma corrida concorrente para verificar o mesmo código não possa contornar o limite.

---

## Padrão Principal: Prevenção de Enumeração de Usuário

```php
// POST /verifications — SEMPRE retorna 202
// Mesmo se o contato for inválido ou a entrega falhar
private function handleRequest(ServerRequestInterface $request): ResponseInterface
{
    $contact = V::str($body['contact'] ?? null, self::MAX_CONTACT_LEN);

    if ($contact === null || $contact === '') {
        return $this->responseFactory->create(['error' => '...'], 422); // apenas para vazio/null
    }

    // sucesso ou falha de entrega é invisível para o chamador
    $this->repository->create($contact);

    return $this->responseFactory->create(['id' => $v->id, 'expires_in' => 600], 202);
}
```

Um 404 ou 422 para um contato desconhecido vaza "este contato não está registrado." Sempre 202.

---

## Padrão Principal: Validação de Tipo e Formato do Código

```php
$raw = $body['code'] ?? null;

// ATK-07: confusão de tipo — código deve ser uma string
if (!is_string($raw)) {
    return $this->responseFactory->create(['error' => 'code must be a 6-digit string.'], 422);
}

// ATK-09: ReDoS — ctype_digit é O(n), não uma regex
// ATK-09: verificação de comprimento exato — não "pelo menos 6"
if (!ctype_digit($raw) || strlen($raw) !== 6) {
    return $this->responseFactory->create(['error' => 'code must be exactly 6 digits.'], 422);
}
```

`is_string()` antes de `ctype_digit()` rejeita inteiros JSON, booleanos e arrays. `ctype_digit()` é seguro contra ReDoS (tempo linear).

---

## Design da Resposta

| Cenário | Status | Corpo |
|---|---|---|
| Código correto | 200 | `{verified: true}` |
| Código errado, tentativas restantes | 422 | `{error: "Incorrect code.", attempts_left: N}` |
| Máximo de tentativas atingido | 429 | `{error: "Too many failed attempts. Request a new code."}` |
| Já verificado (replay) | 410 | `{error: "This verification has already been completed."}` |
| Expirado | 410 | `{error: "Verification has expired. Request a new code."}` |
| Não encontrado | 404 | `{error: "Verification not found."}` |

---

## ATK-01〜12 Todos Aprovados

| ATK | Ataque | Defesa |
|---|---|---|
| 01 | SQL injection em `{id}` | `ctype_digit()` + guarda strlen > 18 |
| 02 | IDOR — verificar com ID de verificação de outro | Mesmo 404 — sem oracle de propriedade |
| 03 | Mass assignment (code_hash/verified_at do corpo) | Definido apenas pelo servidor |
| 04 | XSS no contato | Apenas saída JSON — sem renderização HTML. Não retornar contato na resposta |
| 05 | Força bruta no código de 6 dígitos | 3 falhas → 429 Bloqueado |
| 06 | Bypass de autenticação | verified_at definido apenas pelo servidor |
| 07 | Confusão de tipo (código como int/bool/array) | `is_string()` + `ctype_digit()` |
| 08 | Overflow de inteiro em `{id}` | Guarda strlen > 18 |
| 09 | Entrada de código estilo ReDoS | `ctype_digit()` O(n) |
| 10 | Ataque de temporização na comparação de código | `hash_equals()` tempo constante |
| 11 | Replay de código após sucesso | 410 Gone |
| 12 | Injeção CRLF em headers | PSR-7 rejeita na camada HTTP |

---

## Resultados de Teste (FT188)

```
48 testes / 103 asserções — todos PASS
PHPStan nível 8 — sem erros
PHP CS Fixer — limpo
ATK-01〜12 todos aprovados
```

Fonte: [`../NENE2-FT/verifylog/`](https://github.com/hideyukiMORI/NENE2-examples/tree/main/verifylog)
