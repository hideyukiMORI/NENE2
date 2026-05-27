# How-to: Mass-Assignment-Schutz mit explizitem DTO

> **FT-Referenz**: FT256 (`NENE2-FT/masslog`) — Mass-Assignment-Schutz-Muster mit explizitem DTO-Whitelisting
> **ATK**: FT256 — Cracker-Mindset-Angriffstests (ATK-01 bis ATK-12)

Demonstriert, wie Mass-Assignment-Schwachstellen verhindert werden, indem ein explizites readonly-DTO verwendet wird, das nur die Felder whitelistet, die Aufrufer setzen dürfen. Serverseitig gesteuerte Felder (`role`, `is_active`, `created_at`, `id`) sind vom DTO ausgeschlossen und im Repository fest kodiert. Enthält eine vollständige Cracker-Mindset-Angriffsbewertung.

---

## Routen

| Methode | Pfad | Beschreibung |
|--------|----------|---------------------------|
| `POST` | `/users` | Benutzer erstellen (role=user) |
| `GET`  | `/users` | Alle Benutzer auflisten |

---

## Schema

```sql
CREATE TABLE IF NOT EXISTS users (
    id        INTEGER PRIMARY KEY AUTOINCREMENT,
    name      TEXT    NOT NULL,
    email     TEXT    NOT NULL UNIQUE,
    role      TEXT    NOT NULL DEFAULT 'user' CHECK (role IN ('user', 'admin')),
    is_active INTEGER NOT NULL DEFAULT 1,
    created_at TEXT   NOT NULL
);
```

`CHECK(role IN ('user', 'admin'))` ist ein DB-Sicherheitsnetz. Die Anwendung schreibt bei der Erstellung immer `'user'` in `role`, daher wird der Constraint im normalen Betrieb nie ausgelöst — er schützt vor Fehlern oder direktem DB-Zugriff.

---

## Das explizite DTO: Feld-Whitelisting

```php
/**
 * Explizites DTO für Benutzererstellung — nur name und email werden aus Benutzereingaben akzeptiert.
 *
 * role und is_active sind absichtlich ausgeschlossen: Sie müssen durch serverseitige
 * Geschäftslogik gesetzt werden, niemals aus dem Request-Body. Das ist der Mass-Assignment-Schutz.
 */
final readonly class CreateUserInput
{
    public function __construct(
        public string $name,
        public string $email,
    ) {}
}
```

Das DTO hat genau zwei Felder — `name` und `email`. Es gibt kein `role`-, `is_active`-, `created_at`- oder `id`-Feld. Ein Angreifer kann diese Felder nicht injizieren, weil der Konstruktor sie schlicht nicht akzeptiert.

**Warum das besser als eine Blockliste ist**:

| Ansatz | Sicherheitsmodell | Fehlermodus |
|---|---|---|
| Explizite Allowlist (DTO) | Unbekanntes standardmäßig ablehnen | Sicher — neue Felder müssen explizit hinzugefügt werden |
| Blockliste (`unset($body['role'])`) | Bekannt-Schädliches blockieren | Unsicher — neue sensible Felder werden vergessen |
| `array_intersect_key` | Auf bekannte Schlüssel filtern | Akzeptabel — gleich wie Allowlist wenn Schlüssel vollständig sind |

Ein explizites DTO versagt sicher: Das Hinzufügen einer neuen sensiblen Spalte zum Schema exponiert diese nicht automatisch — der Entwickler muss sie explizit zum DTO hinzufügen.

---

## Controller: explizite Feldextraktion

