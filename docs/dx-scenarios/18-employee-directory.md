# DX Scenario 18: 社員ディレクトリ

## アプリ概要

部署・社員・スキルタグ・検索を管理する社内人材ディレクトリ API。

| 機能 | エンドポイント例 |
|------|----------------|
| 部署管理 | `GET /departments`, `POST /departments`（階層構造） |
| 社員 CRUD | `GET /employees`, `POST /employees`, `PUT /employees/{id}` |
| 部署異動 | `POST /employees/{id}/transfers`（新しい部署への異動記録）|
| スキル管理 | `POST /skills`, `POST /employees/{id}/skills/{skill_id}` |
| 社員検索 | `GET /employees?name=田中&department_id=3&skill=PHP&page=1` |
| 上司部下関係 | `GET /employees/{id}/reports`（直属の部下一覧）|
| 組織図 | `GET /departments/{id}/org-chart`（部署ツリー）|

ポイント: 部署の階層（自己参照または階層テーブル）、異動履歴ログ、スキルの多対多、複合検索。

---

## Persona A — 下村 琴（新卒・女性・22 歳）

### 背景

経営情報学部卒 1 年目。人事系ツール（SmartHR 等）は業務で使ったことがある。

### 作業シナリオ

1. `departments` / `employees` テーブルを作成。部署の階層は `parent_id` で自己参照。
   `docs/howto/hierarchical-data.md` を参考にして設計。
2. 異動記録は `employees.department_id` を直接更新するだけ（履歴なし）。
3. スキルを `employees.skills TEXT`（コンマ区切り）で管理。
4. `GET /employees?name=田中` を `WHERE name LIKE '%田中%'` で実装。
5. 「上司部下関係」を「同じ部署の管理職を上司とする」という曖昧な設計にしてしまう。

### ハマりポイント

- **部署階層 howto の活用**: `hierarchical-data.md` を見つけて活用できた点は良い。
- **異動履歴**: 「現在の部署」だけでなく「過去の異動履歴」が必要なことを後で気づく。
- **スキルのコンマ区切り**: スキル検索で詰まるパターン（繰り返し）。

### 解決策 & 感想

部署階層は `hierarchical-data.md` で解決できた。異動履歴は `employee_transfers` テーブルを後から追加。

> 「階層データの howto があって助かった！あれなかったら全然分からなかった。
>  異動履歴は『最新しかいらない』と思ってたけど、
>  人事履歴って大事なんだとレビューで言われた。」

### DX スコア: ⭐⭐⭐（3/5）

`hierarchical-data.md` が直接役立つ。異動履歴の概念理解と howto 誘導が改善点。

---

## Persona B — 里見 昭（ロースキル・男性・42 歳）

### 背景

中小企業の IT 担当 15 年。Access で組織図ツールを作ったことあり。

### 作業シナリオ

1. `departments(id, name, parent_id, path, depth)` — `hierarchical-data.md` をほぼそのまま適用。
2. `employees(id, name, email, department_id, manager_id, joined_at)` で `manager_id` を直接持たせる。
3. 異動記録: `employee_transfers(employee_id, old_dept_id, new_dept_id, transferred_at, reason)` テーブル。
4. スキル: `skills` + `employee_skills(employee_id, skill_id, level)` で正規化。
5. 複合検索 `GET /employees?name=田中&department_id=3&skill=PHP`:
   ```sql
   SELECT DISTINCT e.* FROM employees e
   LEFT JOIN employee_skills es ON es.employee_id = e.id
   LEFT JOIN skills s ON s.id = es.skill_id
   WHERE e.name LIKE ? AND e.department_id = ?
   AND (s.name = ? OR ? IS NULL)
   ```

### ハマりポイント

- **`DISTINCT` と `LEFT JOIN`**: スキルフィルタありとなしで結果が違う。
  `DISTINCT` で重複解消しているが、ページネーションとの組み合わせが複雑。
- **`manager_id` vs 部署階層**: 「上司部下関係」を `manager_id` で直接持つ設計と
  部署階層から導出する設計のどちらが良いか判断できなかった。
- **組織図 API**: `GET /departments/{id}/org-chart` を `hierarchical-data.md` の
  サブツリークエリで実装できた。

### 解決策 & 感想

業務知識と howto の組み合わせで完成。複合検索のクエリ最適化は今後の課題。

> 「hierarchical-data howto はそのまま使えた。
>  DISTINCT とページネーションの組み合わせはいつも頭を悩ます。
>  複合検索の efficiient な書き方の howto が欲しい。」

### DX スコア: ⭐⭐⭐（3/5）

実用的に完成。複合検索と DISTINCT + ページネーションの組み合わせが課題。

---

## Persona C — 橘 明日香（シニア・女性・36 歳）

### 背景

HR Tech スタートアップのエンジニアリングマネージャー 8 年目。組織データの設計パターンを熟知。

### 作業シナリオ

1. テーブル設計:
   - `departments(id, name, parent_id, path, depth)` — materialized path
   - `employees(id, name, email, joined_at, status)`
   - `employee_departments(employee_id, department_id, role, is_primary, started_at, ended_at)` — 兼任対応
   - `skills(id, name, category)` + `employee_skills(employee_id, skill_id, proficiency_level)`
2. 「現在の所属」は `employee_departments WHERE ended_at IS NULL` で取得。
3. 上司部下関係: `manager_id` は持たず、部署内の `role='manager'` から動的に導出。
4. 複合検索: `GET /employees` に複数フィルタをクエリビルダーなしで条件分岐実装。
   `WHERE 1=1` パターンで可読性を確保。
5. 組織図: `GET /departments/{id}/org-chart` は CTE サブツリー + JOIN で一括取得。

### ハマりポイント

- **兼任対応の複雑さ**: `employee_departments` の期間管理（開始/終了日）が
  フィルタクエリを複雑にした。
- **動的 WHERE 句**: 複数フィルタの条件分岐コードが長くなった。クエリビルダーの欲しさを感じた。
- **`ended_at IS NULL` の「現在」定義**: 終了日なし=現在在籍というシンプルな設計を採用。

### 解決策 & 感想

高品質で完成。条件分岐 SQL の冗長さはクエリビルダー不在の副作用。

> 「hierarchical-data howto は完成度が高くて参考になった。
>  動的フィルタ SQL のパターンは毎回手書きになるので howto があると嬉しい。
>  兼任対応は HR の実業務では重要だけど、複雑になるのでシンプルな方から howto を作るのが良い。」

### DX スコア: ⭐⭐⭐⭐（4/5）

高品質完成。動的フィルタ SQL と複雑なリレーション設計の howto が欲しい。

---

## まとめ & NENE2 DX 評価

| ペルソナ | 完成度 | スコア | 主なフリクション |
|---------|--------|--------|----------------|
| 下村（新卒） | ○ howto 活用で改善 | 3/5 | 異動履歴の重要性、スキル多対多 |
| 里見（ロースキル） | ○ 実用的完成 | 3/5 | DISTINCT + ページネーション、複合検索 |
| 橘（シニア） | ◎ 高品質完成 | 4/5 | 動的 WHERE 句パターン、兼任対応の複雑さ |

**共通のフリクション**:
1. **`hierarchical-data.md` は参照されやすい** — 部署・組織系で直接活用されている良例。
2. **動的フィルタ SQL パターン** — 複数のシナリオで言及。専用 howto の優先度が高い。
3. **複合 JOIN + DISTINCT + ページネーション** — 組み合わせが複雑になる問題のパターン集。
