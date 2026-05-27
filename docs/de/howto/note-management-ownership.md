# How-to: Notizverwaltung mit Eigentümerschaft

> **FT-Referenz**: FT240 (`NENE2-FT/noteslog`) — Notizverwaltungs-API
> **ATK**: FT240 — Cracker-Mindset-Angriffstests (ATK-01 bis ATK-12)

Demonstriert eine Notizverwaltungs-API mit eigentümerbezogenen Operationen, `X-Auth-User`-Header-Identifikation, IDOR-Prävention über `WHERE id = ? AND owner_id = ?` und Feld-Merge-Updates, die nicht angegebene Felder erhalten.

---

## Routen

| Methode | Pfad | Beschreibung |
|----------|----------------|------------------------------------------------------|
| `POST`   | `/notes`       | Notiz erstellen (erfordert `X-Auth-User`-Header)     |
| `GET`    | `/notes`       | Notizen des Aufrufers auflisten                      |
| `GET`    | `/notes/{id}`  | Einzelne Notiz abrufen (404 wenn nicht gefunden oder nicht Eigentümer) |
| `PUT`    | `/notes/{id}`  | Notiz aktualisieren (Feld-Merge: ausgelassene Felder werden behalten) |
| `DELETE` | `/notes/{id}`  | Notiz löschen (404 wenn nicht gefunden oder nicht Eigentümer) |

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

`trim()` entfernt führende/nachfolgende Leerzeichen. Ein nach-trim-leerer Header → `null` → `401 Unauthorized`. Jeder nicht-leere String wird als gültige Benutzer-ID akzeptiert — es gibt keine Token-Verifizierung.

Dies ist absichtlich schwach für Demo-Zwecke. In der Produktion durch verifizierte JWT-Claims oder sitzungs-Cookie-basierte Sessions ersetzen.

---

## IDOR-Prävention: `WHERE id = ? AND owner_id = ?`

Jede Operation, die eine bestimmte Notiz berührt, enthält `owner_id` in der Abfrage:

```php
/**
 * Gibt die Notiz nur zurück, wenn sie dem gegebenen Eigentümer gehört.
 * Gibt null sowohl für "nicht gefunden" als auch für "falscher Eigentümer" zurück — Aufrufer
 * geben in beiden Fällen 404 zurück, um IDOR-Informationslecks zu verhindern (nicht preisgeben,
 * ob eine Ressource existiert).
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

Die Methode gibt `null` sowohl für "nicht gefunden" als auch für "falscher Eigentümer" zurück. Der Controller verwendet die gleiche `404 Not Found`-Antwort in beiden Fällen:

```php
$note = $this->repo->findByIdAndOwner($id, $authUser);

if ($note === null) {
    // 404 nicht 403: nicht preisgeben, ob die Ressource existiert (IDOR-Prävention)
    return $this->problems->create($request, 'not-found', 'Note Not Found', 404, '');
}
```

Die Rückgabe von `403 Forbidden` würde bestätigen, dass die Ressource existiert — der `404`-Ansatz verhindert Enumeration-Angriffe. Ein Aufrufer erfährt nichts über Notizen anderer Benutzer.

---

## Feld-Merge-Update

`PUT /notes/{id}` behält vorhandene Werte für Felder, die im Request-Body fehlen:

```php
$title    = isset($body['title']) && is_string($body['title']) ? trim($body['title']) : $note->title;
$noteBody = isset($body['body'])  && is_string($body['body'])  ? $body['body']        : $note->body;

$this->repo->update($id, $authUser, $title, $noteBody);
$updated = new Note($note->id, $note->ownerId, $title, $noteBody, $note->createdAt);
```

Wenn nur `title` angegeben wird, behält `body` seinen aktuellen Wert — und umgekehrt. Das unterscheidet sich von einer vollständigen Ersetzung (`PUT`-Semantik) — es verhält sich eher wie `PATCH`. Für strikte `PUT`-Semantik beide Felder erfordern und `422` zurückgeben, wenn eines fehlt.

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

`body` hat den Standardwert `''` — keine nullable Spalte für den Text-Body. `owner_id` ist ein freier String (der `X-Auth-User`-Wert); kein Fremdschlüssel zu einer Benutzertabelle existiert.

---

## ATK — Cracker-Mindset-Angriffstests (FT240)

### ATK-01 — `X-Auth-User` ist trivial fälschbar

**Angriff**: Einen anderen Benutzer imitieren, indem ihre Benutzer-ID im Header gesendet wird.

```bash
curl -s -X GET http://localhost:8080/notes \
  -H 'X-Auth-User: alice'

curl -s -X GET http://localhost:8080/notes \
  -H 'X-Auth-User: bob'