```php
private function createUser(ServerRequestInterface $request): ResponseInterface
{
    $body = json_decode((string) $request->getBody(), true);
    if (!is_array($body)) {
        return $this->problems->create($request, 'invalid-body', '...', 400);
    }

    $errors = [];

    if (!isset($body['name']) || !is_string($body['name']) || trim($body['name']) === '') {
        $errors[] = ['field' => 'name', 'code' => 'required', 'message' => 'name is required.'];
    }
    if (!isset($body['email']) || !is_string($body['email']) || !filter_var($body['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = ['field' => 'email', 'code' => 'invalid-email', 'message' => 'email must be a valid email address.'];
    }

    if ($errors !== []) {
        return $this->problems->create($request, 'validation-failed', 'Validation failed.', 422, null, ['errors' => $errors]);
    }

    // Nur erlaubte Felder werden gemappt — extra Felder (role, is_active, etc.) werden stillschweigend verworfen
    $input = new CreateUserInput(
        name:  trim((string) $body['name']),
        email: strtolower(trim((string) $body['email'])),
    );

    $user = $this->repo->create($input);
    return $this->json->create([...], 201);
}
```

Der Controller liest `$body['name']` und `$body['email']` explizit. Alle anderen Schlüssel in `$body` werden stillschweigend verworfen — sie werden nie gelesen oder weitergegeben.

E-Mail wird vor der DTO-Erstellung zu Kleinbuchstaben normalisiert (`strtolower`), um doppelte E-Mails zu verhindern, die sich nur in der Groß-/Kleinschreibung unterscheiden.

---

## Repository: serverseitig gesteuerte Felder

```php
public function create(CreateUserInput $input): User
{
    $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
    $id = $this->executor->insert(
        'INSERT INTO users (name, email, role, is_active, created_at) VALUES (?, ?, ?, ?, ?)',
        [$input->name, $input->email, 'user', 1, $now],  // role und is_active sind fest kodiert
    );

    return new User(
        id:        $id,
        name:      $input->name,
        email:     $input->email,
        role:      'user',    // fest kodiert, nicht aus $input
        isActive:  true,      // fest kodiert, nicht aus $input
        createdAt: $now,
    );
}
```

`'user'` und `1` sind Literalwerte im INSERT. Es gibt keine Möglichkeit, dass Benutzereingaben `role` oder `is_active` beeinflussen. Die `CreateUserInput`-DTO-Typsignatur erzwingt dies auf PHP-Typebene.

---

## ATK — Cracker-Mindset-Angriffstests (FT256)

### ATK-01 — Privilegieneskalation: `role: "admin"` im Request-Body injizieren

**Angriff**: `role` in den Request-Body einschließen, um einen Admin-Benutzer zu erstellen.

```json
{"name": "Attacker", "email": "attacker@example.com", "role": "admin"}
```

**Beobachtet**: `role` ist kein Feld in `CreateUserInput`. Der Controller liest nur `name` und `email` aus `$body`. Der extra Schlüssel wird stillschweigend verworfen. Der erstellte Benutzer hat `role = 'user'`.

**Urteil**: **BLOCKED** — explizite DTO-Feld-Whitelist verhindert Privilegieneskalation.

---

### ATK-02 — Kontostatus-Manipulation: `is_active: false` injizieren

**Angriff**: Benutzer mit `is_active = false` erstellen, um ein deaktiviertes Konto zu erstellen oder zu testen, ob das Feld beschreibbar ist.

```json
{"name": "Bob", "email": "bob@example.com", "is_active": false}
```

**Beobachtet**: `is_active` ist nicht in `CreateUserInput`. Der erstellte Benutzer hat `is_active = true` (fest kodiert im INSERT).

**Urteil**: **BLOCKED** — `is_active` wird nie aus dem Request gelesen.

---

### ATK-03 — Zeitstempel-Manipulation: `created_at` injizieren

**Angriff**: Den Erstellungszeitstempel des Benutzers zurückdatieren.

```json
{"name": "Carol", "email": "carol@example.com", "created_at": "2000-01-01 00:00:00"}
```

**Beobachtet**: `created_at` ist nicht in `CreateUserInput`. Das Repository generiert `$now` aus `DateTimeImmutable` zum Schreibzeitpunkt.

