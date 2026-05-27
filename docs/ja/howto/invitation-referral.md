# ハウツー: 招待/紹介 API

このガイドでは、NENE2 を使って有効期限と一回限りの使用を備えたトークンベースの招待システムの構築方法を示します。
**invitelog** フィールドトライアル（FT221）で実証されたパターンです。

## 機能

- 招待トークンを生成する（`bin2hex(random_bytes(16))` = 32 文字の小文字 hex）
- 招待ごとの有効期限を設定する（ISO 8601）
- 招待を承認/使用する（一回限り、招待された人を記録）
- ユーザースコープの招待リスト（IDOR: 自分のみ表示可能）
- ステータスライフサイクル: `pending → used`（使用時に期限切れを検出）

## スキーマ

```sql
CREATE TABLE IF NOT EXISTS invitations (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    token       TEXT    NOT NULL UNIQUE,
    inviter_id  INTEGER NOT NULL,
    invitee_id  INTEGER,
    status      TEXT    NOT NULL DEFAULT 'pending',
    expires_at  TEXT    NOT NULL,
    used_at     TEXT,
    created_at  TEXT    NOT NULL
);
```

## エンドポイント

| メソッド | パス | 認証 | 説明 |
|--------|------|------|-------------|
| `POST` | `/invitations` | ユーザー | 招待を作成する（トークンを返す） |
| `GET` | `/invitations/{token}` | 公開（トークン = シークレット） | 招待ステータスを取得する |
| `POST` | `/invitations/{token}/use` | ユーザー | 招待を承認する |
| `GET` | `/users/{userId}/invitations` | ユーザー（自分のみ） | 自分の招待を一覧表示する |

## トークン生成

```php
$token = bin2hex(random_bytes(16)); // 32 文字の小文字 hex、暗号論的に安全
```

パスパラメーターでバリデーションされるトークンパターン:

```php
/** トークン: 32 文字の小文字 hex（16 ランダムバイト）*/
public const string TOKEN_PATTERN = '/\A[0-9a-f]{32}\z/';
```

## 一回限りの使用ロジック

```php
/** @return 'ok'|'not_found'|'already_used'|'expired' */
public function use(string $token, int $inviteeId): string
{
    $inv = $this->findByToken($token);
    if ($inv === null) return 'not_found';
    if ($inv['status'] === 'used') return 'already_used'; // → 409
    if ($inv['expires_at'] < $this->now()) return 'expired'; // → 409

    // 使用済みとしてマーク + 招待された人を記録
    $this->pdo->prepare(
        "UPDATE invitations SET status = 'used', invitee_id = :iid, used_at = :now WHERE token = :token"
    )->execute([...]);

    return 'ok';
}
```

## IDOR 保護

招待リストエンドポイントは自分のみのアクセスを強制します:

```php
$callerUid = $this->uid($req);
if ($callerUid !== $targetUid) {
    return $this->problem(404, 'not-found', 'User not found.');
}
```

`GET /invitations/{token}` エンドポイントはトークン自体をシークレットとして使用します — トークンを知ることでアクセスが付与されます。これが「トークン = 能力」パターンです。

## セキュリティパターン

- **`bin2hex(random_bytes(16))`**: 暗号論的に安全な 128 ビットエントロピートークン
- **トークンパターンバリデーション**: `/\A[0-9a-f]{32}\z/` — SQL インジェクション、過大サイズのトークンをブロック
- **`ctype_digit()`**: ユーザー ID パスパラメーターの ReDoS セーフな整数バリデーション
- **ISO 8601 有効期限バリデーション**: 正規表現パターン + 辞書的比較（UTC）
- **使用時に有効期限チェック**: 事前フィルタリングなし — トークンルックアップが結果を返し、その後有効期限をチェック
