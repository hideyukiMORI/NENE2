# Field Trial 19 — teamlog: マルチテナンシーパターン実地検証

## Date

2026-05-20

## Baseline

- NENE2 v1.5.2（`hideyukimori/nene2: ^1.5`、Packagist から取得）
- PHP 8.4
- プロジェクト: **teamlog** — チームベースのタスク管理 API
- エンティティ: `Task`（team_id, title, status, created_at）
- テナント識別: `X-Team-Id` ヘッダー（正の整数）
- テスト: PHPUnit 13/13・PHPStan level 8・PHP-CS-Fixer 全通過
- DB: SQLite（ファイルベース、テストごとに新規生成・削除）

## Goal

マルチテナンシー（全リクエストをテナント ID でスコープ）を実装したときの摩擦を探す。

検証ポイント:
- ミドルウェアで抽出したテナント ID をリポジトリ層まで届ける方法
- `RequestIdHolder` 類似のホルダーパターンを consumer が自力で実装できるか
- `RuntimeApplicationFactory` へのテナントミドルウェア組み込み
- テナント境界を越えたアクセスが確実に拒否されるかの検証

---

## 実装ログ

1. `composer require hideyukimori/nene2:^1.5` — v1.5.2 インストール成功。
2. `TenantContext`（ミュータブルホルダー）を consumer 側で独自実装。
3. `TenantMiddleware`（PSR-15）を実装 → `RuntimeApplicationFactory::$authMiddleware` に注入。
4. `SqliteTaskRepository` で全クエリに `team_id = ?` を追加してテナント分離を実現。
5. `TaskRouteRegistrar` は `TenantContext::get()` でチームIDを取得しリポジトリに渡す。
6. テスト 13 本（基本CRUD + テナント分離検証）全通過。
7. PHPStan level 8: 0 エラー。
8. PHP-CS-Fixer: 0 件。

---

## 摩擦記録

### F-1（高）: NENE2 にリクエストスコープ値ホルダー抽象がない

**状況**: ミドルウェアで抽出したテナント ID をルートハンドラ・リポジトリ層まで届けるには、
「ミドルウェアが書き、ハンドラが読む」共有オブジェクトが必要になる。

NENE2 は `RequestIdHolder`（内部クラス `@internal`）でこのパターンを実装しているが、
consumer が再利用できない。同等のクラスを consumer 側で自力実装する必要がある。

```php
// consumer が独自に実装しなければならないホルダー
final class TenantContext
{
    private ?int $teamId = null;

    public function set(int $teamId): void  { $this->teamId = $teamId; }
    public function get(): int
    {
        if ($this->teamId === null) {
            throw new \LogicException('TenantContext has not been initialized.');
        }
        return $this->teamId;
    }
}
```

コンストラクタで同一インスタンスを `TenantMiddleware` と `TaskRouteRegistrar` 両方に注入することで
「同じリクエスト内で値が共有される」という期待が成立する。

**PHP-FPM / CLI では安全**。PHP の共有なしモデル（1リクエスト = 1プロセス）のため、
`TenantContext` が前のリクエストの値を汚染しない。ただし **Swoole / ReactPHP などの非同期ランタイム** では
複数リクエストが同一プロセスを共有するため、このパターンは安全でない（リクエスト ID に相当する
スコープ管理が別途必要になる）。

**期待する解決策**: NENE2 が汎用の `RequestScopedHolder<T>` または `RequestAttributeHolder` を
公開 API として提供し、`RequestIdHolder` と同じライフサイクル保証を持たせる。

---

### F-2（中）: `RuntimeApplicationFactory` のミドルウェアスロットが 1 つだけ

**状況**: `RuntimeApplicationFactory` には `$authMiddleware` という単一スロットしかない。

今回は `TenantMiddleware` のみなので問題なかったが、実用アプリでは以下を組み合わせる必要が生じる:

- `TenantMiddleware`（テナント抽出）
- `BearerTokenMiddleware`（JWT 認証）
- `ThrottleMiddleware`（レート制限 — 別スロットに存在）

`CompositeAuthMiddleware` は「複数の認証手段を試す」用途だが、
「順番に実行する複数の非認証ミドルウェアを積む」ユースケースには合わない。

```php
// 現状の制約: TenantMiddleware と BearerTokenMiddleware を両方積めない
$app = (new RuntimeApplicationFactory(
    $psr17,
    $psr17,
    authMiddleware: new TenantMiddleware($context, $probs),  // 1 つしか入らない
    // authMiddleware: new BearerTokenMiddleware(...),       // どちらか一方
))->create();
```

