# Exportação de Dados Pessoais

Uma exportação de dados no estilo GDPR permite que os usuários baixem todos os seus dados pessoais. As principais preocupações são: exclusão de campos sensíveis do payload de exportação, tokens de download seguros e aplicação de expiração.

## Componentes principais

- **Job de exportação**: um registro que vincula um usuário a um token de download opaco, com status (pending → ready) e um timestamp de expiração.
- **Etapa de processamento**: uma operação do lado do worker que constrói o payload e marca o job como pronto.
- **Download**: busca o payload pelo token, verificando a expiração antes de servir.

## Schema

```sql
CREATE TABLE data_exports (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    token      TEXT    NOT NULL UNIQUE,
    status     TEXT    NOT NULL DEFAULT 'pending',
    payload    TEXT,
    expires_at TEXT    NOT NULL,
    created_at TEXT    NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id)
);
```

## Geração do token

Use `bin2hex(random_bytes(32))` — 64 caracteres hex, 256 bits de entropia. IDs sequenciais, timestamps ou tokens baseados em MD5 são adivináveis e não devem ser usados para tokens de download.

```php
$token = bin2hex(random_bytes(32));
```

## Exclusão de campos sensíveis

O payload de exportação nunca deve conter credenciais ou campos para os quais o usuário não consentiu explicitamente a exportação. Exclua no nível do repositório, não na camada HTTP:

```php
public function processExport(string $token, User $user, array $activities, string $now): DataExport
{
    $payload = json_encode([
        'exported_at' => $now,
        'user' => [
            'id'         => $user->id,
            'email'      => $user->email,
            'name'       => $user->name,
            'created_at' => $user->createdAt,
            // password_hash intencionalmente excluído
            // phone intencionalmente excluído (requer reconsentimento para PII)
        ],
        'activities' => $activities,
    ], JSON_THROW_ON_ERROR);
    // ...
}
```

Aplique a mesma exclusão ao endpoint de perfil público — `phone`, `password_hash` e quaisquer campos internos não devem aparecer nas respostas de `GET /users/{id}` também.

## Aplicação da expiração

Aplique a expiração **em ambos** o endpoint de download e o endpoint de processamento:

```php
// Em downloadExport:
if ($export->isExpired($now)) {
    return $this->problems->create($request, 'gone', 'Export has expired.', 410, '');
}

// Em processExport — CRÍTICO: também verificar aqui
if ($export->isExpired($now)) {
    return $this->problems->create($request, 'gone', 'Export request has expired. Please request a new export.', 410, '');
}
```

Sem a verificação em `processExport`, um worker que recebe um job desatualizado escreveria os dados do usuário no banco mesmo após o fechamento da janela de download, criando registros órfãos com dados de payload sensíveis.

## Fluxo de status

```
pending ──(processamento chamado, não expirado)──▶ ready ──(download chamado)──▶ [payload servido]
   │                                                          │
   └──(processamento chamado, expirado)──▶ 410               └──(expirado)──▶ 410
```

## Download: 410 Gone vs 404 Not Found

- **404**: o token não existe no banco de dados.
- **410 Gone**: o token existe, mas expirou. Este é o status correto — o recurso existia e foi removido desde então. Os clientes podem usar esse sinal para solicitar ao usuário que faça uma nova exportação.

## Decisões de design

**Por que uma etapa `process` separada em vez de geração síncrona?**
Payloads de exportação podem ser grandes (anos de dados de atividade). A geração síncrona no handler HTTP corre risco de timeouts e ocupa um worker. O padrão assíncrono permite que o usuário solicite e verifique mais tarde. Para este FT, a etapa de processamento é exposta como uma API para simular a invocação do worker.

**Por que usar o token como URL de download em vez do ID de exportação?**
Um ID inteiro sequencial é vulnerável a IDOR — o usuário 1 poderia baixar a exportação do usuário 2 incrementando o ID. Um token aleatório opaco torna a URL de download impossível de adivinhar.

**A etapa `process` deve ser um endpoint público?**
Em produção, não. O endpoint de processamento deve ser chamado apenas por workers internos (via chave de API, rede interna ou fila). Neste FT está exposto para testabilidade. A entropia do token fornece alguma proteção, mas não é substituta para autenticação adequada de workers.
