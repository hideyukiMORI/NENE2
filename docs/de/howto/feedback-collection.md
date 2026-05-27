# How-to: Feedback-Sammlungs-API

## Übersicht

Ein Feedback-System, bei dem Benutzer eine Bewertung (1–5) und einen Kommentar für eine Zielentität einreichen. Admins können alle Feedbacks auflisten; ein öffentlicher Stats-Endpunkt zeigt aggregierte Durchschnittswerte.

**Referenzimplementierung**: `../NENE2-FT/feedbacklog/`

## Schema

```sql
CREATE TABLE IF NOT EXISTS feedback (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    target     TEXT    NOT NULL,
    score      INTEGER NOT NULL,   -- 1-5
    comment    TEXT    NOT NULL DEFAULT '',
    created_at TEXT    NOT NULL,
    UNIQUE (user_id, target)
);
```

## Routen

| Methode | Pfad | Auth | Beschreibung |
|--------|------|------|-------------|
| `POST` | `/feedback` | Benutzer | Feedback einreichen |
| `GET` | `/feedback` | Admin | Alle Feedbacks auflisten |
| `GET` | `/feedback/stats` | Keine | Aggregierte Statistiken |

## Duplikat-Verhinderung

`UNIQUE (user_id, target)` erzwingt ein Feedback pro Benutzer pro Ziel auf DB-Ebene. Zunächst Prüfung auf Anwendungsebene:

```php
$stmt = $this->pdo->prepare('SELECT id FROM feedback WHERE user_id = :uid AND target = :tgt');
$stmt->execute([...]);
if ($stmt->fetch() !== false) return 'already_submitted';
```

## Bewertungsvalidierung

```php
if (!is_int($score) || $score < 1 || $score > 5) {
    return $this->problem(422, 'validation-failed', 'score must be an integer 1-5.');
}
```

## Statistikaggregation

```sql
SELECT COUNT(*) AS cnt, AVG(score) AS avg FROM feedback WHERE target = :tgt
```

Bei einer Anzahl von null `null` als Durchschnittswert zurückgeben, um `NaN` in JSON zu vermeiden.

## HTTP-Statuscodes

| Situation | Status |
|-----------|--------|
| Feedback eingereicht | 201 |
| Statistiken / Liste | 200 |
| Kein X-User-Id | 400 |
| Leeres Ziel / ungültige Bewertung | 422 |
| Kein Admin-Schlüssel | 403 |
| Doppeltes Feedback | 409 |
