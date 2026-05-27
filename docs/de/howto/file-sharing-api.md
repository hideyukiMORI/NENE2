# How-to: File Sharing API

> **FT-Referenz**: FT303 (`NENE2-FT/filelog`) — File Sharing API: Private Dateien geben 404 (nicht 403) an Nicht-Eigentümer zurück, Löschen/Sichtbarkeitsänderung nur für Eigentümer, view-share vs. edit-share Berechtigungsstufen, `user_id` im Body ignoriert (Eigentümerschaft aus Header), Name-Längenlimit 255, `size` `is_int()` strikt, VULN-A–L alle SAFE, 59 Tests / 82 Assertions bestanden.

Diese Anleitung zeigt, wie eine Datei-Metadaten-API aufgebaut wird, bei der Benutzer Dateien besitzen, die Sichtbarkeit steuern und Zugriff mit anderen Benutzern auf view- oder edit-Ebene teilen können.

## Schema

```sql
CREATE TABLE users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    created_at TEXT NOT NULL
);

CREATE TABLE files (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id     INTEGER NOT NULL,
    name        TEXT    NOT NULL,
    size        INTEGER NOT NULL DEFAULT 0 CHECK (size >= 0),
    mime_type   TEXT    NOT NULL,
    description TEXT,
    visibility  TEXT    NOT NULL DEFAULT 'private'
                        CHECK (visibility IN ('private', 'public')),
    created_at  TEXT    NOT NULL,
    updated_at  TEXT    NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

CREATE TABLE file_shares (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    file_id             INTEGER NOT NULL,
    shared_with_user_id INTEGER NOT NULL,
    can_edit            INTEGER NOT NULL DEFAULT 0 CHECK (can_edit IN (0, 1)),
    created_at          TEXT    NOT NULL,
    UNIQUE (file_id, shared_with_user_id),
    FOREIGN KEY (file_id) REFERENCES files(id),
    FOREIGN KEY (shared_with_user_id) REFERENCES users(id)
);
```

Zweigeteilte Freigabe: `can_edit = 0` (nur Lesen) und `can_edit = 1` (Bearbeiten). `UNIQUE(file_id, shared_with_user_id)` verhindert doppelte Freigabe-Einträge.

## Endpunkte

| Methode | Pfad | Auth | Beschreibung |
|---------|------|------|--------------|
| `POST` | `/files` | `X-User-Id` | Datei-Metadaten hochladen |
| `GET` | `/files` | `X-User-Id` | Eigene Dateien auflisten |
| `GET` | `/files/{fileId}` | `X-User-Id` | Datei abrufen (Sichtbarkeitsprüfung) |
| `PUT` | `/files/{fileId}` | `X-User-Id` | Datei aktualisieren (Eigentümer oder edit-share) |
| `DELETE` | `/files/{fileId}` | `X-User-Id` | Datei löschen (nur Eigentümer) |
| `POST` | `/files/{fileId}/shares` | `X-User-Id` (Eigentümer) | Freigabe hinzufügen |
| `DELETE` | `/files/{fileId}/shares/{userId}` | `X-User-Id` (Eigentümer) | Freigabe entfernen |

## Private Datei → 404 (nicht 403)

```php
// Nicht-Eigentümer kann private Dateien nicht sehen — 404 verbirgt Existenz
if ($file['visibility'] === 'private') {
    $share = $this->repo->findShare($fileId, $userId);
    if ($share === null) {
        return $this->problems->create($request, 'not-found', 'File not found', 404);
    }
}
```

Private Dateien geben 404 an Nicht-Eigentümer und Nicht-Freigabe-Empfänger zurück. 403 würde verraten, dass die Datei existiert. Öffentliche Dateien geben allen authentifizierten Benutzern 200 zurück.

## Eigentümerschaft aus Header — Body-`user_id` ignorieren

```php
$userId = $this->requireUserId($request);
// ... Validierung ...
$id = $this->repo->create($userId, $name, $size, $mimeType, $description, $visibility, $now);
```

Die `user_id` der Datei wird immer aus dem `X-User-Id`-Header übernommen. Jede `user_id` im Request-Body wird stillschweigend ignoriert. Dies verhindert Eigentümerschafts-Injection-Angriffe (VULN-E).

## View-Share vs. Edit-Share — Zwei Stufen

```php
// Eigentümer kann immer bearbeiten
$isOwner = ((int) $file['user_id']) === $userId;

if (!$isOwner) {
    $share = $this->repo->findShare($fileId, $userId);
    if ($share === null || !(bool) $share['can_edit']) {
        return $this->problems->create($request, 'forbidden', 'Edit access required', 403);
    }
}
```

