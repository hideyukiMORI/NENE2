# How-to: SQLite-Fensterfunktionen verwenden

Fensterfunktionen berechnen einen Wert über eine Menge von Zeilen *bezogen auf die aktuelle Zeile*, ohne sie zu einer einzigen Gruppe zusammenzufassen (wie es `GROUP BY` tut). Sie sind das richtige Werkzeug für **Rangbildung**, **laufende Summen** und **Periodenvergleich** — drei Muster, die in PHP im Nachhinein umständlich und langsam sind.

SQLite unterstützt Fensterfunktionen seit **3.25.0** (2018). NENE2 wird mit dem in PHP gebündelten SQLite ausgeliefert, das deutlich darüber hinaus ist; MySQL 8.0+ und PostgreSQL unterstützen ebenfalls dieselbe Syntax, sodass diese Abfragen über die drei Adapter portierbar sind, die NENE2 anvisiert.

Sie führen sie über `DatabaseQueryExecutorInterface::fetchAll()` aus wie jede andere Leseabfrage — es gibt keine spezielle Framework-Unterstützung zu verdrahten.

**Voraussetzung**: Sie haben ein Repository, das auf `DatabaseQueryExecutorInterface` basiert. Siehe [Datenbankgestützten Endpunkt hinzufügen](add-database-endpoint.md).

---

## 1. Die Anatomie eines Fensters

```sql
ROW_NUMBER() OVER (PARTITION BY game ORDER BY points DESC)
```

- `PARTITION BY game` — das Fenster für jedes Spiel neu starten (weglassen, um alle Zeilen als ein Fenster zu behandeln).
- `ORDER BY points DESC` — Ordnung *innerhalb* der Partition; dies definiert, was „erste" und „vorherige" bedeuten.

Wenn mehrere Spalten dasselbe Fenster wiederverwenden, benennen Sie es einmal mit einer `WINDOW`-Klausel:

```sql
SELECT player, game, points,
       ROW_NUMBER()  OVER w AS rn,
       RANK()        OVER w AS rnk,
       DENSE_RANK()  OVER w AS drnk
FROM scores
WINDOW w AS (PARTITION BY game ORDER BY points DESC)
ORDER BY game, points DESC;
```

---

## 2. Rangbildung: `ROW_NUMBER` vs. `RANK` vs. `DENSE_RANK`

Die drei unterscheiden sich nur darin, wie sie Gleichstände behandeln. Gegeben zwei Spieler, die mit 150 in `chess` gleichauf liegen:

| player | game  | points | `ROW_NUMBER` | `RANK` | `DENSE_RANK` |
|--------|-------|--------|--------------|--------|--------------|
| b      | chess | 150    | 1            | 1      | 1            |
| c      | chess | 150    | 2            | 1      | 1            |
| a      | chess | 100    | 3            | 3      | 2            |

- **`ROW_NUMBER`** — immer eindeutig (1, 2, 3). Verwenden Sie es für stabile Paginierungs-Cursor oder „genau einen pro Gruppe auswählen".
- **`RANK`** — Gleichstände teilen sich einen Rang, dann wird übersprungen (1, 1, 3). Verwenden Sie es für Bestenlisten, bei denen „gemeinsamer 1. Platz" sinnvoll ist.
- **`DENSE_RANK`** — Gleichstände teilen sich einen Rang, keine Lücke (1, 1, 2). Verwenden Sie es für „Stufen-" / Noten-Buckets.

> Wählen Sie die Rang-Funktion bewusst. Eine Bestenliste, die zwei „Rang 2"-Spieler und keinen „Rang 1" zeigt, ist fast immer eine Verwechslung von `RANK`/`ROW_NUMBER`.

In einer Repository-Methode:

```php
/**
 * @return list<array{player: string, game: string, points: int, rank: int}>
 */
public function topRankedByGame(string $game): array
{
    return $this->executor->fetchAll(
        'SELECT player, game, points,
                RANK() OVER (PARTITION BY game ORDER BY points DESC) AS rank
         FROM scores
         WHERE game = :game
         ORDER BY points DESC',
        ['game' => $game],
    );
}
```

---

## 3. Laufende Summe: ein Aggregat als Fenster

Jedes Aggregat (`SUM`, `AVG`, `COUNT`, …) wird zu einem *laufenden* Aggregat, wenn ihm eine `OVER (...)`-Klausel und ein Rahmen gegeben wird:

```sql
SELECT created_at, points,
       SUM(points) OVER (ORDER BY created_at ROWS UNBOUNDED PRECEDING) AS running_total
FROM scores
ORDER BY created_at;
```

| created_at | points | running_total |
|------------|--------|---------------|
| 2026-01-01 | 100    | 100           |
| 2026-01-02 | 150    | 250           |
| 2026-01-03 | 150    | 400           |
| 2026-01-04 | 90     | 490           |

