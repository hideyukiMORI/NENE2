# Field Trial 147 — コンテンツ通報・モデレーション（Content Report & Moderation）

**Date**: 2026-05-21  
**App**: `reportlog`  
**Path**: `/home/xi/docker/NENE2-FT/reportlog/`  
**NENE2 version**: ^1.5  
**Release**: v1.5.81  
**Special**: 脆弱性診断（VulnTest.php）— 3FT サイクル

---

## What was built

コンテンツ（記事）の通報・モデレーションシステムを実装した。
ユーザーが記事を通報し、モデレーターが通報を確認・解決・却下できる。

| Endpoint | 説明 |
|---|---|
| `POST /reports` | 記事を通報（冪等: 同記事再通報 = 200 / 新規 = 201） |
| `GET /reports` | 通報一覧（モデレーター専用） |
| `GET /reports/{id}` | 通報詳細（自分の通報 or モデレーター） |
| `PUT /reports/{id}/resolve` | 通報を解決（モデレーター専用） |
| `PUT /reports/{id}/dismiss` | 通報を却下（モデレーター専用） |

---

## Architecture decisions

### RBAC — ロールベースアクセス制御

`users.role` カラムに `CHECK (role IN ('user', 'moderator'))` で DB 制約を持たせる。
アプリ層では各ハンドラー先頭で `role === 'moderator'` をチェックし、非モデレーターに 403 を返す。
フレームワークの認証ミドルウェアとは独立したシンプルなロールチェックで十分なスコープを達成できた。

### IDOR 防止

`GET /reports/{id}` は「自分の通報」か「モデレーター」のみ参照可能。
reporter_id は常に `X-User-Id` ヘッダーから設定し、リクエストボディの `reporter_id` フィールドは無視する。
これによりなりすまし（他ユーザーの ID を body に詰めてレポートを偽造）を防ぐ。

### 冪等通報（201 / 200）

`UNIQUE (reporter_id, article_id)` 制約を基盤として、
アプリ層では INSERT 前に既存チェックをして 200 / 201 を切り替える。
UNIQUE 違反を `DatabaseConstraintException` でキャッチするより、事前チェックの方が冪等セマンティクスが明確。

### ステータス遷移の一方向性

`pending → resolved / dismissed` のみを許可し、逆方向遷移は 422 で拒否する。
DB に `CHECK (status IN (...))` を持たせることで、アプリ層のバリデーション漏れを DB が二重保護する。

### NENE2 Router パスパラメータ

パスパラメータは `nene2.route.parameters` 属性（`Router::PARAMETERS_ATTRIBUTE`）に格納される。
`$request->getAttribute('id')` では取得できず、`Router::param($request, 'id')` が正しい。
（FT147 でこのハマりを発見・修正した重要なパターン。）

---

## Vulnerability assessment (VulnTest.php)

12 件の脆弱性シナリオを検証した。

| ID | テスト内容 | 結果 |
|---|---|---|
| VULN-A | IDOR: 他ユーザーの通報を参照できない | Pass |
| VULN-B | 通常ユーザーは通報一覧を取得できない | Pass |
| VULN-C | 通常ユーザーは通報を解決できない | Pass |
| VULN-D | 通常ユーザーは通報を却下できない | Pass |
| VULN-E | 重複通報は冪等（UNIQUE 違反で 500 にならない） | Pass |
| VULN-F | 解決済み通報の再解決を拒否（422） | Pass |
| VULN-G | 却下済み通報の再却下を拒否（422） | Pass |
| VULN-H | reporter_id はボディから偽造できない | Pass |
| VULN-I | X-User-Id=0 は認証失敗（401） | Pass |
| VULN-J | X-User-Id ヘッダーなしは認証失敗（401） | Pass |
| VULN-K | モデレーターは任意の通報を参照できる | Pass |
| VULN-L | 存在しない通報は 404（DB エラー非公開） | Pass |

---

## Test results

| Suite | Tests | Result |
|---|---|---|
| `ReportTest.php` (SQLite) | 20 | Pass |
| `VulnTest.php` (SQLite) | 12 | Pass |
| **Total** | **32** | **Pass** |

---

## Developer Experience (DX) Review

### Persona 1 — 初心者 Web 開発者（PHP 学習中）

「通報システムは YouTube や Twitter でよく見る機能なのでイメージしやすかった。
モデレーター専用の API があること自体は理解できたが、IDOR という言葉は初めて聞いた。
『自分の通報を他のユーザーが見られる』という脆弱性の説明を受けて、初めてアクセス制御の
重要性が感じられた。ステータス遷移が一方向しか許可されない設計は『一度解決したら
やり直せない』という業務ルールをコードで表現しているんだとわかった。
reporter_id をヘッダーから取って body からは無視する仕組みは、最初はなぜかわからなかったが
VulnTest で『body に reporter_id=999 を入れて別人として投稿する』攻撃シナリオを見て納得した。」

