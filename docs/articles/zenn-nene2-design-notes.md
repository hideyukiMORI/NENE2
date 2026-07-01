---
title: "小さな業務OSSを作り続けるための PHP API フレームワーク — NENE2 の設計メモ"
emoji: "🧭"
type: "tech"
topics: ["php", "api", "openapi", "mcp", "設計"]
published: false
---

## はじめに

この記事は、NENE2 のチュートリアルではありません。

NeNe シリーズという小さな業務OSS群を作る中で、なぜ **NENE2** という PHP フレームワークを自作しているのか、その設計メモです。

NENE2 は Laravel や Symfony の代替を目指すものではありません。

大きなフルスタックフレームワークを置き換えたいわけでもありません。

むしろ、目的はかなり狭いです。

**小さな業務APIを、同じ形で、読みやすく、安全に、AIツールからも扱いやすく作り続けるための土台**です。

Repository:

https://github.com/hideyukiMORI/NENE2

---

## 背景: プロダクトが増えるほど、形が必要になる

最近、NeNe シリーズとしていくつかの self-hosted な業務OSSを作っています。

たとえば:

- **NeNe Invoice** — 見積・請求・入金管理
- **NeNe Vault** — 受領書類アーカイブ
- **NeNe Deal** — B2B 商談パイプライン
- **NeNe Contact** — 埋め込み問い合わせフォーム
- **NeNe Records** — 型付き CMS / 業務データ管理
- **NeNe Clear** — 入金消込・督促管理

それぞれの業務領域は違います。

でも、作り続けていると、毎回同じ問いに戻ってきます。

- API の境界はどこか
- 管理 UI はどこまでクライアントで、どこからがサーバの責務か
- エラーの形はどう揃えるか
- OpenAPI と実装をどうずらさないか
- AI / MCP ツールは何を触ってよいのか
- DB を直接触らせずに、業務ルールをどう守るか
- 1人または小さなチームで、後から読める構造にできるか

この「毎回出てくる問い」に対して、毎回ゼロから答えるのはつらいです。

そこで、NENE2 では、業務アプリを作るときの基本形を小さく固定しています。

---

## NENE2 は何を目指しているか

NENE2 の方向性は、ひとことで言うとこうです。

> JSON API を中心にした、小さく明示的な PHP アプリケーション基盤。

もう少し分解すると、次のような方針です。

- API-first
- OpenAPI を契約として扱う
- HTML は薄く、置き換えやすくする
- React / TypeScript は optional なスターターに留める
- Handler → UseCase → Repository のように責務を見える形で分ける
- Problem Details でエラー形状を揃える
- MCP は DB 直結ではなく、アプリケーション境界の後ろに置く
- AI に読みやすい構造は、人間にも読みやすい構造にする

現在の NENE2 は `v1.5.332` で、認証、DB 境界、OpenAPI、MCP、React starter、260 本の howto などを持っています。

ただし、ここで大事なのは機能数ではありません。

「どのプロダクトでも同じ考え方で作れる」ことです。

---

## Laravel ではなく NENE2 を使う理由

誤解されやすいので先に書くと、Laravel や Symfony が悪いという話ではありません。

大きなエコシステム、豊富なパッケージ、採用しやすい人材、ドキュメント量。これらは大きな価値です。

一方で、私が NeNe シリーズで作っているものは、次のような性格を持っています。

- 小さな業務単位ごとの self-hosted OSS
- 日本の業務文脈に寄ったアプリ
- API と OpenAPI を最初から見せたい
- MCP / AI agent の境界を明示したい
- 1リポジトリを読み切れるサイズに保ちたい
- フレームワークの魔法より、責務の見えやすさを優先したい

この場合、巨大なフルスタックの便利さよりも、**「何がどこにあるかすぐ分かる」** ことを優先したい場面があります。

NENE2 は、そこに合わせた小さな土台です。

---

## API-first: 管理画面も AI も API のクライアント

NENE2 では、API を中心に置きます。

管理画面も、公開ページも、外部連携も、MCP ツールも、基本的には API のクライアントです。

```text
Admin UI
Public page
External integration
AI / MCP tool
        ↓
 documented HTTP API
        ↓
 Handler
        ↓
 UseCase
        ↓
 Repository / Adapter
```

この形にしておくと、境界が見えやすくなります。

フロントエンドだけ特別な裏口を通る。

AI だけ DB に直接 SQL を投げる。

管理画面だけ別の内部メソッドで状態を書き換える。

そういう経路が増えると、便利な反面、どこで業務ルールが守られているのか分かりにくくなります。

NENE2 では、なるべく同じ HTTP/API 境界を通します。

---

## OpenAPI は人間とAIの共通言語

NENE2 では OpenAPI を「あとで作るドキュメント」ではなく、API の契約として扱います。

これは人間にとって便利です。

- endpoint が分かる
- request / response の形が分かる
- error response が分かる
- API client やテストに使える

でも、AI agent にとっても重要です。

AI に何かを操作させるとき、曖昧な自然言語だけでは危険です。

「この操作はどの endpoint で、どの field を受け取り、どの権限が必要で、失敗時にどう返るのか」

それを OpenAPI に寄せておくと、MCP tool catalog や自動生成ツールの土台にしやすくなります。

NENE2 の考え方はこうです。

