# How-to: Artikel-Versionierungs-API

> **FT-Referenz**: FT249 (`NENE2-FT/contentvlog`) — Artikel-Versionierungs-API
> **VULN**: FT249 — Schwachstellenbewertung (V-01 bis V-10)

Demonstriert ein Artikel-Versionierungssystem, bei dem eine `current_version`-Integer-Spalte
in der `articles`-Tabelle die neueste Version verfolgt, jede Aktualisierung an
`article_versions` anfügt und ein Rollback eine neue Version aus historischem Inhalt erstellt.
Enthält eine Schwachstellenbewertung des nicht authentifizierten Designs.

---

## Routen

| Methode | Pfad | Beschreibung |
|--------|-----------------------------------|------------------------------------------------------|
| `POST` | `/articles` | Artikel erstellen (Version 1) |
| `GET` | `/articles/{id}` | Artikel abrufen (aktueller Inhalt) |
| `PUT` | `/articles/{id}` | Artikel aktualisieren (erstellt neue Version) |
| `GET` | `/articles/{id}/versions` | Versionsverlauf auflisten (nur Metadaten) |
| `GET` | `/articles/{id}/versions/{version}` | Bestimmte Version abrufen |
| `POST` | `/articles/{id}/rollback` | Auf eine Version zurücksetzen (erstellt neue Version) |

---

## Schema: `current_version`-Integer-Spalte

```sql
CREATE TABLE articles (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    title           TEXT    NOT NULL,
    body            TEXT    NOT NULL,
    current_version INTEGER NOT NULL DEFAULT 1,
    created_at      TEXT    NOT NULL,
    updated_at      TEXT    NOT NULL
);

CREATE TABLE article_versions (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    article_id INTEGER NOT NULL,
    version    INTEGER NOT NULL,
    title      TEXT    NOT NULL,
    body       TEXT    NOT NULL,
    created_at TEXT    NOT NULL,
    UNIQUE (article_id, version),
    FOREIGN KEY (article_id) REFERENCES articles(id)
);
```

Die `current_version`-Spalte speichert die Versionsnummer des aktuellen Inhalts.
`UNIQUE(article_id, version)` verhindert doppelte Versionsnummern für denselben Artikel.

**Vergleich mit dem `is_current`-Flag-Ansatz** (siehe `document-versioning.md`):

| Ansatz | `current_version`-Integer | `is_current`-Flag |
|---|---|---|
| Schema | Spalte in `articles`-Tabelle | Spalte in `versions`-Tabelle |
| Aktuelle Version nachschlagen | `SELECT * FROM articles WHERE id = ?` (kein JOIN) | `LEFT JOIN ... ON dv.is_current = 1` |
| Versionsnummern-Verfolgung | Expliziter Integer in der übergeordneten Zeile | Implizit aus Zeilenanzahl oder MAX |
| Atomarität | Artikel aktualisieren + Version einfügen (2 Schreibvorgänge) | Flag aktualisieren + INSERT (2 Schreibvorgänge) |

---

## Erstellen: Zwei-Schreibvorgangs-Initialisierung

Das Erstellen eines Artikels schreibt in beide Tabellen:

```php
$id = $this->db->insert(
    'INSERT INTO articles (title, body, current_version, created_at, updated_at) VALUES (?, ?, 1, ?, ?)',
    [$title, $body, $now, $now],
);
$this->db->insert(
    'INSERT INTO article_versions (article_id, version, title, body, created_at) VALUES (?, 1, ?, ?, ?)',
    [$id, $title, $body, $now],
);
```

Beide Schreibvorgänge erfolgen ohne umschließende Transaktion. Wenn der zweite Insert fehlschlägt,
existiert die `articles`-Zeile, aber `article_versions` hat keinen entsprechenden Eintrag — der Artikel
ist auf Version 1 ohne Verlaufseintrag. Für den Produktionseinsatz beide in `$txManager->transactional()` einschließen.

---

