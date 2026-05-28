# Current TODO

Purpose: keep the current work visible across chats, agents, and local sessions.
**引き継ぎドキュメントとして機能する — セッション開始時に必ず読むこと。**

---

## 🔖 FT ループ引き継ぎ状態（毎 5FT 更新）

| 項目 | 値 |
|------|-----|
| 最終完了 FT | **FT349** (`NENE2-FT/workflowlog` — ステートマシン型ワークフロー) |
| 現在の VERSION | **1.5.322** |
| 次の FT | **なし（全 FT カバー完了）** |
| 次の ATK 回 | FT352（4件ごと: ...340 ✅, 344 ✅, 348 ✅, 352） |
| 次の VULN 回 | FT351（6件ごと: ...339 ✅, 345 ✅, 351） |
| 進行中ブランチ | なし |

---

## 🎉 全 FT カバー達成（2026-05-27）

```bash
bash tools/uncovered-fts.sh
# → (すべての FT がカバー済みです)
```

---

## 最近完了した FT（直近 10 件）

| FT | タイプ | howto | VERSION |
|----|--------|-------|---------|
| FT340 | ATK | `soft-delete-trash-restore.md` 新規 | 1.5.311 |
| FT341 | 通常 | `dynamic-sort-order-injection.md` 新規 | 1.5.312 |
| FT342 | 通常 | `jwt-tenant-isolation.md` 新規 | 1.5.313 |
| FT343 | 通常 | `threaded-comments-api.md` 新規 | 1.5.314 |
| FT344 | ATK | `category-hierarchy-api.md` 新規 | 1.5.315 |
| FT345 | VULN | `unicode-aware-text-api.md` 新規 | 1.5.316 |
| FT346 | 通常 | `api-versioning.md` 新規 | 1.5.317 |
| FT347 | 通常 | `upvote-downvote-api.md` 新規 | 1.5.318 |
| FT348 | ATK | `webhook-delivery-api.md` 新規 | 1.5.319 |
| FT349 | 通常 | `state-machine-workflow-api.md` 新規 | 1.5.320 |

---

## ✅ 完了済み TODO（2026-05-27）

| 項目 | 完了 |
|------|------|
| P1: shopping-cart.md 重複解消 | ✅ #1090/#1091 |
| P2: uncovered-fts.sh 旧形式検出追加 | ✅ #1092/#1093 |
| P3: ft-registry.md 台帳作成 | ✅ #1094/#1095 |
| P4: ATK/VULN テンプレートを CLAUDE.md に追加 | ✅ #1096/#1097 |
| FT270 featureflaglog howto 更新 | ✅ #1098/#1099 |
| **全 FT カバー達成** | ✅ #1262 |
| **DX シナリオテスト 50 件作成** | ✅ #1267/#1268 |

---

## 🛠️ DX 改善実装 TODO（Trial 01〜26 / 78試験より）

詳細: `docs/todo/dx-trial-improvements.md`

### Phase 1 — コードリリース

フレームワーク本体の変更が必要。