```

**Beobachtet**: Jede Anfrage gibt Notizen zurück, die dem Benutzer-ID-Header gehören. Jeder Aufrufer kann jeden Benutzer imitieren, indem er seine ID-String kennt oder errät.

**Urteil**: **EXPOSED** — der Header trägt keinen kryptografischen Identitätsnachweis. Signierte JWT-Token oder Session-Cookies für die Produktionsauth verwenden.

---

### ATK-02 — Zeilenumbruch-Injection in `X-Auth-User`

**Angriff**: HTTP-Header-Injection-Zeichen (CR/LF) in den Header-Wert einbetten.

```
X-Auth-User: alice\r\nX-Injected: evil
```

**Beobachtet**: PSR-7 (Nyholm) entfernt oder lehnt ungültige Header-Zeichen ab. Der Header-Wert ist ein einfacher String — CRLF-Injection auf HTTP-Ebene wird vom Server (Swoole, Apache, Nginx) behandelt, bevor sie die Anwendung erreicht. `trim()` entfernt führende/nachfolgende Leerzeichen, fügt aber keine weitere Verteidigung gegen eingebettete Steuerzeichen hinzu.

**Urteil**: **BLOCKED** in der Praxis — HTTP-Server lehnen fehlerhafte Header ab, bevor sie die Anwendungsschicht erreichen.

---

### ATK-03 — IDOR: Notiz eines anderen Benutzers lesen

**Angriff**: Notiz-IDs eines anderen Benutzers erraten oder enumerieren.

```bash
curl -s http://localhost:8080/notes/1 -H 'X-Auth-User: bob'
# Notiz 1 wurde von alice erstellt
```

**Beobachtet**: `findByIdAndOwner(1, 'bob')` findet keine Zeile, die `id = 1 AND owner_id = 'bob'` entspricht → gibt `null` zurück → `404 Not Found`. Bob kann nicht feststellen, dass Notiz 1 existiert.

**Urteil**: **BLOCKED** — eigentümerbezogene Abfrage + 404 verhindert IDOR.

---

### ATK-04 — SQL-Injection über Titel oder Body

**Angriff**: SQL-Metazeichen in den Request-Body einbetten.

```json
{"title": "'; DROP TABLE notes; --", "body": "\" OR \"1\"=\"1"}
```

**Beobachtet**: Die Werte werden als parametrisierte `?`-Werte gespeichert — keine String-Konkatenation mit SQL. Die Injection-Payloads werden als Literaltext gespeichert.

**Urteil**: **BLOCKED** — parametrisierte Abfragen verhindern alle SQL-Injections über Body-Felder.

---

### ATK-05 — Leerer Titel

**Angriff**: Eine Notiz mit einem nur-aus-Leerzeichen bestehenden oder leeren Titel erstellen.

```json
{"title": "   "}
{"title": ""}
```

**Beobachtet**: `trim($body['title'])` reduziert beide auf `""`. Die Prüfung `title === ''` schlägt an → `422 Unprocessable Entity`.

**Urteil**: **BLOCKED** — `trim()` + Leer-String-Prüfung behandelt nur-aus-Leerzeichen bestehende Eingaben.

---

### ATK-06 — Fehlender `X-Auth-User`-Header

**Angriff**: Eine Anfrage ohne den `X-Auth-User`-Header senden.

```bash
curl -s http://localhost:8080/notes
```

**Beobachtet**: `getHeaderLine('X-Auth-User')` gibt `""` zurück. Nach `trim()` ist es immer noch `""`. `$userId !== ''` schlägt fehl → `resolveAuthUser()` gibt `null` zurück → `401 Unauthorized` mit einer strukturierten Problem-Details-Antwort.

**Urteil**: **BLOCKED** — fehlender Header wird als nicht authentifiziert behandelt.

---

### ATK-07 — Imitation über beliebigen `X-Auth-User`-Wert

**Angriff**: Notizen als privilegierte Benutzer-ID-String erstellen.

```bash
# Angenommen, 'admin' ist ein spezieller Benutzer
curl -s -X POST http://localhost:8080/notes \
  -H 'X-Auth-User: admin' \
  -H 'Content-Type: application/json' \
  -d '{"title":"Admin note"}'
