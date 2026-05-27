# DX Scenario 05: 採用管理

## アプリ概要

求人票作成・応募受付・選考ステータス管理・メモを備えた採用管理 API。

| 機能 | エンドポイント例 |
|------|----------------|
| 求人管理 | `GET /jobs`, `POST /jobs`, `PUT /jobs/{id}`, `PATCH /jobs/{id}/close` |
| 応募受付 | `POST /jobs/{id}/applications`, `GET /jobs/{id}/applications` |
| 選考ステータス | `PATCH /applications/{id}/status`（書類→一次→二次→内定→辞退/不合格）|
| 評価メモ | `POST /applications/{id}/notes`, `GET /applications/{id}/notes` |
| 担当者割り当て | `POST /applications/{id}/reviewers`, `DELETE /applications/{id}/reviewers/{uid}` |
| ダッシュボード | `GET /dashboard`（ステージ別件数・求人別集計） |

ポイント: 多段ステータス遷移、複数担当者（中間テーブル）、集計ダッシュボード。

---

## Persona A — 加藤 翔平（新卒・男性・24 歳）

### 背景

工学部卒 1 年目。バックエンドチーム配属。採用業務の知識ゼロ。
「APIってURL叩いてJSONが返ってくるやつでしょ」という理解レベル。

### 作業シナリオ

1. 求人テーブルを作成。`status` は `TEXT DEFAULT 'open'` で実装。
2. 応募テーブルで「書類→一次→二次→内定→辞退/不合格」の遷移ルールを
   どう実装するか全く分からず、`status` カラムを自由 UPDATE にしてしまう。
3. `GET /dashboard` を実装しようとして「集計クエリってどう書くの？」で 2 時間止まる。
   結局全件を PHP ループで集計する実装にした。
4. 担当者割り当ては「1 案件に 1 人でいい」と判断して単一 FK にしてしまう。
5. `POST /applications/{id}/notes` の「note は誰が書いたか」を記録する必要があると
   後で言われ、`author_id` カラムの追加を迫られる。

### ハマりポイント

- **ステータス遷移ルール**: 自由遷移にしてしまい「辞退→内定」が可能な状態になる。
- **集計 SQL**: GROUP BY を使った集計クエリの経験がなく PHP ループに逃げる。
- **ノートの著者**: 「誰が書いたか」という監査要件を初期設計で考慮できなかった。

### 解決策 & 感想

`docs/howto/state-machine-workflow-api.md` を先輩に紹介してもらいステータス遷移を修正。
集計クエリは Google で学んだ。

> 「ステータス遷移って難しい。howto 読んだら腑に落ちた。
>  あと『誰が書いたか記録する』って言われても最初は意味が分からなかった。
>  監査っていう概念、学校では習わなかった。」

### DX スコア: ⭐⭐（2/5）

遷移ルールと集計クエリで大幅な修正が必要。業務知識のサポートも課題。

---

## Persona B — 佐々木 由紀（ロースキル・女性・28 歳）

### 背景

中小企業の社内システム担当 5 年。Access VBA から PHP に転向。
「動けばいいが、遅いのは困る」思考。

### 作業シナリオ

1. テーブル設計は「応募者テーブル + 求人テーブル + 中間テーブル（選考状況）」で設計。
   概念は合っているが、中間テーブルに `status` を置くのは正しいか迷う。
2. `PATCH /applications/{id}/status` を実装。バリデーションは「有効な status 値かどうか」のみ。
   遷移ルール（前のステータスから次のステータスへ）はチェックしていない。
3. 評価メモは `notes` テーブルに `application_id`, `content`, `created_by` で実装。良好。
4. `GET /dashboard` は求人別の集計クエリを書こうとして `GROUP BY` に詰まるが、
   ドキュメントを探すうちに `category-hierarchy-api.md` の集計部分を参考にした。
5. 求人の `PATCH /jobs/{id}/close` は `status = 'closed'` への UPDATE。再オープンは未実装。

