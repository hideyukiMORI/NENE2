# HOWTO: Audit-Trail — Wer hat was geändert?

> **FT-Referenz**: FT268 (`NENE2-FT/auditlog`) — Append-only-Audit-Trail: JWT-Actor-Extraktion, Vor/Nach-Payload-Snapshots, unveränderliche Audit-Tabelle, Lücke beim unauthentifizierten Audit-Lesen.
>
> **ATK-Bewertung**: ATK-01 bis ATK-12 am Ende dieses Dokuments.

Diese Anleitung zeigt, wie Sie in einer NENE2-Anwendung einen Append-only-Audit-Trail implementieren.
Ein Audit-Trail protokolliert jede Create-, Update- und Delete-Operation mit dem Akteur (aus JWT-Claims),
der Ressource und einem Payload-Snapshot. Diese Einträge sind unveränderlich: Die API stellt niemals
UPDATE- oder DELETE-Endpunkte für die Audit-Tabelle bereit.

---

## Datenbankschema

```sql
-- Kein FK auf actor_id oder resource_id:
-- Audit-Einträge müssen das Löschen der beschriebenen Entitäten überdauern.
CREATE TABLE IF NOT EXISTS audit_log (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    actor_id      INTEGER NOT NULL,
    action        TEXT    NOT NULL,   -- 'created' | 'updated' | 'deleted'
    resource_type TEXT    NOT NULL,   -- z. B. 'task', 'order', 'user'
    resource_id   INTEGER NOT NULL,
    occurred_at   TEXT    NOT NULL,
    payload       TEXT    NOT NULL DEFAULT '{}'
);

-- Indizes für die häufigsten Abfragemuster
CREATE INDEX idx_audit_log_actor_id ON audit_log(actor_id);
CREATE INDEX idx_audit_log_resource ON audit_log(resource_type, resource_id);
```

Wichtige Designentscheidungen:
- **Keine FK-Constraints** — Audit-Einträge überdauern ihre Subjekte. Wird eine Aufgabe gelöscht, muss ihre Audit-Historie erhalten bleiben.
- **Unveränderlich per Design** — niemals UPDATE- oder DELETE-SQL-Pfade für diese Tabelle hinzufügen.
- **`action` als typisiertes Verb** — Vergangenheitsformen (`created`, `updated`, `deleted`) machen Log-Einträge selbstbeschreibend.

---

## AuditEntry-DTO und AuditRepository

```php
final readonly class AuditEntry
{
    public function __construct(
        public int    $id,
        public int    $actorId,
        public string $action,
        public string $resourceType,
        public int    $resourceId,
        public string $occurredAt,
        public string $payload,
    ) {}
}
```

```php
final readonly class AuditRepository
{
    public function __construct(
        private DatabaseQueryExecutorInterface $executor,
    ) {}

    /** @param array<string, mixed> $payload */
    public function record(
        int    $actorId,
        string $action,
        string $resourceType,
        int    $resourceId,
        array  $payload,
    ): AuditEntry {
        $now         = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $payloadJson = json_encode($payload, JSON_THROW_ON_ERROR);

        $this->executor->execute(
            'INSERT INTO audit_log (actor_id, action, resource_type, resource_id, occurred_at, payload)
             VALUES (?, ?, ?, ?, ?, ?)',
            [$actorId, $action, $resourceType, $resourceId, $now, $payloadJson],
        );

        return $this->findById((int) $this->executor->lastInsertId())
            ?? throw new \RuntimeException('Failed to record audit entry.');
    }

    /** @return list<AuditEntry> */
    public function findByResource(string $resourceType, int $resourceId, int $limit = 50): array
    {
        $rows = $this->executor->fetchAll(
            // ORDER BY id DESC, nicht occurred_at DESC: Zeitstempel mit Sekundengenauigkeit kollidieren,
            // wenn zwei Operationen in derselben Sekunde stattfinden.
            'SELECT * FROM audit_log
             WHERE resource_type = ? AND resource_id = ?
             ORDER BY id DESC LIMIT ?',
            [$resourceType, $resourceId, $limit],
        );
        return array_map(fn (array $row) => $this->hydrate($row), $rows);
    }
}
```