## Aktualisieren: Lesen-dann-Inkrementieren-Muster

```php
public function update(int $id, string $title, string $body, string $now): bool
{
    $article = $this->find($id);
    if ($article === null) {
        return false;
    }
    $nextVersion = (int) $article['current_version'] + 1;

    $this->db->insert(
        'UPDATE articles SET title = ?, body = ?, current_version = ?, updated_at = ? WHERE id = ?',
        [$title, $body, $nextVersion, $now, $id],
    );
    $this->db->insert(
        'INSERT INTO article_versions (article_id, version, title, body, created_at) VALUES (?, ?, ?, ?, ?)',
        [$id, $nextVersion, $title, $body, $now],
    );
    return true;
}
```

Die Versionsnummer wird gelesen, in PHP inkrementiert und dann zurückgeschrieben. Ohne eine
Transaktion können gleichzeitige Aktualisierungen doppelte Versionsnummern erzeugen — der
`UNIQUE(article_id, version)`-Constraint fängt dies ab, aber das `UPDATE` in `articles` könnte
bereits erfolgreich sein, bevor der `INSERT` in `article_versions` fehlschlägt, wodurch der
`current_version` des Artikels seiner Verlaufsaufzeichnung voraus sein könnte.

---

## Rollback: nicht-destruktiv (als neue Version kopieren)

```php
public function rollback(int $id, int $version, string $now): bool
{
    $target = $this->findVersion($id, $version);
    if ($target === null) {
        return false;
    }
    $article     = $this->find($id);
    $nextVersion = (int) $article['current_version'] + 1;
    $title       = (string) $target['title'];
    $body        = (string) $target['body'];

    $this->db->insert(
        'UPDATE articles SET title = ?, body = ?, current_version = ?, updated_at = ? WHERE id = ?',
        [$title, $body, $nextVersion, $now, $id],
    );
    $this->db->insert(
        'INSERT INTO article_versions (article_id, version, title, body, created_at) VALUES (?, ?, ?, ?, ?)',
        [$id, $nextVersion, $title, $body, $now],
    );
    return true;
}
```

Rollback löscht keine Versionen — es kopiert den Inhalt der Zielversion als neue (aktuelle) Version.
Der Verlauf bleibt immer erhalten. Wenn ein Artikel bei Version 5 ist und auf Version 2 zurückgesetzt wird:

```
v1 → v2 → v3 → v4 → v5 → v6 (Kopie von v2-Inhalt)
```

---

## Versionsliste: nur Metadaten (kein Body)

`GET /articles/{id}/versions` gibt Versionsmetadaten ohne den vollständigen Body zurück:

```php
$this->db->fetchAll(
    'SELECT id, article_id, version, title, created_at FROM article_versions
     WHERE article_id = ? ORDER BY version ASC',
    [$articleId],
);
```

`body` ist aus der Liste ausgeschlossen — Aufrufer müssen einzelne Versionen mit
`GET /articles/{id}/versions/{version}` abrufen, um den Inhalt zu erhalten. Dies vermeidet
das Senden potenziell großer Inhalte in der Listenantwort.

---

## VULN — Schwachstellenbewertung (FT249)

### V-01 — Keine Authentifizierung: beliebiger Aufrufer kann beliebige Artikel aktualisieren oder löschen

**Risiko**: Alle Endpunkte sind nicht authentifiziert.

**Auswirkung**: Ein Angreifer kann jeden Artikel überschreiben, seinen Inhalt auf eine frühere Version zurücksetzen oder den gesamten Versionsverlauf aufzählen.

**Urteil**: **EXPONIERT** — Authentifizierung hinzufügen (API-Schlüssel, JWT oder Session). Aktualisieren/Rollback sollte erfordern, dass der Artikelbesitzer authentifiziert ist.

---

### V-02 — Kein Besitz: jeder authentifizierte Benutzer kann jeden Artikel ändern

