# リソースオーナーシップの強制（IDOR 防止）

Insecure Direct Object Reference（IDOR）は OWASP API セキュリティ Top 10 の第 1 位の API 脆弱性です。
ユーザーが ID を推測または列挙することで別のユーザーのリソースにアクセスまたは変更できる場合に発生します。

NENE2 は自動的なオーナーシップ強制を提供しません — すべてのリポジトリとハンドラーが明示的に実装する必要があります。このガイドでは推奨パターンを示します。

---

## 1. コアルール: 403 ではなく 404

ユーザーが別のユーザーに属するリソースにアクセスした場合、`404 Not Found` を返します — `403 Forbidden` では**ありません**。

- **403** は攻撃者に「このリソースは存在するが、アクセスできない」と伝えます — 情報漏洩
- **404** は攻撃者に「このリソースは存在しない」と伝えます — 確認なし

```php
// 間違い — 存在を漏洩する
if ($note->ownerId !== $authUserId) {
    return $this->problems->create($request, 'forbidden', 'Forbidden', 403, '');
}

// 正しい — 何も明かさない
if ($note === null) {
    return $this->problems->create($request, 'not-found', 'Not Found', 404, '');
}
```

これを実現する実用的な方法: リポジトリが**呼び出し元に属さないリソースを返せないようにする** — 次のセクション参照。

---

## 2. SQL レベルでオーナーシップを強制する

最も安全なパターンはすべてのクエリに `owner_id` を含めることです。呼び出し元がどのように結果を使用しても、このメソッドは文字通り別のユーザーのデータを返せません。

```php
public function findByIdAndOwner(int $id, string $ownerId): ?Resource
{
    $row = $this->db->fetchOne(
        'SELECT * FROM resources WHERE id = ? AND owner_id = ?',
        [$id, $ownerId],
    );
    return $row !== null ? $this->hydrate($row) : null;
}

public function update(int $id, string $ownerId, string $newValue): bool
{
    $updated = $this->db->execute(
        'UPDATE resources SET value = ? WHERE id = ? AND owner_id = ?',
        [$newValue, $id, $ownerId],
    );
    return $updated > 0;
}

public function delete(int $id, string $ownerId): bool
{
    return $this->db->execute(
        'DELETE FROM resources WHERE id = ? AND owner_id = ?',
        [$id, $ownerId],
    ) > 0;
}
```

**SQL レベルがアプリケーションレベルより優れている理由:**
- アプリケーションレベルのチェックは開発者が呼び出しを忘れるとバイパスされる可能性がある
- SQL レベルのチェックはスキップできない — 間違ったオーナーの行は単純に返されない
- 「見つからない」と「間違ったオーナー」の両方に `null` を返すことで、呼び出し元が知るべきでないケースで誤って分岐することを防ぐ

---

## 3. ハンドラーパターン

```php
private function show(ServerRequestInterface $request): ResponseInterface
{
    $authUserId = $this->resolveAuthUser($request);
    if ($authUserId === null) {
        return $this->unauthorized($request);
    }

    $id       = $this->resolveId($request);
    $resource = $this->repo->findByIdAndOwner($id, $authUserId);

    if ($resource === null) {
        // 404 は「見つからない」と「別のユーザーに属する」の両方をカバーする
        return $this->problems->create($request, 'not-found', 'Not Found', 404, '');
    }

    return $this->json->create($resource->toArray());
}
```

---

## 4. 一覧: クエリでオーナーによるフィルタリング

```php
public function listByOwner(string $ownerId): array
{
    return $this->db->fetchAll(
        'SELECT * FROM resources WHERE owner_id = ? ORDER BY id DESC',
        [$ownerId],
    );
}
```

全行を取得して PHP でフィルタリングしてはいけません。フィルタリングロジックが間違っていると他のユーザーのデータを漏洩し、N+1 問題にもなります。

---

## 5. クロスオーナーアクセスを明示的にテストする

IDOR が防止されていることを確認する専用テストを追加してください:

```php
public function testCannotReadAnotherUsersResource(): void
{
    $bobId = $this->decode($this->create('bob', 'Bob content'))['id'];

    // Alice が Bob のリソースを読もうとする — 404 を受け取らなければならない
    $res = $this->request('GET', '/resources/' . $bobId, authUser: 'alice');
    self::assertSame(404, $res->getStatusCode());
    // 403 でないことを確認 — 403 はリソースの存在を漏洩してしまう
    self::assertNotSame(403, $res->getStatusCode());
}

public function testListDoesNotLeakCrossTenantData(): void
{
    $this->create('alice', 'Alice content');
    $this->create('bob', 'Bob content');

    $aliceList = $this->decode($this->request('GET', '/resources', authUser: 'alice'));
    $titles    = array_column($aliceList['items'], 'content');

    self::assertNotContains('Bob content', $titles);
}
```

---

## 注記

- **なぜ 404 が違和感を感じるか**: URL に表示されているリソースに 404 を返すのは「不誠実」に感じます。そうです — しかし OWASP は ID 列挙攻撃を防ぐために明示的に推奨しています。このトレードオフは受け入れられたセキュリティ慣行です。
- **管理者バイパス**: 任意のリソースを確認できる管理者ルートがある場合、別のパスプレフィックスに別のオーナーシップチェック（またはチェックなし）を設けてください。オーナーシップメソッドに「is admin」フラグで複雑化しないでください。
- **データベーススキーマ**: `owner_id`（および複合検索用の `(owner_id, id)`）に常にインデックスを追加してください。インデックスなしでは、ユーザーごとのすべてのクエリがフルテーブルスキャンになります。