### ハマりポイント

- **遷移ルールの実装**: 現状から見てどのステータスに移れるかのロジックが不明。
- **GROUP BY の書き方**: `category-hierarchy-api.md` が参考になったが、集計専用 howto がほしい。
- **`closed` 後の再オープン**: ビジネスルールとして再オープンが必要かどうか判断できない。

### 解決策 & 感想

`docs/howto/state-machine-workflow-api.md` を後から読み、遷移ルール実装を追加。

> 「GROUP BY はなんとかできた。
>  でも遷移ルールって考えたことなかった。howto ないと絶対ノーチェックのままだった。」

### DX スコア: ⭐⭐⭐（3/5）

実用的に完成できるが、遷移ルールは howto 誘導がなければ省略されがち。

---

## Persona C — 村田 雄介（シニア・男性・39 歳）

### 背景

SaaS スタートアップのバックエンドリード 10 年。GraphQL も経験あり。
「モデリングに時間をかけることで実装が楽になる」信念。

### 作業シナリオ

1. ドメインモデルを丁寧に設計:
   - `Job` / `Application` / `SelectionStage` (enum-like) / `Note` / `Reviewer` (value object)
2. 選考ステータス遷移を `Application::allowedNextStatuses()` で管理。
   `docs/howto/state-machine-workflow-api.md` のパターンをそのまま適用。
3. 担当者は `application_reviewers` 中間テーブル。追加・削除の API も設計。
4. ダッシュボードは専用 `DashboardRepository::getStageStats()` を作り、
   `COUNT(*) GROUP BY status` の集計クエリを一発で返す。
5. `GET /jobs?status=open&department=engineering` のフィルタ検索を
   クエリビルダーなしで条件分岐 SQL で実装（少し冗長だが動く）。

### ハマりポイント

- **動的フィルタ SQL**: 複数フィルタ条件の組み立てを `WHERE 1=1 AND ...` で書いたが、
  NENE2 にクエリビルダーがないため条件分岐コードが冗長になった。
- **ダッシュボードの ReadModel**: Write 側と Read 側のリポジトリをどう分けるか
  NENE2 のパターンが不明で UseCase 内に書いた。
- **SELECT n+1 問題**: `GET /jobs/{id}/applications` で担当者リストを取得する際に
  N+1 が発生しており、JOIN に直した。

### 解決策 & 感想

高品質で完成。クエリビルダーの欠如は許容範囲内だが、動的フィルタパターンが欲しい。

> 「state-machine howto はそのまま使えて助かった。
>  動的フィルタの SQL パターンは自力で書けるけど、howto に一例あると嬉しい。
>  N+1 は JOIN 書けば解決だけど、気づかない人は気づかないと思う。」

### DX スコア: ⭐⭐⭐⭐（4/5）

高品質完成。動的フィルタと N+1 対策の howto があれば 5/5 に近づく。

---

## まとめ & NENE2 DX 評価

| ペルソナ | 完成度 | スコア | 主なフリクション |
|---------|--------|--------|----------------|
| 加藤（新卒） | △ 大幅修正必要 | 2/5 | 遷移ルール設計、集計 SQL、業務知識の前提 |
| 佐々木（ロースキル） | ○ 遷移ルール後付け | 3/5 | 遷移ルールの自然な発見パス |
| 村田（シニア） | ◎ 高品質完成 | 4/5 | 動的フィルタ SQL、N+1 対策パターン |

**共通のフリクション**:
1. **状態遷移 howto の発見性** — `state-machine-workflow-api.md` は素晴らしいが、
   「選考ステータス管理を作ろう」と思ったとき自然に辿り着けない。索引の改善が必要。
2. **動的 WHERE 句の SQL パターン** — 複数フィルタ条件の組み立て方の howto が欲しい。
3. **N+1 問題の説明** — 一対多・多対多リレーションで起きやすい問題の対策 howto が欲しい。
