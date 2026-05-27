# DX Scenario 15: アンケート + 集計

## アプリ概要

カスタム設問・回答・集計グラフ用データを管理するアンケート API。

| 機能 | エンドポイント例 |
|------|----------------|
| アンケート管理 | `GET /surveys`, `POST /surveys`（title, description）|
| 設問管理 | `POST /surveys/{id}/questions`（type: text/radio/checkbox/scale） |
| 設問順序変更 | `PATCH /surveys/{id}/questions/order`（question_ids: [3,1,2]）|
| 回答送信 | `POST /surveys/{id}/responses`（answers: [{question_id, value}]）|
| 個別回答 | `GET /surveys/{id}/responses/{rid}` |
| 集計 | `GET /surveys/{id}/summary`（設問ごとの集計：平均/分布/テキスト一覧） |
| 公開/終了 | `PATCH /surveys/{id}/publish`, `PATCH /surveys/{id}/close` |

ポイント: 動的設問タイプに応じた回答バリデーション、集計ロジック（タイプ別）、設問順序管理。

---

## Persona A — 清水 健太（新卒・男性・22 歳）

### 背景

情報工学部卒 1 年目。Google フォームを使ったことがあり「これのAPI版を作る」という理解。

### 作業シナリオ

1. `surveys` / `questions` / `responses` テーブルを作成。
   `responses.answers` に全回答を JSON 文字列で保存する設計。
2. 設問タイプを `questions.type TEXT` で持つが、タイプに応じたバリデーションは実装しない。
3. 集計 `GET /surveys/{id}/summary` は「全回答を取得して PHP でループ処理」する。
4. 設問順序を `questions.order_index INTEGER` で管理するが、順序変更の実装が複雑で省略。
5. `responses.answers` が JSON 文字列なので、特定の設問の回答だけを SQL で絞れない。

### ハマりポイント

- **回答の正規化 vs JSON**: JSON でまとめると集計が PHP ループになる。
  正規化テーブル（`response_answers`）が必要。
- **設問タイプ別バリデーション**: `radio` タイプなのに任意テキストが保存できてしまう。
- **順序変更 API**: `[3,1,2]` という配列を受け取って順序を更新するパターンが不明。

### 解決策 & 感想

`response_answers(response_id, question_id, value)` に設計変更。
順序変更は「全件 UPDATE を配列でループ」という方式を先輩に教わった。

> 「JSON で持つか正規化するかって最初は JSON の方が楽だと思ったけど、
>  集計するときに詰まった。最初から正規化すべきだった。」

### DX スコア: ⭐⭐（2/5）

JSON 保存の設計選択で後に詰まる。回答テーブル設計 howto が欲しい。

---

## Persona B — 上田 麻由（ロースキル・女性・31 歳）

### 背景

市場調査会社のアンケートツール担当 7 年。SurveyMonkey 等の管理経験豊富。

### 作業シナリオ

1. テーブル設計:
   - `questions(id, survey_id, type, text, options_json, order_index)`
   - `responses(id, survey_id, respondent_id, submitted_at)`
   - `response_answers(id, response_id, question_id, value)`
2. タイプ別バリデーション:
   - `radio/checkbox`: `value` が `options_json` に含まれるか確認
   - `scale`: `value` が 1〜10 の整数か確認
   - `text`: 最大文字数チェック
3. 集計 SQL:
   ```sql
   -- radio の分布
   SELECT value, COUNT(*) AS count FROM response_answers
   WHERE question_id=? GROUP BY value ORDER BY count DESC
   ```
4. 設問順序変更は `foreach ($questionIds as $i => $id) { UPDATE SET order_index=? WHERE id=? }` でループ。
5. 回答は 1 ユーザー 1 回のみに `UNIQUE(survey_id, respondent_id)` を `responses` テーブルに設定。

### ハマりポイント

