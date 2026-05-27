# How-to: Delegierte Zugriffserteilungen

> **FT-Referenz**: FT282 (`NENE2-FT/grantlog`) — Delegierte Zugriffserteilungen: bereichsbegrenzte (read/write/admin) zeitlich begrenzte Ressourcenzugriffe, UNIQUE(grantor, grantee, resource) + CHECK(grantor != grantee), IDOR → 404, Soft-Delete-Widerruf, Nutzungszähler, GrantScope.satisfies()-Hierarchie, 23 Tests / 71 Assertions PASS.
>
> Ebenfalls validiert in FT176 — ursprüngliche Implementierung.

Benutzerindividuelle, zeitlich begrenzte, widerrufbare Zugriffsdelegation — ein Erteiler (Grantor) gewährt einem Empfänger (Grantee) bereichsbegrenzten Zugriff auf eine benannte Ressource für ein begrenztes Zeitfenster.

---

## Überblick

Delegierte Zugriffserteilungen ermöglichen es einem Benutzer (`grantor`), einem anderen Benutzer (`grantee`) zeitlich begrenzten, bereichsbegrenzten Zugriff auf einen Ressourcenbezeichner zu gewähren. Beispiel: "Dokument:42 als Nur-Lese-Zugriff mit Benutzer 7 teilen, läuft in 24 Stunden ab, jederzeit widerrufbar."

Wesentliche Eigenschaften:

- **Mehrere Parteien** — Erteiler und Empfänger sind immer verschiedene Benutzer; Selbstgewährungen werden abgelehnt.
- **Zustandsmaschine** — aktiv → widerrufen (einseitig); der abgelaufene Zustand wird aus `expires_at` berechnet.
- **Opake Ressource** — `resource` ist ein Freiformat-String; der Server speichert ihn unverändert.
- **Idempotente Eindeutigkeit** — eine eindeutige Erteilung pro `(grantor_id, grantee_id, resource)`.
- **IDOR-sicher** — alle Eigentumsüberprüfungen geben 404 zurück, nicht 403, um Existenz-Enumeration zu verhindern.

---

## Schema

```sql
CREATE TABLE grants (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    grantor_id  INTEGER NOT NULL,
    grantee_id  INTEGER NOT NULL,
    resource    TEXT    NOT NULL,
    scope       TEXT    NOT NULL DEFAULT 'read',
    expires_at  TEXT    NOT NULL,
    revoked_at  TEXT,
    used_count  INTEGER NOT NULL DEFAULT 0,
    created_at  TEXT    NOT NULL,
    UNIQUE (grantor_id, grantee_id, resource),
    CHECK (scope IN ('read', 'write', 'admin')),
    CHECK (grantor_id != grantee_id)
);
```

`CHECK (grantor_id != grantee_id)` ist eine Tiefenschutzmaßnahme — Selbstgewährung muss auch auf Anwendungsebene abgelehnt werden, um eine klare Fehlermeldung zu liefern.

---

## Domain-Schicht

### GrantScope-Enum mit Hierarchie

```php
enum GrantScope: string
{
    case Read  = 'read';
    case Write = 'write';
    case Admin = 'admin';

    public function satisfies(self $required): bool
    {
        $rank = [self::Read->value => 0, self::Write->value => 1, self::Admin->value => 2];
        return $rank[$this->value] >= $rank[$required->value];
    }
}
```

### Grant-Entity — berechnete Zustandsmethoden

```php
final readonly class Grant
{
    public function isExpired(string $now): bool  { return $this->expiresAt <= $now; }
    public function isRevoked(): bool             { return $this->revokedAt !== null; }
    public function isActive(string $now): bool   { return !$this->isExpired($now) && !$this->isRevoked(); }
}
```

**Zuerst auf Widerruf prüfen**, dann auf Ablauf — beide Pfade geben 403 zurück, aber mit unterschiedlichen Fehlerkörpern, sodass Empfänger verstehen, warum der Zugriff verweigert wurde, ohne Systeminterna preiszugeben.

---

## HTTP-Endpunkte

