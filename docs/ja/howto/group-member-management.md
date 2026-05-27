# ハウツー: グループメンバー管理

> **FT リファレンス**: FT291 (`NENE2-FT/grouplog`) — グループメンバーシップ: MemberRole enum（owner/admin/member）、UNIQUE(group_id, user_id)、オーナー削除不可ガード、クロスグループ IDOR 防止、canManageMembers()/canChangeRoles() ロール階層、VULN-A〜L すべて SAFE、38 テスト / 101 アサーション PASS。

このガイドでは、オーナー、管理者、メンバーの段階的な権限を持つロールベースのメンバーシップ管理システムの構築方法を示します。

## スキーマ

```sql
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

`UNIQUE(group_id, user_id)` は重複したメンバーシップを防止します。`CHECK(role IN ...)` は DB レベルで無効なロールをブロックします。

## エンドポイント

| メソッド | パス | 認証 | 説明 |
|--------|------|------|-------------|
| `POST` | `/groups` | `X-User-Id` | グループを作成する（アクターがオーナーになる） |
| `GET` | `/groups/{groupId}/members` | `X-User-Id`（メンバー） | メンバーを一覧表示する |
| `POST` | `/groups/{groupId}/members` | `X-User-Id`（owner/admin） | メンバーを追加する |
| `DELETE` | `/groups/{groupId}/members/{userId}` | `X-User-Id` | メンバーを削除する |
| `PUT` | `/groups/{groupId}/members/{userId}/role` | `X-User-Id`（owner） | ロールを変更する |

## MemberRole Enum

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

ロール能力:
- **Owner**: メンバーの追加/削除、ロール変更が可能。削除不可
- **Admin**: メンバーの追加/削除が可能。ロール変更不可
- **Member**: 自分自身の退出（self-leave）のみ可能

## アクター解決

```php
private function resolveActorId(ServerRequestInterface $request): int
{
    $header = $request->getHeaderLine('X-User-Id');
    return is_numeric($header) ? (int) $header : 0;
}
```

数値でないヘッダーは 0（無効）を返します。すべての特権操作は処理前にアクターを DB に対して検証します。

## すべての操作前のメンバーシップチェック

```php
$actorMembership = $actorId > 0 ? $this->repo->findMembership($groupId, $actorId) : null;

if ($actorMembership === null) {
    return $this->responseFactory->create(['error' => 'not a member'], 403);
}
```

非メンバーはすべてのグループ操作（メンバー一覧表示を含む）で 403 を受け取ります（IDOR 防止）。

## メンバー追加 — ロール階層

```php
$actorRole = MemberRole::tryFrom($actorMembership['role']) ?? MemberRole::Member;

if (!$actorRole->canManageMembers()) {
    return $this->responseFactory->create(['error' => 'only owner or admin can add members'], 403);
}

// add-member エンドポイントで 'owner' を割り当てることはできない
$role = MemberRole::tryFrom($roleValue);
if ($role === null || $role === MemberRole::Owner) {
    return $this->responseFactory->create(['error' => 'role must be member or admin'], 422);
}
```

`owner` ロールは API 経由で割り当てることができません — グループ作成時にのみ設定されます。

## オーナーは削除不可

```php
$targetRole = MemberRole::tryFrom($targetMembership['role']) ?? MemberRole::Member;

if ($targetRole === MemberRole::Owner) {
    return $this->responseFactory->create(['error' => 'cannot remove the group owner'], 422);
}
```

オーナーは削除から保護されています。オーナーシップの移転は専用エンドポイントが必要です。

## 自己退出 vs 管理者による削除

```php
$isSelfLeave = $actorId === $userId;

if (!$isSelfLeave && !$actorRole->canManageMembers()) {
    return $this->responseFactory->create(['error' => 'only owner or admin can remove members'], 403);
}
```

メンバーは管理者権限なしに自分自身を削除（自己退出）できます。別のユーザーを削除するには `canManageMembers()` が必要です。

## ロール変更 — オーナーのみ

```php
if (!$actorRole->canChangeRoles()) {
    return $this->responseFactory->create(['error' => 'only owner can change roles'], 403);
}

