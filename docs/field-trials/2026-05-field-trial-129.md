# Field Trial Report — FT129: Event Sourcing (Basic)

**Date**: 2026-05-21
**Release**: v1.5.63
**App**: `eventsourcelog` (`/home/xi/docker/NENE2-FT/eventsourcelog/`)
**Tests**: 17/17 passed
**PHPStan**: level 8, 0 errors
**CS**: clean
**Special**: Vulnerability Assessment (every 3rd FT — FT114, FT117, FT120, FT123, FT126, FT129...)

## Theme

Implement basic event sourcing for a bank account aggregate. Events are immutable and append-only. Account balance is derived by replaying the event stream — no balance column is stored. The vulnerability assessment examines whether the event log can be corrupted, whether balance can be manipulated via crafted amounts, and whether cross-account isolation holds.

## Core Design

### Append-Only Event Log

The `events` table is write-once. The application has no `DELETE` or `UPDATE` endpoint for events. Event types are defined as PHP constants (`TYPE_DEPOSITED`, `TYPE_WITHDRAWN`) and are never accepted as user input — the handler chooses the event type based on the route called.

### Balance via Replay

```
account_created(owner=Alice) → balance: 0
deposited(amount=1000)       → balance: 1000
deposited(amount=500)        → balance: 1500
withdrawn(amount=300)        → balance: 1200
```

`ORDER BY id ASC` ensures deterministic replay. Timestamp-based ordering would be fragile if two events share the same second.

### Amount Validation

Two layers:
1. `is_int($body['amount'])` — rejects floats (e.g. `1.9` → rejected, not silently truncated to `1`)
2. `$amount > 1_000_000_000` — rejects oversized values that could overflow when summed

Without the integer check, `{"amount": 1.9}` would cast to `1` and `{"amount": 1e18}` would cast to PHP_INT_MIN (undefined behavior on 64-bit).

## Vulnerability Assessment

### VULN-A: Oversized amount causes integer overflow in balance replay — FIXED

**Initial state**: Amount validation was `$amount <= 0` with `(int) $body['amount']`. A deposit of `{"amount": 9999999999999999}` (within PHP float range) would cast to a large integer. Repeated large deposits would cause `replayBalance()` to return a float, silently breaking arithmetic.

**Risk**: An attacker could make the balance look like a very large number (or wrap around to negative) by depositing a carefully chosen large amount. Subsequent withdrawals using the inflated balance would succeed.

**Fix**: Added `is_int()` check (rejects floats) and upper bound `$amount > 1_000_000_000` (prevents summing to overflow).

```php
// BEFORE — accepted floats and large numbers
$amount = isset($body['amount']) ? (int) $body['amount'] : 0;
if ($amount <= 0) { return 422; }

// AFTER — strict int, bounded
$amount = isset($body['amount']) && is_int($body['amount']) ? $body['amount'] : 0;
if ($amount <= 0 || $amount > 1_000_000_000) { return 422; }
```

**Tests**: `testDepositOversizedAmountReturns422`, `testDepositFloatAmountReturns422`

### VULN-B: Event type injection via API — NOT PRESENT

No route accepts `event_type` as input. Handlers hardcode the event type:
```php
$this->repo->appendEvent($id, DomainEvent::TYPE_DEPOSITED, ['amount' => $amount], $now);
```
An attacker cannot append an arbitrary event type via the API.

### VULN-C: Event mutation (UPDATE/DELETE) — NOT PRESENT

No API endpoint modifies or deletes events. Events are append-only by design.

### VULN-D: Cross-account balance manipulation — NOT PRESENT

All queries filter by `aggregate_id`. Replaying account A's events never includes account B's events.

### VULN-E: Insufficient funds bypass — NOT PRESENT

Balance is replayed immediately before recording a withdrawal. The check is:
```php
if ($amount > $balance) { return 422; }
```
No TOCTOU issue because all writes are synchronous in this single-process model.

### VULN-F: Negative amount deposit — NOT PRESENT

`$amount <= 0` check catches negative amounts (−100) and zero.

### VULN-G: Payload JSON injection — NOT PRESENT

Payload is always `['amount' => $amount]` where `$amount` is a validated integer. No user-controlled keys enter the payload.

### Summary

| ID | Finding | Severity | Status |
|---|---|---|---|
| VULN-A | Oversized/float amount causes int overflow in balance replay | Medium | **FIXED** |
| VULN-B | Event type injection via API | High | Not present |
| VULN-C | Event mutation (UPDATE/DELETE) | High | Not present |
| VULN-D | Cross-account balance manipulation | High | Not present |
| VULN-E | Insufficient funds bypass | High | Not present |
| VULN-F | Negative amount deposit | Medium | Not present |
| VULN-G | Payload JSON injection | Medium | Not present |

## Files