**Risiko**: Selbst mit Authentifizierung gibt es keine besitzbezogene Abfrage. Jeder authentifizierte Benutzer kann den Artikel eines anderen Benutzers aktualisieren.

**Auswirkung**: Ohne `WHERE id = ? AND owner_id = ?` sind Artikel-IDs aufzählbar und von jedem mit einem gültigen Token änderbar.

**Urteil**: **EXPONIERT** — eine `owner_id`-Spalte zu `articles` hinzufügen. Besitz mit `WHERE id = ? AND owner_id = ?` in allen Schreiboperationen durchsetzen.

---

### V-03 — IDOR: Versionsverlauf eines anderen Benutzers lesen

**Risiko**: `GET /articles/{id}/versions` gibt den gesamten Versionsverlauf für jede Artikel-ID zurück.

**Auswirkung**: Ein Angreifer kann Draft-Inhaltsverlauf aufzählen, den der Autor möglicherweise nicht veröffentlichen wollte.

**Urteil**: **EXPONIERT** — alle Lesevorgänge besitzbereichsbegrenzen: nur der Artikelbesitzer (oder Rollen mit expliziter Leseberechtigung) sollte den Versionsverlauf sehen.

---

### V-04 — Race Condition beim Versionsnummern-Inkrement

**Risiko**: `update()` liest `current_version`, inkrementiert in PHP und schreibt dann zurück.
Keine Transaktion oder Zeilensperre umschließt die Lese-Schreib-Sequenz.

**Angriff**: Zwei gleichzeitige `PUT /articles/1`-Anfragen lesen beide `current_version = 3`.
Beide berechnen `nextVersion = 4`. Eine gelingt (fügt Version 4 ein); die andere schlägt beim
`UNIQUE(article_id, version)`-Constraint fehl — aber das `UPDATE articles` könnte bereits erfolgreich
sein und `current_version = 4` für beide gesetzt haben, mit nur einem Versionseintrag im Verlauf.

**Urteil**: **EXPONIERT** — `find` + `UPDATE` + `INSERT` in eine DB-Transaktion einschließen.
`UPDATE articles SET current_version = current_version + 1` für atomares Inkrement verwenden.

---

### V-05 — SQL-Injection über title oder body

**Angriff**: SQL-Metazeichen einbetten.

```json
{"title": "'; DROP TABLE articles; --", "body": "x"}
```

**Beobachtet**: Werte sind als parametrisierte `?`-Platzhalter gebunden. Injection wird als wörtlicher Text gespeichert.

**Urteil**: **BLOCKIERT** — parametrisierte Abfragen verhindern SQL-Injection.

---

### V-06 — Versionsaufzählung: unbegrenzter Verlaufszugriff

**Risiko**: `GET /articles/{id}/versions` gibt den vollständigen Versionsverlauf ohne Paginierung oder Limit zurück.

**Auswirkung**: Ein Artikel mit Tausenden von Versionen gibt alle Zeilen in einer einzigen Antwort zurück, was Speicherdruck und langsame Abfragen verursacht.

**Urteil**: **EXPONIERT** — Paginierung (`LIMIT ? OFFSET ?`) zum Versions-Listen-Endpunkt hinzufügen. Maximale Versionen pro Artikel begrenzen.

---

### V-07 — Nicht-transaktionale Zwei-Schreibvorgangs-Operationen

**Risiko**: Sowohl `create()` als auch `update()` führen zwei sequenzielle Schreibvorgänge ohne umschließende DB-Transaktion durch.

**Auswirkung**: Wenn der zweite Schreibvorgang fehlschlägt (z.B. Constraint-Verletzung, Verbindungsfehler), befindet sich das System in einem inkonsistenten Zustand: `articles.current_version` kann von der Anzahl der `article_versions`-Zeilen abweichen, oder ein Artikel könnte ohne Versionseintrag existieren.

**Urteil**: **EXPONIERT** — gepaarte Schreibvorgänge in `DatabaseTransactionManagerInterface::transactional()` einschließen.

---

