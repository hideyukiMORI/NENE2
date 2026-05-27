# Como Construir um Sistema de Mensagens Diretas com NENE2

> **Referência FT**: FT278 (`NENE2-FT/messagelog`) — Mensagens diretas: threading de conversa, UNIQUE(initiator_id, recipient_id) + CHECK(initiator_id != recipient_id), controle de acesso somente para participantes, lookup agnóstico de direção, início de conversa idempotente, 31 testes / 96 asserções PASS.
>
> Também validado em FT135 — implementação original.

Este guia percorre a construção de um sistema de mensagens diretas (DM) estilo Twitter/Instagram — usuários iniciam conversas entre si, enviam mensagens, e apenas participantes podem ler ou enviar em uma conversa.

**Versão do NENE2**: ^1.5  
**Tópicos abordados**: threading de conversa, controle de acesso por participante, lookup de conversa agnóstico de direção, início de conversa idempotente

---

## O que estamos construindo

Uma API REST onde:

- Quaisquer dois usuários podem iniciar uma conversa (idempotente — reiniciar retorna a existente)
- Apenas participantes podem enviar mensagens ou ler as mensagens de uma conversa
- Um usuário pode listar suas próprias conversas (mas não as de outro usuário)
- Mensagens são ordenadas do mais antigo para o mais recente dentro de uma conversa

---

## Schema do banco de dados

```sql
CREATE TABLE users (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT NOT NULL,
    created_at TEXT NOT NULL
);

CREATE TABLE conversations (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    initiator_id INTEGER NOT NULL,
    recipient_id INTEGER NOT NULL,
    created_at   TEXT    NOT NULL,
    UNIQUE (initiator_id, recipient_id),
    CHECK  (initiator_id != recipient_id),
    FOREIGN KEY (initiator_id) REFERENCES users(id),
    FOREIGN KEY (recipient_id) REFERENCES users(id)
);

CREATE TABLE messages (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    conversation_id INTEGER NOT NULL,
    sender_id       INTEGER NOT NULL,
    content         TEXT    NOT NULL,
    created_at      TEXT    NOT NULL,
    FOREIGN KEY (conversation_id) REFERENCES conversations(id),
    FOREIGN KEY (sender_id)       REFERENCES users(id)
);
```

A constraint `UNIQUE (initiator_id, recipient_id)` aplica uma conversa por par ordenado. A camada de aplicação lida com a direção inversa (Bob→Alice retorna a mesma conversa que Alice→Bob).

---

## Endpoints da API

| Método | Caminho                               | Descrição                                    |
|--------|---------------------------------------|----------------------------------------------|
| POST   | `/users`                              | Criar um usuário                             |
| POST   | `/conversations`                      | Iniciar uma conversa (idempotente)           |
| POST   | `/conversations/{id}/messages`        | Enviar uma mensagem (somente participantes)  |
| GET    | `/conversations/{id}/messages`        | Ler mensagens (somente participantes, X-User-Id) |
| GET    | `/users/{userId}/conversations`       | Listar conversas do usuário (somente próprio, X-User-Id) |

---

## Lookup de conversa agnóstico de direção

O desafio principal: Alice inicia uma conversa com Bob (`initiator=Alice, recipient=Bob`). Mais tarde Bob também inicia uma com Alice. Eles devem obter a mesma conversa, não duas separadas.

```php
public function findConversation(int $userA, int $userB): ?int
{
    $row = $this->executor->fetchOne(
        'SELECT id FROM conversations
         WHERE (initiator_id = ? AND recipient_id = ?)
            OR (initiator_id = ? AND recipient_id = ?)',
        [$userA, $userB, $userB, $userA],
    );

    if ($row === null) {
        return null;
    }

    $arr = (array) $row;

    return isset($arr['id']) ? (int) $arr['id'] : null;
}

public function findOrCreateConversation(int $initiatorId, int $recipientId, string $now): int
{
    $existing = $this->findConversation($initiatorId, $recipientId);

    if ($existing !== null) {
        return $existing;
    }

    $this->executor->execute(
        'INSERT INTO conversations (initiator_id, recipient_id, created_at) VALUES (?, ?, ?)',
        [$initiatorId, $recipientId, $now],
    );

    return (int) $this->executor->lastInsertId();
}
```

