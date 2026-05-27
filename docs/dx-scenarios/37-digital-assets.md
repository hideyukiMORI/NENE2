# DX Scenario 37: デジタル資産管理

## アプリ概要

画像・動画・タグ・共有リンクを管理するデジタル資産管理 API。

| 機能 | エンドポイント例 |
|------|----------------|
| アセット登録 | `POST /assets`（file, title, description, type: image/video/document）|
| アセット一覧 | `GET /assets?type=image&tag=product&sort=created_at&page=1` |
| タグ管理 | `POST /assets/{id}/tags`, `DELETE /assets/{id}/tags/{tag_id}` |
| バリアント | `POST /assets/{id}/variants`（variant_type: thumbnail/preview, url）|
| 共有リンク | `POST /assets/{id}/share-links`（expires_in_hours, permissions: view/download）|
| フォルダ | `POST /folders`, `POST /folders/{id}/assets/{asset_id}` |
| 使用記録 | `POST /assets/{id}/usages`（context: page_id, usage_type）|
| ダウンロード | `GET /assets/{id}/download` |

ポイント: タグの多対多、バリアント（サムネイル等の派生ファイル）、フォルダ階層、使用箇所追跡。

---

## Persona A — 村岡 さつき（新卒・女性・24 歳）

### 背景

グラフィックデザイン専攻からエンジニアに転向。Canva や Adobe Stock を毎日使う。

### 作業シナリオ

1. `assets` テーブルに全情報を保存。
2. タグを `assets.tags TEXT`（コンマ区切り）で実装（パターンの繰り返し）。
3. 共有リンクのトークンを `md5(asset_id . time())` で生成。
4. フォルダ機能を省略（要件として複雑すぎると判断）。
5. 使用記録テーブルを設計したが「どう使うの？」という理解不足で省略。

### ハマりポイント

- **タグの N:M**: また同じ過ち。howto を先に読んでいれば防げた。
- **使用記録の意義**: CMS では「この画像はどのページで使われているか」の追跡が重要だが、理解できなかった。
- **バリアント設計**: サムネイル = 別ファイルとして `asset_variants` テーブルが必要な概念が分からない。

### 解決策 & 感想

レビューでタグの N:M と `asset_variants` テーブルの必要性を指摘された。

> 「タグをコンマ区切りにするのはもうしないって思ってたけどまたやってしまった。
>  よっぽど体で覚えるまで間違える。
>  アンチパターン howto を序文に置いてほしい。」

### DX スコア: ⭐⭐（2/5）

同じアンチパターンを繰り返した。howto の序文にアンチパターン警告が必要。

---

## Persona B — 岡本 雄一（ロースキル・男性・35 geq 歳）

### 背景

Web 制作会社のデザイナー兼エンジニア 10 年。Cloudinary / Imgix の利用経験あり。

### 作業シナリオ

1. テーブル設計:
   - `assets(id, user_id, folder_id, type, title, file_path, file_size, mime_type, created_at)`
   - `asset_tags(asset_id, tag_id)` UNIQUE(asset_id, tag_id)
   - `asset_variants(asset_id, variant_type, file_path, width, height, created_at)`
   - `share_links(id, asset_id, token, permissions, expires_at)`
   - `folders(id, parent_id, name, user_id)` — 自己参照で階層フォルダ（`hierarchical-data.md` 参照）
2. 共有リンクトークン: `bin2hex(random_bytes(32))`.
3. ファイルは `var/storage/assets/{user_id}/{year}/{month}/{filename}` に保存。
4. タグフィルタ検索: `EXISTS (SELECT 1 FROM asset_tags at JOIN tags t ON t.id=at.tag_id WHERE at.asset_id=a.id AND t.name=?)`.
5. 使用記録: `asset_usages(asset_id, context_type, context_id, used_at)` テーブル。

### ハマりポイント

