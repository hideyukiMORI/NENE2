# Bloqueio de Conta (Proteção contra Força Bruta)

> **Referência FT**: FT280 (`NENE2-FT/lockoutlog`) — Bloqueio de conta: 5 tentativas falhas disparam bloqueio de 15 minutos (423 Locked), senha correta bloqueada durante o bloqueio, sucesso reseta o contador, verificação de senha com Argon2id, testes de integração MySQL, 27 testes passam / 5 pulados (MySQL), 44 asserções PASS.
>
> **Avaliação ATK**: ATK-01 a ATK-12 incluídos no final deste documento.

Proteja os endpoints de login contra ataques de força bruta bloqueando uma conta após um número configurável de tentativas falhas.

## Visão Geral

O bloqueio de conta rastreia tentativas de login falhas por endereço de email e define um timestamp `locked_until` quando o limite de falhas é excedido. O bloqueio é aplicado em toda tentativa de login — mesmo uma senha correta é rejeitada enquanto a conta está bloqueada. O bloqueio expira automaticamente após um período de espera.

## Schema do Banco de Dados

```sql
CREATE TABLE users (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    email         TEXT    NOT NULL UNIQUE,
    password_hash TEXT    NOT NULL,
    created_at    TEXT    NOT NULL
);

CREATE TABLE account_states (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    email        TEXT    NOT NULL UNIQUE,
    failed_count INTEGER NOT NULL DEFAULT 0,
    locked_until TEXT,
    updated_at   TEXT    NOT NULL
);
```

`account_states` rastreia o histórico de falhas por conta. `locked_until` é null para contas desbloqueadas.

## Constantes

```php
public const int MAX_ATTEMPTS    = 5;   // falhas antes do bloqueio
public const int LOCKOUT_MINUTES = 15;  // duração do bloqueio
```

## Fluxo de Login

```php
// 1. Verificar bloqueio antes da verificação de senha
$state = $this->repo->findOrCreateAccountState($email, $now);
if ($state->isLocked($now)) {
    return 423; // Locked
}

// 2. Verificar credenciais
$user = $this->repo->findUserByEmail($email);
if ($user === null || !$user->verifyPassword($pass)) {
    if ($user !== null) {
        $this->repo->recordFailure($email, $now);
    }
    return 401; // Unauthorized
}

// 3. Sucesso — resetar contador
$this->repo->resetState($email, $now);
return 200;
```

A verificação de bloqueio acontece **antes** da verificação de senha. O estado de bloqueio é gravado apenas para **usuários existentes** — emails desconhecidos retornam 401 sem criar uma linha `account_state` (evita esgotamento de armazenamento).

## Verificação de Bloqueio

```php
public function isLocked(string $now): bool
{
    return $this->lockedUntil !== null && $now < $this->lockedUntil;
}
```

`$now` é uma string `Y-m-d H:i:s`. A comparação lexicográfica funciona corretamente para strings de data/hora ISO 8601.

## Registrando Falha

```php
public function recordFailure(string $email, string $now): AccountState
{
    $state    = $this->findOrCreateAccountState($email, $now);
    $newCount = $state->failedCount + 1;

    $lockedUntil = null;
    if ($newCount >= AccountState::MAX_ATTEMPTS) {
        $lockedUntil = date('Y-m-d H:i:s', strtotime($now) + AccountState::LOCKOUT_MINUTES * 60);
    }

    $this->executor->execute(
        'UPDATE account_states SET failed_count = ?, locked_until = ?, updated_at = ? WHERE email = ?',
        [$newCount, $lockedUntil, $now, $email],
    );
    ...
}
```

Quando `failed_count` atinge `MAX_ATTEMPTS`, `locked_until` é definido para `now + LOCKOUT_MINUTES * 60` segundos.

## Reset no Sucesso

```php
$this->executor->execute(
    'UPDATE account_states SET failed_count = 0, locked_until = NULL, updated_at = ? WHERE email = ?',
    [$now, $email],
);
```

A autenticação bem-sucedida reseta tanto `failed_count` quanto `locked_until`. Um usuário que tem sucesso antes do bloqueio recebe um contador de falhas zerado.

## Prevenção de Enumeração de Usuários

Retorne o mesmo status HTTP (401) tanto para senha errada quanto para email desconhecido:

```php
if ($user === null || !$user->verifyPassword($pass)) {
    if ($user !== null) {
        $this->repo->recordFailure($email, $now);
    }
    return 401; // mesmo status independentemente
}
```

Um atacante não consegue distinguir "sem conta" de "senha errada" via a resposta HTTP.

## Schema MySQL

Para MySQL, use `INT AUTO_INCREMENT` e `DATETIME`:

