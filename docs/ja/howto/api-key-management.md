# API キー管理

> **FT リファレンス**: FT266 (`NENE2-FT/apikeylog`) — API キーライフサイクル: 生成、SHA-256 ハッシュ保存、プレフィックスベースのルックアップ、スコープ強制、ローテーション

このガイドでは、NENE2 アプリケーションで API キー管理を実装する方法を説明します: キーの生成、安全な保存、スコープベースの認可、失効、ローテーション。

## コア設計原則

1. **生キーを保存しない** — データベースには SHA-256 ハッシュのみ。
2. **生キーは一度だけ返す** — 作成時のみ、以降は返さない。
3. **プレフィックスベースのルックアップ、ハッシュベースの検証** — プレフィックスが DB クエリを絞り込み；`hash_equals()` が実際の認証を行う。
4. **スコープ階層** — admin ⊃ write ⊃ read；エンドポイントごとにチェック。
5. **安全なローテーション** — ロックアウトを防ぐため古いキーを失効させる前に新しいキーを作成する。

## キーフォーマット

```
nk_Vf3aB2cX9dJkQmHpNrTsUvWxYzAeBfCg
^   ^----- 43 文字の base64url(32 ランダムバイト) -----^
|
型プレフィックス（ログで識別可能）
```

`random_bytes(32)` で 256 ビットのエントロピーが得られます。これはハッシュ速度に関係なくブルートフォースは計算上不可能なため、SHA-256（高速、単目的）が適切です — パスワードとは異なり、API キーは辞書攻撃対象になりません。

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

`prefix` カラムには生キーの**最初の 16 文字**（型プレフィックス `nk` ではない）を保存します。これにより約 78 ビットの差別化が得られ、各プレフィックスは実質的に一意になり、O(1) のインデックスルックアップが可能になります。

**重要**: DB ルックアッププレフィックスとして型プレフィックス（`nk`）を使用しないでください。すべてのキーが同じ型プレフィックスを共有するため、`WHERE prefix = 'nk'` はテーブル全体をスキャンします — O(n) ルックアップかつキー数に比例したタイミングチャネルになります。

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
        // キーの最初の 16 文字 — キーごとに一意、インデックスに安全
        return substr($rawKey, 0, 16);
    }

    public function verify(string $rawKey, string $storedHash): bool
    {
        return hash_equals($storedHash, $this->hash($rawKey));
    }
}
```

`hash_equals()` は必須です。ハッシュ比較に `===` や `==` を使用するとタイミング情報が漏洩します: 64 文字の 16 進数文字列を `===` で比較すると最初の不一致で終了し、先頭の何文字が一致するかを明らかにします。

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
1. プレフィックスによるインデックスルックアップ（高速な DB クエリ）
2. 保存されたハッシュに対する `hash_equals()` 検証

すべての失敗ケース（見つからない、ハッシュ不一致、期限切れ、失効済み）に対して同じ `null` と `401` を返します — 呼び出し元はこれらを区別してはいけません。

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

未認証には `401`、認証済みだがスコープ不足には `403` を返します — キーが存在するかどうかは絶対に漏洩させません。

## レスポンスフィルタリング

`ApiKey` の `toArray()` メソッドには `key_hash` を**含めてはいけません**。生キーは作成直後の `ApiKeyCreateResult::toArray()` でのみ利用可能です。

```php
// ApiKey::toArray() — 任意のエンドポイントから返しても安全
public function toArray(): array
{
    return [
        'id', 'owner_id', 'prefix', 'scope', 'description',
        'expires_at', 'revoked_at', 'created_at', 'updated_at',
        // key_hash は意図的に除外
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

    // まず作成 — これが失敗した場合、古いキーはアクティブのまま（ロックアウトなし）
    $result = $this->create($ownerId, $old->scope, $old->description, $now, $old->expiresAt);

    // 作成後に失効 — これが失敗した場合、両方のキーが一時的に存在する（リスト経由で回復可能）
    $this->executor->execute(
        'UPDATE api_keys SET revoked_at = ?, updated_at = ? WHERE id = ?',
        [$now, $now, $oldId],
    );

    return $result;
}
```

失効後作成は危険です: REVOKE 後に CREATE が失敗すると、所有者は永久にロックアウトされます。逆の順序（作成後失効）では最悪ケースで一時的に 2 つのアクティブキーが存在します — これは観察可能かつ回復可能です。

## 有効期限

`expires_at` を ISO 日時文字列として保存します。`isActive()` でチェックします:

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

認証フローは `$now` をパラメーターとして渡し、固定タイムスタンプによるロジックのテストを可能にします。

## やってはいけないこと

| アンチパターン | リスク |
|---|---|
| 生キーを DB に保存する | DB 侵害で完全露出 |
| ハッシュ比較に `===` を使用する | タイミング攻撃がハッシュのプレフィックス長を漏洩させる |
| DB ルックアップインデックスとして型プレフィックス（`nk`）を使用する | O(n) テーブルスキャン；タイミングチャネル |
| 一覧/詳細レスポンスで `key_hash` を返す | ハッシュへのオフライン辞書攻撃 |
| ローテーションで新しいキーを作成する前に古いキーを失効させる | DB エラー時に所有者がロックアウトされる |
| "キーが見つからない" と "キーが期限切れ" で異なるエラーを返す | キー存在のオラクル |
| `X-Api-Key` ヘッダーをログに記録する | キーがログストレージに漏洩する |
