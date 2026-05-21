# Field Trial Report — FT122: Distributed Locking

**Date**: 2026-05-21
**Release**: v1.5.56
**App**: `distlocklog` (`/home/xi/docker/NENE2-FT/distlocklog/`)
**Tests**: 16/16 passed
**PHPStan**: level 8, 0 errors
**CS**: clean

## Theme

Implement a DB-backed distributed lock with owner-enforced acquisition, release, and TTL renewal. Key patterns: stale lock claim (expired lock taken by new owner), 403 vs 404 on wrong-owner release, 409 on expired-lock renew.

## Core design

### Acquisition

`acquire()` checks for an existing row first:
- No row → INSERT (catches race via UNIQUE constraint; concurrent INSERT fails with RuntimeException → caller gets `acquired: false`)
- Row exists, expired or same owner → UPDATE to claim
- Row exists, held by another owner, not expired → return null (`acquired: false`)

### Owner-enforced operations

Release returns 403 (not 404) when the owner mismatches — the lock exists, the caller just doesn't hold it. This distinction matters: 404 would suggest retrying acquisition, but 403 correctly signals "someone else has this."

Renew returns 409 when the lock is expired or owned by someone else. An expired lock cannot be renewed because another process may have already claimed it.

### Expiry detection

`LockRecord::isExpired(string $now)` compares string timestamps (ISO 8601 format). This makes test isolation simple — pass a crafted `$now` instead of sleeping.

## Test coverage (16 tests)

| Category | Tests |
|---|---|
| Acquire new resource | 1 |
| Same-owner re-acquire (idempotent) | 1 |
| Contested lock returns `acquired: false` | 1 |
| Missing owner / ttl_seconds validation | 3 |
| Get active lock (200), not found (404) | 2 |
| Release: correct owner (204), wrong owner (403), not found (404) | 3 |
| After release, new owner can acquire | 1 |
| Expired lock claimed by new owner | 1 |
| Renew: correct owner (200), wrong owner (409), expired (409) | 3 |

## Developer Experience (DX) Review

### ペルソナ1: 初心者（プログラミング歴1.5年・PHP独学・女性・バックエンド志望）

**「ロックってなんで必要？」:** 同じジョブが2つのワーカーで同時に動いたらどうなるのか、最初はイメージしにくい。「二重課金」「在庫の二重消費」という具体例を出すと理解が早い。TTL の存在理由（「クラッシュしたプロセスがロックを永久に持ち続けないように」）も同様に説明が必要。

**`acquired: false` の扱い方:** ロックが取れなかったとき「どうすればいい？」という疑問が出る。リトライのコードを書く必要があると気づくまでに時間がかかる。howto に「リトライは呼び出し側の責任」と明記しておくと助かる。

### ペルソナ2: ロースキル経験者（PHP歴4年・受託Web開発・男性・SES）

**ownner チェックを省略したくなる:** `DELETE FROM distributed_locks WHERE resource = ?` だけで解放する実装を書きがち。所有権チェックがないと「Aプロセスが取ったロックをBプロセスが解放できてしまう」という問題が発生する。なぜ owner を確認するのか、という動機が先に必要。

**TTL 更新を忘れる:** 長時間処理を書くとき、ロックの有効期限が切れることを意識しない実装が多い。「10分かかる処理に30秒のTTLを設定する」というミスを犯しやすい。renew API の存在を知っていても、呼ぶのを忘れる。

### ペルソナ3: フロントエンド寄り経験者（React/TS歴4年・フルスタック転向中・ノンバイナリ）

**`acquired: false` の型定義:** レスポンスの shape が `{ acquired: true, lock: {...} }` と `{ acquired: false }` で異なる。TypeScript で型付けするには discriminated union が必要:
```typescript
type AcquireResponse = 
  | { acquired: true; lock: LockRecord }
  | { acquired: false };
```
この非対称レスポンスは意図的設計だが、クライアントに型負担が生じる。

**ポーリングパターン:** 「ロックが取れるまで待ちたい」という要求に対して、フロントからポーリングするのは自然だが、WebSocket やサーバープッシュの方が効率的だと気づく経路が必要。

### ペルソナ4: バックエンド経験者（Laravel歴6年・男性・リードエンジニア）

**Redis SETNX との比較:** Redis の `SET key value NX PX milliseconds` は1コマンドで原子的にロック取得できる。DB版は SELECT → INSERT/UPDATE の2ステップになり、厳密な原子性を DB のトランザクションに頼る必要がある。高頻度な競合がある環境では Redis を選ぶべき。低〜中程度の競合（バッチジョブ・定期レポート）なら DB で十分。

**Redlock との比較:** 複数の独立した Redis ノードでロックを取る Redlock プロトコルは DB 版では実現できない。単一 DB のシングルポイント障害を許容できるかどうかがアーキテクチャ選択のポイント。

### ペルソナ5: シニアエンジニア（設計・コードレビュー担当・女性・12年）

**コードレビューポイント:**
1. `INSERT` の失敗（UNIQUE 制約違反）を `RuntimeException` でキャッチして `null` を返す実装が正しいか — PDO は正しく例外を上げるか確認
2. `isExpired()` の境界: `$now >= $this->expiresAt` は「ちょうど期限の秒」に expired 判定される。実用上は問題ないが、精度要件が高い場合はミリ秒精度が必要
3. renew で wrong-owner のときに 403 ではなく 409 を返す設計 — 409 が正しい（状態の競合）。403 はアクセス制御の拒否であり意味が違う

**UUIDv4 を owner にすべき:** `owner` に固定の `worker-1` を使うと再起動後に同じ owner 名になる。各プロセス起動時に UUID を生成すれば owner の一意性が保証される。

### ペルソナ6: 設計者・ポリシー照合（NENE2設計ポリシー目線）

**ポリシー整合:**
- `LockRecord::isExpired()` をレコード側に置く設計は「ドメインロジックを HTTP から独立させる」NENE2 ポリシーと整合
- `ReleaseResult` enum による match 式は型安全な分岐 — 追加ケースの対応漏れを PHPStan がキャッチできる
- `$now` を文字列引数で注入することで `sleep()` なしのテストが可能 — NENE2 の「決定論的テスト」方針に沿っている

**非ブロッキング設計はフレームワークポリシーと整合:** NENE2 は PHP-FPM 前提（非同期なし）なので、ブロッキングポーリングは実装できない。`acquired: false` を即返す設計はこの制約と整合している。

## Issues / PRs

- Issue #746: このトライアルの起票 → `docs/howto/distributed-locking.md` で解消
