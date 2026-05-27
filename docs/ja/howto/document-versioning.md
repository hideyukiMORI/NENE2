# ハウツー: ドキュメントバージョニング API

> **FT リファレンス**: FT239 (`NENE2-FT/doclog`) — ドキュメントバージョニング API

`is_current` フラグで現在のバージョンを追跡し、revert が新しいバージョンを作成（非破壊的）し、すべての複数ステップ書き込みが `DatabaseTransactionManagerInterface` 経由でトランザクションにラップされる追記専用のドキュメントバージョニングシステムを実演します。

---

## ルート

| メソッド | パス | 説明 |
|--------|-------------------------------------------|------------------------------------------------------|
| `POST` | `/documents`                              | 最初のバージョンでドキュメントを作成する |
| `GET`  | `/documents`                              | 現在のバージョン付きでドキュメントを一覧表示する（ページネーション） |
| `GET`  | `/documents/{id}`                         | 現在のバージョン付きでドキュメントを取得する |
| `GET`  | `/documents/{id}/versions`                | バージョン履歴を一覧表示する（ページネーション） |
| `POST` | `/documents/{id}/versions`                | 新しいバージョンを追加する |
| `POST` | `/documents/{id}/revert/{version}`        | 特定のバージョン番号に戻す |

静的サブルート（`/documents/{id}/versions`）はパラメータ化された `/documents/{id}` ルートの前に登録され、正しいディスパッチを保証します。

---

## スキーマ: `is_current` フラグパターン

```sql
CREATE TABLE IF NOT EXISTS documents (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    title      TEXT    NOT NULL,
    created_at TEXT    NOT NULL,
    updated_at TEXT    NOT NULL
);

CREATE TABLE IF NOT EXISTS document_versions (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    document_id INTEGER NOT NULL REFERENCES documents(id) ON DELETE CASCADE,
    content     TEXT    NOT NULL,
    version_num INTEGER NOT NULL,
    is_current  INTEGER NOT NULL DEFAULT 0 CHECK(is_current IN (0, 1)),
    created_at  TEXT    NOT NULL,
    UNIQUE(document_id, version_num)
);
CREATE INDEX IF NOT EXISTS idx_versions_document ON document_versions(document_id);
```

`is_current` は INTEGER として保存されたブール値フラグ（0/1）で、`CHECK` で制約されます。ドキュメントごとに最大 1 行が `is_current = 1` を持つべきです。`UNIQUE(document_id, version_num)` は同じドキュメントの重複バージョン番号を防止します。

**`current_version` 整数との比較**: `is_current` フラグアプローチはバージョンが変わるたびに親 `documents` テーブルのカラムを更新する必要がなくなります。フラグは新しいバージョンを挿入するのと同じトランザクション内で `document_versions` テーブルで直接切り替えられます。

---

## JOIN で現在のバージョンを取得する

一覧と表示クエリは `is_current = 1` でフィルタリングされた `LEFT JOIN` を使って、1 つのクエリで現在のバージョンを取得します:

```php
$row = $this->executor->fetchOne(
    'SELECT d.*, dv.id AS vid, dv.content, dv.version_num, dv.is_current,
            dv.created_at AS version_created_at
     FROM documents d
     LEFT JOIN document_versions dv ON dv.document_id = d.id AND dv.is_current = 1
     WHERE d.id = ?',
    [$id],
);
```

`LEFT JOIN ... AND dv.is_current = 1` — JOIN 条件が現在のバージョンのみにフィルタリングします。バージョンのないドキュメントは `NULL` の JOIN 行を返し、`currentVersion: null` としてハイドレートされます。

---

## バージョン追加: 3 ステップトランザクション

バージョンの追加にはトランザクションにラップされた 3 つの操作が必要です:

```php
public function addVersion(int $documentId, string $content, string $now): Document
{
    return $this->txManager->transactional(function (DatabaseQueryExecutorInterface $tx) use ($documentId, $content, $now): Document {
        // ステップ 1: 次のバージョン番号を計算
        $maxRow     = $tx->fetchOne('SELECT MAX(version_num) AS max_ver FROM document_versions WHERE document_id = ?', [$documentId]);
        $nextVerNum = ((int) ($maxRow['max_ver'] ?? 0)) + 1;

        // ステップ 2: 現在のバージョンを非アクティブ化
        $tx->execute('UPDATE document_versions SET is_current = 0 WHERE document_id = ? AND is_current = 1', [$documentId]);

        // ステップ 3: 新しいバージョンを現在として挿入
        $versionId = $tx->insert(
            'INSERT INTO document_versions (document_id, content, version_num, is_current, created_at) VALUES (?, ?, ?, 1, ?)',
            [$documentId, $content, $nextVerNum, $now],
        );

        // ステップ 4: ドキュメントの updated_at を更新
        $tx->execute('UPDATE documents SET updated_at = ? WHERE id = ?', [$now, $documentId]);
        // ...
    });
}
```

