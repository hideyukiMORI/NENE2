# Current TODO

Purpose: keep the current work visible across chats, agents, and local sessions.
**引き継ぎドキュメントとして機能する — セッション開始時に必ず読むこと。**

---

## 🔖 FT ループ引き継ぎ状態（毎 5FT 更新）

| 項目 | 値 |
|------|-----|
| 最終完了 FT | **FT315** (`NENE2-FT/hierarchylog` — 階層カテゴリ API VULN) |
| 現在の VERSION | **1.5.286** |
| 次の FT | **FT316** |
| 次の ATK 回 | **FT316**（4件ごと: ...304 ✅, 308 ✅, 312, 316） |
| 次の VULN 回 | **FT321**（6件ごと: ...303 ✅, 309 ✅, 315 ✅, 321） |
| 進行中ブランチ | なし（main クリーン） |

---

## 最近完了した FT（直近 10 件）

| FT | タイプ | howto | VERSION |
|----|--------|-------|---------|
| FT306 | 通常 | `emoji-reactions-api.md` 新規 | 1.5.277 |
| FT307 | 通常 | `etag-conditional-requests.md` 新規 | 1.5.278 |
| FT308 | ATK | `webhook-delivery-system.md` 新規 | 1.5.279 |
| FT309 | VULN | `magic-link-authentication.md` 新規 | 1.5.280 |
| FT310 | 通常 | `event-sourcing-ledger.md` 新規 | 1.5.281 |
| FT311 | 通常 | `expense-tracking-api.md` 新規 | 1.5.282 |
| FT312 | 通常 | `data-export-api.md` 新規 | 1.5.283 |
| FT313 | 通常 | `feature-flag-api.md` 新規 | 1.5.284 |
| FT314 | 通常 | `follow-api.md` 新規 | 1.5.285 |
| FT315 | VULN | `category-hierarchy-api.md` 新規 | 1.5.286 |

---

## ✅ 完了済み TODO（2026-05-27）

| 項目 | 完了 |
|------|------|
| P1: shopping-cart.md 重複解消 | ✅ #1090/#1091 |
| P2: uncovered-fts.sh 旧形式検出追加 | ✅ #1092/#1093 |
| P3: ft-registry.md 台帳作成 | ✅ #1094/#1095 |
| P4: ATK/VULN テンプレートを CLAUDE.md に追加 | ✅ #1096/#1097 |
| FT270 featureflaglog howto 更新 | ✅ #1098/#1099 |

---

## 次のアクション（FT316〜）

```bash
# 未カバー FT を確認
bash tools/uncovered-fts.sh

# FT を選んだらバージョンバンプ
bash tools/bump-ft.sh 1.5.287

# CHANGELOG.md に手動追記してから
docker compose run --rm app composer check
```

---

## その他の検討事項

| 項目 | 状態 |
|------|------|
| src/ 還元 batch 2（JSON ボディ整数バリデーター等） | 📋 候補 |
| v2.0 設計検討（FT ループ摩擦点の還元） | 📋 候補 |

---

## Operating Notes

- このファイルは **5FT ごと**または**セッション終了時**に更新する。
- FT の全履歴は `docs/milestones/` と `docs/roadmap.md` に保管する。
- main がクリーンな状態でセッションを終えること。
