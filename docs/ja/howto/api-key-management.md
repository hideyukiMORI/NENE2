# API キー管理

> **FT リファレンス**: FT266 (`NENE2-FT/apikeylog`) — API キーライフサイクル: 生成、SHA-256 ハッシュストレージ、プレフィックスベースのルックアップ、スコープ強制、ローテーション

このガイドでは、NENE2 アプリケーションでの API キー管理の実装を説明します: キー生成、安全なストレージ、スコープベースの認可、失効、ローテーション。

## コア設計原則

1. **生のキーを保存しない** — データベースには SHA-256 ハッシュのみ。
2. **生のキーを一度だけ返す** — 作成時のみ、それ以降は返さない。
3. **プレフィックスベースのルックアップ、ハッシュベースの検証** — プレフィックスで DB クエリを絞り込み、`hash_equals()` が実際の認証を行う。
4. **スコープ階層** — admin ⊃ write ⊃ read。エンドポイントごとにチェックする。
5. **安全にローテーション** — ロックアウトを防ぐために古いキーを失効させる前に新しいキーを作成する。

## キーフォーマット

```
nk_Vf3aB2cX9dJkQmHpNrTsUvWxYzAeBfCg
^   ^----- base64url(32 ランダムバイト) 43 文字 -----^
|
型プレフィックス（ログで識別可能）
```

`random_bytes(32)` は 256 ビットのエントロピーを提供します。これはハッシュ速度に関係なくブルートフォースは計算不可能なため、SHA-256（高速、単一目的）が適切です — パスワードとは異なり、API キーは辞書攻撃できません。

## スキーマ

```sql
CREATE TABLE IF NOT EXISTS api_keys (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    owner_id    INTEGER NOT NULL,
    prefix      TEXT    NOT NULL,     -- 生キーの最初の 16 文字（ルックアップインデックス）
    key_hash    TEXT    NOT NULL UNIQUE,
    scope       TEXT    NOT NULL DEFAULT 'read',
    description TEXT    NOT NULL DEFAULT '',
    expires_at  TEXT,
    revoked_at  TEXT,
    created_at  TEXT    NOT NULL,
    updated_at  TEXT    NOT NULL
);
```

`prefix` カラムには**生キーの最初の 16 文字**が保存されます（型プレフィックス `nk` ではない）。これにより約 78 ビットの差別化が得られ、各プレフィックスは事実上ユニークで O(1) のインデックスルックアップが可能になります。

**重要**: 型プレフィックス（`nk`）を DB ルックアップのプレフィックスとして使用しないでください。すべてのキーが同じ型プレフィックスを共有するため、`WHERE prefix = 'nk'` はテーブル全体をスキャンします — O(n) ルックアップとキー数に比例するタイミングチャネル。

## キー生成

```php
final class ApiKeyGenerator
{
    private const string PREFIX = 'nk';
    private const int    BYTES  = 32;

    public function generate(): string
    {
        $raw = random_bytes(self::BYTES);
        return self::PREFIX . '_' . rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    public function hash(string $rawKey): string
    {
        return hash('sha256', $rawKey);
    }

    public function extractPrefix(string $rawKey): string
    {
        // フルキーの最初の 16 文字 — キーごとにユニーク、インデックスに安全
        return substr($rawKey, 0, 16);
    }

    public function verify(string $rawKey, string $storedHash): bool
    {
        return hash_equals($storedHash, $this->hash($rawKey));
    }
}
```

`hash_equals()` は必須です。ハッシュ比較に `===` または `==` を使用するとタイミング情報が漏れます: 64 文字の hex 文字列を `===` で比較すると最初の不一致で終了し、先頭の何文字が一致するかを明かします。

## 認証フロー

```php
public function authenticate(string $rawKey, string $now): ?ApiKey
{
    $prefix = $this->generator->extractPrefix($rawKey);

    $rows = $this->executor->fetchAll(
        'SELECT * FROM api_keys WHERE prefix = ?',
        [$prefix],
    );

    foreach ($rows as $row) {
        $key = $this->hydrate($row);
        if ($this->generator->verify($rawKey, $key->keyHash) && $key->isActive($now)) {
            return $key;
        }
    }

    return null;
}
```

2 段階アプローチ:
1. プレフィックスによるインデックスルックアップ（高速 DB クエリ）
2. 保存されたハッシュに対する `hash_equals()` 検証

