# ハウツー: ファイル共有 API

> **FT リファレンス**: FT303 (`NENE2-FT/filelog`) — ファイル共有 API: プライベートファイルは非オーナーに 404（403 ではなく）を返す、オーナーのみの削除/可視性変更、ビュー共有 vs 編集共有の権限ティア、ボディの `user_id` は無視（オーナーシップはヘッダーから）、名前の長さ制限 255、サイズ `is_int()` 厳格、VULN-A〜L すべて SAFE、59 テスト / 82 アサーション PASS。

このガイドでは、ユーザーがファイルを所有し、可視性を制御し、ビューまたは編集レベルで他のユーザーとアクセスを共有するファイルメタデータ API の構築方法を示します。

## スキーマ

```sql
CREATE TABLE users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    created_at TEXT NOT NULL
);

CREATE TABLE files (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id     INTEGER NOT NULL,
    name        TEXT    NOT NULL,
    size        INTEGER NOT NULL DEFAULT 0 CHECK (size >= 0),
    mime_type   TEXT    NOT NULL,
    description TEXT,
    visibility  TEXT    NOT NULL DEFAULT 'private'
                        CHECK (visibility IN ('private', 'public')),
    created_at  TEXT    NOT NULL,
    updated_at  TEXT    NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

CREATE TABLE file_shares (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    file_id             INTEGER NOT NULL,
    shared_with_user_id INTEGER NOT NULL,
    can_edit            INTEGER NOT NULL DEFAULT 0 CHECK (can_edit IN (0, 1)),
    created_at          TEXT    NOT NULL,
    UNIQUE (file_id, shared_with_user_id),
    FOREIGN KEY (file_id) REFERENCES files(id),
    FOREIGN KEY (shared_with_user_id) REFERENCES users(id)
);
```

2 段階の共有: `can_edit = 0`（閲覧のみ）と `can_edit = 1`（編集アクセス）。`UNIQUE(file_id, shared_with_user_id)` は重複した共有エントリを防止します。

## エンドポイント

| メソッド | パス | 認証 | 説明 |
|--------|------|------|-------------|
| `POST` | `/files` | `X-User-Id` | ファイルメタデータをアップロードする |
| `GET` | `/files` | `X-User-Id` | 自分のファイルを一覧表示する |
| `GET` | `/files/{fileId}` | `X-User-Id` | ファイルを取得する（可視性チェック） |
| `PUT` | `/files/{fileId}` | `X-User-Id` | ファイルを更新する（オーナーまたは編集共有） |
| `DELETE` | `/files/{fileId}` | `X-User-Id` | ファイルを削除する（オーナーのみ） |
| `POST` | `/files/{fileId}/shares` | `X-User-Id`（オーナー） | 共有を追加する |
| `DELETE` | `/files/{fileId}/shares/{userId}` | `X-User-Id`（オーナー） | 共有を削除する |

## プライベートファイル → 404（403 ではなく）

```php
// 非オーナーはプライベートファイルを見られない — 404 で存在を隠す
if ($file['visibility'] === 'private') {
    $share = $this->repo->findShare($fileId, $userId);
    if ($share === null) {
        return $this->problems->create($request, 'not-found', 'File not found', 404);
    }
}
```

プライベートファイルは非オーナーおよび非共有者に 404 を返します。403 を返すとファイルが存在することが明かされます。パブリックファイルはすべての認証ユーザーに 200 を返します。

## ヘッダーからのオーナーシップ — ボディの user_id を無視

```php
$userId = $this->requireUserId($request);
// ... バリデーション ...
$id = $this->repo->create($userId, $name, $size, $mimeType, $description, $visibility, $now);
```

ファイルの `user_id` は常に `X-User-Id` ヘッダーから取得されます。リクエストボディの `user_id` はサイレントに無視されます。これにより所有権インジェクション攻撃（VULN-E）を防ぎます。

## ビュー共有 vs 編集共有 — 2 段階

```php
// オーナーは常に編集できる
$isOwner = ((int) $file['user_id']) === $userId;

if (!$isOwner) {
    $share = $this->repo->findShare($fileId, $userId);
    if ($share === null || !(bool) $share['can_edit']) {
        return $this->problems->create($request, 'forbidden', 'Edit access required', 403);
    }
}
```

