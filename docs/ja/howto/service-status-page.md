# ハウツー: サービスステータスページ API

> **NENE2 フィールドトライアル 185** — コンポーネントヘルストラッキング、インシデントライフサイクル管理、`V::secret()` + `hash_equals()` による管理者キー保護。

---

## このトライアルが証明すること

サービスステータスページ API には以下が必要です:
1. **コンポーネントステータストラッキング** — operational / degraded / partial_outage / major_outage
2. **インシデントライフサイクル** — investigating → identified → monitoring → resolved
3. **イミュータビリティガード** — 解決済みインシデントは更新不可（再オープンを防ぐ）
4. **管理者キー保護** — `V::secret()` が書き込み操作に定数時間比較を強制
5. **ステータス enum 強制** — `V::enum()` allowlist が未知の値インジェクションを防ぐ

---

## API

| メソッド | パス | 認証 | 説明 |
|---|---|---|---|
| `GET` | `/components` | — | すべてのコンポーネントを一覧表示する（パブリック） |
| `POST` | `/components` | X-Admin-Key | コンポーネントを作成する |
| `PATCH` | `/components/{id}` | X-Admin-Key | コンポーネントステータスを更新する |
| `GET` | `/incidents` | — | インシデントを一覧表示する（パブリック、アクティブには `?open=1`） |
| `GET` | `/incidents/{id}` | — | 更新タイムライン付きのインシデント詳細 |
| `POST` | `/incidents` | X-Admin-Key | インシデントを作成する |
| `PATCH` | `/incidents/{id}` | X-Admin-Key | インシデントステータスを更新する |
| `POST` | `/incidents/{id}/updates` | X-Admin-Key | 更新メッセージを追加する |

---

## コアパターン: `V::secret()` による管理者キー認証

```php
// V::secret() のチェック: $expected !== '' && hash_equals($expected, $actual)
private function requireAdmin(ServerRequestInterface $request): bool
{
    return V::secret($this->adminKey, $request->getHeaderLine('X-Admin-Key'));
}

// すべての書き込みハンドラーで使用:
if (!$this->requireAdmin($request)) {
    return $this->responseFactory->create(['error' => 'X-Admin-Key is required.'], 401);
}
```

**`=== $key` ではなく `V::secret()` を使う理由:**
- `===` は短絡評価: タイミングがマッチの長さによって変わる → タイミングオラクル
- `hash_equals()` は文字列がどこで異なるかに関わらず定数時間
- `$expected !== ''` ガードにより空キーを誤って受け入れることを防ぐ

---

## `V::enum()` によるステータス enum 強制

```php
// V::enum(mixed $raw, string $enumClass): ?\BackedEnum
// クラス名を渡す — 型付き enum インスタンスまたは null を返す

$statusEnum = V::enum($body['status'] ?? null, ComponentStatus::class);

if (!$statusEnum instanceof ComponentStatus) {
    return $this->responseFactory->create(
        ['error' => 'status must be one of: ' . implode(', ', ComponentStatus::values()) . '.'],
        422,
    );
}

// $statusEnum はすでに正しい型付き enum — ::from() は不要
$component = $this->repository->updateComponentStatus($id, $statusEnum);
```

**enum 強制が重要な理由:**
- なければ、任意の文字列が DB に到達する
- SQL の `ORDER BY status` インジェクションベクターをブロックする
- allowlist は enum 自身のケース — 常に同期されている

---

## インシデントライフサイクルとトランジションガード

```php
enum IncidentStatus: string
{
    case Investigating = 'investigating';
    case Identified    = 'identified';
    case Monitoring    = 'monitoring';
    case Resolved      = 'resolved';

    public function isResolved(): bool
    {
        return $this === self::Resolved;
    }
}
```

**すべての書き込みハンドラーのトランジションガード:**
```php
$incident = $this->repository->findIncidentById($id);

// 解決済みインシデントはイミュータブル — 誤った再オープンを防ぐ
if ($incident->status->isResolved()) {
    return $this->responseFactory->create(
        ['error' => 'Resolved incidents cannot be updated.'],
        409,
    );
}
```