| Methode | Pfad | Auth | Zweck |
|---------|------|------|-------|
| `POST` | `/grants` | `X-User-Id` (Erteiler) | Erteilung erstellen |
| `GET` | `/grants/issued` | `X-User-Id` | Vom Aufrufer ausgestellte Erteilungen auflisten |
| `GET` | `/grants/received` | `X-User-Id` | Vom Aufrufer empfangene Erteilungen auflisten |
| `DELETE` | `/grants/{id}` | `X-User-Id` (muss Erteiler sein) | Erteilung widerrufen |
| `POST` | `/grants/{id}/use` | `X-User-Id` (muss Empfänger sein) | Erteilung nutzen |

---

## Validierungsregeln

| Feld | Regel |
|------|-------|
| `grantee_id` | Muss ein **JSON-Integer** > 0 sein; String `"2"`, null, boolean, float werden abgelehnt |
| `resource` | Nicht-leerer String; ≤ 500 UTF-8-Zeichen; wird unverändert gespeichert (opak) |
| `scope` | Muss eines von `read` / `write` / `admin` sein |
| `expires_at` | Gültiges ISO 8601; muss in der Zukunft liegen; ≤ 30 Tage ab jetzt |
| Selbstgewährung | `grantee_id == grantor X-User-Id` → 422 |

### Striktes Integer-Feld-Parsing

Eine häufige Schwachstelle ist implizite Typumwandlung — `"2"` (JSON-String) als `2` (int) akzeptieren. Explizite Typüberprüfung verwenden:

```php
private function intField(array $body, string $key): ?int
{
    if (!array_key_exists($key, $body)) {
        return null;
    }
    // is_int() gibt false für "2", null, true, 2.5 zurück — nur true für PHP int
    return is_int($body[$key]) ? $body[$key] : null;
}
```

Hinweis: `2.0` (PHP float) ist nach `json_encode` nicht von `2` (int) zu unterscheiden — `2.5` verwenden, um Float-Ablehnung in Unit-Tests zu testen.

---

## Zustandsmaschine

```
         revoke()
aktiv ─────────────→ widerrufen   (409 bei zweitem Widerruf)
  │
  │ expires_at ≤ jetzt
  ↓
abgelaufen

widerrufen + abgelaufen → widerrufen gewinnt (zuerst auf Widerruf prüfen)
```

Doppelter Widerruf muss mit **409** abgelehnt werden, nicht stillschweigend akzeptiert.
Der `revoked_at`-Zeitstempel darf sich beim zweiten Aufruf nicht ändern.

---

## IDOR-Schutzmuster

```php
// DELETE /grants/{id}
$grant = $this->repository->find($id);

// 404 für sowohl "nicht gefunden" ALS AUCH "nicht Ihre Erteilung" zurückgeben
// Hier niemals 403 zurückgeben — das würde Existenz preisgeben
if ($grant === null || $grant->grantorId !== $callerId) {
    return $this->responseFactory->create(['error' => "Grant #{$id} not found."], 404);
}
```

Dasselbe Muster gilt für `POST /grants/{id}/use` — 404 zurückgeben, wenn der Aufrufer nicht der Empfänger ist.

---

## Mehrparteien-Verwechslungsschutz

| Szenario | Erwartetes Ergebnis |
|----------|---------------------|
| Erteiler ruft `POST /grants/{id}/use` (eigene Erteilung) auf | 404 — Erteiler ist nicht der Empfänger |
| Empfänger ruft `DELETE /grants/{id}` auf | 404 — Empfänger ist nicht der Erteiler |
| Benutzer 3 ruft eines der oben genannten für eine Erteilung zwischen Benutzer 1 und 2 auf | 404 — IDOR |
| `X-User-Id: 0` oder `X-User-Id: -1` | 401 — Nicht-positive IDs werden abgelehnt |
| Fehlende `X-User-Id` | 401 |

---

## Sicherheits-Checkliste (ATK-01 bis ATK-12)

