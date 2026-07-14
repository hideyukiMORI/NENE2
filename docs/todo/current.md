# Current TODO

Purpose: keep the current work visible across chats, agents, and local sessions.
**引き継ぎドキュメントとして機能する — セッション開始時に必ず読むこと。**

---

## 🚧 現在のレーン — framework hardening（v1.11.0）

| 項目 | 値 |
|------|-----|
| 現在の VERSION | **1.11.0**（`src/FrameworkInfo.php` が正） |
| 主軸 | 共有ホスティング向け opt-in 部品の連続投入（installer → fail-closed auth → audit → export → conformance → demo） |
| v1.6.0 の成果 | opt-in インストーラ toolkit `Nene2\Install`（payload セキュリティ核 / preflight & config / TenantConfigurationValidator / DatabaseSchemaApplier / release manifest / ReleaseSource / 提示契約 / 無ブランド参照レンダラ・`src/Install/`） |
| v1.7.0 の成果 | fail-closed な JWT secret 解決 `GuardedJwtSecretResolver`（ADR 0013）＋ Installer 入力型（#1490/#1482） |
| v1.8.0 の成果 | 監査ログ基盤 `Nene2\Audit`（ADR 0014）＋ CSV 出力 `Nene2\Export\CsvWriter`（ADR 0015）＋ `LocalBearerTokenVerifier` の Clock 注入 |
| v1.8.1/1.8.2 の成果 | 準拠リンタ `tools/conformance.php`（ADR 0016）＋消費側 autoload / D1 誤検知の修正 |
| **v1.9.0 の成果** | **使い捨て org デモモジュール `Nene2\Demo`**（ADR 0017・2026-07-09 施主決定=デモ方式を invoice 型に全製品統一）。5 interface（provisioner/reaper/seater/seeder/template key）＋具象（`StartDisposableDemoHandler`/`DisposableDemoSweeper`/`CountingDemoCapacityGuard`=invoice #608 根治点/`DemoRouteRegistrar`）＋`DEMO_*` typed 化（`AppConfig::$demo`）＋howto `add-disposable-demo.md`（5ロケール訳）（#1522/#1523/#1524） |
| **v1.10.0 の成果** | **`Nene2\Demo` ブラウザ向けエラーの HTML negotiation**（ADR 0018・invoice 本番 2026-07-10 実発生の上流化）。`DemoErrorPageRendererInterface`＋既定 `MinimalDemoErrorPageRenderer`（ページ専用 CSP 持ち＝アプリ全体 `default-src 'self'` のインライン CSS ブロック罠を回避）。不変条件（API/成功のバイト不変・status/`Retry-After` 強制コピー・`X-Robots-Tag: noindex`）は handler が強制。`DemoRouteRegistrar` を PSR-15 `RequestHandlerInterface` 受けに拡大（デコレータの一般解）・throttle 既定 10→30回/h（invoice 本番実証）（#1536/#1537） |
| **v1.11.0 の成果** | **X-Authorization フォールバック受け口 `Nene2\Middleware\AuthorizationHeaderFallbackMiddleware`**（ADR 0019・nene-clear #265 本番実証の上流化）。前段 proxy が標準 `Authorization` を剥がす共有ホスティング（HETEML 型 Tier A）向けに、`Authorization` 不在/空のときのみ `X-Authorization: Bearer` ミラーを採用（method/path 非依存・標準ヘッダ環境ではバイト不変）。`RuntimeApplicationFactory` に **opt-in** フラグ `enableAuthorizationHeaderFallback`（既定 `false`）。FE 側は nene2-js v1.1.0 が全リクエストで両ヘッダ送出済み — BE 受け口有効化で初めてミラーが E2E で効く。（#1557/#1558） |
| 第1 consumer | NeNe Invoice（#562。demo モジュールの consumer 化も invoice が先頭 — 指揮リナの別指示書待ち） |
| 進行中ブランチ | なし |

### 未処理 Issue

なし（2026-07-14 時点で open Issue ゼロ。#1557 は PR #1558 で完了）。

※ #1421 は 2026-07-06 完了。#1514 は v1.8.2 修正で 07-09 クローズ。#1526/#1527（ローカル限定テスト失敗）は 07-09 修正済み（#1529/#1530）。

### 引き継ぎメモ（2026-07-09・Nene2\Demo リリース）

- 次工程は **invoice/clear/vault の consumer 化**（NENE2 スコープ外・指揮リナが別指示書を発行）。invoice consumer 化では `TENANT_RESOLUTION=path` 前提と path 二重合成（workspace issues #38）を実機回帰で必ず踏むこと。
- v1.10.0（ADR 0018）取り込み後、invoice は `DemoBrowserErrorPage` を `DemoErrorPageRendererInterface` 実装へ載せ替えて自前 registrar を廃止できる（NENE2 スコープ外・別発注）。clear/deal/vault は framework 既定でブラウザ向けエラーページが最初から効く。
- ローカル Docker の `composer test` は**完全緑**（832 tests）。かつて存在した環境起因失敗2種は根治済み — ① compose の `DB_NAME` env 干渉 → テストを env 非依存化（#1526/#1530） ② ext-zip 欠如 → Dockerfile に追加（#1527/#1529・**要 `docker compose build app`**）。

