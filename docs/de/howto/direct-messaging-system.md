# Ein Direktnachrichten-System mit NENE2 aufbauen

> **FT-Referenz**: FT278 (`NENE2-FT/messagelog`) — Direktnachrichten: Konversations-Threading, UNIQUE(initiator_id, recipient_id) + CHECK(initiator_id != recipient_id), Teilnehmer-Zugriffskontrolle, richtungsunabhängige Suche, idempotenter Konversationsstart, 31 Tests / 96 Assertions bestanden.
>
> Auch in FT135 validiert — ursprüngliche Implementierung.

Diese Anleitung führt durch den Aufbau eines Twitter/Instagram-artigen Direktnachrichten-Systems (DM) — Benutzer starten Konversationen miteinander, senden Nachrichten, und nur Teilnehmer können Nachrichten in einer Konversation lesen oder senden.

**NENE2-Version**: ^1.5  
**Behandelte Themen**: Konversations-Threading, Teilnehmer-Zugriffskontrolle, richtungsunabhängige Konversationssuche, idempotenter Konversationsstart

---

## Was wir bauen

Eine REST-API, bei der:

- Zwei beliebige Benutzer eine Konversation starten können (idempotent — ein erneuter Start gibt die vorhandene zurück)
- Nur Teilnehmer Nachrichten senden oder die Nachrichten einer Konversation lesen können
- Ein Benutzer seine eigenen Konversationen auflisten kann (aber nicht die eines anderen Benutzers)
- Nachrichten innerhalb einer Konversation von ältestem zum neuesten sortiert sind

---

## Datenbankschema

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

Die `UNIQUE (initiator_id, recipient_id)`-Constraint erzwingt eine Konversation pro geordnetem Paar. Die Anwendungsschicht behandelt die umgekehrte Richtung (Bob→Alice gibt dieselbe Konversation zurück wie Alice→Bob).

---

## API-Endpunkte

| Methode | Pfad | Beschreibung |
|--------|------|-------------|
| POST   | `/users` | Einen Benutzer erstellen |
| POST   | `/conversations` | Eine Konversation starten (idempotent) |
| POST   | `/conversations/{id}/messages` | Eine Nachricht senden (nur Teilnehmer) |
| GET    | `/conversations/{id}/messages` | Nachrichten lesen (nur Teilnehmer, X-User-Id) |
| GET    | `/users/{userId}/conversations` | Konversationen des Benutzers auflisten (nur selbst, X-User-Id) |

---

## Richtungsunabhängige Konversationssuche

Die Kernherausforderung: Alice startet eine Konversation mit Bob (`initiator=Alice, recipient=Bob`). Später startet Bob auch eine mit Alice. Sie sollten dieselbe Konversation bekommen, nicht zwei getrennte.

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

## Teilnehmerprüfung

Vor dem Lesen von Nachrichten oder dem Senden prüfen, ob der Aufrufer an der Konversation teilnimmt:

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

## Akteur-Identität — X-User-Id-Header

Geschützte Endpunkte verwenden einen einfachen `X-User-Id`-Header zur Identifikation des Aufrufers. Produktionssysteme würden stattdessen einen JWT-Claim verwenden.

```php
private function resolveActorId(ServerRequestInterface $request): int
{
    $header = $request->getHeaderLine('X-User-Id');

    return is_numeric($header) ? (int) $header : 0;
}
```

**Hinweis**: `is_numeric()` gibt false für nicht-numerische Strings zurück, daher führt `X-User-Id: admin` zu `actorId = 0` → 404.

---

## Nachricht-Senden-Handler

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

**Reihenfolge der Prüfungen**: Konversation existiert → Absender existiert → Absender ist Teilnehmer → Inhalt gültig. Existenzprüfungen vor Zugriffsprüfungen verhindern Informationslecks über Konversations-IDs.

---

## Nachrichten-Lesen-Handler — GET ohne Body

Für GET-Endpunkte, die Identität erfordern (`listMessages`, `listUserConversations`), kommt der Akteur aus dem `X-User-Id`-Header. **`JsonRequestBodyParser::parse()` darf nicht auf GET-Anfragen aufgerufen werden** — es gibt 400 zurück, weil GET-Anfragen keinen JSON-Body haben.

