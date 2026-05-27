# 暗号化フィールドストレージの構築方法

> **FT リファレンス**: FT267 (`NENE2-FT/encryptlog`) — AES-256-GCM フィールドレベル暗号化: 書き込み時暗号化 / 読み込み時復号化、検索可能な暗号文のブラインドインデックス、暗号化キーとインデックスキーの分離
>
> **VULN アセスメント**: V-01〜V-10 をドキュメント末尾に掲載
>
> **FT187 encryptlog でも実証済みのパターン** — 検索可能な PII ストレージのための HMAC-SHA256 ブラインドインデックスを使った AES-256-GCM フィールドごとの暗号化

---

## 対象範囲

機密フィールド（名前、メール、SSN、クレジットカード）を暗号化して保存しながら検索可能に保つ方法:

1. **AES-256-GCM** — 認証付き暗号化。各レコードが独自のノンスを持つ
2. **ブラインドインデックス** — フィールド値の HMAC-SHA256 により復号化なしに `WHERE email_idx = ?` が可能
3. **AEAD 改ざん検知** — タグ不一致は 400 ではなく `\RuntimeException` を引き起こす
4. **API レスポンスに暗号文を含めない** — VO / toArray() レイヤーは常に平文を返す
5. **IDOR 防止** — すべての読み書きは `WHERE id AND user_id` でスコープされる

---

## 暗号文フォーマット

```
base64( nonce ‖ ciphertext ‖ tag )
```

| コンポーネント | サイズ | 目的 |
|---|---|---|
| `nonce` | 12 バイト | 暗号化ごとにランダムな IV（GCM 標準） |
| `ciphertext` | 可変 | AES-256-GCM 暗号化された平文 |
| `tag` | 16 バイト | 認証タグ — 改ざんを検知 |

単一の `TEXT` カラムとして保存されます。同じ平文 → 毎回異なる暗号文（異なるノンス）。

---

## スキーマ

```sql
CREATE TABLE vault_records (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    name_enc   TEXT    NOT NULL,   -- base64(nonce || ciphertext || tag)
    email_enc  TEXT    NOT NULL,
    email_idx  TEXT    NOT NULL,   -- 検索用 HMAC-SHA256 ブラインドインデックス
    notes_enc  TEXT,               -- null 許容の暗号化フィールド
    created_at TEXT    NOT NULL,
    updated_at TEXT    NOT NULL
);
CREATE INDEX idx_vault_email ON vault_records(email_idx);
```

`email_idx` にはインデックスがあります — `WHERE email_idx = ?` は高速です。`email_enc` 暗号文は検索しません。

---

## FieldCrypto ヘルパー

```php
final readonly class FieldCrypto
{
    private const string ALGO      = 'aes-256-gcm';
    private const int    TAG_LEN   = 16;
    private const int    NONCE_LEN = 12;

    public function __construct(
        private string $encKey,   // 32 バイト必須
        private string $indexKey, // 32 バイト必須
    ) {
        if (strlen($this->encKey) !== 32) {
            throw new \InvalidArgumentException('encKey must be exactly 32 bytes.');
        }
    }

    public function encrypt(string $plaintext): string
    {
        $nonce = random_bytes(self::NONCE_LEN); // 値ごとに新鮮な IV
        $tag   = '';
        $ct    = openssl_encrypt(
            $plaintext, self::ALGO, $this->encKey,
            OPENSSL_RAW_DATA, $nonce, $tag, '', self::TAG_LEN,
        );

        return base64_encode($nonce . $ct . $tag);
    }

    public function decrypt(string $encoded): string
    {
        $raw  = base64_decode($encoded, strict: true);
        $nonce = substr($raw, 0, self::NONCE_LEN);
        $tag   = substr($raw, -self::TAG_LEN);
        $ct    = substr($raw, self::NONCE_LEN, strlen($raw) - self::NONCE_LEN - self::TAG_LEN);

        $pt = openssl_decrypt($ct, self::ALGO, $this->encKey, OPENSSL_RAW_DATA, $nonce, $tag);

        if ($pt === false) {
            throw new \RuntimeException('Decryption failed — tag mismatch or corrupt ciphertext.');
        }

        return $pt;
    }

    /**
     * 決定論的 — 同じ入力は常に同じ出力。
     * 格納された暗号文を復号化せずに WHERE email_idx = ? を可能にする。
     */
    public function blindIndex(string $plaintext): string
    {
        return hash_hmac('sha256', $plaintext, $this->indexKey);
    }
}
```

