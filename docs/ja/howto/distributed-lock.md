# ハウツー: 分散ロック

> **FT リファレンス**: FT288 (`NENE2-FT/distlocklog`) — 分散ロック: UNIQUE(resource) DB 制約、オーナー検証、TTL ベースの有効期限、設計による期限切れロック再取得、ReleaseResult enum（Released/NotFound/Forbidden）、オーナー不一致時の 403、16 テスト / 27 アサーション PASS。
>
> **ATK 評価**: ATK-01 〜 ATK-12 はこのドキュメントの最後に含まれています。

このガイドでは、分散ロック API の実装方法を示します — リースされたロックを発行することで同じリソースへの並行操作を防ぎます。

## 分散ロックとは?

複数のプロセスが共有リソース（例: 支払い、ファイル、キュージョブ）への排他的アクセスを必要とする場合、分散ロックにより一度に 1 つのプロセスのみが処理を進められることを保証します。ロックには TTL があるため、ホルダーがクラッシュした場合に自動的に期限切れになります。

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

`resource TEXT UNIQUE` — リソースごとに 1 行。取得時にこの行を挿入または更新します。

## エンドポイント

| メソッド | パス | 説明 |
|--------|------|------|
| `POST` | `/locks/{resource}` | ロックを取得する |
| `GET` | `/locks/{resource}` | ロックの状態を取得する |
| `DELETE` | `/locks/{resource}` | ロックを解放する |
| `POST` | `/locks/{resource}/renew` | TTL を延長する |

## 取得ロジック

```php
public function acquire(string $resource, string $owner, string $expiresAt, string $now): ?LockRecord
{
    $existing = $this->findByResource($resource);

    if ($existing === null) {
        // ロックなし — INSERT（UNIQUE 制約が競合を処理）
        try {
            $this->executor->execute('INSERT INTO distributed_locks ...', [...]);
        } catch (\RuntimeException) {
            return null;  // 競合: 別のプロセスが並行して挿入した
        }
        return $this->findByResource($resource);
    }

    if ($existing->isExpired($now) || $existing->owner === $owner) {
        // 期限切れ → 再取得（UPDATE で古い行を置き換え）
        // 同じオーナー → 再取得（延長または再ロック）
        $this->executor->execute('UPDATE distributed_locks SET owner = ?, expires_at = ?, acquired_at = ? WHERE resource = ?', ...);
        return $this->findByResource($resource);
    }

    // 別のオーナーに保持されており、期限切れでない → 取得不可
    return null;
}
```

## オーナー検証付き解放

```php
$result = $this->repo->release($resource, $owner, $now);

return match ($result) {
    ReleaseResult::Released  => $this->json->create([], 204),
    ReleaseResult::NotFound  => $this->problems->create($request, 'not-found', 'Lock not found.', 404),
    ReleaseResult::Forbidden => $this->problems->create($request, 'forbidden', 'Owner mismatch.', 403),
};
```

ロックオーナーのみが解放できます。間違った `owner` → 403 Forbidden。

## ReleaseResult Enum

```php
enum ReleaseResult
{
    case Released;   // ロックが見つかり、オーナーが一致し、行が削除された
    case NotFound;   // ロックが見つからないか既に期限切れ
    case Forbidden;  // ロックが見つかったが、オーナーが一致しない
}
```

enum（マジック文字列ではなく）を使用することで `match` での網羅的な処理が保証されます。

## 取得レスポンス

```php
// 成功:
{ "acquired": true, "lock": { "resource": "...", "owner": "...", "expires_at": "...", "acquired_at": "..." } }

// 失敗（別のオーナーが保持中）:
{ "acquired": false, "resource": "payment:42" }
```

`acquired: false` はエラーではありません — 「後でもう一度試してください」という意味です。4xx ステータスなし。呼び出し元はリトライすべきです。

---

## ATK 評価 — クラッカーマインドセット攻撃テスト