```sql
CREATE TABLE IF NOT EXISTS users (
    id            INT          NOT NULL AUTO_INCREMENT,
    email         VARCHAR(255) NOT NULL,
    password_hash TEXT         NOT NULL,
    created_at    DATETIME     NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS account_states (
    id           INT          NOT NULL AUTO_INCREMENT,
    email        VARCHAR(255) NOT NULL,
    failed_count INT          NOT NULL DEFAULT 0,
    locked_until DATETIME     NULL,
    updated_at   DATETIME     NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

O formato de data/hora `Y-m-d H:i:s` funciona tanto para SQLite (comparação TEXT) quanto para MySQL (coluna DATETIME).

## Teste de Integração MySQL

Adicione um `MysqlLockoutTest.php` que pula a menos que `MYSQL_HOST` esteja definido:

```php
protected function setUp(): void
{
    $host = (string) (getenv('MYSQL_HOST') ?: '');
    if ($host === '') {
        self::markTestSkipped('MYSQL_HOST não definido — pulando testes de integração MySQL');
    }
    // Drop + recriar tabelas para isolamento de teste
    $this->pdo->exec('DROP TABLE IF EXISTS account_states');
    $this->pdo->exec('DROP TABLE IF EXISTS users');
    $this->pdo->exec($mysqlSchema);
    ...
}
```

Execute contra o container MySQL FT compartilhado (porta 3308, volume persistente):

```bash
docker compose -f ../NENE2-FT/docker-compose.yml up -d mysql
```

Em seguida, execute os testes de integração com variáveis de ambiente:

```bash
MYSQL_HOST=127.0.0.1 MYSQL_PORT=3308 MYSQL_DATABASE=ft_test \
  MYSQL_USER=ft_user MYSQL_PASSWORD=ft_pass \
  php8.4 vendor/bin/phpunit --filter Mysql
