# Ressourcen-Eigentumsrechte durchsetzen (IDOR-Prävention)

Insecure Direct Object Reference (IDOR) ist die #1-API-Schwachstelle (OWASP API Security Top 10).
Sie tritt auf, wenn ein Benutzer durch das Erraten oder Enumerieren von IDs auf die Ressourcen eines anderen Benutzers zugreifen oder diese ändern kann.

NENE2 bietet keine automatische Eigentumsdurchsetzung — jedes Repository und jeder Handler muss sie explizit implementieren. Diese Anleitung zeigt die empfohlenen Muster.

---

## 1. Die Grundregel: 404, nicht 403

Wenn ein Benutzer auf eine Ressource zugreift, die einem anderen Benutzer gehört, `404 Not Found` zurückgeben — **nicht** `403 Forbidden`.

- **403** teilt dem Angreifer mit: "Diese Ressource existiert, aber Sie können nicht darauf zugreifen." — Informationsleck
- **404** teilt dem Angreifer mit: "Diese Ressource existiert nicht." — keine Bestätigung

```php
// FALSCH — verrät Existenz
if ($note->ownerId !== $authUserId) {
    return $this->problems->create($request, 'forbidden', 'Forbidden', 403, '');
}

// KORREKT — verrät nichts
if ($note === null) {
    return $this->problems->create($request, 'not-found', 'Not Found', 404, '');
}
```

Der praktische Weg, das zu erreichen: das Repository so gestalten, dass es **keine Ressource zurückgeben kann, die nicht dem Aufrufer gehört** — siehe nächster Abschnitt.

---

## 2. Eigentumsrechte auf SQL-Ebene durchsetzen

Das sicherste Muster ist, `owner_id` in jede Abfrage einzubeziehen. Die Methode kann buchstäblich keine Daten eines anderen Benutzers zurückgeben, unabhängig davon, wie der Aufrufer das Ergebnis verwendet.

```php
public function findByIdAndOwner(int $id, string $ownerId): ?Resource
{
    $row = $this->db->fetchOne(
        'SELECT * FROM resources WHERE id = ? AND owner_id = ?',
        [$id, $ownerId],
    );
    return $row !== null ? $this->hydrate($row) : null;
}

public function update(int $id, string $ownerId, string $newValue): bool
{
    $updated = $this->db->execute(
        'UPDATE resources SET value = ? WHERE id = ? AND owner_id = ?',
        [$newValue, $id, $ownerId],
    );
    return $updated > 0;
}

public function delete(int $id, string $ownerId): bool
{
    return $this->db->execute(
        'DELETE FROM resources WHERE id = ? AND owner_id = ?',
        [$id, $ownerId],
    ) > 0;
}
```

**Warum SQL-Ebene besser als Anwendungsebene ist:**
- Eine App-Level-Prüfung kann umgangen werden, wenn ein Entwickler vergisst, sie aufzurufen
- Eine SQL-Level-Prüfung kann nicht übersprungen werden — die falsch-eigentümer-Zeile wird einfach nicht zurückgegeben
- `null` für "nicht gefunden" und "falscher Eigentümer" zurückzugeben verhindert, dass der Aufrufer versehentlich auf einen Fall verzweigt, den er nicht kennen sollte

---

## 3. Handler-Muster

```php
private function show(ServerRequestInterface $request): ResponseInterface
{
    $authUserId = $this->resolveAuthUser($request);
    if ($authUserId === null) {
        return $this->unauthorized($request);
    }

    $id       = $this->resolveId($request);
    $resource = $this->repo->findByIdAndOwner($id, $authUserId);

    if ($resource === null) {
        // 404 deckt sowohl "nicht gefunden" als auch "gehört einem anderen Benutzer" ab
        return $this->problems->create($request, 'not-found', 'Not Found', 404, '');
    }

    return $this->json->create($resource->toArray());
}
```

---

## 4. Auflistung: In der Abfrage nach Eigentümer filtern

```php
public function listByOwner(string $ownerId): array
{
    return $this->db->fetchAll(
        'SELECT * FROM resources WHERE owner_id = ? ORDER BY id DESC',
        [$ownerId],
    );
}
```

Niemals alle Zeilen abrufen und in PHP filtern. Das verrät Daten anderer Benutzer, wenn die Filterlogik falsch ist, und ist auch ein N+1-Problem.

---

## 5. Cross-Owner-Zugriff explizit testen

Dedizierte Tests hinzufügen, die verifizieren, dass IDOR verhindert wird:

```php
public function testCannotReadAnotherUsersResource(): void
{
    $bobId = $this->decode($this->create('bob', 'Bob content'))['id'];

    // Alice versucht, Bobs Ressource zu lesen — muss 404 bekommen
    $res = $this->request('GET', '/resources/' . $bobId, authUser: 'alice');
    self::assertSame(404, $res->getStatusCode());
    // Ausdrücklich nicht 403 — was die Existenz der Ressource verraten würde
    self::assertNotSame(403, $res->getStatusCode());
}

public function testListDoesNotLeakCrossTenantData(): void
{
    $this->create('alice', 'Alice content');
    $this->create('bob', 'Bob content');

    $aliceList = $this->decode($this->request('GET', '/resources', authUser: 'alice'));
    $titles    = array_column($aliceList['items'], 'content');

    self::assertNotContains('Bob content', $titles);
}
```

---

## Hinweise

- **Warum 404 falsch wirkt**: 404 für eine Ressource zurückzugeben, die in der URL sichtbar ist, fühlt sich "unehrlich" an. Das ist es — aber OWASP empfiehlt es ausdrücklich, um ID-Enumerationsangriffe zu verhindern. Der Kompromiss ist akzeptierte Sicherheitspraxis.
- **Admin-Bypass**: Wenn Sie Admin-Routen haben, die jede Ressource sehen können, halten Sie diese auf einem separaten Pfad-Präfix mit einer separaten Eigentumsprüfung (oder keiner Prüfung). Die Eigentumsmethoden nicht mit "is admin"-Flags komplizieren.
- **Datenbankschema**: Immer einen Index auf `owner_id` hinzufügen (und auf `(owner_id, id)` für Compound-Suchen). Ohne Index ist jede Pro-Benutzer-Abfrage ein Full-Table-Scan.