> **`ORDER BY id DESC` statt `occurred_at DESC`:** `occurred_at` hat Sekundengenauigkeit.
> Zwei Operationen in derselben Sekunde erhalten identische Zeitstempel, was die Sortierreihenfolge unvorhersehbar macht.
> Der auto-increment-`id`-Wert bewahrt die Einfügereihenfolge zuverlässig.

---

## Audits im Handler aufzeichnen

Audit-Events werden im Handler (UseCase-Äquivalent) aufgezeichnet, nicht im Repository.
Das Aufzeichnen im Repository verliert den Geschäftskontext ("Welche Operation hat das ausgelöst?").

### Create — initialen Snapshot aufzeichnen

```php
$task = $this->tasks->create($title, $body, $actorId);

// Audit: actor_id NICHT im Payload — er ist bereits im Audit-Eintrag selbst enthalten.
$this->audit->record($actorId, 'created', 'task', $task->id, [
    'title'  => $task->title,
    'body'   => $task->body,
    'status' => $task->status,
]);
```

### Update — Vor/Nach für Diff-Sichtbarkeit aufzeichnen

```php
$before = $this->tasks->findById($id);
// ... Eigentümerprüfung, Validierung ...
$after  = $this->tasks->update($id, $title, $body, $status);

$this->audit->record($actorId, 'updated', 'task', $id, [
    'before' => ['title' => $before->title, 'body' => $before->body, 'status' => $before->status],
    'after'  => ['title' => $after->title,  'body' => $after->body,  'status' => $after->status],
]);
```

### Delete — Snapshot vor dem Löschen aufzeichnen

```php
$task = $this->tasks->findById($id);
// ... Eigentümerprüfung ...
$this->tasks->delete($id);

// NACH dem Löschen aufzeichnen — die Task-Zeile ist weg, aber das Audit bleibt bestehen.
$this->audit->record($actorId, 'deleted', 'task', $id, [
    'title'  => $task->title,
    'status' => $task->status,
]);
```

---

## Akteur aus JWT-Claims

Den Akteur immer aus dem verifizierten JWT ableiten, nie aus dem Request-Body.

```php
private function actorId(ServerRequestInterface $request): ?int
{
    /** @var array<string, mixed>|null $claims */
    $claims = $request->getAttribute('nene2.auth.claims');

    if (!is_array($claims) || !isset($claims['sub']) || !is_int($claims['sub'])) {
        return null;
    }

    return $claims['sub'];
}
```

`nene2.auth.claims` wird von `BearerTokenMiddleware` nach der Token-Validierung gesetzt.
Ein Client kann keine gefälschte `actor_id` im Request-Body mitschicken und aufzeichnen lassen.

---

## Ausschluss sensibler Felder

**Niemals Passwörter, Tokens oder interne IDs in den Payload aufnehmen.**

```php
// ❌ Gibt sensible Daten preis und ist redundant
$this->audit->record($actorId, 'created', 'user', $user->id, [
    'email'         => $user->email,
    'password_hash' => $user->passwordHash,  // NIEMALS einschließen
    'actor_id'      => $actorId,              // redundant
]);

// ✅ Nur geschäftlich sichtbare Attribute
$this->audit->record($actorId, 'created', 'user', $user->id, [
    'email' => $user->email,
    'role'  => $user->role,
]);
```

---

## Unveränderliche Audit-API — keine Schreibendpunkte

```php
public function register(Router $router): void
{
    $router->get('/audit', $this->list(...));
    $router->get('/audit/{resource_type}/{resource_id}', $this->byResource(...));
    // POST, PUT, DELETE sind absichtlich nicht vorhanden
}
```

---

## Eigentümerprüfung vor jedem Schreibvorgang (und vor dem Audit)

```php
$task = $this->tasks->findById($id);
if ($task === null) {
    return $this->problems->create($request, 'not-found', 'Task not found.', 404);
}

// 404 statt 403 zurückgeben, um das Vorhandensein der Ressource nicht zu bestätigen.
if ($task->actorId !== $actorId) {
    return $this->problems->create($request, 'not-found', 'Task not found.', 404);
}

// Erst jetzt: Ändern + Audit
```

---

## Das Audit-Log abfragen