- **オーナー**: すべての操作（読み取り、書き込み、削除、共有管理、可視性）
- **編集共有**（`can_edit=1`）: name/size/mime/description の更新可能 — ただし visibility は不可
- **ビュー共有**（`can_edit=0`）: 読み取りのみ — 書き込み試行はすべて 403

`visibility` を変更できるのはオーナーのみです:

```php
// オーナーのみが visibility を変更できる
if (!$isOwner && isset($body['visibility'])) {
    $visibility = (string) $file['visibility']; // リクエストをサイレントに無視
}
```

## 厳格な入力バリデーション

```php
$size = $body['size'] ?? null;
if (!is_int($size) || $size < 0) {
    $errors[] = ['field' => 'size', 'code' => 'invalid', 'message' => 'size must be a non-negative integer'];
}

if (!is_string($name) || strlen($name) > 255 || $name === '') {
    $errors[] = ['field' => 'name', 'code' => 'invalid', 'message' => 'name required, max 255 chars'];
}
```

- `size`: `is_int()` で `1.5` のような浮動小数点を拒否（VULN-I）
- `name`: 最大 255 文字 — 過大な入力によるクラッシュを防止（VULN-H）
- `visibility`: `in_array($value, ['private', 'public'], true)` 厳格な許可リスト

## 共有削除 — オーナーのみ

```php
// ファイルオーナーのみが共有を削除できる
if ((int) $file['user_id'] !== $userId) {
    return $this->problems->create($request, 'not-found', 'File not found', 404);
}
```

共有されたユーザーは自分自身を共有リストから削除できません — オーナーのみが共有を管理できます。非オーナーはファイルの存在を隠すために 404（403 ではなく）を受け取ります（VULN-F）。

## ユーザー ID バリデーション — ゼロと負の値を拒否

```php
$raw = $request->getHeaderLine('X-User-Id');
$userId = ctype_digit($raw) ? (int) $raw : 0;
if ($userId <= 0) {
    return $this->problems->create($request, 'unauthorized', 'Authentication required', 401);
}
```

`X-User-Id: 0` と `X-User-Id: -1` は 401 を返します（VULN-L）。正の整数のみが有効なユーザー ID です。

---

## 脆弱性アセスメント

### V-01 — IDOR: 他ユーザーのプライベートファイルにアクセス ✅ SAFE

**リスク**: ユーザー B がユーザー A のプライベートファイルを読む。
**判定**: SAFE — プライベートファイルは共有エントリのない非オーナーに 404 を返す。

---

### V-02 — IDOR: 他ユーザーのファイルを削除 ✅ SAFE

**リスク**: ユーザー B がユーザー A のファイルを削除する。
**判定**: SAFE — 削除はオーナーシップをチェック。非オーナーは 404 を受け取る。失敗後もファイルは存在する。

---

### V-03 — IDOR: 他ユーザーのファイルを更新 ✅ SAFE

**リスク**: ユーザー B がユーザー A のファイル名/メタデータを更新する。
**判定**: SAFE — 更新はオーナーシップをチェック。編集共有なしの非オーナーは 404 を受け取る。

---

### V-04 — 権限昇格: ビュー共有者が編集を試みる ✅ SAFE

**リスク**: 閲覧のみの共有を持つユーザーが PUT を呼び出してファイルを変更する。
**判定**: SAFE — 編集チェックが `can_edit = 1` を要求。ビュー共有は 403 を返す。

---

### V-05 — 所有権インジェクション: リクエストボディの user_id ✅ SAFE

**リスク**: `{ "user_id": 99, "name": "..." }` がファイルをユーザー 99 に割り当てる。
**判定**: SAFE — ボディの `user_id` はサイレントに無視される。所有権は常に `X-User-Id` ヘッダーから取得。

---

### V-06 — 非オーナーによる共有削除 ✅ SAFE

**リスク**: 共有されたユーザーが自分を共有リストから削除する。
**判定**: SAFE — 共有削除エンドポイントはファイルオーナーシップをチェック。非オーナーは 404 を返す。

---

### V-07 — 名前フィールドでの SQL インジェクション ✅ SAFE

**リスク**: `"name": "test'; DROP TABLE files; --"` がデータを破壊する。
**判定**: SAFE — パラメーター化されたクエリがインジェクション文字列をリテラルデータとして保存する。files テーブルは無傷。

