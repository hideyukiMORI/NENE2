# Como validar entrada Unicode

O NENE2 armazena e retorna strings como UTF-8. Este guia aborda as armadilhas da validação com suporte a Unicode e como tratá-las.

## Use `mb_strlen` para limites de contagem de caracteres

`strlen` conta bytes, não caracteres. Japonês, árabe e emoji usam múltiplos bytes por caractere.

```php
strlen('あ')              // 3 (bytes)
mb_strlen('あ', 'UTF-8') // 1 (caractere)

strlen('🎉')              // 4 (bytes)
mb_strlen('🎉', 'UTF-8') // 1 (caractere — um codepoint)
```

Sempre use `mb_strlen($value, 'UTF-8')` ao aplicar um limite de caracteres:

```php
private const int NAME_MAX_CHARS = 50;

if (mb_strlen($name, 'UTF-8') > self::NAME_MAX_CHARS) {
    $errors[] = ['field' => 'name', 'code' => 'too_long',
                 'message' => 'name must be at most ' . self::NAME_MAX_CHARS . ' characters.'];
}
```

**Por que `strlen` quebra:** Um nome japonês com 50 caracteres tem 150 bytes. `strlen(...) > 50` o rejeitaria.

## Rejeitar null bytes explicitamente

Colunas TEXT do SQLite aceitam null bytes (`\x00`). Operações de string PHP também as tratam — mas null bytes na entrada do usuário são quase sempre tentativas de injeção ou bugs de codificação. Rejeite-os cedo:

```php
if (str_contains($name, "\x00")) {
    $errors[] = ['field' => 'name', 'code' => 'invalid', 'message' => 'name must not contain null bytes.'];
}
```

Aplique esta verificação a todo campo string antes de outras validações (comprimento, formato, etc.).

## Clusters de grafemas vs codepoints

`mb_strlen` conta _codepoints_ Unicode. Um glifo visível (cluster de grafemas) pode ser múltiplos codepoints:

| Entrada | Codepoints | `mb_strlen` | Glifos |
|---------|-----------|-------------|--------|
| `é` (precomposto) | 1 | 1 | 1 |
| `é` (e + acento combinando) | 2 | 2 | 1 |
| 👨‍👩‍👧 (família ZWJ) | 5 | 5 | 1 |

Para a maioria dos casos de uso (usernames, bios), a contagem por codepoint é suficiente. Se você precisar contar caracteres visíveis, use `grapheme_strlen()` da extensão `intl`:

```php
grapheme_strlen('👨‍👩‍👧') // 1
mb_strlen('👨‍👩‍👧', 'UTF-8') // 5
```

Escolha o método de contagem que corresponda à expectativa do usuário para o seu campo.

## Respostas JSON e caracteres não-ASCII

`JsonResponseFactory` codifica respostas com `JSON_UNESCAPED_UNICODE`, então caracteres não-ASCII aparecem como UTF-8 literal no corpo da resposta:

```json
{ "name": "田中太郎" }
```

Se você está construindo uma chamada `json_encode` personalizada em outro lugar (por exemplo, armazenando tags como JSON em uma coluna TEXT), adicione o mesmo flag:

```php
$tagsJson = json_encode($tags, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
```

Sem `JSON_UNESCAPED_UNICODE`, o valor armazenado seria `["\\u30bf\\u30b0"]` em vez de `["タグ"]`.

## Exemplo completo de validação

```php
private const int NAME_MAX_CHARS = 50;

private function validateName(string $raw): ?string
{
    if ($raw === '') {
        return 'name is required.';
    }
    if (str_contains($raw, "\x00")) {
        return 'name must not contain null bytes.';
    }
    if (mb_strlen($raw, 'UTF-8') > self::NAME_MAX_CHARS) {
        return 'name must be at most ' . self::NAME_MAX_CHARS . ' characters.';
    }
    return null; // válido
}
```

## Testando valores de fronteira

Sempre escreva testes para:

- Exatamente `MAX` caracteres (deve passar) — use um caractere Unicode para verificar a diferença byte/char:

  ```php
  $name50 = str_repeat('あ', 50); // 150 bytes, 50 chars — deve passar
  ```

- `MAX + 1` caracteres (deve falhar):

  ```php
  $name51 = str_repeat('あ', 51); // deve retornar 422 com too_long
  ```

- Rejeição de null byte:

  ```php
  "Valid\x00Name" // deve retornar 422 com invalid
  ```
