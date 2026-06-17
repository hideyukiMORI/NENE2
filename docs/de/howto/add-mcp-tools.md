# MCP-Tools hinzufügen

Diese Anleitung zeigt, wie Sie die API-Endpunkte Ihrer NENE2-Anwendung als MCP-Tools bereitstellen, damit KI-Assistenten (Claude, Cursor usw.) Ihre API über das Model Context Protocol aufrufen können.

**Voraussetzung**: Eine funktionierende NENE2-Anwendung mit mindestens einer Route und einer `docs/openapi/openapi.yaml`-Datei.

---

## Übersicht

NENE2 liefert einen lokalen MCP-Server (`LocalMcpServer`), der JSON-RPC-MCP-Nachrichten in HTTP-Aufrufe an Ihre API übersetzt. Der Tool-Katalog (`docs/mcp/tools.json`) deklariert, welche Endpunkte als MCP-Tools verfügbar sind.

---

## 1. Validator-Skript hinzufügen

In `composer.json`:

```json
{
  "require-dev": { "symfony/yaml": "^7.0" },
  "scripts": {
    "mcp": "php vendor/hideyukimori/nene2/tools/validate-mcp-tools.php --root=."
  }
}
```

Installation: `composer require --dev symfony/yaml`

---

## 2. Tool-Katalog erstellen

Erstellen Sie `docs/mcp/tools.json`. Jeder Eintrag in `tools` entspricht einem API-Endpunkt.

### Sicherheitsstufen

| Stufe | Bedeutung |
|---|---|
| `read` | Sicher ohne Nebenwirkungen (GET-Anfragen) |
| `write` | Erstellt oder modifiziert Daten (POST / PUT / PATCH) |
| `admin` | Verwaltungsvorgang — mit Vorsicht verwenden |
| `destructive` | Löscht Daten dauerhaft — erfordert explizite Bestätigung |

---

## 3. Katalog validieren

```bash
composer mcp
```

---

## 4. MCP-Server starten

```bash
NENE2_LOCAL_API_BASE_URL=http://localhost:8200 \
NENE2_LOCAL_JWT_SECRET=your-local-secret \
php vendor/hideyukimori/nene2/tools/local-mcp-server.php
```

---

## 5. MCP-Schicht testen

```php
$catalog = new LocalMcpToolCatalog(dirname(__DIR__) . '/docs/mcp/tools.json');
$tool = $catalog->find('listNotes');
self::assertSame('read', $tool['safety']);
```

---

## Nächste Schritte

- [JWT-Authentifizierung hinzufügen](./add-jwt-authentication.md)
- [Rate-Limiting hinzufügen](./add-rate-limiting.md)
