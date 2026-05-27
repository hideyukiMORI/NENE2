# ハウツー: 承認ワークフロー API

> **FT リファレンス**: FT68 (`NENE2-FT/approvallog`) — 承認ワークフロー API

リクエストが定義された状態を通じて移動するマルチステップ承認ワークフローを示します（Draft → Submitted → UnderReview → Approved/Rejected）。無効な遷移は 409 Conflict を返します。ステートマシンは `allowedTransitions()` メソッドを使って `ApprovalStatus` backed enum に直接エンコードされています。

---

## ワークフロー状態

```
Draft ──submit──▶ Submitted ──review──▶ UnderReview
                                              │
                                    ┌─approve─┤─reject─┐
                                    ▼                   ▼
                                 Approved            Rejected
                                                        │
                                                    ─rework─▶ Draft
```

| 状態 | 説明 |
|-------|-------------|
| `draft` | 作成済みだがまだ提出されていない |
| `submitted` | レビュー割り当て待ち |
| `under_review` | レビュアーが割り当てられレビュー中 |
| `approved` | 最終承認が得られた |
| `rejected` | 必須の理由付きで却下された |

却下されたリクエストは改訂と再提出のために手直し（`draft` に戻す）できます。承認されたリクエストはそれ以上の遷移がありません。

---

## enum にエンコードされた遷移ルール

状態遷移ルールは enum の中に存在します — リポジトリやコントローラーにはありません:

```php
enum ApprovalStatus: string
{
    case Draft       = 'draft';
    case Submitted   = 'submitted';
    case UnderReview = 'under_review';
    case Approved    = 'approved';
    case Rejected    = 'rejected';

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft       => [self::Submitted],
            self::Submitted   => [self::UnderReview],
            self::UnderReview => [self::Approved, self::Rejected],
            self::Approved    => [],
            self::Rejected    => [self::Draft],   // 手直しパス
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }
}
```

`canTransitionTo()` は遷移が有効かどうかの唯一の真実の源です。新しい許可された遷移を追加するには、このメソッドのみを更新します。

---

## ルート

| メソッド | パス | 説明 |
|--------|-------------------------------|----------------------------------------|
| `POST` | `/requests` | ドラフトリクエストを作成する |
| `GET` | `/requests` | すべてのリクエストを一覧表示する（`?status=` フィルター） |
| `GET` | `/requests/{id}` | 単一リクエストを取得する |
| `POST` | `/requests/{id}/submit` | Draft → Submitted |
| `POST` | `/requests/{id}/review` | Submitted → UnderReview（レビュアーを割り当てる） |
| `POST` | `/requests/{id}/approve` | UnderReview → Approved |
| `POST` | `/requests/{id}/reject` | UnderReview → Rejected（理由必須） |
| `POST` | `/requests/{id}/rework` | Rejected → Draft（レビュアー/ノートをクリアする） |

---

## リポジトリでの遷移ガード

リポジトリは UPDATE クエリを実行する前に `canTransitionTo()` をチェックします:

```php
public function submit(int $id, string $now): ?ApprovalRequest
{
    $req = $this->findById($id);

    if ($req === null || !$req->status->canTransitionTo(ApprovalStatus::Submitted)) {
        return null;   // 呼び出し元が null → 409 Conflict にマップする
    }

    $this->db->execute(
        "UPDATE requests SET status = 'submitted', submitted_at = ?, updated_at = ? WHERE id = ?",
        [$now, $now, $id],
    );

    return $this->findById($id);
}
```

「見つからない」と「無効な遷移」の両方に `null` を返すのは意図的な単純化です。本番では、型付き結果を返すかドメイン例外をスローすることで 404（見つからない）と 409（見つかったが無効な遷移）を区別してください。

コントローラーは `null → 409 Conflict` にマップします:

```php
private function submit(ServerRequestInterface $request): ResponseInterface
{
    $id  = (int) ($params['id'] ?? 0);
    $req = $this->repo->submit($id, $now);

    if ($req === null) {
        return $this->problems->create(
            $request,
            'conflict',
            'Request not found or cannot be submitted from its current status.',
            409,
            '',
        );
    }

    return $this->json->create($req->toArray());
}
```

---

## 却下には理由が必要

`reject` 遷移は `reviewer` と `note` の両方が必要です:

