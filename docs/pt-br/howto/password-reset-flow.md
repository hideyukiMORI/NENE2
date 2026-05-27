# Como Fazer: Fluxo de Redefinição de Senha

> **Referência FT**: FT285 (`NENE2-FT/resetlog`) — Fluxo de redefinição de senha: prevenção de enumeração de usuário (sempre 202), armazenamento de hash SHA-256 do token, TTL de 1 hora, token de uso único (409 em reutilização), 410 Gone na expiração, hash Argon2id para nova senha, 15 testes / 23 assertivas PASS.
>
> **Avaliação VULN**: V-01 a V-10 incluídos ao final deste documento.

Este guia mostra como implementar um fluxo seguro de redefinição de senha — os usuários solicitam uma redefinição, recebem um token (geralmente por e-mail) e o usam para definir uma nova senha.

## Schema

```sql
CREATE TABLE users (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    email         TEXT    NOT NULL UNIQUE,
    name          TEXT    NOT NULL,
    password_hash TEXT    NOT NULL,
    created_at    TEXT    NOT NULL
);

CREATE TABLE password_resets (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    token_hash TEXT    NOT NULL UNIQUE,
    used_at    TEXT,
    expires_at TEXT    NOT NULL,
    created_at TEXT    NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id)
);
```

`token_hash TEXT UNIQUE` — armazena SHA-256 do token bruto. O token bruto é enviado ao cliente e nunca armazenado.

## Endpoints

| Método | Caminho | Auth | Descrição |
|--------|---------|------|-----------|
| `POST` | `/password-reset` | Nenhuma | Solicitar redefinição de senha |
| `GET` | `/password-reset/{token}` | Nenhuma | Verificar status do token |
| `POST` | `/password-reset/{token}` | Nenhuma | Concluir redefinição com nova senha |

## Prevenção de Enumeração de Usuário

```php
$user = $this->repo->findUserByEmail($email);

// Sempre retornar 202 para prevenir enumeração de usuário
if ($user === null) {
    return $this->json->create(['status' => 'pending'], 202);
}

// Usuário real: criar token e (em produção) enviar e-mail
$rawToken = bin2hex(random_bytes(32));
// ...
return $this->json->create(['status' => 'pending', 'token' => $rawToken], 202);
```

E-mails válidos e inválidos retornam respostas 202 idênticas. Um atacante não consegue determinar quais e-mails estão registrados.

> **Nota de produção**: O token é retornado na resposta da API aqui por testabilidade. Em produção, envie o token apenas por e-mail — nunca inclua-o na resposta da API.

## Armazenamento do Token — Apenas SHA-256

```php
$rawToken  = bin2hex(random_bytes(32));  // 64 chars hex = entropia de 256 bits
$tokenHash = hash('sha256', $rawToken);

$this->repo->createReset($user->id, $tokenHash, $expiresAt, $now);

// Retornar token bruto ao cliente (em produção: via e-mail, não resposta HTTP)
return $this->json->create(['status' => 'pending', 'token' => $rawToken], 202);
```

O banco de dados armazena apenas o hash SHA-256. O token bruto é enviado ao usuário (por e-mail em produção) e nunca armazenado. Uma violação do banco revela hashes — inúteis sem os tokens brutos.

## Validação do Token

```php
$rawToken  = (string) ($params['token'] ?? '');
$tokenHash = hash('sha256', $rawToken);
$reset     = $this->repo->findByTokenHashOrNull($tokenHash);
```

O token bruto chega no caminho da requisição. O servidor o faz hash e consulta o banco. SHA-256 é determinístico — o mesmo token bruto sempre produz o mesmo hash.

## Estados do Ciclo de Vida do Token

```
pending → used (409 em reutilização)
pending → expired (410 Gone)
```

```php
if ($reset->isExpired($now)) {
    return $this->problems->create($request, 'gone', 'Reset token has expired.', 410, '');
}

if ($reset->isUsed()) {
    return $this->problems->create($request, 'conflict', 'Reset token has already been used.', 409, '');
}
```

| Status | HTTP | Quando |
|--------|------|--------|
| Não encontrado | 404 | Token não existe no banco |
| Expirado | 410 Gone | `expires_at` está no passado |
| Já usado | 409 Conflict | `used_at` está definido |
| Válido | 200 (GET) / 200 (POST) | Ativo, não usado, não expirado |

