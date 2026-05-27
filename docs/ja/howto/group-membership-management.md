# NENE2 でグループメンバーシップ管理を構築する方法

このガイドでは、ユーザーがグループを作成し、ロール付きでメンバーを招待し（owner/admin/member）、メンバーシップを管理し、ロール昇格を制御するグループシステムを構築する手順を解説します。

**フィールドトライアル**: FT138
**NENE2 バージョン**: ^1.5
**対象トピック**: ロールベースのメンバーシップ、オーナー自動参加、自己退出、MySQL 予約語の落とし穴（`groups`）、脆弱性アセスメント

---

## 構築するもの

- `POST /groups` — グループを作成する（作成者がオーナーになる）
- `GET /groups/{groupId}/members` — メンバーを一覧表示する（メンバーのみ）
- `POST /groups/{groupId}/members` — メンバーを追加する（owner/admin のみ、role: member または admin）
- `DELETE /groups/{groupId}/members/{userId}` — メンバーを削除する（owner/admin が他のメンバーを削除可能。誰でも自己退出可能）
- `PUT /groups/{groupId}/members/{userId}/role` — ロールを変更する（owner のみ）

---

## データベーススキーマ — 重要: `groups` をテーブル名に使用しない

`groups` は **MySQL の予約語**（`GROUP BY` で使用される）です。代わりに `user_groups` を使用してください。

```sql
-- SQLite
CREATE TABLE user_groups (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT    NOT NULL,
    owner_id   INTEGER NOT NULL,
    created_at TEXT    NOT NULL,
    FOREIGN KEY (owner_id) REFERENCES users(id)
);

CREATE TABLE memberships (
    id        INTEGER PRIMARY KEY AUTOINCREMENT,
    group_id  INTEGER NOT NULL,
    user_id   INTEGER NOT NULL,
    role      TEXT    NOT NULL DEFAULT 'member',
    joined_at TEXT    NOT NULL,
    UNIQUE (group_id, user_id),
    CHECK (role IN ('owner', 'admin', 'member')),
    FOREIGN KEY (group_id) REFERENCES user_groups(id),
    FOREIGN KEY (user_id)  REFERENCES users(id)
);
```

```sql
-- MySQL
CREATE TABLE user_groups (
    id         INT          NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name       VARCHAR(255) NOT NULL,
    owner_id   INT          NOT NULL,
    created_at VARCHAR(32)  NOT NULL,
    FOREIGN KEY (owner_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE memberships (
    id        INT         NOT NULL AUTO_INCREMENT PRIMARY KEY,
    group_id  INT         NOT NULL,
    user_id   INT         NOT NULL,
    role      VARCHAR(16) NOT NULL DEFAULT 'member',
    joined_at VARCHAR(32) NOT NULL,
    UNIQUE KEY uq_group_user (group_id, user_id),
    CONSTRAINT chk_role CHECK (role IN ('owner', 'admin', 'member')),
    FOREIGN KEY (group_id) REFERENCES user_groups(id),
    FOREIGN KEY (user_id)  REFERENCES users(id)
) ENGINE=InnoDB;
```

---

## 能力メソッド付きロール enum

```php
enum MemberRole: string
{
    case Owner  = 'owner';
    case Admin  = 'admin';
    case Member = 'member';

    public function canManageMembers(): bool
    {
        return $this === self::Owner || $this === self::Admin;
    }

    public function canChangeRoles(): bool
    {
        return $this === self::Owner;
    }
}
```

enum の能力メソッドにより、認可ロジックをハンドラーから切り離せます。

---

## グループ作成時のオーナー自動参加

グループが作成されると、オーナーは自動的に `owner` ロールのメンバーとして追加されます:

