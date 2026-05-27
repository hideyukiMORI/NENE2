# Current TODO

Purpose: keep the current work visible across chats, agents, and local sessions.
**引き継ぎドキュメントとして機能する — セッション開始時に必ず読むこと。**

---

## 🔖 FT ループ引き継ぎ状態（毎 5FT 更新）

| 項目 | 値 |
|------|-----|
| 最終完了 FT | **FT290** (`NENE2-FT/otplog` — OTP 認証システム ATK) |
| 現在の VERSION | **1.5.261** |
| 次の FT | **FT291** |
| 次の ATK 回 | **FT292**（4件ごと: 272 ✅, 276 ✅, 280 ✅, 284 ✅, 288 ✅, 292） |
| 次の VULN 回 | **FT291**（6件ごと: 273 ✅, 279 ✅, 285 ✅, 291） |
| 進行中ブランチ | なし（main クリーン） |

---

## 最近完了した FT（直近 10 件）

| FT | タイプ | howto | VERSION |
|----|--------|-------|---------|
| FT281 | 通常 | `refresh-token-pattern.md` 新規 | 1.5.252 |
| FT282 | 通常 | `delegated-access-grants.md` 更新 | 1.5.253 |
| FT283 | 通常 | `invitation-system.md` 新規 | 1.5.254 |
| FT284 | ATK | `rate-limiting.md` 更新 | 1.5.255 |
| FT285 | VULN | `password-reset-flow.md` 新規 | 1.5.256 |
| FT286 | 通常 | `timezone-aware-scheduling.md` 新規 | 1.5.257 |
| FT287 | 通常 | `waitlist-system.md` 新規 | 1.5.258 |
| FT288 | ATK | `distributed-lock.md` 新規 | 1.5.259 |
| FT289 | 通常 | `content-reporting.md` 新規 | 1.5.260 |
| FT290 | ATK | `otp-authentication.md` 更新 | 1.5.261 |

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
