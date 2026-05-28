# Anleitung: Mandantenisolierung & mandantenübergreifende IDOR-Prävention

**FT179 — isolationlog**

Verhinderung mandantenübergreifender Datenlecks in Multi-Mandanten-APIs —
gescope SQL-Abfragen, header-basierte Identität und Body-Injektions-Prävention.

---

## Die Bedrohung: Mandantenübergreifendes IDOR

In einem Multi-Mandanten-System gehört jede Ressource zu einem Mandanten.
Ein Angreifer, der ein Mandantenkonto kontrolliert, testet IDs von anderen Mandanten:

```
GET /notes/42          X-Tenant-Id: 2   ← Angreifer ist Mandant 2
                                         Notiz 42 gehört zu Mandant 1
```

Wenn der Server die Notiz zurückgibt, hat der Angreifer die Daten eines anderen Mandanten gelesen —
eine **Insecure Direct Object Reference (IDOR)** an der Mandantengrenze.

---

## Das Isolierungsmuster

### 1. Alle Lesevorgänge auf SQL-Ebene scopen

Niemals nur nach ID abfragen. Immer `AND tenant_id = ?` hinzufügen:

```php
// ❌ FALSCH — ID allein, mandantenübergreifend lesbar
'SELECT * FROM notes WHERE id = ?'

// ✅ KORREKT — ID + Mandant in SQL durchgesetzt
'SELECT * FROM notes WHERE id = ? AND tenant_id = ?'
```

Dies gibt `null` für mandantenübergreifenden Zugriff zurück, was zu einem 404 wird.
Der Angreifer erfährt nichts über Notiz 42 — nicht einmal ob sie existiert.

### 2. Listen-Abfragen sind immer gescoped

```php
// ❌ FALSCH — könnte mit ?tenant_id=... Injektion ergänzt werden
'SELECT * FROM notes ORDER BY id DESC LIMIT ?'

// ✅ KORREKT — WHERE tenant_id = ? ist nie optional
'SELECT * FROM notes WHERE tenant_id = ? ORDER BY id DESC LIMIT ?'
```

### 3. Löschen verwendet dasselbe Muster

```sql
DELETE FROM notes WHERE id = ? AND tenant_id = ?
```

`rowCount()` gibt 0 zurück, wenn die Notiz nicht zum Mandanten gehört → 404.

---

## Header-basierte Mandantenidentität

`X-Tenant-Id` + `X-User-Id`-Header für mandantenabgegrenzte Endpunkte verwenden.
Beide mit `V::userId()` validieren (ctype_digit + Überlaufschutz + > 0):

```php
private function resolveTenantUser(ServerRequestInterface $request): array
{
    $tenantId = V::userId($request->getHeaderLine('X-Tenant-Id'));
    $userId   = V::userId($request->getHeaderLine('X-User-Id'));

    return [$tenantId, $userId];
}
```

`V::userId()` lehnt ab:
- Leerer String (`ctype_digit('') === false`)
- Null (`id <= 0`)
- Negativ (`'-'` schlägt bei `ctype_digit` fehl)
- Float-String (`'1.5'` schlägt bei `ctype_digit` fehl)
- 20+ Ziffern Überlauf (strlen > 18 Schutz)
- SQL-Injection-Versuche (`'1 OR 1=1'` schlägt bei `ctype_digit` fehl)

---

## Body-Injektions-Prävention

Angreifer können `tenant_id` im POST-Body einschließen, um zu versuchen, eine
Ressource einem anderen Mandanten zuzuweisen:

```json
POST /notes
X-Tenant-Id: 1
{ "content": "Injection", "tenant_id": 99 }
```

**Niemals `tenant_id` aus dem Body lesen.** Immer den server-validierten Header verwenden:

```php
// ATK-04: body['tenant_id'] wird nie gelesen — immer $tenantId aus Header verwenden
$note = $this->notes->create($tenantId, $userId, $content, date('c'));
//                            ^^^^^^^^^
//                            von V::userId(X-Tenant-Id), nicht von $body
```

---

## Mandanten-Existenzprüfung beim Schreiben

Vor dem Erstellen einer Ressource prüfen, ob der Mandant existiert:

```php
if (!$this->tenants->exists($tenantId)) {
    return $this->responseFactory->create(['error' => 'Tenant not found.'], 422);
}
```

Ohne diese Prüfung würden Notizen für Ghost-Mandanten-IDs erstellt, die nicht in der
Mandanten-Tabelle existieren, was die referenzielle Integrität bricht.

---

## Angriffs-Checkliste (ATK-01 bis ATK-12)

| # | Test | Erwartung |
|---|------|-----------|
| ATK-01 | Keine Auth-Header | 401 |
| ATK-02 | Mandantenübergreifendes GET (IDOR) | 404 — Notiz existiert, aber nicht für diesen Mandanten |
| ATK-03 | X-Tenant-Id: `"1"`, `1.5`, `+1`, `1 OR 1=1` | 401 — V::userId lehnt ab |
| ATK-04 | POST-Body enthält `tenant_id: 99` | 201 — Body-tenant_id ignoriert |
| ATK-05 | Mandantenübergreifendes DELETE | 404 — Notiz nicht gelöscht |
| ATK-06 | X-Tenant-Id: `0`, `-1` | 401 |
| ATK-07 | X-Tenant-Id: 20-stelliger Überlauf | 401 |
| ATK-08 | Mandantenerstellung ohne X-Admin-Key | 401 |
| ATK-09 | Falscher X-Admin-Key | 401 |
| ATK-10 | Notiz für nicht existierende Mandanten-ID | 422 |
| ATK-11 | Liste: T1 sieht nur T1-Notizen, nicht T2's | Durch SQL WHERE tenant_id durchgesetzt |
| ATK-12 | `?limit=-1`, `?limit=10.5`, 20-stelliges Limit | 422 — V::queryInt-Guards |

---

## Antwortstrategie: 404 nicht 403

Wenn ein mandantenübergreifendes IDOR erkannt wird, **404** zurückgeben — nicht 403 Verboten.

- `403` lüftet Existenz: „die Ressource existiert, aber Sie können nicht darauf zugreifen"
- `404` enthüllt nichts: „keine solche Ressource für diesen Mandanten"

Dies verhindert Mandanten-Aufzählungsangriffe.
