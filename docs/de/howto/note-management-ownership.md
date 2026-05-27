# How-to: Notiz-Verwaltung mit Eigentümerschaft

> **FT-Referenz**: FT240 (`NENE2-FT/noteslog`) — Notiz-Verwaltungs-API
> **ATK**: FT240 — Cracker-Mindset-Angriffstest (ATK-01 bis ATK-12)

Demonstriert eine Notiz-Verwaltungs-API mit eigentümerbezogenen Operationen, `X-Auth-User`-Header-Identifikation, IDOR-Prävention via `WHERE id = ? AND owner_id = ?` und Felder-Merge-Updates, die nicht angegebene Felder erhalten.

---

## Routen

| Methode  | Pfad           | Beschreibung                                          |
|----------|----------------|------------------------------------------------------|
| `POST`   | `/notes`       | Notiz erstellen (erfordert `X-Auth-User`-Header)      |
| `GET`    | `/notes`       | Vom Aufrufer besessene Notizen auflisten              |
| `GET`    | `/notes/{id}`  | Einzelne Notiz abrufen (404 wenn nicht gefunden oder kein Eigentümer) |
| `PUT`    | `/notes/{id}`  | Notiz aktualisieren (Feld-Merge: ausgelassene Felder werden behalten) |
| `DELETE` | `/notes/{id}`  | Notiz löschen (404 wenn nicht gefunden oder kein Eigentümer) |

---

## `X-Auth-User`-Header-Identifikation

Die API verwendet einen minimalen `X-Auth-User`-String-Header als Identität des Aufrufers:

```php
private function resolveAuthUser(ServerRequestInterface $request): ?string
{
    $userId = trim($request->getHeaderLine('X-Auth-User'));

    return $userId !== '' ? $userId : null;
}
```

`trim()` entfernt führende/nachfolgende Leerzeichen. Ein nach-Trim leerer Header → `null` → `401 Unauthorized`. Jeder nicht-leere String wird als gültige Benutzer-ID akzeptiert — es gibt keine Token-Verifizierung.

Dies ist absichtlich schwach für Demo-Zwecke. In der Produktion durch verifizierte JWT-Claims oder sitzungscookie-gestützte Sessions ersetzen.

---

## IDOR-Prävention: `WHERE id = ? AND owner_id = ?`

Jede Operation, die eine bestimmte Notiz berührt, schließt `owner_id` in die Abfrage ein:

```php
/**
 * Gibt die Notiz nur zurück, wenn sie dem angegebenen Eigentümer gehört.
 * Gibt null für "nicht gefunden" und "falscher Eigentümer" zurück — Aufrufer geben 404 in beiden Fällen zurück,
 * um IDOR-Informationslecks zu verhindern (nicht exponieren, ob eine Ressource existiert).
 */
public function findByIdAndOwner(int $id, string $ownerId): ?Note
{
    $row = $this->db->fetchOne(
        'SELECT * FROM notes WHERE id = ? AND owner_id = ?',
        [$id, $ownerId],
    );

    return $row !== null ? $this->hydrate($row) : null;
}
```

Die Methode gibt `null` für "nicht gefunden" und "falscher Eigentümer" zurück. Der Controller verwendet in beiden Fällen dieselbe `404 Not Found`-Antwort:

```php
$note = $this->repo->findByIdAndOwner($id, $authUser);

if ($note === null) {
    // 404 nicht 403: nicht verraten, ob die Ressource existiert (IDOR-Prävention)
    return $this->problems->create($request, 'not-found', 'Note Not Found', 404, '');
}
```

`403 Forbidden` zurückzugeben würde bestätigen, dass die Ressource existiert — der `404`-Ansatz verhindert Enumerationsangriffe.

---

## Feld-Merge-Update

`PUT /notes/{id}` behält vorhandene Werte für im Request-Body ausgelassene Felder:

```php
$title    = isset($body['title']) && is_string($body['title']) ? trim($body['title']) : $note->title;
$noteBody = isset($body['body'])  && is_string($body['body'])  ? $body['body']        : $note->body;

$this->repo->update($id, $authUser, $title, $noteBody);
$updated = new Note($note->id, $note->ownerId, $title, $noteBody, $note->createdAt);
```

Wenn nur `title` angegeben wird, behält `body` seinen aktuellen Wert — und umgekehrt. Dies unterscheidet sich von einer vollständigen Ersetzung (`PUT`-Semantik) — es verhält sich eher wie `PATCH`. Für strikte `PUT`-Semantik beide Felder erfordern und `422` zurückgeben, wenn eines fehlt.

---

## Schema

```sql
CREATE TABLE IF NOT EXISTS notes (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    owner_id   TEXT    NOT NULL,
    title      TEXT    NOT NULL,
    body       TEXT    NOT NULL DEFAULT '',
    created_at TEXT    NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_notes_owner ON notes (owner_id);
```

`body` hat den Standard `''` — keine nullable Spalte für den Text-Body. `owner_id` ist ein freier String (der `X-Auth-User`-Wert); es gibt keinen Foreign Key zu einer Benutzertabelle.

---

## ATK — Cracker-Mindset-Angriffstest (FT240)

### ATK-01 — `X-Auth-User` ist trivial fälschbar ⚠️ EXPOSED

**Angriff**: Einen anderen Benutzer imitieren, indem seine Benutzer-ID im Header gesendet wird.

**Ergebnis**: EXPOSED — der Header trägt keinen kryptografischen Identitätsbeweis. Für die Produktion signierte JWT-Tokens oder Session-Cookies verwenden.

---

### ATK-02 — Zeilenumbruch-Injektion in `X-Auth-User` 🚫 BLOCKED

