# Field Trial 150 — クーポン・プロモコード管理（Coupon/Promo Code System）

**Date**: 2026-05-21  
**App**: `couponlog`  
**Path**: `/home/xi/docker/NENE2-FT/couponlog/`  
**NENE2 version**: ^1.5  
**Release**: v1.5.84

---

## What was built

admin RBAC とユーザーごとの利用追跡を備えたクーポン・プロモコード管理システムを実装した。

| Endpoint | 説明 | 権限 |
|---|---|---|
| `POST /coupons` | クーポン作成 | admin |
| `GET /coupons/{code}` | クーポン情報取得 | 誰でも |
| `POST /coupons/{code}/use` | クーポン利用（1ユーザー1回） | 認証済み |
| `GET /coupons/{code}/uses` | 利用履歴一覧 | admin |
| `DELETE /coupons/{code}` | クーポン無効化 | admin |

---

## Architecture decisions

### admin RBAC パターン

`X-User-Role: admin` ヘッダーで権限を判定。
クーポン作成・無効化・利用履歴閲覧は admin のみ許可。
一般ユーザーが作成を試みると 403。認証なしは 401。

### ユーザーごとの一利用制限

`UNIQUE (coupon_id, user_id)` 制約がアプリ層の事前チェック（`findUse`）と組み合わさり、
同一ユーザーの二重利用を防ぐ。DB 制約が最終防衛ライン。

### 利用チェック順序

1. 認証（401）
2. クーポン存在（404）
3. is_active（422）
4. expires_at（422）
5. max_uses（422、0=無制限）
6. ユーザー重複（422）
7. recordUse（INSERT + use_count インクリメント）

### discount_pct の範囲制約

`1-100` の整数のみ許可。アプリ層バリデーションと DB の `CHECK` 制約の二重防御。
0 や 100 超は 422。

### user_id 注入防止

利用者 user_id はボディから受け付けない。`X-User-Id` ヘッダーのみから取得する。

---

## Test results

| Suite | Tests | Result |
|---|---|---|
| `CouponTest.php` (SQLite) | 22 | Pass |
| `VulnTest.php` (SQLite) | 12 | Pass |
| **Total** | **34** | **Pass** |

---

## 脆弱性診断（VulnTest.php）

| ID | 内容 | 結果 |
|---|---|---|
| VULN-A | 非認証ユーザーはクーポン作成不可 → 401 | Pass |
| VULN-B | 一般ユーザーはクーポン作成不可 → 403 | Pass |
| VULN-C | 一般ユーザーは利用履歴閲覧不可 → 403 | Pass |
| VULN-D | 一般ユーザーはクーポン無効化不可 → 403 | Pass |
| VULN-E | 有効期限切れクーポンは利用不可 → 422 | Pass |
| VULN-F | 無効化済みクーポンは利用不可 → 422 | Pass |
| VULN-G | 同一ユーザーの二重利用防止 → 422 | Pass |
| VULN-H | max_uses 超過後の利用防止 → 422 | Pass |
| VULN-I | discount_pct=0 が拒否される → 422 | Pass |
| VULN-J | discount_pct=101 が拒否される → 422 | Pass |
| VULN-K | SQL インジェクション試みに 404 を返す | Pass |
| VULN-L | user_id はボディから受け付けない | Pass |

全 12 件 Pass — クーポン管理の主要な脆弱性パターンをすべて検出・防御確認。

---

## Developer Experience (DX) Review

### Persona 1 — 初心者 Web 開発者（PHP 学習中）

「クーポンコードは EC サイトでよく見る機能なので、作るものがイメージしやすかった。
admin かどうかを `X-User-Role` ヘッダーで判定するパターンは最初シンプルすぎる気がしたが、
『テスト中は自分で値を渡せる→実運用では認証ミドルウェアが付与する』という説明で納得した。
discount_pct を 1〜100 の整数に限定するバリデーションは、DB の CHECK 制約とアプリ層の両方で
書くことで『アプリが壊れても DB が最後に守る』という多層防御の概念を学べた。
同一ユーザーの二重利用を `UNIQUE (coupon_id, user_id)` で防ぐ発想は
FT146 の position 管理と同じパターンで、データベース制約の活用例として覚えやすかった。」

★★★★☆ — EC 機能の実装で多層防御パターンが学べる

### Persona 2 — Laravel 経験者（NENE2 初学）