---

## コアパターン: 書き込み時に暗号化、読み込み時に復号化

```php
// CREATE — INSERT 前にすべての機密フィールドを暗号化
public function create(int $userId, string $name, string $email, ?string $notes): VaultRecord
{
    $stmt->execute([
        'name_enc'  => $this->crypto->encrypt($name),
        'email_enc' => $this->crypto->encrypt($email),
        'email_idx' => $this->crypto->blindIndex($email), // 検索用の決定論的値
        'notes_enc' => $notes !== null ? $this->crypto->encrypt($notes) : null,
        // ...
    ]);
}

// READ — ハイドレーション時に透過的に復号化
private function hydrateRow(array $row): VaultRecord
{
    return new VaultRecord(
        name:  $this->crypto->decrypt((string) $row['name_enc']),
        email: $this->crypto->decrypt((string) $row['email_enc']),
        notes: $row['notes_enc'] !== null
            ? $this->crypto->decrypt((string) $row['notes_enc'])
            : null,
        // ...
    );
}
```

---

## コアパターン: ブラインドインデックス検索

```php
// SEARCH — クエリパラメーターからブラインドインデックスを計算。検索中に行を復号化しない
public function findByEmail(int $userId, string $email): array
{
    $idx  = $this->crypto->blindIndex($email); // 同じキー → 同じインデックス
    $stmt = $this->pdo->prepare(
        'SELECT * FROM vault_records WHERE user_id = :user_id AND email_idx = :idx',
    );
    $stmt->execute(['user_id' => $userId, 'idx' => $idx]);
    // 行は hydrateRow() で復号化される
}
```

**更新時にメールが変わる場合はインデックスを再計算:**

```php
$stmt->execute([
    'email_enc' => $this->crypto->encrypt($newEmail),
    'email_idx' => $this->crypto->blindIndex($newEmail), // ← 一緒に更新が必要
]);
```

---

## コアパターン: レスポンスに暗号文を含めない

```php
// VaultRecord::toArray() — 復号化された平文のみを返す
public function toArray(): array
{
    return [
        'id'         => $this->id,
        'name'       => $this->name,  // 平文
        'email'      => $this->email, // 平文
        'notes'      => $this->notes, // 平文または null
        'created_at' => $this->createdAt,
        'updated_at' => $this->updatedAt,
        // name_enc、email_enc、email_idx、notes_enc — 公開しない
    ];
}
```

API レスポンスを読んだ攻撃者はオフライン攻撃を行うための暗号文を回収できません。

---

## コアパターン: 改ざん検知は 500

```php
$pt = openssl_decrypt($ct, self::ALGO, $this->encKey, OPENSSL_RAW_DATA, $nonce, $tag);

if ($pt === false) {
    // タグ不一致 = 改ざんされた DB 行、または間違ったキー
    // スローする — グローバルエラーハンドラーに 500 を返させる
    // 400 を返してはいけない — 400 はクライアントエラー。これは内部整合性の問題
    throw new \RuntimeException('Decryption failed.');
}
```

400 を返すとクライアントが悪いデータを送ったことを示します。500 は「サーバーサイドの整合性問題」を正しく示し、どのフィールドが失敗したかや理由を漏らしません。

---

## キー管理ガイドライン

```php
// 本番: KMS またはシークレットマネージャーからキーを導出する
$encKey   = random_bytes(32); // 32 バイト = AES-256
$indexKey = random_bytes(32); // 別のキー — 異なる HMAC ドメイン

// キーをソースに絶対にハードコードしない。環境変数またはキー導出を使う:
$encKey   = hex2bin(getenv('VAULT_ENC_KEY'));   // 64 hex 文字 → 32 バイト
$indexKey = hex2bin(getenv('VAULT_INDEX_KEY')); // 64 hex 文字 → 32 バイト
```

**2 つの別個のキー:**
- `encKey` — AES-256-GCM。ローテーション可能: 新しいキーで行を再暗号化してバージョンプレフィックスを更新。
- `indexKey` — HMAC-SHA256。すべてのインデックスを再ハッシュせずにはローテーションできない。

---

## テスト結果（FT187）

```
51 テスト / 110 アサーション — すべて PASS
PHPStan level 8 — エラーなし
PHP CS Fixer — クリーン
```

