# Guia de Implementação de Login Social OAuth2

## Visão Geral

Este guia explica como implementar Login Social usando o OAuth2 Authorization Code Flow com NENE2.
Inclui prevenção de CSRF (parâmetro state), prevenção de replay de código, invalidação de sessão e testes de ataque cracker (ATK-01〜12).

---

## Schema do Banco de Dados

```sql
CREATE TABLE users (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    provider   TEXT    NOT NULL,
    subject    TEXT    NOT NULL,  -- identificador de usuário emitido pelo provedor OAuth
    name       TEXT    NOT NULL,
    email      TEXT,
    created_at TEXT    NOT NULL,
    UNIQUE (provider, subject)
);

CREATE TABLE oauth_states (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    state      TEXT    NOT NULL UNIQUE,
    created_at TEXT    NOT NULL,
    expires_at TEXT    NOT NULL,
    used_at    TEXT    -- NULL = não usado, NOT NULL = usado (não reutilizável)
);

CREATE TABLE sessions (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    token      TEXT    NOT NULL UNIQUE,
    created_at TEXT    NOT NULL,
    expires_at TEXT    NOT NULL,
    revoked_at TEXT,   -- NULL = válido, NOT NULL = logout realizado
    FOREIGN KEY (user_id) REFERENCES users(id)
);

CREATE TABLE used_oauth_codes (
    id       INTEGER PRIMARY KEY AUTOINCREMENT,
    code     TEXT    NOT NULL UNIQUE,
    used_at  TEXT    NOT NULL
);
```

`oauth_states.used_at` e `used_oauth_codes` são o núcleo da **prevenção de CSRF e replay de código**.

---

## Design dos Endpoints

| Método | Caminho | Descrição |
|---|---|---|
| POST | `/auth/oauth/start` | Gerar state e retornar URL de autorização |
| POST | `/auth/oauth/callback` | Verificar state/code, criar usuário, emitir sessão |
| POST | `/auth/logout` | Invalidar sessão |
| GET | `/me` | Obter informações do usuário autenticado |

---

## Authorization Code Flow

```
Cliente                Servidor                 Provedor OAuth
  |                      |                            |
  |-- POST /start -----→ |                            |
  |← {state, auth_url} --|                            |
  |                      |                            |
  |-- Usuário acessa auth_url →→→→→→→→→→→→→→→→→→→→→|
  |←←←←←←←←←←←←←←←←←←← redirect com ?code=XXX&state=YYY |
  |                      |                            |
  |-- POST /callback ──→ |                            |
  |   {state, code}      |-- troca de code →→→→→→→→ |
  |                      |← {subject, name, email} ---|
  |← {token, user} -----.|                            |
  |                      |                            |
  |-- GET /me ─────────→ |                            |
  |   Authorization: Bearer <token>                   |
  |← {id, name, email} - |                            |
```

---

## Pontos-chave do Design

### Prevenção de CSRF (parâmetro state)

Callbacks OAuth2 chegam via parâmetros de URL, portanto um atacante pode direcionar a vítima para uma URL de callback maliciosa (CSRF).
Previna com `state`:

1. Salvar um state aleatório no banco de dados em `/auth/oauth/start`
2. Verificar o state no callback
3. **Tornar o state usado não reutilizável** (registrar `used_at`)

```php
if (!$this->repo->isStateValid($state, $now)) {
    return $this->json->create(['error' => 'Invalid, expired, or already used state'], 400);
}
```

### Prevenção de Replay de Código

O Authorization Code pode ser usado apenas uma vez (RFC 6749 §4.1.2).
Registre códigos usados na tabela `used_oauth_codes` e recuse reutilização:

```php
if ($this->repo->isCodeUsed($code)) {
    return $this->json->create(['error' => 'Authorization code already used'], 400);
}
// ... verificação do provedor ...
$this->repo->markCodeUsed($code, $now);
```

### Ordem de Consumo de State e Code

Verificar state → verificar code → **consultar provedor → marcar state e code como usados simultaneamente**.
Se o provedor falhar, nem state nem code são consumidos (possível tentar novamente).

### Autenticação por Token Bearer

```php
private function bearerToken(ServerRequestInterface $request): ?string
{
    $header = $request->getHeaderLine('Authorization');
    if (!str_starts_with($header, 'Bearer ')) {
        return null;
    }
    return substr($header, 7) ?: null;
}
```

### Upsert de Usuário

Quando o mesmo subject do mesmo provedor faz login novamente, atualizar o usuário existente:

```php
public function upsertUser(array $info, string $now): int
{
    $row = $this->db->fetchOne(
        'SELECT id FROM users WHERE provider = ? AND subject = ?',
        [$info['provider'], $info['subject']],
    );
    if ($row !== null) {
        // Atualizar nome e email para os mais recentes
        $this->db->insert('UPDATE users SET name = ?, email = ? WHERE id = ?', [...]);
        return (int) $row['id'];
    }
    return $this->db->insert('INSERT INTO users ...', [...]);
}
```

### Validade do State

O state é válido por 5 minutos. States expirados são rejeitados pela verificação `expires_at > $now`:

```php
public function isStateValid(string $state, string $now): bool
{
    $row = $this->findState($state);
    if ($row === null || $row['used_at'] !== null) return false;
    return (string) $row['expires_at'] > $now;
}
```

---

## Testes de Ataque Cracker ATK-01〜12 (Todos Aprovados)

| # | Cenário de Ataque | Contramedida | Status Esperado |
|---|---|---|---|
| ATK-01 | CSRF: parâmetro state ausente | Validação de campo obrigatório | 422 |
| ATK-02 | CSRF: valor de state falsificado | Verificação no banco de dados → state desconhecido rejeitado | 400 |
| ATK-03 | Reutilização de state usado | Não reutilizável após registrar `used_at` | 400 |
| ATK-04 | Reutilização de state legítimo por terceiro | Invalidado imediatamente após um uso | 400 |
| ATK-05 | Replay de authorization code | Registrado em `used_oauth_codes` | 400 |
| ATK-06 | Authorization code inválido | Mock do provedor retorna null | 401 |
| ATK-07 | Injeção de open redirect | Start não aceita redirect_uri | auth_url não contém domínio malicioso |
| ATK-08 | Reutilização de sessão após logout | `revoked_at` definido → findSession falha | 401 |
| ATK-09 | Token de sessão inválido | Verificação no banco de dados → token não registrado rejeitado | 401 |
| ATK-10 | Acesso a /me sem autenticação | Bearer não definido → 401 | 401 |
| ATK-11 | SQL injection no parâmetro state | Declaração preparada invalida | 400/422 |
| ATK-12 | /me com sessão de usuário diferente | Token vinculado a user_id | user.id diferente |

---

## Configuração de Testes

```
tests/
  OAuth/
    OAuthTest.php   — 10 testes funcionais
    AttackTest.php  — 12 testes de ataque cracker (ATK-01〜12)
```

Total de 22 testes / 36 asserções.

---

## Implementação de Referência

`../NENE2-FT/oauthlog/` — Field trial FT160 (22 testes + 12 testes de ataque cracker)
