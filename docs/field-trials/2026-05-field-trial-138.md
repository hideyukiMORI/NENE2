# Field Trial 138 — グループメンバーシップ管理

**Date**: 2026-05-21  
**App**: `grouplog`  
**Path**: `/home/xi/docker/NENE2-FT/grouplog/`  
**NENE2 version**: ^1.5  
**Release**: v1.5.72  
**Special**: 脆弱性診断 (3FT ごと) + MySQL 統合テスト (5FT ごと)

---

## What was built

グループ作成・メンバー招待・ロール管理・脱退を実装した。

| Endpoint | 説明 |
|---|---|
| `POST /users` | ユーザー作成 |
| `POST /groups` | グループ作成（作成者は owner として自動追加） |
| `GET /groups/{groupId}/members` | メンバー一覧（メンバーのみ） |
| `POST /groups/{groupId}/members` | メンバー追加（owner/admin のみ・role: member or admin） |
| `DELETE /groups/{groupId}/members/{userId}` | メンバー削除（owner/admin が他者を削除・本人は自己脱退可） |
| `PUT /groups/{groupId}/members/{userId}/role` | ロール変更（owner のみ） |

---

## Architecture decisions

### `groups` は MySQL 予約語 → `user_groups` を使用

MySQL の `GROUP BY` 句で `groups` は予約語として扱われる。CREATE TABLE に使うと構文エラーになる。`user_groups` に改名することで SQLite・MySQL 両方で動作する。

### MemberRole enum に権限メソッドを持たせる

```php
enum MemberRole: string {
    public function canManageMembers(): bool { ... }
    public function canChangeRoles(): bool { ... }
}
```

ハンドラーに `if ($role === 'owner' || $role === 'admin')` の文字列比較を書かない。enum のメソッドが権限の単一の真実源となる。

### Owner の自動追加

`createGroup()` の中でグループ INSERT の直後に `memberships` へ owner を INSERT する。アプリコードが owner の追加を忘れられない構造。

### Owner は削除・ロール変更不可

- `DELETE` で owner を指定 → 422
- `PUT /role` で owner ロールを付与しようとする → 422 (add-member API では 422)
- ロール変更は owner のみ実行可能（admin は不可）

---

## Test results

| Suite | Tests | Result |
|---|---|---|
| `GroupTest.php` | 21 | Pass |
| `VulnTest.php` | 12 | Pass |
| `MysqlGroupTest.php` | 5 | Pass |
| **Total** | **38** | **Pass** |

---

## Vulnerability assessment (FT138)

| ID | Attack | Expected | Result |
|---|---|---|---|
| VULN-A | IDOR: 非メンバーがメンバー一覧を取得 | 403 | Pass |
| VULN-B | IDOR: 非メンバーがメンバーを追加 | 403 | Pass |
| VULN-C | 一般 member がメンバーを追加しようとする | 403 | Pass |
| VULN-D | admin が owner ロールを付与しようとする | not 200 | Pass |
| VULN-E | member が自分を admin に昇格しようとする | 403 | Pass |
| VULN-F | group owner を削除しようとする | 422 | Pass |
| VULN-G | X-User-Id なしでグループ作成 | not 201 | Pass |
| VULN-H | 非数値の X-User-Id | not 200 | Pass |
| VULN-I | グループ名に SQL インジェクション | 201 (verbatim) | Pass |
| VULN-J | 別グループのメンバー操作（cross-group） | 403 | Pass |
| VULN-K | 負の groupId | 404 | Pass |
| VULN-L | admin がロール変更を試みる | 403 | Pass |

**全12件 Pass。脆弱性なし。**

---

## MySQL integration tests (FT138)

`MysqlGroupTest.php` の 5 テストが MySQL 環境で通過。

| テスト | 確認内容 |
|---|---|
| `testMysqlCreateGroupAndListMembers` | グループ作成 → owner が自動追加される |
| `testMysqlAddAndRemoveMember` | メンバー追加・削除・一覧確認 |
| `testMysqlDuplicateMemberReturns409` | 重複追加 → 409 |
| `testMysqlRoleChange` | ロール変更 → 200 + 新ロール |
| `testMysqlNonMemberCannotViewMembers` | 非メンバー → 403 |

