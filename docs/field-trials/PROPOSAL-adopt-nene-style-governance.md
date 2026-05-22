# Proposal: NeNe 流 Field Trial ガバナンスの導入

> **ステータス**: 提案 (Proposal) — 採否と作業は NENE2 のメンテナに委ねる
> **提案元**: 姉妹リポジトリ `hideyukiMORI/NeNe` のメンテナンス文脈
> **日付**: 2026-05-22

## 背景

NENE2 と nene2-python は「フィールドトライアル (FT)」という実践を最初に確立した姉妹プロジェクトであり、合計 150 トライアル弱の実績がある。NENE2 単独でも `docs/field-trials/2026-05-field-trial-1.md` から `2026-05-field-trial-70.md` まで 70 本超のレポートが蓄積されている。

一方で **FT を回す上での規律（ガバナンス）は文書化されていない**。何を friction として記録するか、どう Issue 化するか、次のトライアルをいつ始めて良いかといった判断は、暗黙のうちにメンテナの頭にだけ存在する。

`hideyukiMORI/NeNe` (PHP renovation 系) は FT の発想を NENE2 / nene2-python から借り、その上に**形式化レイヤ**を載せた。具体的には ADR、ループ規律 (loop cadence)、friction kind / decision の分類、クローン分離規律 (`../*-FT/ft{N}-{topic}/`)、ブートストラップスクリプトを揃え、過去 1 週間で FT1〜FT6 を回した。

本提案は、NeNe で確立したこの形式化レイヤを NENE2 に**逆輸入**することを推奨するものである。形式化が役立つ局面と、NENE2 固有の事情で再現が難しい局面の両方を明示する。

## 現状の差分

NeNe には揃っているが NENE2 にはまだ無いもの:

| 資材 | NeNe での所在 | NENE2 の現状 |
|---|---|---|
| FT 方針 ADR | `docs/adr/0002-adopt-field-trial-methodology.md` (53 行) | 無し (ADR-0006 で間接言及のみ) |
| FT README (運用ガイド) | `docs/field-trials/README.md` (132 行) | 無し |
| Friction 分類タクソノミー | 同 README L73–94 (`docs-gap` / `feature-gap` / `design-trade-off` / `legacy-preserved` / `process-gap`) | 無し |
| Decision 分類 | 同 README L85–94 (`fix-in-framework` / `document` / `keep-legacy` / `defer`) | 無し |
| クローン分離規律 | 同 README L18–50 (`../NeNe-FT/ft{N}-{topic}/`、独立ワーキングツリー、ポートオフセット) | 無し |
| ブートストラップスクリプト | `tools/nene-ft-new.sh` (254 行) | 無し |
| レポートテンプレート | `docs/templates/field-trial-report.md` (95 行) | **既に存在 ✅** `docs/templates/field-trial-report.md` |
| ループ規律 | ADR-0002 L34「次のトライアルは前のトライアルの Issue が全て閉じるまで開始しない」 | 暗黙 |

レポートテンプレートは NENE2 にも既にあるため、再導入の必要はない。

## なぜ形式化が役に立つか

NeNe で FT を 6 本走らせて確認できた効果:

1. **ループ規律により finding が「次のトライアル」に混入しなくなる**。FT4 の friction を FT5 で再発見してしまう事故が防げる。
2. **Friction kind により「修正すべきもの」と「文書化すべきもの」が分離される**。特に `legacy-preserved` (NeNe 特有の「意図的に残した legacy」) は redesign 衝動を抑える。NENE2 にも同種の判断は発生しているはずだが、明示されていないため毎回再判断になっている。
3. **ブートストラップスクリプトでトライアル開始コストがほぼゼロになる**。ポート競合、`.claude` 設定、PLAN.md skeleton までワンコマンドで揃う。FT を 70 本回した NENE2 ではこの効果が累積している可能性が高い。
4. **ADR で記録すると、姉妹プロジェクトに引用できる**。事実 NeNe ADR-0002 は NENE2 / nene2-python の実践を引用してから自分の決定を述べている。NENE2 にも同等の ADR があれば、外部プロジェクト (例: クライアント案件) で NENE2 流の FT を提案する根拠になる。