**Urteil**: **BLOCKED** — Audit-Zeitstempel werden serverseitig generiert, nicht clientseitig geliefert.

---

### ATK-04 — ID-Hijacking: `id: 9999` injizieren

**Angriff**: Einen Primärschlüssel vorab auswählen, um einen vorhandenen Datensatz zu überschreiben oder eine bekannte ID zu beanspruchen.

```json
{"name": "Dave", "email": "dave@example.com", "id": 9999}
```

**Beobachtet**: `id` ist nicht in `CreateUserInput`. Das INSERT verwendet `AUTOINCREMENT` — die `id` wird von SQLite zugewiesen, nicht aus einem benutzerseitig angegebenen Wert.

**Urteil**: **BLOCKED** — Primärschlüsselzuweisung erfolgt immer serverseitig.

---

### ATK-05 — SQL-Injection über Name oder E-Mail

**Angriff**: SQL-Metazeichen einbetten.

```json
{"name": "'; DROP TABLE users; --", "email": "sql@example.com"}
```

**Beobachtet**: Beide Felder werden als parametrisierte `?`-Platzhalter im INSERT gebunden. Das Injection-Payload wird als Literaltext gespeichert.

**Urteil**: **BLOCKED** — parametrisierte Abfragen verhindern SQL-Injection.

---

### ATK-06 — E-Mail-Groß-/Kleinschreibungs-Bypass: E-Mail in Großbuchstaben einreichen

**Angriff**: `ADMIN@EXAMPLE.COM` als anderen Benutzer als `admin@example.com` registrieren.

```json
{"name": "Eve", "email": "ADMIN@EXAMPLE.COM"}
```

**Beobachtet**: Der Controller wendet `strtolower()` an, bevor er an das DTO übergibt. Sowohl `ADMIN@EXAMPLE.COM` als auch `admin@example.com` normalisieren zu `admin@example.com`. Der `UNIQUE`-Constraint verhindert eine zweite Registrierung.

**Urteil**: **BLOCKED** — Groß-/Kleinschreibungsnormalisierung + UNIQUE-Constraint verhindern doppelte Konten.

---

### ATK-07 — Doppelte E-Mail: dieselbe Adresse zweimal registrieren

**Angriff**: Dieselbe E-Mail-Adresse registrieren, um einen Fehler auszulösen oder doppelte Konten zu erstellen.

```json
{"name": "Frank", "email": "frank@example.com"}
{"name": "FrankDuplicate", "email": "frank@example.com"}
```

**Beobachtet**: Die erste Anfrage gelingt mit `201`. Die zweite Anfrage löst einen SQLite-`UNIQUE`-Constraint-Verstoß aus. Die aktuelle Implementierung fängt diese Exception nicht ab — sie propagiert als unbehandelter Fehler.

**Urteil**: **EXPOSED** — den UNIQUE-Constraint-Verstoß abfangen und eine strukturierte `409 Conflict`- oder `422 Unprocessable Entity`-Antwort zurückgeben. Rohe DB-Fehler zu enthüllen ist ein Sicherheits- und UX-Problem.

---

### ATK-08 — XSS-Payload in Name oder E-Mail

**Angriff**: Ein Script-Tag speichern.

```json
{"name": "<script>alert(1)</script>", "email": "xss@example.com"}
```

**Beobachtet**: Inhalt wird unverändert gespeichert und wörtlich in JSON zurückgegeben. Die API kodiert die Ausgabe nicht HTML-mäßig.

**Urteil**: **BY DESIGN AKZEPTIERT** — JSON-APIs geben rohen Inhalt zurück. Die Rendering-Schicht muss vor dem Einfügen in HTML bereinigen.

---

### ATK-09 — Fehlende Pflichtfelder

**Angriff**: `name` oder `email` weglassen.

```json
{"email": "missing@example.com"}
{"name": "NoEmail"}
{}
```

**Beobachtet**: Jede gibt `422 Unprocessable Entity` mit einem strukturierten `errors`-Array zurück, das das fehlende Feld namentlich identifiziert.