| # | 内容 | 効果 | 状態 |
|---|------|------|------|
| IMP-07 | `DatabaseConstraintException` を公開 API に昇格 | 全78試験で workaround が発生。最大インパクト | ✅ 完了済み（v1.5.114+ で Packagist 公開済み・#1305 で TODO 整理） |
| IMP-04 | `DatabaseConfig::sqlite(string $path): self` ファクトリメソッド追加 | テストの DatabaseConfig コンストラクタ8引数を1行に短縮 | ✅ 完了 (#1303/#1304) |
| IMP-14 | テスト用 PDO 直注入パターンを `Nene2\Testing\DatabaseTestKit` で公式化 | `@internal` 回避の匿名クラス不要に | ✅ 完了 (#1307/#1308・ADR 0012) |
| IMP-01 | `Router::param()` を vendor に収録 or 乖離を howto に明記 | シニアが毎回実行時 500 を踏む | ✅ 完了済み（v1.5.31+ で公開済み・#1317 で ADR 0009 stable API に正式記載） |

### Phase 2 — howto 追記（最優先・`add-database-endpoint.md` 中心）

コード変更不要。1ファイル集中で大部分を解消できる。

| # | 追記先 | 内容 | 重要度 |
|---|--------|------|--------|
| IMP-09 | `add-database-endpoint.md` 冒頭 | **PSR-4 namespace 図解**（`"Ns\\": "src/Ns/"` → ファイル内は `namespace Ns;`）| 🔴 最高 |
| IMP-19 | `add-database-endpoint.md` ファイル構成図 | **AppFactory は `src/Ns/` 内に置く**（`src/AppFactory.php` は autoload 対象外）| 🔴 最高 |
| IMP-15 | `add-database-endpoint.md` | `create()` / `createList()` / `createEmpty()` 比較表 | 🟠 高 |
| IMP-12/20 | `add-database-endpoint.md` HAVING/WHERE 節 | SQLite プレースホルダーと数値比較は **全般的に `CAST(? AS INTEGER)`** が必要 | 🟠 高 |
| IMP-02 | `add-database-endpoint.md` transactional 節 | コールバック内では `$this->db` でなく引数の `$db` を使う | 🟠 高 |
| IMP-11 | `add-database-endpoint.md` テストサンプル | 空ボディは `'{}'` or `json_encode(new \stdClass())`（`[]` は JSON 配列になる）| 🟡 中 |
| IMP-16 | `add-database-endpoint.md` PHPStan 節 | 数値文字列キーを持つ配列は `array<int\|string, T>` で宣言 | 🟡 中 |

### Phase 3 — howto 追記（その他ファイル）

| # | 追記先 | 内容 | 重要度 |
|---|--------|------|--------|
| IMP-18 | `use-transactions.md` | `:memory:` SQLite + `transactional()` 非互換の警告 | 🟠 高 |
| IMP-10 | `use-transactions.md` | コールバック内では `new Repository($txExecutor)` パターン | 🟡 中 |
| IMP-13 | テスト howto | `withParsedBody()` は `JsonRequestBodyParser` をバイパス → `withBody()` + JSON stream | 🟡 中 |
| IMP-06 | `add-database-endpoint.md` | `createEmpty(204)` の存在を明記（IMP-15 と同時対応可） | 🟡 中 |
| IMP-17 | テスト howto / サンプルコード | `use Psr\Http\Server\RequestHandlerInterface` を明示 | 🟡 中 |
| IMP-03 | `add-database-endpoint.md` | SQLite は `FOR UPDATE` 非対応 → アトミック UPDATE で代替 | 🟢 低 |
| IMP-05 | `use-transactions.md` | 途中失敗をシミュレートするテスト例 | 🟢 低 |
| IMP-21 | `add-database-endpoint.md` | PHP regex で `$` をデリミタ直前に使う場合は `\z` か `explode()` | 🟢 低 |

### 着手順序（推奨）

```
1. v1.5.23 リリース（IMP-07 + IMP-04 + IMP-14 をまとめて）
2. add-database-endpoint.md に IMP-09/19/15/12/20/02/11/16 を一括追記
3. use-transactions.md に IMP-18/10 を追記
4. テスト howto に IMP-13/17 を追記
5. 残り（IMP-03/05/21）は余裕があれば
```

---

## 次のアクション

```bash
# 未カバー FT を確認（新 FT が追加されたとき）
bash tools/uncovered-fts.sh

# FT を選んだらバージョンバンプ
bash tools/bump-ft.sh 1.5.322

# CHANGELOG.md に手動追記してから
docker compose run --rm app composer check
```

---

## その他の検討事項

| 項目 | 状態 |
|------|------|
| src/ 還元 batch 2（JSON ボディ整数バリデーター等） | 📋 候補 |
| v2.0 設計検討（FT ループ摩擦点の還元） | 📋 候補 |
| 新規 FT 追加（FT350〜）の ATK/VULN サイクル継続 | 📋 候補 |
| DX シナリオ 50 件から howto 候補を抽出・優先度付け | 📋 候補 |

### DX シナリオ分析から抽出した howto 候補（上位）

| 優先 | howto テーマ | 言及シナリオ数 |
|------|-------------|--------------|
| ★★★ | SQLite ウィンドウ関数（LAG/LEAD/ROW_NUMBER）3.25+ | 7+ |
| ★★★ | 動的 WHERE 句（WHERE 1=1 AND ...） | 6+ |
| ★★★ | 整数演算による金額計算（intdiv / ROUND） | 8+ |
| ★★★ | N:M AND 検索（HAVING COUNT(DISTINCT) = N） | 5+ |
| ★★ | Atomic UPDATE パターン（競合防止） | 5+ |
| ★★ | マルチテナント API 設計（テナント分離 + API キー） | 2 |
| ★★ | order_index 一括更新（CASE WHEN UPDATE） | 3 |
| ★★ | SQLite 動的 interval（date + カラム値） | 3 |
| ★★ | 階層データ再帰 CTE（エリア・組織・カテゴリ） | 4 |
| ★ | ロールベース情報マスキング（匿名 DTO 制御） | 2 |

---

## Operating Notes

- このファイルは **5FT ごと**または**セッション終了時**に更新する。
- FT の全履歴は `docs/milestones/` と `docs/roadmap.md` に保管する。
- main がクリーンな状態でセッションを終えること。
