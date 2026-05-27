# How-to: Inhalts-Meldesystem

> **FT-Referenz**: FT289 (`NENE2-FT/reportlog`) — Inhaltsberichte: Allowlisted-Gründe (ReportReason-Enum), `UNIQUE(reporter_id, article_id)` mit idempotenten 200 bei Duplikat, pending→resolved/dismissed-Zustandsmaschine, Nur-Moderator-Liste/Lösen/Abweisen, DB-Level-`CHECK`-Constraints, 32 Tests / 58 Assertions PASS.

Diese Anleitung zeigt, wie ein Inhalts-Meldesystem aufgebaut wird, bei dem Benutzer Inhalte markieren und Moderatoren Berichte prüfen und lösen.

## Schema

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

DB-Level-`CHECK`-Constraints erzwingen Enum-Werte, auch wenn die Anwendungsvalidierung umgangen wird.

## Endpunkte

| Methode | Pfad | Auth | Beschreibung |
|---------|------|------|--------------|
| `POST` | `/reports` | `X-User-Id` | Bericht einreichen |
| `GET` | `/reports` | Moderator | Alle Berichte auflisten |
| `GET` | `/reports/{id}` | Melder oder Moderator | Bericht abrufen |
| `PUT` | `/reports/{id}/resolve` | Moderator | Bericht lösen |
| `PUT` | `/reports/{id}/dismiss` | Moderator | Bericht abweisen |

## ReportReason-Enum

```php
enum ReportReason: string
{
    case Spam         = 'spam';
    case Harassment   = 'harassment';
    case Misinformation = 'misinformation';
    case Other        = 'other';
}
```

`ReportReason::tryFrom($reasonStr)` lehnt unbekannte Werte ab. Der Handler gibt gültige Gründe in der Fehlerantwort zurück:

```php
$reason = ReportReason::tryFrom($reasonStr);
if ($reason === null) {
    $validReasons = array_map(fn(ReportReason $r) => $r->value, ReportReason::cases());
    return $this->responseFactory->create(['error' => 'invalid reason', 'valid_reasons' => $validReasons], 422);
}
```

## Idempotente Berichtseinreichung

Wenn ein Benutzer denselben Artikel bereits gemeldet hat, wird der vorhandene Bericht mit 200 zurückgegeben (nicht 201):

```php
$existing = $this->repository->findReportByReporterAndArticle($actorId, $articleId);
if ($existing !== null) {
    return $this->responseFactory->create($this->formatReport($existing), 200);
}

// Erstes Mal: 201 Created
$id = $this->repository->createReport(...);
return $this->responseFactory->create($this->formatReport(...), 201);
```

`UNIQUE(reporter_id, article_id)` sichert dies auf DB-Ebene ab. Die Anwendung prüft zuerst, um eine benutzerfreundliche Antwort zurückzugeben, aber der UNIQUE-Constraint ist das Sicherheitsnetz.

## Status-Lebenszyklus

```
pending ──→ resolved (Moderator-Aktion)
       └──→ dismissed (Moderator-Aktion)
```

Einmal gelöst oder abgewiesen kann ein Bericht nicht mehr übergehen. Der Versuch, einen nicht-ausstehenden Bericht zu ändern, gibt 422 zurück:

```php
if ($report['status'] !== 'pending') {
    return $this->responseFactory->create([
        'error' => 'report is not pending',
        'current_status' => $report['status'],
    ], 422);
}
```

## Moderator-Rollenprüfung

```php
$actor = $this->repository->findUserById($actorId);
if ($actor === null || $actor['role'] !== 'moderator') {
    return $this->responseFactory->create(['error' => 'moderator role required'], 403);
}
```

Die Rolle wird in der `users`-Tabelle gespeichert und bei jeder privilegierten Operation geprüft. Ein DB-Level-`CHECK (role IN ('user', 'moderator'))` verhindert das Einfügen ungültiger Rollen.

## Zugriffskontrolle: Melder vs. Moderator

GET `/reports/{id}` ist sowohl für den ursprünglichen Melder als auch für Moderatoren zugänglich:

```php
$isModerator = $actor['role'] === 'moderator';
$isReporter  = (int)$report['reporter_id'] === $actorId;

if (!$isModerator && !$isReporter) {
    return $this->responseFactory->create(['error' => 'access denied'], 403);
}
```

Melder können ihre eigenen Berichte einsehen, um den Status zu verfolgen. Moderatoren sehen alle Berichte.

## Lösung mit Prüfpfad

```php
$this->repository->updateReportStatus($id, $newStatus, $actorId, date('c'), $note);
```

`resolved_by` (Moderator-ID), `resolved_at` (Zeitstempel) und `resolution_note` (optional) erstellen einen Prüfpfad für jede Moderationsaktion.

---

## Was Sie NICHT tun sollten

| Anti-Pattern | Risiko |
|---|---|
| Freiform-Grund-String akzeptieren | Tippfehler, Injection, unendliche Kategorien; Enum-Allowlist verwenden |
| Kein `UNIQUE(reporter_id, article_id)` | Gleicher Benutzer reicht Dutzende Berichte für gleichen Artikel ein; aufgeblähte Warteschlange |
| 409 bei doppeltem Bericht zurückgeben | Retry-sichere Idempotenz: Duplikat → 200 mit vorhandenem Bericht, kein Fehler |
| Übergang von resolved/dismissed erlauben | Gelöster Bericht wird erneut geöffnet; Prüfpfad wird unzuverlässig |
| Keine Moderator-Rollenprüfung bei Liste/Lösen | Beliebiger Benutzer liest alle Berichte; Datenschutzverletzung + Prüfungsumgehung |
| Eigenen Bericht an anderen Benutzer zurückgeben | IDOR — immer prüfen, ob Melder === Akteur oder Akteur Moderator ist |
| Kein `resolution_note`-Feld | Moderatoren können nicht kommunizieren, warum ein Bericht abgewiesen vs. gelöst wurde |
| Kein `resolved_by`-Feld | Kann nicht auditiert werden, welcher Moderator gehandelt hat |
| Nur DB-`CHECK`, keine App-Validierung | DB löst Exception bei ungültigem Grund aus; Benutzer erhält 500 statt 422 |