## 推奨する導入順 (NENE2 メンテナへの依頼)

採否含めて NENE2 メンテナに委ねるが、もし採用する場合の現実的な順序を提案する:

1. **ADR-0012 (Adopt Field Trial Methodology)** を起こす。NeNe `docs/adr/0002-adopt-field-trial-methodology.md` を雛形に、NENE2 の文脈で書き直す。特に「Context」節は NENE2 が FT を**先に**始めた事実を反映させること (NeNe は 2026-05-20 開始、NENE2 は遥かに先行)。
2. **`docs/field-trials/README.md`** を作る。NeNe の README をベースに、PHP/Composer/Phinx 文脈で言い換える。Friction 分類はそのまま使えるが、`legacy-preserved` は NeNe 固有 (renovation スタンス由来) なので、NENE2 でも採用するか別の kind に置き換えるかは判断の余地がある。
3. **`tools/nene2-ft-new.sh`** を作る。NeNe `tools/nene-ft-new.sh` を Composer / `compose.yaml` の port 構造に合わせて移植する。`8080+N` / `3307+N` のオフセット規約は NENE2 の compose.yaml の既定ポートに合わせて調整。
4. レポートテンプレートは既存のまま (`docs/templates/field-trial-report.md`)。必要なら friction summary table のセクションを足す程度の小改修。

## 留意点 / トレードオフ

- **NENE2 固有の事情**: NENE2 は Packagist 公開を控えた段階で「FT を release gate にする」性質が ADR-0006 で示されている。NeNe は逆に「continuous quality gate」として位置付けている。同じガバナンスを移植するにしても、目的が異なる点は ADR の「Decision」節で明確に書き分けるべき。
- **過去 70 本の遡及適用は不要**: 過去レポートにタクソノミーを後付けすることに意味は薄い。新規トライアル (FT-71 以降) から適用すれば十分。
- **nene2-python との関係**: 現在 nene2-python は CLAUDE.md で FT 方法論を `../NENE2/docs/field-trials/` に委譲している。NENE2 側でガバナンスを明文化すれば、nene2-python はそれを参照するか自前で持つかを選べるようになる (本提案と並行して nene2-python 側にも同様の提案 PR を投げている)。
- **新規 ADR の重み**: ADR-0012 は「コード変更を伴わない process ADR」になる。NENE2 の ADR 集 (`docs/adr/`) は技術選定中心 (Phinx 採用、JWT 認証、rate limiting 等) なので、process ADR を入れる際は ADR-0011 (security review policy) の前例を参考にできる。

## 作業のオーナーシップ

本提案は方針提示までに留め、**実装作業と受け入れ判断は NENE2 のメンテナ側で行うことを想定している**。NeNe 側で持ち込み PR を出すことはしない (NeNe で確立したコンテキストを NENE2 で再構築する負荷の方が大きい)。

NENE2 で実際に着手する場合は、本ファイルを起点に Issue 化 (例: 「ADR-0012 起草」「FT README 作成」「ft-new.sh 移植」を別 Issue) して進めるのが NENE2 の従来サイクルと整合する。

## 参考リンク (NeNe 側の参照点)

- ADR-0002: <https://github.com/hideyukiMORI/NeNe/blob/main/docs/adr/0002-adopt-field-trial-methodology.md>
- FT README (friction kind / decision の定義込み): <https://github.com/hideyukiMORI/NeNe/blob/main/docs/field-trials/README.md>
- ブートストラップスクリプト: <https://github.com/hideyukiMORI/NeNe/blob/main/tools/nene-ft-new.sh>
- 6 トライアル振り返り: <https://github.com/hideyukiMORI/NeNe/blob/main/docs/field-trials/2026-05-reflection-after-six-trials.md>
