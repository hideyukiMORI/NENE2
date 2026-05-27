# ハウツー: フィーチャーフラグ API

> **FT リファレンス**: FT270 (`NENE2-FT/featureflaglog`) — フィーチャーフラグ API: 優先度チェーン評価（ユーザーターゲット → テナントターゲット → globally_enabled → rollout_pct ハッシュ）、crc32 ベースの決定論的バケット割り当て、ユーザー/テナントキルスイッチ、フラグ UNIQUE 名制約、21 テスト / 31 アサーション PASS。

フィーチャーフラグを使うと、コードをデプロイせずにランタイムで機能をトグルできます。コアの決定事項は: 状態をどこに保存するか（DB vs 設定ファイル）、複数のルールが適用された場合の優先度の評価方法、ユーザーごとの追跡なしでロールアウトパーセンテージを処理する方法です。

---

## ルート

| メソッド | パス | 説明 |
|----------|---------------------------------------|------------------------------------------|
| `POST`   | `/flags`                              | 新しいフィーチャーフラグを作成する |
| `GET`    | `/flags/{name}`                       | ターゲット付きでフラグの詳細を取得する |
| `POST`   | `/flags/{name}/toggle`                | globally_enabled をオン/オフに設定する |
| `PUT`    | `/flags/{name}/rollout`               | ロールアウトパーセンテージを設定する（0〜100） |
| `PUT`    | `/flags/{name}/targets`               | ユーザーまたはテナントターゲットオーバーライドを UPSERT する |
| `DELETE` | `/flags/{name}/targets/{type}/{id}`   | 特定のターゲットオーバーライドを削除する |
| `POST`   | `/flags/{name}/evaluate`              | ユーザー/テナントのフラグを評価する |

---

## コアコンポーネント

- **フィーチャーフラグレジストリ**: 名前、グローバルオン/オフスイッチ、ロールアウトパーセンテージを持つフラグ 1 行。
- **フラグターゲット**: グローバル状態に勝つユーザーごとまたはテナントごとのオーバーライド。
- **評価器**: 優先度チェーンを適用し、特定のユーザーに対してブール値を返す。

## スキーマ

```sql
CREATE TABLE feature_flags (
    id               INTEGER PRIMARY KEY AUTOINCREMENT,
    name             TEXT    NOT NULL UNIQUE,
    description      TEXT    NOT NULL DEFAULT '',
    globally_enabled INTEGER NOT NULL DEFAULT 0,
    rollout_pct      INTEGER NOT NULL DEFAULT 0,  -- 0-100
    created_at       TEXT    NOT NULL
);

CREATE TABLE flag_targets (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    flag_id     INTEGER NOT NULL,
    target_type TEXT    NOT NULL,  -- 'user' | 'tenant'
    target_id   TEXT    NOT NULL,
    enabled     INTEGER NOT NULL DEFAULT 1,
    UNIQUE (flag_id, target_type, target_id),
    FOREIGN KEY (flag_id) REFERENCES feature_flags(id)
);
```

## 評価優先度

```php
final readonly class FlagEvaluator
{
    /** @param FlagTarget[] $targets */
    public function evaluate(FeatureFlag $flag, array $targets, string $userId, ?string $tenantId): bool
    {
        // 1. 明示的なユーザーレベルターゲットが最初に勝つ
        foreach ($targets as $target) {
            if ($target->targetType === 'user' && $target->targetId === $userId) {
                return $target->enabled;
            }
        }

        // 2. テナントレベルターゲット
        if ($tenantId !== null) {
            foreach ($targets as $target) {
                if ($target->targetType === 'tenant' && $target->targetId === $tenantId) {
                    return $target->enabled;
                }
            }
        }

        // 3. グローバルスイッチ
        if ($flag->globallyEnabled) {
            return true;
        }

        // 4. ロールアウトパーセンテージ: crc32 ハッシュによる決定論的バケット
        if ($flag->rolloutPct > 0) {
            $bucket = abs(crc32($userId . '.' . $flag->name)) % 100;
            return $bucket < $flag->rolloutPct;
        }

        // 5. デフォルトオフ
        return false;
    }
}
```

優先度順（最も高いものが勝つ）:
1. ユーザーレベルターゲット（`target_type = 'user'`）
2. テナントレベルターゲット（`target_type = 'tenant'`）
3. `globally_enabled = 1`
4. `rollout_pct > 0` でハッシュベースのバケット
5. `false`

## ロールアウトパーセンテージ — 決定論的バケット

`crc32($userId . '.' . $flagName) % 100` は（ユーザー、フラグ）ペアごとに安定したバケットを生成します。同じユーザーは常に同じバケットに入り、リクエスト全体で一貫した体験を得ます。フラグ名を追加することで、すべてのフラグが `pct = 10` で同じユーザーにロールアウトされることを防ぎます。

