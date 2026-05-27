# Mass-Assignment-Schutz

Mass Assignment ist eine Schwachstelle, bei der ein Angreifer dem Request-Body zusätzliche Felder hinzufügt — wie `role=admin` oder `is_active=false` — und der Server sie unbeabsichtigt persistiert.

NENE2 hat keine `create($body)`-Zaubermethode, die dies versehentlich leicht auslösbar machen würde. Trotzdem ist das DTO-Whitelist-Muster die korrekte und explizite Verteidigung.

## Die Schwachstelle

```php
// ❌ Gefährlich: $body direkt an INSERT übergeben
$body = json_decode((string) $request->getBody(), true);

$this->executor->insert(
    'INSERT INTO users (name, email, role, is_active) VALUES (?, ?, ?, ?)',
    [$body['name'], $body['email'], $body['role'] ?? 'user', $body['is_active'] ?? 1],
);
```

Ein Angreifer sendet:

```json
{
  "name": "Attacker",
  "email": "attacker@example.com",
  "role": "admin"
}
```

Da `$body['role']` aus der Anfrage gelesen wird, erhält der Angreifer `role=admin` in der Datenbank.

## Die Verteidigung: Explizite DTO-Allowlist

Ein DTO definieren, das nur die Felder enthält, die ein Benutzer liefern darf:

```php
/**
 * Nur name und email werden aus Benutzereingaben akzeptiert.
 * role und is_active werden durch serverseitige Logik gesetzt, niemals aus der Anfrage.
 */
final readonly class CreateUserInput
{
    public function __construct(
        public string $name,
        public string $email,
    ) {}
}
```

Im Controller nur die erlaubten Felder auf das DTO abbilden:

```php
// ✅ Zusätzliche Felder (role, is_active, id, created_at) werden niemals aus $body gelesen
$input = new CreateUserInput(
    name:  trim((string) $body['name']),
    email: strtolower(trim((string) $body['email'])),
);

$user = $this->repo->create($input);
```

Im Repository die DTO-Eigenschaften direkt verwenden:

```php
public function create(CreateUserInput $input): User
{
    $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
    $id = $this->executor->insert(
        'INSERT INTO users (name, email, role, is_active, created_at) VALUES (?, ?, ?, ?, ?)',
        [$input->name, $input->email, 'user', 1, $now], // role und is_active sind hardcodiert
    );
    // ...
}
```

Selbst wenn der Angreifer `role=admin` sendet, hat `$input` nur `name` und `email` — das zusätzliche Feld erreicht den INSERT niemals.

## Behandelte Angriffsszenarien

| Feld | Angriffsabsicht | Verteidigung |
|------|-----------------|-------------|
| `role=admin` | Rechteerweiterung | `role` ist nicht in `CreateUserInput`; immer auf `'user'` im Repository gesetzt |
| `is_active=false` | Deaktiviertes Konto erstellen oder Benutzer sperren | `is_active` nicht im DTO; immer auf `1` gesetzt |
| `id=9999` | Primärschlüssel überschreiben | `id` nicht im DTO; automatisch von SQLite vergeben |
| `created_at=2000-01-01` | Audit-Zeitstempel fälschen | `created_at` nicht im DTO; immer auf aktuelle Zeit gesetzt |

## Response-Feld-Kontrolle

Die Verteidigung erstreckt sich auf die Response: niemals DB-Zeilen direkt zurückgeben. Explizit abbilden, was eingeschlossen werden soll:

```php
return $this->json->create([
    'id'         => $user->id,
    'name'       => $user->name,
    'email'      => $user->email,
    'role'       => $user->role,
    'is_active'  => $user->isActive,
    'created_at' => $user->createdAt,
    // password_hash bewusst ausgeschlossen
    // deleted_at bewusst ausgeschlossen
], 201);
```

Auf das Fehlen sensibler Felder testen:

```php
$this->assertArrayNotHasKey('password_hash', $data);
$this->assertArrayNotHasKey('deleted_at', $data);
```

## Vertrauenswürdige interne Dienste

Wenn ein interner Dienst einen Admin-Benutzer erstellen muss (z.B. ein Bereitstellungsdienst), ein separates DTO verwenden:

```php
final readonly class AdminCreateUserInput
{
    public function __construct(
        public string $name,
        public string $email,
        public string $role,   // nur für interne Aufrufer erlaubt
        public bool $isActive,
    ) {}
}
```

Dieses DTO nur aus Code-Pfaden aufrufen, die die Identität des Aufrufers bereits verifiziert haben (z.B. Machine-API-Key, interne Service-Auth). Niemals einen öffentlichen HTTP-Endpunkt exponieren, der `AdminCreateUserInput` direkt akzeptiert.

## `create()` vs `createList()` für Antworten

Wenn eine Liste zurückgegeben wird, `createList()` statt `create()` verwenden:

```php
// ✅ Top-Level JSON-Array
return $this->json->createList(array_map(fn (User $u) => [...], $users));

// ✅ Top-Level JSON-Objekt
return $this->json->create(['id' => $user->id, ...], 201);
```

`create()` erwartet `array<string, mixed>` (ein Objekt). Die Ausgabe von `array_map()` direkt an `create()` übergeben verursacht einen PHPStan-Level-8-Typfehler, da `array_map` `list<T>` zurückgibt.

## Code-Review-Checkliste

- [ ] Request-Body-Felder werden vor der Übergabe an das Repository auf ein DTO abgebildet
- [ ] DTO enthält nur Felder, die der Benutzer liefern darf
- [ ] Server-gesteuerte Felder (`role`, `is_active`, Zeitstempel, Primärschlüssel) werden im Repository gesetzt, nicht aus `$body` gelesen
- [ ] Response listet zurückgegebene Felder explizit auf; kein Wildcard-`SELECT *` oder direkte Zeile-zu-JSON-Serialisierung
- [ ] Tests verifizieren, dass zusätzliche Request-Felder ignoriert werden und den persistierten Wert nicht beeinflussen
