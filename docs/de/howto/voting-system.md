# Abstimmungssystem (Upvote / Downvote)

Benutzern erlauben, Elemente hoch- oder herunterzustufen. Jeder Benutzer kann pro Element maximal eine Stimme abgeben. Zweimal in dieselbe Richtung abstimmen schaltet die Stimme aus. In die entgegengesetzte Richtung abstimmen wechselt die Stimme.

## Überblick

Ein Abstimmungssystem umfasst:
- **Stimme abgeben**: Element hoch- oder herunterstimmen
- **Umschalten**: Zweimal in dieselbe Richtung abstimmen entfernt die Stimme
- **Wechseln**: In die entgegengesetzte Richtung abstimmen ersetzt die aktuelle Stimme
- **Score**: Upvotes − Downvotes, mit jeder Stimm-Antwort zurückgegeben
- **Aktuelle Stimme**: Aktuelle Stimme eines Benutzers für ein Element abrufen (für UI-Hervorhebung)

## Datenbankschema

```sql
CREATE TABLE votes (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    item_id    INTEGER NOT NULL,
    direction  TEXT    NOT NULL CHECK (direction IN ('up', 'down')),
    created_at TEXT    NOT NULL,
    UNIQUE (user_id, item_id),
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (item_id) REFERENCES items(id)
);
```

Der `UNIQUE (user_id, item_id)`-Constraint setzt eine Stimme pro Benutzer pro Element auf Datenbankebene durch. `CHECK (direction IN ('up', 'down'))` verhindert ungültige Werte, auch wenn die Validierung auf Anwendungsebene umgangen wird.

## Richtung als Enum

Ein backed Enum verwenden, um ungültige Richtungswerte zu verhindern, bevor sie das Repository erreichen:

```php
enum VoteDirection: string
{
    case Up   = 'up';
    case Down = 'down';
}
```

Mit `VoteDirection::tryFrom($dirStr)` parsen — gibt `null` für ungültige Eingaben zurück und ermöglicht eine saubere 422-Behandlung ohne match/switch.

## Toggle- und Switch-Logik

Alle drei Fälle (Toggle-Off, Richtungswechsel, neue Stimme) werden im Repository behandelt:

```php
public function castVote(int $userId, int $itemId, VoteDirection $direction, string $now): ?VoteDirection
{
    $current = $this->getCurrentVote($userId, $itemId);

    if ($current === $direction) {
        // gleiche Richtung → Toggle-Off
        $this->executor->execute(
            'DELETE FROM votes WHERE user_id = ? AND item_id = ?',
            [$userId, $itemId],
        );
        return null;
    }

    if ($current !== null) {
        // andere Richtung → wechseln
        $this->executor->execute(
            'UPDATE votes SET direction = ?, created_at = ? WHERE user_id = ? AND item_id = ?',
            [$direction->value, $now, $userId, $itemId],
        );
    } else {
        // keine vorhandene Stimme → einfügen
        $this->executor->execute(
            'INSERT INTO votes (user_id, item_id, direction, created_at) VALUES (?, ?, ?, ?)',
            [$userId, $itemId, $direction->value, $now],
        );
    }

    return $direction;
}
```

Der Rückgabewert `?VoteDirection` lässt den Handler wissen, ob die Stimme jetzt gesetzt (`'up'`/`'down'`) oder entfernt (`null`) ist.

## Score mit jeder Stimme zurückgeben

Den aktualisierten Score in die Stimmantwort einschließen, damit Clients Zähler ohne ein separates GET aktualisieren können:

```php
$result = $this->repo->castVote($userId, $itemId, $direction, $now);
$score  = $this->repo->getScore($itemId);

return $this->responseFactory->create([
    'user_id' => $userId,
    'item_id' => $itemId,
    'vote'    => $result !== null ? $result->value : null,
    'score'   => $score->toArray(),
]);
```

## Score-Berechnung

Separate COUNT-Abfragen pro Richtung sind einfacher und lesbarer als ein einzelnes GROUP BY:

```php
public function getScore(int $itemId): ItemScore
{
    $upRow = $this->executor->fetchOne(
        "SELECT COUNT(*) as cnt FROM votes WHERE item_id = ? AND direction = 'up'",
        [$itemId],
    );
    $downRow = $this->executor->fetchOne(
        "SELECT COUNT(*) as cnt FROM votes WHERE item_id = ? AND direction = 'down'",
        [$itemId],
    );
    ...
}
```

`score = upvotes - downvotes`. Null ist der Ausgangszustand, bevor Stimmen abgegeben wurden.

## Benutzer-Stimmstatus

Ein separater Endpunkt lässt die UI zeigen, in welche Richtung der aktuelle Benutzer gestimmt hat (für Button-Hervorhebung):

```php
// GET /items/{itemId}/vote/{userId}
$current = $this->repo->getCurrentVote($userId, $itemId);
return ['vote' => $current !== null ? $current->value : null];
```

Gibt `null` zurück, wenn der Benutzer nicht gestimmt hat (oder seine Stimme umgeschaltet hat).

## Sicherheitseigenschaften

| Eigenschaft | Implementierung |
|-------------|-----------------|
| Eine Stimme pro Benutzer pro Element | `UNIQUE (user_id, item_id)` DB-Constraint |
| Ungültige Richtung abgelehnt | `CHECK (direction IN ('up', 'down'))` + `VoteDirection::tryFrom()` |
| Unbekannter Benutzer/Element | Gibt 404 zurück — keine Ressourcenexistenz geleakt |
| Toggle-Sicherheit | Prüft aktuelle Stimme vor DELETE/UPDATE |

## Routenübersicht

| Methode | Pfad | Beschreibung |
|---------|------|--------------|
| `POST` | `/users` | Benutzer erstellen |
| `POST` | `/items` | Element erstellen |
| `POST` | `/items/{itemId}/vote` | Stimme abgeben, wechseln oder umschalten |
| `GET` | `/items/{itemId}/score` | Upvotes, Downvotes und Score abrufen |
| `GET` | `/items/{itemId}/vote/{userId}` | Aktuelle Stimme eines Benutzers für ein Element abrufen |