`ROWS UNBOUNDED PRECEDING` bedeutet „jede Zeile vom Beginn der Partition bis zur aktuellen Zeile". Ohne einen expliziten Rahmen summiert der Standard (`RANGE UNBOUNDED PRECEDING`) **alle Zeilen, die beim `ORDER BY`-Wert gleichauf liegen**, in denselben Schritt — eine subtile Quelle falscher Summen, wenn Zeitstempel kollidieren. Seien Sie explizit mit `ROWS`, wenn Sie eine echte Zeile-für-Zeile laufende Summe wollen.

---

## 4. Periodenvergleich: `LAG` und `LEAD`

`LAG` liest eine Spalte aus der *vorherigen* Zeile im Fenster; `LEAD` liest die *nächste*. Dies berechnet ein Delta ohne Self-Join:

```sql
SELECT created_at, points,
       points - LAG(points) OVER (ORDER BY created_at) AS delta
FROM scores
ORDER BY created_at;
```

| created_at | points | delta |
|------------|--------|-------|
| 2026-01-01 | 100    | *null* |
| 2026-01-02 | 150    | 50    |
| 2026-01-03 | 150    | 0     |
| 2026-01-04 | 90     | −60   |

Das `delta` der ersten Zeile ist `NULL`, weil es keine vorherige Zeile gibt. Geben Sie einen Standardwert an, um Null-Behandlung nachgelagert zu vermeiden: `LAG(points, 1, 0)` gibt `0` statt `NULL` für die erste Zeile zurück. Bilden Sie `NULL` in Ihrem DTO auf einen typisierten Wert ab, anstatt es in die JSON-Antwort durchsickern zu lassen.

---

## 5. Filtern auf einem Fensterergebnis

Sie **können** keine Fensterfunktion in eine `WHERE`-Klausel setzen — Fenster werden *nach* `WHERE` ausgewertet. Wickeln Sie die Abfrage in eine Unterabfrage (oder CTE) und filtern Sie auf den Alias:

```sql
WITH ranked AS (
    SELECT player, game, points,
           ROW_NUMBER() OVER (PARTITION BY game ORDER BY points DESC) AS rn
    FROM scores
)
SELECT player, game, points
FROM ranked
WHERE rn <= 3            -- top 3 per game
ORDER BY game, points DESC;
```

Diese „Top-N-pro-Gruppe"-Form ist der häufigste reale Anwendungsfall; greifen Sie zu ihr statt zu `N` separaten `LIMIT`-Abfragen.

---

## 6. Als typisierte Antwort zurückgeben

Halten Sie das SQL im Repository und bilden Sie auf ein readonly DTO ab, bevor es den Controller erreicht — übergeben Sie nicht das rohe `array` über die Grenze:

```php
final readonly class GameRanking
{
    public function __construct(
        public int $rank,
        public string $player,
        public int $points,
    ) {}
}
```

```php
/** @return list<GameRanking> */
public function topRankedByGame(string $game): array
{
    $rows = $this->executor->fetchAll(
        'SELECT player, points,
                RANK() OVER (PARTITION BY game ORDER BY points DESC) AS rank
         FROM scores WHERE game = :game ORDER BY points DESC',
        ['game' => $game],
    );

    return array_map(
        static fn (array $r): GameRanking => new GameRanking(
            rank: (int) $r['rank'],
            player: (string) $r['player'],
            points: (int) $r['points'],
        ),
        $rows,
    );
}
```

SQLite gibt alle Spaltenwerte über PDO als Zeichenketten zurück, also casten Sie (`(int)`, `(float)`) innerhalb des Mappers — das Fensterfunktions-Ergebnis (`rank`, `running_total`) ist keine Ausnahme.

---

## Tücken

- **`WHERE` kann Fenster-Aliase nicht sehen** — in einer äußeren Abfrage/CTE filtern (§5).
- **Standardrahmen ist `RANGE`, nicht `ROWS`** — bei laufenden Summen explizit `ROWS UNBOUNDED PRECEDING` verwenden (§3).
- **`LAG`/`LEAD` geben `NULL` an den Rändern zurück** — einen Standardwert übergeben oder auf einen typisierten Wert abbilden (§4).
- **Portabilität** — die obige Syntax ist Standard und läuft auf SQLite 3.25+, MySQL 8.0+ und PostgreSQL. Wenn Sie älteres MySQL (5.7) anvisieren, sind Fensterfunktionen nicht verfügbar; greifen Sie auf einen Self-Join zurück oder berechnen Sie in PHP.
- **Die `ORDER BY`-Spalten indexieren** — `PARTITION BY` / `ORDER BY` eines Fensters profitieren von denselben Indizes wie eine normale Sortierung.

---

## Verwandte Anleitungen

- [Datenbanktransaktionen verwenden](use-transactions.md) — atomare mehrstufige Schreibvorgänge
- [Bestenlisten-Rangbildung](leaderboard-ranking.md) — ein Produktrezept, das auf Rangbildung aufbaut
- [Datenbankgestützten Endpunkt hinzufügen](add-database-endpoint.md) — Repository- + Executor-Verdrahtung