- **`hierarchical-data.md`** のフォルダへの適用: materialized path はフォルダに完璧に使えた。
- **バリアントの自動生成**: 「アップロード時にサムネイルを自動生成」するには
  画像処理が必要（`gd` 拡張か `imagick`）。今回は手動で登録するエンドポイントのみ。
- **ファイルの論理削除**: `assets.deleted_at` で論理削除し、`var/storage/` の物理ファイルは
  別途クリーンアップバッチが必要（今回は省略）。

### 解決策 & 感想

`hierarchical-data.md` がフォルダ階層に直接活用できて良かった。

> 「hierarchical-data howto はフォルダ構造にも使えた。
>  汎用的なパターンで助かった。
>  バリアント自動生成は PHP の GD 拡張の話で NENE2 外の話だが、
>  どう統合するかの howto があれば参考になる。」

### DX スコア: ⭐⭐⭐（3/5）

実用的に完成。`hierarchical-data.md` が再活用できた。画像処理統合の howto が欲しい。

---

## Persona C — 奥田 俊介（ベテラン・男性・45 geq 歳）

### 背景

メディア系企業の DAM（Digital Asset Management）システム開発 18 年。

### 作業シナリオ

1. テーブル設計（DAM の標準）:
   - `assets(id, checksum_sha256, original_filename, file_path, status: active|archived|deleted)`
   - `asset_metadata(asset_id, key, value)` — EAV でメタデータを柔軟に管理
   - `asset_collections(id, name, parent_id, path)` — `hierarchical-data.md` の materialized path
   - `collection_assets(collection_id, asset_id, order_index)` UNIQUE(collection_id, asset_id)
2. `checksum_sha256` で重複アップロード検出（同じファイルは 1 件のみ保持）。
3. バリアント管理: `asset_variants(asset_id, variant_key, file_path, width, height, file_size)`
   `variant_key = 'thumb_200x200'` のような文字列で管理。
4. 共有リンクの権限: `permissions: view|download|embed` をビットフラグまたは JSON で管理。
5. 使用追跡: `asset_usages(asset_id, context_type, context_id, used_at)` + 使用中の asset は削除不可。

### ハマりポイント

- **EAV (Entity-Attribute-Value) のトレードオフ**: `asset_metadata` を EAV で持つと
  柔軟だが型安全性がない。EXIF データ等の任意メタデータには EAV が有効。
- **重複検出の `checksum_sha256`**: アップロード時に PHP で `hash_file('sha256', $path)` を計算。
  大きなファイルで時間がかかる。
- **使用中 asset の削除防止**: `asset_usages` にレコードがある場合は論理削除のみ許可。

### 解決策 & 感想

高品質で完成。EAV の活用と `hierarchical-data.md` の適用が重要だった。

> 「EAV パターンの howto はないが、DAM や CMS では頻出パターン。
>  チェックサム重複検出も storage 節約に重要。
>  how to に追加する価値がある。」

### DX スコア: ⭐⭐⭐⭐（4/5）

高品質完成。EAV パターンとチェックサム重複検出の howto が欲しい。

---

## まとめ & NENE2 DX 評価

| ペルソナ | 完成度 | スコア | 主なフリクション |
|---------|--------|--------|----------------|
| 村岡（新卒） | △ 同じアンチパターン繰り返し | 2/5 | タグ N:M・バリアント設計 |
| 岡本（ロースキル） | ○ `hierarchical-data.md` 活用 | 3/5 | 画像処理統合、論理削除クリーンアップ |
| 奥田（ベテラン） | ◎ 高品質完成 | 4/5 | EAV パターン、チェックサム重複検出 |

**共通のフリクション**:
1. **タグのアンチパターン警告** — howto の序文に「コンマ区切りはアンチパターン」を明記。
2. **`hierarchical-data.md` の汎用性** — フォルダ・カテゴリ・部署など多くの場所で活用されている。
3. **EAV パターン** — 任意メタデータを扱う CMS/DAM で頻出の設計パターン howto。
