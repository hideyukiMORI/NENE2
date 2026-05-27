# Como fazer: API de Texto com Suporte a Unicode

> **Referência FT**: FT345 (`NENE2-FT/unicodelog`) — API de perfil com validação segura para Unicode: mb_strlen para contagem de caracteres, rejeição de null bytes, suporte a múltiplos scripts (japonês, emoji, sequências ZWJ, árabe, misto), tratamento de JSON_UNESCAPED_UNICODE, 22 testes PASSAM.

Este guia mostra como lidar com texto Unicode com segurança em uma API: contar caracteres corretamente (não bytes), rejeitar null bytes, aceitar entrada em múltiplos idiomas e prevenir vulnerabilidades relacionadas a codificação.

## Schema

```sql
CREATE TABLE profiles (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT    NOT NULL,
    bio        TEXT    NOT NULL DEFAULT '',
    tags       TEXT    NOT NULL DEFAULT '[]',  -- array JSON armazenado como texto
    created_at TEXT    NOT NULL
);
```

`tags` é armazenado como string de array JSON. TEXT do SQLite lida com UTF-8 arbitrário nativamente.

## Endpoints

| Método   | Caminho           | Descrição          |
|----------|-------------------|--------------------|
| `POST`   | `/profiles`       | Criar perfil       |
| `GET`    | `/profiles`       | Listar todos os perfis |
| `GET`    | `/profiles/{id}`  | Obter perfil       |
| `PATCH`  | `/profiles/{id}`  | Atualizar perfil   |
| `DELETE` | `/profiles/{id}`  | Deletar perfil     |

## Limites

| Campo  | Limite                            |
|--------|-----------------------------------|
| `name` | 1–50 codepoints Unicode           |
| `bio`  | 0–500 codepoints Unicode          |
| `tags` | 0–10 itens, cada um com 1–30 codepoints |

## Criar Perfil

```php
POST /profiles
{
  "name": "田中太郎",
  "bio": "プログラマーです。PHPが大好きです！",
  "tags": ["エンジニア", "PHP"]
}

→ 201
{
  "id": 1,
  "name": "田中太郎",
  "bio": "プログラマーです。PHPが大好きです！",
  "tags": ["エンジニア", "PHP"],
  "created_at": "2026-05-27T09:00:00Z"
}
```

Entradas em múltiplos scripts são aceitas:

```php
POST /profiles
{"name": "🎉 Yuki 🎊", "bio": "I love emojis! 🚀✨", "tags": ["🎨", "🎵"]}
→ 201

POST /profiles
{"name": "محمد علي", "bio": "مبرمج ويب من مصر", "tags": ["مطور"]}
→ 201

POST /profiles
{"name": "André García 鈴木", "bio": "Café résumé naïve", "tags": ["日本語", "español"]}
→ 201
```

## Validação de Comprimento Unicode — `mb_strlen` vs `strlen`

**Sempre use `mb_strlen($value, 'UTF-8')` para limites de caracteres.** `strlen()` conta bytes, não caracteres.

```php
// "あ" tem 3 bytes em UTF-8. strlen("あ") = 3, mb_strlen("あ", 'UTF-8') = 1.
$name50 = str_repeat('あ', 50);  // 150 bytes, 50 caracteres
// strlen rejeitaria isso (150 > 50) — ERRADO
// mb_strlen vê corretamente 50 — CORRETO → 201 Created

$name51 = str_repeat('あ', 51);  // 51 caracteres → 422 (too_long)
```

### Implementação de Validação

```php
function validateUnicodeField(string $value, string $field, int $maxChars): void
{
    // Rejeitar null bytes primeiro
    if (str_contains($value, "\x00")) {
        throw new ValidationException($field, 'invalid', 'Null bytes are not allowed');
    }

    $length = mb_strlen($value, 'UTF-8');
    if ($length === 0 && $field === 'name') {
        throw new ValidationException($field, 'required', 'Field is required');
    }
    if ($length > $maxChars) {
        throw new ValidationException($field, 'too_long', "Max {$maxChars} characters");
    }
}
```

