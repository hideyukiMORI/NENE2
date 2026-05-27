# Current TODO

Purpose: keep the current work visible across chats, agents, and local sessions.
**引き継ぎドキュメントとして機能する — セッション開始時に必ず読むこと。**

---

## 🔖 FT ループ引き継ぎ状態（毎 5FT 更新）

| 項目 | 値 |
|------|-----|
| 最終完了 FT | **FT339** (`NENE2-FT/sluglog` — スラグ URL VULN) |
| 現在の VERSION | **1.5.310** |
| 次の FT | **FT340** |
| 次の ATK 回 | **FT340**（4件ごと: ...332 ✅, 336 ✅, 340） |
| 次の VULN 回 | **FT345**（6件ごと: ...333 ✅, 339 ✅, 345） |
| 進行中ブランチ | docs/1239-ft339-sluglog (PR #1240 CI 待ち) |

---

## 最近完了した FT（直近 10 件）

| FT | タイプ | howto | VERSION |
|----|--------|-------|---------|
| FT330 | 通常 | `scheduled-publish-article.md` 新規 | 1.5.301 |
| FT331 | 通常 | `password-auth-argon2id.md` 新規 | 1.5.302 |
| FT332 | ATK | `leaderboard-ranking-api.md` 新規 | 1.5.303 |
| FT333 | VULN | `rating-review-api.md` 新規 | 1.5.304 |
| FT334 | 通常 | `article-relations-api.md` 新規 | 1.5.305 |
| FT335 | 通常 | `resource-reservation-booking.md` 新規 | 1.5.306 |
| FT336 | ATK | `reservation-availability-api.md` 新規 | 1.5.307 |
| FT337 | 通常 | `url-shortener-ssrf-prevention.md` 新規 | 1.5.308 |
| FT338 | 通常 | `signed-url-download.md` 新規 | 1.5.309 |
| FT339 | VULN | `slug-url-history.md` 新規 | 1.5.310 |

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
