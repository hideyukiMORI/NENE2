# So bauen Sie ein Systemankündigungs-Management

> **Muster bewiesen durch FT190 announcelog** — Zeitbasierte Systemankündigungen mit
> Admin-Key-Authentifizierung, Pro-Benutzer-Ausblendung und Prioritätssortierung. 38 Tests / 93 Assertions PASS.

---

## Was diese Anleitung abdeckt

Eine Systemankündigungs-API zum Übertragen von Wartungshinweisen, Feature-Updates und Alarmen:

1. **Erstellen/Aktualisieren/Löschen** — Nur-Admin-Operationen via zeitkonstantem Key-Vergleich
2. **Aktive auflisten** — UTC-zeitgefilterter nach `starts_at` / `ends_at`
3. **Ausblenden** — Pro-Benutzer-Opt-out, persistent als idempotentes UPSERT

---

## Schema

```sql
CREATE TABLE announcements (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    title      TEXT    NOT NULL,
    body       TEXT    NOT NULL,
    starts_at  TEXT    NOT NULL,   -- ISO 8601 UTC
    ends_at    TEXT    NOT NULL,   -- ISO 8601 UTC
    priority   INTEGER NOT NULL DEFAULT 0,  -- höher = wird zuerst angezeigt
    created_at TEXT    NOT NULL,
    updated_at TEXT    NOT NULL
);

CREATE TABLE announcement_dismissals (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id         INTEGER NOT NULL,
    announcement_id INTEGER NOT NULL,
    dismissed_at    TEXT    NOT NULL,
    UNIQUE(user_id, announcement_id)
);
```

`UNIQUE(user_id, announcement_id)` ermöglicht idempotentes Ausblenden. `starts_at` / `ends_at` sind
ISO-8601-Zeichenketten — lexikografischer Vergleich funktioniert korrekt für UTC-Datetimes.

---

## API

| Methode | Pfad | Auth | Beschreibung |
|---|---|---|---|
| `POST`   | `/announcements` | `X-Admin-Key` | Ankündigung erstellen (201) |
| `PUT`    | `/announcements/{id}` | `X-Admin-Key` | Ankündigung aktualisieren (200) |
| `DELETE` | `/announcements/{id}` | `X-Admin-Key` | Ankündigung löschen (200) |
| `GET`    | `/announcements` | optionales `X-User-Id` | Aktuell aktive Ankündigungen auflisten |
| `POST`   | `/announcements/{id}/dismiss` | `X-User-Id` | Für diesen Benutzer ausblenden (200) |

---

## Kernmuster: Zeitkonstante Admin-Key-Verifizierung

```php
private function isAdmin(ServerRequestInterface $request): bool
{
    // Leere adminKey-Konfiguration bedeutet kein Admin-Zugriff — fail-closed
    if ($this->adminKey === '') {
        return false;
    }

    $provided = $request->getHeaderLine('X-Admin-Key');

    // hash_equals: zeitkonstant — verhindert Timing-Angriffe beim Key-Vergleich
    return $provided !== '' && hash_equals($this->adminKey, $provided);
}
```

**Warum nicht `===`:** Zeichenkettenvergleich schließt beim ersten Mismatch kurz. Ein Angreifer kann
Timing-Unterschiede messen, um Teil-Präfix-Übereinstimmungen zu finden, dann Zeichen für Zeichen
brute-forcen. `hash_equals()` benötigt unabhängig davon, wo der Mismatch ist, konstante Zeit.

**Fail-Closed:** Eine leere `adminKey`-Konfiguration gibt immer `false` zurück — es gibt keinen
versehentlichen „offenen Admin"-Modus.

---

## Kernmuster: UTC-zeitbasierte Filterung

```php
// Aktuell aktive Ankündigungen auflisten
$now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM);

SELECT ... FROM announcements
WHERE starts_at <= :now AND ends_at > :now
ORDER BY priority DESC, id DESC
```

ISO-8601-Zeichenketten in UTC sortieren lexikografisch korrekt — `'2025-06-01T...' > '2025-05-01T...'`.
Stets UTC in der Datenbank verwenden.

Das `ends_at > :now` (strikte Größer-als) bedeutet, dass eine Ankündigung genau bei `ends_at` abläuft,
nicht eine Sekunde danach.

---

## Kernmuster: Pro-Benutzer-Ausblendung (Idempotent)

```php
// UNIQUE(user_id, announcement_id) ermöglicht sichere wiederholte Ausblende-Aufrufe
INSERT INTO announcement_dismissals (user_id, announcement_id, dismissed_at)
VALUES (:user_id, :announcement_id, :now)
ON CONFLICT(user_id, announcement_id) DO NOTHING
```

Ein Benutzer, der `POST /announcements/5/dismiss` zweimal aufruft, ist sicher — der zweite Aufruf
gelingt stillschweigend. Der Client muss nie zuerst prüfen.

---

## Kernmuster: Optionaler Benutzerkontext bei Liste

```php
// Ohne X-User-Id: alle aktiven Ankündigungen anzeigen
// Mit X-User-Id: ausgeblendete für diesen Benutzer ausschließen

// Ohne Benutzer:
WHERE a.starts_at <= :now AND a.ends_at > :now

// Mit Benutzer (LEFT JOIN + IS NULL-Filter):
LEFT JOIN announcement_dismissals d
  ON d.announcement_id = a.id AND d.user_id = :user_id
WHERE a.starts_at <= :now AND a.ends_at > :now
  AND d.id IS NULL
```

Dieser einzelne `GET /announcements`-Endpunkt behandelt sowohl nicht-authentifizierte (Monitoring,
Admin-Ansicht) als auch authentifizierte (UI zeigt relevante Banner) Anwendungsfälle.

---

## Kernmuster: ends_at muss nach starts_at liegen

```php
// Serverseitige Validierung — nicht nur Client-Vertrauen
if ($body['ends_at'] <= $body['starts_at']) {
    return 'ends_at must be after starts_at.';
}
```

Eine Ankündigung mit `ends_at <= starts_at` ist unmittelbar nach der Erstellung unsichtbar —
validieren und ablehnen anstatt stillschweigend fehlerhafte Daten zu akzeptieren.

---

## Antwort-Design

| Szenario | Status | Body |
|---|---|---|
| Erfolgreich erstellt | 201 | `{announcement: {id, title, body, starts_at, ends_at, priority}}` |
| Erfolgreich aktualisiert | 200 | `{announcement: {...}}` |
| Erfolgreich gelöscht | 200 | `{deleted: true}` |
| Aktive auflisten | 200 | `{data: [...], total: N}` |
| Ausblenden | 200 | `{dismissed: true}` |
| Admin-Key fehlend/falsch | 401 | `{error: "Admin key required."}` |
| Nicht gefunden | 404 | `{error: "Announcement not found."}` |
| Validierung fehlgeschlagen | 422 | `{error: "..."}` |

`created_at` / `updated_at` sind **nicht** in der öffentlichen Antwort — sie sind interne Metadaten.

---

## Testergebnisse (FT190)

```
38 Tests / 93 Assertions — alle PASS
PHPStan Level 8 — keine Fehler
PHP CS Fixer — sauber
```

Quelle: [`../NENE2-FT/announcelog/`](https://github.com/hideyukiMORI/NENE2-examples/tree/main/announcelog)
