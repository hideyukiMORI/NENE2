# How-to: JSON Merge Patch & ETag Conflict Detection

**FT178 — patchlog**

Implementierung von PATCH (RFC 7396 JSON Merge Patch) und PUT-Semantik mit
optimistischem Locking via ETag, Schutz unveränderlicher Felder und V.php-Integration.

---

## Das Problem mit PUT

`PUT` ersetzt die gesamte Ressource. Clients müssen jedes Feld senden, auch die, die sie
nicht geändert haben. Dies erzeugt:

- **Race Conditions**: gleichzeitige Leser sehen beide Version 1, beide PUT, der letzte
  gewinnt und verwirft stillschweigend die Änderungen des anderen.
- **Bandbreitenverschwendung**: volles Payload auch bei Änderungen an einem einzigen Feld.
- **Berechtigungsverwirrung**: Felder schreiben, die der Client nicht besitzt.

`PATCH` mit **JSON Merge Patch (RFC 7396)** löst die ersten beiden; `ETag` /
`If-Match` löst die Race Condition für sowohl PATCH als auch PUT.

---

## JSON Merge Patch-Semantik (RFC 7396)

Das Patch-Dokument beschreibt Änderungen nach einer einfachen Regel:

| Patch-Wert | Bedeutung |
|------------|-----------|
| `"neuer Wert"` | Feld auf diesen Wert setzen |
| `null` | Feld zurücksetzen (löschen oder auf Standard zurücksetzen) |
| *(Schlüssel abwesend)* | Feld unverändert lassen |

```json
// Dokument vor PATCH:
{ "title": "Hello", "body": "World", "status": "draft" }

// PATCH-Body:
{ "title": "Goodbye", "status": null }

// Ergebnis:
{ "title": "Goodbye", "body": "World", "status": "draft" }
//                              ^^^^^     ^^^^^^^^^^^^^^
//                              unverändert  null → auf Standard zurücksetzen
```

### Unveränderliche Felder

Manche Felder dürfen niemals via PATCH oder PUT modifizierbar sein:

```php
private const array IMMUTABLE_FIELDS = ['id', 'owner_id', 'version', 'created_at', 'updated_at'];

$violations = array_intersect(array_keys($body), self::IMMUTABLE_FIELDS);

if ($violations !== []) {
    return $this->responseFactory->create(
        ['error' => 'Fields are immutable: ' . implode(', ', $violations)],
        422,
    );
}
```

### Leeres PATCH ist gültig (No-Op)

RFC 7396 §3 erlaubt explizit einen leeren Patch `{}`:

```php
// Keine Schlüssel in $patch → UPDATE überspringen, aktuelles Dokument unverändert zurückgeben
if ($patch === []) {
    return $doc;  // No-Op; Version NICHT inkrementiert
}
```

---

## ETag und If-Match für optimistisches Locking

### ETag-Format

```php
public function etag(): string
{
    return sprintf('"doc-%d-%d"', $this->id, $this->version);
    // z. B. "doc-42-7"
}
```

`ETag` bei jeder GET/PATCH/PUT-Antwort zurückgeben:

```php
return $this->responseFactory->create($doc->toArray())
    ->withHeader('ETag', $doc->etag());
```

### Konflikterkennung

```php
$ifMatch = $request->getHeaderLine('If-Match');

if ($ifMatch !== '' && $ifMatch !== $doc->etag()) {
    return $this->responseFactory->create(
        ['error' => 'Version conflict. Fetch the document and retry.'],
        412,  // Precondition Failed
    );
}
```

**If-Match abwesend**: optimistisches Update ohne Konfliktprüfung (Last-Write-Wins).
**If-Match vorhanden und übereinstimmend**: sicheres gleichzeitiges Update.
**If-Match vorhanden aber veraltet**: 412 — Client muss neu laden und es erneut versuchen.

### Versions-Inkrementierung in SQL

Die Datenbank verwenden, um die Version atomar zu inkrementieren:

```sql
UPDATE documents
SET title = ?, version = version + 1, updated_at = ?
WHERE id = ? AND version = ?
```

Die `WHERE version = ?`-Klausel prüft das optimistische Lock auf DB-Ebene doppelt und
verhindert, dass ein gleichzeitiger Schreibvorgang zwischen unserem Lesen und Schreiben einsickert.

---

## V.php-Integration

FT178 ist das erste FT, das `Nene2\Validation\V` als geteiltes Utility verwendet:

```php
// Query-Parameter
$page  = V::queryInt($params, 'page', 1, PHP_INT_MAX, 1);
$limit = V::queryInt($params, 'limit', 1, 50, 20);

// Auth-Header
$ownerId = V::userId($request->getHeaderLine('X-User-Id'));

// String-Felder (mit expliziten Längenbegrenzungen)
$title = V::str($body['title'] ?? null, 200);

// Enum-Validierung
$status = V::enum($body['status'] ?? null, DocumentStatus::class);
```

### Die `?? ''`-Falle für optionale Body-Felder

```php
// ❌ FALSCH — umgeht V::str null-Rückgabe für überlanges Input
$text = V::str($body['body'] ?? null, 10000) ?? '';

// ✅ KORREKT — bei Vorhandensein validieren, bei Abwesenheit Standard verwenden
$rawText = $body['body'] ?? null;
if ($rawText !== null) {
    $text = V::str($rawText, 10000);
    if ($text === null) {
        return $this->responseFactory->create(['error' => 'body too long'], 422);
    }
} else {
    $text = '';
}
```

`V::str(null, ...)` gibt `null` zurück, weil `null` kein String ist.
`V::str(zu_langer_string, 10000)` gibt ebenfalls `null` zurück.
`?? ''` zu verwenden kollabiert beide Fälle zu einem leeren String — und akzeptiert stillschweigend die überbreite Eingabe.

---

## Routenparameter-Extraktion

Der NENE2-Router speichert Pfadparameter im `nene2.route.parameters`-Attribut,
nicht als individuelle Request-Attribute:

```php
// ❌ FALSCH
$id = $request->getAttribute('id');  // immer null für Pfadparameter

// ✅ KORREKT
$id = Router::param($request, 'id');  // liest aus nene2.route.parameters
```

---

## Angriffs-Checkliste (ATK-01 bis ATK-12)

| # | Test | Erwartung |
|---|------|-----------|
| ATK-01 | PATCH `{"id": 999}` | 422 — unveränderliches Feld |
| ATK-02 | PATCH `{"owner_id": 99}` | 422 — unveränderliches Feld |
| ATK-03 | PATCH `{"version": 999}` | 422 — unveränderliches Feld |
| ATK-04 | PATCH `{"title": 42}` (Typ-Verwirrung) | 422 — V::str lehnt Nicht-String ab |
| ATK-05 | PATCH durch Nicht-Eigentümer | 404 — IDOR-Schutz |
| ATK-06 | If-Match veraltetes ETag | 412 — optimistischer Lock-Konflikt |
| ATK-07 | PUT fehlender erforderlicher Titel | 422 |
| ATK-08 | PATCH leeres `{}` | 200 — gültiger No-Op (RFC 7396 §3) |
| ATK-09 | PATCH `{"status": null}` | 200 — auf Standard `draft` zurücksetzen |
| ATK-10 | PATCH `{"status": 2}` (Typ-Verwirrung) | 422 — V::enum lehnt Nicht-String ab |
| ATK-11 | PATCH `{"__proto__": {...}}` | 200 — unbekannter Schlüssel ignoriert, kein Absturz |
| ATK-12 | `?limit=999999`, `?page=-1`, 20-stelliger Überlauf | 422 — V::queryInt-Guard |