> AI が推測で DB 構造を触るのではなく、OpenAPI で定義されたアプリケーション能力を呼ぶ。

---

## MCP は DB 直結にしない

MCP は便利です。

AI ツールがアプリケーションの機能を呼び出すための、分かりやすい入口になります。

ただし、使い方を間違えると、きれいな形をした危険な裏口にもなります。

避けたい形はこれです。

```text
AI Agent
  -> MCP Tool
  -> direct SQL
  -> production database
```

この形だと、アプリケーションが持っているはずのルールを飛ばしてしまいます。

- validation
- authorization
- tenant isolation
- audit logging
- transaction boundary
- Problem Details error response
- request id
- rate limit
- business workflow state

これらは DB だけでは分かりません。

アプリケーションが知っているものです。

だから、NENE2 ではこうします。

```text
AI Agent
  -> MCP Tool
  -> documented HTTP API
  -> Handler
  -> UseCase
  -> Repository / transaction boundary
```

これは遠回りに見えるかもしれません。

でも、お金、認証、個人情報、監査ログが絡む業務アプリでは、この遠回りこそが大事だと思っています。

---

## あえて退屈な構造にする

NENE2 のコード構造は、意図的に派手ではありません。

典型的には、次のような形です。

```text
Handler
  -> UseCase
  -> RepositoryInterface
  -> PdoRepository
```

Handler は HTTP 入力を解釈します。

UseCase は業務ルールを扱います。

Repository は永続化の詳細を隠します。

OpenAPI は外から見える契約を説明します。

テストは UseCase、HTTP、Repository の境界で書きます。

この構造は新しくありません。

むしろ、とても退屈です。

でも、NeNe シリーズのように複数の小さなプロダクトを作る場合、この退屈さが効きます。

新しいプロダクトを作っても、読む場所がだいたい同じになるからです。

---

## 「AI-readable」は、AI専用という意味ではない

NENE2 では、AI-readable という言葉を使っています。

ただし、これは AI だけのためにコードを書くという意味ではありません。

AI が読めるコードは、だいたい人間にも読みやすいです。

逆に、人間が読んでも責務が追えないコードは、AI にとっても危ういです。

NENE2 で大事にしているのは、次のようなことです。

- ディレクトリ名で責務が分かる
- クラス名で役割が分かる
- UseCase が HTTP や DB に依存しすぎない
- public API の shape が OpenAPI に出ている
- エラーが Problem Details として揃っている
- 重要な設計判断が ADR や docs に残っている
- MCP がアプリケーション境界を迂回しない

これは「AI のための特殊対応」というより、普通に良い保守性の話です。

AI 時代になって、その重要性がより見えやすくなっただけだと思っています。

---

## NeNe シリーズでどう使っているか

NENE2 は単体で完結する実験ではありません。

実際に、NeNe シリーズのプロダクトで使いながら育てています。

たとえば:

- Invoice では、請求書・入金・PDF・管理 UI
- Vault では、受領書類の保存、検索、監査ログ
- Records では、型付きエンティティ、公開ページ、OpenAPI / MCP
- Clear では、入金消込、督促、監査証跡

それぞれ業務は違います。

でも、API-first、OpenAPI、Handler → UseCase → Repository、MCP 境界という考え方は共通です。

つまり、NENE2 は「フレームワークを作りたかったから作ったもの」ではなく、**複数の業務OSSを同じ設計言語で作り続けるために必要になったもの**です。

---

## まだ完成品ではない

NENE2 も NeNe シリーズも、まだ磨き込み中です。

全部の配布経路が完成しているわけではありません。

すべての API が安定しているわけでもありません。

大きなフレームワークのようなエコシステムもありません。

ただ、方向性はかなりはっきりしています。

- 小さい
- 明示的
- API-first
- OpenAPI を契約にする
- MCP はアプリケーション境界の後ろに置く
- 日本の小さな業務OSSで実際に試す

この方針を、実プロダクトを作りながら確認しています。

---

## まとめ

NENE2 は、Laravel や Symfony の代替を目指す PHP フレームワークではありません。

小さな業務APIを、同じ設計思想で作り続けるための土台です。

大事にしているのは、次のようなことです。

- API-first
- OpenAPI contract
- Problem Details
- thin HTML
- optional frontend starter
- Handler → UseCase → Repository
- AI / MCP は DB 直結ではなく API 境界へ
- 人間にも AI にも読みやすい構造

NeNe シリーズを作る中で、NENE2 は少しずつ「自分用の小さな道具」から、「複数の業務OSSを同じ形で育てるための基盤」になってきました。

派手なフレームワークではありません。

でも、こういう退屈で明示的な土台があるから、小さな業務ツールを安心して増やせるのだと思っています。

---

## リンク

| 種類 | URL |
| --- | --- |
| NENE2 | <https://github.com/hideyukiMORI/NENE2> |
| OpenAPI | <https://github.com/hideyukiMORI/NENE2/blob/main/docs/openapi/openapi.yaml> |
| NENE2 howto | <https://github.com/hideyukiMORI/NENE2/tree/main/docs/howto> |
| NeNe series overview | <https://dev.to/hideyukimori/i-am-building-self-hosted-business-tools-for-small-teams-in-japan-4i26> |
| MCP boundary article | <https://dev.to/hideyukimori/mcp-should-not-mean-letting-ai-touch-your-database-57p1> |