### V-08 — Rollback auf eine Version eines anderen Artikels

**Angriff**: Einen Rollback mit einer `version`-Nummer einreichen, die für einen anderen Artikel existiert.

```bash
# Artikel 1 hat Versionen 1-3; Artikel 2 hat Version 1
POST /articles/1/rollback  {"version": 1}
```

**Beobachtet**: `findVersion(articleId=1, version=1)` verwendet `WHERE article_id = ? AND version = ?`
— es findet nur Versionen, die zu Artikel 1 gehören. Eine Version, die für Artikel 2 existiert, wird nicht zurückgegeben.

**Urteil**: **BLOCKIERT** — Versionssuche ist nach `article_id` begrenzt.

---

### V-09 — Großer Body: keine Größenbeschränkung für Artikelinhalt

**Risiko**: `body` akzeptiert Strings beliebiger Länge ohne Validierung.

**Auswirkung**: Mehrmegabyte-Bodies verbrauchen bei jedem Lesen Speicher und Arbeitsspeicher.

**Urteil**: **EXPONIERT** — eine Body-Längenprüfung hinzufügen (z.B. `strlen($body) > 1_000_000 → 422`).
Request-Größen-Middleware als äußere Grenze verwenden.

---

### V-10 — Rollback auf `version = 0` oder negative Version

**Angriff**: Einen Rollback mit Version 0 oder -1 einreichen.

```json
{"version": 0}
{"version": -1}
```

**Beobachtet**: `(int) $body['version']` akzeptiert jede Ganzzahl. `findVersion($id, 0)` und `findVersion($id, -1)` geben `null` zurück (keine solche Version) → `404 Not Found`. Keine Version 0 wird je gespeichert (Versionen beginnen bei 1).

**Urteil**: **BLOCKIERT** — `findVersion` gibt `null` für nicht existierende Versionen zurück; kein Sonderfall ist erforderlich.

---

## VULN-Zusammenfassung

| # | Schwachstelle | Urteil |
|---|---------------|---------|
| V-01 | Keine Authentifizierung an Schreib-Endpunkten | EXPONIERT |
| V-02 | Keine Besitzprüfung (jeder Benutzer kann jeden Artikel ändern) | EXPONIERT |
| V-03 | IDOR beim Versionsverlauf | EXPONIERT |
| V-04 | Race Condition beim Versionsnummern-Inkrement | EXPONIERT |
| V-05 | SQL-Injection über title/body | BLOCKIERT |
| V-06 | Unbegrenzte Versionsliste (keine Paginierung) | EXPONIERT |
| V-07 | Nicht-transaktionale gepaarte Schreibvorgänge | EXPONIERT |
| V-08 | Rollback auf Version eines anderen Artikels | BLOCKIERT |
| V-09 | Keine Body-Größenbeschränkung | EXPONIERT |
| V-10 | Rollback auf Version 0 / negativ | BLOCKIERT |

**Kritische Korrekturen vor dem Produktionseinsatz**:
1. **V-01 / V-02 / V-03** — Authentifizierung und `owner_id`-Besitzdurchsetzung hinzufügen
2. **V-04 / V-07** — Alle Multi-Schreibvorgangs-Operationen in `transactional()` einschließen; atomisches Versions-Inkrement verwenden
3. **V-06** — `LIMIT ? OFFSET ?`-Paginierung zur Versionsliste hinzufügen
4. **V-09** — Body-Größenvalidierung hinzufügen

---

## Verwandte Anleitungen

- [`document-versioning.md`](document-versioning.md) — `is_current`-Flag-Ansatz mit `DatabaseTransactionManagerInterface`
- [`content-versioning.md`](content-versioning.md) — Inhaltsversionierung mit linearen Versionsnummern
- [`transactions.md`](transactions.md) — `DatabaseTransactionManagerInterface`-Muster
- [`optimistic-locking.md`](optimistic-locking.md) — Race-Condition-Verhinderung mit Versions-Spalte + bedingtem UPDATE