| テスト領域 | カバレッジ |
|---|---|
| FieldCrypto ユニット | 暗号化/復号化ラウンドトリップ、ノンス一意性、ブラインドインデックス決定論性、改ざん検知、短いキーの拒否 |
| ハッピーパス | 作成/取得/一覧/更新/削除/検索 |
| 暗号文分離 | レスポンスに `name_enc`、`email_enc`、`email_idx`、`notes_enc` を含めない |
| IDOR 防止 | クロスユーザーの取得/更新/削除はすべて 404 を返す |
| マスアサインメント | ボディからの `name_enc`、`email_idx`、`user_id` は無視 |
| バリデーション | 欠落/長すぎる/型が違う name、email、notes、limit |
| ブラインドインデックス再計算 | メール更新でインデックスを同期保持 |

---

## VULN アセスメント（FT267）

フィールド暗号化の脅威モデルにおける `NENE2-FT/encryptlog` のセキュリティアセスメント。

### V-01 — キー管理: 環境変数ロード ✅ SAFE

**脅威**: 暗号化キーが VCS にコミットされるかソースにハードコードされる。
**緩和策**: `ConfigLoader` の `getenv()` 経由でキーをロード、ブート時に長さを検証。`.env` ファイルは git-ignored。キー素材はソースコードに現れない。
**残留リスク**: キーローテーション（両キーの置き換え、全行の再暗号化）は実装されていない。FT スコープとして受け入れ。本番システムにはローテーション計画が必要。

---

### V-02 — ノンス再利用（GCM） ✅ SAFE

**脅威**: 同じキーで同じノンスが 2 回使われると、GCM はすべての機密性と真正性の保証を失う。
**緩和策**: `random_bytes(12)` が呼び出しごとに `encrypt()` 内で呼ばれる。96 ビットのノンス空間と `random_bytes()` により、現実的な使用量（キーライフタイムあたり 2^32 回未満の暗号化が安全な境界）での衝突確率は無視できる。
**判定**: 安全。

---

### V-03 — 認証タグの検証 ✅ SAFE

**脅威**: 暗号文の改ざんが検知されず、攻撃者がビットを反転させて復号化された平文を操作できる。
**緩和策**: `openssl_decrypt()` は平文を返す前に 16 バイトの GCM 認証タグを検証する。1 ビットの変更でも `false` を返し、`FieldCrypto::decrypt()` がそれをスローされた `\RuntimeException` に変換する。アプリケーションがそれをキャッチして `500` を返す。部分的な平文は公開されない。
**判定**: 安全。

---

### V-04 — API レスポンスが復号化エラー詳細を漏洩 ⚠️ EXPOSED

**脅威**: エラーハンドラーが `\RuntimeException::getMessage()`（"Decryption failed — tag mismatch or corrupt ciphertext."）を API レスポンスにシリアライズし、攻撃者に整合性のシグナルを漏らす。
**判定**: `APP_DEBUG=true` モードでは完全なメッセージとスタックトレースが表示される可能性がある。`APP_DEBUG=false` モードでも、デフォルトハンドラーが例外クラス名を露出する可能性がある。
**推奨**: デバッグモードに関わらず、汎用的な `"internal-error"` Problem Details ボディを持つ `500` にマッピングする専用の `DecryptionFailedExceptionHandler` を追加する。タグ検証の失敗はサーバーサイドのみでログに記録する。

---

### V-05 — ブラインドインデックスの衝突 / オフライン辞書 ✅ SAFE

**脅威**: 攻撃者がオフラインで `blindIndex(candidate)` 値の辞書を作成し、`email_idx` カラムと照合する。
**緩和策**: 256 ビットのシークレットキーを使った HMAC-SHA256。`VAULT_INDEX_KEY` なしでは、任意のインデックス値の事前計算は計算上不可能。ブラインドインデックスは完全一致のみをサポート（`WHERE email_idx = ?`）。ワイルドカードや部分文字列検索は不可能。
**残留リスク**: `VAULT_INDEX_KEY` が漏洩すると、有限の既知メールリストに対してすべてのメールブラインドインデックスがブルートフォース可能になる。キーの機密性は不可欠。

---

### V-06 — エンドポイントに認証 / 認可なし ⚠️ EXPOSED