```
database/schema.sql
src/EventSource/Account.php
src/EventSource/DomainEvent.php
src/EventSource/EventSourceRepository.php
src/EventSource/RouteRegistrar.php
tests/EventSource/EventSourceTest.php    (17 tests)
docs/howto/event-sourcing.md
```

## Developer Experience (DX) Review

### ペルソナ1: 初心者（プログラミング歴1.5年・PHP独学・バックエンド志望）

「なぜバランスをカラムに保存しないの？」という疑問が最初に来る。「全部イベントから計算できる」ということを理解するには、「履歴から現在状態を計算するとはどういうことか」という発想の転換が必要。銀行口座の例は直感的で良い（入金・出金の記録から残高を計算する）。`event_type` が `TEXT` 型で保存されていることに「なぜ ENUM を使わないの？」と感じる — PHP 側で定数として管理していることは理解できるが、DB レベルの制約がないことが気になるかもしれない。`payload` が JSON 文字列として保存されていることも「なぜ別テーブルにしないの？」という疑問になる。

### ペルソナ2: ロースキル経験者（PHP歴4年・受託Web開発・SES）

「本番で大量のイベントがあったとき、毎回全件スキャンするのでは？」という疑問が出る。スナップショットパターン（定期的に集約状態を保存してリプレイ起点を短くする）を知っていると「基本実装ではスナップショットがない」と指摘したくなる。また「ORDER BY id ASC は適切か？id は挿入順を保証するか？」という疑問 — AUTO INCREMENT な SQLite では挿入順と id の順序は一致するが、MySQL の InnoDB でも PRIMARY KEY でのソートは挿入順を保証する（ただし UUID PRIMARY KEY なら話が別）。この設計判断を howto に記録してあるのは良い。

### ペルソナ3: フロントエンド寄り経験者（React/TS歴4年・フルスタック転向中）

`GET /accounts/{id}/events` のレスポンスが便利 — Redux の action ログのように使えて、状態の変化を時系列で UI に表示しやすい。`payload` フィールドが `{"amount": 750}` のような構造化オブジェクトになっているのも TypeScript で型定義しやすい。「リアルタイム更新（WebSocket）と組み合わせると、イベントをストリームとして受け取れる」という発展可能性が見えやすい。気になるのはページネーション — 大量のイベントがある場合の `GET /accounts/{id}/events` は全件返すのか？カーソルページネーションとの組み合わせ（FT100）が必要になるかもしれない。

### ペルソナ4: バックエンド経験者（Laravel歴6年・リードエンジニア）

「`replayBalance()` がO(n)なのは許容できるか？」が最初の設計評価。FT では単純実装として適切だが、本番では 10,000 イベントのアカウントで問題になる。スナップショット戦略（N イベントごとに残高をスナップショットテーブルに保存）を追加するのが実装上の次のステップ。「`appendEvent()` と `findOrCreate` の間にトランザクションは必要か？」と確認すると、`appendEvent()` は単純な INSERT なので問題ない。ただし「残高チェック → 出金イベント記録」の間に別のリクエストが同じアカウントの残高を変えた場合（並行出金）に二重出金が起きる可能性がある — 楽観的ロック（FT105）との組み合わせが必要な場面。

### ペルソナ5: シニアエンジニア（設計・コードレビュー担当・12年）

レビューで最初に確認するのは「events テーブルに UPDATE/DELETE するエンドポイントがないか」（ない — イベント不変性が担保）と「event_type が定数で管理されているか」（DomainEvent クラスに定数あり — 良い）。VULN-A（整数オーバーフロー）の指摘は正しく、`is_int()` による型チェックは PHPStan でも明示的にできる設計。「並行出金」問題はこの FT のスコープ外だが、howto に「楽観的バージョニングと組み合わせる場合は aggregate_version でスナップショットを取る」と書くとより実用的になる。PHPStan level 8 が通過しているのは `@var array<string, mixed>` の型アノテーションが適切に使われているから — 良い実践。

### ペルソナ6: 設計者・ポリシー照合（NENE2 設計ポリシー目線）

- **明示的ルーティング**: ✓ — 5 ルートが RouteRegistrar に一覧。
- **薄いコントローラー**: ✓ — バリデーション → リポジトリ → レスポンス。
- **No magic**: ✓ — イベントリプレイが明示的な foreach ループ。`replayBalance()` の実装が透明。
- **RFC 9457**: ✓ — 全エラーが ProblemDetailsResponseFactory 経由。`insufficient-funds` Problem Type が明示的。
- **設計懸念**: `replayBalance()` は O(n) — 本番スケールでは追加設計が必要（スナップショット）。FT ループの教育目的としては許容範囲。`appendEvent()` の「INSERT → fetchOne LAST」パターンは前 FT と同じ指摘（FT102 以来のトランザクション境界未整理）。