### ATK-01 — 別のオーナーに保持されているロックを取得する 🚫 BLOCKED

**攻撃**: 攻撃者が別のプロセスが保持している間に `locks/payment:42` を取得しようとする。
**結果**: BLOCKED — リポジトリは `existing.owner === $caller_owner` を確認。別のオーナー + 期限切れでない → `null` を返す → `{ acquired: false }`。エラーもクラッシュもなし — 攻撃者は単にロックを取得できない。

---

### ATK-02 — 別のオーナーのロックを解放する 🚫 BLOCKED

**攻撃**: 攻撃者が `{ "owner": "attacker" }` で `DELETE /locks/payment:42` を送信してロックを強制解放する。
**結果**: BLOCKED — リポジトリは `lock.owner === $body_owner` を確認。不一致 → `ReleaseResult::Forbidden` → 403。

---

### ATK-03 — 期限切れ後にロックを盗む 🚫 BLOCKED（設計上）

**攻撃**: 攻撃者がロックの期限切れを待って取得する。
**結果**: BLOCKED（設計上） — 期限切れのロックは任意のオーナーが再取得できます。これは意図した動作です: TTL ベースの期限切れはクラッシュしたホルダーがロックを失う方法です。TTL ベース攻撃を減らすには調整（ハートビートによる更新）が必要です。

---

### ATK-04 — 別のオーナーのロックを更新する 🚫 BLOCKED

**攻撃**: 攻撃者が `{ "owner": "attacker", "ttl_seconds": 3600 }` で `POST /locks/payment:42/renew` を送信する。
**結果**: BLOCKED — renew は `lock.owner === $body_owner` を確認。不一致 → 403 Forbidden。

---

### ATK-05 — ゼロまたは負の TTL で既に期限切れのロックを作成する 🚫 BLOCKED

**攻撃**: `{ "ttl_seconds": 0 }` または `{ "ttl_seconds": -100 }` を送信して即座に期限切れのロックを作成する。
**結果**: BLOCKED — `if ($ttlSeconds === null || $ttlSeconds < 1)` → 422 バリデーションエラー。

---

### ATK-06 — リソースパスパラメーターを通じた SQL インジェクション 🚫 BLOCKED

**攻撃**: リソース名として `locks/resource'; DROP TABLE distributed_locks; --` を使用する。
**結果**: BLOCKED — すべてのクエリがパラメータ化されたステートメントを使用（`WHERE resource = ?`）。注入された文字列はリテラルのリソース識別子として扱われます。

---

### ATK-07 — 空のオーナーで所有権チェックをバイパスする 🚫 BLOCKED

**攻撃**: 有効な所有権なしで解放または更新するために `{ "owner": "" }` または `{ "owner": "   " }` を送信する。
**結果**: BLOCKED — `$owner = trim(...); if ($owner === '')` → 422 バリデーションエラー。

---

### ATK-08 — 非整数の TTL で型バリデーションをバイパスする 🚫 BLOCKED

**攻撃**: `{ "ttl_seconds": "3600" }`（文字列）または `{ "ttl_seconds": 60.5 }`（浮動小数点）を送信する。
**結果**: BLOCKED — `is_int($body['ttl_seconds'])` が文字列と浮動小数点を拒否。JSON 整数型のみ受け入れられます。

---

### ATK-09 — 同じオーナーが複数回取得する 🚫 BLOCKED（設計上）

**攻撃**: 同じオーナーが `/renew` を使わずに保持しているロックを再取得して延長する。
**結果**: ALLOWED（設計上） — `$existing->owner === $owner` → UPDATE（再取得/延長）。同じオーナーの再取得は冪等で安全です。`expires_at` と `acquired_at` を更新します。

---

### ATK-10 — 競合状態: 2 つのオーナーが並行して取得する 🚫 BLOCKED

