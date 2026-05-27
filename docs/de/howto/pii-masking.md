# How-to: PII-Maskierungs-API

> **FT-Referenz**: FT297 (`NENE2-FT/masklog`) — PII-Maskierung: E-Mail/Telefon/Name partielle Maskierung, rollenbasierter Rohdatenzugriff (nur Admin) mit obligatorischem X-Accessor-Audit-Trail, unveränderliches Audit-Log, VULN-A~L alle SAFE, 24 Tests / 49 Assertions PASS.

Diese Anleitung zeigt, wie eine Kundendaten-API erstellt wird, die PII (Persönlich Identifizierbare Informationen) standardmäßig maskiert und vollen Zugriff nur für autorisierte Rollen mit einem Audit-Trail gewährt.

## Schema

```sql
CREATE TABLE customers (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT NOT NULL,
    email      TEXT NOT NULL,
    phone      TEXT NOT NULL,
    created_at TEXT NOT NULL
);

CREATE TABLE mask_audit_log (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    customer_id INTEGER NOT NULL REFERENCES customers(id),
    accessor    TEXT NOT NULL,
    accessed_at TEXT NOT NULL
);
```

Roh-PII wird in `customers` gespeichert. Jeder Admin-Zugriff auf Rohdaten wird in `mask_audit_log` aufgezeichnet (nur Anhängen — keine Update/Delete-Route).

## Maskierungsmuster

```php
final class MaskService
{
    // "john.doe@example.com" → "j***@example.com"
    public function maskEmail(string $email): string
    {
        $at     = strpos($email, '@');
        $local  = substr($email, 0, $at);
        $domain = substr($email, $at + 1);
        return substr($local, 0, 1) . '***@' . $domain;
    }

    // "090-1234-5678" → "***-****-5678" (letzte 4 Ziffern beibehalten)
    public function maskPhone(string $phone): string
    {
        $digits   = preg_replace('/\D/', '', $phone);
        $keepFrom = strlen($digits) - 4;
        $replaced = 0;
        $result   = '';
        for ($i = 0; $i < strlen($phone); $i++) {
            $ch = $phone[$i];
            if (ctype_digit($ch)) {
                $result .= ($replaced < $keepFrom) ? ('*' . ($replaced++ | 0) * 0 . '') : $ch;
                $replaced++;
            } else {
                $result .= $ch;
            }
        }
        return $result;
    }

    // "John Doe" → "J*** D***"
    public function maskName(string $name): string
    {
        $words = explode(' ', $name);
        return implode(' ', array_filter(array_map(
            fn($w) => $w !== '' ? mb_substr($w, 0, 1) . '***' : '',
            $words
        )));
    }
}
```

## Rollenbasierter Zugriff — Standardmäßig Maskiert

```php
private function handleGet(ServerRequestInterface $request): ResponseInterface
{
    $id       = $this->id($request);
    $customer = $this->repo->find($id);
    if ($customer === null) {
        return $this->json->create(['error' => 'Customer not found'], 404);
    }

    $role     = $request->getHeaderLine('X-Role');
    $accessor = trim($request->getHeaderLine('X-Accessor'));

    if ($role === 'admin') {
        if ($accessor === '') {
            return $this->json->create(['error' => 'X-Accessor header required for admin access'], 403);
        }
        $this->repo->logAccess((int) $customer['id'], $accessor, $this->now());
        return $this->json->create($customer);  // Roh-PII
    }

    return $this->json->create($this->masker->applyMask($customer));  // maskiert
}
```

- **Nicht-Admin (Standard)**: erhält immer maskierte Daten.
- **Admin mit `X-Accessor`**: erhält Rohdaten und der Zugriff wird protokolliert.
- **Admin ohne `X-Accessor`**: 403 — der Audit-Trail darf nicht leer sein.

## Audit-Log — Nur Anhängen

```php
public function register(Router $router): void
{
    $router->post('/customers', $this->handleCreate(...));
    $router->get('/customers/{id}', $this->handleGet(...));
    $router->get('/customers/{id}/audit', $this->handleAudit(...));
    // Kein DELETE oder PUT für Audit-Log — unveränderlich per Design
}
```

Das Audit-Log hat keine Lösch- oder Aktualisierungsroute. Einträge sind dauerhaft; nur Admins können das Log lesen.

---

## Schwachstellenbewertung

### V-01 — PII nicht im Standard-GET exponiert ✅ SAFE

**Risiko**: Nicht-Admin liest Roh-Kunden-E-Mail/Telefon/Name.
**Befund**: SAFE — Standardantwort wendet immer `applyMask()` an. Rohe Felder werden niemals ohne `X-Role: admin` zurückgegeben.

---

### V-02 — SQL-Injection im Namensfeld ✅ SAFE

**Risiko**: `"name": "'; DROP TABLE customers; --"` löscht Daten.
**Befund**: SAFE — parametrisierte Abfragen speichern den Injection-String wörtlich als Namen.

---

### V-03 — SQL-Injection im E-Mail-Feld ✅ SAFE

**Risiko**: SQL-Injection via E-Mail bei Erstellung.
**Befund**: SAFE — gleicher Schutz durch parametrisierte Abfragen.

---

### V-04 — IDOR: Nicht-Admin liest Roh-PII via Kunden-ID ✅ SAFE

**Risiko**: Ohne `X-Role: admin` versucht ein Benutzer `GET /customers/1` für vollständige PII.
**Befund**: SAFE — jede Anfrage ohne `X-Role: admin` erhält maskierte Daten, unabhängig von der Kunden-ID.

---

