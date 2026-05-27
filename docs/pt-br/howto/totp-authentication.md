# Guia de Implementação de Autenticação de Dois Fatores TOTP

## Visão Geral

Este guia explica como implementar autenticação de dois fatores RFC 6238 TOTP (Time-based One-Time Password) usando o NENE2.
Fornece geração de segredo compatível com Google Authenticator e Authy, verificação de código, prevenção de ataques de replay e bloqueio por força bruta.

---

## Schema do Banco de Dados

```sql
CREATE TABLE totp_secrets (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id         INTEGER NOT NULL UNIQUE,
    secret          TEXT    NOT NULL,
    is_enabled      INTEGER NOT NULL DEFAULT 0,
    failed_attempts INTEGER NOT NULL DEFAULT 0,
    locked_until    TEXT,
    created_at      TEXT    NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

CREATE TABLE used_totp_steps (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    time_step  INTEGER NOT NULL,
    used_at    TEXT    NOT NULL,
    UNIQUE (user_id, time_step),
    FOREIGN KEY (user_id) REFERENCES users(id)
);
```

A tabela `used_totp_steps` é o núcleo da **prevenção de ataques de replay**. Registra os passos de tempo já utilizados.

---

## Design dos Endpoints

| Método | Caminho | Descrição |
|---|---|---|
| POST | `/users/{id}/totp/setup` | Gerar segredo TOTP (retornado para registro no app) |
| POST | `/users/{id}/totp/enable` | Verificar código e ativar 2FA |
| POST | `/users/{id}/totp/verify` | Verificar código (fluxo de login) |
| DELETE | `/users/{id}/totp` | Desativar 2FA (requer código válido) |
| GET | `/users/{id}/totp` | Obter status da 2FA |

---

## Implementação RFC 6238 TOTP

```php
class TotpGenerator
{
    private const int DIGITS = 6;
    private const int PERIOD = 30; // segundos

    public function computeCode(string $base32Secret, int $timeStep): string
    {
        $secret = $this->base32Decode($base32Secret);

        // Empacota o passo de tempo em 8 bytes big-endian
        $msg = pack('N*', 0) . pack('N*', $timeStep);
        $hash = hash_hmac('sha1', $msg, $secret, true);

        // Truncamento dinâmico (RFC 4226 §5.4)
        $offset = ord($hash[19]) & 0x0F;
        $code = ((ord($hash[$offset]) & 0x7F) << 24)
              | ((ord($hash[$offset + 1]) & 0xFF) << 16)
              | ((ord($hash[$offset + 2]) & 0xFF) << 8)
              | (ord($hash[$offset + 3]) & 0xFF);

        return str_pad((string) ($code % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    public function verify(string $base32Secret, string $code, int $window = 1): ?int
    {
        $t = (int) floor(time() / self::PERIOD);
        for ($offset = -$window; $offset <= $window; $offset++) {
            $step = $t + $offset;
            $expected = $this->computeCode($base32Secret, $step);
            if (hash_equals($expected, $code)) {   // previne ataque de timing
                return $step;
            }
        }
        return null;
    }
}
```

---

## Pontos-Chave do Design

### Prevenção de Ataques de Replay

Os códigos TOTP são válidos por 30 segundos. Se o mesmo código for usado duas vezes, a personificação se torna possível.
A tabela `used_totp_steps` registra os passos de tempo utilizados e rejeita a reutilização.

```php
$matchedStep = $this->totp->verify($secret, $code);
if ($matchedStep === null) {
    // código inválido
    return 401;
}
if ($this->repo->isStepUsed($userId, $matchedStep)) {
    // o mesmo time_step já foi usado → ataque de replay
    return 401;
}
// registra como utilizado
$this->repo->markStepUsed($userId, $matchedStep, $now);
```

### Prevenção de Ataque de Timing

Use `hash_equals()` para comparar códigos TOTP. `===` e `strcmp()` terminam antecipadamente a comparação de strings, permitindo que o tempo de resposta revele quantos dígitos correspondem.

```php
// ERRADO: vulnerável a ataque de timing
if ($expected === $inputCode) { ... }

// CORRETO: comparação em tempo constante
if (hash_equals($expected, $inputCode)) { ... }
```

### Janela de Tolerância (para Desvio de Relógio)

`window = 1` tolera o passo atual ± 1 (= ±30 segundos).
O desvio de relógio de smartphones quase sempre fica dentro desse intervalo.
Aumentar a janela reduz a segurança, portanto 1 é recomendado.

### Bloqueio por Força Bruta

3 falhas resultam em bloqueio de 15 minutos (423 Locked).
Durante o bloqueio, até códigos corretos são rejeitados (previne oracle de timing):

```php
if ($this->repo->isLocked($userId, $now)) {
    return 423; // bloqueado — não verifica se o código está correto
}
```

### Fluxo de Configuração

1. `POST /users/{id}/totp/setup` para gerar o segredo
2. Registrar o `secret` (Base32) ou `otpauth_uri` da resposta no app Authenticator
3. `POST /users/{id}/totp/enable` para verificar o primeiro código e ativar
4. Antes da ativação, o segredo fica salvo no banco com `is_enabled = false`

```
otpauth://totp/NENE2:alice?secret=JBSWY3DPEHPK3PXP&issuer=NENE2&algorithm=SHA1&digits=6&period=30
```

### Expiração do Segredo Antigo ao Reconfigurar

Chamar `POST /users/{id}/totp/setup` novamente sobrescreve o segredo antigo e
os `used_totp_steps` também são deletados. Códigos do segredo antigo não podem mais autenticar.

---

## Checklist de Segurança (12 itens de diagnóstico de vulnerabilidades, todos aprovados)

| # | Item de Verificação | Medida |
|---|---|---|
| A | Ataque de replay | Registra passos de tempo utilizados em `used_totp_steps` |
| B | Força bruta | 3 falhas resultam em bloqueio de 15 minutos (423) |
| C | Código legítimo durante bloqueio | Verifica o bloqueio primeiro e não realiza verificação de código |
| D | Desativação de 2FA não autorizada | DELETE também requer código válido |
| E | Ativação de 2FA não autorizada | Enable requer verificação de código obrigatória |
| F | Uso indevido do segredo antigo | Reconfiguração deleta segredo antigo e passos utilizados |
| G | IDOR | Código verificado com segredo independente por usuário |
| H | Exposição do segredo | Respostas de verify/enable não incluem secret |
| I | Código com formato inválido | Não correspondência → 401 (validação de formato é opcional) |
| J | Código vazio | Validação de campo obrigatório retorna 422 |
| K | Verificação sem ativação | Verificação de `is_enabled` retorna 409 |
| L | Usuário inexistente | findUser() → null → 404 |

---

## Observações para Testes

Como os códigos TOTP dependem do tempo, usar o mesmo time_step consecutivamente será tratado como replay.
Nos testes, gere códigos com passos diferentes usando `TotpGenerator::computeCode($secret, $gen->currentTimeStep() + N)`:

```php
$enableCode  = $gen->computeCode($secret, $gen->currentTimeStep());     // usado no enable
$verifyCode  = $gen->computeCode($secret, $gen->currentTimeStep() + 1); // usado no verify
$disableCode = $gen->computeCode($secret, $gen->currentTimeStep() + 2); // usado no disable
```

---

## Implementação de Referência

`../NENE2-FT/totplog/` — Field Trial FT159 (21 testes + 12 diagnósticos de vulnerabilidades = 32 testes)