```

**Beobachtet**: `201 Created` — die Notiz wird mit `owner_id = 'admin'` erstellt. Jeder String wird als Identität des Aufrufers akzeptiert.

**Urteil**: **EXPOSED** (gleiche Ursache wie ATK-01). Ohne kryptografische Auth gibt es keine Möglichkeit, einen echten Admin von einem Angreifer zu unterscheiden, der den String `"admin"` kennt.

---

### ATK-08 — XSS-Payload in Titel oder Body

**Angriff**: Ein Script-Tag speichern.

```json
{"title": "<script>alert(1)</script>", "body": "<img src=x onerror=alert(1)>"}
```

**Beobachtet**: Inhalt wird unverändert gespeichert und wörtlich in JSON zurückgegeben. Die JSON-API kodiert die Ausgabe nicht HTML-mäßig.

**Urteil**: **BY DESIGN AKZEPTIERT** — JSON-APIs geben rohen Inhalt zurück. Die Rendering-Schicht muss vor dem Einfügen in HTML bereinigen. Diese Erwartung für API-Consumer dokumentieren.

---

### ATK-09 — Teilupdate verliert unbeabsichtigt Felder

**Angriff**: Versuchen, `body` durch Weglassen aus dem Update zu überschreiben.

```json
{"title": "New title"}
// Aufrufer erwartet, dass body gelöscht wird; tatsächlich wird er erhalten
```

**Beobachtet**: Die Feld-Merge-Logik erhält `body`, wenn er im Request fehlt: `$noteBody = isset($body['body']) ? $body['body'] : $note->body`. Der Body ist unverändert — das entspricht der Absicht für eine Merge-Update-API, kann aber Aufrufer überraschen, die vollständige Ersetzung erwarten (`PUT`-Semantik).

**Urteil**: **BY DESIGN AKZEPTIERT** — dokumentiertes Merge-Update-Verhalten. Wenn strikte `PUT`-Semantik gewünscht ist, alle Felder erfordern.

---

### ATK-10 — Nicht-numerische Notiz-ID

**Angriff**: Einen String oder Float als `{id}` übergeben.

```
GET /notes/abc
GET /notes/1.5
```

**Beobachtet**: `(int) 'abc'` = 0, `(int) '1.5'` = 1.
- `abc` → `findByIdAndOwner(0, ...)` → keine Zeile → `404 Not Found`.
- `1.5` → `findByIdAndOwner(1, ...)` → wenn Notiz 1 dem Aufrufer gehört, wird sie zurückgegeben.

**Urteil**: **TEILWEISE BLOCKED** — nicht-numerische Strings werden auf 404 abgebildet. Floats werden stillschweigend abgeschnitten. `ctype_digit()`-Guard für strenge Validierung hinzufügen.

---

### ATK-11 — Nicht vorhandene oder nicht eigene Notiz löschen

**Angriff**: Eine Notiz-ID löschen, die nicht existiert oder einem anderen Benutzer gehört.

```bash
curl -s -X DELETE http://localhost:8080/notes/99999 -H 'X-Auth-User: alice'
curl -s -X DELETE http://localhost:8080/notes/1    -H 'X-Auth-User: eve'
# (Notiz 1 gehört alice)
```

**Beobachtet**: Das Repository führt `DELETE FROM notes WHERE id = ? AND owner_id = ?` aus. Wenn keine Zeilen übereinstimmen (nicht vorhanden oder falscher Eigentümer), ist `$deleted = false` → `404 Not Found`. Eves Versuch gibt dasselbe 404 zurück wie eine nicht vorhandene Notiz.

**Urteil**: **BLOCKED** — eigentümerbasiertes DELETE + 404-Antwort verhindert plattformübergreifendes Löschen.

---

### ATK-12 — Nur-Leerzeichen-`X-Auth-User`

**Angriff**: Einen Header senden, der nur Leerzeichen oder Tabs enthält.

```
X-Auth-User:    
X-Auth-User: \t
```

**Beobachtet**: `trim('   ')` = `""` → `$userId !== ''` schlägt fehl → `401 Unauthorized`.

**Urteil**: **BLOCKED** — `trim()` normalisiert nur-aus-Leerzeichen bestehende Header zu leer.

---

## ATK-Zusammenfassung

| # | Angriffsvektor | Urteil |
|---|---------------|---------|
| ATK-01 | X-Auth-User ist trivial fälschbar | EXPOSED |
| ATK-02 | Zeilenumbruch-Injection in X-Auth-User | BLOCKED |
| ATK-03 | IDOR: Notiz eines anderen Benutzers lesen | BLOCKED |
| ATK-04 | SQL-Injection über Titel/Body | BLOCKED |
| ATK-05 | Leerer Titel | BLOCKED |
| ATK-06 | Fehlender X-Auth-User-Header | BLOCKED |
| ATK-07 | Imitation über beliebigen Header-Wert | EXPOSED |
| ATK-08 | XSS in Titel/Body | BY DESIGN AKZEPTIERT |
| ATK-09 | Teilupdate Feld-Merge-Überraschung | BY DESIGN AKZEPTIERT |
| ATK-10 | Nicht-numerische Notiz-ID | TEILWEISE BLOCKED |
| ATK-11 | Nicht vorhandene/nicht eigene Notiz löschen | BLOCKED |
| ATK-12 | Nur-Leerzeichen-X-Auth-User | BLOCKED |

**Echte Schwachstellen, die vor der Produktion behoben werden müssen**:
1. **ATK-01 / ATK-07** — `X-Auth-User` durch signierte JWT- oder Session-Verifizierung ersetzen
2. **ATK-10** — `ctype_digit()`-Guard für ID-Pfadparameter hinzufügen

---

## Verwandte Anleitungen

- [`use-bearer-auth.md`](use-bearer-auth.md) — signierte Bearer-Token-Authentifizierung
- [`enforce-resource-ownership.md`](enforce-resource-ownership.md) — IDOR-Präventionsmuster
- [`jwt-authentication.md`](jwt-authentication.md) — JWT-Verifizierung für Benutzeridentifikation
- [`scheduled-reminders.md`](scheduled-reminders.md) — V::userId()-Header-Validierungsmuster
