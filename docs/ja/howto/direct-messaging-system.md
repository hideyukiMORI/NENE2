# NENE2 でダイレクトメッセージシステムを構築する方法

> **FT リファレンス**: FT278 (`NENE2-FT/messagelog`) — ダイレクトメッセージ: 会話スレッディング、UNIQUE(initiator_id, recipient_id) + CHECK(initiator_id != recipient_id)、参加者のみのアクセス制御、方向非依存のルックアップ、冪等な会話開始、31 テスト / 96 アサーション PASS。
>
> FT135 でも検証済み — 元の実装。

このガイドでは、Twitter/Instagram スタイルのダイレクトメッセージ（DM）システムを構築します — ユーザーが互いに会話を開始し、メッセージを送信し、参加者のみが会話を読んだり送信したりできます。

**NENE2 バージョン**: ^1.5  
**カバートピック**: 会話スレッディング、参加者アクセス制御、方向非依存の会話ルックアップ、冪等な会話開始

---

## 構築するもの

REST API で:

- 任意の 2 ユーザーが会話を開始できる（冪等 — 再開始すると既存の会話を返す）
- 参加者のみがメッセージを送信したり会話のメッセージを読んだりできる
- ユーザーは自分の会話を一覧表示できる（他のユーザーのはできない）
- メッセージは会話内で最古順に並べられる

---

## データベーススキーマ

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

`UNIQUE (initiator_id, recipient_id)` 制約は順序付きペアごとに 1 つの会話を強制します。アプリケーション層は逆方向（Bob→Alice は Alice→Bob と同じ会話を返す）を処理します。

---

## API エンドポイント

| メソッド | パス | 説明 |
|--------|----------------------------------------|----------------------------------------------|
| POST   | `/users`                               | ユーザーを作成する |
| POST   | `/conversations`                       | 会話を開始する（冪等） |
| POST   | `/conversations/{id}/messages`         | メッセージを送信する（参加者のみ） |
| GET    | `/conversations/{id}/messages`         | メッセージを読む（参加者のみ、X-User-Id） |
| GET    | `/users/{userId}/conversations`        | ユーザーの会話を一覧表示する（自分のみ、X-User-Id） |

---

## 方向非依存の会話ルックアップ

主な課題: Alice が Bob との会話を開始します（`initiator=Alice, recipient=Bob`）。その後 Bob も Alice との会話を開始します。2 つの別々の会話ではなく、同じ会話が返る必要があります。

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

## 参加者チェック

メッセージを読んだり送信したりする前に、呼び出し元が会話に参加していることを確認します:

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

## アクター識別 — X-User-Id ヘッダー

保護されたエンドポイントは呼び出し元を識別するシンプルな `X-User-Id` ヘッダーを使用します。本番システムでは代わりに JWT クレームを使用します。

```php
private function resolveActorId(ServerRequestInterface $request): int
{
    $header = $request->getHeaderLine('X-User-Id');

    return is_numeric($header) ? (int) $header : 0;
}
```

**注意**: `is_numeric()` は非数値文字列に対して false を返します。`X-User-Id: admin` → `actorId = 0` → 404。

---

## メッセージ送信ハンドラー

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

**チェックの順序**: 会話が存在する → 送信者が存在する → 送信者が参加者 → コンテンツが有効。アクセスチェックの前に存在チェックを行うことで会話 ID に関する情報漏洩を防ぎます。

---

## メッセージ読み取りハンドラー — ボディなしの GET

アイデンティティを必要とする GET エンドポイント（`listMessages`、`listUserConversations`）では、アクターは `X-User-Id` ヘッダーから取得します。**GET リクエストで `JsonRequestBodyParser::parse()` を呼び出さないでください** — GET リクエストには JSON ボディがないため 400 を返します。

