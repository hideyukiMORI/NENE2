# Field Trial 142 — コンテンツ下書き管理（Draft → Published ライフサイクル）

**Date**: 2026-05-21  
**App**: `draftlog`  
**Path**: `/home/xi/docker/NENE2-FT/draftlog/`  
**NENE2 version**: ^1.5  
**Release**: v1.5.76  
**Special**: なし（通常 FT）

---

## What was built

記事の下書き・公開・アーカイブのステータス遷移を管理する CMS 基本機能を実装した。

| Endpoint | 説明 |
|---|---|
| `POST /articles` | 記事作成（常に draft で開始） |
| `GET /articles` | 公開中記事一覧のみ |
| `GET /articles/{id}` | 記事詳細（作者は全ステータス・他者は published のみ） |
| `PUT /articles/{id}` | 記事編集（draft のみ・作者のみ） |
| `POST /articles/{id}/publish` | draft → published（作者のみ） |
| `POST /articles/{id}/archive` | published → archived（作者のみ） |

---

## Architecture decisions

### ArticleStatus enum でステータス遷移ガード

```php
enum ArticleStatus: string {
    public function canEdit(): bool    { return $this === self::Draft; }
    public function canPublish(): bool { return $this === self::Draft; }
    public function canArchive(): bool { return $this === self::Published; }
}
```

ハンドラーはガードメソッドを呼び出し、遷移が不正な場合は 422 を返す。逆方向遷移（published → draft）は不可。

### 非公開記事は 404 で隠す

ドラフトを作者以外が読もうとした場合、存在を明かさないために 404 を返す（403 ではない）。403 はリソースの存在を認める。

### 同秒ソートの安定性

`ORDER BY published_at DESC, id DESC` により、同秒に公開された複数記事の表示順が決定論的になる。

### テストで1件失敗 → 修正

最初の実装で `ORDER BY published_at DESC` のみだったため、同秒に公開した2記事の順序が不定でテストが失敗した。`id DESC` をセカンダリソートに追加して解決。

---

## Test results

| Suite | Tests | Result |
|---|---|---|
| `DraftTest.php` | 20 | Pass |
| **Total** | **20** | **Pass** |

---

## Developer Experience (DX) Review

### Persona 1 — 初心者 Web 開発者（PHP 学習中）

「ブログの下書き→公開のフローは身近でわかりやすかった。enum にガードメソッドを持たせるパターンが前の FT（グループメンバーシップ）でも出てきたので、今回は自然に理解できた。draft を読もうとしたら 403 ではなく 404 が返ってくる設計は、最初「なぜ?」と思ったが、存在を知らせないためという説明で納得した。ソートの安定性の問題は自分では気づかなかったと思う。」

★★★★☆ — ガードパターンの再利用で理解が深まる

### Persona 2 — Laravel 経験者（NENE2 初学）

「Laravel だと `Publishable` トレイトや `$casts = ['status' => 'App\Enums\Status']` でステータス管理できる。NENE2 では手動で enum を使う必要があるが、コードが明示的で追いやすい。`canPublish()` / `canArchive()` のメソッドはシンプルで、業務ロジックとしてどこに何を書けばいいかが明確。同秒ソートのバグは本番でも起きやすいので、テストで発見できて良かった。」

★★★★☆ — 明示的な設計で理解しやすい。同秒バグの発見は実用的

### Persona 3 — セキュリティエンジニア

「`GET /articles/{id}` で非公開記事を 404 で返す設計は正しい（存在漏洩防止）。作者所有権チェックが publish / archive / update の全アクションで一貫して実装されている。ステータス遷移の一方向性（逆遷移なし）はビジネスルールとして適切。VulnTest がないが、通常 FT なので問題なし。次の FT144 で脆弱性診断が入る予定。」

★★★★☆ — セキュリティ設計が適切。次回の脆弱性診断に期待

### Persona 4 — フロントエンド開発者（API 利用者）

「`GET /articles` が公開記事のみを返すのはフロント側で最も使いやすい設計。`published_at` が一覧に含まれているので新着順ソートができる。`POST /articles/{id}/publish` と `POST /articles/{id}/archive` が独立したエンドポイントなので、ボタンとの対応が明確。ステータスが記事取得レスポンスに含まれているので UI の表示切り替えも簡単。」

★★★★★ — API 設計がフロント要件に合っている

### Persona 5 — インフラ・DevOps エンジニア

「`status` カラムに CHECK 制約があるのでデータ整合性が保証されている。`published_at` と `archived_at` が nullable なのは正しい設計（未遷移は NULL）。`ORDER BY published_at DESC, id DESC` の複合ソートはインデックスを張れば効率的。`updated_at` を毎トランジションで更新しているのは監査として有用。テストが 20 件で主要フローをカバーしている。」

★★★★☆ — DB 設計が堅牢。本番化しやすい

### Persona 6 — プロダクトマネージャー

「CMS の基本フロー（下書き→公開→アーカイブ）が完全に実装されている。一方向遷移（逆にできない）はコンテンツ管理のベストプラクティスに沿っている。読者からは公開記事のみ見えるのは当然の設計。作者は自分の下書きを確認できるのも重要。今後の拡張として、公開スケジュール予約（scheduled ステータス）・複数著者のコラボ・タグ付けなどが考えられる。」

★★★★☆ — CMS として使えるレベルの完成度

---

## Howto

`docs/howto/content-draft-lifecycle.md`