`DatabaseTransactionManagerInterface::transactional()` がクロージャをトランザクションにラップします。いずれかのステップがスローした場合、トランザクションはロールバックされます。`$tx` パラメーターはトランザクションにスコープされた executor です — 別の接続は不要です。

---

## 非破壊的な revert: 新しいバージョンとしてコピー

revert は既存の履歴を変更しません — 対象バージョンのコンテンツを含む新しいバージョンを作成します:

```php
public function revertToVersion(int $documentId, int $versionNum, string $now): Document
{
    return $this->txManager->transactional(function (DatabaseQueryExecutorInterface $tx) use ($documentId, $versionNum, $now): Document {
        $targetRow = $tx->fetchOne(
            'SELECT * FROM document_versions WHERE document_id = ? AND version_num = ?',
            [$documentId, $versionNum],
        );

        if ($targetRow === null) {
            throw new VersionNotFoundException($documentId, $versionNum);
        }

        // revert コピーの次のバージョン番号を計算
        $maxRow     = $tx->fetchOne('SELECT MAX(version_num) AS max_ver FROM document_versions WHERE document_id = ?', [$documentId]);
        $nextVerNum = ((int) ($maxRow['max_ver'] ?? 0)) + 1;

        // 現在のバージョンを非アクティブ化
        $tx->execute('UPDATE document_versions SET is_current = 0 WHERE document_id = ? AND is_current = 1', [$documentId]);

        // 対象コンテンツのコピーを新しい現在バージョンとして挿入
        $newVersionId = $tx->insert(
            'INSERT INTO document_versions (document_id, content, version_num, is_current, created_at) VALUES (?, ?, ?, 1, ?)',
            [$documentId, (string) $targetRow['content'], $nextVerNum, $now],
        );
        // ...
    });
}
```

ドキュメントがバージョン 5 にあり、バージョン 2 に戻す場合、バージョン 2 のコンテンツでバージョン 6 が作成されます。履歴は:
```
v1 → v2 → v3 → v4 → v5 → v6（v2 のコピー）
```

このアプローチは完全な監査証跡を保持します — revert 自体が新しいエントリとして履歴に表示されます。履歴を「失う」ことは不可能です。

---

## 構造化コンテキストを持つ VersionNotFoundException

`VersionNotFoundException` はドキュメント ID とバージョン番号の両方を持ちます:

```php
final class VersionNotFoundException extends \RuntimeException
{
    public function __construct(int $documentId, int $versionNum)
    {
        parent::__construct("Version {$versionNum} not found for document {$documentId}.");
    }
}
```

例外はトランザクションクロージャの内部でスローされます。例外ハンドラーがそれを `404 Not Found` レスポンスにマップします。例外が revert の書き込み操作の前にスローされるため、トランザクションはクリーンにロールバックされます。

---

## NENE2 ビルトイン: PaginationQueryParser と PaginationResponse

一覧エンドポイントは NENE2 のページネーションヘルパーを使用します:

```php
private function listDocuments(ServerRequestInterface $request): ResponseInterface
{
    $pagination = PaginationQueryParser::parse($request);
    $items      = $this->repository->findAll($pagination->limit, $pagination->offset);
    $total      = $this->repository->countAll();

    $response = new PaginationResponse(
        items: array_map($this->serializeDocument(...), $items),
        limit: $pagination->limit,
        offset: $pagination->offset,
        total: $total,
    );

    return $this->json->create($response->toArray());
}
```

`PaginationQueryParser::parse()` はクエリパラメーターから `?limit=` と `?offset=` を安全なデフォルトと境界付きで読み取ります。`PaginationResponse::toArray()` は一貫したエンベロープ `{ items, total, limit, offset }` を生成します。

---

## NENE2 ビルトイン: ValidationException と ValidationError

入力バリデーションは NENE2 の構造化バリデーションヘルパーを使用します:

```php
$errors = [];
if (!isset($body['title']) || !is_string($body['title']) || trim($body['title']) === '') {
    $errors[] = new ValidationError('title', 'title is required.', 'required');
}
if (!isset($body['content']) || !is_string($body['content'])) {
    $errors[] = new ValidationError('content', 'content is required.', 'required');
}
if ($errors !== []) {
    throw new ValidationException($errors);
}
```

`ValidationException` は NENE2 のエラーハンドラーによってキャッチされ、構造化された `errors` 配列を持つ `422 Unprocessable Entity` Problem Details レスポンスに変換されます。

---

## 関連ハウツー

- [`content-versioning.md`](content-versioning.md) — 整数ベースの current_version パターン
- [`audit-trail.md`](audit-trail.md) — 追記専用の履歴パターン
- [`transactions.md`](transactions.md) — DatabaseTransactionManagerInterface パターン
- [`use-transactions.md`](use-transactions.md) — 複数書き込み操作のラップ