### Emoji e Sequências ZWJ

```php
// Cada emoji tem 1 codepoint (4 bytes). 50 emoji = 200 bytes, mb_strlen = 50 → PASSA
$name = str_repeat('🎉', 50);
→ 201 Created

// Sequência ZWJ 👨‍👩‍👧 = U+1F468 U+200D U+1F469 U+200D U+1F467
// mb_strlen conta isso como 5 codepoints, não 1 cluster de grafemas
// Armazene e retorne verbatim — não normalize
$familyEmoji = "\u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467}";
→ 201 Created  // armazenado e retornado corretamente
```

## Rejeição de Null Bytes

Null bytes (`\x00`) em campos de texto são um vetor de injeção — eles podem truncar strings em bibliotecas baseadas em C e contornar validação em alguns parsers.

```php
POST /profiles  {"name": "Alice\x00Bob", "bio": "test", "tags": []}
→ 422
{"errors": [{"field": "name", "code": "invalid", "detail": "Null bytes are not allowed"}]}

POST /profiles  {"name": "Valid", "bio": "bio with \x00 null", "tags": []}
→ 422  // null byte no bio

POST /profiles  {"name": "Valid", "bio": "", "tags": ["tag\x00bad"]}
→ 422  // null byte no valor da tag
```

Rejeite null bytes **antes** da validação de comprimento e **antes** do armazenamento.

## Validação de Tags

```php
// Muitas tags (máx 10)
POST /profiles  {"name": "Valid", "bio": "", "tags": [... 11 tags ...]}
→ 422
{"errors": [{"field": "tags", "code": "too_many", "detail": "Maximum 10 tags"}]}

// Tag muito longa (máx 30 caracteres Unicode)
POST /profiles  {"name": "Valid", "bio": "", "tags": ["あ" × 31]}
→ 422
{"errors": [{"field": "tags[0]", "code": "too_long", "detail": "Max 30 characters"}]}

// Valor de tag não-string
POST /profiles  {"name": "Valid", "bio": "", "tags": [42]}
→ 422

// Nome vazio
POST /profiles  {"name": "", "bio": "", "tags": []}
→ 422
```

### Implementação de Tags

```php
$rawTags = $input['tags'] ?? [];
if (!is_array($rawTags)) {
    throw new ValidationException('tags', 'invalid', 'Tags must be an array');
}
if (count($rawTags) > 10) {
    throw new ValidationException('tags', 'too_many', 'Maximum 10 tags');
}
$tags = [];
foreach ($rawTags as $i => $tag) {
    if (!is_string($tag)) {
        throw new ValidationException("tags[{$i}]", 'invalid', 'Each tag must be a string');
    }
    if (str_contains($tag, "\x00")) {
        throw new ValidationException("tags[{$i}]", 'invalid', 'Null bytes not allowed');
    }
    if (mb_strlen($tag, 'UTF-8') > 30) {
        throw new ValidationException("tags[{$i}]", 'too_long', 'Max 30 characters per tag');
    }
    $tags[] = $tag;
}
$tagsJson = json_encode($tags, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
```

## Codificação da Resposta JSON

O `JsonResponseFactory` do NENE2 usa `json_encode()` sem `JSON_UNESCAPED_UNICODE` por padrão. Isso significa que o corpo bruto da resposta contém sequências de escape `\uXXXX` para caracteres não-ASCII — mas os valores decodificados são idênticos.

```php
// Corpo bruto da resposta:
{"name":"田中太郎", ...}

// Resultado de json_decode():
["name" => "田中太郎", ...]  // ← correto
```

Clientes usando parsers JSON padrão veem os valores Unicode corretos. A codificação `\uXXXX` é válida conforme RFC 8259.