---

## Verificação de participante

Antes de ler mensagens ou enviar, verifique se o chamador está na conversa:

```php
public function isParticipant(int $conversationId, int $userId): bool
{
    return $this->executor->fetchOne(
        'SELECT id FROM conversations
         WHERE id = ? AND (initiator_id = ? OR recipient_id = ?)',
        [$conversationId, $userId, $userId],
    ) !== null;
}
```

---

## Identidade do ator — header X-User-Id

Endpoints protegidos usam um header simples `X-User-Id` para identificar o chamador. Sistemas de produção usariam um claim de JWT.

```php
private function resolveActorId(ServerRequestInterface $request): int
{
    $header = $request->getHeaderLine('X-User-Id');

    return is_numeric($header) ? (int) $header : 0;
}
```

**Nota**: `is_numeric()` retorna false para strings não numéricas, então `X-User-Id: admin` → `actorId = 0` → 404.

---

## Handler de envio de mensagem

```php
private function sendMessage(ServerRequestInterface $request): ResponseInterface
{
    $params         = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
    $conversationId = isset($params['conversationId']) && is_numeric($params['conversationId'])
        ? (int) $params['conversationId'] : 0;

    if ($conversationId <= 0 || $this->repo->findConversationById($conversationId) === null) {
        return $this->responseFactory->create(['error' => 'conversation not found'], 404);
    }

    $body     = JsonRequestBodyParser::parse($request);
    $senderId = isset($body['sender_id']) && is_int($body['sender_id']) ? $body['sender_id'] : 0;
    $content  = isset($body['content']) && is_string($body['content']) ? trim($body['content']) : '';

    if ($senderId <= 0 || !$this->repo->findUserById($senderId)) {
        return $this->responseFactory->create(['error' => 'sender not found'], 404);
    }

    if (!$this->repo->isParticipant($conversationId, $senderId)) {
        return $this->responseFactory->create(['error' => 'not a participant'], 403);
    }

    if ($content === '') {
        return $this->responseFactory->create(['error' => 'content is required'], 422);
    }

    $now       = date('Y-m-d H:i:s');
    $messageId = $this->repo->sendMessage($conversationId, $senderId, $content, $now);

    return $this->responseFactory->create([...], 201);
}
```

**Ordem das verificações**: conversa existe → remetente existe → remetente é participante → conteúdo válido. Verificações de existência antes de verificações de acesso previnem vazamento de informações sobre IDs de conversa.

---

## Handler de leitura de mensagens — GET sem corpo

Para endpoints GET que requerem identidade (`listMessages`, `listUserConversations`), o ator vem do header `X-User-Id`. **Não chame `JsonRequestBodyParser::parse()` em requisições GET** — ele retorna 400 porque requisições GET não têm corpo JSON.

```php
private function listMessages(ServerRequestInterface $request): ResponseInterface
{
    $params         = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
    $conversationId = isset($params['conversationId']) && is_numeric($params['conversationId'])
        ? (int) $params['conversationId'] : 0;

    if ($conversationId <= 0 || $this->repo->findConversationById($conversationId) === null) {
        return $this->responseFactory->create(['error' => 'conversation not found'], 404);
    }

    // Sem JsonRequestBodyParser::parse() aqui — ator vem somente do header
    $actorId = $this->resolveActorId($request);

    if ($actorId <= 0 || !$this->repo->findUserById($actorId)) {
        return $this->responseFactory->create(['error' => 'actor not found'], 404);
    }

    if (!$this->repo->isParticipant($conversationId, $actorId)) {
        return $this->responseFactory->create(['error' => 'not a participant'], 403);
    }

    $messages = $this->repo->listMessages($conversationId);

    return $this->responseFactory->create(['items' => $messages, 'count' => count($messages)]);
}
```