```php
public function createGroup(string $name, int $ownerId, string $now): int
{
    $this->executor->execute(
        'INSERT INTO user_groups (name, owner_id, created_at) VALUES (?, ?, ?)',
        [$name, $ownerId, $now],
    );

    $groupId = (int) $this->executor->lastInsertId();

    // オーナーは 'owner' ロールのメンバーとして自動追加される
    $this->executor->execute(
        'INSERT INTO memberships (group_id, user_id, role, joined_at) VALUES (?, ?, ?, ?)',
        [$groupId, $ownerId, 'owner', $now],
    );

    return $groupId;
}
```

---

## メンバー追加ハンドラー — ロールバリデーション

`owner` ロールは add-member API 経由で割り当てることができません。`MemberRole::tryFrom()` に `TokenScope::tryFrom()` パターンを適用:

```php
$role = MemberRole::tryFrom($roleValue);

if ($role === null || $role === MemberRole::Owner) {
    return $this->responseFactory->create(['error' => 'role must be member or admin'], 422);
}
```

---

## メンバー削除 — 自己退出と管理者による削除

メンバーは管理者権限なしに自分のグループから退出（自己退出）できます。管理者は他のメンバーを削除できます。オーナーは削除できません:

```php
$isSelfLeave = $actorId === $userId;

if (!$isSelfLeave && !$actorRole->canManageMembers()) {
    return $this->responseFactory->create(['error' => 'only owner or admin can remove members'], 403);
}

$targetRole = MemberRole::tryFrom($targetMembership['role']) ?? MemberRole::Member;

if ($targetRole === MemberRole::Owner) {
    return $this->responseFactory->create(['error' => 'cannot remove the group owner'], 422);
}
```

---

## MySQL FK 削除順 — 順序が重要

テスト時に MySQL をリセットする際は、`FOREIGN_KEY_CHECKS = 0` で FK 依存テーブルを先にドロップしてください:

```php
$this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
$this->pdo->exec('DROP TABLE IF EXISTS memberships');
$this->pdo->exec('DROP TABLE IF EXISTS user_groups');
$this->pdo->exec('DROP TABLE IF EXISTS users');
$this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
```

---

## 脆弱性アセスメント（FT138）

12 件の脆弱性テストが以下を検証します:

| ID | 攻撃 | 期待結果 | 結果 |
|----|--------|----------|--------|
| VULN-A | IDOR: 非メンバーがメンバーリストを読む | 403 | Pass |
| VULN-B | IDOR: 非メンバーがメンバーを追加する | 403 | Pass |
| VULN-C | 一般メンバーが誰かを追加しようとする | 403 | Pass |
| VULN-D | 管理者が owner ロールを設定しようとする | 200 でない | Pass |
| VULN-E | メンバーが自分を admin に昇格させようとする | 403 | Pass |
| VULN-F | グループオーナーを削除する | 422 | Pass |
| VULN-G | 作成時に X-User-Id が欠如 | 201 でない | Pass |
| VULN-H | 数値でない X-User-Id | 200 でない | Pass |
| VULN-I | グループ名への SQL インジェクション | 201（そのまま保存） | Pass |
| VULN-J | クロスグループメンバー操作 | 403 | Pass |
| VULN-K | 負のグループ ID | 404 | Pass |
| VULN-L | 管理者がロールを変更できない | 403 | Pass |

12 件すべての脆弱性テストが Pass。脆弱性は見つかりませんでした。

---

## よくある落とし穴

| 落とし穴 | 修正 |
|---------|-----|
| MySQL でテーブル名に `groups` を使用する | `user_groups` を使用する — `groups` は MySQL の予約語 |
| オーナーが memberships に自動追加されない | `createGroup()` でオーナーのメンバーシップを INSERT する |
| 管理者がロールを変更できる | `canChangeRoles()` は `Owner` のみ true を返す |
| add-member API で `owner` ロールを許可する | `role === MemberRole::Owner` を拒否 → 422 |
| アクターが存在しないと非メンバーが 403 をバイパスする | `findMembership(groupId, actorId) !== null` を確認する |
| FK 制約で MySQL の DROP TABLE が失敗する | DROP 前に `SET FOREIGN_KEY_CHECKS = 0` を実行する |