```php
// Historie für eine bestimmte Ressource
GET /audit/task/42

// Alle Events eines Akteurs
GET /audit?actor_id=7

// Alle Löschvorgänge über alle Ressourcentypen
GET /audit?action=deleted

// Sichere Paginierung
GET /audit?limit=20&offset=40
```

---

## Sicherheitsüberlegungen

| Risiko | Gegenmaßnahme |
|---|---|
| Audit-Log-Löschung | Kein DELETE-Endpunkt. Auf Tabellenebene: DELETE-Berechtigung für den App-DB-Benutzer entziehen, wenn möglich |
| Actor-Spoofing | Akteur kommt immer aus `nene2.auth.claims`, nie aus dem Request-Body |
| Sensibler Payload | Passwörter, Tokens, interne Schlüssel explizit aus dem Payload ausschließen |
| IDOR (cross-user Audit-Lesen) | `GET /audit` auf Admin-Rollen beschränken (in Kombination mit RBAC); oder nach actor_id des Anfragers filtern |
| Timing-Angriff / Benutzer-Enumeration | Als Dummy einen echten vorberechneten Argon2id-Hash verwenden, keinen fehlerhaften String |
| `LIMIT -1` DoS | Begrenzen: `max(1, min((int) $limit, 100))` |

---

## Dummy-Hash muss ein echter Argon2id-Hash sein

Ein fehlerhafter Dummy-Hash bewirkt, dass `password_verify()` sofort `false` zurückgibt (ohne die KDF auszuführen),
was einen ~20.000-fachen Zeitunterschied erzeugt, mit dem ein Angreifer gültige E-Mail-Adressen enumerieren kann.

```php
// ❌ Fehlerhaft — KDF wird übersprungen, gibt in ~0,001ms false zurück
$dummyHash = '$argon2id$v=19$m=65536,t=4,p=1$dummysaltdummysaltdummysalt$dummyhashvaluedummyhashvaluedummyh';

// ✅ Echter vorberechneter Hash — KDF läuft mit vollem Aufwand (~180ms)
// Einmal generieren: password_hash('dummy-constant-value', PASSWORD_ARGON2ID)
$dummyHash = '$argon2id$v=19$m=65536,t=4,p=1$VkZVLkx3L3FPaVA5NndVSA$vwBHHeAqq1DpGTf7G55ZPAUad+CGLvEJle2m5NA8ulA';
```

> Dieses Dummy-Hash-Muster wurde zuerst in [password-hashing.md](password-hashing.md) dokumentiert.
> **Dasselbe Prinzip gilt überall, wo `password_verify()` auf einen möglicherweise fehlenden Benutzer aufgerufen wird.**

---

## ATK-Bewertung (FT268)

Cracker-Mindset-Angriffstest gegen `NENE2-FT/auditlog`. Die Angriffsfläche: JWT-authentifiziertes Task-CRUD + unauthentifiziertes Audit-Log-Lesen.

### ATK-01 — JWT-None-Algorithm-Angriff 🚫 BLOCKED

**Angriff**: Gefälschtes JWT mit `"alg":"none"` und keiner Signatur, beliebiger `sub`-Claim.
```
Header: {"alg":"none","typ":"JWT"}
Payload: {"sub":1,"email":"admin@x.com","iat":9999999999,"exp":9999999999}
Signatur: (leer)
```
**Ergebnis**: `LocalBearerTokenVerifier` validiert mit HMAC-HS256 gegen das konfigurierte Secret. Tokens ohne gültige Signatur werden abgelehnt — `alg:none` wird nicht akzeptiert. → **401 Unauthorized**

---

### ATK-02 — JWT-Signatur-Manipulation 🚫 BLOCKED

**Angriff**: Ein gültiges JWT nehmen, das `sub`-Feld auf die ID eines anderen Benutzers ändern (z. B. `1` → `2`), ohne erneute Signierung neu kodieren.
**Ergebnis**: Die HMAC-HS256-Signatur stimmt nicht mehr mit dem geänderten Payload überein. `LocalBearerTokenVerifier` lehnt das Token ab. → **401 Unauthorized**

---

### ATK-03 — Abgelaufenes JWT wiederverwenden 🚫 BLOCKED

**Angriff**: Ein abgefangenes JWT nach Ablauf seines `exp`-Zeitstempels erneut verwenden.
**Ergebnis**: `BearerTokenMiddleware` / `LocalBearerTokenVerifier` prüft `exp`. Abgelaufene Tokens werden abgelehnt. → **401 Unauthorized**