**422（Unprocessable）ではなく 409（Conflict）の理由:**
- リクエストは構文的に有効
- 競合はリソースの現在の状態にある
- 409 は「有効なリクエスト、タイミングが悪い」を伝える

---

## コンポーネントステータス値

```php
enum ComponentStatus: string
{
    case Operational   = 'operational';    // すべてのシステムが正常
    case Degraded      = 'degraded';       // パフォーマンス低下
    case PartialOutage = 'partial_outage'; // 一部の機能が利用不可
    case MajorOutage   = 'major_outage';   // 完全なサービス障害
}
```

---

## 自動 `resolved_at` タイムスタンプ

```php
public function updateIncidentStatus(int $id, IncidentStatus $status): ?Incident
{
    $now        = $this->now();
    $resolvedAt = $status->isResolved() ? $now : null;

    $stmt = $this->pdo->prepare(
        'UPDATE incidents SET status = :status, resolved_at = :resolved_at, updated_at = :now WHERE id = :id'
    );
    $stmt->execute(['status' => $status->value, 'resolved_at' => $resolvedAt, ...]);
}
```

`resolved_at` タイムスタンプはサーバーが設定します — リクエストボディからは取得しません。

---

## 整数 ID パース（インジェクションなし）

```php
private function parseId(ServerRequestInterface $request, string $param): ?int
{
    $raw = Router::param($request, $param);

    // ctype_digit: 負の数、浮動小数点、文字列、パストラバーサルを拒否
    if ($raw === null || !ctype_digit($raw)) {
        return null;
    }

    $id = (int) $raw;

    return $id > 0 ? $id : null; // ゼロも拒否
}
```

---

## オープンインシデントフィルター

```php
// ?open=1 で解決済みインシデントを除外
$openOnly = isset($params['open']) && $params['open'] === '1';

if ($openOnly) {
    $stmt = $pdo->prepare(
        "SELECT * FROM incidents WHERE status != 'resolved' ORDER BY created_at DESC"
    );
} else {
    $stmt = $pdo->query('SELECT * FROM incidents ORDER BY created_at DESC');
}
```

---

## 完全なインシデントライフサイクル例

```
POST /incidents          → 201 {status: "investigating", impact: "major"}
POST /incidents/1/updates → 201 {message: "Root cause identified."}
PATCH /incidents/1       → 200 {status: "identified"}
PATCH /incidents/1       → 200 {status: "monitoring"}
PATCH /incidents/1       → 200 {status: "resolved", resolved_at: "2026-05-26T..."}
PATCH /incidents/1       → 409 Resolved incidents cannot be updated.
GET /incidents?open=1    → 200 {count: 0}  — 解決済みは表示されなくなる
```

---

## テスト結果

```
46 テスト / 93 アサーション — すべて PASS
PHPStan level 8 — エラーなし
PHP CS Fixer — クリーン
```

---

## 主要なポイント

| パターン | ルール |
|---|---|
| 管理者キー認証 | `V::secret()` — 定数時間 `hash_equals()`、空キーをガード |
| enum バリデーション | `V::enum($raw, EnumClass::class)` — 型付き enum または null を返す |
| トランジションガード | 変更を適用する前に現在の状態を確認 — 解決済みには 409 |
| `resolved_at` | サーバーが設定するタイムスタンプ、リクエストボディからは取得しない |
| 整数 ID | `ctype_digit()` + `> 0` ガード — 文字列、負の数、ゼロを拒否 |
| パブリック読み取り | GET エンドポイントに認証なし — ステータスページはパブリックを前提 |
| イミュータブル履歴 | インシデント更新は追記のみ — 編集/削除なし |

完全な例: [`../NENE2-FT/statuslog/`](https://github.com/hideyukiMORI/NENE2-examples)（examples リポジトリ）
