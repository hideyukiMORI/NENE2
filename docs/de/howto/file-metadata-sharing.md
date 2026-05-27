# Implementierungsleitfaden: Datei-Metadaten-Verwaltung und Freigabe-API

## Übersicht

Dieser Leitfaden erklärt, wie eine Datei-Metadaten-Verwaltungs-API mit NENE2 implementiert wird.
Es werden keine tatsächlichen Dateien gespeichert — stattdessen werden Metadaten (Name, Größe, MIME-Typ,
Beschreibung, Sichtbarkeit) verwaltet und die Freigabe zwischen Benutzern (view/edit-Berechtigungen) unterstützt.

---

## DB-Schema

```sql
CREATE TABLE files (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    name TEXT NOT NULL,
    size INTEGER NOT NULL DEFAULT 0 CHECK (size >= 0),
    mime_type TEXT NOT NULL,
    description TEXT,
    visibility TEXT NOT NULL DEFAULT 'private' CHECK (visibility IN ('private', 'public')),
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

CREATE TABLE file_shares (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    file_id INTEGER NOT NULL,
    shared_with_user_id INTEGER NOT NULL,
    can_edit INTEGER NOT NULL DEFAULT 0 CHECK (can_edit IN (0, 1)),
    created_at TEXT NOT NULL,
    UNIQUE (file_id, shared_with_user_id),
    FOREIGN KEY (file_id) REFERENCES files(id),
    FOREIGN KEY (shared_with_user_id) REFERENCES users(id)
);
```

**Designentscheidungen**

- `visibility CHECK (visibility IN ('private', 'public'))` — Gültige Werte auf DB-Ebene eingeschränkt
- `can_edit CHECK (can_edit IN (0, 1))` — SQLite Boolean als INTEGER 0/1
- `UNIQUE (file_id, shared_with_user_id)` — Doppelte Freigaben an denselben Benutzer verhindert

---

## Endpunkt-Design

| Methode | Pfad | Beschreibung |
|---|---|---|
| `GET` | `/files` | Liste zugänglicher Dateien (eigene + freigegebene) |
| `POST` | `/files` | Datei-Metadaten erstellen |
| `GET` | `/files/{fileId}` | Datei abrufen (nur Eigentümer, öffentlich oder freigegeben) |
| `PUT` | `/files/{fileId}` | Aktualisieren (Eigentümer oder edit-Freigabe) |
| `DELETE` | `/files/{fileId}` | Löschen (nur Eigentümer) |
| `POST` | `/files/{fileId}/shares` | Mit Benutzer teilen |
| `DELETE` | `/files/{fileId}/shares/{userId}` | Freigabe aufheben (nur Eigentümer) |

---

## Zugriffssteuerungs-Design

### 3-stufige Zugriffsebenen

```
Eigentümer (user_id = X-User-Id)
  → Alle Operationen möglich
  
edit-Freigabe (file_shares.can_edit = 1)
  → GET / PUT möglich
  → visibility-Änderung nicht möglich (nur Eigentümer)
  → DELETE nicht möglich
  
view-Freigabe (file_shares.can_edit = 0) oder öffentliche Datei
  → Nur GET möglich
```

### Existenz-Verschleierung (IDOR-Prävention)

Private Dateien anderer Benutzer geben **404** zurück (nicht 403).
403 würde implizieren "Datei existiert, aber kein Zugriff" und begünstigt ID-Rateraten-Angriffe.

```php
if ((int) $file['user_id'] !== $userId) {
    $share = $this->repo->findShare($fileId, $userId);
    if ($share === null) {
        return $this->json->create(['error' => 'File not found'], 404); // 403 statt 404 vermeiden
    }
}
```

---

## Zugängliche Dateien auflisten — Abfrage

```php
return $this->db->fetchAll(
    'SELECT f.id, f.user_id, f.name, f.size, f.mime_type, f.description,
            f.visibility, f.created_at, f.updated_at,
            u.name AS owner_name,
            CASE WHEN f.user_id = ? THEN 1 ELSE fs.can_edit END AS can_edit,
            CASE WHEN f.user_id = ? THEN 1 ELSE 0 END AS is_owner
     FROM files f
     JOIN users u ON u.id = f.user_id
     LEFT JOIN file_shares fs ON fs.file_id = f.id AND fs.shared_with_user_id = ?
     WHERE f.user_id = ? OR fs.shared_with_user_id = ?
     ORDER BY f.created_at DESC, f.id DESC',
    [$userId, $userId, $userId, $userId, $userId]
);
```

- `LEFT JOIN` verbindet die Freigabetabelle; `WHERE` holt "eigene ODER freigegebene" Dateien
- Öffentliche Dateien sind nicht in der Liste enthalten (Abruf einzeln via GET möglich)
- `CASE WHEN` berechnet Eigentümer-Flag und Bearbeitungsberechtigung

---

## Sichtbarkeits-Eskalations-Verhinderung

Auch edit-Freigabe-Benutzer können `visibility` nicht ändern. Nur der Eigentümer darf das.

```php
// Only owner can change visibility
if ($ownerId !== $userId) {
    $visibility = (string) $file['visibility']; // Override with current value
}

$this->repo->update($fileId, $name, $size, $mimeType, $description, $visibility, $now);
```

---

## Bereinigung von Freigabe-Einträgen beim Löschen

