# なぜ RFC 9457 Problem Details なのか？

NENE2 の API エラーは RFC 9457 Problem Details 形式を使用します。このページではその選択理由を説明します。

## Problem Details の見た目

```http
HTTP/1.1 422 Unprocessable Entity
Content-Type: application/problem+json

{
  "type": "https://nene2.dev/problems/validation-failed",
  "title": "Validation failed",
  "status": 422,
  "errors": [
    { "field": "title", "code": "required", "message": "Title is required." }
  ]
}
```

## 独自の形状でなく標準を使う理由

### 1. クライアントがエラーを汎用的に処理できる

RFC 9457 を知っているクライアントは、特定のアプリケーションを知らなくても、あらゆる RFC 9457 API からの `title` と `status` を表示できます。`errors` などのアプリケーション固有フィールドは付加的な拡張です — 汎用ハンドラーを壊さずに詳細を追加できます。

### 2. `Content-Type: application/problem+json` は機械可読

レスポンスが `application/problem+json` を持つ場合、クライアントはエラーオブジェクトを受け取ったとわかります。部分的な成功ではありません。この区別は、デシリアライズ前に Content-Type を検査する MCP ツールや他のマシンクライアントにとって重要です。

### 3. `type` URI がエラーに安定した識別子を与える

各問題タイプは `https://nene2.dev/problems/validation-failed` のような URI を持ちます。その URI は:

- **安定** — HTTP ステータスコードが別のエラーに再利用されても変わらない
- **ドキュメント化可能** — URI が人間可読なドキュメントを指せる
- **マッチング可能** — クライアントが `title` をパースするのでなく `type` 文字列で分岐できる

### 4. 公開された標準

RFC 9457（RFC 7807 の後継）は公開された IETF 標準です。これを使うことで、エラー形式はあらゆる API コンシューマーがドキュメントを必要とする独自の発明ではなくなります。

## トレードオフ

| メリット | コスト |
|---------|--------|
| 機械可読なエラータイプ | `type` URI スキームの決定が必要 |
| 安定したクライアントコントラクト | `{"error": "..."}` より冗長 |
| 付加的な拡張モデル | クライアントが基本標準を理解する必要がある |

## `nene2.dev` URI について

NENE2 の `type` URI は現在 `https://nene2.dev/problems/...` をプレースホルダードメインとして使用しています。プロジェクトが本番稼働する前に、デプロイ者は次のいずれかを行う必要があります。

- `nene2.dev` を登録し、問題ドキュメントをそこでホストする、または
- `ProblemDetailsResponseFactory` 内のベース URL をプロジェクト固有のドメインに置き換える

この決定は Phase 26（本番デプロイガイド）の一部として追跡されます。