```php
private function reject(ServerRequestInterface $request): ResponseInterface
{
    $reviewer = isset($body['reviewer']) && is_string($body['reviewer']) ? trim($body['reviewer']) : '';
    $note     = isset($body['note']) && is_string($body['note']) ? trim($body['note']) : '';

    if ($reviewer === '' || $note === '') {
        $errors = [];
        if ($reviewer === '') {
            $errors[] = ['field' => 'reviewer', 'code' => 'required', 'message' => 'reviewer is required.'];
        }
        if ($note === '') {
            $errors[] = ['field' => 'note', 'code' => 'required', 'message' => 'note (rejection reason) is required.'];
        }

        return $this->problems->create($request, 'validation-failed', 'Validation Failed', 422, null, compact('errors'));
    }
    // ...
}
```

理由なしの却下は拒否されます（422）。ノートなしの承認は許可されます — `note` フィールドは承認にはオプションです。

---

## 手直し: レビュー状態のクリア

却下されたリクエストが手直しされると、レビュアーとレビューノートがクリアされ、次のレビュアーが新鮮な状態で開始できます:

```php
// リポジトリ: 手直し（Rejected → Draft）
$this->db->execute(
    "UPDATE requests SET status = 'draft', reviewer = NULL, review_note = NULL, reviewed_at = NULL, updated_at = ? WHERE id = ?",
    [$now, $id],
);
```

`submitted_at` タイムスタンプは保持されます — リクエストが最初に提出されたときを記録し、現在のサイクルではありません。

---

## スキーマ

```sql
CREATE TABLE requests (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    title        TEXT    NOT NULL,
    submitter    TEXT    NOT NULL,
    status       TEXT    NOT NULL DEFAULT 'draft',
    reviewer     TEXT,              -- レビュー開始まで NULL
    review_note  TEXT,             -- レビューまで NULL
    submitted_at TEXT,             -- 提出まで NULL
    reviewed_at  TEXT,             -- 承認/却下まで NULL
    created_at   TEXT    NOT NULL,
    updated_at   TEXT    NOT NULL
);
```

Nullable カラム（`reviewer`、`review_note`、`submitted_at`、`reviewed_at`）は手直し時に `NULL` にクリアされ、`rework_count` カラムを追加せずにスキーマをクリーンに保ちます。

> **強化**: enum 値に一致させるための DB レベルのバックストップとして `CHECK(status IN ('draft','submitted','under_review','approved','rejected'))` を追加してください。

---

## 一覧エンドポイントのステータスフィルター

```php
private function list(ServerRequestInterface $request): ResponseInterface
{
    $params    = $request->getQueryParams();
    $statusRaw = isset($params['status']) && is_string($params['status']) ? $params['status'] : null;
    $status    = $statusRaw !== null ? ApprovalStatus::tryFrom($statusRaw) : null;

    if ($statusRaw !== null && $status === null) {
        return $this->problems->create($request, 'validation-failed', 'Validation Failed', 422, null, [
            'errors' => [['field' => 'status', 'code' => 'invalid_value', 'message' => 'Invalid status value.']],
        ]);
    }

    $requests = $this->repo->listByStatus($status);
    // ...
}
```

`ApprovalStatus::tryFrom()` は未知のステータス文字列に `null` を返す → 422。`$statusRaw === null`（フィルターなし）の場合、すべてのリクエストが返されます。

---

## 新しい遷移の追加

非終端状態から到達できる `cancelled` 状態を追加するには:

1. `ApprovalStatus` に `case Cancelled = 'cancelled';` を追加する。
2. `Draft`、`Submitted`、`UnderReview` の `allowedTransitions()` を更新して `self::Cancelled` を含める。
3. `POST /requests/{id}/cancel` ルートとハンドラーを追加する。
4. リポジトリに DB UPDATE を書く。
5. スキーマ `CHECK` 制約を更新する（追加した場合）。

enum が唯一の真実の源です — 遷移ガードを追加するために他のファイルを変更する必要はありません。

---

## 関連ハウツー

- [`content-draft-lifecycle.md`](content-draft-lifecycle.md) — draft → publish ライフサイクル（よりシンプルなステートマシン）
- [`media-watchlist.md`](media-watchlist.md) — `tryFrom()` を使った backed enum 検証
- [`add-custom-route.md`](add-custom-route.md) — POST アクションエンドポイントパターン
- [`multi-step-workflow.md`](multi-step-workflow.md) — 汎用マルチステップワークフローパターン
