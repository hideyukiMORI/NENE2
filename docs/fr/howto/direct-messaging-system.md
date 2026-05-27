# How-to : Construire un système de messagerie directe avec NENE2

> **Référence FT** : FT278 (`NENE2-FT/messagelog`) — Messagerie directe : threading de conversation, UNIQUE(initiator_id, recipient_id) + CHECK(initiator_id != recipient_id), contrôle d'accès participant uniquement, recherche agnostique au sens, démarrage de conversation idempotent, 31 tests / 96 assertions PASS.
>
> Aussi validé en FT135 — implémentation originale.

Ce guide décrit la construction d'un système de messages directs (DM) style Twitter/Instagram — les utilisateurs démarrent des conversations entre eux, envoient des messages, et seuls les participants peuvent lire ou envoyer dans une conversation.

**Version NENE2** : ^1.5
**Sujets couverts** : threading de conversation, contrôle d'accès participant, recherche de conversation agnostique au sens, démarrage de conversation idempotent

---

## Ce que nous construisons

Une API REST où :

- Deux utilisateurs quelconques peuvent démarrer une conversation (idempotent — redémarrer retourne l'existante)
- Seuls les participants peuvent envoyer des messages ou lire les messages d'une conversation
- Un utilisateur peut lister ses propres conversations (mais pas celles d'un autre utilisateur)
- Les messages sont ordonnés du plus ancien au plus récent dans une conversation

---

## Schéma de la base de données

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

La contrainte `UNIQUE (initiator_id, recipient_id)` impose une conversation par paire ordonnée. La couche applicative gère le sens inverse (Bob→Alice retourne la même conversation qu'Alice→Bob).

---

## Endpoints API

| Méthode | Chemin                                   | Description                                       |
|---------|------------------------------------------|---------------------------------------------------|
| POST    | `/users`                                 | Créer un utilisateur                              |
| POST    | `/conversations`                         | Démarrer une conversation (idempotent)            |
| POST    | `/conversations/{id}/messages`           | Envoyer un message (participants uniquement)      |
| GET     | `/conversations/{id}/messages`           | Lire les messages (participants uniquement, X-User-Id) |
| GET     | `/users/{userId}/conversations`          | Lister les conversations d'un utilisateur (soi uniquement, X-User-Id) |

---

## Recherche de conversation agnostique au sens

Le défi clé : Alice démarre une conversation avec Bob (`initiator=Alice, recipient=Bob`). Plus tard, Bob en démarre aussi une avec Alice. Ils devraient obtenir la même conversation, pas deux séparées.

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

## Vérification de participant

Avant de lire les messages ou d'envoyer, vérifier que l'appelant est dans la conversation :

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

## Identité de l'acteur — en-tête X-User-Id

Les endpoints protégés utilisent un en-tête simple `X-User-Id` pour identifier l'appelant. Les systèmes en production utiliseraient plutôt un claim JWT.

```php
private function resolveActorId(ServerRequestInterface $request): int
{
    $header = $request->getHeaderLine('X-User-Id');

    return is_numeric($header) ? (int) $header : 0;
}
```

**Note** : `is_numeric()` retourne false pour les chaînes non numériques, donc `X-User-Id: admin` → `actorId = 0` → 404.

---

## Gestionnaire d'envoi de message

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

**Ordre des vérifications** : la conversation existe → l'expéditeur existe → l'expéditeur est participant → contenu valide. Les vérifications d'existence avant les vérifications d'accès empêchent la fuite d'informations sur les IDs de conversation.

---

## Gestionnaire de lecture de messages — GET sans corps

Pour les endpoints GET qui nécessitent une identité (`listMessages`, `listUserConversations`), l'acteur vient de l'en-tête `X-User-Id`. **Ne pas appeler `JsonRequestBodyParser::parse()` sur les requêtes GET** — il retourne 400 car les requêtes GET n'ont pas de corps JSON.

```php
private function listMessages(ServerRequestInterface $request): ResponseInterface
{
    $params         = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
    $conversationId = isset($params['conversationId']) && is_numeric($params['conversationId'])
        ? (int) $params['conversationId'] : 0;

    if ($conversationId <= 0 || $this->repo->findConversationById($conversationId) === null) {
        return $this->responseFactory->create(['error' => 'conversation not found'], 404);
    }

    // Pas de JsonRequestBodyParser::parse() ici — l'acteur vient uniquement de l'en-tête
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

## Ordre des messages

Les messages utilisent `ORDER BY id ASC` — le plus ancien en premier, correspondant aux conventions UI de chat. Les listes follow/notification utilisent `ORDER BY id DESC` (le plus récent en premier). Choisir en fonction des attentes de l'interface.

---

## Évaluation des vulnérabilités (FT135)

Douze tests de vulnérabilité vérifient :

| ID | Attaque | Attendu | Résultat |
|----|---------|---------|---------|
| VULN-A | Lire les messages de la conversation d'un autre utilisateur (IDOR) | 403 | Pass |
| VULN-B | Envoyer un message à une conversation dont on ne fait pas partie (IDOR) | 403 | Pass |
| VULN-C | Lire la liste de conversation d'un autre utilisateur (IDOR) | 403 | Pass |
| VULN-D | X-User-Id manquant sur list messages | 404/403 | Pass |
| VULN-E | X-User-Id manquant sur liste de conversation | 403 | Pass |
| VULN-F | ID utilisateur négatif dans le chemin | 404 | Pass |
| VULN-G | ID de conversation zéro dans le chemin | 404 | Pass |
| VULN-H | En-tête X-User-Id non numérique | pas 200 | Pass |
| VULN-I | Injection SQL dans le contenu du message | 201 (stocké verbatim) | Pass |
| VULN-J | XSS dans le contenu du message | 201 (stocké verbatim) | Pass |
| VULN-K | Tentative d'auto-conversation | 422 | Pass |
| VULN-L | Contenu de message 100 Ko | 201 ou 413 | Pass |

Les 12 tests de vulnérabilité passent. Aucune vulnérabilité trouvée.

---

## Pièges courants

| Piège | Correction |
|-------|-----------|
| Appeler `JsonRequestBodyParser::parse()` sur les requêtes GET | L'appeler uniquement pour les gestionnaires POST/PUT/PATCH qui attendent un corps |
| `UNIQUE (initiator_id, recipient_id)` n'empêche pas A→B et B→A comme deux conversations | Chercher de façon agnostique au sens avec une requête OR avant INSERT |
| Vérifier le participant après la validité du contenu | Vérifier le participant *avant* le contenu pour éviter de fuiter des infos |
| Accepter tout entier non zéro comme actorID sans vérifier l'existence de l'utilisateur | Toujours vérifier `findUserById(actorId)` avant de vérifier la participation |

---

## Ce qu'il ne faut PAS faire

| Anti-pattern | Risque |
|---|---|
| Stocker les conversations comme `(user_a, user_b)` avec sens — deux lignes séparées pour A→B et B→A | Les deux mêmes utilisateurs accumulent des conversations en double ; la recherche agnostique au sens échoue |
| Pas de contrainte `CHECK (initiator_id != recipient_id)` | Les utilisateurs peuvent se envoyer des messages à eux-mêmes, créant des auto-conversations confuses |
| Pas de contrainte `UNIQUE (initiator_id, recipient_id)` | Les requêtes de démarrage de conversation concurrentes créent des lignes en double pour la même paire |
| Retourner 404 au lieu de 403 sur l'accès non-participant | Révèle l'existence de l'ID de conversation aux non-participants |
| Appeler `JsonRequestBodyParser::parse()` sur GET `/conversations/{id}/messages` | Les requêtes GET n'ont pas de corps ; le parser retourne 400 |
| Vérifier la validité du contenu avant la vérification du participant | Fuite d'informations — l'attaquant peut sonder les IDs de conversation valides en envoyant du contenu vide et en observant 403 vs 422 |
| Utiliser `is_numeric()` sans cast vers `int` puis `> 0` | `is_numeric("0")` est true ; l'ID utilisateur 0 serait traité comme valide |
| Sauter la vérification d'existence utilisateur après la vérification de participant | `isParticipant()` vérifie uniquement les FK — les utilisateurs supprimés ou inexistants peuvent toujours apparaître si la DB n'a pas de cascade |
| Autoriser n'importe quel utilisateur à lister les conversations d'un autre | IDOR — toujours vérifier `actorId === targetUserId` avant de retourner la liste de conversations |
| Indexer uniquement sur `conversation_id` pour les messages | Index `id ASC` manquant cause un ORDER BY lent sur de grands historiques de messages |
