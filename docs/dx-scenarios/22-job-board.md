# DX Scenario 22: 求人掲示板

## アプリ概要

求人・応募・メッセージ・ステータス管理を備えた求人掲示板 API。

| 機能 | エンドポイント例 |
|------|----------------|
| 求人投稿 | `POST /jobs`（title, description, company, location, salary_range, tags） |
| 求人検索 | `GET /jobs?keyword=PHP&location=東京&remote=true&page=1` |
| 応募 | `POST /jobs/{id}/applications`（cover_letter, resume_url） |
| 応募ステータス | `PATCH /applications/{id}/status`（applied→reviewing→interview→offered→rejected） |
| メッセージ | `POST /applications/{id}/messages`（body）|
| メッセージ一覧 | `GET /applications/{id}/messages` |
| 企業の応募一覧 | `GET /companies/{id}/applications?status=reviewing` |
| 求人者自分の応募 | `GET /users/{id}/applications` |

ポイント: 求人と応募者の双方向メッセージ、応募ステータス遷移、多条件キーワード検索。

---

## Persona A — 樋口 康太（新卒・男性・24 歳）

### 背景

情報系専門学校卒業直後。転職サイトを使った経験はあるが「裏側」を考えたことない。

### 作業シナリオ

1. `jobs` / `applications` テーブルを作成。
2. メッセージ機能を「`applications.last_message TEXT`」カラム 1 つで実装してしまう。
3. 応募ステータスは自由に更新できる（遷移ルールなし）。
4. 求人検索 `GET /jobs?keyword=PHP` を `WHERE description LIKE '%PHP%'` で実装。
5. 企業と応募者がメッセージをやり取りする際、「送信者が誰か」を記録しない。

### ハマりポイント

- **メッセージスレッドの設計**: `messages(application_id, sender_id, sender_type, body, sent_at)` テーブルが必要。
- **応募ステータス遷移**: 「rejected → interview」など不正遷移を防ぐガードが必要。
- **sender_type**: 企業側か応募者側かを識別するフィールドの設計。

### 解決策 & 感想

`application_messages(application_id, sender_id, sender_type, body, sent_at)` テーブルに設計変更。
ステータス遷移は `state-machine-workflow-api.md` を参考にした。

> 「メッセージって別テーブルが要るって気づかなかった。
>  LINE のトークみたいな機能なんだから当たり前か。
>  state-machine howto は就活ステータスにも使えた。」

### DX スコア: ⭐⭐⭐（3/5）

`state-machine-workflow-api.md` 活用。メッセージスレッドの設計例が欲しい。

---

## Persona B — 荒木 由里（ロースキル・女性・30 歳）

### 背景

人材派遣会社の IT 担当 7 年目。社内の求人管理ツールを運用してきた。

### 作業シナリオ

1. テーブル設計:
   - `jobs(id, company_id, title, description, location, is_remote, salary_min, salary_max, status, created_at)`
   - `applications(id, job_id, user_id, cover_letter, resume_url, status, applied_at)`
   - `application_messages(id, application_id, sender_id, sender_role, body, created_at)`
   - `job_tags(job_id, tag_id)` で多対多タグ
2. ステータス遷移を `ApplicationWorkflow::allowedTransitions()` で実装。
3. メッセージの `sender_role` = `'applicant' | 'recruiter'`。
4. 求人検索 `GET /jobs?keyword=PHP&location=東京&remote=true` は `WHERE 1=1` 条件分岐。
   タグ検索は `EXISTS (SELECT 1 FROM job_tags jt JOIN tags t ON t.id=jt.tag_id WHERE jt.job_id=j.id AND t.name=?)`.
5. 既読管理は省略（「未読メッセージ件数」機能は今回の要件外）。

### ハマりポイント

- **`EXISTS` サブクエリとページネーション**: `WHERE EXISTS (...)` とページネーションの組み合わせが
  正しく動いているか確認が必要だった。