```php
private function listMessages(ServerRequestInterface $request): ResponseInterface
{
    $params         = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
    $conversationId = isset($params['conversationId']) && is_numeric($params['conversationId'])
        ? (int) $params['conversationId'] : 0;

    if ($conversationId <= 0 || $this->repo->findConversationById($conversationId) === null) {
        return $this->responseFactory->create(['error' => 'conversation not found'], 404);
    }

    // ここで JsonRequestBodyParser::parse() は呼ばない — アクターはヘッダーのみから取得
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

## メッセージの順序

メッセージは `ORDER BY id ASC` を使用します — 最古順、チャット UI の慣例に合わせています。フォロー/通知リストは `ORDER BY id DESC`（最新順）を使用します。UI の期待に基づいて選択してください。

---

## 脆弱性評価（FT135）

12 の脆弱性テストで検証:

| ID | 攻撃 | 期待される結果 | 実際の結果 |
|----|--------|----------|--------|
| VULN-A | 他のユーザーの会話からメッセージを読む（IDOR） | 403 | Pass |
| VULN-B | 参加していない会話にメッセージを送る（IDOR） | 403 | Pass |
| VULN-C | 他のユーザーの会話リストを読む（IDOR） | 403 | Pass |
| VULN-D | listMessages で X-User-Id が欠落 | 404/403 | Pass |
| VULN-E | 会話リストで X-User-Id が欠落 | 403 | Pass |
| VULN-F | パスの負のユーザー ID | 404 | Pass |
| VULN-G | パスのゼロの会話 ID | 404 | Pass |
| VULN-H | 非数値の X-User-Id ヘッダー | 200 以外 | Pass |
| VULN-I | メッセージコンテンツの SQL インジェクション | 201（そのまま保存） | Pass |
| VULN-J | メッセージコンテンツの XSS | 201（そのまま保存） | Pass |
| VULN-K | 自己会話の試み | 422 | Pass |
| VULN-L | 100KB のメッセージコンテンツ | 201 または 413 | Pass |

12 の脆弱性テストすべてが合格。脆弱性なし。

---

## よくある落とし穴

| 落とし穴 | 修正 |
|---------|-----|
| GET リクエストで `JsonRequestBodyParser::parse()` を呼び出す | ボディを期待する POST/PUT/PATCH ハンドラーのみで呼び出す |
| `UNIQUE (initiator_id, recipient_id)` が A→B と B→A を 2 つの会話として防がない | INSERT の前に OR クエリで方向非依存のルックアップを行う |
| コンテンツの有効性確認の後に参加者を確認する | 情報漏洩を避けるためにコンテンツの*前*に参加者を確認する |
| ユーザー存在確認なしで任意の非ゼロ整数をアクター ID として受け入れる | 参加確認の前に常に `findUserById(actorId)` を確認する |

---

## やってはいけないこと

| アンチパターン | リスク |
|---|---|
| 会話を方向付きで `(user_a, user_b)` として保存 — A→B と B→A に 2 つの別々の行 | 同じ 2 ユーザーが重複した会話を蓄積する。方向非依存のルックアップが失敗する |
| `CHECK (initiator_id != recipient_id)` 制約なし | ユーザーが自分自身にメッセージを送れる。混乱する自己会話を作成する |
| `UNIQUE (initiator_id, recipient_id)` 制約なし | 並行する会話開始リクエストが同じペアで重複行を作成する |
| 非参加者アクセスに 403 の代わりに 404 を返す | 非参加者に会話 ID の存在が分かる |
| GET `/conversations/{id}/messages` で `JsonRequestBodyParser::parse()` を呼び出す | GET リクエストにボディはない。パーサーは 400 を返す |
| 参加者確認の前にコンテンツの有効性を確認する | 情報漏洩 — 攻撃者が空のコンテンツを送信して 403 vs 422 を確認することで有効な会話 ID を探れる |
| `is_numeric()` を使って `int` にキャストせず `> 0` でチェックしない | `is_numeric("0")` は true。ユーザー ID 0 が有効として扱われる |
| 参加確認後のユーザー存在チェックをスキップ | `isParticipant()` は FK のみを確認する — DB にカスケードがない場合、削除または存在しないユーザーが残る |
| 任意のユーザーが他のユーザーの会話を一覧表示することを許可 | IDOR — 会話リストを返す前に常に `actorId === targetUserId` を確認する |
| メッセージの `id ASC` のインデックスのみ | インデックスがないと大きなメッセージ履歴で ORDER BY が遅くなる |