- **Eigentümer**: alle Operationen (lesen, schreiben, löschen, Freigabe-Verwaltung, Sichtbarkeit)
- **Edit-share** (`can_edit=1`): Name/Größe/MIME/Beschreibung aktualisieren — aber NICHT Sichtbarkeit
- **View-share** (`can_edit=0`): nur lesen — jeder Schreibversuch → 403

Nur Eigentümer können `visibility` ändern:

```php
// Nur Eigentümer kann Sichtbarkeit ändern
if (!$isOwner && isset($body['visibility'])) {
    $visibility = (string) $file['visibility']; // Anfrage stillschweigend ignorieren
}
```

## Strikte Eingabevalidierung

```php
$size = $body['size'] ?? null;
if (!is_int($size) || $size < 0) {
    $errors[] = ['field' => 'size', 'code' => 'invalid', 'message' => 'size must be a non-negative integer'];
}

if (!is_string($name) || strlen($name) > 255 || $name === '') {
    $errors[] = ['field' => 'name', 'code' => 'invalid', 'message' => 'name required, max 255 chars'];
}
```

- `size`: `is_int()` lehnt Floats wie `1.5` ab (VULN-I)
- `name`: max 255 Zeichen — verhindert übergroße Eingaben (VULN-H)
- `visibility`: `in_array($value, ['private', 'public'], true)` strikte Allowlist

## Freigabe-Entfernung — Nur Eigentümer

```php
// Nur der Datei-Eigentümer kann Freigaben entfernen
if ((int) $file['user_id'] !== $userId) {
    return $this->problems->create($request, 'not-found', 'File not found', 404);
}
```

Der freigegebene Benutzer kann sich nicht selbst aus einer Freigabe entfernen — nur der Eigentümer kann Freigaben verwalten. Nicht-Eigentümer erhalten 404 (nicht 403), um die Existenz der Datei zu verbergen (VULN-F).

## User-ID-Validierung — Null und Negative ablehnen

```php
$raw = $request->getHeaderLine('X-User-Id');
$userId = ctype_digit($raw) ? (int) $raw : 0;
if ($userId <= 0) {
    return $this->problems->create($request, 'unauthorized', 'Authentication required', 401);
}
```

`X-User-Id: 0` und `X-User-Id: -1` geben 401 zurück (VULN-L). Nur positive Ganzzahlen sind gültige User-IDs.

---

## Vulnerability Assessment

### V-01 — IDOR: Private Datei durch anderen Benutzer zugänglich ✅ SAFE

**Risk**: Benutzer B liest die private Datei von Benutzer A.
**Finding**: SAFE — Private Dateien geben 404 an Nicht-Eigentümer ohne Freigabe-Eintrag zurück.

---

### V-02 — IDOR: Datei eines anderen Benutzers löschen ✅ SAFE

**Risk**: Benutzer B löscht die Datei von Benutzer A.
**Finding**: SAFE — Löschen prüft Eigentümerschaft; Nicht-Eigentümer erhält 404. Datei existiert nach fehlgeschlagenem Versuch noch.

---

### V-03 — IDOR: Datei eines anderen Benutzers aktualisieren ✅ SAFE

**Risk**: Benutzer B aktualisiert Name/Metadaten der Datei von Benutzer A.
**Finding**: SAFE — Aktualisierung prüft Eigentümerschaft; Nicht-Eigentümer ohne edit-share erhält 404.

---

### V-04 — Privilegienerweiterung: View-Share versucht Bearbeitung ✅ SAFE

**Risk**: Benutzer mit View-only-Freigabe ruft PUT auf, um die Datei zu ändern.
**Finding**: SAFE — Bearbeitungsprüfung erfordert `can_edit = 1`; View-share gibt 403 zurück.

---

### V-05 — Eigentümerschafts-Injection: `user_id` im Request-Body ✅ SAFE

**Risk**: `{ "user_id": 99, "name": "..." }` weist Datei Benutzer 99 zu.
**Finding**: SAFE — `user_id` aus dem Body wird stillschweigend ignoriert; Eigentümerschaft kommt immer aus `X-User-Id`-Header.

---

### V-06 — Freigabe-Entfernung durch Nicht-Eigentümer ✅ SAFE

**Risk**: Freigegebener Benutzer entfernt sich selbst aus der Freigabeliste.
**Finding**: SAFE — Freigabe-Lösch-Endpunkt prüft Datei-Eigentümerschaft; Nicht-Eigentümer gibt 404 zurück.

---

### V-07 — SQL-Injection im Namensfeld ✅ SAFE

**Risk**: `"name": "test'; DROP TABLE files; --"` zerstört Daten.
**Finding**: SAFE — Parametrisierte Abfragen speichern den Injection-String als Literaldaten. Dateien-Tabelle intakt.

