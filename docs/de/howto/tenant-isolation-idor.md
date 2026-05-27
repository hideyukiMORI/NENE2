# Anleitung: Mandantenisolierung & IDOR-Prävention

> **FT-Referenz**: FT318 (`NENE2-FT/isolationlog`) — Multi-Mandanten-Datenisolierung, mandantenübergreifende IDOR-Prävention, Header-Typverwirrungsabsicherung, Body-tenant_id-Injektions-Prävention, 34 Tests / 133 Assertions BESTANDEN.

Diese Anleitung zeigt, wie strikte mandantenebenenbasierte Datenisolierung durchgesetzt wird, sodass kein Mandant die Daten eines anderen lesen, ändern oder aufzählen kann — selbst wenn er Header oder Request-Bodies manipuliert.

## Schema

```sql
CREATE TABLE tenants (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT    NOT NULL,
    created_at TEXT    NOT NULL
);

CREATE TABLE notes (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id  INTEGER NOT NULL REFERENCES tenants(id),
    user_id    INTEGER NOT NULL,
    content    TEXT    NOT NULL,
    created_at TEXT    NOT NULL
);
```

## Authentifizierungsmodell

```
Admin-Endpunkte  → X-Admin-Key: <server_secret>       (z. B. env ADMIN_KEY)
Mandanten-Endpunkte → X-Tenant-Id: <int>  X-User-Id: <int>
```

### Header-Validierungsregeln

`X-Tenant-Id` und `X-User-Id` müssen die **strikte positive-Integer**-Validierung bestehen:

| Eingabe | Ergebnis |
|---------|----------|
| `"1"` (gültig) | ✅ Akzeptiert |
| `"0"` | ❌ 401 — muss > 0 sein |
| `"-1"` | ❌ 401 — Negativ abgelehnt |
| `"1.5"` | ❌ 401 — Float abgelehnt |
| `"+1"` | ❌ 401 — Vorzeichen-Präfix abgelehnt |
| `"1 OR 1=1"` | ❌ 401 — SQL-Injection-Versuch abgelehnt |
| `""` (nicht vorhanden) | ❌ 401 — Fehlender Header |
| `"99999999999999999999"` (20 Ziffern) | ❌ 401 — Überlauf abgelehnt |

```php
// Validierungsmuster mit ctype_digit + Bereichsprüfung
$raw = $request->getHeaderLine('X-Tenant-Id');
if (!ctype_digit($raw) || ($id = (int) $raw) <= 0 || strlen($raw) > 10) {
    return $this->json->create(['error' => 'Unauthorized'], 401);
}
```

## Admin-Endpunkte

```php
POST /tenants   X-Admin-Key: admin-secret
{"name": "Acme Corp"}
→ 201  {"id": 1, "name": "Acme Corp", "created_at": "..."}

GET  /tenants   X-Admin-Key: admin-secret
→ 200  {"total": 2, "tenants": [...]}

GET  /tenants/1  X-Admin-Key: admin-secret
→ 200  {"id": 1, "name": "Acme Corp", ...}

// Kein Admin-Schlüssel
POST /tenants  (kein X-Admin-Key)   → 401
POST /tenants  X-Admin-Key: falsch  → 401
```

## Mandanten-Endpunkte — IDOR-Prävention

### Notiz erstellen (server-zugewiesener Mandant)

```php
POST /notes  X-Tenant-Id: 1  X-User-Id: 42
{"content": "Hello"}
→ 201  {"id": 1, "tenant_id": 1, "content": "Hello", ...}
```

**Die `tenant_id` im Request-Body wird IMMER ignoriert.** Der Server verwendet nur den Header-Wert:

```php
// Angreifer sendet X-Tenant-Id: 1 aber Body versucht Mandant 2 einzuschleusen
POST /notes  X-Tenant-Id: 1
{"content": "Injection", "tenant_id": 2}  // ← ignoriert

→ 201  {"tenant_id": 1, ...}   // aus Header zugewiesen, nicht aus Body
```

### Mandantenübergreifendes IDOR — Gibt 404 zurück

```php
// Notiz 5 gehört zu Mandant 1
GET  /notes/5  X-Tenant-Id: 2  → 404   // IDOR blockiert
DELETE /notes/5  X-Tenant-Id: 2 → 404  // IDOR blockiert

// Eigentümer kann noch zugreifen
GET  /notes/5  X-Tenant-Id: 1  → 200   ✅
```

Alle Abfragen enthalten `WHERE tenant_id = $tenantId`. Eine fehlende Zeile gibt 404 zurück — **nicht 403** — um Existenz-Aufzählung zu verhindern.

### Listen-Isolierung

```php
// T1 hat 2 Notizen, T2 hat 1 Notiz
GET /notes  X-Tenant-Id: 1  → {"data": [note_A, note_B], "tenant_id": 1}
GET /notes  X-Tenant-Id: 2  → {"data": [note_X],         "tenant_id": 2}
// T2 sieht nie die Notizen von T1
```

```sql
SELECT * FROM notes WHERE tenant_id = ? ORDER BY id DESC LIMIT ?
-- Immer nach tenant_id aus dem validierten Header filtern
```

### Query-Parameter-Validierung

```php
GET /notes?limit=-1       → 422  // negativ
GET /notes?limit=10.5     → 422  // Float
GET /notes?limit=999999   → 422  // überschreitet Maximum (z. B. 100)
GET /notes?limit=99999999999999999999  → 422  // Überlauf
GET /notes                → 200  // Standard-Limit angewendet
```

## Notiz für nicht existierenden Mandanten erstellen

```php
POST /notes  X-Tenant-Id: 9999  X-User-Id: 1
{"content": "test"}
→ 422  // Mandant 9999 existiert nicht
```

---

## Was NICHT zu tun ist

| Anti-Muster | Risiko |
|---|---|
| `tenant_id` aus dem Request-Body vertrauen | Angreifer weist Notizen beliebigem Mandanten zu |
| 403 statt 404 bei IDOR zurückgeben | 403 enthüllt, dass die Ressource existiert; 404 verhindert Aufzählung |
| Header direkt casten: `(int) $header` ohne ctype_digit | `-1`, `+1`, `1.5`, Überlauf produzieren alle unerwartete Integer |
| Kein `WHERE tenant_id = ?` in Listen-Abfragen | Vollständiges mandantenübergreifendes Datenleck |
| Admin-Schlüssel in Client-Antworten teilen | Admin-Schlüssel muss nur serverseitig bleiben |
| `X-Tenant-Id: 0` erlauben | Null ist oft ein Standard-/ungecetzter Zustand; nur positive Integer akzeptieren |
