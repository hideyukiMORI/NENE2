# Como Fazer: API de Exportação de Dados

> **Referência FT**: FT312 (`NENE2-FT/exportlog`) — Exportação de dados (estilo GDPR): máquina de estados assíncrona `pending→ready` via download baseado em token, exclusão de PII via `toPublicArray()` (password_hash e phone nunca na resposta GET nem no payload de exportação), hash de senha ARGON2ID, token de exportação de 64 caracteres hex, 410 Gone para exportações expiradas, 409 para tentativa de download pendente, 19 testes / 32 asserções PASS.

Este guia mostra como construir um sistema de exportação de dados de usuário (portabilidade do Artigo 20 do GDPR) onde as exportações são assíncronas, protegidas por tokens, e campos sensíveis de PII nunca são vazados.

## Schema

```sql
CREATE TABLE users (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    email         TEXT    NOT NULL UNIQUE,
    name          TEXT    NOT NULL,
    phone         TEXT    NOT NULL DEFAULT '',
    password_hash TEXT    NOT NULL,
    created_at    TEXT    NOT NULL
);

CREATE TABLE data_exports (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    token      TEXT    NOT NULL UNIQUE,  -- 64 chars hex
    status     TEXT    NOT NULL DEFAULT 'pending',
    payload    TEXT,                     -- JSON, definido quando status='ready'
    expires_at TEXT    NOT NULL,
    created_at TEXT    NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id)
);
```

`token` é uma string hex de 64 caracteres para a URL de download. `payload` é nulo até a exportação ser processada.

## Endpoints

| Método | Caminho | Descrição |
|--------|---------|-----------|
| `POST` | `/users` | Registrar usuário |
| `GET` | `/users/{id}` | Obter usuário (PII excluída) |
| `POST` | `/users/{id}/export` | Solicitar exportação de dados → 202 |
| `POST` | `/exports/{token}/process` | Processar exportação (worker assíncrono) |
| `GET` | `/exports/{token}` | Baixar exportação concluída |

## Exclusão de PII — toPublicArray()

```php
final class User
{
    public function toPublicArray(): array
    {
        return [
            'id'         => $this->id,
            'email'      => $this->email,
            'name'       => $this->name,
            'created_at' => $this->createdAt,
            // phone e password_hash intencionalmente excluídos da visão pública
        ];
    }
}
```

A resposta de `GET /users/{id}` chama `toPublicArray()` — nunca o array completo. `phone` e `password_hash` são armazenados mas nunca retornados via API.

A mesma exclusão se aplica ao payload de exportação: a exportação é construída a partir de `toPublicArray()` (ou equivalente), não de uma linha bruta do BD.

## Hash de Senha — ARGON2ID

```php
$passwordHash = password_hash($password, PASSWORD_ARGON2ID);
```

ARGON2ID é o algoritmo moderno recomendado (resistente a memória, resistente a ataques com GPU). `PASSWORD_BCRYPT` é aceitável mas mais fraco contra cracking com GPU.

## Exportação Assíncrona — pending → ready

```
POST /users/{id}/export  →  202 Accepted
  → cria linha data_exports: status='pending', token='<64hex>'

POST /exports/{token}/process  →  200 OK
  → constrói payload, define status='ready'

GET /exports/{token}  →  200 OK (download)
  → retorna payload se status='ready'
```

**Geração do token de exportação:**
```php
$token = bin2hex(random_bytes(32)); // 64 chars hex
```

**Handler de processamento:**
```php
if ($export->status === 'ready') {
    return 200; // já processado, idempotente
}
if ($export->expiresAt < date('c')) {
    return 410; // expirado — não processar
}
// Construir e armazenar payload
$this->repo->markReady($export->token, json_encode($export->user->toPublicArray()));
```

## Verificações de Status — 409 e 410

```php
// Handler de download
if ($export->expiresAt < date('c')) {
    return $this->problems->create($request, 'gone', 'Export has expired.', 410, '');
}

if ($export->status !== 'ready') {
    return $this->problems->create($request, 'conflict', 'Export is not yet ready.', 409, '');
}
```

| Estado | Resposta ao download |
|--------|----------------------|
| `pending` | 409 Conflict |
| `ready` (não expirado) | 200 OK com payload |
| `ready` (expirado) | 410 Gone |

410 Gone é usado para recursos expirados (GDPR: dados de exportação não devem persistir indefinidamente).

---

## O Que NÃO Fazer

| Anti-padrão | Risco |
|---|---|
| Incluir `password_hash` na resposta GET | Hash de senha exposto; permite cracking offline |
| Incluir `phone` na resposta GET sem auth | Vazamento de PII; números de telefone expostos a qualquer um que conheça o ID do usuário |
| Incluir `password_hash` no payload de exportação | Violação do GDPR; exportação é um documento de portabilidade de dados voltado ao usuário |
| Usar `PASSWORD_MD5` ou `PASSWORD_DEFAULT` | Hash de senha fraco; migre para ARGON2ID |
| Retornar 404 para exportações expiradas (não 410) | 404 oculta a distinção entre "nunca existiu" e "expirou" |
| Retornar 200 para download pendente | Cliente pensa que a exportação está pronta; recebe payload vazio ou quebrado |
| Token de exportação curto (< 64 chars) | Token adivinhável; qualquer pessoa pode baixar a exportação de qualquer usuário |
| Sem `expires_at` nas exportações | Exportações persistem indefinidamente; problema de conformidade com GDPR |