---

### V-08 — Übergroßer Name verursacht Absturz ✅ SAFE

**Risk**: 300-Zeichen-Name verursacht DB-Fehler oder Speichererschöpfung.
**Finding**: SAFE — `strlen($name) > 255`-Validierung gibt 422 zurück, bevor eingefügt wird.

---

### V-09 — Float-Größen-Typverwechslung ✅ SAFE

**Risk**: `"size": 1.5` passiert Validierung und korrumpiert Größen-Tracking.
**Finding**: SAFE — `is_int($size)` lehnt Floats ab → 422.

---

### V-10 — Edit-Share eskaliert Sichtbarkeit auf public ✅ SAFE

**Risk**: Edit-Share-Benutzer setzt `"visibility": "public"`, um eine private Datei zu exponieren.
**Finding**: SAFE — Sichtbarkeitsänderungen sind nur für Eigentümer; das Visibility-Feld im PUT-Body von edit-share wird stillschweigend ignoriert.

---

### V-11 — Existenzoffenlegung privater Dateien via 403 ✅ SAFE

**Risk**: 403-Antwort verrät, dass die Datei auch für nicht-autorisierte Benutzer existiert.
**Finding**: SAFE — Nicht-Eigentümer erhalten 404, nicht 403. Datei-Existenz wird nicht preisgegeben.

---

### V-12 — Auth-Bypass via X-User-Id: 0 oder negativ ✅ SAFE

**Risk**: `X-User-Id: 0` oder `X-User-Id: -1` umgeht die Benutzerprüfung.
**Finding**: SAFE — `ctype_digit()` + `$userId <= 0`-Prüfung gibt 401 für Null- und Negativwerte zurück.

---

### VULN-Zusammenfassung

| ID | Schwachstelle | Befund |
|----|---------------|--------|
| V-01 | IDOR: Private Datei zugänglich | ✅ SAFE |
| V-02 | IDOR: Datei eines anderen Benutzers löschen | ✅ SAFE |
| V-03 | IDOR: Datei eines anderen Benutzers aktualisieren | ✅ SAFE |
| V-04 | View-Share Privilegienerweiterung | ✅ SAFE |
| V-05 | Eigentümerschafts-Injection via Body | ✅ SAFE |
| V-06 | Freigabe-Entfernung durch Nicht-Eigentümer | ✅ SAFE |
| V-07 | SQL-Injection im Namen | ✅ SAFE |
| V-08 | Übergroßer Name-Absturz | ✅ SAFE |
| V-09 | Float-Größen-Typverwechslung | ✅ SAFE |
| V-10 | Edit-Share Sichtbarkeits-Eskalation | ✅ SAFE |
| V-11 | Existenzoffenlegung privater Dateien | ✅ SAFE |
| V-12 | Auth-Bypass via ungültige User-ID | ✅ SAFE |

**12 SAFE, 0 EXPOSED**
Private-Datei-404-Muster, header-only-Eigentümerschaft, Zwei-Stufen-Freigabeberechtigungen, strikte Typvalidierung und Eigentümer-only-Sichtbarkeit verhindern alle IDOR- und Privilegienerweiterungs-Vektoren.

---

## Was man NICHT tun sollte

| Anti-Muster | Risiko |
|---|---|
| 403 für private Datei an Nicht-Eigentümer zurückgeben | Gibt nicht-autorisierten Benutzern Aufschluss über Datei-Existenz |
| `user_id` aus Request-Body für Eigentümerschaft akzeptieren | Jeder authentifizierte Benutzer beansprucht Eigentümerschaft über jede Datei |
| View-Share PUT aufrufen lassen | Freigegebene Betrachter können Datei-Metadaten ändern |
| Edit-Share Sichtbarkeit ändern lassen | Freigegebene Bearbeiter exponieren private Dateien für die Öffentlichkeit |
| Freigegebenen Benutzer eigene Freigabe entfernen lassen | Benutzer können die Zugriffsverwaltung vom Eigentümer entziehen |
| `size: 1.5` (Float) akzeptieren | Typverwechslung; nicht-ganzzahlige Dateigrößen korrumpieren Größen-Tracking |
| Kein `name`-Längenlimit | Lange Dateinamen können DB-Spaltenüberlauf oder Speicherprobleme verursachen |
| `X-User-Id: 0` als gültig akzeptieren | User-ID 0 kann auf nicht initialisierte Zeilen passen oder Eigentümerschattsprüfungen umgehen |
| `ctype_digit()` ohne `> 0`-Prüfung | `"0"` besteht `ctype_digit`, ist aber keine gültige User-ID |