---

## Ordenação de mensagens

Mensagens usam `ORDER BY id ASC` — mais antigo primeiro, correspondendo às convenções de UI de chat. Listas de seguimento/notificação usam `ORDER BY id DESC` (mais recente primeiro). Escolha com base na expectativa da UI.

---

## Avaliação de vulnerabilidades (FT135)

Doze testes de vulnerabilidade verificam:

| ID | Ataque | Esperado | Resultado |
|----|--------|---------|-----------|
| VULN-A | Ler mensagens da conversa de outro usuário (IDOR) | 403 | Pass |
| VULN-B | Enviar mensagem para conversa da qual não faz parte (IDOR) | 403 | Pass |
| VULN-C | Ler lista de conversas de outro usuário (IDOR) | 403 | Pass |
| VULN-D | X-User-Id ausente em list messages | 404/403 | Pass |
| VULN-E | X-User-Id ausente em conversation list | 403 | Pass |
| VULN-F | ID de usuário negativo no caminho | 404 | Pass |
| VULN-G | ID de conversa zero no caminho | 404 | Pass |
| VULN-H | Header X-User-Id não numérico | não 200 | Pass |
| VULN-I | SQL injection no conteúdo da mensagem | 201 (armazenado verbatim) | Pass |
| VULN-J | XSS no conteúdo da mensagem | 201 (armazenado verbatim) | Pass |
| VULN-K | Tentativa de auto-conversa | 422 | Pass |
| VULN-L | Conteúdo de mensagem de 100KB | 201 ou 413 | Pass |

Todos os 12 testes de vulnerabilidade passam. Nenhuma vulnerabilidade encontrada.

---

## Armadilhas comuns

| Armadilha | Correção |
|-----------|----------|
| Chamar `JsonRequestBodyParser::parse()` em requisições GET | Chamar apenas para handlers POST/PUT/PATCH que esperam um corpo |
| `UNIQUE (initiator_id, recipient_id)` não previne A→B e B→A como duas conversas | Fazer lookup agnóstico de direção com consulta OR antes do INSERT |
| Verificar participante após verificar validade do conteúdo | Verificar participante *antes* do conteúdo para evitar vazamento de informações |
| Aceitar qualquer inteiro não-zero como actor ID sem verificar existência do usuário | Sempre verificar `findUserById(actorId)` antes de verificar participação |

---

## O Que NÃO Fazer

| Anti-padrão | Risco |
|---|---|
| Armazenar conversas como `(user_a, user_b)` com direção — duas linhas separadas para A→B e B→A | Dois usuários acumulam conversas duplicadas; lookup agnóstico de direção falha |
| Sem constraint `CHECK (initiator_id != recipient_id)` | Usuários podem enviar mensagens para si mesmos, criando auto-conversas confusas |
| Sem constraint `UNIQUE (initiator_id, recipient_id)` | Requisições concorrentes de início de conversa criam linhas duplicadas para o mesmo par |
| Retornar 404 em vez de 403 em acesso de não-participante | Revela existência do ID de conversa para não-participantes |
| Chamar `JsonRequestBodyParser::parse()` em GET `/conversations/{id}/messages` | Requisições GET não têm corpo; parser retorna 400 |
| Verificar validade do conteúdo antes da verificação de participante | Vaza informação — atacante pode sondar IDs de conversa válidos enviando conteúdo vazio e observando 403 vs 422 |
| Usar `is_numeric()` sem cast para `int` depois `> 0` | `is_numeric("0")` é true; user ID 0 seria tratado como válido |
| Pular verificação de existência de usuário após verificação de participante | `isParticipant()` apenas verifica FK — usuários deletados ou inexistentes podem ainda aparecer se o BD não tiver cascade |
| Permitir que qualquer usuário liste as conversas de outro usuário | IDOR — sempre verificar `actorId === targetUserId` antes de retornar lista de conversas |
| Índice apenas em `conversation_id` para mensagens | Faltando `id ASC` no índice causa ORDER BY lento em históricos de mensagens grandes |