**脅威**: 未認証の呼び出し元が任意の `user_id` 値に対してボールドレコードを作成、読み取り、更新、削除できる。
**判定**: FT は API キー、JWT、またはセッションチェックなしに `/vault/{userId}/records` を公開している。`user_id` パスパラメーターは呼び出し元が提供する。
**推奨**: 認証（API キーまたは JWT）を要求し、検証済みトークンから `$userId` を導出する — 呼び出し元が提供する `user_id` を信頼してはいけない。`requireScope()` または同等の認証ミドルウェアを追加する。
**FT 注記**: FT のスコープ制約として意図的。本番使用には認証が必要。

---

### V-07 — 更新 / 削除の IDOR ✅ SAFE

**脅威**: 認証済みだが間違ったユーザーが別のユーザーの暗号化レコードを変更する。
**緩和策**: すべての書き込みクエリに `AND user_id = :user_id` が含まれる。レコードが別のユーザーに属する場合、`rowCount()` は 0 を返し、コントローラーは 404 を返す。攻撃者はレコードが（自分にとって）存在しないことのみを知る。
**判定**: 安全（認証が存在する前提。V-06 参照）。

---

### V-08 — キーローテーション / 再暗号化のギャップ ⚠️ EXPOSED

**脅威**: `VAULT_ENC_KEY` がローテーションされると、前のキーで暗号化された古い暗号文が復号化できない。再暗号化マイグレーション戦略がない。
**判定**: キーバージョニングなし、再暗号化ユーティリティなし、マイグレーションのドキュメントなし。
**推奨**: 各暗号化 blob にキーバージョンバイトをプレフィックスとして付ける（例: `v1:<base64>`）。復号化時にバージョンを読み取り、キーを選択する。古いキーで復号化し、新しいキーでトランザクション内で再暗号化するマイグレーションスクリプトを提供する。

---

### V-09 — ブラインドインデックスのタイミング比較 ✅ SAFE

**脅威**: 信頼できないソースからの `email_idx` を `===` で比較すると、文字ごとのタイミング情報が漏れる。
**緩和策**: `findByEmail()` は計算されたブラインドインデックスを SQL パラメーターとして渡す。比較は SQLite の B-tree インデックス検索内で行われ、PHP サイドからはタイミングオラクルにならない。ブラインドインデックス値の PHP サイドの文字列比較は発生しない。
**判定**: 安全。

---

### V-10 — メモリ / ログ内の復号化データ ⚠️ EXPOSED

**脅威**: 復号化された平文（名前、メール、メモ）が PHP 例外トレース、リクエストログミドルウェア（ボディがログに記録される場合）、エラー出力、APM スパンに現れる。
**判定**: リクエストボディログミドルウェアは暗号化前に POST ボディをログに記録する — 平文フィールドがログに含まれる。`VaultRecord` が例外コンテキストに含まれると、復号化されたフィールドがスタックトレースに現れる。
**推奨**:
1. リクエストボディのログ記録から平文の vault ペイロードを除外する（`/vault` ルートをマスクまたはスキップ）。
2. var_dump / 例外シリアライゼーションから機密フィールドを削除するために `VaultRecord` に `__debugInfo()` を実装する。
3. エラー追跡統合（Sentry など）が平文フィールドを送信前に削除することを確認する。

---

### VULN サマリー

| ID | 脅威 | ステータス |
|----|--------|--------|
| V-01 | VCS へのキーコミット | ✅ SAFE |
| V-02 | ノンス再利用（GCM） | ✅ SAFE |
| V-03 | 改ざんされた暗号文の受け入れ | ✅ SAFE |
| V-04 | レスポンスに復号化エラー詳細 | ⚠️ EXPOSED |
| V-05 | ブラインドインデックスのオフライン辞書 | ✅ SAFE |
| V-06 | エンドポイントに認証なし | ⚠️ EXPOSED |
| V-07 | 更新/削除の IDOR | ✅ SAFE |
| V-08 | キーローテーション / 再暗号化のギャップ | ⚠️ EXPOSED |
| V-09 | ブラインドインデックスのタイミング比較 | ✅ SAFE |
| V-10 | ログ/例外内の復号化データ | ⚠️ EXPOSED |

**スコア**: 6 SAFE、4 EXPOSED。

4 つの EXPOSED はキーローテーション戦略（V-08）、認証（V-06、FT スコープとして意図的）、エラー詳細の漏洩（V-04）、ログ衛生（V-10）に関するものです。これらは AES-256-GCM またはブラインドインデックス暗号設計の欠陥ではありません — 本番使用前に対処しなければならない運用上および統合上のギャップです。
