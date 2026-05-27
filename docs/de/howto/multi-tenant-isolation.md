# How-to: Multi-Tenant-Isolation

Diese Anleitung beschreibt den Aufbau einer Multi-Tenant-API mit NENE2, bei der die Daten jedes Mandanten strikt isoliert sind. Das Auslassen auch nur eines Schritts erzeugt eine stille IDOR-Schwachstelle (Insecure Direct Object Reference), die die Daten aller Mandanten exponiert.

---

## Die Grundregel: `tenant_id`-Filter in jeder Abfrage

Das Weglassen des Mandanten-Filters aus einer einzigen Abfrage gibt stillschweigend die Daten aller Mandanten zurück:

```sql
-- ❌ Kein Mandanten-Filter — gibt Datensätze aller Mandanten zurück
SELECT id, title, body FROM notes WHERE id = ?

-- ✅ Immer den Mandanten-Filter einschließen
SELECT id, title, body FROM notes WHERE id = ? AND tenant_id = ?
```

Repository-Methoden mit einem `ForTenant`-Suffix benennen, um den Vertrag sichtbar zu machen:

```php
public function findByIdForTenant(int $id, int $tenantId): ?Note
{
    /** @var array{id: int, tenant_id: int, title: string, body: string, created_at: string}|null $row */
    $row = $this->executor->fetchOne(
        'SELECT id, tenant_id, title, body, created_at FROM notes WHERE id = ? AND tenant_id = ?',
        [$id, $tenantId],
    );

    return $row !== null ? $this->hydrate($row) : null;
}

/** @return list<Note> */
public function findAllForTenant(int $tenantId): array
{
    /** @var list<array{id: int, tenant_id: int, title: string, body: string, created_at: string}> $rows */
    $rows = $this->executor->fetchAll(
        'SELECT id, tenant_id, title, body, created_at FROM notes WHERE tenant_id = ? ORDER BY id DESC',
        [$tenantId],
    );

    return array_map($this->hydrate(...), $rows);
}

public function delete(int $id, int $tenantId): bool
{
    $note = $this->findByIdForTenant($id, $tenantId);

    if ($note === null) {
        return false;
    }

    $this->executor->execute('DELETE FROM notes WHERE id = ? AND tenant_id = ?', [$id, $tenantId]);

    return true;
}
```

Der `ForTenant`-Suffix zwingt Aufrufer, die Mandanten-ID anzugeben. Außerdem vereinfacht er Code-Reviews: Jede Methode ohne diesen Suffix ist ein Kandidat für IDOR-Überprüfung.

---

## `tenant_id` im JWT einbetten

Die Mandantenzugehörigkeit einmalig beim Login auflösen und im Token einbetten. Dies vermeidet einen DB-Round-Trip bei jeder Anfrage und hält den Mandanten-Kontext manipulationssicher (die JWT-Signatur deckt ihn ab).

```php
$now   = time();
$token = $this->issuer->issue([
    'sub'       => $user->id,
    'tenant_id' => $user->tenantId,  // muss int sein
    'email'     => $user->email,
    'iat'       => $now,
    'exp'       => $now + self::TOKEN_TTL_SECONDS,
]);
```

Den Claim in Handlern extrahieren und validieren. `is_int()` verwenden — `is_string()` allein ist nicht sicher; MySQL/PostgreSQL können String-zu-Int-Vergleiche stillschweigend ablehnen:

```php
private function tenantId(ServerRequestInterface $request): ?int
{
    /** @var array<string, mixed>|null $claims */
    $claims = $request->getAttribute('nene2.auth.claims');

    if (!is_array($claims) || !isset($claims['tenant_id']) || !is_int($claims['tenant_id'])) {
        return null;  // 401 auslösen
    }

    return $claims['tenant_id'];
}
```

`BearerTokenMiddleware` speichert verifizierte Claims in `nene2.auth.claims`. Das Middleware lehnt abgelaufene Tokens, manipulierte Signaturen und `alg: none`-Angriffe ab, bevor der Handler läuft.

---

## 404 für mandantenübergreifenden Zugriff zurückgeben (nicht 403)

403 Forbidden zurückzugeben verrät, dass die Ressource existiert, aber dem Aufrufer die Berechtigung fehlt — Information, die Mandantengrenzen überquert. Immer 404 zurückgeben:

```php
// ❌ 403 gibt mandantenübergreifende Information preis
if ($note->tenantId !== $tenantId) {
    return $this->problems->create($request, 'forbidden', 'Forbidden', 403);
}

// ✅ Mandanten-Filter in SQL — mandantenübergreifende Datensätze geben einfach null zurück
$note = $this->notes->findByIdForTenant($id, $tenantId);

if ($note === null) {
    return $this->problems->create(
        $request,
        'not-found',
        'Note Not Found',
        404,
        "Note {$id} does not exist.",
    );
}
```

Wenn `WHERE id = ? AND tenant_id = ?` nichts trifft, gibt das Repository `null` zurück und der Handler gibt 404 zurück — keine explizite mandantenübergreifende Prüfung notwendig.

---

## `tenant_id` aus Antworten ausschließen

