# Current TODO

Purpose: keep the current work visible across chats, agents, and local sessions.
**引き継ぎドキュメントとして機能する — セッション開始時に必ず読むこと。**

---

## 🔖 FT ループ引き継ぎ状態（毎 5FT 更新）

| 項目 | 値 |
|------|-----|
| 最終完了 FT | **FT280** (`NENE2-FT/lockoutlog` — アカウントロックアウト ATK) |
| 現在の VERSION | **1.5.251** |
| 次の FT | **FT281** |
| 次の ATK 回 | **FT284**（4件ごと: 272 ✅, 276 ✅, 280 ✅, 284） |
| 次の VULN 回 | **FT285**（6件ごと: 273 ✅, 279 ✅, 285） |
| 進行中ブランチ | なし（main クリーン） |

---

## 最近完了した FT（直近 10 件）

| FT | タイプ | howto | VERSION |
|----|--------|-------|---------|
| FT271 | 通常 | `notification-inbox.md` 更新 | 1.5.242 |
| FT272 | ATK | `token-lifecycle-api.md` 新規 | 1.5.243 |
| FT273 | VULN | `bearer-token-middleware.md` 新規 | 1.5.244 |
| FT274 | 通常 | `order-management.md` 更新 | 1.5.245 |
| FT275 | 通常 | `user-profile-api.md` 新規 | 1.5.246 |
| FT276 | ATK | `idempotency.md` 更新 | 1.5.247 |
| FT277 | 通常 | `activity-feed.md` 更新 | 1.5.248 |
| FT278 | 通常 | `direct-messaging-system.md` 更新 | 1.5.249 |
| FT279 | VULN | `rbac-jwt-auth.md` 新規 | 1.5.250 |
| FT280 | ATK | `account-lockout.md` 更新 | 1.5.251 |

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