**Urteil**: **BLOCKED** — explizite Präsenzprüfungen für jedes Pflichtfeld.

---

### ATK-10 — Typverwechslung: Name als Integer einreichen

**Angriff**: `name` als JSON-Zahl senden.

```json
{"name": 12345, "email": "typed@example.com"}
```

**Beobachtet**: `is_string($body['name'])` gibt `false` für Integer-Werte zurück. Die Anfrage gibt `422` mit `name is required` zurück.

**Urteil**: **BLOCKED** — `is_string()` lehnt Nicht-String-Typen ab.

---

### ATK-11 — Sehr langer Name oder E-Mail

**Angriff**: Einen Namen oder eine E-Mail mit mehr als 10.000 Zeichen einreichen.

```json
{"name": "aaaa...aaaa (10000 Zeichen)", "email": "x@example.com"}
```

**Beobachtet**: Die Anfrage gelingt mit `201`. Keine Längenvalidierung wird auf `name` oder `email` angewendet. SQLite speichert TEXT ohne inhärentes Längenlimit.

**Urteil**: **EXPOSED** — Längenvalidierung hinzufügen (z.B. `mb_strlen($name) > 255 → 422`). Request-Size-Middleware als äußeres Limit verwenden.

---

### ATK-12 — Mehrere Rollenwerte: als Array injizieren

**Angriff**: `role` als Array statt als String einreichen.

```json
{"name": "Grace", "email": "grace@example.com", "role": ["admin", "superuser"]}
```

**Beobachtet**: `role` wird überhaupt nicht aus `$body` gelesen. Ob es ein String, Array oder null ist, hat keinen Einfluss auf den erstellten Benutzer.

**Urteil**: **BLOCKED** — das DTO schließt `role` vollständig aus; sein Typ ist irrelevant.

---

## ATK-Zusammenfassung

| # | Angriffsvektor | Urteil |
|---|---------------|---------|
| ATK-01 | Privilegieneskalation über `role: "admin"` | BLOCKED |
| ATK-02 | Kontostatus-Manipulation über `is_active: false` | BLOCKED |
| ATK-03 | Zeitstempel-Rückdatierung über `created_at` | BLOCKED |
| ATK-04 | ID-Hijacking über `id: 9999` | BLOCKED |
| ATK-05 | SQL-Injection über Name/E-Mail | BLOCKED |
| ATK-06 | E-Mail-Groß-/Kleinschreibungs-Bypass (`ADMIN@EXAMPLE.COM`) | BLOCKED |
| ATK-07 | Doppelte E-Mail (kein graceful Fehler) | EXPOSED |
| ATK-08 | XSS-Payload im Namen | BY DESIGN AKZEPTIERT |
| ATK-09 | Fehlende Pflichtfelder | BLOCKED |
| ATK-10 | Typverwechslung (Name als Integer) | BLOCKED |
| ATK-11 | Sehr langer Name oder E-Mail (kein Längenlimit) | EXPOSED |
| ATK-12 | Role als Array | BLOCKED |

**Echte Schwachstellen, die vor der Produktion behoben werden müssen**:
1. **ATK-07** — UNIQUE-Constraint-Verstoß abfangen; `409 Conflict` mit benutzerfreundlicher Meldung zurückgeben
2. **ATK-11** — `mb_strlen`-Längenvalidierung für `name` und `email` hinzufügen

---

## Verwandte Anleitungen

- [`mass-assignment.md`](mass-assignment.md) — Übersicht über Mass-Assignment-Schutz-Muster
- [`enforce-resource-ownership.md`](enforce-resource-ownership.md) — eigentumsbasierte Abfragen zur IDOR-Prävention
- [`rbac.md`](rbac.md) — rollenbasierte Zugangskontrolle mit JWT-Claims
- [`user-profile-management.md`](user-profile-management.md) — Profilaktualisierung mit Feld-Whitelisting