$role = MemberRole::tryFrom($roleValue);
if ($role === null || $role === MemberRole::Owner) {
    return $this->responseFactory->create(['error' => 'role must be member or admin'], 422);
}
```

オーナーのみがメンバーを昇格/降格できます。`owner` ロールは割り当て不可です（暗黙のオーナーシップ窃取を防止）。

---

## 脆弱性アセスメント

### V-01 — IDOR: 非メンバーがメンバーリストを読む ✅ SAFE

**リスク**: 非メンバーが `GET /groups/{id}/members` を呼び出してユーザーを列挙する。
**判定**: SAFE — `findMembership(groupId, actorId) === null` → データを返す前に 403。

---

### V-02 — IDOR: 非メンバーがグループにユーザーを追加する ✅ SAFE

**リスク**: 非メンバーが `POST /groups/{id}/members` を呼び出してユーザーをインジェクションする。
**判定**: SAFE — 同じメンバーシップチェック。非メンバー → 403。

---

### V-03 — 権限昇格: 一般メンバーが別のメンバーを追加する ✅ SAFE

**リスク**: 一般メンバー（`role = 'member'`）が新しいユーザーを追加しようとする。
**判定**: SAFE — `Member` に対して `canManageMembers()` は false を返す → 403。

---

### V-04 — 権限昇格: 管理者がオーナーに昇格させる ✅ SAFE

**リスク**: 管理者が add-member または change-role エンドポイントで `role = 'owner'` を割り当てようとする。
**判定**: SAFE — 両エンドポイントが割り当て可能なロールとして `MemberRole::Owner` を拒否 → 422。

---

### V-05 — 権限昇格: メンバーが自己昇格する ✅ SAFE

**リスク**: 一般メンバーが `PUT /groups/{id}/members/{self}/role` を呼び出す。
**判定**: SAFE — `Member` と `Admin` に対して `canChangeRoles()` は false → 403。

---

### V-06 — オーナー削除 ✅ SAFE

**リスク**: 管理者がグループオーナーを削除しようとする。
**判定**: SAFE — `if ($targetRole === MemberRole::Owner)` → 422。

---

### V-07 — グループ作成時の X-User-Id 欠如 ✅ SAFE

**リスク**: `X-User-Id` なしのリクエストが有効なオーナーなしのグループを作成する。
**判定**: SAFE — 欠如/無効なヘッダーに対して `resolveActorId()` が 0 を返す → `findUserById(0)` が null を返す → 404。

---

### V-08 — 数値でない X-User-Id ✅ SAFE

**リスク**: ヘッダー `X-User-Id: admin` が数値アクターバリデーションをバイパスする。
**判定**: SAFE — 数値でない文字列に対して `is_numeric($header)` は false → 0 を返す → 拒否。

---

### V-09 — グループ名への SQL インジェクション ✅ SAFE

**リスク**: グループ名 `'; DROP TABLE user_groups; --` がデータを削除する。
**判定**: SAFE — すべてのクエリはパラメーター化ステートメントを使用。インジェクション文字列は実行されずにグループ名としてそのまま保存される。

---

### V-10 — クロスグループメンバー操作（IDOR） ✅ SAFE

**リスク**: グループ A のオーナーがグループ B のメンバーを削除しようとする。
**判定**: SAFE — `findMembership(groupId, actorId)` が*ターゲット*グループのメンバーシップをチェック。グループ A のオーナーはグループ B にメンバーシップがない → 403。

---

### V-11 — 負のグループ ID ✅ SAFE

**リスク**: `GET /groups/-1/members` が DB エラーや予期しない動作を引き起こす。
**判定**: SAFE — `is_numeric($params['groupId']) ? (int)$params['groupId'] : 0` が `-1` を数値として受け付けるが、`findGroupById(-1)` が null を返す → 404。

---

### V-12 — 管理者がロールを変更できない ✅ SAFE

**リスク**: 管理者が `PUT /groups/{id}/members/{userId}/role` を呼び出してユーザーを昇格させる。
**判定**: SAFE — `canChangeRoles()` はオーナー専用 → 管理者は 403 を受け取る。

---

### VULN サマリー

| ID | 脆弱性 | 判定 |
|----|---------------|---------|
| V-01 | IDOR: 非メンバーがメンバーリストを読む | ✅ SAFE |
| V-02 | IDOR: 非メンバーがメンバーを追加する | ✅ SAFE |
| V-03 | 権限昇格: メンバーがメンバーを追加する | ✅ SAFE |
| V-04 | 権限昇格: 管理者 → オーナー | ✅ SAFE |
| V-05 | 権限昇格: メンバーが自己昇格する | ✅ SAFE |
| V-06 | オーナー削除 | ✅ SAFE |
| V-07 | 作成時の X-User-Id 欠如 | ✅ SAFE |
| V-08 | 数値でない X-User-Id | ✅ SAFE |
| V-09 | グループ名への SQL インジェクション | ✅ SAFE |
| V-10 | クロスグループ IDOR（他グループのオーナー） | ✅ SAFE |
| V-11 | 負のグループ ID | ✅ SAFE |
| V-12 | 管理者がロールを変更できない | ✅ SAFE |

**12 SAFE、0 EXPOSED**
すべての操作前のメンバーシップチェック、`canManageMembers()`/`canChangeRoles()` ロール階層、オーナー削除ガードがすべての権限昇格と IDOR ベクターを防止します。

---

## やってはいけないこと

| アンチパターン | リスク |
|---|---|
| メンバー一覧表示前のメンバーシップチェックなし | 非メンバーがすべてのグループユーザーを列挙できる（IDOR） |
| add-member で `owner` ロール割り当てを許可する | 管理者が暗黙的にオーナーシップを奪取できる |
| change-role で `owner` ロール割り当てを許可する | 同様 — 1 回のリクエストでオーナーシップを窃取 |
| `canManageMembers()` チェックをスキップする | 一般メンバーが誰でも追加/削除できる |
| オーナー削除を許可する | グループが統治ユーザーを失う |
| `UNIQUE(group_id, user_id)` なし | 同じユーザーが 2 回追加される。重複メンバーシップレコード |
| X-User-Id に `is_numeric()` チェックのみ使う | `"1.5"` は `is_numeric` を通過する。`(int)` キャスト後に DB に対してバリデーションすること |
| アクター自身のグループでメンバーシップをチェックする（ターゲットグループではなく） | クロスグループ IDOR: グループ A のオーナーがグループ B を変更する |
| 管理者がロールを変更できるようにする | 管理者がオーナーに自己昇格する。ロール階層のバイパス |