**Angriff**: HTTP-Header-Injektionszeichen (CR/LF) im Header-Wert einbetten.

**Ergebnis**: BLOCKED — HTTP-Server lehnen fehlerhafte Header vor der Anwendungsebene ab.

---

### ATK-03 — IDOR: Notiz eines anderen Benutzers lesen 🚫 BLOCKED

**Angriff**: Notiz-IDs eines anderen Benutzers erraten oder enumerieren.

**Ergebnis**: BLOCKED — eigentümerbezogene Abfrage + 404 verhindert IDOR.

---

### ATK-04 — SQL-Injection via Titel oder Body 🚫 BLOCKED

**Angriff**: SQL-Metazeichen im Request-Body einbetten.

**Ergebnis**: BLOCKED — parametrisierte Abfragen verhindern alle SQL-Injection via Body-Felder.

---

### ATK-05 — Leerer Titel 🚫 BLOCKED

**Angriff**: Notiz mit einem nur-Leerzeichen oder leeren Titel erstellen.

**Ergebnis**: BLOCKED — `trim()` + Leer-String-Prüfung behandelt Nur-Leerzeichen-Eingaben.

---

### ATK-06 — Fehlender `X-Auth-User`-Header 🚫 BLOCKED

**Angriff**: Anfrage ohne `X-Auth-User`-Header senden.

**Ergebnis**: BLOCKED — fehlender Header wird als nicht-authentifiziert behandelt → `401 Unauthorized`.

---

### ATK-07 — Impersonation via beliebigen `X-Auth-User`-Wert ⚠️ EXPOSED

**Angriff**: Notizen als privilegierte Benutzer-ID-String erstellen.

**Ergebnis**: EXPOSED (gleiche Wurzel wie ATK-01). Ohne kryptografische Auth kann ein echter Admin nicht von einem Angreifer unterschieden werden, der den String `"admin"` kennt.

---

### ATK-08 — XSS-Payload in Titel oder Body ✅ ACCEPTED BY DESIGN

**Angriff**: Script-Tag speichern.

**Ergebnis**: ACCEPTED BY DESIGN — JSON-APIs geben rohen Inhalt zurück. Die Rendering-Schicht muss vor dem HTML-Einfügen bereinigen.

---

### ATK-09 — Teilaktualisierung verliert unbeabsichtigte Felder ✅ ACCEPTED BY DESIGN

**Angriff**: Versuchen, `body` zu leeren durch Auslassen aus dem Update.

**Ergebnis**: ACCEPTED BY DESIGN — dokumentiertes Merge-Update-Verhalten. Wenn strikte `PUT`-Semantik gewünscht ist, alle Felder erfordern.

---

### ATK-10 — Nicht-numerische Notiz-ID ⚠️ PARTIALLY BLOCKED

**Angriff**: String oder Float als `{id}` übergeben.

**Ergebnis**: PARTIALLY BLOCKED — nicht-numerische Strings mappen auf 404. Floats werden stillschweigend abgeschnitten. `ctype_digit()`-Guard für strikte Validierung hinzufügen.

---

### ATK-11 — Nicht-besessene/nicht-existente Notiz löschen 🚫 BLOCKED

**Angriff**: Notiz-ID löschen, die nicht existiert oder einem anderen Benutzer gehört.

**Ergebnis**: BLOCKED — eigentümerbezogenes DELETE + 404-Antwort verhindert mandantenübergreifendes Löschen.

---

### ATK-12 — Nur-Leerzeichen `X-Auth-User` 🚫 BLOCKED

**Angriff**: Header mit nur Leerzeichen oder Tabs senden.

**Ergebnis**: BLOCKED — `trim()` normalisiert Nur-Leerzeichen-Header zu leer.

---

## ATK-Zusammenfassung

| # | Angriffsvektor | Ergebnis |
|---|---------------|---------|
| ATK-01 | X-Auth-User ist trivial fälschbar | ⚠️ EXPOSED |
| ATK-02 | Zeilenumbruch-Injektion in X-Auth-User | 🚫 BLOCKED |
| ATK-03 | IDOR: Notiz eines anderen Benutzers lesen | 🚫 BLOCKED |
| ATK-04 | SQL-Injection via Titel/Body | 🚫 BLOCKED |
| ATK-05 | Leerer Titel | 🚫 BLOCKED |
| ATK-06 | Fehlender X-Auth-User-Header | 🚫 BLOCKED |
| ATK-07 | Impersonation via beliebigen Header-Wert | ⚠️ EXPOSED |
| ATK-08 | XSS in Titel/Body | ✅ ACCEPTED BY DESIGN |
| ATK-09 | Teilaktualisierung Feld-Merge-Überraschung | ✅ ACCEPTED BY DESIGN |
| ATK-10 | Nicht-numerische Notiz-ID | ⚠️ PARTIALLY BLOCKED |
| ATK-11 | Nicht-besessene/nicht-existente Notiz löschen | 🚫 BLOCKED |
| ATK-12 | Nur-Leerzeichen X-Auth-User | 🚫 BLOCKED |

**Echte Schwachstellen vor der Produktion zu beheben**:
1. **ATK-01 / ATK-07** — `X-Auth-User` durch signierte JWT- oder Session-Verifizierung ersetzen
2. **ATK-10** — `ctype_digit()`-Guard für ID-Pfadparameter hinzufügen

---

## Verwandte Anleitungen

- [`use-bearer-auth.md`](use-bearer-auth.md) — signierte Bearer-Token-Authentifizierung
- [`enforce-resource-ownership.md`](enforce-resource-ownership.md) — IDOR-Präventionsmuster
- [`jwt-authentication.md`](jwt-authentication.md) — JWT-Verifizierung zur Benutzeridentifikation