```php
private function listMessages(ServerRequestInterface $request): ResponseInterface
{
    $params         = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
    $conversationId = isset($params['conversationId']) && is_numeric($params['conversationId'])
        ? (int) $params['conversationId'] : 0;

    if ($conversationId <= 0 || $this->repo->findConversationById($conversationId) === null) {
        return $this->responseFactory->create(['error' => 'conversation not found'], 404);
    }

    // Hier kein JsonRequestBodyParser::parse() — Akteur kommt nur aus dem Header
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

## Nachrichten-Sortierung

Nachrichten verwenden `ORDER BY id ASC` — älteste zuerst, entsprechend Chat-UI-Konventionen. Follow-/Benachrichtigungslisten verwenden `ORDER BY id DESC` (neueste zuerst). Die Wahl hängt von der UI-Erwartung ab.

---

## Schwachstellenbewertung (FT135)

Zwölf Schwachstellentests verifizieren:

| ID | Angriff | Erwartet | Ergebnis |
|----|---------|----------|---------|
| VULN-A | Nachrichten aus einer fremden Konversation lesen (IDOR) | 403 | Bestanden |
| VULN-B | Nachricht in eine Konversation senden, an der man nicht teilnimmt (IDOR) | 403 | Bestanden |
| VULN-C | Konversationsliste eines anderen Benutzers lesen (IDOR) | 403 | Bestanden |
| VULN-D | Fehlender X-User-Id bei Nachrichtenliste | 404/403 | Bestanden |
| VULN-E | Fehlender X-User-Id bei Konversationsliste | 403 | Bestanden |
| VULN-F | Negative Benutzer-ID im Pfad | 404 | Bestanden |
| VULN-G | Null-Konversations-ID im Pfad | 404 | Bestanden |
| VULN-H | Nicht-numerischer X-User-Id-Header | nicht 200 | Bestanden |
| VULN-I | SQL-Injection im Nachrichteninhalt | 201 (unverändert gespeichert) | Bestanden |
| VULN-J | XSS im Nachrichteninhalt | 201 (unverändert gespeichert) | Bestanden |
| VULN-K | Selbst-Konversationsversuch | 422 | Bestanden |
| VULN-L | 100 KB Nachrichteninhalt | 201 oder 413 | Bestanden |

Alle 12 Schwachstellentests bestanden. Keine Schwachstellen gefunden.

---

## Häufige Fallstricke

| Fallstrick | Lösung |
|---------|-----|
| `JsonRequestBodyParser::parse()` auf GET-Anfragen aufrufen | Nur für POST/PUT/PATCH-Handler aufrufen, die einen Body erwarten |
| `UNIQUE (initiator_id, recipient_id)` verhindert nicht A→B und B→A als zwei Konversationen | Richtungsunabhängig mit OR-Abfrage vor INSERT suchen |
| Teilnehmer nach Inhaltsvalidierung prüfen | Teilnehmer *vor* Inhalt prüfen, um Informationslecks zu vermeiden |
| Jede Nicht-Null-Ganzzahl als Akteur-ID ohne Benutzerexistenzprüfung akzeptieren | Immer `findUserById(actorId)` vor der Teilnahmeprüfung verifizieren |

---

## Was man NICHT tun sollte

| Anti-Muster | Risiko |
|---|---|
| Konversationen als `(user_a, user_b)` mit Richtung speichern — zwei getrennte Zeilen für A→B und B→A | Dieselben zwei Benutzer akkumulieren doppelte Konversationen; richtungsunabhängige Suche schlägt fehl |
| Kein `CHECK (initiator_id != recipient_id)`-Constraint | Benutzer können sich selbst Nachrichten schicken, was verwirrende Selbst-Konversationen erzeugt |
| Kein `UNIQUE (initiator_id, recipient_id)`-Constraint | Gleichzeitige Konversationsstart-Anfragen erstellen doppelte Zeilen für dasselbe Paar |
| 404 statt 403 bei Nicht-Teilnehmer-Zugriff zurückgeben | Verrät die Existenz der Konversations-ID an Nicht-Teilnehmer |
| `JsonRequestBodyParser::parse()` bei GET `/conversations/{id}/messages` aufrufen | GET-Anfragen haben keinen Body; der Parser gibt 400 zurück |
| Inhaltsvalidierung vor Teilnehmerprüfung | Informationsleck — Angreifer kann gültige Konversations-IDs sondieren, indem leerer Inhalt gesendet und 403 vs. 422 beobachtet wird |
| `is_numeric()` ohne Cast in `int` und dann `> 0` verwenden | `is_numeric("0")` ist true; Benutzer-ID 0 würde als gültig behandelt |
| Benutzerexistenzprüfung nach Teilnehmerprüfung überspringen | `isParticipant()` prüft nur FK — gelöschte oder nicht vorhandene Benutzer können noch erscheinen, wenn die DB kein Cascade hat |
| Jedem Benutzer erlauben, die Konversationen eines anderen Benutzers aufzulisten | IDOR — immer `actorId === targetUserId` vor der Rückgabe der Konversationsliste verifizieren |
| Nur nach `conversation_id` für Nachrichten indexieren | Fehlender `id ASC`-Index verursacht langsames ORDER BY bei großen Nachrichtenhistorien |
