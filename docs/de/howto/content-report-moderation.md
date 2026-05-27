# Content-Meldung und Moderation

Implementierungsleitfaden für ein Inhalts-Melde- und Moderationssystem für Artikel.
Erläutert RBAC (rollenbasierte Zugriffskontrolle), IDOR-Prävention, idempotente Meldungen und einseitige Statusübergänge.

## Überblick

- Benutzer melden Artikel (idempotent: erneutes Melden desselben Artikels gibt 200 zurück)
- Nur Moderatoren können die Meldungsliste einsehen, Meldungen lösen oder abweisen
- Melder können nur ihre eigenen Meldungen einsehen (IDOR-Prävention)
- Status folgt dem einseitigen Übergang: `pending → resolved / dismissed`

## Endpunkte

| Methode | Pfad | Beschreibung |
|---------|------|--------------|
| `POST` | `/reports` | Artikel melden (idempotent) |
| `GET` | `/reports` | Meldungsliste (nur Moderatoren) |
| `GET` | `/reports/{id}` | Meldungsdetails (eigene Meldung oder Moderator) |
| `PUT` | `/reports/{id}/resolve` | Meldung lösen (nur Moderatoren) |
| `PUT` | `/reports/{id}/dismiss` | Meldung abweisen (nur Moderatoren) |

## Datenbankdesign

```sql
CREATE TABLE users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    role TEXT NOT NULL DEFAULT 'user',
    created_at TEXT NOT NULL,
    CHECK (role IN ('user', 'moderator'))
);

CREATE TABLE reports (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    reporter_id INTEGER NOT NULL,
    article_id INTEGER NOT NULL,
    reason TEXT NOT NULL,
    details TEXT,
    status TEXT NOT NULL DEFAULT 'pending',
    resolved_by INTEGER,
    resolved_at TEXT,
    resolution_note TEXT,
    created_at TEXT NOT NULL,
    UNIQUE (reporter_id, article_id),
    CHECK (status IN ('pending', 'resolved', 'dismissed')),
    CHECK (reason IN ('spam', 'harassment', 'misinformation', 'other')),
    FOREIGN KEY (reporter_id) REFERENCES users(id),
    FOREIGN KEY (article_id) REFERENCES articles(id),
    FOREIGN KEY (resolved_by) REFERENCES users(id)
);
```

`UNIQUE (reporter_id, article_id)` bildet die Grundlage für idempotentes Hinzufügen.
`CHECK`-Constraints gewährleisten gültige Status und Meldegründe auf DB-Ebene.

## Idempotente Meldung

```php
$existing = $this->repository->findReportByReporterAndArticle($actorId, $articleId);
if ($existing !== null) {
    return $this->responseFactory->create($this->formatReport($existing), 200);
}

$id = $this->repository->createReport($actorId, $articleId, $reason, $details, date('c'));
$report = $this->repository->findReportById($id);

return $this->responseFactory->create($this->formatReport($report ?? []), 201);
```

Rückgabewert `201` = neue Meldung, `200` = vorhandene Meldung (Aufrufer unterscheidet anhand des Status).

## RBAC — Rollenprüfung

```php
$actor = $this->repository->findUserById($actorId);
if ($actor === null || $actor['role'] !== 'moderator') {
    return $this->responseFactory->create(['error' => 'moderator role required'], 403);
}
```

Moderator-exklusive Endpunkte validieren die Rolle am Anfang des Handlers.

## IDOR-Prävention

```php
$isModerator = $actor !== null && $actor['role'] === 'moderator';
$isReporter  = (int) $report['reporter_id'] === $actorId;

if (!$isModerator && !$isReporter) {
    return $this->responseFactory->create(['error' => 'access denied'], 403);
}
```

`GET /reports/{id}` ist nur für "eigene Meldungen" oder "Moderatoren" zugänglich.
`reporter_id` wird nicht aus dem Request-Body entnommen, sondern immer aus dem `X-User-Id`-Header gesetzt.

## Statusübergang (einseitig)

```php
if ($report['status'] !== 'pending') {
    return $this->responseFactory->create([
        'error' => 'report is not pending',
        'current_status' => $report['status'],
    ], 422);
}
```

Meldungen, die einmal zu `resolved` oder `dismissed` übergegangen sind, können nicht erneut bearbeitet werden.
Der DB-`CHECK`-Constraint sichert die Validierung auf Anwendungsebene ab.

## Pfadparameter abrufen

Der NENE2-Router speichert Path-Params im `nene2.route.parameters`-Attribut.

```php
// Korrekte Methode
$id = (int) Router::param($request, 'id');

// Falsch (getAttribute('id') funktioniert nicht direkt)
$id = (int) $request->getAttribute('id');
```

## Sicherheit der reporter_id

```php
// createReport: actorId wurde aus dem X-User-Id-Header verifiziert
$id = $this->repository->createReport($actorId, $articleId, $reason, $details, date('c'));
```

Das `reporter_id`-Feld im Request-Body wird ignoriert; stattdessen wird die authentifizierte `X-User-Id` verwendet. Dadurch wird Identitätsdiebstahl anderer Benutzer verhindert.

## Beispielantwort für POST /reports

```json
{
  "id": 1,
  "reporter_id": 1,
  "article_id": 3,
  "reason": "spam",
  "details": "This article contains repeated spam links",
  "status": "pending",
  "resolved_by": null,
  "resolved_at": null,
  "resolution_note": null,
  "created_at": "2026-05-21T12:00:00+00:00"
}
```

## Beispielantwort für PUT /reports/{id}/resolve

```json
{
  "id": 1,
  "reporter_id": 1,
  "article_id": 3,
  "reason": "spam",
  "details": "...",
  "status": "resolved",
  "resolved_by": 3,
  "resolved_at": "2026-05-21T13:00:00+00:00",
  "resolution_note": "Article removed for TOS violation",
  "created_at": "2026-05-21T12:00:00+00:00"
}
```

## Beispielantwort für GET /reports (Moderator)

```json
{
  "reports": [
    {
      "id": 2,
      "reporter_id": 2,
      "article_id": 5,
      "reason": "harassment",
      "status": "pending",
      ...
    }
  ],
  "count": 1
}
```
