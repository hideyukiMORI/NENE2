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

---

## Operating Notes

- このファイルは **5FT ごと**または**セッション終了時**に更新する。
- FT の全履歴は `docs/milestones/` と `docs/roadmap.md` に保管する。
- main がクリーンな状態でセッションを終えること。
