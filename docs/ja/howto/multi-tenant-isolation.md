# ハウツー: マルチテナント分離

このガイドでは NENE2 で各テナントのデータが厳密に分離されたマルチテナント API の構築方法を解説します。いずれかのステップをスキップすると、すべてのテナントのデータを公開するサイレントな IDOR（Insecure Direct Object Reference）が生じます。

---

## コアルール: すべてのクエリに `tenant_id` フィルターを入れる

単一クエリからテナントフィルターを省略すると、すべてのテナントのデータがサイレントに返されます:

```sql
-- ❌ テナントフィルターなし — すべてのテナントのレコードを返す
SELECT id, title, body FROM notes WHERE id = ?

-- ✅ 常にテナントフィルターを含める
SELECT id, title, body FROM notes WHERE id = ? AND tenant_id = ?
```

コントラクトを見えるようにするために、リポジトリメソッドに `ForTenant` サフィックスを付けてください:

```php
public function findByIdForTenant(int $id, int $tenantId): ?Note
{
    /** @var array{id: int, tenant_id: int, title: string, body: string, created_at: string}|null $row */
    $row = $this->executor->fetchOne(
        'SELECT id, tenant_id, title, body, created_at FROM notes WHERE id = ? AND tenant_id = ?',
        [$id, $tenantId],
    );

    return $row !== null ? $this->hydrate($row) : null;
}

/** @return list<Note> */
public function findAllForTenant(int $tenantId): array
{
    /** @var list<array{id: int, tenant_id: int, title: string, body: string, created_at: string}> $rows */
    $rows = $this->executor->fetchAll(
        'SELECT id, tenant_id, title, body, created_at FROM notes WHERE tenant_id = ? ORDER BY id DESC',
        [$tenantId],
    );

    return array_map($this->hydrate(...), $rows);
}

public function delete(int $id, int $tenantId): bool
{
    $note = $this->findByIdForTenant($id, $tenantId);

    if ($note === null) {
        return false;
    }

    $this->executor->execute('DELETE FROM notes WHERE id = ? AND tenant_id = ?', [$id, $tenantId]);

    return true;
}
```

`ForTenant` サフィックスは呼び出し元にテナント ID の提供を強制します。また、コードレビューを分かりやすくします: そのサフィックスのないメソッドは IDOR レビューの候補です。

---

## JWT に `tenant_id` を埋め込む

ログイン時に 1 回テナントメンバーシップを解決してトークンに埋め込んでください。これによりリクエストごとの DB ラウンドトリップを避け、テナントコンテキストを改ざん防止にします（JWT 署名がカバーします）。

```php
$now   = time();
$token = $this->issuer->issue([
    'sub'       => $user->id,
    'tenant_id' => $user->tenantId,  // int でなければならない
    'email'     => $user->email,
    'iat'       => $now,
    'exp'       => $now + self::TOKEN_TTL_SECONDS,
]);
```

ハンドラーでクレームを抽出してバリデーションしてください。`is_int()` を使ってください — `is_string()` のみでは安全ではありません; MySQL/PostgreSQL は文字列から整数への比較をサイレントに拒否する場合があります:

```php
private function tenantId(ServerRequestInterface $request): ?int
{
    /** @var array<string, mixed>|null $claims */
    $claims = $request->getAttribute('nene2.auth.claims');

    if (!is_array($claims) || !isset($claims['tenant_id']) || !is_int($claims['tenant_id'])) {
        return null;  // 401 をトリガー
    }

    return $claims['tenant_id'];
}
```

`BearerTokenMiddleware` は検証済みクレームを `nene2.auth.claims` に保存します。ミドルウェアはハンドラーが実行される前に、期限切れトークン、改ざんされた署名、`alg: none` 攻撃を拒否します。

---

## クロステナントアクセスには 404 を返す（403 ではない）

403 Forbidden を返すと、リソースが存在するがテナント境界を越えた情報である呼び出し元に権限がないことが明かされます。常に 404 を返してください:

```php
// ❌ 403 はクロステナント情報を漏洩する
if ($note->tenantId !== $tenantId) {
    return $this->problems->create($request, 'forbidden', 'Forbidden', 403);
}

// ✅ SQL のテナントフィルター — クロステナントレコードは単純に null を返す
$note = $this->notes->findByIdForTenant($id, $tenantId);

if ($note === null) {
    return $this->problems->create(
        $request,
        'not-found',
        'Note Not Found',
        404,
        "Note {$id} does not exist.",
    );
}
```

`WHERE id = ? AND tenant_id = ?` が何もマッチしない場合、リポジトリは `null` を返し、ハンドラーは 404 を返します — 明示的なクロステナントチェックは不要です。

---

## レスポンスから `tenant_id` を除外する