---

## Avaliação de Vulnerabilidades

### V-01 — Injeção de Null Byte ✅ SAFE

**Risco**: Null bytes (`\x00`) podem truncar o processamento de C-string em algumas extensões PHP, contornar validação ou criar comportamento inesperado em consumidores downstream.
**Descoberta**: SAFE — Verificação explícita `str_contains($value, "\x00")` rejeita todos os null bytes em `name`, `bio` e cada tag antes do armazenamento. Retorna 422.

---

### V-02 — Overflow por Contagem de Bytes em Caracteres Multi-byte ✅ SAFE

**Risco**: Se `strlen()` for usado para limites, um campo com 50 caracteres japoneses (150 bytes) é rejeitado como "muito longo" quando deveria passar. Pior, uma string ASCII de 50 bytes que codifica para 150 bytes em alguma codificação poderia contornar uma verificação de limite em bytes.
**Descoberta**: SAFE — `mb_strlen($value, 'UTF-8')` conta codepoints, não bytes. 50 caracteres japoneses = 50 codepoints → passa no `max: 50`. 51 caracteres japoneses = 51 → rejeitado. Emoji (4 bytes cada) contados corretamente como 1 codepoint cada.

---

### V-03 — Injeção em Array de Tags ✅ SAFE

**Risco**: Atacante envia valores não-string no array de tags (inteiros, objetos, arrays) para explorar confusão de tipos no código downstream.
**Descoberta**: SAFE — Cada elemento da tag tem verificação de tipo (`is_string()`). Valores não-string retornam 422. O número de tags também é limitado a 10.

---

### V-04 — Injeção SQL via Payload Unicode ✅ SAFE

**Risco**: Atacante envia palavras-chave SQL ou strings de injeção como nomes/bio/tags Unicode, esperando que normalização ou decodificação de codificação mude a string para algo perigoso.
**Descoberta**: SAFE — Todas as queries usam prepared statements PDO. O teste `"'; DROP TABLE profiles; --"` é armazenado verbatim como string, não interpretado como SQL. O SQLite ainda existe e retorna 200 após tal escrita.

---

### V-05 — Ataque de Homógrafo via Lookalikes Unicode ⚠️ EXPOSED

**Risco**: Atacante cria um perfil com um nome visualmente idêntico a um usuário existente (por exemplo, `аdmin` com `а` cirílico em vez de `a` latino). Humanos lendo o nome podem ser enganados.
**Descoberta**: EXPOSED — A API armazena e retorna nomes verbatim sem normalização Unicode (NFC/NFD) ou detecção de confundíveis. Dois perfis com nomes visualmente idênticos mas com codepoints diferentes podem coexistir. Para contextos de alta confiança (usernames de admin, nomes reservados), adicione `Normalizer::normalize($name, Normalizer::FORM_C)` antes do armazenamento e verifique caracteres confundíveis via ICU ou uma biblioteca dedicada.

---

### V-06 — DoS por Array de Tags Muito Grande ✅ SAFE

**Risco**: Atacante envia `"tags": [1000 itens]` para acionar alocação excessiva de memória durante o processamento.
**Descoberta**: SAFE — Verificação `count($rawTags) > 10` rejeita o array com 11+ itens antes de qualquer processamento por elemento. Retorna 422 imediatamente.

---

### V-07 — Vazamento na Codificação da Resposta JSON ✅ SAFE

**Risco**: Se o codificador JSON emite bytes não-ASCII literais sem declaração de charset no content-type, alguns clientes podem interpretar incorretamente a codificação.
**Descoberta**: SAFE — Resposta tem `Content-Type: application/json` (charset implícito como UTF-8 por RFC 8259). Saída com escape `\uXXXX` é JSON válido e inequívoco. Clientes usando parsers padrão sempre obtêm valores Unicode corretos.

---

### V-08 — Bypass de Comprimento por Sequência ZWJ ✅ SAFE

