# ハウツー: サーキットブレーカー

> **FT リファレンス**: FT298 (`NENE2-FT/circuitlog`) — サーキットブレーカーパターン: closed/open/half_open の 3 状態マシン、設定可能な失敗しきい値、タイムアウトベースの自動 half_open 遷移、オープンサーキット時の 503 Service Unavailable、`isCallAllowed()` 読み取り専用チェック、15 テスト / 28 アサーション PASS。

サーキットブレーカーパターンは外部サービスを呼び出す際の連鎖障害を防止します。遅いまたは失敗した呼び出しが積み重なるのを待つ代わりに、サーキットがトリップしてすぐに呼び出しを拒否し、依存関係が回復するまで続きます。

## 3 つの状態

```
Closed ──（N 回の連続失敗）──▶ Open ──（タイムアウト経過）──▶ Half-Open
  ▲                                                                    │
  └──────────────────（成功）────────────────────────────────────────┘
  Half-Open ──（失敗）──▶ Open
```

| 状態 | 動作 |
|---|---|
| **Closed** | 通常 — 呼び出しは通過する。エラーごとに失敗カウントが増加する。 |
| **Open** | 呼び出しは即座に 503 で拒否される。`failure_threshold` 回の連続失敗後に `timeout_seconds` 間オープンになる。 |
| **Half-Open** | 単一のプローブ呼び出しが許可される。成功 → Closed（リセット）。失敗 → 再びオープン。 |

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

サーキット名は通常外部サービス識別子です（例: `payment-gateway`、`email-svc`）。複数の独立したサーキットが共存できます。

## 結果の記録

```php
// 外部サービスへの呼び出しが成功した後:
$this->repo->recordSuccess($circuitName, $now);

// 呼び出しが失敗した後:
$this->repo->recordFailure($circuitName, $now, timeoutSeconds: 30);
```

`recordFailure()` が遷移を決定します:
- `failure_count + 1 >= failure_threshold` の場合 → 状態を `open` に設定し、`open_until = now + timeout` を計算する。
- まだしきい値以下の場合 → `failure_count` をインクリメントし、`closed` のまま。
- `half_open` 状態の場合 → どんな失敗でも即座に再オープンする。

## 呼び出しが許可されているかの確認

```php
$circuit = $this->repo->maybeTransitionToHalfOpen($name, $now);

if (!$circuit->isCallAllowed($now)) {
    // 503 を即座に返す — 外部サービスを呼び出さない
    return $problems->create($request, 'service-unavailable', 'Circuit is open.', 503);
}

// 呼び出しを試みる...
```

すべてのリクエストで `isCallAllowed()` チェックの前に `maybeTransitionToHalfOpen()` を呼び出してください。これにより `open_until` が経過した後 `Open → Half-Open` に遷移し、プローブ呼び出しを通過させます。

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

`Open → Half-Open` 遷移は遅延評価です: `open_until` が経過した後に `maybeTransitionToHalfOpen()` が次回呼び出されたときに発生します。これは意図的です — バックグラウンドタイマーを避け、状態変化を受信リクエストに結びつけておきます。

## 失敗しきい値とタイムアウトのチューニング

| 依存関係の種類 | 推奨しきい値 | 推奨タイムアウト |
|---|---|---|
| データベース（クリティカル） | 3〜5 | 10〜30 秒 |
| 外部 API | 5〜10 | 30〜60 秒 |
| 非クリティカルサービス | 10〜20 | 60〜120 秒 |

高いしきい値は誤検知を減らします（一時的な問題）。長いタイムアウトは依存関係に回復する時間を与えますが、顧客に見えるデグレードを延長します。

## サービスごとの複数サーキット

異なる障害ドメインに異なるサーキット名を使用します:

```
payment-gateway/charge
payment-gateway/refund
email-svc/transactional
email-svc/marketing
```

これにより返金エンドポイントの障害が請求の試みをブロックするのを防止します。

## サーキットがオープンのときのレスポンス

`open_until` を指す `Retry-After` ヘッダーとともに `503 Service Unavailable` を返します:

```php
return $problems->create($request, 'service-unavailable', 'Circuit is open.', 503, null, [
    'open_until' => $circuit->openUntil,
]);
```

`503` を尊重するクライアントとロードバランサーは、サーキットがオープンの間このインスタンスへのルーティングを停止できます。

## 設計上の決定

**なぜインメモリではなく DB バックの状態か？** インメモリ状態は再起動時に失われ、PHP-FPM ワーカー間で共有されません。DB 状態はすべてのワーカー間で一貫していて再起動後も生き残りますが、保護された呼び出しごとに 1 つの追加 DB クエリがかかります。高スループットのパスにはアトミックインクリメント操作を持つ Redis を検討してください。

**なぜ遅延 Half-Open 遷移か？** プロアクティブなバックグラウンド遷移にはスケジューラーまたはデーモンが必要です。遅延遷移はシンプルで、スケジューラーの観点からステートレスで、リクエスト量がチェックのプロンプトな実行を確保するほとんどの Web API に十分です。

**なぜ `failure_count` は成功時にリセットされるか？** これは「連続失敗」セマンティクスです。代替は「スライディングウィンドウ上の失敗率」（例: 過去 60 秒で >50% の失敗）。スライディングウィンドウは低いが安定したトラフィックのサービスに対してより正確です。連続失敗はよりシンプルで、上下どちらかのサービスに十分です。

---

## やってはいけないこと

| アンチパターン | リスク |
|---|---|
| `UNIQUE(name)` 制約なし | 同時作成で同じサーキットに複数行が生成される |
| オープンサーキットのタイムアウトなし | しきい値超過後にサーキットが永遠にオープンのまま |
| half_open 状態なし | サーキットが直接 open → closed になる。プローブ後検証なし |
| サーキットがオープンのときに 200 を返す | 呼び出し元が呼び出しが成功したと思う。ダウンストリームエラーが隠れる |
| 503 レスポンスに `open_until` なし | 呼び出し元が即座に再試行する（サンダリングハード）。再試行タイミングを含める |
| 成功として文字列 `"true"` を受け入れる | JSON 型の混乱。`is_bool()` を厳密に使用する |
| `maybeTransitionToHalfOpen()` なしで `isCallAllowed()` をチェックする | オープンサーキットが永遠に half_open にならない。永続的にスタック |
| インメモリ状態のみ | ワーカー再起動で状態が失われる。PHP-FPM ワーカー間で共有されない |
