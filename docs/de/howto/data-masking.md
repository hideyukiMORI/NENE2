# How-to: Datenmaskierung hinzufügen

PII-Felder (E-Mail, Telefon, Name) in API-Antworten standardmäßig maskieren, mit einem auditierten Admin-Demaskierungs-Pfad.

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

## Routen

| Methode | Pfad | Beschreibung |
|---------|------|--------------|
| `POST` | `/customers` | Kunden erstellen (Antwort ist maskiert) |
| `GET` | `/customers/{id}` | Kunden abrufen (standardmäßig maskiert, demaskiert für Admin) |
| `GET` | `/customers/{id}/audit` | Auditprotokoll anzeigen (nur Admin) |

## Maskierungsmuster

```php
class MaskService
{
    public function maskEmail(string $email): string
    {
        $at     = strpos($email, '@');
        $local  = substr($email, 0, $at);
        $domain = substr($email, $at + 1);
        return substr($local, 0, 1) . '***@' . $domain;
    }

    public function maskPhone(string $phone): string
    {
        // Letzte 4 Stellen beibehalten; alles andere zeichenweise maskieren
        $digits  = preg_replace('/\D/', '', $phone) ?? '';
        $keepFrom = strlen($digits) - 4;
        $replaced = 0;
        $result   = '';
        for ($i = 0; $i < strlen($phone); $i++) {
            $ch = $phone[$i];
            if (ctype_digit($ch)) {
                $result .= ($replaced < $keepFrom) ? '*' : $ch;
                if (ctype_digit($ch)) { $replaced++; }
            } else {
                $result .= $ch;
            }
        }
        return $result;
    }

    public function maskName(string $name): string
    {
        return implode(' ', array_map(
            fn($w) => mb_substr($w, 0, 1) . '***',
            array_filter(explode(' ', $name))
        ));
    }
}
```

Beispiele:
- `john@example.com` → `j***@example.com`
- `555-123-4567` → `***-***-4567`
- `John Doe` → `J*** D***`

## Rollenbasierte Demaskierung

Der Handler prüft den `X-Role`-Header. Der Admin-Zugriff erfordert `X-Accessor` zur Erzwingung des Prüfpfads:

```php
$role     = $request->getHeaderLine('X-Role');
$accessor = trim($request->getHeaderLine('X-Accessor'));

if ($role === 'admin') {
    if ($accessor === '') {
        return $this->json->create(['error' => 'X-Accessor header required'], 403);
    }
    $this->repo->logAccess($id, $accessor, $this->now());
    return $this->json->create($customer);        // Rohe PII
}

return $this->json->create($this->masker->applyMask($customer));  // Maskiert
```

## Auditprotokoll

Jede Admin-Demaskierung schreibt in `mask_audit_log`. Das Auditprotokoll hat keine DELETE- oder UPDATE-Route — es ist by Design append-only.

```php
public function logAccess(int $customerId, string $accessor, string $now): void
{
    $stmt = $this->pdo->prepare(
        'INSERT INTO mask_audit_log (customer_id, accessor, accessed_at) VALUES (?, ?, ?)'
    );
    $stmt->execute([$customerId, $accessor, $now]);
}
```

## Sicherheitseigenschaften

- **Standardmäßig maskiert**: Alle GET-Antworten maskieren PII, es sei denn, `X-Role: admin` ist vorhanden.
- **Accessor erzwungen**: Admin-Demaskierung erfordert `X-Accessor`; 403 wenn abwesend — kein anonymer Admin-Zugriff.
- **Unveränderliches Audit**: Keine Route löscht oder aktualisiert Audit-Einträge.
- **Parametrisierter Speicher**: PII wird via Prepared Statements gespeichert — SQL-Injection-Versuche werden als Literale gespeichert.
- **Rollengenauigkeit**: Nur der exakte Wert `admin` gewährt Demaskierung; `ADMIN`, `superuser` usw. werden als normale Benutzer behandelt.