| # | Angriffsvektor | Gegemaßnahme |
|---|---|---|
| ATK-01 | Abgelaufene Erteilung (Uhrzeitgrenze) | `isExpired()`-Vergleich; DB `expires_at` rückdatiert im Test |
| ATK-02 | Widerruf-Zustand-Umgehung | `isRevoked()`-Prüfung vor der Verwendung |
| ATK-03 | Selbstgewährung (grantor == grantee) | App-Layer 422 + DB `CHECK` |
| ATK-04 | Falscher Empfänger verwendet Erteilung (IDOR) | 404, nicht 403 |
| ATK-05 | Nicht-Erteiler widerruft Erteilung (IDOR) | 404, nicht 403; ursprüngliche Erteilung bleibt aktiv |
| ATK-06 | Vergangenes `expires_at` bei Erstellung | `strtotime($expiresAt) <= strtotime($now)` → 422 |
| ATK-07 | Typverwirrung bei `grantee_id` | `is_int()`-Strict-Check; lehnt `"2"`, `null`, `true`, `2.5` ab |
| ATK-08 | Pfadüberquerung in `resource` | Opake Speicherung; kein Dateisystemzugriff |
| ATK-09 | SQL-Injection in `resource`/`scope` | Parametrisierte Abfragen; Scope-Enum lehnt injizierte Werte ab |
| ATK-10 | Unicode/BIDI in `resource` | Wird unverändert gespeichert; Homoglyphen und BIDI sind unterschiedliche Ressourcen |
| ATK-11 | Doppelter Widerruf (Zustandsmaschine) | 409 beim zweiten Widerruf; `revoked_at` unveränderlich nach erstem |
| ATK-12 | Erteiler verwendet eigene Erteilung als Empfänger | 404 — Parteienrollen strikt durchgesetzt |

---

## Testansatz

- **ATK-01, ATK-02**: DB-Zustand direkt erzwingen (`UPDATE grants SET expires_at/revoked_at`), um Zeitreisen ohne Schlaf zu simulieren.
- **ATK-07**: `"2"` (String), `null`, `true`, `2.5` (Float) testen — nicht `2.0` (nach PHP json_encode nicht von int zu unterscheiden).
- **ATK-10**: `"\u{202E}"` (BIDI-Override) und kyrillische Homoglyphen verwenden, um unveränderte Speicherung zu bestätigen.
- **ATK-11**: Sicherstellen, dass `revoked_at`-Wert in der DB nach zweitem Widerrufsversuch unverändert ist.

---

## Was Sie NICHT tun sollten

| Anti-Muster | Risiko |
|---|---|
| Kein `UNIQUE (grantor_id, grantee_id, resource)` | Dasselbe Paar kann doppelte Erteilungen erstellen; Empfänger hat veraltete und aktive Erteilungen für dieselbe Ressource |
| Hard-Delete bei Widerruf | Verliert Audit-Historie; kann nicht sagen, wann Zugriff entfernt wurde oder wie oft er genutzt wurde |
| 403 statt 404 für Eigentumsüberprüfung zurückgeben | Gibt nicht-autorisierten Aufrufern Existenz der Erteilung preis; IDOR-Enumerationsfläche |
| Kein `CHECK (grantor_id != grantee_id)` | Tiefenschutz fehlt; Selbstgewährungen könnten durchschlüpfen, wenn App-Layer-Prüfung umgangen wird |
| Freiformat-Scope-String akzeptieren | Tippfehler verfallen stillschweigend auf `read`; `GrantScope::tryFrom()` verwenden, um unbekannte Werte abzulehnen |
| Scope-Prüfung ohne `satisfies()`-Hierarchie | `write`-Benutzer muss `read`-Prüfungen separat bestehen; Hierarchie verwenden, um alle niedrigeren Ebenen zu prüfen |
| Kein maximales TTL für `expires_at` | Erteiler erstellt 100-Jahres-Erteilungen; effektiv dauerhafter Zugriff ohne Überprüfung |
| Kein Ressourcenlängenlimit | 10-MB-Ressource-String verursacht langsame Index-Lookups und Speicherzuteilung |
| Ablauf vor Widerruf prüfen | Widerrufene + abgelaufene Erteilung sollte "widerrufen" anzeigen — Widerruf gewinnt in der Zustandsmaschine |
| `used_count` clientseitig verfolgen | Client meldet Nutzungszähler; Server muss den Zähler besitzen |
