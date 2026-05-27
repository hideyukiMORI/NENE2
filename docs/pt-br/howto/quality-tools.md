# Ferramentas de Qualidade

Este guia cobre PHPStan, PHP-CS-Fixer e problemas comuns ao executá-los em projetos baseados no NENE2.

---

## PHPStan

O NENE2 tem como alvo o PHPStan nível 8. Projetos consumidores que instalam `hideyukimori/nene2` via Composer podem configurar o PHPStan para seu próprio código-fonte em um arquivo `phpstan.neon`.

### Configuração mínima

```neon
# phpstan.neon
parameters:
    level: 8
    paths:
        - src
        - tests
```

### Limite de memória

O PHPStan pode ficar sem memória ao analisar um projeto que importa muitos pacotes vendor (nene2 + psr/* + nyholm/psr7 + stubs do phpunit somam rapidamente). O limite padrão de memória do PHP é 128 MB, o que geralmente é muito baixo.

**No PHPStan 2.x, `memory_limit` é uma opção apenas de CLI — não pode ser definida em `phpstan.neon`.**

Passe-a na linha de comando em vez disso:

```bash
phpstan analyse --level=8 --memory-limit=512M src tests
```

Ou defina na seção `scripts` do `composer.json` para aplicar de forma consistente:

```json
{
    "scripts": {
        "analyse": "phpstan analyse --level=8 --memory-limit=512M src tests",
        "check": ["@test", "@analyse", "@cs"]
    }
}
```

> **Nota**: Adicionar `memory_limit: 512M` em `parameters:` no `phpstan.neon` causa
> `Invalid configuration: Unexpected item 'parameters › memory_limit'.` no PHPStan 2.x.
> Use o flag de CLI em vez disso.

### Suprimindo falsos positivos

Se o PHPStan emitir um falso positivo que não pode ser corrigido (ex.: incompatibilidade de PHPDoc em uma classe vendor), use um ignore inline:

```php
/** @phpstan-ignore-next-line */
$value = $externalLib->getSomething();
```

Ou adicione um arquivo baseline:

```bash
phpstan analyse --generate-baseline
```

---

## PHP-CS-Fixer

O NENE2 usa `@PSR12` como conjunto base de regras, mais `strict_param` e `declare_strict_types`.

### Configuração mínima

Crie `.php-cs-fixer.php` na raiz do projeto:

```php
<?php

$finder = PhpCsFixer\Finder::create()->in([__DIR__ . '/src', __DIR__ . '/tests']);

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PSR12'               => true,
        'strict_param'         => true,
        'declare_strict_types' => true,
    ])
    ->setFinder($finder);
```

### Problemas de formatação comuns

**Expansão do corpo do construtor**: `@PSR12` requer que os corpos dos construtores se estendam por várias linhas mesmo quando vazios. O CS-Fixer expande automaticamente:

```php
// Antes (escrito à mão)
public function __construct(private Foo $foo) {}

// Após correção pelo CS-Fixer
public function __construct(private Foo $foo)
{
}
```

Execute `composer cs:fix` após escrever novas classes para evitar correções manuais.

**Posicionamento de `declare(strict_types=1)`**: A regra `declare_strict_types` adiciona esta declaração automaticamente. Se você a escrever manualmente, o CS-Fixer normalizará os espaços em branco ao redor dela.

### Dry-run vs correção

```bash
composer cs        # dry-run, mostra o que seria alterado
composer cs:fix    # aplica as correções
```

---

## Combinando verificações

Adicione um script `check` no composer que executa todas as ferramentas em sequência:

```json
{
    "scripts": {
        "test":     "phpunit",
        "analyse":  "phpstan analyse --level=8 --memory-limit=512M src tests",
        "cs":       "php-cs-fixer fix --dry-run --diff",
        "cs:fix":   "php-cs-fixer fix",
        "check":    ["@test", "@analyse", "@cs"]
    }
}
```

Execute `composer check` antes de cada commit para detectar problemas antecipadamente. O CI deve executar o mesmo comando.
