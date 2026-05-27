# ハウツー: 楽観的同時実行制御を追加する（ETag / If-Match）

楽観的ロックは**ロスト更新問題**を防止します: 2 つのクライアントが同じリソースを読み取り、両方が変更し、2 番目の書き込みが最初のものをサイレントに上書きします。

NENE2 は書き込み側（PUT、PATCH、DELETE）に `ConditionalWriteHelper` を、読み取り側（GET → 304 Not Modified）に `ConditionalGetHelper` を提供します。

---

## 1. スキーマにバージョンカウンターを追加する

```sql
CREATE TABLE documents (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    title      TEXT    NOT NULL,
    version    INTEGER NOT NULL DEFAULT 1,
    updated_at TEXT    NOT NULL
);
```

---

## 2. すべての GET と書き込みレスポンスで ETag を返す

バージョン番号をシンプルでデバッグしやすい ETag として使用します:

```php
private function etag(int $version): string
{
    return '"v' . $version . '"';
}

// GET ハンドラーで:
return $this->json->create($doc->toArray())
    ->withHeader('ETag', $this->etag($doc->version));

// POST（作成）ハンドラーで:
return $this->json->create($doc->toArray(), 201)
    ->withHeader('ETag', $this->etag($doc->version));
```

---

## 3. PUT / PATCH / DELETE で `If-Match` を確認する

```php
use Nene2\Http\ConditionalWriteHelper;

private function update(ServerRequestInterface $request): ResponseInterface
{
    $id  = $this->resolveId($request);
    $doc = $this->repo->findById($id);
    if ($doc === null) {
        return $this->problems->create($request, 'not-found', 'Not Found', 404, '');
    }

    $block = ConditionalWriteHelper::check($request, $this->problems, $this->etag($doc->version));
    if ($block !== null) {
        return $block; // 412 Precondition Failed または 428 Precondition Required
    }

    // ETag が一致 — 安全に書き込む
    $updated = $this->repo->updateIfMatch($id, /* 新しい値 */, $doc->version);
    if ($updated === null) {
        // チェック後に同時変更された
        return $this->problems->create($request, 'precondition-failed', 'Precondition Failed', 412, '');
    }
    return $this->json->create($updated->toArray())
        ->withHeader('ETag', $this->etag($updated->version));
}
```

### `ConditionalWriteHelper::check()` が返すステータスコード

| `If-Match` ヘッダー | サーバー ETag | 結果 |
|-------------------|-------------|--------|
| 欠落 | any | **428** Precondition Required（ヘッダーは必須） |
| `*` | any | **null** — 通過（ワイルドカード、任意バージョン） |
| `"v3"` | `"v3"` | **null** — 通過（完全一致） |
| `"v2"` | `"v3"` | **412** Precondition Failed（古いバージョン） |

`If-Match` をオプションにするには `require: false` を渡します:

```php
ConditionalWriteHelper::check($request, $this->problems, $etag, require: false);
```

---

## 4. リポジトリで条件付き UPDATE を使用する

```php
public function updateIfMatch(int $id, string $title, int $expectedVersion): ?Document
{
    $newVer  = $expectedVersion + 1;
    $now     = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
    $updated = $this->db->execute(
        'UPDATE documents SET title = ?, version = ?, updated_at = ? WHERE id = ? AND version = ?',
        [$title, $newVer, $now, $id, $expectedVersion],
    );

    if ($updated === 0) {
        return null; // バージョン不一致または見つからない
    }
    return new Document($id, $title, $newVer, $now);
}
```

`WHERE version = ?` 句はデータベースレベルのロックガードです。行のバージョンが同時書き込みによってすでに進んでいた場合、`execute()` は `0` を返し（更新行なし）、呼び出し元は 2 番目の 412 レスポンスを返せます。

---

## 5. ロスト更新シナリオをテストする

```php
public function testLostUpdatePrevented(): void
{
    $id = $this->decode($this->create('Original'))['id'];

    // Alice がバージョン 1 を読み取り更新する → バージョンが 2 になる
    $this->req('PUT', '/documents/' . $id, ['title' => "Alice's edit"], '"v1"');

    // Bob が古い v1 ETag で更新しようとする → 失敗しなければならない
    $bob = $this->req('PUT', '/documents/' . $id, ['title' => "Bob's edit"], '"v1"');
    self::assertSame(412, $bob->getStatusCode());

    // Alice の更新が保持される
    $final = $this->decode($this->req('GET', '/documents/' . $id));
    self::assertSame("Alice's edit", $final['title']);
    self::assertSame(2, $final['version']);
}
```

---

## 注意事項

- **ETag フォーマット**: `"v{version}"`（整数ベース）はシンプルでテストで予測可能です。コンテンツハッシュ ETag（`'"' . md5($body) . '"'`）はコンテンツアドレス可能なリソースに対してより堅牢ですが、ハッシュを事前計算せずにはテストで予測しにくいです。
- **ワイルドカード `If-Match: *`**: RFC 9110 は `*` を「リソースに現在の表現があれば成功する」と定義しています — つまり存在することです。バージョンを知らずに「存在する場合に更新する」に便利です。呼び出し元はリソースが不在の場合に 404 を返す必要があります。
- **428 Precondition Required**（RFC 6585 §3）: `If-Match` が必須だが欠落している場合の正しいステータスです。400 や 422 の代わりに使用してください — リクエストは整形式ですが、前提条件が欠けています。
- **TOCTOU ウィンドウ**: `findById()` + 条件付き UPDATE パターンはマルチライターデータベースで短いレースウィンドウがあります。SQLite の書き込みシリアライズのもとでは無害です。高並行性下の PostgreSQL では、両方の操作を `SERIALIZABLE` トランザクションでラップしてください。
