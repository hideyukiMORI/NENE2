# ハウツー: A/B テストフレームワーク

> **FT リファレンス**: FT293 (`NENE2-FT/ablog`) — A/B 実験フレームワーク: crc32 シードによる重み付き決定論的バリアント割り当て、draft→active→stopped ステートマシン、UNIQUE(experiment_id, user_id) による冪等な割り当て、SQL での CVR 集計、16 テスト / 26 アサーション PASS。

ユーザーをバリアントに割り当て、コンバージョンイベントを収集することで制御実験を実行します。

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
| `GET` | `/experiments/{id}` | 実験とバリアントを取得する |
| `PUT` | `/experiments/{id}/status` | ステータスを遷移させる |
| `POST` | `/experiments/{id}/variants` | バリアントを追加する |
| `POST` | `/experiments/{id}/assign` | ユーザーをバリアントに割り当てる（冪等） |
| `POST` | `/experiments/{id}/events` | コンバージョンイベントを記録する |
| `GET` | `/experiments/{id}/results` | バリアントごとの CVR 集計を取得する |

## ステータスライフサイクル

```
draft → active → stopped
```

無効な遷移は 422 で拒否します:

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

ユーザーは常に同じバリアントに割り当てられる必要があります — 再現可能なステートレスバケットには `crc32` を使用します:

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

DB は初回呼び出し時に割り当てを保存し、以降の呼び出しでは保存済みのバリアントを返します — 決定論性と DB の信頼性を両立します。

## 冪等な割り当て

```php
// 再ロールせずに既存の割り当てを返す
$existing = $this->repo->findAssignment($id, $userId);
if ($existing !== null) {
    return $this->json->create($existing);   // 201 ではなく 200
}
// 初回: 計算して保存する
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

その後 PHP で CVR を計算します:

```php
$row['cvr'] = $assignments > 0 ? round($events / $assignments, 4) : 0.0;
```

## ガードレール

- `active` 状態の実験のみ割り当てを受け付けます（それ以外は 409）。
- イベントにはユーザーが割り当て済みであることが必要です（それ以外は 404）。
- `UNIQUE(experiment_id, user_id)` により DB レベルで二重割り当てを防止します。
- ウェイトは正の整数である必要があり、ゼロウェイトのバリアントは拒否されます（422）。

---

## やってはいけないこと

| アンチパターン | リスク |
|---|---|
| ランダム（非決定論的）割り当て | 同じユーザーが呼び出しごとに異なるバリアントになる；一貫性のない体験 |
| `UNIQUE(experiment_id, user_id)` なし | 同時割り当てで重複行が作成され、ユーザーが複数のバリアントに入る |
| `draft` または `stopped` 状態で割り当てを許可する | ドラフト実験には有効なバリアントがない；停止した実験は新データを収集すべきでない |
| 後退ステータス遷移を許可する | `stopped → active` でクローズ済み実験が再オープンされ、履歴データが汚染される |
| ウェイトバリデーションなし（0 を許可） | ゼロ合計ウェイトでバケット計算のゼロ除算が発生する |
| 全行を取得してアプリでCVRを計算する | 全行を取得してループする；代わりに `GROUP BY` SQL 集計を使用する |
| イベント→割り当てバリデーションなし | 有効な割り当てのないイベントがバリアントごとのコンバージョン率を歪める |