- **`salary_min/max` の範囲検索**: `?salary_min=400` の意味が「求人の min_salary が 400 以上」か
  「求人の範囲内に 400 が含まれる」かの曖昧さ。
- **`sender_role` vs `sender_type`**: 役職（applicant/recruiter）か型（user/company）かの設計迷い。

### 解決策 & 感想

良好に完成。給与範囲検索の仕様は「求人の min_salary >= 入力値」と定義した。

> 「EXISTS サブクエリは遅そうだけど動いた。
>  給与レンジ検索の仕様、ドキュメントが曖昧だと実装者によって変わるよね。」

### DX スコア: ⭐⭐⭐（3/5）

実用的に完成。EXISTS 検索のパフォーマンスと仕様の明確化が欲しい。

---

## Persona C — 横田 真一（シニア・男性・40 geq 歳）

### 背景

HR Tech ベンチャーのバックエンドリード 10 年。Indeed や LinkedIn のような求人検索エンジンの設計経験あり。

### 作業シナリオ

1. テーブル設計（検索パフォーマンス重視）:
   - `jobs(id, company_id, title, description, location, is_remote, salary_min, salary_max, status, search_text)` — `search_text` は title + description の結合
   - `job_tags` / `applications` / `application_messages(sender_id, sender_role)` / `application_status_history`
2. 検索: `WHERE search_text LIKE ? AND location LIKE ? AND is_remote = ?` で `search_text` の `LIKE` を活用。
   タグは `INNER JOIN job_tags INNER JOIN tags WHERE tags.name IN (?, ?)` + `HAVING COUNT(DISTINCT tags.name) = ?`（AND 条件）。
3. `application_status_history` で全ステータス変更を記録（前シナリオ 21 の監査ログパターン応用）。
4. メッセージ既読管理: `message_reads(message_id, user_id, read_at)` テーブルで実装。
5. OpenAPI で検索パラメータと許可値（`status: open|closed|draft`）を enum 定義。

### ハマりポイント

- **タグ AND 検索**: `PHP AND Laravel` の両方を持つ求人を検索するには
  `HAVING COUNT(DISTINCT) = N` パターンが必要。直感的でない SQL。
- **`search_text` カラムの維持**: title や description を更新したとき `search_text` も更新する
  必要があり、UPDATE 漏れのリスク。
- **FTS5 vs `search_text` LIKE**: SQLite FTS5 を使う方が高速だが、設定が複雑なため
  今回は `search_text` LIKE で妥協。

### 解決策 & 感想

高品質で完成。タグ AND 検索パターンは難しかった。

> 「タグ AND 検索の HAVING パターンは知らないと書けない。
>  howto に 'N:M テーブルで AND 条件を実現するパターン' を書いてほしい。
>  FTS5 の howto も優先度高いと思う。」

### DX スコア: ⭐⭐⭐⭐（4/5）

高品質完成。N:M AND 検索パターンと FTS5 howto が欲しい。

---

## まとめ & NENE2 DX 評価

| ペルソナ | 完成度 | スコア | 主なフリクション |
|---------|--------|--------|----------------|
| 樋口（新卒） | ○ state-machine 活用で改善 | 3/5 | メッセージスレッド設計 |
| 荒木（ロースキル） | ○ 実用的完成 | 3/5 | EXISTS パフォーマンス、仕様の曖昧さ |
| 横田（シニア） | ◎ 高品質完成 | 4/5 | N:M AND 検索 HAVING パターン、FTS5 |

**共通のフリクション**:
1. **N:M テーブルでの AND 検索** — `HAVING COUNT(DISTINCT) = N` パターンの howto。
2. **複合条件の `WHERE 1=1` パターン** — 動的 WHERE 句の標準実装パターン（複数シナリオで言及）。
3. **メッセージスレッド設計** — `sender_role/type` を持つメッセージテーブルのパターン howto。
