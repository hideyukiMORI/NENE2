# How to Add Data Masking

Mask PII fields (email, phone, name) in API responses by default, with an audited admin unmask path.

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

## Routes

| Method | Path | Description |
|--------|------|-------------|
| `POST` | `/customers` | Create customer (response is masked) |
| `GET` | `/customers/{id}` | Get customer (masked by default, unmasked for admin) |
| `GET` | `/customers/{id}/audit` | View audit log (admin only) |

## Masking Patterns

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
        // Preserve last 4 digits; mask everything else character-by-character
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

Examples:
- `john@example.com` → `j***@example.com`
- `555-123-4567` → `***-***-4567`
- `John Doe` → `J*** D***`

## Role-Based Unmask

The handler checks the `X-Role` header. Admin access requires `X-Accessor` to enforce audit trail:

```php
$role     = $request->getHeaderLine('X-Role');
$accessor = trim($request->getHeaderLine('X-Accessor'));

if ($role === 'admin') {
    if ($accessor === '') {
        return $this->json->create(['error' => 'X-Accessor header required'], 403);
    }
    $this->repo->logAccess($id, $accessor, $this->now());
    return $this->json->create($customer);        // raw PII
}

return $this->json->create($this->masker->applyMask($customer));  // masked
```

## Audit Log

Every admin unmask writes to `mask_audit_log`. The audit log has no DELETE or UPDATE route — it is append-only by design.

```php
public function logAccess(int $customerId, string $accessor, string $now): void
{
    $stmt = $this->pdo->prepare(
        'INSERT INTO mask_audit_log (customer_id, accessor, accessed_at) VALUES (?, ?, ?)'
    );
    $stmt->execute([$customerId, $accessor, $now]);
}
```

## Security Properties

- **Default masked**: all GET responses mask PII unless `X-Role: admin` is present.
- **Forced accessor**: admin unmask requires `X-Accessor`; 403 if absent — no anonymous admin access.
- **Immutable audit**: no route deletes or updates audit entries.
- **Parameterized storage**: PII is stored via prepared statements — SQL injection attempts are stored as literals.
- **Role precision**: only exact `admin` value grants unmask; `ADMIN`, `superuser`, etc. are treated as regular users.