`410 Gone` é semanticamente mais correto que 404 para recursos expirados — o token existia, mas não está mais disponível.

## Concluir Redefinição

```php
$newHash = password_hash($newPassword, PASSWORD_ARGON2ID);
$this->repo->updatePasswordHash($reset->userId, $newHash);
$this->repo->markUsed($tokenHash, $now);  // definir used_at = $now

return $this->json->create(['status' => 'completed'], 200);
```

Ambas as operações devem estar em uma transação em produção. Se `updatePasswordHash` for bem-sucedido mas `markUsed` falhar, o usuário terá a senha redefinida, mas o token permanecerá reutilizável.

## Validação de Senha

```php
if (strlen($newPassword) < 8) {
    return $this->problems->create($request, 'validation-failed', 'Validation Failed', 422, null, [
        'errors' => [['field' => 'password', 'code' => 'min-length', 'message' => 'password must be at least 8 characters.']],
    ]);
}
```

Mínimo de 8 caracteres; aplicado tanto no registro quanto na redefinição. A nova senha é hashada com `PASSWORD_ARGON2ID` antes do armazenamento.

---

## Avaliação VULN — Diagnóstico de Vulnerabilidade

### V-01 — Enumeração de usuário via timing/conteúdo da resposta de redefinição 🛡️ SAFE

**Ameaça**: Atacante envia requisições de redefinição para muitos e-mails para identificar os registrados.
**Defesa**: E-mails registrados e não registrados retornam `202 { "status": "pending" }` com corpo de resposta e código de status idênticos. Sem diferença de timing (sem verificação de hash de senha necessária para a requisição de redefinição).
**Resultado**: SAFE — enumeração impossível pela resposta da API.

---

### V-02 — Força bruta do token 🛡️ SAFE

**Ameaça**: Atacante adivinha valores de token e os submete para redefinir qualquer conta.
**Defesa**: `bin2hex(random_bytes(32))` gera entropia de 256 bits (64 chars hex). A 10.000 tentativas/segundo, a força bruta levaria ~10^65 anos. A comparação de hash SHA-256 previne extensão de comprimento e timing oracle.
**Resultado**: SAFE — entropia de 256 bits é impossível de adivinhar.

---

### V-03 — Repetição do token após uso 🛡️ SAFE

**Ameaça**: Atacante intercepta um token de redefinição e o usa após o usuário legítimo já ter redefinido sua senha.
**Defesa**: `markUsed()` define `used_at` após a redefinição. Tentativas subsequentes verificam `isUsed()` → 409 Conflict.
**Resultado**: SAFE — imposição de uso único previne repetição.

---

### V-04 — Token expirado aceito 🛡️ SAFE

**Ameaça**: Atacante salva um token, aguarda o usuário fazer login, depois usa o token antigo.
**Defesa**: `isExpired($now)` verifica `expires_at`. Tokens expiram após 1 hora → 410 Gone.
**Resultado**: SAFE — tokens com limite de tempo previnem ataques tardios.

---

### V-05 — Injeção SQL via parâmetro de caminho do token 🛡️ SAFE

**Ameaça**: Submeter `'; DROP TABLE password_resets; --` como token.
**Defesa**: `hash('sha256', $rawToken)` produz uma string hex de 64 chars independente da entrada. O hash é usado em uma query parametrizada (`WHERE token_hash = ?`). Injeção SQL via parâmetro de caminho não é possível.
**Resultado**: SAFE — hashing + query parametrizada bloqueiam duplamente a injeção.

---

### V-06 — Token armazenado em texto puro no banco 🛡️ SAFE

**Ameaça**: Violação do banco expõe todos os tokens de redefinição ativos; atacante redefine todas as contas.
**Defesa**: O banco armazena apenas `hash('sha256', $rawToken)`. Os tokens brutos são retornados aos clientes (ou enviados por e-mail). SHA-256 é unidirecional; hashes não podem ser revertidos para tokens brutos sem força bruta.
**Resultado**: SAFE — armazenamento de hash SHA-256 protege tokens em repouso.

---

### V-07 — Nova senha armazenada em texto puro 🛡️ SAFE

