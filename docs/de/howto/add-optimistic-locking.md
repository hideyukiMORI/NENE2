# Optimistic Concurrency Control (ETag / If-Match) hinzufügen

Optimistic Locking verhindert das **Lost-Update-Problem**: zwei Clients lesen dieselbe Ressource,
beide ändern sie, und der zweite Schreibvorgang überschreibt den ersten stillschweigend.

NENE2 liefert `ConditionalWriteHelper` für die Schreibseite (PUT, PATCH, DELETE) und
`ConditionalGetHelper` für die Leseseite (GET → 304 Not Modified).

---

## 1. Einen Versionszähler zum Schema hinzufügen

```sql
CREATE TABLE documents (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    title      TEXT    NOT NULL,
    version    INTEGER NOT NULL DEFAULT 1,
    updated_at TEXT    NOT NULL
);
```

---

## 2. Bei jedem GET und Schreibantwort ein ETag zurückgeben

Die Versionsnummer als einfaches, debuggbares ETag verwenden:

```php
private function etag(int $version): string
{
    return '"v' . $version . '"';
}

// Im GET-Handler:
return $this->json->create($doc->toArray())
    ->withHeader('ETag', $this->etag($doc->version));

// Im POST-Handler (erstellen):
return $this->json->create($doc->toArray(), 201)
    ->withHeader('ETag', $this->etag($doc->version));
```

---

## 3. `If-Match` bei PUT / PATCH / DELETE prüfen

```php
use Nene2\Http\ConditionalWriteHelper;

private function update(ServerRequestInterface $request): ResponseInterface
{
    $id  = $this->resolveId($request);
    $doc = $this->repo->findById($id);
    if ($doc === null) {
        return $this->problems->create($request, 'not-found', 'Not Found', 404, '');
    }

    $block = ConditionalWriteHelper::check($request, $this->problems, $this->etag($doc->version));
    if ($block !== null) {
        return $block; // 412 Precondition Failed oder 428 Precondition Required
    }

    // ETag stimmt überein — sicheres Schreiben möglich
    $updated = $this->repo->updateIfMatch($id, /* neue Werte */, $doc->version);
    if ($updated === null) {
        // Gleichzeitige Änderung nach unserer Prüfung
        return $this->problems->create($request, 'precondition-failed', 'Precondition Failed', 412, '');
    }
    return $this->json->create($updated->toArray())
        ->withHeader('ETag', $this->etag($updated->version));
}
```

### Von `ConditionalWriteHelper::check()` zurückgegebene Statuscodes

| `If-Match`-Header | Server-ETag | Ergebnis |
|-------------------|-------------|--------|
| fehlt | beliebig | **428** Precondition Required (Header ist Pflicht) |
| `*` | beliebig | **null** — bestanden (Wildcard, beliebige Version) |
| `"v3"` | `"v3"` | **null** — bestanden (exakte Übereinstimmung) |
| `"v2"` | `"v3"` | **412** Precondition Failed (veraltete Version) |

Um `If-Match` optional zu machen, `require: false` übergeben:

```php
ConditionalWriteHelper::check($request, $this->problems, $etag, require: false);
```

---

## 4. Ein bedingtes UPDATE im Repository verwenden

```php
public function updateIfMatch(int $id, string $title, int $expectedVersion): ?Document
{
    $newVer  = $expectedVersion + 1;
    $now     = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
    $updated = $this->db->execute(
        'UPDATE documents SET title = ?, version = ?, updated_at = ? WHERE id = ? AND version = ?',
        [$title, $newVer, $now, $id, $expectedVersion],
    );

    if ($updated === 0) {
        return null; // Versionskonflikt oder nicht gefunden
    }
    return new Document($id, $title, $newVer, $now);
}
```

Die `WHERE version = ?`-Klausel ist der Sperrschutz auf Datenbankebene. Wenn die Version der Zeile
bereits von einem gleichzeitigen Schreiber weitergeschaltet wurde, gibt `execute()` `0` zurück
(keine Zeilen aktualisiert) und der Aufrufer kann eine zweite 412-Antwort zurückgeben.

---

## 5. Das Lost-Update-Szenario testen

```php
public function testLostUpdatePrevented(): void
{
    $id = $this->decode($this->create('Original'))['id'];

    // Alice liest Version 1 und aktualisiert → Version wird 2
    $this->req('PUT', '/documents/' . $id, ['title' => "Alice's edit"], '"v1"');

    // Bob versucht mit veraltetem v1-ETag zu aktualisieren → muss fehlschlagen
    $bob = $this->req('PUT', '/documents/' . $id, ['title' => "Bob's edit"], '"v1"');
    self::assertSame(412, $bob->getStatusCode());

    // Alices Aktualisierung bleibt erhalten
    $final = $this->decode($this->req('GET', '/documents/' . $id));
    self::assertSame("Alice's edit", $final['title']);
    self::assertSame(2, $final['version']);
}
```

---

## Hinweise

- **ETag-Format**: `"v{version}"` (ganzzahlbasiert) ist einfach und in Tests vorhersehbar.
  Content-Hash-ETags (`'"' . md5($body) . '"'`) sind robuster für inhaltsadressierbare Ressourcen,
  aber in Tests ohne Vorberechnung des Hashs schwerer vorherzusagen.
- **Wildcard `If-Match: *`**: RFC 9110 definiert `*` als "erfolgreich, wenn die Ressource eine
  aktuelle Darstellung hat" — d.h., sie existiert. Nützlich für "aktualisieren, wenn vorhanden"
  ohne die Version zu kennen. Der Aufrufer muss trotzdem 404 zurückgeben, wenn die Ressource fehlt.
- **428 Precondition Required** (RFC 6585 §3): der korrekte Status, wenn `If-Match` erforderlich,
  aber nicht vorhanden ist. Statt 400 oder 422 verwenden — die Anfrage ist wohlgeformt; die
  Vorbedingung fehlt.
- **TOCTOU-Fenster**: Das Muster `findById()` + bedingtes UPDATE hat ein kurzes Race-Fenster
  auf Multi-Writer-Datenbanken. Unter SQLites Schreib-Serialisierung ist dies harmlos. Auf
  PostgreSQL unter hoher Gleichzeitigkeit beide Operationen in einer `SERIALIZABLE`-Transaktion einschließen.