`tenant_id` ist ein Infrastruktur-Identifier. Das Exponieren in Antworten ermöglicht Angreifern, alle Mandanten-IDs zu enumerieren, und dient als Ausgangspunkt für gezielte Angriffe:

```php
// ❌ tenant_id leckt in der Antwort
return $this->json->create([
    'id'        => $note->id,
    'tenant_id' => $note->tenantId,  // dies entfernen
    'title'     => $note->title,
    'body'      => $note->body,
]);

// ✅ Nur Felder, die der Client benötigt
return $this->json->create([
    'id'         => $note->id,
    'title'      => $note->title,
    'body'       => $note->body,
    'created_at' => $note->createdAt,
]);
```

---

## PHPStan: `assertIsList()` für `list<>`-Rückgabetypen

`json_decode()` gibt `mixed` zurück. Nach `assertIsArray()` verengt PHPStan den Typ auf `array<mixed>`, aber das erfüllt nicht `list<array<string, mixed>>`. `assertIsList()` hinzufügen, um weiter zu verengen:

```php
/** @return list<array<string, mixed>> */
private function jsonList(ResponseInterface $response): array
{
    $data = json_decode((string) $response->getBody(), true);

    $this->assertIsArray($data);
    $this->assertIsList($data);  // verengt array<mixed> → list<mixed>

    return $data;
}
```

PHPUnits `assertIsList()` validiert auch zur Laufzeit, dass das Array sequentielle Integer-Schlüssel beginnend bei 0 hat — eine nützliche Korrektheitsprüfung für API-Listenantworten.

---

## Schema-Design

```sql
CREATE TABLE IF NOT EXISTS tenants (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT NOT NULL,
    created_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS users (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id     INTEGER NOT NULL REFERENCES tenants(id),
    email         TEXT NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    created_at    TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS notes (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id  INTEGER NOT NULL REFERENCES tenants(id),
    title      TEXT NOT NULL,
    body       TEXT NOT NULL,
    created_at TEXT NOT NULL
);
```

Jede mandanten-bezogene Tabelle trägt einen `tenant_id NOT NULL` Foreign Key. Dies wird auf DB-Ebene zusätzlich zu den anwendungsseitigen Filtern durchgesetzt.

---

## Code-Review-Checkliste

Bei der Überprüfung von Multi-Tenant-Code prüfen:

1. Jedes `SELECT`, `UPDATE` und `DELETE` enthält `WHERE tenant_id = ?`
2. `tenant_id` stammt aus dem JWT-Claim, nicht aus einem URL-Parameter oder Request-Body
3. Mandantenübergreifender Zugriff gibt 404, nicht 403 zurück
4. Antworten enthalten keine `tenant_id`
5. Kein `JOIN` überquert Mandantengrenzen ohne Mandanten-Filter
6. `is_int($claims['tenant_id'])`-Typprüfung ist vorhanden

---

## Isolation testen

Unit-Tests sind unzureichend — mandantenübergreifende Integrationstests schreiben, die tatsächlich versuchen, auf die Daten eines anderen Mandanten zuzugreifen:

```php
public function testCrossTenantGetReturns404NotForbidden(): void
{
    $aliceToken = $this->loginAs('alice@acme.com');
    $bobToken   = $this->loginAs('bob@beta.com');

    $res    = $this->post('/notes', ['title' => 'Secret', 'body' => 'Acme secret'], $aliceToken);
    $noteId = $this->json($res)['id'];

    // Bob versucht, Alices Notiz zu lesen
    $crossRes = $this->get('/notes/' . $noteId, $bobToken);

    // Muss 404 sein — NICHT 403
    $this->assertSame(404, $crossRes->getStatusCode());
}

public function testListNotesShowsOnlyCurrentTenantNotes(): void
{
    $aliceToken = $this->loginAs('alice@acme.com');
    $bobToken   = $this->loginAs('bob@beta.com');

    $this->post('/notes', ['title' => 'Alice Note', 'body' => 'Acme'], $aliceToken);
    $this->post('/notes', ['title' => 'Bob Note',   'body' => 'Beta'], $bobToken);

    $aliceNotes = $this->jsonList($this->get('/notes', $aliceToken));
    $bobNotes   = $this->jsonList($this->get('/notes', $bobToken));

    $this->assertCount(1, $aliceNotes);
    $this->assertSame('Alice Note', $aliceNotes[0]['title']);

    $this->assertCount(1, $bobNotes);
    $this->assertSame('Bob Note', $bobNotes[0]['title']);
}
```

Happy-Path-Tests überprüfen nur, dass die eigenen Mandantendaten funktionieren. Mandantenübergreifende Tests sind der einzige Weg, Isolationsfehler zu erkennen.

---

## Verwandte Anleitungen

- [`jwt-authentication.md`](jwt-authentication.md) — JWT-Ausstellung und -Verifizierung
- [`rbac.md`](rbac.md) — Rollenbasierte Zugriffskontrolle auf Basis von JWT
- [`enforce-resource-ownership.md`](enforce-resource-ownership.md) — Eigentumsprüfungen pro Benutzer
