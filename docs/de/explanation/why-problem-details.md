# Warum RFC 9457 Problem Details?

NENE2-API-Fehler verwenden das RFC 9457 Problem Details-Format. Diese Seite erklärt die Wahl.

## Wie Problem Details aussieht

```http
HTTP/1.1 422 Unprocessable Entity
Content-Type: application/problem+json

{
  "type": "https://nene2.dev/problems/validation-failed",
  "title": "Validation failed",
  "status": 422,
  "errors": [
    { "field": "title", "code": "required", "message": "Title is required." }
  ]
}
```

## Warum ein Standard statt einer eigenen Form?

### 1. Clients können Fehler generisch behandeln

Ein Client, der RFC 9457 kennt, kann `title` und `status` für jeden Fehler einer RFC 9457-API anzeigen, ohne die spezifische Anwendung zu kennen.

### 2. `Content-Type: application/problem+json` ist maschinenlesbar

Wenn eine Antwort `application/problem+json` trägt, weiß ein Client, dass er ein Fehlerobjekt erhalten hat. Dies ist wichtig für MCP-Tools und andere Maschinenclients.

### 3. Die `type`-URI gibt Fehlern eine stabile Identität

Jeder Problemtyp hat eine URI wie `https://nene2.dev/problems/validation-failed`. Diese URI ist stabil, dokumentierbar und für Pattern-Matching auf Client-Seite verwendbar.

### 4. Es ist ein veröffentlichter Standard

RFC 9457 (Nachfolger von RFC 7807) ist ein veröffentlichter IETF-Standard.

## Die `nene2.dev`-URIs

Die `type`-URIs in NENE2 verwenden derzeit `https://nene2.dev/problems/...` als Platzhalter-Domain. Vor dem Produktionseinsatz muss der Deployer diese Domain registrieren oder die Basis-URL in `ProblemDetailsResponseFactory` ersetzen.