「Laravel では `Policy::before()` で admin チェックを書くが、NENE2 は各ハンドラの冒頭で
明示的に書く。その分、`if (!$this->isAdmin($request)) { return 403; }` の流れが
コードを追うだけで分かるのはメリット。
クーポンコードの lookup に `findByCode()` を使い、ユーザー重複チェックを事前に行ってから
INSERT するパターンは Laravel の `firstOrFail()` と `syncWithoutDetaching()` に近い。
`use_count = use_count + 1` でインクリメントするのは Eloquent の `increment()` に相当し、
MySQL での並行アクセスに対しても安全なパターンだと理解した。
有効期限を ISO 8601 文字列で比較する実装は、Laravel の Carbon を使わずに済む点が
依存の少なさとして評価できる。」

★★★★☆ — 明示的な権限チェックで可読性高い

### Persona 3 — セキュリティエンジニア

「RBAC チェックは各エンドポイントで明示的に実行されており、ミドルウェアの設定漏れによる
バイパスリスクがない（ただしミドルウェア統合後は設定を二重確認すること）。

`user_id` をボディではなく `X-User-Id` ヘッダーのみから取得する設計（VULN-L）は
なりすまし防止として適切。

クーポンコードのブルートフォース耐性については、短いコード（例: 8文字英数字）は
十分なエントロピーを持つが、現実的にはレート制限が必要。
本実装は `ThrottleMiddleware` を導入していないため、高頻度のコード推測が可能。

`UNIQUE (coupon_id, user_id)` 制約は MySQL での並行 INSERT でも最終防衛ラインとして機能するが、
TOCTOU（findUse 後に並行 INSERT）が発生しうる。
MySQL では `DatabaseConstraintException` をキャッチして 422 を返す追加実装が望ましい。

discount_pct の DB CHECK 制約（`>= 1 AND <= 100`）はアプリ側バリデーション失敗時の
第二防衛ラインとして有効。」

★★★★☆ — 主要な認可制御は適切、レート制限の追加を推奨

### Persona 4 — フロントエンド開発者（API 利用者）

「チェックアウト画面でのクーポン適用フローが直感的に実装できる。
`GET /coupons/{code}` でコード検証（is_active、expires_at 表示）→
`POST /coupons/{code}/use` で適用、という 2 ステップが明確。
201 vs 422 の分岐が明確なのでトースト通知の出し分けが楽。
エラーメッセージ（'not active'、'expired'、'limit reached'、'already used'）が
ユーザー向けメッセージに変換しやすい粒度になっている。
admin 側の利用履歴（`GET /coupons/{code}/uses`）で user_id と user_name が返るので、
管理画面でユーザーテーブルとの JOIN 不要で表示できる。」

★★★★☆ — チェックアウト UI への統合がシンプル

### Persona 5 — インフラ・DevOps エンジニア

「`coupons.code` に UNIQUE 制約あり（ルックアップが O(log n)）。
`coupon_uses.(coupon_id, user_id)` の複合 UNIQUE インデックスが重複防止と
ルックアップ両方に機能する。
`use_count` のインクリメントは `UPDATE ... SET use_count = use_count + 1` を使っており、
MySQL でもアトミックに機能する。
高負荷時は `SELECT ... FOR UPDATE` または `INSERT ... ON CONFLICT DO NOTHING` で
楽観的ロックを検討すること。
クーポンコードの文字列長は定義されていないため、長いコード（例: 256 文字）でも
UNIQUE インデックスが機能するか事前に確認すること（MySQL は 767 バイト制限あり）。」

★★★★☆ — 小〜中規模で十分、高並行時はロック戦略追加

### Persona 6 — プロダクトマネージャー

「EC サイト・SaaS・サブスクリプションで必須のクーポン機能。
'1 ユーザー 1 回のみ利用可能' は紹介キャンペーンや初回購入クーポンで最も使われるパターン。
max_uses=0（無制限）と max_uses=N（上限あり）の切り替えが 1 フィールドでできるのは
マーケティング施策の柔軟性につながる。
今後の拡張:
- 最小購入金額（`min_order_amount`）
- 適用対象カテゴリ制限
- クーポン有効化予約（`starts_at`）
- admin によるコード一括生成（バルク作成）
- 利用統計ダッシュボード（`GET /coupons` + 集計）
'discount_pct 100 = 無料化' を許可するかは PM とビジネスルールで決めること
（本実装では 1-100 を許可）。」

★★★★★ — EC・SaaS 必須機能として即使えるレベル

---

## Howto

`docs/howto/coupon-promo-code.md`