MySQL テストのキーポイント:
- `FOREIGN_KEY_CHECKS = 0` で FK 依存テーブルを安全に DROP
- `getenv()` は `string|false` を返すため `=== false` チェックが必須（PHPStan level 8）
- `schema.mysql.sql` で `VARCHAR`・`AUTO_INCREMENT`・`ENGINE=InnoDB`・`CONSTRAINT chk_role` を使用

---

## Common pitfalls encountered

| Pitfall | Fix |
|---|---|
| `groups` テーブル名が MySQL でエラー | `user_groups` に改名 |
| `getenv()` の型チェック不足 | `=== false` を先にチェック、その後 `=== ''` |
| MySQL FK 制約で DROP TABLE が失敗 | `SET FOREIGN_KEY_CHECKS = 0` を前置 |

---

## Developer Experience (DX) Review

### Persona 1 — 初心者 Web 開発者（PHP 学習中）

「グループとメンバーシップで2テーブルに分かれていることは理解できた。でも `groups` テーブルという名前が MySQL で使えないとは知らなかった。フレームワークのドキュメントを読んでいたら気づけたかもしれないけど、エラーメッセージだけ見てもすぐには原因を特定できなかった。howto の最初に警告として書いてあるのが助かった。`MemberRole::canManageMembers()` という書き方はすごく読みやすいと感じた。」

★★★☆☆ — MySQL 予約語のトラップは初心者がはまりやすい落とし穴

### Persona 2 — Laravel 経験者（NENE2 初学）

「Eloquent だったら `belongsToMany` でリレーションを定義するだけでメンバーシップはできる。NENE2 はすべて生 SQL なので JOIN も自分で書く必要があった。でも Repository パターンで SQL がきれいに分離されているのは好感が持てる。enum に権限チェックメソッドを持たせるパターンは Laravel でも真似できそう。」

★★★★☆ — 生 SQL の明示性は高く評価。ORM に慣れた人でも理解しやすい構造

### Persona 3 — セキュリティエンジニア

「12 件の脆弱性テストが全部 Pass しているのは良い。特に VULN-J（クロスグループ操作）と VULN-L（admin のロール変更禁止）の分離は正確に実装されている。`canChangeRoles()` が Owner のみ `true` を返す設計は、最小権限の原則に沿っている。SQL インジェクション（VULN-I）がプリペアドステートメントで確実に防がれているのも確認できた。MySQL でも同じテストが通るのは特に重要。」

★★★★★ — 権限分離が明確で安全。脆弱性テストの網羅性が高い

### Persona 4 — フロントエンド開発者（API 利用者）

「`GET /groups/{groupId}/members` が一覧を返すのは自然。`X-User-Id` がなければ 403 というのは実装するときに混乱したが、howto を読んで理解できた。`role` フィールドが `owner`/`admin`/`member` の 3 段階あるのはフロント側でも使いやすい。削除系のレスポンスが 204 No Content なのは HTTP の作法として正しい。」

★★★★☆ — シンプルな API 設計。ヘッダー認証の説明がもう少し充実すると嬉しい

### Persona 5 — インフラ・DevOps エンジニア

「MySQL と SQLite の両方でテストが通ることが確認済みなのは本番移行の安心感につながる。`SET FOREIGN_KEY_CHECKS = 0` のパターンをテストコードに明示しているのが気に入った。テスト環境で MySQL コンテナが必要になるが、`MYSQL_HOST` 環境変数なしで自動スキップされるので CI で余計な手間がかからない。FK 制約のあるスキーマは `user_groups` と `memberships` の drop 順が正しく制御されている。」

★★★★★ — MySQL テストが環境変数ゲート付きで CI フレンドリー

### Persona 6 — プロダクトマネージャー

「グループ機能は多くの SaaS に必要な基盤機能。owner・admin・member の 3 ロールはシンプルで理解しやすい。owner が自動追加される仕組みは UX 上正しい（作成者がメンバー一覧に表示されないバグを防ぐ）。自己脱退（self-leave）と admin による強制削除が同一エンドポイントで制御されているのは API がシンプルで良い。owner を削除できない制約は運営上も重要。」

★★★★☆ — 権限設計がプロダクト要件に合っている。グループ名変更・削除機能は次のステップ

---

## Howto

`docs/howto/group-membership-management.md`
