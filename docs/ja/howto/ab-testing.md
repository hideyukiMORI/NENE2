# ハウツー: A/B テストフレームワーク

> **FT リファレンス**: FT293 (`NENE2-FT/ablog`) — A/B 実験フレームワーク: crc32 シードによる重み付き決定論的バリアント割り当て、draft→active→stopped ステートマシン、UNIQUE(experiment_id, user_id) による冪等な割り当て、SQL での CVR 集計、16 テスト / 26 アサーション PASS。

ユーザーをバリアントに割り当ててコンバージョンイベントを収集することで、制御された実験を実行します。

## スキーマ

```sql
CREATE TABLE experiments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL UNIQUE,
    description TEXT NOT NULL DEFAULT '',
    status TEXT NOT NULL DEFAULT 'draft' CHECK(status IN ('draft', 'active', 'stopped')),
    created_at TEXT NOT NULL, updated_at TEXT NOT NULL
);
CREATE TABLE experiment_variants (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    experiment_id INTEGER NOT NULL REFERENCES experiments(id) ON DELETE CASCADE,
    name TEXT NOT NULL, weight INTEGER NOT NULL DEFAULT 100,
    UNIQUE(experiment_id, name)
);
CREATE TABLE experiment_assignments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    experiment_id INTEGER NOT NULL REFERENCES experiments(id) ON DELETE CASCADE,
    user_id TEXT NOT NULL, variant_id INTEGER NOT NULL REFERENCES experiment_variants(id),
    assigned_at TEXT NOT NULL, UNIQUE(experiment_id, user_id)
);
CREATE TABLE experiment_events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    experiment_id INTEGER NOT NULL REFERENCES experiments(id) ON DELETE CASCADE,
    assignment_id INTEGER NOT NULL REFERENCES experiment_assignments(id),
    event_type TEXT NOT NULL, created_at TEXT NOT NULL
);
```

## ルート

| メソッド | パス | 説明 |
|--------|------|-------------|
| `POST` | `/experiments` | 実験を作成する（`draft` 状態で開始） |
| `GET` | `/experiments` | すべての実験を一覧表示する |
| `GET` | `/experiments/{id}` | 実験 + バリアントを取得する |
| `PUT` | `/experiments/{id}/status` | ステータスを遷移させる |
| `POST` | `/experiments/{id}/variants` | バリアントを追加する |
| `POST` | `/experiments/{id}/assign` | ユーザーをバリアントに割り当てる（冪等） |
| `POST` | `/experiments/{id}/events` | コンバージョンイベントを記録する |
| `GET` | `/experiments/{id}/results` | バリアントごとの CVR 集計 |

## ステータスライフサイクル

```
draft → active → stopped
```

無効なトランザクションを 422 で拒否します:

```php
private const array VALID_TRANSITIONS = [
    'draft'   => ['active'],
    'active'  => ['stopped'],
    'stopped' => [],
];

$allowed = self::VALID_TRANSITIONS[$current] ?? [];
if (!in_array($status, $allowed, true)) {
    throw new ValidationException([...]);
}
```

## 決定論的バリアント割り当て

ユーザーは常に同じバリアントに割り当てられる必要があります — 再現可能なステートレスなバケットのために `crc32` を使用します:

```php
class VariantAssigner
{
    /** @param list<array<string, mixed>> $variants */
    public function assign(array $variants, string $userId, int $experimentId): ?array
    {
        $totalWeight = array_sum(array_column($variants, 'weight'));
        $seed        = abs(crc32($userId . ':' . $experimentId));
        $bucket      = $seed % $totalWeight;

        $cumulative = 0;
        foreach ($variants as $v) {
            $cumulative += (int) $v['weight'];
            if ($bucket < $cumulative) {
                return $v;
            }
        }
        return $variants[0];
    }
}
```

DB は最初の呼び出し時に割り当てを保存します。以降の呼び出しは保存されたバリアントを返します — 決定論性 + DB の信頼性。

## 冪等な割り当て

```php
// 再抽選せずに既存の割り当てを返す
$existing = $this->repo->findAssignment($id, $userId);
if ($existing !== null) {
    return $this->json->create($existing);   // 201 ではなく 200
}
// 初回: 計算して保存
$variant      = $this->assigner->assign($variants, $userId, $id);
$assignmentId = $this->repo->createAssignment($id, $userId, $variant['id'], $now);
return $this->json->create($assignment, 201);
```

## 結果集計（CVR）

```sql
SELECT ev.id AS variant_id, ev.name AS variant_name,
       COUNT(DISTINCT ea.id) AS assignments,
       COUNT(ee.id) AS events
FROM experiment_variants ev
LEFT JOIN experiment_assignments ea ON ea.variant_id = ev.id
LEFT JOIN experiment_events ee ON ee.assignment_id = ea.id
WHERE ev.experiment_id = ?
GROUP BY ev.id, ev.name, ev.weight
ORDER BY ev.id ASC
```

次に PHP で CVR を計算します:

```php
$row['cvr'] = $assignments > 0 ? round($events / $assignments, 4) : 0.0;
```

## ガードレール

- `active` の実験のみ割り当てを受け入れます（それ以外は 409）。
- イベントにはユーザーの割り当てが必要です（それ以外は 404）。
- `UNIQUE(experiment_id, user_id)` により DB レベルで重複割り当てを防止します。
- ウェイトは正の整数である必要があります。ゼロウェイトのバリアントは拒否されます（422）。

---

## やってはいけないこと

| アンチパターン | リスク |
|---|---|
| ランダム（非決定論的）割り当て | 同じユーザーが毎回異なるバリアントを取得する。一貫性のないエクスペリエンス |
| `UNIQUE(experiment_id, user_id)` なし | 同時割り当てで重複行が作成される。ユーザーが複数のバリアントに入る |
| `draft` または `stopped` ステータスでの割り当て許可 | ドラフト実験には有効なバリアントがない。停止済み実験は新しいデータを収集すべきでない |
| 後退ステータス遷移を許可する | `stopped → active` でクローズ済み実験を再開する。過去のデータが汚染される |
| ウェイト検証なし（0 を許可） | ゼロの合計ウェイトでバケット計算がゼロ除算になる |
| 全行を取得してアプリケーションで CVR を計算する | 全行をフェッチしてループする。代わりに `GROUP BY` SQL 集計を使用する |
| イベント → 割り当て検証なし | 有効な割り当てのないイベントがバリアントごとのコンバージョン率を歪める |
