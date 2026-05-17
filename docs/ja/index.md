---
layout: home

hero:
  name: "NENE2"
  text: "ミニマル PHP API フレームワーク"
  tagline: JSON API を素早く構築。OpenAPI と MCP を標準搭載。最初から AI 対応。
  actions:
    - theme: brand
      text: はじめる →
      link: /ja/tutorial/first-api
    - theme: alt
      text: GitHub で見る
      link: https://github.com/hideyukiMORI/NENE2
    - theme: alt
      text: Packagist
      link: https://packagist.org/packages/hideyukimori/nene2

features:
  - icon: 🚀
    title: 数分で起動
    details: composer require hideyukimori/nene2 を実行するだけで、ヘルスチェック・リクエスト ID・Problem Details エラーを備えた JSON API がすぐに動きます。ルートを一行も書く前から。

  - icon: 📄
    title: OpenAPI ファースト
    details: 作成したエンドポイントはすべて OpenAPI コントラクトを持ちます。Swagger UI を同梱。コントラクトはクライアントに渡すものであり、後付けではありません。

  - icon: 🤖
    title: MCP 対応
    details: ローカル MCP サーバーが API をツールとして公開し、AI エージェント（Claude・Cursor）が直接呼び出せます。OpenAPI カタログを読み取るだけで、特別な統合作業は不要です。

  - icon: 🛡️
    title: RFC 9457 エラー
    details: すべてのエラーレスポンスは Problem Details オブジェクトです。type・title・status・detail を含む機械可読な JSON 構造体。本番環境で生の例外は返しません。

  - icon: 🧱
    title: クリーンアーキテクチャ
    details: UseCase → RepositoryInterface → PDO アダプター。各レイヤーを独立してテスト可能。マジックなし、隠しワイヤリングなし、ドメインへのフレームワーク侵食なし。

  - icon: 🔬
    title: PHPStan レベル 8
    details: 最高レベルの静的解析。PHPStan を通れば、実行時に驚かされません。PHPUnit・PHP-CS-Fixer とすぐに連携して動きます。
---
