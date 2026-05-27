# Qualitätswerkzeuge

Diese Anleitung behandelt PHPStan, PHP-CS-Fixer und häufige Probleme beim Ausführen in NENE2-basierten Projekten.

---

## PHPStan

NENE2 zielt auf PHPStan Level 8 ab. Consumer-Projekte, die `hideyukimori/nene2` via Composer installieren, können PHPStan für ihre eigenen Quellen in einer `phpstan.neon`-Datei konfigurieren.

### Minimale Konfiguration

```neon
# phpstan.neon
parameters:
    level: 8
    paths:
        - src
        - tests
```

### Speicherlimit

PHPStan kann beim Analysieren eines Projekts, das viele Vendor-Pakete importiert, den Speicher aufbrauchen (nene2 + psr/* + nyholm/psr7 + phpunit stubs summieren sich schnell). Das Standard-PHP-Speicherlimit ist 128 MB, was oft zu niedrig ist.

**In PHPStan 2.x ist `memory_limit` eine CLI-Option — sie kann nicht in `phpstan.neon` gesetzt werden.**

Stattdessen auf der Kommandozeile übergeben:

```bash
phpstan analyse --level=8 --memory-limit=512M src tests
```

Oder im `scripts`-Abschnitt von `composer.json` festlegen, damit es konsistent angewendet wird:

```json
{
    "scripts": {
        "analyse": "phpstan analyse --level=8 --memory-limit=512M src tests",
        "check": ["@test", "@analyse", "@cs"]
    }
}
```

> **Hinweis**: Das Hinzufügen von `memory_limit: 512M` unter `parameters:` in `phpstan.neon` verursacht
> `Invalid configuration: Unexpected item 'parameters › memory_limit'.` in PHPStan 2.x.
> Stattdessen das CLI-Flag verwenden.

### Falsch-Positives unterdrücken

Wenn PHPStan ein Falsch-Positives ausgibt, das nicht behoben werden kann (z.B. eine PHPDoc-Inkongruenz in einer Vendor-Klasse), ein Inline-Ignore verwenden:

```php
/** @phpstan-ignore-next-line */
$value = $externalLib->getSomething();
```

Oder eine Baseline-Datei erstellen:

```bash
phpstan analyse --generate-baseline
```

---

## PHP-CS-Fixer

NENE2 verwendet `@PSR12` als Basis-Regelset, ergänzt um `strict_param` und `declare_strict_types`.

### Minimale Konfiguration

`.php-cs-fixer.php` im Projektstamm erstellen:

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

### Häufige Formatierungsprobleme

**Erweiterung des Konstruktorkörpers**: `@PSR12` erfordert, dass Konstruktorkörper mehrere Zeilen umspannen, auch wenn sie leer sind. CS-Fixer erweitert automatisch:

```php
// Vorher (von Hand geschrieben)
public function __construct(private Foo $foo) {}

// Nach CS-Fixer-Korrektur
public function __construct(private Foo $foo)
{
}
```

`composer cs:fix` nach dem Schreiben neuer Klassen ausführen, um manuelle Korrekturen zu vermeiden.

**`declare(strict_types=1)`-Platzierung**: Die `declare_strict_types`-Regel fügt diese Deklaration automatisch hinzu. Wenn sie manuell geschrieben wird, normalisiert CS-Fixer den Leerraum darum.

### Dry-run vs. Fix

```bash
composer cs        # dry-run, zeigt was geändert werden würde
composer cs:fix    # wendet Korrekturen an
```

---

## Prüfungen kombinieren

Ein `check`-Composer-Skript hinzufügen, das alle Werkzeuge nacheinander ausführt:

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

`composer check` vor jedem Commit ausführen, um Probleme frühzeitig zu erkennen. CI sollte denselben Befehl ausführen.
