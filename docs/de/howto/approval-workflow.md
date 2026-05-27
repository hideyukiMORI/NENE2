# How-to: Genehmigungs-Workflow-API

> **FT-Referenz**: FT68 (`NENE2-FT/approvallog`) — Genehmigungs-Workflow-API

Demonstriert einen mehrstufigen Genehmigungs-Workflow, bei dem eine Anfrage durch definierte
Zustände (Draft → Submitted → UnderReview → Approved/Rejected) läuft. Ungültige Übergänge
geben 409 Conflict zurück. Die Zustandsmaschine ist direkt im backed-Enum `ApprovalStatus`
über eine `allowedTransitions()`-Methode kodiert.

---

## Workflow-Zustände

```
Draft ──submit──▶ Submitted ──review──▶ UnderReview
                                              │
                                    ┌─approve─┤─reject─┐
                                    ▼                   ▼
                                 Approved            Rejected
                                                        │
                                                    ─rework─▶ Draft
```

| Zustand | Beschreibung |
|-------|-------------|
| `draft` | Erstellt, aber noch nicht eingereicht |
| `submitted` | Wartet auf Zuweisung eines Prüfers |
| `under_review` | Prüfer zugewiesen und in Prüfung |
| `approved` | Endgültige Genehmigung erteilt |
| `rejected` | Abgelehnt mit Pflichtangabe eines Grundes |

Eine abgelehnte Anfrage kann zur Überarbeitung (zurück zu `draft`) und erneuten Einreichung zurückgesandt werden.
Eine genehmigte Anfrage hat keine weiteren Übergänge.

---

## Im Enum kodierte Übergangsregeln

Zustandsübergangsregeln befinden sich im Enum — nicht im Repository oder Controller:

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
            self::Rejected    => [self::Draft],   // Überarbeitungspfad
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }
}
```

`canTransitionTo()` ist die einzige Wahrheitsquelle dafür, ob ein Übergang gültig ist.
Das Hinzufügen eines neuen erlaubten Übergangs bedeutet, nur diese eine Methode zu aktualisieren.

---

## Routen

| Methode | Pfad | Beschreibung |
|--------|-------------------------------|----------------------------------------|
| `POST` | `/requests` | Entwurfsanfrage erstellen |
| `GET` | `/requests` | Alle Anfragen auflisten (`?status=`-Filter) |
| `GET` | `/requests/{id}` | Einzelne Anfrage abrufen |
| `POST` | `/requests/{id}/submit` | Draft → Submitted |
| `POST` | `/requests/{id}/review` | Submitted → UnderReview (weist Prüfer zu) |
| `POST` | `/requests/{id}/approve` | UnderReview → Approved |
| `POST` | `/requests/{id}/reject` | UnderReview → Rejected (Grund erforderlich) |
| `POST` | `/requests/{id}/rework` | Rejected → Draft (löscht Prüfer/Notiz) |

---

## Übergänge im Repository absichern

Das Repository prüft `canTransitionTo()` vor der Ausführung der UPDATE-Abfrage:

```php
public function submit(int $id, string $now): ?ApprovalRequest
{
    $req = $this->findById($id);

    if ($req === null || !$req->status->canTransitionTo(ApprovalStatus::Submitted)) {
        return null;   // Aufrufer mappt null → 409 Conflict
    }

    $this->db->execute(
        "UPDATE requests SET status = 'submitted', submitted_at = ?, updated_at = ? WHERE id = ?",
        [$now, $now, $id],
    );

    return $this->findById($id);
}
```

`null` für sowohl "nicht gefunden" als auch "ungültiger Übergang" zurückzugeben ist eine bewusste
Vereinfachung. In der Produktion zwischen 404 (nicht gefunden) und 409 (gefunden aber ungültiger
Übergang) unterscheiden, indem ein typisiertes Ergebnis zurückgegeben oder Domain-Exceptions geworfen werden.

Der Controller mappt `null → 409 Conflict`:

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

## Ablehnung erfordert einen Grund

Der `reject`-Übergang erfordert sowohl `reviewer` als auch `note`:

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

Ablehnen ohne Grund wird mit 422 abgelehnt. Genehmigen ohne Notiz ist erlaubt — das `note`-Feld ist für Genehmigungen optional.

---

## Rework: Prüfungsstatus zurücksetzen

Wenn eine abgelehnte Anfrage überarbeitet wird, werden Prüfer und Prüfungsnotiz gelöscht, damit der nächste Prüfer neu beginnt:

```php
// Repository: rework (Rejected → Draft)
$this->db->execute(
    "UPDATE requests SET status = 'draft', reviewer = NULL, review_note = NULL, reviewed_at = NULL, updated_at = ? WHERE id = ?",
    [$now, $id],
);
```

Der `submitted_at`-Zeitstempel bleibt erhalten — er erfasst, wann die Anfrage zuerst eingereicht wurde, nicht den aktuellen Zyklus.

---

## Schema

```sql
CREATE TABLE requests (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    title        TEXT    NOT NULL,
    submitter    TEXT    NOT NULL,
    status       TEXT    NOT NULL DEFAULT 'draft',
    reviewer     TEXT,              -- NULL bis Prüfung beginnt
    review_note  TEXT,             -- NULL bis Prüfung erfolgt
    submitted_at TEXT,             -- NULL bis Einreichung
    reviewed_at  TEXT,             -- NULL bis Genehmigung/Ablehnung
    created_at   TEXT    NOT NULL,
    updated_at   TEXT    NOT NULL
);
```

Nullable-Spalten (`reviewer`, `review_note`, `submitted_at`, `reviewed_at`) werden bei rework auf `NULL` zurückgesetzt, was das Schema sauber hält ohne eine `rework_count`-Spalte hinzuzufügen.

> **Erweiterung**: ein `CHECK(status IN ('draft','submitted','under_review','approved','rejected'))`
> als DB-Ebene-Sicherheitsnetz hinzufügen, das den Enum-Werten entspricht.

---

## Statusfilter am Listen-Endpunkt

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

`ApprovalStatus::tryFrom()` gibt für unbekannte Status-Strings `null` zurück → 422. Wenn
`$statusRaw === null` (kein Filter), werden alle Anfragen zurückgegeben.

---

## Neuen Übergang hinzufügen

Um einen `cancelled`-Zustand hinzuzufügen, der von jedem nicht-terminalen Zustand erreicht werden kann:

1. `case Cancelled = 'cancelled';` zu `ApprovalStatus` hinzufügen.
2. `allowedTransitions()` für `Draft`, `Submitted` und `UnderReview` aktualisieren, um `self::Cancelled` einzuschließen.
3. `POST /requests/{id}/cancel`-Route und Handler hinzufügen.
4. Das DB-UPDATE im Repository schreiben.
5. Das Schema-`CHECK`-Constraint aktualisieren (falls vorhanden).

Das Enum ist die einzige Wahrheitsquelle — keine anderen Dateien müssen geändert werden, um den Übergangsschutz hinzuzufügen.

---

## Verwandte Anleitungen

- [`content-draft-lifecycle.md`](content-draft-lifecycle.md) — draft → publish-Lebenszyklus (einfachere Zustandsmaschine)
- [`media-watchlist.md`](media-watchlist.md) — backed-Enum-Validierung mit `tryFrom()`
- [`add-custom-route.md`](add-custom-route.md) — POST-Aktions-Endpunkt-Muster
- [`multi-step-workflow.md`](multi-step-workflow.md) — generische mehrstufige Workflow-Muster