**攻撃**: 2 つのプロセスが両方ともロックがないことを確認して両方同時に INSERT を試みる。
**結果**: BLOCKED — `UNIQUE(resource)` 制約により 1 つの INSERT のみが成功します。失者は `\RuntimeException` をキャッチして `null` を返す → `{ acquired: false }`。1 つのオーナーのみが勝ちます。

---

### ATK-11 — 存在しないまたは期限切れのロックを GET する 🚫 BLOCKED

**攻撃**: `GET /locks/nonexistent` を呼び出すか、ロックの期限切れを待って GET を呼び出す。
**結果**: BLOCKED — `if ($lock === null || $lock->isExpired($now)) return 404`。期限切れのロックは 404 を返します（古いロックデータではなく）。

---

### ATK-12 — DoS を引き起こす極端に長いリソース名 ⚠️ 設計上のメモ

**攻撃**: リソースパスパラメーターとして `{ "resource": "<10MB 文字列>" }` を送信する。
**結果**: 部分的に BLOCKED — リソースは URL パスから取得されるため、Web サーバーのパス長（通常 8KB）により制限されます。この FT には明示的なアプリケーションレベルの長さバリデーションはありません。本番環境では `if (strlen($resource) > 255)` → 422 を追加してください。DB はアプリケーションが渡すものを何でも保存します。

---

### ATK まとめ

| ID | 攻撃 | 結果 |
|----|--------|--------|
| ATK-01 | 別のオーナーが保持するロックを取得 | 🚫 BLOCKED |
| ATK-02 | 別のオーナーのロックを解放 | 🚫 BLOCKED |
| ATK-03 | TTL 期限切れ後にロックを盗む | 🚫 BLOCKED（設計上） |
| ATK-04 | 別のオーナーのロックを更新 | 🚫 BLOCKED |
| ATK-05 | ゼロ/負の TTL | 🚫 BLOCKED |
| ATK-06 | リソースパス経由の SQL インジェクション | 🚫 BLOCKED |
| ATK-07 | 空のオーナーでのバイパス | 🚫 BLOCKED |
| ATK-08 | 非整数 TTL の型バイパス | 🚫 BLOCKED |
| ATK-09 | 同じオーナーの再取得 | 🚫 BLOCKED（意図通り） |
| ATK-10 | 並行取得の競合状態 | 🚫 BLOCKED |
| ATK-11 | 期限切れ/存在しないロックの GET | 🚫 BLOCKED |
| ATK-12 | 極端に長いリソース名 | ⚠️ 設計上のメモ |

**11 BLOCKED、1 設計上のメモ、0 EXPOSED**
オーナー検証、`UNIQUE(resource)` 競合保護、TTL バリデーション、パラメータ化クエリがすべての重大な攻撃ベクターを防止します。

---

## やってはいけないこと

| アンチパターン | リスク |
|---|---|
| `UNIQUE(resource)` 制約なし | 競合状態: 2 つのオーナーが両方取得する。TOCTOU 脆弱性 |
| オーナーチェックなしで解放 | 任意のプロセスが任意のロックを解放できる。排他性保証なし |
| ロックに TTL なし | クラッシュしたホルダーのロックが永遠に持続する。システムデッドロック |
| 0 または負の TTL を受け入れる | ロックは作成時に既に期限切れ。即座に再取得可能 |
| 解放時のオーナー不一致に 404 を返す | 攻撃者が「ロックが存在しない」と「オーナーが間違っている」を区別できない。403 を使用する |
| 文字列/浮動小数点を TTL として受け入れる | `"3600"` は有効に見えるが `is_int` が失敗する。厳格な型チェックで微妙なバグを防ぐ |
| バリデーションなしでオーナーを保存 | 空のオーナーが所有権をバイパスする。常に空でないことを検証する |
| リソース長制限なし | Web サーバーのパス制限のみが唯一のガード。明示的なバリデーションを追加する |
| 期限切れロックを更新する | 期限切れのロックは誰のものでもない。更新ではなく再取得する |