重要: `crc32()` は 64 ビットシステムで負の値を返す可能性があります — `abs()` を使用してください。

## キルスイッチとしてのターゲット

`enabled = false` のターゲットはキルスイッチです: `globally_enabled = 1` の場合でも、そのユーザーまたはテナントに対してフラグを無効にします。これはすでにグローバルに有効になっているロールアウトから特定のユーザーを除外する標準的な方法です。

```php
// ユーザーレベルキルスイッチ（グローバル有効をオーバーライド）
$repo->upsertTarget($flag->id, 'user', 'problem-user', false);

// テナントの早期アクセス（グローバル無効をオーバーライド）
$repo->upsertTarget($flag->id, 'tenant', 'beta-tenant', true);
```

## ターゲットの UPSERT パターン

ターゲットは `INSERT OR REPLACE` / upsert セマンティクスを使用します — 異なる `enabled` 値で同じエンドポイントを 2 回呼び出すと、重複を作成するのではなく既存の行が更新されます:

```php
$existing = $this->executor->fetchOne(
    'SELECT * FROM flag_targets WHERE flag_id = ? AND target_type = ? AND target_id = ?',
    [$flagId, $targetType, $targetId],
);

if ($existing !== null) {
    $this->executor->execute('UPDATE flag_targets SET enabled = ? WHERE id = ?', ...);
} else {
    $this->executor->execute('INSERT INTO flag_targets ...', ...);
}
```

`(flag_id, target_type, target_id)` の UNIQUE 制約により（フラグ、ターゲット）ペアごとに最大 1 つのオーバーライドが保証されます。

## 重複フラグ名に対する競合レスポンス

`feature_flags.name` には UNIQUE 制約があります。重複作成時、DB は `RuntimeException` をスローします。500 ではなく 409 Conflict を返すようにキャッチしてください:

```php
try {
    $this->executor->execute('INSERT INTO feature_flags ...', [...]);
} catch (\RuntimeException) {
    return null; // 呼び出し元が null → 409 にマップする
}
```

## 設計上の決定

**なぜ設定ファイルではなく DB バックエンドを使うのか?**
設定ファイルはフラグを変更するためにデプロイが必要です。DB バックのフラグはコードに触れたりプロセスを再起動したりせずにライブでトグルできます。

**なぜロールアウトにリクエストごとのランダムではなく決定論的ハッシュを使うのか?**
ランダム選択は同じユーザーがリクエスト全体で有効/無効を切り替える可能性があります。安定したハッシュはフラグのライフタイム全体で各ユーザーに一貫した体験を提供します。

**なぜ `enabled = false` のターゲットを許可するのか?**
キルスイッチのないフラグシステムは不完全です。`enabled = false` はすでにグローバルに有効になっているロールアウトからユーザーを除外する最も安全な方法です — コード変更もデプロイも不要です。

**なぜ `globally_enabled` と `rollout_pct` を分けるのか?**
`globally_enabled = 1` は明示的なオールオアナッシングスイッチです。`rollout_pct` は段階的な公開用です。分けることで 1 つのフィールドに 2 つの異なる意味を持たせることを避けられます。

---

## レスポンス例

**POST /flags**（201 Created）:
```json
{
    "id": 1,
    "name": "new-checkout",
    "description": "New checkout flow",
    "globally_enabled": false,
    "rollout_pct": 0,
    "created_at": "2026-05-27 10:00:00"
}
```

**GET /flags/{name}**（200 OK）:
```json
{
    "flag": {
        "id": 1,
        "name": "new-checkout",
        "globally_enabled": false,
        "rollout_pct": 30
    },
    "targets": [
        {
            "id": 1,
            "flag_id": 1,
            "target_type": "user",
            "target_id": "user-42",
            "enabled": true
        }
    ]
}
```

**POST /flags/{name}/evaluate**（200 OK）:
```json
{
    "flag": "new-checkout",
    "user_id": "user-42",
    "enabled": true
}
```

---

## やってはいけないこと

| アンチパターン | リスク |
|---|---|
| リクエストごとのロールアウトにランダム数を使用する | 同じユーザーがリクエスト全体で有効/無効を切り替える — 不整合な UX |
| `crc32()` で `abs()` を忘れる | crc32 は 64 ビット PHP で負の値を返す可能性がある — モジュロが間違ったバケットを返す |
| 任意の `target_type` 値を許可する | 制御されていない enum で評価ロジックが無制限になる。`'user'` と `'tenant'` に制限する |
| `UNIQUE (flag_id, target_type, target_id)` なし | 重複ターゲットで評価が曖昧になる — 最初の行が恣意的に勝つ |
| フラグ名を `target_id` として使用する | フラグ名は変わる可能性がある。ユーザー/テナントターゲット指定には安定した ID を使用する |
| 重複フラグ名に 500 を返す | 名前の一意性違反はドメインエラーであり、サーバーエラーではない。409 Conflict にマップする |