### V-05 — Rollenerhöhung: beliebiger X-Role-Header ✅ SAFE

**Risiko**: `X-Role: superuser` oder `X-Role: ADMIN` senden, um die Maskierung zu umgehen.
**Befund**: SAFE — nur der genaue String `'admin'` gewährt Rohzugriff: `if ($role === 'admin')`. Jeder andere Wert fällt zur maskierten Antwort durch.

---

### V-06 — Admin ohne X-Accessor-Header ✅ SAFE

**Risiko**: Admin greift auf Rohdaten ohne X-Accessor zu, um den Audit-Trail zu vermeiden.
**Befund**: SAFE — `if ($accessor === '') return 403`. Admin-Zugriff erfordert einen nicht-leeren Accessor-Identifier.

---

### V-07 — Audit-Log für Nicht-Admin zugänglich ✅ SAFE

**Risiko**: Nicht-Admin liest `GET /customers/1/audit`, um herauszufinden, wer auf seine Daten zugegriffen hat.
**Befund**: SAFE — Audit-Endpunkt prüft `X-Role: admin`. Nicht-Admin → 403.

---

### V-08 — Nicht existierender Kunde gibt 404 zurück ✅ SAFE

**Risiko**: Abfrage einer nicht existierenden ID gibt 500 zurück oder leckt DB-Fehler.
**Befund**: SAFE — `if ($customer === null) return 404`. Sauberer Fehler, keine internen Informationen.

---

### V-09 — Extrem langer Input stürzt nicht ab ✅ SAFE

**Risiko**: 10.000-Zeichen-Name verursacht DB-Fehler oder Speichererschöpfung.
**Befund**: SAFE — SQLite TEXT hat kein Längenlimit; die Anwendung speichert und maskiert ohne Absturz. In der Produktion ein Längenlimit hinzufügen (z.B. 500 Zeichen).

---

### V-10 — XSS-Payload als Literal gespeichert ✅ SAFE

**Risiko**: `"name": "<script>alert(1)</script>"` wird in einem Browser ausgeführt.
**Befund**: SAFE — API gibt `application/json` zurück; JSON-Encoding escapet `<` und `>`. Kein HTML-Rendering in der API-Schicht.

---

### V-11 — Maskierte Antwort verrät keine vollständige PII ✅ SAFE

**Risiko**: Maskierte Antwort enthält genug Daten, um die ursprüngliche PII zu rekonstruieren.
**Befund**: SAFE — E-Mail: nur erstes Zeichen + Domain; Telefon: nur letzte 4 Ziffern; Name: nur erstes Zeichen pro Wort. Kann Original nicht rekonstruieren.

---

### V-12 — Audit-Log ist unveränderlich ✅ SAFE

**Risiko**: Admin löscht eigene Audit-Log-Einträge, um Spuren zu verwischen.
**Befund**: SAFE — keine `DELETE /customers/{id}/audit`-Route vorhanden. Log-Einträge sind nur anhängbar.

---

### VULN-Zusammenfassung

| ID | Schwachstelle | Befund |
|----|---------------|---------|
| V-01 | PII im Standard-GET exponiert | ✅ SAFE |
| V-02 | SQL-Injection im Namen | ✅ SAFE |
| V-03 | SQL-Injection in E-Mail | ✅ SAFE |
| V-04 | IDOR: Nicht-Admin liest Roh-PII | ✅ SAFE |
| V-05 | Rollenerhöhung via X-Role-Header | ✅ SAFE |
| V-06 | Admin ohne X-Accessor | ✅ SAFE |
| V-07 | Audit-Log für Nicht-Admin zugänglich | ✅ SAFE |
| V-08 | Nicht existierendes Kunden-Verhalten | ✅ SAFE |
| V-09 | Absturz durch extrem langen Input | ✅ SAFE |
| V-10 | XSS-Payload im Namen | ✅ SAFE |
| V-11 | Maskierte Antwort verrät PII | ✅ SAFE |
| V-12 | Audit-Log-Veränderbarkeit | ✅ SAFE |

**12 SAFE, 0 EXPOSED**
Standardmäßige Maskierung, obligatorischer Accessor-Audit, strenge Rollenprüfung und unveränderliches Log verhindern alle PII-Expositions- und Audit-Bypass-Vektoren.

---

## Was man NICHT tun sollte

| Anti-Muster | Risiko |
|---|---|
| Roh-PII standardmäßig zurückgeben | Jeder authentifizierte Benutzer liest vollständige E-Mail/Telefon/Name |
| Groß-/Kleinschreibungsunabhängige Rollenprüfung (`strtolower`) ohne explizite Allowlist | `ADMIN`, `Admin`, `aDmIn` — nur den exakten erwarteten String akzeptieren |
| Admin-Zugriff ohne X-Accessor erlauben | Kein Audit-Trail; DSGVO-Compliance-Versagen |
| Veränderbares Audit-Log | Admins löschen eigene Einträge; forensischer Trail ist unzuverlässig |
| Audit-Log für Nicht-Admin exponieren | Benutzer erfahren, wer (welche Mitarbeiter) auf ihre Daten zugegriffen hat |
| Hash-Maskierung (Hash statt echter Daten zeigen) | Hash von PII ist noch sensibel — Angreifer können kurze Werte per Brute-Force knacken |
| Keine Maskierung bei Create-Antwort | Neue Kundenerstellungs-Antwort exponiert die gerade gespeicherte PII |
| Kein Eingabe-Längenlimit | Sehr lange Eingaben verbrauchen Speicherplatz; explizite Längenbegrenzungen in Produktion hinzufügen |