> 設計方針: すべて opt-in・generic。wire しなければ dormant、製品固有の前提（UI/ブランド/語彙/パス）を core に焼かない。詳細は CHANGELOG `[1.6.0]` / `[1.7.0]` / `[Unreleased]`。

---

## ⏸ FT ループ引き継ぎ状態（一時停止中）

> **2026-07-04 の施主判断で FT ループは降格・期日撤廃**（installer レーン + biz 優先）。
> 再開時はこの表と `bash tools/uncovered-fts.sh` を起点にする。横断状況は `_work/board.txt` が正。

| 項目 | 値 |
|------|-----|
| 最終完了 FT | **FT352** (`bulk-reorder-api.md` — ドラッグ&ドロップ並べ替え API / ATK) / VERSION 1.5.326 |
| 次の FT（再開時） | **FT353** |
| 次の ATK 回 | FT356（4件ごと: ...344 ✅, 348 ✅, 352 ✅, 356） |
| 次の VULN 回 | FT357（6件ごと: ...345 ✅, 351 ✅, 357） |

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
| FT343 | 通常 | `threaded-comments-api.md` 新規 | 1.5.314 |
| FT344 | ATK | `category-hierarchy-api.md` 新規 | 1.5.315 |
| FT345 | VULN | `unicode-aware-text-api.md` 新規 | 1.5.316 |
| FT346 | 通常 | `api-versioning.md` 新規 | 1.5.317 |
| FT347 | 通常 | `upvote-downvote-api.md` 新規 | 1.5.318 |
| FT348 | ATK | `webhook-delivery-api.md` 新規 | 1.5.319 |
| FT349 | 通常 | `state-machine-workflow-api.md` 新規 | 1.5.320 |
| FT350 | 通常 | `use-window-functions.md` 新規 | 1.5.324 |
| FT351 | VULN | `csv-export-formula-injection.md` 新規 | 1.5.325 |
| FT352 | ATK | `bulk-reorder-api.md` 新規 | 1.5.326 |

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

## ✅ DX 改善実装 — 全 Phase 完了（2026-05-29）

詳細: `docs/todo/dx-trial-improvements.md`

DX Trial 01〜26（78 試験）で抽出した IMP-01〜21 のうち、TODO 化された 14 件全てを完了。

| Phase | 内容 | 結果 PR |
|-------|------|---------|
| Phase 1 | コードリリース（IMP-04/07/14/01） | #1304 / #1306 / #1308 / #1318 |
| Phase 2 | `add-database-endpoint.md` 一括追記（IMP-09/19/15/12/20/02/11/16） | #1310 |
| Phase 3a | `use-transactions.md` 追記（IMP-18/10/05） | #1312 |
| Phase 3b | test traps（IMP-13/17） | #1314 |
| Phase 3c | IMP-03/21 | #1316 |

主な成果:
- 新規 stable public API: `DatabaseConfig::sqlite()`, `Nene2\Testing\DatabaseTestKit`, ADR 0009 に `Router::param()` 正式記載
- 新規 ADR 0012: `Nene2\Testing` 名前空間の決定記録
- `add-database-endpoint.md` / `use-transactions.md` に DX 罠 11 件のコールアウト追加

---

## 次のアクション

**現在のレーン（framework hardening）:**

- `Nene2\Demo` の consumer 化（invoice→clear→vault）— NENE2 スコープ外。指揮リナの別指示書を待って着手。
- 製品側の自作監査ログを `Nene2\Audit` へ移行する別 PR（invoice / payout / profile / vault / clear）。

**FT ループ再開時（保留中）:**

```bash
# 未カバー FT を確認（新 FT が追加されたとき）
bash tools/uncovered-fts.sh

# FT を選んだらバージョンバンプ（引数なしで +1）
bash tools/bump-ft.sh

# CHANGELOG.md に手動追記してから
docker compose run --rm app composer check
```

---

## その他の検討事項

| 項目 | 状態 |
|------|------|
| howto 資産化・検索性向上（Phase A → Phase B） | 📋 方針提案あり — [`howto-curation-strategy.md`](howto-curation-strategy.md) (#1323) |
| src/ 還元 batch 2（JSON ボディ整数バリデーター等） | 📋 候補 |
| v2.0 設計検討（FT ループ摩擦点の還元 / ロードマップ Phase 76） | 📋 候補 |
| 新規 FT 追加（FT350〜）の ATK/VULN サイクル継続 | 📋 候補（全 FT カバー後の継続） |
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