---

### V-08 — 大きすぎる名前でクラッシュ ✅ SAFE

**リスク**: 300 文字の名前が DB エラーまたはメモリ枯渇を引き起こす。
**判定**: SAFE — `strlen($name) > 255` バリデーションが挿入前に 422 を返す。

---

### V-09 — 浮動小数点サイズの型混乱 ✅ SAFE

**リスク**: `"size": 1.5` がバリデーションを通過してサイズ追跡を破損する。
**判定**: SAFE — `is_int($size)` が浮動小数点を拒否 → 422。

---

### V-10 — 編集共有者が visibility をパブリックにエスカレーション ✅ SAFE

**リスク**: 編集共有ユーザーが `"visibility": "public"` を設定してプライベートファイルを公開する。
**判定**: SAFE — 可視性変更はオーナーのみ。PUT ボディの編集共有者の visibility フィールドはサイレントに無視される。

---

### V-11 — 403 によるプライベートファイル存在の開示 ✅ SAFE

**リスク**: 403 レスポンスが未認可ユーザーにもファイルが存在することを明かす。
**判定**: SAFE — 非オーナーは 403 ではなく 404 を受け取る。ファイルの存在は開示されない。

---

### V-12 — X-User-Id: 0 または負の値による認証バイパス ✅ SAFE

**リスク**: `X-User-Id: 0` または `X-User-Id: -1` がユーザーチェックをバイパスする。
**判定**: SAFE — `ctype_digit()` + `$userId <= 0` チェックがゼロと負の値に 401 を返す。

---

### VULN サマリー

| ID | 脆弱性 | 判定 |
|----|---------------|---------|
| V-01 | IDOR: プライベートファイルアクセス | ✅ SAFE |
| V-02 | IDOR: 他ユーザーのファイル削除 | ✅ SAFE |
| V-03 | IDOR: 他ユーザーのファイル更新 | ✅ SAFE |
| V-04 | ビュー共有の権限昇格 | ✅ SAFE |
| V-05 | ボディによる所有権インジェクション | ✅ SAFE |
| V-06 | 非オーナーによる共有削除 | ✅ SAFE |
| V-07 | 名前フィールドの SQL インジェクション | ✅ SAFE |
| V-08 | 大きすぎる名前でのクラッシュ | ✅ SAFE |
| V-09 | 浮動小数点サイズの型混乱 | ✅ SAFE |
| V-10 | 編集共有の可視性昇格 | ✅ SAFE |
| V-11 | プライベートファイル存在の開示 | ✅ SAFE |
| V-12 | 無効なユーザー ID による認証バイパス | ✅ SAFE |

**12 SAFE、0 EXPOSED**
プライベートファイル 404 パターン、ヘッダーのみのオーナーシップ、2 段階の共有権限、厳格な型バリデーション、オーナーのみの可視性変更が、すべての IDOR と権限昇格ベクターを防止します。

---

## やってはいけないこと

| アンチパターン | リスク |
|---|---|
| 非オーナーのプライベートファイルに 403 を返す | 未認可ユーザーにファイルの存在を明かす |
| リクエストボディの `user_id` をオーナーシップに受け入れる | 認証済みの任意のユーザーが任意のファイルのオーナーシップを主張できる |
| ビュー共有が PUT を呼び出せる | 共有閲覧者がファイルメタデータを変更できる |
| 編集共有が visibility を変更できる | 共有編集者がプライベートファイルをパブリックに公開できる |
| 共有されたユーザーが自分の共有を削除できる | ユーザーがオーナーからアクセス管理を取り消せる |
| `size: 1.5`（浮動小数点）を受け入れる | 型混乱。非整数のファイルサイズがサイズ追跡を破損する |
| `name` の長さ制限なし | 長いファイル名が DB カラムオーバーフローまたはメモリ問題を引き起こす可能性がある |
| `X-User-Id: 0` を有効として受け入れる | ユーザー ID 0 が初期化されていない行にマッチするかオーナーシップチェックをバイパスする可能性がある |
| `> 0` チェックなしの `ctype_digit()` | `"0"` は `ctype_digit` を通過するが有効なユーザー ID ではない |