---

### ATK-04 — IDOR: Zugriff auf die Aufgabe eines anderen Benutzers per ID ✅ BLOCKED

**Angriff**: Als Benutzer A (sub=1) authentifizieren, dann `PUT /tasks/3` aufrufen, wobei Aufgabe 3 Benutzer B (sub=2) gehört.
**Ergebnis**: Der Task-Route-Handler liest `task->actorId` und vergleicht mit `actorId` aus den JWT-Claims. Bei Nichtübereinstimmung wird → **404 Not Found** zurückgegeben (Ressourcenexistenz wird dem Angreifer nicht bestätigt).

---

### ATK-05 — IDOR: Aufgabe eines anderen Benutzers löschen ✅ BLOCKED

**Angriff**: Als Benutzer A authentifizieren, `DELETE /tasks/7` aufrufen, wobei Aufgabe 7 Benutzer B gehört.
**Ergebnis**: Gleiche Eigentümerprüfung wie ATK-04. `task->actorId !== $actorId` → **404 Not Found**.

---

### ATK-06 — Actor-ID-Einschleusung über Request-Body ✅ BLOCKED

**Angriff**: `POST /tasks` mit Body `{"title":"Injected","actor_id":999}`.
**Ergebnis**: Der Controller ignoriert `body['actor_id']` vollständig. Der Audit-Eintrag verwendet `actorId` aus `nene2.auth.claims['sub']` (JWT). Die Aufgabe wird dem authentifizierten Akteur zugeordnet — `actor_id:999` hat keine Wirkung.

---

### ATK-07 — Unauthentifiziertes Audit-Log-Lesen ⚠️ EXPOSED

**Angriff**: `GET /audit` ohne Authorization-Header.
**Ergebnis**: Die Audit-Log-Lese-Endpunkte (`GET /audit`, `GET /audit/{type}/{id}`) sind **nicht durch `BearerTokenMiddleware`** geschützt. Jeder unauthentifizierte Aufrufer kann die vollständige Audit-Historie aller Akteure und Ressourcen lesen.

**Auswirkung**: Vollständige Offenlegung von: Wer was wann mit welcher Ressource getan hat, einschließlich Vor/Nach-Payload-Snapshots. Bei einer Multi-Tenant-Anwendung ist dies eine kritische Informationsoffenlegung.

**Empfehlung**: Audit-Endpunkte auf admin-scoped JWT beschränken (z. B. `claims['role'] === 'admin'`), oder mindestens ein gültiges JWT voraussetzen. Das Audit-Präfix zu den geschützten Routen von `BearerTokenMiddleware` hinzufügen.

---

### ATK-08 — Audit-Log-Cross-Actor-Enumeration via ?actor_id ⚠️ EXPOSED

**Angriff**: `GET /audit?actor_id=2` (oder 1..N enumerieren) — liest alle Audit-Einträge für beliebige actor_ids.
**Ergebnis**: Keine Autorisierungsprüfung auf den `actor_id`-Filter. Der Angreifer enumeriert alle Benutzer-IDs und ruft deren vollständige Audit-Historie ab. Verkettet mit ATK-07 (unauthentifizierter Zugriff).
**Empfehlung**: Wenn Audit auf authentifizierte Benutzer beschränkt ist (nicht Admin), nach dem `sub` des authentifizierten Benutzers filtern — Aufrufer können keine Logs anderer Akteure abfragen. Admins sehen alles.

---

### ATK-09 — SQL-Injection in Audit-Suchparametern 🚫 BLOCKED

**Angriff**: `GET /audit?action=deleted';DROP TABLE audit_log;--&resource_type=task`
**Ergebnis**: `$action` und `$resourceType` werden als `?`-Parameter in der SQL-Abfrage gebunden. Keine String-Interpolation. SQLite empfängt `WHERE action = ?` mit dem eingeschleusten String als Wert — was schlicht 0 Zeilen zurückgibt. Die Tabelle ist sicher. → **200 OK (leer)**

---

### ATK-10 — Limit -1 / Großes Limit DoS ✅ BLOCKED