`tenant_id` はインフラストラクチャ識別子です。レスポンスで公開すると、攻撃者がすべてのテナント ID を列挙できるようになり、標的型攻撃の出発点になります:

```php
// ❌ レスポンスで tenant_id が漏洩する
return $this->json->create([
    'id'        => $note->id,
    'tenant_id' => $note->tenantId,  // これを削除
    'title'     => $note->title,
    'body'      => $note->body,
]);

// ✅ クライアントが必要なフィールドのみ
return $this->json->create([
    'id'         => $note->id,
    'title'      => $note->title,
    'body'       => $note->body,
    'created_at' => $note->createdAt,
]);
```

---

## PHPStan: `list<>` 戻り型のための `assertIsList()`

`json_decode()` は `mixed` を返します。`assertIsArray()` の後、PHPStan は型を `array<mixed>` に絞り込みますが、それは `list<array<string, mixed>>` を満たしません。さらに絞り込むために `assertIsList()` を追加してください:

```php
/** @return list<array<string, mixed>> */
private function jsonList(ResponseInterface $response): array
{
    $data = json_decode((string) $response->getBody(), true);

    $this->assertIsArray($data);
    $this->assertIsList($data);  // array<mixed> → list<mixed> に絞り込む

    return $data;
}
```

PHPUnit の `assertIsList()` は 0 から始まる連続した整数キーを持つ配列であることをランタイムでもバリデーションします — API のリストレスポンスに対する便利な正確性チェックです。

---

## スキーマ設計

```sql
CREATE TABLE IF NOT EXISTS tenants (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT NOT NULL,
    created_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS users (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id     INTEGER NOT NULL REFERENCES tenants(id),
    email         TEXT NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    created_at    TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS notes (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id  INTEGER NOT NULL REFERENCES tenants(id),
    title      TEXT NOT NULL,
    body       TEXT NOT NULL,
    created_at TEXT NOT NULL
);
```

すべてのテナントスコープテーブルには `tenant_id NOT NULL` 外部キーがあります。これはアプリケーションレベルのフィルターに加えて DB レベルで強制されます。

---

## コードレビューチェックリスト

マルチテナントコードをレビューする際に確認してください:

1. すべての `SELECT`、`UPDATE`、`DELETE` に `WHERE tenant_id = ?` が含まれている
2. `tenant_id` は URL パラメーターやリクエストボディではなく JWT クレームから取得している
3. クロステナントアクセスは 403 ではなく 404 を返す
4. レスポンスに `tenant_id` が含まれない
5. テナントフィルターなしでテナント境界を越える `JOIN` がない
6. `is_int($claims['tenant_id'])` 型チェックが存在する

---

## 分離のテスト

ユニットテストでは不十分です — 実際に別のテナントのデータへのアクセスを試みるクロステナント統合テストを書いてください:

```php
public function testCrossTenantGetReturns404NotForbidden(): void
{
    $aliceToken = $this->loginAs('alice@acme.com');
    $bobToken   = $this->loginAs('bob@beta.com');

    $res    = $this->post('/notes', ['title' => 'Secret', 'body' => 'Acme secret'], $aliceToken);
    $noteId = $this->json($res)['id'];

    // Bob が Alice のノートにアクセスしようとする
    $crossRes = $this->get('/notes/' . $noteId, $bobToken);

    // 403 ではなく 404 でなければならない
    $this->assertSame(404, $crossRes->getStatusCode());
}

public function testListNotesShowsOnlyCurrentTenantNotes(): void
{
    $aliceToken = $this->loginAs('alice@acme.com');
    $bobToken   = $this->loginAs('bob@beta.com');

    $this->post('/notes', ['title' => 'Alice Note', 'body' => 'Acme'], $aliceToken);
    $this->post('/notes', ['title' => 'Bob Note',   'body' => 'Beta'], $bobToken);

    $aliceNotes = $this->jsonList($this->get('/notes', $aliceToken));
    $bobNotes   = $this->jsonList($this->get('/notes', $bobToken));

    $this->assertCount(1, $aliceNotes);
    $this->assertSame('Alice Note', $aliceNotes[0]['title']);

    $this->assertCount(1, $bobNotes);
    $this->assertSame('Bob Note', $bobNotes[0]['title']);
}
```

ハッピーパスのテストは自テナントのデータが動くことのみを確認します。クロステナントテストが分離の失敗を検出する唯一の方法です。

---

## 参照

- `docs/howto/jwt-authentication.md` — JWT の発行と検証
- `docs/howto/rbac.md` — JWT 上のロールベースアクセス制御
- `docs/howto/enforce-resource-ownership.md` — ユーザーごとの所有権チェック
- `docs/field-trials/2026-05-field-trial-112.md` — マルチテナント分離フィールドトライアル
