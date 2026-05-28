# How-to: Circuit Breaker

> **FT 参照**: FT298 (`NENE2-FT/circuitlog`) — サーキットブレーカーパターン: closed/open/half_open の 3 状態マシン、設定可能な失敗閾値、タイムアウトベースの自動 half_open 遷移、open 回路での 503 Service Unavailable、`isCallAllowed()` 読み取り専用チェック、15 テスト / 28 アサーション PASS。

サーキットブレーカーパターンは、外部サービスを呼ぶ際の連鎖障害を防ぎます。遅い・失敗した呼び出しが積み上がるのを放置するのではなく、回路を open にトリップさせ、依存先が回復するまで呼び出しを即座に拒否します。

## 3 つの状態

```
Closed ──(N 回連続失敗)──▶ Open ──(タイムアウト経過)──▶ Half-Open
  ▲                                                          │
  └──────────────────(成功)──────────────────────────────────┘
  Half-Open ──(失敗)──▶ Open
```

| 状態 | 振る舞い |
|---|---|
| **Closed** | 通常 — 呼び出しはそのまま通過します。エラー毎に失敗カウントを増加。 |
| **Open** | 呼び出しは即座に 503 で拒否されます。`failure_threshold` 回連続失敗の後、`timeout_seconds` の間 open になります。 |
| **Half-Open** | 単一のプローブ呼び出しが許可されます。成功 → Closed（リセット）。失敗 → 再び Open。 |

## スキーマ

```sql
CREATE TABLE IF NOT EXISTS circuits (
    id                INTEGER PRIMARY KEY AUTOINCREMENT,
    name              TEXT    NOT NULL UNIQUE,
    state             TEXT    NOT NULL DEFAULT 'closed',
    failure_count     INTEGER NOT NULL DEFAULT 0,
    failure_threshold INTEGER NOT NULL DEFAULT 5,
    open_until        TEXT,
    half_open_at      TEXT,
    last_failure_at   TEXT,
    updated_at        TEXT    NOT NULL
);
```

回路名は通常、外部サービス識別子です（例: `payment-gateway`、`email-svc`）。複数の独立した回路が共存できます。

## 結果の記録

```php
// 外部サービスへの成功した呼び出しの後:
$this->repo->recordSuccess($circuitName, $now);

// 失敗した呼び出しの後:
$this->repo->recordFailure($circuitName, $now, timeoutSeconds: 30);
```

`recordFailure()` が遷移を決定します:
- `failure_count + 1 >= failure_threshold` の場合 → 状態を `open` に設定し、`open_until = now + timeout` を計算。
- 閾値未満の場合 → `failure_count` を増加し、`closed` のまま。
- `half_open` 状態の場合 → どんな失敗でも即座に再 open。

## 呼び出しが許可されているかチェック

```php
$circuit = $this->repo->maybeTransitionToHalfOpen($name, $now);

if (!$circuit->isCallAllowed($now)) {
    // 外部サービスを呼ばずに即座に 503 を返す
    return $problems->create($request, 'service-unavailable', 'Circuit is open.', 503);
}

// 呼び出しを試行...
```

毎リクエスト、`isCallAllowed()` チェックの前に `maybeTransitionToHalfOpen()` を呼んでください。これは `open_until` が経過した時に `Open → Half-Open` へ遷移させ、プローブ呼び出しを通過させます。

```php
public function isCallAllowed(string $now): bool
{
    return match ($this->state) {
        CircuitState::Closed   => true,
        CircuitState::Open     => $now >= ($this->openUntil ?? ''),
        CircuitState::HalfOpen => true,
    };
}
```

## Half-Open のタイミング

`Open → Half-Open` 遷移は遅延型です: `open_until` が経過した後、次に `maybeTransitionToHalfOpen()` が呼ばれた時点で発生します。これは意図的な設計で、バックグラウンドタイマーを避け、状態変化を到来するリクエストに紐づけます。

## 失敗閾値とタイムアウトのチューニング

| 依存タイプ | 推奨閾値 | 推奨タイムアウト |
|---|---|---|
| データベース（クリティカル） | 3–5 | 10–30s |
| 外部 API | 5–10 | 30–60s |
| 非クリティカルサービス | 10–20 | 60–120s |

閾値が高いほど偽陽性（一時的なグリッチ）を減らせます。タイムアウトが長いほど依存先に回復時間を与えますが、顧客に見える機能低下も長くなります。

## サービスごとの複数回路

異なる失敗ドメインには別々の回路名を使ってください:

```
payment-gateway/charge
payment-gateway/refund
email-svc/transactional
email-svc/marketing
```

これにより refund エンドポイントの失敗が charge の試行をブロックすることを防げます。

## 回路が Open の時のレスポンス

`open_until` を指す `Retry-After` ヘッダー付きで `503 Service Unavailable` を返します:

```php
return $problems->create($request, 'service-unavailable', 'Circuit is open.', 503, null, [
    'open_until' => $circuit->openUntil,
]);
```

`503` を尊重するクライアントやロードバランサーは、回路が open の間、このインスタンスへのルーティングを停止できます。

## 設計判断

**なぜインメモリではなく DB バック状態か?** インメモリ状態は再起動時に失われ、PHP-FPM ワーカー間で共有されません。DB 状態は全ワーカー間で一貫し、再起動を生き残ります。代わりに保護された呼び出し毎に DB クエリが 1 つ追加されます。高スループット経路ではアトミックインクリメント操作を備えた Redis を検討してください。

**なぜ遅延型 Half-Open 遷移か?** 能動的なバックグラウンド遷移はスケジューラーやデーモンを必要とします。遅延遷移はよりシンプルで、スケジューラー視点ではステートレスで、リクエスト量がチェックの実行を担保するほとんどの Web API には十分です。

**なぜ `failure_count` は成功でリセットされるのか?** これは「連続失敗」セマンティクスです。代替案は「スライディングウィンドウでの失敗率」（例: 直近 60 秒で 50% 超失敗）です。スライディングウィンドウは低頻度だが安定したトラフィックのサービスにはより正確ですが、連続失敗の方がシンプルで、上か下かが明確なサービスには十分です。

---

## やってはいけないこと

| アンチパターン | リスク |
|---|---|
| `UNIQUE(name)` 制約がない | 同時作成で同じ回路に複数行ができる |
| open 回路にタイムアウトがない | 閾値超過後、回路が永久に open のまま |
| half_open 状態がない | 回路が open → closed に直接遷移し、プローブ後検証ができない |
| 回路 open 時に 200 を返す | 呼び出し側が成功と勘違いし、ダウンストリームエラーが隠れる |
| 503 レスポンスに `open_until` がない | 呼び出し側が即座に再試行（thundering herd）。リトライタイミングを含めること |
| 文字列 `"true"` を成功として受け入れる | JSON 型混同。`is_bool()` を厳密に使う |
| `maybeTransitionToHalfOpen()` を先に呼ばずに `isCallAllowed()` をチェック | open 回路が half_open にならず、永久にスタックする |
| インメモリ状態のみ | ワーカー再起動で状態消失。PHP-FPM ワーカー間で共有なし |
