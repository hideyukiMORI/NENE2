# ハウツー: 招待システム

> **FT リファレンス**: FT283 (`NENE2-FT/invitelog`) — 招待コードシステム: 32 文字 hex トークン（128 ビットエントロピー）、ISO 8601 日時バリデーション、pending→used ステータスライフサイクル、match 式によるステータスマッピング、IDOR 保護された招待リスト、23 テスト / 47 アサーション PASS。

このガイドでは、セキュアな招待システムの構築方法を示します — 引き換え時にアクセスを付与する一回限りのトークンを生成します。

## ユースケース

ユーザーが招待リンク（トークン）を作成して共有します。受取人がトークンを引き換えて参加します。各トークンは一回限りで時間制限があります。

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

CREATE INDEX IF NOT EXISTS idx_invitations_token ON invitations (token);
CREATE INDEX IF NOT EXISTS idx_invitations_inviter ON invitations (inviter_id, id DESC);
```

ポイント:
- `token TEXT UNIQUE` — DB レベルで 1 トークン 1 行を強制
- `invitee_id` は引き換えまで `NULL`
- `status` — `'pending'` | `'used'`
- `used_at` — 引き換え時に設定、監査タイムスタンプを提供

## エンドポイント

| メソッド | パス | 認証 | 説明 |
|--------|------|------|-------------|
| `POST` | `/invitations` | `X-User-Id` | 招待を作成する |
| `GET` | `/invitations/{token}` | なし | トークンで招待を検索する |
| `POST` | `/invitations/{token}/use` | `X-User-Id` | 招待を引き換える |
| `GET` | `/users/{userId}/invitations` | `X-User-Id`（自分のみ） | ユーザーの招待を一覧表示する |

## トークン生成

```php
/** トークン: 32 文字の小文字 hex（16 ランダムバイト = 128 ビットエントロピー）*/
public const string TOKEN_PATTERN = '/\A[0-9a-f]{32}\z/';

$token = bin2hex(random_bytes(16));
```

`random_bytes(16)` は 128 ビットの暗号論的に安全なランダムデータを生成します。hex 表現は 32 文字です。これは UUID v4 と同じエントロピーレベル（122 ビット使用可能）です。

## expires_at バリデーション

```php
private const string ISO_DATE_PATTERN = '/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/';

$expiresAt = trim((string) ($body['expires_at'] ?? ''));
if (!preg_match(self::ISO_DATE_PATTERN, $expiresAt)) {
    return $this->problem(422, 'validation-failed', 'expires_at must be ISO 8601 datetime.');
}
```

正規表現はフォーマットのみをバリデーションします。タイムゾーン処理（UTC とローカル）はアプリケーションの責任です — 一貫したタイムゾーン（例: UTC）を使用するとエッジケースを避けられます。

## ステータスライフサイクル

```
pending → used（一方向、不可逆）
```

`pending` の招待のみ引き換えられます。一度使用されると、招待は永続的に消費されます。

## match 式による引き換え

```php
$result = $this->repo->use($token, $uid);

return match ($result) {
    'not_found'    => $this->problem(404, 'not-found', 'Invitation not found.'),
    'already_used' => $this->problem(409, 'conflict', 'Invitation already used.'),
    'expired'      => $this->problem(409, 'conflict', 'Invitation has expired.'),
    default        => $this->json(['message' => 'Invitation accepted.']),
};
```

`match` は網羅的です（`switch` と異なり）: フォールスルーなし、すべてのケースを処理する必要があります。Repository は文字列の結果型を返し、ハンドラーはそれをクリーンに HTTP レスポンスにマップします。

## Repository — アトミックな引き換え

```php
/** @return 'ok'|'not_found'|'already_used'|'expired' */
public function use(string $token, int $inviteeId): string
{
    $inv = $this->findByToken($token);
    if ($inv === null) {
        return 'not_found';
    }
    if ($inv['status'] === 'used') {
        return 'already_used';
    }
    // 有効期限チェック
    $now = $this->now();
    if ($inv['expires_at'] < $now) {
        return 'expired';
    }

    // 使用済みとしてマーク
    $this->pdo->prepare('UPDATE invitations SET status = \'used\', invitee_id = ?, used_at = ? WHERE token = ?')
        ->execute([$inviteeId, $now, $token]);

    return 'ok';
}
```

チェック後更新のシーケンスは、同じトークンの並行した引き換えで TOCTOU 競合状態の可能性があります。本番では DB レベルのトランザクションか `UPDATE WHERE status = 'pending'` を使用して影響を受けた行数を確認してください。

## IDOR — 招待リスト

招待者のみが自分の招待を表示できます:

```php
$callerUid = $this->uid($req);
if ($callerUid !== $targetUid) {
    return $this->problem(404, 'not-found', 'User not found.');
}
```

ターゲットユーザーが存在するかどうかを隠すために 404 を返します（403 ではなく）。

## X-User-Id ヘッダーバリデーション

```php
private function uid(ServerRequestInterface $req): ?int
{
    $raw = $req->getHeaderLine('X-User-Id');
    if ($raw === '' || !ctype_digit($raw) || strlen($raw) > 18) {
        return null;
    }
    $id = (int) $raw;
    return $id > 0 ? $id : null;
}
```

`ctype_digit()` は ReDoS セーフ。`strlen > 18` は 64 ビットでの PHP int オーバーフローを防止。`> 0` でユーザー ID 0 を拒否。

---

## やってはいけないこと

| アンチパターン | リスク |
|---|---|
| 短い/連番のトークンを使う（4 桁 PIN） | ミリ秒でブルートフォース可能。≥128 ビットランダムを使うこと |
| `UNIQUE` 制約なしでトークンを保存する | 重複トークンの衝突が引き換えの混乱を引き起こす |
| 緩い比較で `status === 'pending'` をチェックする | PHP の `'0' == false`。常に厳格な `===` を使うこと |
| 引き換え時に有効期限バリデーションなし | 有効期限切れの招待が永続的に引き換え可能のまま |
| 招待リストの IDOR チェックで 403 を返す | ターゲットユーザーが存在することを明かす。列挙を隠すために 404 を使うこと |
| トランザクションなしのアトミックな引き換え | 並行リクエストが両方とも `pending` を見て両方が成功する — 二重引き換え |
| ステータスカラムの代わりにソフト削除（`deleted_at`） | ステータスカラムは自己文書化。`pending`/`used` は null/非 null より明確 |
| `expires_at` として任意の文字列を受け付ける | パラメーター化されていない場合は SQL インジェクション可能。パラメーター化クエリ + フォーマットバリデーションを使うこと |
| 期限切れトークンのステータスを `pending` にリセットする | 正当に期限切れになったトークンの再使用を可能にする |
