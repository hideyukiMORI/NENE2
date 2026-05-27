# ハウツー: JWT マルチテナント分離

> **FT リファレンス**: FT342 (`NENE2-FT/tenantlog`) — JWT Bearer 認証、トークンクレームに埋め込んだ tenant_id、厳格なテナントごとのクエリスコーピング、クロステナント IDOR を 404 でブロック、tenant_id をレスポンスに含めない、13 テスト / 30+ アサーション PASS。

このガイドでは JWT トークンを使って `tenant_id` をクレームとして運び、すべてのクエリを認証済みテナントにスコープし、クロステナントのデータアクセスを防止する方法を示します。

## スキーマ

```sql
CREATE TABLE tenants (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT    NOT NULL,
    created_at TEXT    NOT NULL
);

CREATE TABLE users (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id     INTEGER NOT NULL REFERENCES tenants(id),
    email         TEXT    NOT NULL UNIQUE,
    password_hash TEXT    NOT NULL,
    created_at    TEXT    NOT NULL
);

CREATE TABLE notes (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id  INTEGER NOT NULL REFERENCES tenants(id),
    user_id    INTEGER NOT NULL REFERENCES users(id),
    title      TEXT    NOT NULL,
    body       TEXT    NOT NULL DEFAULT '',
    created_at TEXT    NOT NULL
);
```

## 認証

```
POST /auth/login  →  Bearer トークン（JWT）
他のすべてのエンドポイント → Authorization: Bearer <token>
```

### ログイン

```php
POST /auth/login
{"email": "alice@acme.com", "password": "password"}
→ 200  {"token": "eyJhbGci..."}

// 誤った認証情報または不明なメール
→ 401  {"type": ".../invalid-credentials", "detail": "Invalid email or password"}
// 両方の失敗で同じメッセージを返す（ユーザー列挙防止）
```

### JWT クレーム

```php
// トークンペイロード（デコード済み）
{
  "sub": 1,           // user_id
  "tenant_id": 1,     // ユーザーが属するテナント
  "exp": 1748427600
}
```

`tenant_id` クレームはテナントアイデンティティの権威あるソースです — リクエストボディやヘッダーからの `tenant_id` は決して信頼しないでください。

### 検証

```php
$verifier = new LocalBearerTokenVerifier($secret);
$claims   = $verifier->verify($token);
// $claims['tenant_id'] が信頼されたテナントスコープ
```

改ざんされたトークン（無効な署名）→ 401。

## テナントスコープエンドポイント

すべてのノート操作には有効な Bearer トークンが必要です。`tenant_id` は検証済みの JWT クレームから抽出されます。

### ノート作成

```php
POST /notes
Authorization: Bearer <alice_token>
{"title": "Alice Note", "body": "Acme content"}
→ 201
{
  "id": 1,
  "title": "Alice Note",
  "body": "Acme content",
  "created_at": "..."
  // tenant_id は返されない — クライアントには決して漏洩しない
}

// トークンなし → 401
// 無効なトークン → 401
```

**`tenant_id` は常に JWT クレームから取得し、リクエストボディからは取得しません。**

### ノート一覧

```php
GET /notes
Authorization: Bearer <alice_token>
→ 200  [{"id": 1, "title": "Alice Note", ...}]

// Bob のトークンは Bob のノートのみ表示 — Alice のノートは表示されない
GET /notes
Authorization: Bearer <bob_token>
→ 200  [{"id": 2, "title": "Bob Note", ...}]
```

```sql
SELECT * FROM notes WHERE tenant_id = ? ORDER BY created_at DESC
-- tenant_id は JWT クレームからバインド、リクエストからではない
```

### ノート取得（IDOR 防止）

```php
// Alice のノート
GET /notes/1
Authorization: Bearer <alice_token>
→ 200  {"id": 1, "title": "Alice Note", ...}

// Bob が Alice のノートにアクセスしようとする（ノート id 1 はテナント 1 に属する）
GET /notes/1
Authorization: Bearer <bob_token>
→ 404  // 403 ではない — クロステナントの存在列挙を防止
```

**クロステナントアクセスには 403 ではなく 404 を返してください。** 403 はリソースが別のテナントに存在することを明かします。

### ノート削除

```php
DELETE /notes/1
Authorization: Bearer <alice_token>
→ 204

// クロステナント削除
DELETE /notes/1
Authorization: Bearer <bob_token>
→ 404  // ノートはそのまま; Bob のトークンはアクセスできない
```

## 実装パターン

```php
// ミドルウェアが JWT を抽出して検証する
$claims = $verifier->verify($bearerToken);
$request = $request->withAttribute('tenant_id', $claims['tenant_id']);
$request = $request->withAttribute('user_id', $claims['sub']);

// コントローラーはリクエスト属性から読み取る（ボディからではない）
$tenantId = (int) $request->getAttribute('tenant_id');

// リポジトリは常にテナントにスコープする
public function findById(int $id, int $tenantId): ?array
{
    $stmt = $this->db->prepare(
        'SELECT id, title, body, created_at FROM notes WHERE id = ? AND tenant_id = ?'
    );
    $stmt->execute([$id, $tenantId]);
    return $stmt->fetch() ?: null;
}

// null 返却 → 404 レスポンス（403 ではない）
if ($note === null) {
    return $this->json->create(['error' => 'Not found'], 404);
}
```

## トークン改ざん拒否

```php
// 攻撃者が異なる tenant_id を持つトークンを手動で作成する
$fakeToken = 'eyJhbGciOiJIUzI1NiJ9.tampered.invalidsignature';

GET /notes/1
Authorization: Bearer $fakeToken
→ 401  // 署名検証が失敗する
```

サーバーはサーバーシークレットと HMAC-SHA256 署名が一致しないトークンを拒否します。

---

## やってはいけないこと

| アンチパターン | リスク |
|---|---|
| リクエストボディやクエリパラメーターから `tenant_id` を読み取る | 攻撃者が `tenant_id=2` を設定して他のテナントのデータにアクセスする |
| クロステナントアクセスに 403 を返す | リソースが別のテナントに存在することを確認する — 情報漏洩 |
| ノートレスポンスに `tenant_id` を含める | 内部のテナントトポロジーを公開する; クライアントには不要 |
| クエリで `AND tenant_id = ?` をスキップする | クロステナント漏洩 — 有効なトークンを持つ攻撃者がすべてのテナントのデータを参照する |
| データと一緒に JWT シークレットをコンフィグに保存する | シークレット侵害により任意のテナントのトークンを偽造できる |
| `X-Tenant-Id` ヘッダーから `tenant_id` を信頼する | ヘッダーは任意のクライアントが設定できる; 検証済み JWT クレームのみ信頼する |
