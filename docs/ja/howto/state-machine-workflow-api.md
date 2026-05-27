# ハウツー: ステートマシンワークフロー API

> **FT リファレンス**: FT349 (`NENE2-FT/workflowlog`) — ハードコードされたトランジションマップ、レスポンス内の `allowed_next`、トランジション履歴ログ、終端状態強制、一覧のステートフィルターを持つステートマシンワークフローインスタンス、13 テスト PASS。

このガイドでは、ステートマシンを使ったワークフローエンジンの構築方法を説明します: 許可されたステートトランジションを定義し、ワークフローインスタンスを作成し、アクター帰属を持って状態を駆動し、完全なトランジション履歴をログに記録します。

## スキーマ

```sql
CREATE TABLE instances (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    workflow      TEXT    NOT NULL,          -- 例: "order"
    current_state TEXT    NOT NULL,
    context       TEXT    NOT NULL DEFAULT '{}',  -- JSON
    created_at    TEXT    NOT NULL,
    updated_at    TEXT    NOT NULL
);

CREATE TABLE transitions (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    instance_id INTEGER NOT NULL REFERENCES instances(id) ON DELETE CASCADE,
    from_state  TEXT    NOT NULL,
    to_state    TEXT    NOT NULL,
    actor       TEXT    NOT NULL,
    note        TEXT    NOT NULL DEFAULT '',
    occurred_at TEXT    NOT NULL
);
```

## ワークフロー定義 — "order"

```
draft ──┬──► submitted ──┬──► approved ──► fulfilled（終端）
        │                ├──► rejected  （終端）
        └──► cancelled   └──► cancelled （終端）
        （終端）
```

| From State  | 許可された次の状態 |
|-------------|------------------------------|
| `draft`     | `submitted`, `cancelled`     |
| `submitted` | `approved`, `cancelled`, `rejected` |
| `approved`  | `fulfilled`                  |
| `fulfilled` | _（終端 — なし）_          |
| `cancelled` | _（終端 — なし）_          |
| `rejected`  | _（終端 — なし）_          |

## エンドポイント

| メソッド | パス | 説明 |
|--------|------|------|
| `POST` | `/workflows/{workflow}/instances`                 | ワークフローインスタンスを作成する |
| `GET`  | `/workflows/{workflow}/instances`                 | インスタンスを一覧表示する        |
| `GET`  | `/workflows/{workflow}/instances/{id}`            | インスタンスを履歴付きで取得する  |
| `POST` | `/workflows/{workflow}/instances/{id}/transition` | ステートトランジションを駆動する  |

## インスタンスの作成

```php
POST /workflows/order/instances
{"context": {"order_ref": "ORD-001", "amount": 99.99}}

→ 201
{
  "id": 1,
  "workflow": "order",
  "current_state": "draft",
  "context": {"order_ref": "ORD-001", "amount": 99.99},
  "allowed_next": ["submitted", "cancelled"],  // ← 次に有効な状態
  "created_at": "...",
  "updated_at": "..."
}
```

`allowed_next` はトランジションマップから計算されます — 常に現在の状態を反映します。

### 不明なワークフロー → 404

```php
POST /workflows/unknown/instances  {}
→ 404  // ワークフローが定義されていない
```

## インスタンスの一覧表示

```php
// "order" ワークフローのすべてのインスタンス
GET /workflows/order/instances
→ 200  {"instances": [{...}, {...}]}

// 現在の状態でフィルター
GET /workflows/order/instances?state=draft
→ 200  {"instances": [{...}]}  // draft インスタンスのみ
```

## インスタンスの取得（履歴付き）

```php
GET /workflows/order/instances/1

→ 200
{
  "id": 1,
  "workflow": "order",
  "current_state": "approved",
  "context": {...},
  "allowed_next": ["fulfilled"],
  "history": [
    {
      "from_state": "draft",
      "to_state": "submitted",
      "actor": "alice",
      "occurred_at": "..."
    },
    {
      "from_state": "submitted",
      "to_state": "approved",
      "actor": "manager",
      "occurred_at": "..."
    }
  ],
  ...
}
```

`history` は常に時系列順（`occurred_at` の ASC）で返されます。一覧エンドポイントはパフォーマンスのために `history` を省略します。

## トランジションの駆動