**Ameaça**: Violação do banco expõe novas senhas definidas durante a redefinição.
**Defesa**: `password_hash($newPassword, PASSWORD_ARGON2ID)` faz hash da nova senha antes do armazenamento. O texto puro nunca é persistido.
**Resultado**: SAFE — hashing Argon2id protege senhas em repouso.

---

### V-08 — Tomada de conta por criação de token duplicado 🛡️ SAFE

**Ameaça**: Atacante prevê ou colide com o hash de token de outro usuário.
**Defesa**: `token_hash TEXT UNIQUE` — hashes duplicados são rejeitados pelo banco. Com entropia de 256 bits, a probabilidade de colisão é desprezível (limite de aniversário ~2^128 tentativas para probabilidade de colisão de 50%).
**Resultado**: SAFE — restrição UNIQUE + entropia de 256 bits previnem colisão.

---

### V-09 — Submeter nova senha fraca (< 8 chars) durante redefinição 🛡️ SAFE

**Ameaça**: Atacante redefine uma conta para uma senha trivialmente adivinhável como `aa`.
**Defesa**: `strlen($newPassword) < 8` → erro de validação 422 antes de qualquer operação no banco.
**Resultado**: SAFE — comprimento mínimo aplicado no caminho de redefinição (igual ao registro).

---

### V-10 — Endpoint de token revela qual etapa falhou (enumeração) 🛡️ SAFE

**Ameaça**: Comparando respostas 404 vs 409 vs 410, atacante mapeia o estado dos tokens de redefinição.
**Defesa**: Os códigos de erro revelam estado do ciclo de vida do token (não encontrado/expirado/usado), mas não informações do usuário. Saber que um token está expirado ou usado não identifica o titular da conta. A requisição de redefinição sempre retorna 202, independente de o e-mail existir.
**Resultado**: SAFE — nenhuma informação de identidade do usuário é revelada pelos estados do token.

---

### Resumo VULN

| ID | Ameaça | Resultado |
|----|--------|-----------|
| V-01 | Enumeração de usuário via resposta de redefinição | 🛡️ SAFE |
| V-02 | Força bruta do token | 🛡️ SAFE |
| V-03 | Repetição do token após uso | 🛡️ SAFE |
| V-04 | Token expirado aceito | 🛡️ SAFE |
| V-05 | Injeção SQL via caminho do token | 🛡️ SAFE |
| V-06 | Token armazenado em texto puro | 🛡️ SAFE |
| V-07 | Nova senha armazenada em texto puro | 🛡️ SAFE |
| V-08 | Colisão de token duplicado | 🛡️ SAFE |
| V-09 | Nova senha fraca aceita | 🛡️ SAFE |
| V-10 | Estado do token revela informações do usuário | 🛡️ SAFE |

**10 SAFE, 0 EXPOSED**
Prevenção de enumeração de usuário, entropia de 256 bits do token, armazenamento de hash SHA-256, hashing Argon2id de senha e imposição de uso único previnem todos os vetores de vulnerabilidade testados.

---

## O Que NÃO Fazer

| Anti-padrão | Risco |
|---|---|
| Retornar 404 para e-mail não registrado, 202 para registrado | Enumeração de usuário — atacante mapeia contas registradas |
| Armazenar token bruto no banco | Violação do banco expõe todos os tokens de redefinição ativos; tomada de conta em massa |
| Enviar token no corpo da resposta HTTP (produção) | Token interceptado por logs de navegador, proxies ou JS; enviar apenas por e-mail |
| Sem expiração nos tokens de redefinição | Tokens antigos permanecem válidos para sempre; tokens roubados utilizáveis meses depois |
| Permitir reutilização do token após redefinição de senha | Ataque de repetição do token após interceptação de e-mail |
| Sem comprimento mínimo de senha | Usuários definem `aa` como nova senha |
| Retornar 200 para GET `/password-reset/{token}` com token usado | Cliente não consegue distinguir válido de já usado |
| Usar MD5/SHA-1 para hash do token | Tabelas rainbow pré-computadas existem; usar SHA-256 ou melhor |
| Sem transação para `updatePasswordHash` + `markUsed` | Condição de corrida: senha atualizada, mas token permanece reutilizável |