**期待する解決策**:
- `$authMiddleware` を `list<MiddlewareInterface>` に拡張してスタックを受け付ける、または
- `RuntimeApplicationFactory` に `additionalMiddlewares` パラメータを追加し、
  `ThrottleMiddleware` と同様に任意のミドルウェアを挿入できるようにする。

---

### F-3（低）: テナントスコープが PHP 同期モデルに依存した暗黙の前提

**状況**: `TenantContext` は PHP-FPM/CLI の「1リクエスト＝1プロセス」モデルを前提とする。
NENE2 自体は現在非同期ランタイムをサポートしていないが、将来的に
Swoole・ReactPHP・FrankenPHP（worker モード）で動かす場合、
ミュータブルな共有ホルダーパターンは競合状態を引き起こす。

NENE2 のドキュメントにはこの前提が明記されていない。

**期待する解決策**: `use-transactions.md` のように、スコープホルダーパターンの
「PHPの共有なしモデルへの依存」をドキュメント化する。

---

## テナント分離検証

テスト `testListTasksIsIsolatedPerTeam` と `testGetTaskByIdReturns404ForOtherTeam` で
テナント境界を明示的に検証:

```php
// Team 2 のタスクを Team 1 が見えないことを確認
$this->request('POST', '/tasks', ['title' => 'Team 2 secret'], teamId: 2);
$response = $this->request('GET', '/tasks', teamId: 1);
self::assertSame([], $payload['items'], 'Team 1 must not see Team 2 tasks');

// Team 2 が Team 1 のタスクを取得しようとすると 404
$id = $this->json($this->request('POST', '/tasks', ['title' => 'Private'], teamId: 1))['id'];
$response = $this->request('GET', "/tasks/{$id}", teamId: 2);
self::assertSame(404, $response->getStatusCode());
```

全クエリに `team_id = ?` を追加するパターンは明快だが、
リポジトリの各メソッドが `$teamId` 引数を明示的に受け取るため、
引数追加漏れによるテナント境界バイパスのリスクがある（型システムが守れない部分）。

---

## テストカバレッジ

| テスト | 検証内容 |
|---|---|
| `testMissingTeamIdHeaderReturns400` | ヘッダーなし → 400 |
| `testInvalidTeamIdHeaderReturns400` | 数値以外 → 400 |
| `testZeroTeamIdHeaderReturns400` | 0（非正）→ 400 |
| `testListTasksReturnsEmptyInitially` | GET /tasks → 200 空配列 |
| `testCreateTask` | POST /tasks → 201、team_id 付与確認 |
| `testGetTaskById` | GET /tasks/{id} → 200 |
| `testDeleteTask` | DELETE /tasks/{id} → 200、その後 404 |
| `testCreateTaskValidationRejectsEmptyTitle` | 空 title → 422 |
| `testTeam1CannotSeeTeam2Tasks` | **テナント分離**: 他チームタスクが見えない |
| `testListTasksIsIsolatedPerTeam` | **テナント分離**: 各チームが自チームのタスクのみ取得 |
| `testGetTaskByIdReturns404ForOtherTeam` | **テナント分離**: 他チームのタスク ID は 404 |
| `testDeleteTaskReturns404ForOtherTeam` | **テナント分離**: 他チームのタスクは削除不可 |
| `testGetTaskByIdReturns404ForMissingTask` | 存在しないID → 404 |

**合計**: 13/13 通過

---

## 総評

マルチテナンシーは NENE2 v1.5.2 で実装可能。`TenantContext` ホルダーパターンは
`RequestIdHolder` と同じ設計で動作する。ただし consumer が完全に自力実装する必要があり、
NENE2 には再利用可能な汎用抽象がない（F-1）。

F-2（ミドルウェアスロット1つ制約）は、テナント認証と JWT 認証を組み合わせる実用アプリで
すぐに顕在化する。`$authMiddleware` のリスト化または追加スロットが必要。

次のアクション候補:
1. F-1 → `RequestScopedHolder` 汎用抽象の検討（新 Issue）
2. F-2 → `RuntimeApplicationFactory` への複数ミドルウェアサポート（新 Issue）
3. F-3 → ドキュメント追記（既存 `use-transactions.md` に同期モデルの注記を追加）