★★★★☆ — 脆弱性テストが概念理解を助ける良い学習素材

### Persona 2 — Laravel 経験者（NENE2 初学）

「Laravel では `Policy` クラスで `before()` メソッドにモデレーター判定を入れるパターンが
一般的だが、NENE2 ではハンドラーごとに明示的なロールチェックを書いている。
冗長に見えるが可読性は高い。
`firstOrCreate` パターンと同じ冪等追加ロジックが PHP 8.4 の enum + match で
すっきり書けているのは好印象。IDOR 防止で `reporter_id` をボディから取らない設計は
Laravel の `$request->user()->id` に相当する発想で、フレームワークが違っても
同じ原則が適用されていると感じた。VulnTest が Policy テストに対応するものとして
参照できるのは NENE2 固有の良さだと思う。」

★★★★☆ — 明示的ロールチェックで可読性高い

### Persona 3 — セキュリティエンジニア

「RBAC の実装は適切。モデレーター専用エンドポイント（GET /reports, PUT /reports/{id}/resolve, dismiss）は
先頭でロールチェックを行い、早期リターンしている。
IDOR 防止: GET /reports/{id} は reporter_id と X-User-Id を照合してアクセス制御している。
reporter_id の body 注入攻撃: VULN-H で検証済み、createReport に reporter_id 引数が存在し
handler が actorId のみを渡す設計なので問題なし。
ステータス遷移: VULN-F/G で一方向性を確認。DB CHECK 制約がアプリ層のバリデーション漏れを補完している。
気になる点: X-User-Id の存在は確認しているが、実際のユーザー存在を GET /reports/{id} でも
確認している（findUserById）のは良い。ただし rate limiting がないため大量通報は可能。
本番では 1 ユーザーあたりの通報上限（例: 1 記事につき 1 通報の UNIQUE 制約は実装済み）や
報告レート制限が必要。」

★★★★☆ — 基本的なアクセス制御・IDOR 防止は適切

### Persona 4 — フロントエンド開発者（API 利用者）

「通報フォームの実装に必要な情報が揃っている。
POST /reports が 201（新規）/ 200（既存）で区別できるので
『通報済みです』メッセージとの切り替えが容易。
reason フィールドは enum 値（spam/harassment/misinformation/other）が
エラーレスポンスの valid_reasons 配列で返るので、フォームの選択肢を動的に構築できる。
GET /reports/{id} がステータス・解決者・解決メモを返すので、通報者側のステータス確認 UI も作れる。
モデレーター画面では GET /reports の count フィールドで件数バッジを表示できる。
404 と 403 の区別（通報が見えない vs 見る権限がない）の使い分けを UI 側がどう処理するかは
設計の余地がある（通報の存在自体を隠したければ 404 統一も選択肢）。」

★★★★☆ — 通報フォームとモデレーション UI の両方が実装できる

### Persona 5 — インフラ・DevOps エンジニア

「`UNIQUE (reporter_id, article_id)` は通報が増えても低コストなインデックスになる。
`CHECK` 制約は SQLite・MySQL・PostgreSQL すべてで有効。
`status` カラムには現状インデックスがないが、モデレーター画面で
`WHERE status = 'pending'` のクエリが頻出する場合は `(status, created_at)` 複合インデックスを追加すべき。
`resolved_by` の FOREIGN KEY はソフトデリート実装時に注意が必要（モデレーターアカウントを削除できなくなる）。
MySQL 移行時: `CHECK` 制約は MySQL 8.0.16+ で有効。それ以前では無効なので注意。
SQLite テストが全 32 件 pass していることで回帰リスクは低い。」

★★★★☆ — 小規模に十分、中規模以上はインデックス追加を検討

### Persona 6 — プロダクトマネージャー

「コミュニティの健全性維持に通報・モデレーション機能は必須。
設計の良い点: モデレーター権限を DB で管理しているので、ユーザーへのロール付与が
SQL 1 行で可能（`UPDATE users SET role = 'moderator' WHERE id = ?`）。
resolution_note が通報者に返るので、なぜ解決・却下されたかのフィードバックが
ユーザーに提供できる（将来の UX 改善に使える）。
冪等通報（201/200）は重複クリックに強いため、モバイルアプリとの相性が良い。
今後の拡張: 通報カテゴリの追加（著作権侵害、成人向けコンテンツ等）、
通報統計ダッシュボード（何が最も通報されているか）、自動モデレーション連携（AI フィルター）、
モデレーターへの新規通報メール通知。」

★★★★★ — プロダクト機能として即座に使えるレベル

---

## Howto

`docs/howto/content-report-moderation.md`
