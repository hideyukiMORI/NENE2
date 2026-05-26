# Current TODO

Purpose: keep the current work visible across chats, agents, and local sessions.
**引き継ぎドキュメントとして機能する — セッション開始時に必ず読むこと。**

---

## 🔖 FT ループ引き継ぎ状態（毎 5FT 更新）

| 項目 | 値 |
|------|-----|
| 最終完了 FT | **FT269** (`NENE2-FT/cartlog` — ショッピングカート API) |
| 現在の VERSION | **1.5.240** |
| 次の FT | **FT270** |
| 次の ATK 回 | **FT272**（4件ごと: FT252, 256, 260, 264, 268 ✅） |
| 次の VULN 回 | **FT273**（6件ごと: FT249, 255, 261, 267 ✅） |
| 進行中ブランチ | なし（main クリーン） |

---

## 最近完了した FT（直近 10 件）

| FT | タイプ | howto | VERSION |
|----|--------|-------|---------|
| FT260 | ATK | `webhook-signature-verification.md` | 1.5.231 |
| FT261 | VULN | `jwt-authentication.md` 更新 | 1.5.232 |
| FT262 | 通常 | `multi-currency-money-ledger.md` | 1.5.233 |
| FT263 | 通常 | `emoji-reactions-toggle.md` | 1.5.234 |
| FT264 | ATK | `sql-injection-defence.md` | 1.5.235 |
| FT265 | 通常 | `url-bookmark-api.md` | 1.5.236 |
| FT266 | 通常 | `api-key-management.md` 更新 | 1.5.237 |
| FT267 | VULN | `encrypted-field-storage.md` 更新 | 1.5.238 |
| FT268 | ATK | `audit-trail.md` 更新 | 1.5.239 |
| FT269 | 通常 | `shopping-cart-api.md` | 1.5.240 |

---

## 次のアクション

FT270 から通常フローで継続。

```bash
# 未カバー FT を確認
bash tools/uncovered-fts.sh

# FT を選んだらバージョンバンプ
bash tools/bump-ft.sh 1.5.241

# CHANGELOG.md に手動追記してから
docker compose run --rm app composer check
```

---

## 進行中の検討事項

| 項目 | 状態 |
|------|------|
| src/ 還元 batch 2（JSON ボディ整数バリデーター等） | 📋 候補 |
| v2.0 設計検討（FT ループ摩擦点の還元） | 📋 候補 |

---

## Operating Notes

- このファイルは **5FT ごと**または**セッション終了時**に更新する。
- FT の全履歴は `docs/milestones/` と `docs/roadmap.md` に保管する。
- main がクリーンな状態でセッションを終えること。
