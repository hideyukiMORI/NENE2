# ハウツー: 資産チェックアウト / チェックイン管理

追記専用の監査ログを持つ排他的な資産保持追跡を示します。
フィールドトライアル: FT194 (`../NENE2-FT/assetlog/`)。

---

## パターンまとめ

| 懸念事項 | アプローチ |
|---|---|
| 排他的保持 | `holder_id INTEGER` — NULL = 利用可能、非 null = 保持中 |
| チェックアウト競合 | 更新前に `holder_id IS NOT NULL` の場合 409 |
| 誤った保持者のチェックイン | `holder_id != userId` の場合 403 |
| 監査ログ | すべての状態変更時に追記専用の `asset_history` 行 |
| IDOR 防止 | 公開 API は `holder_id` を隠す；表示には管理者キーが必要 |
| 管理者キー | `hash_equals()` による定数時間比較、空キーはフェイルクローズ |
| ユーザー識別 | `X-User-Id` ヘッダー；`ctype_digit()` + 長さガード、正規表現なし |

---

## ルート

| メソッド | パス | 認証 | 説明 |
|---|---|---|---|
| `POST` | `/assets` | `X-Admin-Key` | 資産を作成する |
| `GET` | `/assets` | — | すべての資産を一覧表示する |
| `GET` | `/assets/{id}` | — | 単一資産を取得する |
| `POST` | `/assets/{id}/checkout` | `X-User-Id` | 資産をチェックアウトする |
| `POST` | `/assets/{id}/checkin` | `X-User-Id` | 資産をチェックインする |
| `GET` | `/assets/{id}/history` | — | 監査履歴 |

---

## データベーススキーマ

```sql
CREATE TABLE assets (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT    NOT NULL,
    holder_id  INTEGER,           -- NULL = 利用可能
    created_at TEXT    NOT NULL,
    updated_at TEXT    NOT NULL
);

CREATE TABLE asset_history (
    id       INTEGER PRIMARY KEY AUTOINCREMENT,
    asset_id INTEGER NOT NULL,
    user_id  INTEGER NOT NULL,
    action   TEXT    NOT NULL,   -- 'checkout' | 'checkin'
    acted_at TEXT    NOT NULL,
    FOREIGN KEY (asset_id) REFERENCES assets(id) ON DELETE CASCADE
);
```

---

## 排他的チェックアウトパターン

```php
public function checkout(int $assetId, int $userId): string
{
    $asset = $this->findById($assetId);
    if ($asset === null) return 'not_found';
    if (!$asset->isAvailable()) return 'unavailable';   // 409

    $now = $this->now();
    $this->pdo->prepare(
        'UPDATE assets SET holder_id = :uid, updated_at = :now WHERE id = :id AND holder_id IS NULL'
    )->execute([...]);

    $this->appendHistory($assetId, $userId, 'checkout', $now);
    return 'success';
}
```

`WHERE holder_id IS NULL` ガードにより、同時リクエスト下での二重チェックアウトを防止します（SQLite は書き込みをシリアライズ；MySQL/PgSQL はトランザクションまたは `SELECT FOR UPDATE` が必要）。

---

## IDOR 防止

```php
// 公開レスポンス — holder_id なし
public function toPublicArray(): array
{
    return ['id' => $this->id, 'name' => $this->name, 'available' => $this->isAvailable(), ...];
}

// 管理者レスポンス — holder_id を含む
public function toAdminArray(): array
{
    return [..., 'holder_id' => $this->holderId];
}
```

ハンドラーが `isAdmin()` をチェックして正しいプロジェクションを選択します:

```php
fn (Asset $a) => $isAdmin ? $a->toAdminArray() : $a->toPublicArray()
```

---

## 管理者キー（フェイルクローズ）

```php
private function isAdmin(ServerRequestInterface $request): bool
{
    if ($this->adminKey === '') return false;   // キー未設定 → 拒否
    $provided = $request->getHeaderLine('X-Admin-Key');
    return $provided !== '' && hash_equals($this->adminKey, $provided);
}
```

---

## ユーザー ID バリデーション

```php
private function resolveUserId(ServerRequestInterface $request): ?int
{
    $raw = $request->getHeaderLine('X-User-Id');
    if ($raw === '' || !ctype_digit($raw) || strlen($raw) > 18) return null;
    $id = (int) $raw;
    return $id > 0 ? $id : null;
}
```

`ctype_digit()` は O(n) で ReDoS セーフです。長さキャップで整数オーバーフローを防止します。

---

## エラーマッピング

| リポジトリ結果 | HTTP ステータス |
|---|---|
| `success` | 200 / 201 |
| `not_found` | 404 |
| `unavailable` | 409 Conflict |
| `not_holder` | 403 Forbidden |
| `already_available` | 409 Conflict |

---

## テストノート

- `AppFactory::create(?PDO, ?string)` はユニットテスト用のインメモリ SQLite を受け入れます。
- `withParsedBody($body)` はテストリクエストで呼び出す必要があります — Nyholm PSR-7 は JSON を自動解析しません。
- 公開の一覧/取得のアサーションで `holder_id` キーが存在しないことを確認します（`assertArrayNotHasKey`）。
- ライフサイクルテスト: チェックアウト → 競合 → チェックイン → 別ユーザーによる再チェックアウト。