```php
// 有効なトランジション
POST /workflows/order/instances/1/transition
{"to_state": "submitted", "actor": "alice"}

→ 200
{
  "current_state": "submitted",
  "allowed_next": ["approved", "cancelled", "rejected"],
  "history": [
    {"from_state": "draft", "to_state": "submitted", "actor": "alice", ...}
  ]
}
```

### 完全なハッピーパス

```php
POST .../transition  {"to_state": "submitted", "actor": "alice"}    → submitted
POST .../transition  {"to_state": "approved",  "actor": "manager"}  → approved
POST .../transition  {"to_state": "fulfilled", "actor": "warehouse"} → fulfilled

// fulfilled は終端
→ {"current_state": "fulfilled", "allowed_next": [], ...}
```

### 無効なトランジション → 409

```php
// draft → approved（先に submitted を経由する必要がある）
POST .../transition  {"to_state": "approved", "actor": "alice"}
→ 409
{
  "type": "https://nene2.dev/problems/invalid-transition",
  "detail": "Transition from 'draft' to 'approved' is not allowed"
}
```

### 終端状態 → 409

```php
// cancelled は終端 — トランジション不可
POST .../transition  {"to_state": "draft", "actor": "alice"}
→ 409  // "cancelled" には許可されたトランジションがない
```

## 実装

### WorkflowDefinition — トランジションマップ

```php
final class WorkflowDefinition
{
    /** @var array<string, array<string, list<string>>> */
    private static array $transitions = [
        'order' => [
            'draft'     => ['submitted', 'cancelled'],
            'submitted' => ['approved', 'cancelled', 'rejected'],
            'approved'  => ['fulfilled'],
            'fulfilled' => [],     // 終端
            'cancelled' => [],     // 終端
            'rejected'  => [],     // 終端
        ],
    ];

    /** @return list<string> */
    public static function allowedTransitions(string $workflow, string $fromState): array
    {
        return self::$transitions[$workflow][$fromState] ?? [];
    }

    public static function isValidWorkflow(string $workflow): bool
    {
        return isset(self::$transitions[$workflow]);
    }

    public static function initialState(string $workflow): string
    {
        return match ($workflow) {
            'order' => 'draft',
            default => throw new \InvalidArgumentException("Unknown workflow: {$workflow}"),
        };
    }
}
```

### トランジションハンドラー

```php
public function transition(int $id, string $toState, string $actor): ?WorkflowInstance
{
    $instance = $this->repo->findByIdOrNull($id);
    if ($instance === null) {
        return null;  // → 404
    }

    $allowed = WorkflowDefinition::allowedTransitions(
        $instance->workflow,
        $instance->currentState,
    );

    if (!in_array($toState, $allowed, true)) {
        return false;  // → 409 無効または終端
    }

    // アトミック: インスタンスを更新 + トランジションログを挿入
    $this->db->execute(
        'UPDATE instances SET current_state = ?, updated_at = ? WHERE id = ?',
        [$toState, $now, $id],
    );
    $this->db->execute(
        'INSERT INTO transitions (instance_id, from_state, to_state, actor, occurred_at) VALUES (?, ?, ?, ?, ?)',
        [$id, $instance->currentState, $toState, $actor, $now],
    );

    return $this->hydrateInstanceWithHistory($id);
}
```

`allowed_next` は常にトランジションマップから計算され、保存されません — `current_state` と一貫性が保たれます。

---

## やってはいけないこと

| アンチパターン | リスク |
|---|---|
| `allowed_next` を DB に保存する | トランジションマップが変わると古くなる; 常に現在の状態から計算すること |
| allowlist チェックなしで自由形式の `to_state` を許可する | 攻撃者がワークフローロジックをバイパスして任意の値に状態を設定できる |
| トランジションログをスキップする | 監査証跡がない; ワークフロー履歴を再構築できず、スタックしたインスタンスをデバッグできない |
| 終端状態を `allowed_next` に返す | 呼び出し元を誤解させる; 終端状態は常に空の `allowed_next` を持つ |
| 無効なトランジションに 404 を返す | 404 は "インスタンスが見つからない" と "トランジションが許可されていない" の区別を隠す; 後者には 409 を使うこと |
| インスタンステーブルに `workflow` フィールドがない | 異なるワークフロータイプのインスタンスを区別できない; クロスワークフロークエリが不可能 |
