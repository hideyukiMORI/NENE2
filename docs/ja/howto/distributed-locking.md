# 分散ロッキング

分散ロックは並行プロセスが同時にクリティカルセクションを実行することを防ぎます。DB バックの分散ロックはスループットをシンプルさと引き換えにします — Redis は不要で、データを保持するのと同じ DB がロックを保持します。

## コアコンセプト

- **リソース**: ロックされるものの名前（例: `job:42`、`report:monthly-2026-05`）
- **オーナー**: ロックホルダーを識別するトークン — オーナーのみが解放または更新できる
- **有効期限（TTL）**: ロックは自動期限切れになるため、クラッシュしたオーナーが永遠にロックを保持できない
- **古いロックのクレーム**: 期限切れのロックは新しいオーナーに引き継がれる

## スキーマ

```sql
CREATE TABLE distributed_locks (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    resource    TEXT    NOT NULL UNIQUE,
    owner       TEXT    NOT NULL,
    expires_at  TEXT    NOT NULL,
    acquired_at TEXT    NOT NULL
);
```

`resource` の `UNIQUE` 制約により、リソースごとに 1 行のみが存在します。並行する INSERT は DB レベルでシリアル化されます。

## 取得ロジック

```php
public function acquire(string $resource, string $owner, string $expiresAt, string $now): ?LockRecord
{
    $existing = $this->findByResource($resource);

    if ($existing === null) {
        // ロックなし — INSERT（競合で失敗する可能性あり。呼び出し元は null を取得してリトライ）
        $this->executor->execute(
            'INSERT INTO distributed_locks (resource, owner, expires_at, acquired_at) VALUES (?, ?, ?, ?)',
            [$resource, $owner, $expiresAt, $now],
        );
        return $this->findByResource($resource);
    }

    if ($existing->isExpired($now) || $existing->owner === $owner) {
        // 期限切れ（古い）または同じオーナーが再取得 — UPDATE してクレーム
        $this->executor->execute(
            'UPDATE distributed_locks SET owner = ?, expires_at = ?, acquired_at = ? WHERE resource = ?',
            [$owner, $expiresAt, $now, $resource],
        );
        return $this->findByResource($resource);
    }

    // 別のオーナーに保持されており、まだ有効 — 取得不可
    return null;
}
```

戻り値の規則:
- 成功した場合は `LockRecord` を返す（API レスポンスでは `acquired: true`）
- 別のオーナーにロックされている場合は `null` を返す（`acquired: false`）

## オーナー強制の解放

オーナーのみが解放できます。オーナーが一致しない場合に 404 ではなく 403 を返すことで、ロックは存在するが保持していないことを呼び出し元に伝えます:

```php
return match ($result) {
    ReleaseResult::Released  => $this->json->create([], 204),
    ReleaseResult::NotFound  => $this->problems->create($request, 'not-found', 'Lock not found.', 404, ''),
    ReleaseResult::Forbidden => $this->problems->create($request, 'forbidden', 'Owner mismatch.', 403, ''),
};
```

## TTL 更新

長時間実行タスクは期限切れ前にロックを延長する必要があります。現在のオーナーのみが更新できます — 間違ったオーナーによる更新は 409 を返します（403 ではなく、権限拒否ではなく状態の競合を示すため）:

```php
if ($existing->isExpired($now)) {
    return null; // → 409: 期限切れのロックは更新できない（他者が保持している可能性あり）
}
if ($existing->owner !== $owner) {
    return null; // → 409: 間違ったオーナー
}
// expires_at を延長
```

## 古いロック検出

`LockRecord::isExpired()` は現在時刻を `expires_at` と比較します:

```php
public function isExpired(string $now): bool
{
    return $now >= $this->expiresAt;
}
```

つまり `GET /locks/{resource}` は期限切れのロックに 404 を返し（期限切れを存在しないものとして扱う）、`POST /locks/{resource}` により新しいオーナーが期限切れのロックをクレームできます。

## 設計上の決定

**なぜ Redis SETNX を使わないのか?**
Redis は単一コマンドで TTL 付きアトミックな SETNX を提供し、高スループットロッキングの本番標準です。DB バックのロッキングはデプロイがシンプルで（追加サービス不要）、残りのトランザクションデータと一貫しており、低〜中程度の競合シナリオ（バックグラウンドジョブ、レポート生成、バッチ処理）には十分です。

**なぜ再取得時に DELETE+INSERT を使わないのか?**
UPDATE は行 ID を保持してアトミックです。DELETE+INSERT はロック行が存在しない短い窓を作り、並行プロセスが INSERT してロックを盗める可能性があります。

**なぜ `acquired_at` を `expires_at` から分離するのか?**
`acquired_at` は所有権が最後に確立されたタイムスタンプです（監査に有用）。`expires_at` は更新時に変わります。分離することで曖昧さを避けられます。

**設計上のノンブロッキング**
ロックエンドポイントはロックが利用可能になるまでブロックするのではなく、`acquired: false` で即座に返します。呼び出し元はタイムアウト要件に基づいて自分のリトライ戦略（指数バックオフ、デッドレターキュー等）を実装します。
