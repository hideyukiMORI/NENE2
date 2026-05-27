# How-to: JWT-Multi-Mandanten-Isolation

> **FT-Referenz**: FT342 (`NENE2-FT/tenantlog`) — Multi-Mandanten-Notiz-API mit JWT-Bearer-Authentifizierung, tenant_id in Token-Claims eingebettet, strenge Abfrageeinschränkung pro Mandant, mandantenübergreifendes IDOR mit 404 blockiert, tenant_id wird niemals in Antworten exponiert, 13 Tests / 30+ Assertions PASS.

Diese Anleitung zeigt, wie JWT-Token verwendet werden, um `tenant_id` als Claim zu tragen, alle Abfragen auf den authentifizierten Mandanten zu beschränken und mandantenübergreifenden Datenzugriff zu verhindern.

## Schema

```sql
CREATE TABLE tenants (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT    NOT NULL,
    created_at TEXT    NOT NULL
);

CREATE TABLE users (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id     INTEGER NOT NULL REFERENCES tenants(id),
    email         TEXT    NOT NULL UNIQUE,
    password_hash TEXT    NOT NULL,
    created_at    TEXT    NOT NULL
);

CREATE TABLE notes (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id  INTEGER NOT NULL REFERENCES tenants(id),
    user_id    INTEGER NOT NULL REFERENCES users(id),
    title      TEXT    NOT NULL,
    body       TEXT    NOT NULL DEFAULT '',
    created_at TEXT    NOT NULL
);
```

## Authentifizierung

```
POST /auth/login  →  Bearer Token (JWT)
Alle anderen Endpunkte → Authorization: Bearer <token>
```

### Login

```php
POST /auth/login
{"email": "alice@acme.com", "password": "password"}
→ 200  {"token": "eyJhbGci..."}

// Falsche Anmeldedaten oder unbekannte E-Mail
→ 401  {"type": ".../invalid-credentials", "detail": "Invalid email or password"}
// Beide Fehler geben dieselbe Meldung zurück (Benutzer-Enumerations-Prävention)
```

### JWT-Claims

```php
// Token-Payload (dekodiert)
{
  "sub": 1,           // user_id
  "tenant_id": 1,     // Mandant, zu dem der Benutzer gehört
  "exp": 1748427600
}
```

Der `tenant_id`-Claim ist die maßgebliche Quelle der Mandantenidentität — `tenant_id` aus dem Request-Body oder Headers niemals vertrauen.

### Verifizierung

```php
$verifier = new LocalBearerTokenVerifier($secret);
$claims   = $verifier->verify($token);
// $claims['tenant_id'] ist die vertrauenswürdige Mandanteneinschränkung
```

Ein manipuliertes Token (ungültige Signatur) → 401.

## Mandantenbezogene Endpunkte

Alle Notizoperationen erfordern ein gültiges Bearer-Token. Die `tenant_id` wird aus den verifizierten JWT-Claims extrahiert.

### Notiz erstellen

```php
POST /notes
Authorization: Bearer <alice_token>
{"title": "Alice Note", "body": "Acme content"}
→ 201
{
  "id": 1,
  "title": "Alice Note",
  "body": "Acme content",
  "created_at": "..."
  // tenant_id wird NICHT zurückgegeben — wird niemals an Client durchgesickert
}

// Kein Token → 401
// Ungültiges Token → 401
```

**`tenant_id` wird immer aus dem JWT-Claim genommen, nicht aus dem Request-Body.**

### Notizen auflisten

```php
GET /notes
Authorization: Bearer <alice_token>
→ 200  [{"id": 1, "title": "Alice Note", ...}]

// Bobs Token sieht nur Bobs Notizen — Alices Notizen erscheinen niemals
GET /notes
Authorization: Bearer <bob_token>
→ 200  [{"id": 2, "title": "Bob Note", ...}]
```

```sql
SELECT * FROM notes WHERE tenant_id = ? ORDER BY created_at DESC
-- tenant_id aus JWT-Claims gebunden, niemals aus der Anfrage
```

### Notiz abrufen (IDOR-Prävention)

```php
// Alices Notiz
GET /notes/1
Authorization: Bearer <alice_token>
→ 200  {"id": 1, "title": "Alice Note", ...}

// Bob versucht, auf Alices Notiz zuzugreifen (Notiz-ID 1 gehört zu Mandant 1)
GET /notes/1
Authorization: Bearer <bob_token>
→ 404  // NICHT 403 — verhindert mandantenübergreifende Existenz-Enumeration
```

**404 zurückgeben, nicht 403, für mandantenübergreifenden Zugriff.** Eine 403 enthüllt, dass die Ressource in einem anderen Mandanten existiert.

### Notiz löschen

```php
DELETE /notes/1
Authorization: Bearer <alice_token>
→ 204

// Mandantenübergreifendes Löschen
DELETE /notes/1
Authorization: Bearer <bob_token>
→ 404  // Notiz bleibt intakt; Bobs Token kann sie nicht erreichen
```

## Implementierungsmuster

```php
// Middleware extrahiert und verifiziert JWT
$claims = $verifier->verify($bearerToken);
$request = $request->withAttribute('tenant_id', $claims['tenant_id']);
$request = $request->withAttribute('user_id', $claims['sub']);

// Controller liest aus Request-Attributen (niemals aus Body)
$tenantId = (int) $request->getAttribute('tenant_id');

// Repository beschränkt immer auf Mandant
public function findById(int $id, int $tenantId): ?array
{
    $stmt = $this->db->prepare(
        'SELECT id, title, body, created_at FROM notes WHERE id = ? AND tenant_id = ?'
    );
    $stmt->execute([$id, $tenantId]);
    return $stmt->fetch() ?: null;
}

// Null-Rückgabe → 404-Antwort (niemals 403)
if ($note === null) {
    return $this->json->create(['error' => 'Not found'], 404);
}
```

## Token-Manipulations-Ablehnung

```php
// Angreifer erstellt manuell ein Token mit einer anderen tenant_id
$fakeToken = 'eyJhbGciOiJIUzI1NiJ9.tampered.invalidsignature';

GET /notes/1
Authorization: Bearer $fakeToken
→ 401  // Signaturverifizierung schlägt fehl
```

Der Server lehnt jedes Token ab, dessen HMAC-SHA256-Signatur nicht mit dem Server-Secret übereinstimmt.

---

## Was man NICHT tun sollte

| Anti-Muster | Risiko |
|---|---|
| `tenant_id` aus Request-Body oder Query-Parametern lesen | Angreifer setzt `tenant_id=2`, um auf Daten eines anderen Mandanten zuzugreifen |
| 403 bei mandantenübergreifendem Zugriff zurückgeben | Bestätigt, dass die Ressource in einem anderen Mandanten existiert — Informationsleck |
| `tenant_id` in Notizantworten einschließen | Exponiert interne Mandantentopologie; für den Client nicht notwendig |
| `AND tenant_id = ?` in Abfragen weglassen | Mandantenübergreifendes Leck — Angreifer mit gültigem Token sieht Daten aller Mandanten |
| JWT-Secret in Konfiguration neben Daten speichern | Secret-Kompromittierung ermöglicht das Fälschen von Tokens für beliebige Mandanten |
| `tenant_id` aus `X-Tenant-Id`-Header vertrauen | Header kann von jedem Client gesetzt werden; nur verifizierten JWT-Claims vertrauen |