```php
public function delete(int $id): void
{
    $this->db->execute('DELETE FROM file_shares WHERE file_id = ?', [$id]);
    $this->db->execute('DELETE FROM files WHERE id = ?', [$id]);
}
```

Wegen FK-Constraint muss `file_shares` zuerst gelöscht werden, dann `files`.

---

## Validierungs-Design

```php
// name: erforderlich, max. 255 Zeichen
if (!isset($body['name']) || !is_string($body['name']) || trim($body['name']) === '') {
    $errors[] = new ValidationError('name', 'name is required', 'required');
} elseif (mb_strlen($body['name']) > 255) {
    $errors[] = new ValidationError('name', 'name is too long', 'too_long');
}

// size: Ganzzahl erforderlich, >= 0
if (!isset($body['size']) || !is_int($body['size'])) {
    $errors[] = new ValidationError('size', 'size must be an integer', 'invalid_type');
}

// visibility: Enum-Wertprüfung
if (!in_array($body['visibility'], ['private', 'public'], true)) {
    $errors[] = new ValidationError('visibility', 'visibility must be private or public', 'invalid_value');
}
```

---

## Schwachstellendiagnose-Ergebnisse (FT156)

| ID | Schwachstelle | Ergebnis |
|---|---|---|
| VULN-A | IDOR: Direktzugriff auf private Datei eines anderen Benutzers | Bestanden (gibt 404 zurück) |
| VULN-B | IDOR: Datei eines anderen Benutzers löschen | Bestanden (gibt 404 zurück) |
| VULN-C | IDOR: Datei eines anderen Benutzers aktualisieren | Bestanden (gibt 404 zurück) |
| VULN-D | Rechteeskalation: view-Freigabe versucht edit-Operation | Bestanden (gibt 403 zurück) |
| VULN-E | Eigentümerschafts-Injektion: user_id im Body | Bestanden (wird ignoriert) |
| VULN-F | Freigabe-Lösch-Spoofing: Freigabe-Empfänger löscht eigene Freigabe | Bestanden (gibt 404 zurück) |
| VULN-G | SQL-Injektion: Dateiname | Bestanden (parametrisierte Abfragen) |
| VULN-H | Zu langer name: 300 Zeichen | Bestanden (gibt 422 zurück) |
| VULN-I | Typ-Verwirrung: Float für size | Bestanden (gibt 422 zurück) |
| VULN-J | Sichtbarkeits-Eskalation: edit-Freigabe ändert visibility | Bestanden (wird ignoriert) |
| VULN-K | Existenz-Erkennung: 403 vs. 404 | Bestanden (gibt 404 zurück) |
| VULN-L | Authentifizierungs-Bypass: X-User-Id=0 / negativer Wert | Bestanden (gibt 401 zurück) |

---

## Cracker-Angriffstests (FT156)

| ID | Angriffsszenario | Ergebnis |
|---|---|---|
| ATK-01 | Spoofing: GET der Datei eines anderen Benutzers | Bestanden (gibt 404 zurück) |
| ATK-02 | Spoofing: DELETE der Datei eines anderen Benutzers | Bestanden (gibt 404 zurück) |
| ATK-03 | view-Freigabe versucht PUT-Bearbeitung | Bestanden (gibt 403 zurück) |
| ATK-04 | user_id im Body injiziert zur Eigentümer-Fälschung | Bestanden (wird ignoriert) |
| ATK-05 | Path-Traversal: `../../etc/passwd` | Bestanden (gibt 404 zurück) |
| ATK-06 | Zugriffsversuch mit String-ID | Bestanden (gibt 404 zurück) |
| ATK-07 | X-User-Id-Header als leere Zeichenkette gesendet | Bestanden (gibt 401 zurück) |
| ATK-08 | SQL-Injektion: mime_type-Feld | Bestanden (parametrisierte Abfragen) |
| ATK-09 | Extrem lange description (10.000 Zeichen) | Bestanden (wird gespeichert, ohne Kürzung; name > 255 gibt 422) |
| ATK-10 | edit-Freigabe eskaliert visibility auf public | Bestanden (wird ignoriert) |
| ATK-11 | Freigabe-Empfänger versucht eigene Freigabe zu löschen | Bestanden (gibt 404 zurück) |
| ATK-12 | Existenz-Sondierung: Datei-IDs anderer Benutzer raten | Bestanden (gibt 404 zurück) |

---

## Test-Schwerpunkte

```php
// Private Dateien anderer Benutzer geben 404 zurück (nicht 403)
$res = $this->req('GET', "/files/{$fileId}", ['X-User-Id' => '2']);
$this->assertSame(404, $res->getStatusCode());

// edit-Freigabe kann visibility nicht ändern
$this->req('PUT', "/files/{$fileId}", ['X-User-Id' => '2'], [
    'name' => 'a.txt', 'size' => 1, 'mime_type' => 'text/plain', 'visibility' => 'public',
]);
$check = $this->req('GET', "/files/{$fileId}", ['X-User-Id' => '1']);
$this->assertSame('private', $this->json($check)['visibility']);

// user_id im Body wird ignoriert (wird aus X-User-Id bezogen)
$res = $this->req('POST', '/files', ['X-User-Id' => '1'], ['name' => 'test.txt', 'size' => 1, 'mime_type' => 'text/plain', 'user_id' => 2]);
$this->assertSame(1, $this->json($res)['user_id']);
```