**Angriff**: `GET /audit?limit=-1` oder `GET /audit?limit=99999`.
**Ergebnis**: `max(1, min((int) ($q['limit'] ?? 50), 100))` begrenzt auf `[1, 100]`. Negative und überdimensionierte Limits werden stillschweigend begrenzt. → **200 OK (max. 100 Einträge)**

---

### ATK-11 — Login-Brute-Force (kein Rate-Limiting) ⚠️ EXPOSED

**Angriff**: Schnelle aufeinanderfolgende `POST /auth/login`-Versuche mit derselben E-Mail-Adresse und verschiedenen Passwörtern.
**Ergebnis**: Kein Rate-Limiting, keine Sperrung, kein CAPTCHA. Ein Angreifer kann Passwörter unbegrenzt iterieren. Die Argon2id-KDF verlangsamt jeden Versuch auf ~180ms, was Brute-Force bei starken Passwörtern unpraktikabel macht, aber bei schwachen noch möglich ist.
**Empfehlung**: `ThrottleMiddleware` auf `/auth/login` hinzufügen (z. B. 5 Versuche / 15 Min pro IP). Fehlgeschlagene Versuche mit request_id für Monitoring protokollieren.

---

### ATK-12 — Einschleusung beliebiger Statuswerte ⚠️ EXPOSED

**Angriff**: `PUT /tasks/1` mit Body `{"status":"<script>alert(1)</script>"}` oder `{"status":"admin_override"}`.
**Ergebnis**: Der Handler akzeptiert jeden nicht-leeren String als `status`. Das Repository schreibt ihn unverändert. Die Aufgabe wird mit `status="<script>alert(1)</script>"` aktualisiert. Keine Enum-Validierung, keine Allowlist.
**Auswirkung**: Stored XSS, wenn der Status unescaped im Browser gerendert wird. Beschädigtes Domain-Modell, wenn die Business-Logik `status` in `{open, closed, in_progress}` erwartet.
**Empfehlung**: Status gegen eine Allowlist oder ein PHP-BackedEnum validieren:
```php
$validStatuses = ['open', 'in_progress', 'closed'];
if (!in_array($status, $validStatuses, true)) {
    return $this->problems->create($request, 'validation-failed', 'Validation Failed', 422, null, [
        'errors' => [['field' => 'status', 'code' => 'invalid', 'message' => 'status must be one of: open, in_progress, closed']],
    ]);
}
```

---

### ATK-Zusammenfassung

| ID | Angriff | Ergebnis |
|----|---------|----------|
| ATK-01 | JWT `alg:none` | 🚫 BLOCKED |
| ATK-02 | JWT-Signatur-Manipulation | 🚫 BLOCKED |
| ATK-03 | Abgelaufenes JWT wiederverwenden | 🚫 BLOCKED |
| ATK-04 | IDOR: Zugriff auf fremde Aufgabe | ✅ BLOCKED |
| ATK-05 | IDOR: Fremde Aufgabe löschen | ✅ BLOCKED |
| ATK-06 | Actor-ID-Einschleusung über Body | ✅ BLOCKED |
| ATK-07 | Unauthentifiziertes Audit-Log-Lesen | ⚠️ EXPOSED |
| ATK-08 | Cross-Actor-Audit-Enumeration | ⚠️ EXPOSED |
| ATK-09 | SQL-Injection in Audit-Suche | 🚫 BLOCKED |
| ATK-10 | Limit -1 / überdimensioniertes Limit DoS | ✅ BLOCKED |
| ATK-11 | Login-Brute-Force (kein Rate-Limit) | ⚠️ EXPOSED |
| ATK-12 | Einschleusung beliebiger Statuswerte | ⚠️ EXPOSED |

**9 BLOCKED / SAFE, 4 EXPOSED** (ATK-07, 08 sind durch dieselbe unauthentifizierte Audit-Lese-Lücke verkettet).

Der kritische Fund ist **ATK-07**: Die Audit-Log-Endpunkte haben keinen Authentifizierungs-Guard und geben die vollständige Akteuraktivitätshistorie an jeden unauthentifizierten Aufrufer preis. ATK-12 (Status-Allowlist) und ATK-11 (Rate-Limiting) sind Standard-Härtungslücken. Keine SQL-Injection- oder JWT-Fälschungsvektoren wurden gefunden.
