# Qualitätswerkzeuge

Diese Anleitung behandelt PHPStan, PHP-CS-Fixer und häufige Probleme beim Einsatz in NENE2-basierten Projekten.

---

## PHPStan

NENE2 zielt auf PHPStan-Level 8 ab. Consumer-Projekte, die `hideyukimori/nene2` über Composer installieren, können PHPStan für ihre eigenen Quellen in einer `phpstan.neon`-Datei konfigurieren.

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

PHPStan kann bei der Analyse eines Projekts, das viele Vendor-Pakete importiert, zu wenig Speicher bekommen (nene2 + psr/* + nyholm/psr7 + phpunit-Stubs summieren sich schnell). Das Standard-PHP-Speicherlimit von 128 MB ist oft zu niedrig.

**In PHPStan 2.x ist `memory_limit` eine reine CLI-Option — sie kann nicht in `phpstan.neon` gesetzt werden.**

Übergeben Sie sie stattdessen auf der Kommandozeile:

```bash
phpstan analyse --level=8 --memory-limit=512M src tests
```

Oder tragen Sie sie im `scripts`-Abschnitt von `composer.json` ein, damit sie konsistent angewendet wird:

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
> Verwenden Sie stattdessen das CLI-Flag.

### Falsch-Positive unterdrücken

Wenn PHPStan einen falsch-positiven Befund ausgibt, der nicht behoben werden kann (z. B. eine PHPDoc-Nichtübereinstimmung in einer Vendor-Klasse), verwenden Sie eine Inline-Unterdrückung:

```php
/** @phpstan-ignore-next-line */
$value = $externalLib->getSomething();
```

Oder erstellen Sie eine Baseline-Datei:

```bash
phpstan analyse --generate-baseline
```

---

## PHP-CS-Fixer

NENE2 verwendet `@PSR12` als Basis-Regelwerk, ergänzt durch `strict_param` und `declare_strict_types`.

### Minimale Konfiguration

Erstellen Sie `.php-cs-fixer.php` im Projektstamm:

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

**Erweiterung des Konstruktorrumpfs**: `@PSR12` erfordert, dass Konstruktorrümpfe mehrere Zeilen umfassen, selbst wenn sie leer sind. CS-Fixer erweitert automatisch:

```php
// Vorher (handgeschrieben)
public function __construct(private Foo $foo) {}

// Nachher (CS-Fixer-Korrektur)
public function __construct(private Foo $foo)
{
}
```

Führen Sie `composer cs:fix` nach dem Schreiben neuer Klassen aus, um manuelle Korrekturen zu vermeiden.

**Platzierung von `declare(strict_types=1)`**: Die `declare_strict_types`-Regel fügt diese Deklaration automatisch hinzu. Wenn Sie sie manuell schreiben, normalisiert CS-Fixer die Leerzeichen darum.

### Dry-run vs. Korrektur

```bash
composer cs        # Dry-run, zeigt was geändert werden würde
composer cs:fix    # Korrekturen anwenden
```

---

## Checks kombinieren

Fügen Sie ein `check`-Composer-Skript hinzu, das alle Werkzeuge nacheinander ausführt:

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

Führen Sie `composer check` vor jedem Commit aus, um Probleme frühzeitig zu erkennen. Die CI sollte denselben Befehl ausführen.