すべての失敗ケース（見つからない、ハッシュ間違い、期限切れ、失効済み）に同じ `null` と `401` を返します — 呼び出し元はそれらを区別してはなりません。

## スコープ階層

```php
enum ApiKeyScope: string
{
    case Read  = 'read';
    case Write = 'write';
    case Admin = 'admin';

    public function allows(self $required): bool
    {
        return match ($required) {
            self::Read  => true,
            self::Write => $this === self::Write || $this === self::Admin,
            self::Admin => $this === self::Admin,
        };
    }
}
```

エンドポイントレベルでスコープを強制します:

```php
private function requireScope(ServerRequestInterface $request, ApiKeyScope $required): ApiKey|ResponseInterface
{
    $rawKey = $request->getHeaderLine('X-Api-Key');
    if ($rawKey === '') {
        return $this->problems->create($request, 'unauthorized', 'Missing X-Api-Key header.', 401, '');
    }

    $key = $this->repo->authenticate($rawKey, $now);
    if ($key === null) {
        return $this->problems->create($request, 'unauthorized', 'Invalid or expired API key.', 401, '');
    }

    if (!$key->scope->allows($required)) {
        return $this->problems->create($request, 'forbidden', 'Insufficient scope.', 403, '');
    }

    return $key;
}
```

未認証には `401`、認証済みだがスコープ不足には `403` を返します — キーが存在するかどうかを漏らさないでください。

## レスポンスフィルタリング

`ApiKey` の `toArray()` メソッドは `key_hash` を**含めてはなりません**。生のキーは作成直後に `ApiKeyCreateResult::toArray()` を通じてのみ取得できます。

```php
// ApiKey::toArray() — どのエンドポイントからも安全に返せる
public function toArray(): array
{
    return [
        'id', 'owner_id', 'prefix', 'scope', 'description',
        'expires_at', 'revoked_at', 'created_at', 'updated_at',
        // key_hash は意図的に省略
    ];
}

// ApiKeyCreateResult::toArray() — 作成エンドポイントのみ
public function toArray(): array
{
    return array_merge($this->key->toArray(), ['key' => $this->rawKey]);
}
```

## キーローテーション — 安全な順序

**常に古いキーを失効させる前に新しいキーを作成してください。**

```php
public function rotate(int $oldId, int $ownerId, string $now): ?ApiKeyCreateResult
{
    $old = $this->findById($oldId);
    if ($old === null || $old->ownerId !== $ownerId || $old->isRevoked()) {
        return null;
    }

    // 先に作成する — これが失敗した場合、古いキーはアクティブのまま（ロックアウトなし）
    $result = $this->create($ownerId, $old->scope, $old->description, $now, $old->expiresAt);

    // 後で失効させる — これが失敗した場合、両方のキーが一時的に存在する（リスト経由で復元可能）
    $this->executor->execute(
        'UPDATE api_keys SET revoked_at = ?, updated_at = ? WHERE id = ?',
        [$now, $now, $oldId],
    );

    return $result;
}
```

失効後作成は危険です: REVOKE 後に CREATE が失敗すると、オーナーは永続的にロックアウトされます。逆（作成後失効）では最悪のケースは 2 つのアクティブなキーが一時的に存在することです — 観察可能で復元可能です。

## 有効期限

`expires_at` を ISO 日時文字列として保存します。`isActive()` で確認します:

```php
public function isActive(string $now): bool
{
    return !$this->isRevoked() && !$this->isExpired($now);
}

public function isExpired(string $now): bool
{
    return $this->expiresAt !== null && $this->expiresAt < $now;
}
```

認証フローは `$now` をパラメーターとして渡し、固定タイムスタンプでのロジックのテストが可能になります。

## やってはいけないこと

| アンチパターン | リスク |
|---|---|
| 生のキーを DB に保存する | DB 侵害での完全な露出 |
| ハッシュ比較に `===` を使用する | タイミング攻撃でハッシュプレフィックスの長さが漏れる |
| 型プレフィックス（`nk`）を DB ルックアップインデックスとして使用する | O(n) テーブルスキャン。タイミングチャネル |
| 一覧/詳細レスポンスに `key_hash` を返す | ハッシュへのオフライン辞書攻撃 |
| ローテーションで新しいキー作成前に古いキーを失効させる | DB エラーでオーナーがロックアウト |
| 「キーが見つからない」と「キーが期限切れ」で異なるエラーを返す | キー存在のオラクル |
| `X-Api-Key` ヘッダーをログに記録する | キーがログストレージに漏れる |