- **`options_json` のバリデーション**: `json_decode()` 後の値チェックが冗長になった。
- **設問順序変更のトランザクション**: ループ UPDATE を 1 トランザクションにまとめるかどうか迷い、
  最終的にトランザクション内でまとめた。
- **scale タイプの集計**: 平均値 + 分布（1〜10 の件数）を 1 クエリで返したい。

### 解決策 & 感想

業務知識でスムーズに実装できた。集計 SQL のパターンを自力で書いた。

> 「options_json は保存は簡単だけど、バリデーションが地味に面倒だった。
>  scale の集計は AVG と CASE WHEN を組み合わせて書いた。
>  集計 SQL の howto があれば速かった。」

### DX スコア: ⭐⭐⭐（3/5）

良好に完成。集計 SQL のパターン howto と `options_json` バリデーション例が欲しい。

---

## Persona C — 斎藤 隆（ベテラン・男性・49 歳）

### 背景

SaaS アンケートツール開発 18 年。動的フォームエンジンの設計経験あり。

### 作業シナリオ

1. テーブル設計（正規化 + パフォーマンス考慮）:
   - `questions(id, survey_id, type, text, config_json, display_order)`
   - `config_json`: タイプごとの設定（選択肢、最小値・最大値など）
   - `response_answers(id, response_id, question_id, value_text, value_number, value_json)`
     ← タイプ別に異なるカラムに保存（NULL 許可）
2. タイプ別バリデーションを `QuestionValidator::validate(Question $q, mixed $value)` として抽象化。
3. 集計は `SurveyAnalyzer::summarize(int $surveyId)` として UseCase に独立。
   タイプ別の集計ロジック（text=一覧返し、radio=分布、scale=平均+分布）を switch で分岐。
4. 設問順序変更は `display_order` を連続整数（1,2,3...）に UPDATE。
   `ORDER BY display_order` で常にソートされる保証。
5. OpenAPI で集計レスポンスのスキーマを `oneOf` で設問タイプ別に定義。

### ハマりポイント

- **`oneOf` OpenAPI**: 設問タイプ別の集計レスポンス形式を `oneOf` で定義したが、
  NENE2 の `composer openapi` バリデーションが `oneOf` をどこまで検証するか不明だった。
- **`value_text/value_number` の 3 カラム設計**: 代替設計（EAV）との比較が必要だったが、
  今回は可読性重視でこの設計を選択。
- **集計のパフォーマンス**: 全 `response_answers` を JOIN して集計するため、
  大量回答では遅くなる可能性。スナップショット集計は今回省略。

### 解決策 & 感想

高品質で完成。`oneOf` の挙動確認に少し時間がかかった。

> 「動的フォームエンジンは設計の選択肢が多い。
>  NENE2 の OpenAPI バリデーションが oneOf をどう扱うか
>  ドキュメントに書いてあれば確認が速かった。
>  あと EAV パターンの howto があると面白い。」

### DX スコア: ⭐⭐⭐⭐（4/5）

高品質完成。`oneOf` 検証の仕様と EAV パターン比較のドキュメントが改善余地。

---

## まとめ & NENE2 DX 評価

| ペルソナ | 完成度 | スコア | 主なフリクション |
|---------|--------|--------|----------------|
| 清水（新卒） | △ JSON 設計で詰まり再設計 | 2/5 | 回答テーブル正規化、タイプ別バリデーション |
| 上田（ロースキル） | ○ 業務知識で完成 | 3/5 | options_json バリデーション、集計 SQL |
| 斎藤（ベテラン） | ◎ 高品質完成 | 4/5 | OpenAPI oneOf 検証、EAV パターン |

**共通のフリクション**:
1. **動的設問バリデーションのパターン** — タイプ別バリデーションのファクトリーパターン howto。
2. **集計 SQL パターン集** — AVG/COUNT/CASE WHEN/GROUP BY の実例が欲しい（複数シナリオで言及）。
3. **JSON カラム vs 正規化テーブルの設計指針** — `options_json` のトレードオフ説明。
