# Mass-Assignment-Schutz

Mass-Assignment ist eine Schwachstelle, bei der ein Angreifer dem Request-Body extra Felder hinzufügt — wie `role=admin` oder `is_active=false` — und der Server diese unbeabsichtigt persistiert.

NENE2 hat keine `create($body)`-Methode, die das versehentliche Auslösen vereinfachen würde. Dennoch ist das DTO-Whitelist-Muster die korrekte und explizite Abwehr.

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

Da `$body['role']` aus dem Request gelesen wird, erhält der Angreifer `role=admin` in der Datenbank.

## Die Abwehr: Explizite DTO-Whitelist

Ein DTO definieren, das nur die Felder enthält, die ein Benutzer angeben darf:

```php
/**
 * Nur name und email werden aus Benutzereingaben akzeptiert.
 * role und is_active werden durch serverseitige Logik gesetzt, niemals aus dem Request.
 */
final readonly class CreateUserInput
{
    public function __construct(
        public string $name,
        public string $email,
    ) {}
}
```

Im Controller nur die erlaubten Felder auf das DTO mappen:

```php
// ✅ Extra Felder (role, is_active, id, created_at) werden nie aus $body gelesen
$input = new CreateUserInput(
    name:  trim((string) $body['name']),
    email: strtolower(trim((string) $body['email'])),
);

$user = $this->repo->create($input);
```

Im Repository die DTO-Properties direkt verwenden:

```php
public function create(CreateUserInput $input): User
{
    $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
    $id = $this->executor->insert(
        'INSERT INTO users (name, email, role, is_active, created_at) VALUES (?, ?, ?, ?, ?)',
        [$input->name, $input->email, 'user', 1, $now], // role und is_active sind fest kodiert
    );
    // ...
}
```

Selbst wenn der Angreifer `role=admin` sendet, hat `$input` nur `name` und `email` — das extra Feld erreicht das INSERT nie.

## Abgedeckte Angriffsszenarien

| Feld | Angriffsabsicht | Abwehr |
|-------|---------------|---------|
| `role=admin` | Privilegieneskalation | `role` ist nicht in `CreateUserInput`; immer auf `'user'` im Repository gesetzt |
| `is_active=false` | Deaktiviertes Konto erstellen oder Benutzer sperren | `is_active` nicht im DTO; immer auf `1` gesetzt |
| `id=9999` | Primärschlüssel überschreiben | `id` nicht im DTO; automatisch von SQLite zugewiesen |
| `created_at=2000-01-01` | Audit-Zeitstempel fälschen | `created_at` nicht im DTO; immer auf aktuelle Zeit gesetzt |

## Antwortfeld-Kontrolle

Die Abwehr erstreckt sich auch auf die Antwort: DB-Zeilen niemals direkt zurückgeben. Explizit mappen, was eingeschlossen werden soll:

```php
return $this->json->create([
    'id'         => $user->id,
    'name'       => $user->name,
    'email'      => $user->email,
    'role'       => $user->role,
    'is_active'  => $user->isActive,
    'created_at' => $user->createdAt,
    // password_hash absichtlich ausgeschlossen
    // deleted_at absichtlich ausgeschlossen
], 201);
```

Auf die Abwesenheit sensibler Felder testen:

```php
$this->assertArrayNotHasKey('password_hash', $data);
$this->assertArrayNotHasKey('deleted_at', $data);
```

## Vertrauenswürdige interne Dienste

Wenn ein interner Dienst einen Admin-Benutzer erstellen muss (z.B. ein Bereitstellungs-Service), ein separates DTO verwenden:

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

Beim Zurückgeben einer Liste `createList()` statt `create()` verwenden:

```php
// ✅ JSON-Array auf oberster Ebene
return $this->json->createList(array_map(fn (User $u) => [...], $users));

// ✅ JSON-Objekt auf oberster Ebene
return $this->json->create(['id' => $user->id, ...], 201);
```

`create()` erwartet `array<string, mixed>` (ein Objekt). Die direkte Übergabe von `array_map()`-Ausgabe an `create()` verursacht einen PHPStan Level 8 Typfehler, weil `array_map` ein `list<T>` zurückgibt.

## Code-Review-Checkliste

- [ ] Request-Body-Felder werden auf ein DTO gemappt, bevor sie an das Repository übergeben werden
- [ ] DTO enthält nur Felder, die der Benutzer angeben darf
- [ ] Serverseitig gesteuerte Felder (`role`, `is_active`, Zeitstempel, Primärschlüssel) werden im Repository gesetzt, nicht aus `$body` gelesen
- [ ] Antwort listet explizit die zurückgegebenen Felder auf; kein Wildcard-`SELECT *` oder direkte Zeile-zu-JSON-Serialisierung
- [ ] Tests verifizieren, dass extra Request-Felder ignoriert werden und den persistierten Wert nicht beeinflussen