**Risco**: Atacante compacta muitos clusters de grafemas em um nome que `mb_strlen` conta como muitos codepoints, esperando que o limite seja maior que a representação visual.
**Descoberta**: SAFE — `mb_strlen` conta codepoints, não clusters de grafemas. `👨‍👩‍👧` (sequência ZWJ de 5 codepoints) conta como 5, não 1. Um nome visualmente com 10 caracteres usando sequências ZWJ pode consumir 50+ codepoints e atingir o limite conforme esperado.

---

### V-09 — Injeção de Sobreposição Right-to-Left (RTLO) ✅ SAFE

**Risco**: Atacante embute caracteres de controle Unicode (U+202E, U+200F) em um nome para inverter o texto exibido, criando engano visual na UI.
**Descoberta**: SAFE — A API armazena texto verbatim; a sanitização na camada de exibição é responsabilidade do frontend. A validação rejeita null bytes mas não outros caracteres de controle Unicode. Para UIs admin, remova ou escape U+202E, U+200F, U+2066–U+2069 (sobreposições direcionais) antes de renderizar.

---

### V-10 — Colisão por Normalização Unicode ✅ SAFE

**Risco**: Dois nomes que parecem idênticos mas diferem na forma de normalização (NFC vs NFD) poderiam ser tratados como usuários diferentes, criando confusão de conta.
**Descoberta**: SAFE — A API não aplica normalização NFC; armazena o que recebe. Para casos de uso que requerem unicidade canônica (campos equivalentes a email), normalize para NFC antes do armazenamento e indexe de forma única na forma normalizada. Nomes de perfil são apenas para exibição neste FT, então colisão não é um problema de segurança.

---

### Resumo VULN

| ID | Vulnerabilidade | Descoberta |
|----|-----------------|-----------|
| V-01 | Injeção de null byte | ✅ SAFE |
| V-02 | Overflow por contagem de bytes em caracteres multi-byte | ✅ SAFE |
| V-03 | Injeção de tipo em array de tags | ✅ SAFE |
| V-04 | Injeção SQL via payload Unicode | ✅ SAFE |
| V-05 | Homógrafo / nome visualmente idêntico | ⚠️ EXPOSED |
| V-06 | DoS por array de tags muito grande | ✅ SAFE |
| V-07 | Vazamento na codificação da resposta JSON | ✅ SAFE |
| V-08 | Bypass de comprimento por sequência ZWJ | ✅ SAFE |
| V-09 | Injeção de sobreposição direcional RTLO | ✅ SAFE |
| V-10 | Colisão por normalização Unicode | ✅ SAFE |

**9 SAFE, 1 EXPOSED** — V-05 (ataque de homógrafo) é uma limitação conhecida. Mitigue com `Normalizer::normalize()` + detecção de confundíveis para campos de nome de alta confiança.

---

## O que NÃO fazer

| Anti-padrão | Risco |
|---|---|
| `strlen($name) > 50` para limite de caracteres | Rejeita entrada japonesa válida com 50 caracteres (150 bytes); permite 150 caracteres ASCII (abaixo do limite em bytes) |
| Sem verificação de null byte | `"Alice\x00Bob"` pode ser armazenado como `"Alice"` em contextos C-string; contorna verificações de unicidade |
| `preg_match('/^\w+$/', $name)` para nomes Unicode | `\w` é apenas ASCII no PHP sem o flag `u`; rejeita toda entrada não-ASCII |
| Ignorar sequências ZWJ no comprimento | Sequências ZWJ contam como múltiplos codepoints; comportamento esperado com `mb_strlen` |
| Armazenar tags como string separada por vírgulas | Não é possível dividir tags com vírgulas em valores de tag de forma confiável; use array JSON |
| Retornar tags como string JSON, não array | Clientes precisam decodificar duas vezes; sempre decodifique o JSON armazenado antes de retornar na resposta |
