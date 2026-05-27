# Current TODO

Purpose: keep the current work visible across chats, agents, and local sessions.
**引き継ぎドキュメントとして機能する — セッション開始時に必ず読むこと。**

---

## 🔖 FT ループ引き継ぎ状態（毎 5FT 更新）

| 項目 | 値 |
|------|-----|
| 最終完了 FT | **FT270** (`NENE2-FT/featureflaglog` — フィーチャーフラグ API) |
| 現在の VERSION | **1.5.241** |
| 次の FT | **FT271** |
| 次の ATK 回 | **FT272**（4件ごと: FT252, 256, 260, 264, 268, 272 ✅ 予定） |
| 次の VULN 回 | **FT273**（6件ごと: FT249, 255, 261, 267, 273 ✅ 予定） |
| 進行中ブランチ | なし（main クリーン） |

---

## 最近完了した FT（直近 10 件）

| FT | タイプ | howto | VERSION |
|----|--------|-------|---------|
| FT261 | VULN | `jwt-authentication.md` 更新 | 1.5.232 |
| FT262 | 通常 | `multi-currency-money-ledger.md` | 1.5.233 |
| FT263 | 通常 | `emoji-reactions-toggle.md` | 1.5.234 |
| FT264 | ATK | `sql-injection-defence.md` | 1.5.235 |
| FT265 | 通常 | `url-bookmark-api.md` | 1.5.236 |
| FT266 | 通常 | `api-key-management.md` 更新 | 1.5.237 |
| FT267 | VULN | `encrypted-field-storage.md` 更新 | 1.5.238 |
| FT268 | ATK | `audit-trail.md` 更新 | 1.5.239 |
| FT269 | 通常 | `shopping-cart-api.md` | 1.5.240 |
| FT270 | 通常 | `feature-flags.md` 更新 | 1.5.241 |

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

## 次のアクション（FT271〜）

```bash
# 未カバー FT を確認
bash tools/uncovered-fts.sh

# FT を選んだらバージョンバンプ
bash tools/bump-ft.sh 1.5.242

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
