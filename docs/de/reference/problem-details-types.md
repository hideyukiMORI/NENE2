# Problem Details-Typen

NENE2 gibt für alle Fehlerantworten `application/problem+json` zurück, gemäß [RFC 9457](https://www.rfc-editor.org/rfc/rfc9457).

## Typkatalog

| `type` | HTTP-Status | `title` | Erzeugt von |
|---|---|---|---|
| `…/not-found` | 404 | Not Found | Route nicht gefunden; Note oder Tag mit angegebener Id nicht vorhanden |
| `…/method-not-allowed` | 405 | Method Not Allowed | Falsche HTTP-Methode für eine bekannte Route |
| `…/validation-failed` | 422 | Validation Failed | Ungültiger Anfragekörper oder fehlende Pflichtfelder |
| `…/unauthorized` | 401 | Unauthorized | Bearer-Token fehlt oder ist ungültig |
| `…/payload-too-large` | 413 | Payload Too Large | Anfragekörper überschreitet das konfigurierte Limit |
| `…/internal-server-error` | 500 | Internal Server Error | Unbehandelte Ausnahme |

Basis-URI-Präfix: `https://nene2.dev/problems/`

## Benutzerdefinierten Typ hinzufügen

1. Erstellen Sie eine Domain-Exception-Klasse (z.B. `ProductNotFoundException`).
2. Implementieren Sie `DomainExceptionHandlerInterface` und rufen Sie `ProblemDetailsResponseFactory::create()` auf.
3. Registrieren Sie den Handler in `RuntimeServiceProvider`.

Sehen Sie `NoteNotFoundExceptionHandler` und `TagNotFoundExceptionHandler` als konkrete Beispiele.
