# Current TODO

Purpose: keep the current work visible across chats, agents, and local sessions.
**引き継ぎドキュメントとして機能する — セッション開始時に必ず読むこと。**

---

## 🔖 FT ループ引き継ぎ状態（毎 5FT 更新）

| 項目 | 値 |
|------|-----|
| 最終完了 FT | **FT300** (`NENE2-FT/pointlog` — ポイント台帳 API ATK) |
| 現在の VERSION | **1.5.271** |
| 次の FT | **FT301** |
| 次の ATK 回 | **FT304**（4件ごと: 272〜300 ✅, 304） |
| 次の VULN 回 | **FT303**（6件ごと: 273〜297 ✅, 303） |
| 進行中ブランチ | なし（main クリーン） |

---

## 最近完了した FT（直近 10 件）

| FT | タイプ | howto | VERSION |
|----|--------|-------|---------|
| FT291 | VULN | `group-member-management.md` 新規 | 1.5.262 |
| FT292 | ATK | `idempotency-key.md` 新規 | 1.5.263 |
| FT293 | 通常 | `ab-testing.md` 更新 | 1.5.264 |
| FT294 | 通常 | `batch-api-partial-success.md` 更新 | 1.5.265 |
| FT295 | 通常 | `bookmark-api.md` 新規 | 1.5.266 |
| FT296 | ATK | `geolocation-api.md` 新規 | 1.5.267 |
| FT297 | VULN | `pii-masking.md` 新規 | 1.5.268 |
| FT298 | 通常 | `circuit-breaker.md` 更新 | 1.5.269 |
| FT299 | 通常 | `collection-api.md` 新規 | 1.5.270 |
| FT300 | ATK | `point-ledger-api.md` 新規 | 1.5.271 |

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
