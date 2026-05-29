---
title: "Quality Tools"
category: getting-started
tags: [phpstan, php-cs-fixer, static-analysis, code-style]
difficulty: beginner
related: [add-health-check, add-custom-route]
---

# Quality Tools

This guide covers PHPStan, PHP-CS-Fixer, and common issues when running them in NENE2-based projects.

---

## PHPStan

NENE2 targets PHPStan level 8. Consumer projects that install `hideyukimori/nene2` via Composer can configure PHPStan for their own source in a `phpstan.neon` file.

### Minimal configuration

```neon
# phpstan.neon
parameters:
    level: 8
    paths:
        - src
        - tests
```

### Memory limit

PHPStan may run out of memory when analysing a project that imports many vendor packages (nene2 + psr/* + nyholm/psr7 + phpunit stubs add up quickly). The default PHP memory limit is 128 MB, which is often too low.

**In PHPStan 2.x, `memory_limit` is a CLI-only option — it cannot be set in `phpstan.neon`.**

Pass it on the command line instead:

```bash
phpstan analyse --level=8 --memory-limit=512M src tests
```

Or set it in the `scripts` section of `composer.json` so it applies consistently:

```json
{
    "scripts": {
        "analyse": "phpstan analyse --level=8 --memory-limit=512M src tests",
        "check": ["@test", "@analyse", "@cs"]
    }
}
```

> **Note**: Adding `memory_limit: 512M` under `parameters:` in `phpstan.neon` causes
> `Invalid configuration: Unexpected item 'parameters › memory_limit'.` in PHPStan 2.x.
> Use the CLI flag instead.

### Suppressing false positives

If PHPStan emits a false positive that cannot be fixed (e.g., a PHPDoc mismatch in a vendor class), use an inline ignore:

```php
/** @phpstan-ignore-next-line */
$value = $externalLib->getSomething();
```

Or add a baseline file:

```bash
phpstan analyse --generate-baseline
```

---

## PHP-CS-Fixer

NENE2 uses `@PSR12` as the base rule set, plus `strict_param` and `declare_strict_types`.

### Minimal configuration

Create `.php-cs-fixer.php` at the project root:

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

### Common formatting issues

**Constructor body expansion**: `@PSR12` requires constructor bodies to span multiple lines even when empty. CS-Fixer automatically expands:

```php
// Before (written by hand)
public function __construct(private Foo $foo) {}

// After CS-Fixer fix
public function __construct(private Foo $foo)
{
}
```

Run `composer cs:fix` after writing new classes to avoid manual corrections.

**`declare(strict_types=1)` placement**: The `declare_strict_types` rule adds this declaration automatically. If you write it manually, CS-Fixer will normalise whitespace around it.

### Dry-run vs fix

```bash
composer cs        # dry-run, shows what would change
composer cs:fix    # applies fixes
```

---

## Combining checks

Add a `check` composer script that runs all tools in sequence:

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

Run `composer check` before every commit to catch issues early. CI should run the same command.
