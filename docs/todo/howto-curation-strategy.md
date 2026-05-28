# HOWTO 資産化・検索性向上の方針

Date: 2026-05-29 / Issue: #1323

`docs/howto/` が 256 件まで成長した結果、手動キュレーション索引が追いつかなくなった。本ドキュメントは、howto を「ストック資産」として持続的に検索・発見可能にするための方針案を記録する。

着手判断はまだ。次セッションで本ドキュメントを読んだ上で Phase A から始めるかを決める。

---

## 1. 現状診断（2026-05-29 時点）

| 項目 | 状態 |
|---|---|
| howto ファイル総数 | **256** 件（en/ja/fr/zh/de/pt-br 各ロケール） |
| `docs/howto/README.md` のカテゴリ表に載っているもの | **97** 件 |
| **インデックスから漏れている** | **159 件（62%）** |
| 「I want to…」task-finder 表 | 30 件（よく機能している） |
| 各 howto の YAML frontmatter | **なし** |
| VitePress search | 有効（local provider） |
| 多言語 README | en/ja は手動同期（189 行）、fr/zh/de/pt-br は未追従 |

`README.md` のカテゴリ別カウント:

| カテゴリ | 件数 |
|---|---|
| Getting Started | 6 |
| Authentication & Authorization | 11 |
| Security | 8 |
| Database | 8 |
| API Design | 12 |
| Background & Infrastructure | 10 |
| Product Features (Recipe Patterns) | 42 |
| **合計（カテゴリ網羅分）** | **97** |

これは **手動 README が構造的に追いつけない** ことを示している。
怠慢ではなくスケールの問題。今後 howto が増えるたびに同じ乖離が起きる。

---

## 2. ユーザーの 3 つの発見モード

「howto を見つけたい」モードは 3 つあり、最適解が異なる:

| モード | ユーザー視点 | 必要なもの | 現状 |
|---|---|---|---|
| **タスク逆引き** | 「タグでフィルタしたい」 | 手で選んだ "I want to…" 表 | ✅ 機能している |
| **キーワード検索** | 「JWT refresh」 | VitePress local search | ✅ 機能している |
| **分類ブラウズ** | 「Security 系を全部見たい」 | カテゴリ別の網羅的インデックス | ❌ **壊れている** |

直すべきは ③ の分類ブラウズだけ。
①②は既に機能しているので触らない。

---

## 3. 提案: 索引はデータから自動生成する

### Phase A — 即効性（1 PR、半日想定）

**全 256 件のフラット索引を自動生成して README に追記する。**

```bash
tools/build-howto-index.php
# 各 .md の H1 を読んで、英数順の表として出力する
# 既存の "I want to..." と分類表は手動のまま残す
```

成果物:
- `tools/build-howto-index.php` 新規（PHP スクリプト、既存 tools/ 流儀に揃える）
- README の末尾に「Full Index (auto-generated)」セクションを追記
- CI で `composer howto:index && git diff --exit-code` を走らせて lock する
- 6 ロケール分（README は各ロケールに存在）も同じ仕組みで生成

これだけで **その日のうちに 159 件の孤児が見える化** する。
プレースホルダーではなく、リンク + タイトル付きの実用的な索引。

### Phase B — 持続的に正しくする（複数 PR、Agent 並列で 1-2 セッション）

各 howto に **最小限の YAML frontmatter** を追加する。

```yaml
---
title: Add JWT Authentication
category: auth          # 単一の primary category
tags: [jwt, bearer, middleware]
difficulty: intermediate
ft: FT102               # 任意：FT registry と相互参照
related: [use-bearer-auth, refresh-token-rotation]
---
```

スキーマは **5 フィールドに絞る**。リッチすぎると埋まらない。

256 件への annotate は Agent 並列でおおよそ数時間で完走できる規模（既に
ja 翻訳 10 件を 2 Agent で完走した実績がある）。

Phase B 完了時の生成物:
- `docs/howto/README.md` — カテゴリ別（frontmatter から再生成）
- `docs/howto/by-tag.md` — タグ別ブラウズ
- 既存の "I want to…" 表は **手動のまま残す**（人間の判断が必要なキュレーション）
- CI で「新規 howto に frontmatter が無いと fail」を強制 → 二度と腐らない

### やらない方が良い選択肢

| 選択肢 | 却下理由 |
|---|---|
| Algolia / 外部 search portal | VitePress local search で十分。複雑さに見合わない |
| AI 埋め込み + ベクトル検索 | docs サイトの静的シンプルさを破る、SaaS 依存が増える |
| カテゴリ体系の大改装 | 既存 8 カテゴリは妥当。網羅性だけが問題 |
| 手動で 159 件を README にコピペ | 同じ問題が再発する未来の負債 |

---

## 4. Diataxis との整合

`docs/howto/` は Diataxis 4 象限のうち **task-focused goal-oriented** に位置する。

| 索引軸 | 位置づけ |
|---|---|
| **主軸**: task / category / tag | howto の本質 — 何をやるか |
| **副次**: difficulty / FT 参照 | 補助情報 — 誰向けか・どの実装に対応するか |

tutorial / explanation / reference は別象限なので混ぜない。
本提案は howto 内で閉じる。

---

## 5. 推奨着手順

1. **Phase A だけまず実装** — `tools/build-howto-index.php` を書いて README 追記、1 PR で完結させる
2. Phase A 完了後、frontmatter スキーマを 5 件で実証（PR で前例を作る）
3. 実証 OK なら Agent 並列で 256 件を annotate、build tool を整備して Phase B 完了
4. CI lock を入れて鮮度を恒久維持

各ステップは独立した PR にできるので、途中で方針変更も容易。

---

## 6. 関連

- 既存の `docs/howto/README.md` — 現状のキュレーション
- `docs/ft-registry.md` — FT 番号 → ディレクトリ名 → howto の対応表
- `.vitepress/config.mts` — howto 動的サイドバーは既に実装済み (#1302)
- VitePress local search 設定 — そのまま活用、変更不要

## 7. 関連 Issue / 状態

| 種別 | 番号 | 状態 |
|---|---|---|
| 本ドキュメント発行 | #1323 | 着手前 |
| 後続 (Phase A 実装) | 未起票 | — |
| 後続 (Phase B 実装) | 未起票 | — |
