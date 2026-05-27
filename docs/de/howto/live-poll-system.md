# How-to: Live-Umfragesystem

## Übersicht

Diese Anleitung behandelt den Aufbau einer Live-Umfragesystem-API mit NENE2, einschließlich admin-gesteuerter Umfrageerstellung, Abstimmungs-Deduplizierung pro Benutzer, Umfrage-Lifecycle-Verwaltung und Ergebnis-Aggregation.

**Referenzimplementierung**: `../NENE2-FT/polllog/`

---

## Schema-Design

```sql
CREATE TABLE IF NOT EXISTS polls (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    question   TEXT    NOT NULL,
    closed     INTEGER NOT NULL DEFAULT 0,
    created_at TEXT    NOT NULL
);

CREATE TABLE IF NOT EXISTS poll_options (
    id      INTEGER PRIMARY KEY AUTOINCREMENT,
    poll_id INTEGER NOT NULL,
    label   TEXT    NOT NULL,
    FOREIGN KEY (poll_id) REFERENCES polls(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS poll_votes (
    id        INTEGER PRIMARY KEY AUTOINCREMENT,
    poll_id   INTEGER NOT NULL,
    option_id INTEGER NOT NULL,
    user_id   INTEGER NOT NULL,
    voted_at  TEXT    NOT NULL,
    UNIQUE (poll_id, user_id),
    FOREIGN KEY (poll_id) REFERENCES polls(id) ON DELETE CASCADE,
    FOREIGN KEY (option_id) REFERENCES poll_options(id) ON DELETE CASCADE
);
```

Wichtige Constraints:
- `UNIQUE (poll_id, user_id)` — verhindert, dass ein Benutzer mehr als einmal pro Umfrage abstimmt.
- `ON DELETE CASCADE` — entfernt Optionen und Stimmen, wenn eine Umfrage gelöscht wird.

---

## Routentabelle

| Methode | Pfad | Auth | Beschreibung |
|--------|------|------|-------------|
| `POST` | `/polls` | Admin | Umfrage mit Optionen erstellen |
| `GET` | `/polls` | Keine | Alle Umfragen auflisten |
| `GET` | `/polls/{id}` | Keine | Umfrage mit Stimmzahlen abrufen |
| `POST` | `/polls/{id}/vote` | Benutzer | Stimme abgeben |
| `POST` | `/polls/{id}/close` | Admin | Umfrage schließen |

---

## Admin-Authentifizierungsmuster

Ein gemeinsames Secret im `X-Admin-Key`-Header übergeben. Fail-closed-Logik verwenden:

```php
private function isAdmin(ServerRequestInterface $req): bool
{
    if ($this->adminKey === '') {
        return false;          // fail-closed: kein Key konfiguriert → niemals Admin
    }
    return hash_equals($this->adminKey, $req->getHeaderLine('X-Admin-Key'));
}
```

`403 Forbidden` zurückgeben, wenn kein Admin:
```php
if (!$this->isAdmin($req)) {
    return $this->problem(403, 'forbidden', 'Admin key required.');
}
```

---

## Umfragen mit Optionen erstellen

Mindestens 2 Optionen validieren; in einer Transaktion einfügen:

```php
public function create(string $question, array $options): array
{
    $now  = $this->now();
    $stmt = $this->pdo->prepare('INSERT INTO polls (question, closed, created_at) VALUES (?, 0, ?)');
    $stmt->execute([$question, $now]);
    $pollId = (int) $this->pdo->lastInsertId();

    $ins = $this->pdo->prepare('INSERT INTO poll_options (poll_id, label) VALUES (?, ?)');
    foreach ($options as $label) {
        $ins->execute([$pollId, $label]);
    }

    return $this->findById($pollId);
}
```

---

## Abstimmung mit Deduplizierung

Den UNIQUE-Constraint-Verstoß abfangen, um Doppelabstimmungen zu erkennen:

```php
public function vote(int $pollId, int $optionId, int $userId): string
{
    $poll = $this->findById($pollId);
    if ($poll === null) return 'not_found';
    if ($poll['closed']) return 'poll_closed';

    // Überprüfen, ob die Option zu dieser Umfrage gehört
    $stmt = $this->pdo->prepare('SELECT id FROM poll_options WHERE id = ? AND poll_id = ?');
    $stmt->execute([$optionId, $pollId]);
    if ($stmt->fetch() === false) return 'invalid_option';

    try {
        $this->pdo->prepare(
            'INSERT INTO poll_votes (poll_id, option_id, user_id, voted_at) VALUES (?, ?, ?, ?)'
        )->execute([$pollId, $optionId, $userId, $this->now()]);
    } catch (\PDOException $e) {
        if (str_contains($e->getMessage(), 'UNIQUE')) return 'already_voted';
        throw $e;
    }

    return 'ok';
}
```

---

## Stimmzahlen aggregieren

`LEFT JOIN` verwenden, um Optionen mit null Stimmen einzuschließen:

```sql
SELECT po.id, po.label, COUNT(pv.id) AS votes
FROM poll_options po
LEFT JOIN poll_votes pv ON pv.option_id = po.id
WHERE po.poll_id = :poll_id
GROUP BY po.id, po.label
ORDER BY po.id ASC
```

---

## HTTP-Statuscodes

| Situation | Status |
|-----------|--------|
| Umfrage erstellt | 201 |
| Stimme abgegeben | 201 |
| Umfrage gefunden / geschlossen | 200 |
| Umfrage nicht gefunden | 404 |
| Ungültige Option-ID | 422 |
| Fehlende Frage oder < 2 Optionen | 422 |
| Nicht-ganzzahlige option_id | 422 |
| Bereits abgestimmt | 409 |
| Abstimmung bei geschlossener Umfrage | 409 |
| Kein Admin-Key | 403 |
| Kein X-User-Id-Header | 400 |

---

## Validierungscheckliste

- `question`: nicht-leerer String
- `options`: Array mit ≥ 2 nicht-leeren Strings
- `option_id`: muss `is_int()` sein (String wie `'1'` ablehnen)
- `X-User-Id`: `ctype_digit()` + positive Ganzzahl
- Umfrage muss vor dem Abstimmen oder Schließen existieren
- Option muss zur Zielumfrage gehören (Cross-Umfrage-Injection)

---

## Sicherheitshinweise

- **Admin-Key fail-closed**: leerer Key bedeutet, niemand ist Admin.
- **`hash_equals()` verwenden**, um Timing-Angriffe auf den Admin-Key-Vergleich zu verhindern.
- **UNIQUE-Constraint** ist der maßgebliche Doppelabstimmungs-Schutz — alleinige Anwendungsebenenprüfung ist unter gleichzeitiger Last nicht ausreichend.
- **Options-Eigentumscheck** verhindert das Abstimmen mit einer Option aus einer anderen Umfrage.