```

Sem `MYSQL_HOST`, os testes MySQL são automaticamente pulados.

## Propriedades de Segurança

| Propriedade | Implementação |
|---|---|
| Limite de bloqueio | 5 tentativas falhas |
| Duração do bloqueio | 15 minutos |
| Senha correta durante o bloqueio | Bloqueada (423) |
| Enumeração de usuários | Mesmo 401 para email desconhecido e senha errada |
| Escopo do bloqueio | Por endereço de email, não por IP |
| Reset do bloqueio | Automático no login bem-sucedido |
| Hash de senha | Argon2id |
| Entrada de email longa | Rejeitada com 256+ caracteres (422) |
| SQL injection | Consultas parametrizadas previnem injeção |

## Trade-off de Design: DoS por Bloqueio

Como o bloqueio é por email (não por IP), um atacante que conhece o email de um usuário pode bloqueá-lo enviando 5 senhas erradas. Essa é uma tensão inerente entre proteção contra força bruta e disponibilidade.

Mitigações (não implementadas aqui, mas disponíveis):
- **Atrasos progressivos** em vez de bloqueio rígido
- **CAPTCHA** após N falhas
- **Email de notificação** quando o bloqueio é disparado
- **Endpoint de desbloqueio pelo admin**

Para a maioria das aplicações, o trade-off favorece a proteção contra força bruta. O bloqueio expira automaticamente após 15 minutos.

## Resumo das Rotas

| Método | Caminho | Descrição |
|---|---|---|
| `POST` | `/users` | Criar usuário (seed/registro) |
| `POST` | `/auth/login` | Tentativa de login (200/401/423) |
| `GET` | `/auth/status/{email}` | Verificar status de bloqueio |

---

## Avaliação ATK — Teste de Ataque com Mentalidade de Cracker

### ATK-01 — Força bruta até o bloqueio 🚫 BLOCKED

**Ataque**: Enviar 5+ tentativas de login falhas com senhas erradas para um email conhecido.
**Resultado**: BLOCKED — após 5 falhas, `failed_count >= MAX_ATTEMPTS` define `locked_until = now + 15 min`. Tentativas subsequentes recebem 423 `account-locked` antes da senha ser verificada.

---

### ATK-02 — Enviar senha correta após bloqueio 🚫 BLOCKED

**Ataque**: Bloquear a conta, depois enviar imediatamente a senha correta.
**Resultado**: BLOCKED — a verificação de bloqueio acontece antes de `findUserByEmail()`. Mesmo com a senha correta, 423 é retornado enquanto bloqueado.

---

### ATK-03 — Sondar email inexistente para evitar bloqueio em contas reais 🚫 BLOCKED (por design)

**Ataque**: Usar um email inexistente para sondar sem disparar bloqueio em contas reais.
**Resultado**: BLOCKED (por design) — emails inexistentes não acumulam falhas, protegendo o armazenamento. Contas reais são protegidas por seu próprio estado de bloqueio. Sondar emails falsos não revela nada sobre contas reais.

---

### ATK-04 — Condição de corrida: tentativas de login concorrentes no limite de falhas 🚫 BLOCKED

**Ataque**: Enviar duas requisições simultaneamente quando `failed_count` está em 4 para ultrapassar o bloqueio.
**Resultado**: BLOCKED — `UPDATE account_states` é atômico no nível do banco de dados. SQLite WAL serializa escritas concorrentes; MySQL usa bloqueio por linha. Ambas as atualizações são bem-sucedidas; `locked_until` final é definido corretamente.

---

### ATK-05 — Endpoint de status revela estado de bloqueio 🚫 BLOCKED (por design)

**Ataque**: `GET /auth/status/{email}` para descobrir se um email foi bloqueado.
**Resultado**: POR DESIGN — o endpoint de status é destinado à UX do cliente ("tente novamente em 15 min"). Em produção, deve ter rate limiting ou exigir autenticação. Revela o tempo de bloqueio, mas não informações de senha.

---

### ATK-06 — SQL injection via campo de email 🚫 BLOCKED

**Ataque**: Enviar `{"email": "' OR '1'='1' --", "password": "x"}`.
**Resultado**: BLOCKED — todas as consultas usam declarações parametrizadas (`WHERE email = ?`). A string injetada é tratada como um valor de email literal.

---

### ATK-07 — String de email excessivamente grande para causar negação de serviço 🚫 BLOCKED

**Ataque**: Enviar um campo de email com 100.000 caracteres.
**Resultado**: BLOCKED — `if (strlen($email) > 255)` → 422 `validation-failed` antes de qualquer consulta ao banco de dados.

---

### ATK-08 — Campos de email ou senha ausentes 🚫 BLOCKED

**Ataque**: Enviar `{}` ou `{"email": "x@x.com"}` sem senha.
**Resultado**: BLOCKED — `if ($email === '' || $pass === '')` → 422 `validation-failed`.

---

### ATK-09 — Resetar contador fazendo login com outra conta 🚫 BLOCKED

**Ataque**: Bloquear a conta A, depois fazer login como conta B para resetar o contador de A.
**Resultado**: BLOCKED — `resetState()` é indexado por email. O login bem-sucedido de outra conta não tem efeito no estado da conta A.

---

### ATK-10 — Email com apenas espaços para contornar validação 🚫 BLOCKED

**Ataque**: Enviar `{"email": "   ", "password": "x"}`.
**Resultado**: BLOCKED — `$email = trim($body['email'])` reduz espaços para `''` → 422.

---

### ATK-11 — Tipo de email não string para contornar verificação is_string 🚫 BLOCKED

**Ataque**: Enviar `{"email": 12345, "password": "x"}` (email como inteiro).
**Resultado**: BLOCKED — verificação `is_string($body['email'])` → false → `$email = ''` → 422.

---

### ATK-12 — Bloqueio sustentado da vítima (ataque de disponibilidade) 🚫 BLOCKED (mitigado)

**Ataque**: Usuário malicioso falha repetidamente o login do email da vítima para manter o bloqueio permanente.
**Resultado**: MITIGADO — o bloqueio é baseado em tempo (15 minutos). Expira automaticamente; sem banimento permanente. O ataque sustentado mantém a janela de 15 minutos, mas não consegue desabilitar permanentemente a conta. Proteção em produção: CAPTCHA, rate limiting por IP, notificar o usuário por email.

---

### Resumo ATK

| ID | Ataque | Resultado |
|----|--------|-----------|
| ATK-01 | Força bruta até o bloqueio | 🚫 BLOCKED |
| ATK-02 | Senha correta após bloqueio | 🚫 BLOCKED |
| ATK-03 | Sondar via email inexistente | 🚫 BLOCKED (por design) |
| ATK-04 | Condição de corrida no contador de falhas | 🚫 BLOCKED |
| ATK-05 | Endpoint de status revela estado de bloqueio | 🚫 BLOCKED (por design) |
| ATK-06 | SQL injection via email | 🚫 BLOCKED |
| ATK-07 | DoS com email excessivamente grande | 🚫 BLOCKED |
| ATK-08 | Campos obrigatórios ausentes | 🚫 BLOCKED |
| ATK-09 | Resetar contador via outra conta | 🚫 BLOCKED |
| ATK-10 | Email com apenas espaços | 🚫 BLOCKED |
| ATK-11 | Tipo de email não string | 🚫 BLOCKED |
| ATK-12 | Bloqueio sustentado da vítima | 🚫 BLOCKED (mitigado) |

**12 BLOCKED / MITIGATED, 0 EXPOSED**
Verificação de bloqueio antes da verificação de senha, consultas parametrizadas, validação de comprimento de entrada e expiração baseada em tempo previnem todos os vetores de ataque testados.

---

## O Que NÃO Fazer

| Anti-padrão | Risco |
|---|---|
| Verificar bloqueio após verificação de senha | Desperdiça CPU do Argon2id para contas bloqueadas; canal lateral de tempo de bloqueio |
| Retornar 429 para bloqueio de conta | Semântica errada — 429 é rate limiting, 423 é recurso bloqueado |
| Implementar bloqueio permanente por falha | Atacante pode negar serviço permanentemente para qualquer usuário com email conhecido |
| Registrar falhas para emails inexistentes | Atacante pré-cria estados de bloqueio antes dos usuários se cadastrarem |
| Sem validação de comprimento de email | Strings de email com 100KB+ causam consultas lentas ou pressão de memória |
| Armazenar estado de bloqueio em memória/sessão | Estado perdido na reinicialização do servidor; não compartilhado entre múltiplas instâncias do app |
| Mesmo erro para bloqueado vs senha errada | Difícil distinguir UX — use 423 para bloqueado, 401 para credenciais erradas |
