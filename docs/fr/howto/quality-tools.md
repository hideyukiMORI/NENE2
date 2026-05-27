# Outils de qualité

Ce guide couvre PHPStan, PHP-CS-Fixer et les problèmes courants lors de leur exécution dans des projets basés sur NENE2.

---

## PHPStan

NENE2 cible le niveau 8 de PHPStan. Les projets consommateurs qui installent `hideyukimori/nene2` via Composer peuvent configurer PHPStan pour leur propre source dans un fichier `phpstan.neon`.

### Configuration minimale

```neon
# phpstan.neon
parameters:
    level: 8
    paths:
        - src
        - tests
```

### Limite mémoire

PHPStan peut manquer de mémoire lors de l'analyse d'un projet qui importe de nombreux packages vendor (nene2 + psr/* + nyholm/psr7 + les stubs phpunit s'accumulent rapidement). La limite mémoire PHP par défaut est de 128 Mo, ce qui est souvent trop bas.

**Dans PHPStan 2.x, `memory_limit` est une option CLI uniquement — elle ne peut pas être définie dans `phpstan.neon`.**

Passez-la sur la ligne de commande à la place :

```bash
phpstan analyse --level=8 --memory-limit=512M src tests
```

Ou définissez-la dans la section `scripts` de `composer.json` pour qu'elle s'applique de manière cohérente :

```json
{
    "scripts": {
        "analyse": "phpstan analyse --level=8 --memory-limit=512M src tests",
        "check": ["@test", "@analyse", "@cs"]
    }
}
```

> **Note** : Ajouter `memory_limit: 512M` sous `parameters:` dans `phpstan.neon` provoque
> `Invalid configuration: Unexpected item 'parameters › memory_limit'.` dans PHPStan 2.x.
> Utilisez le flag CLI à la place.

### Supprimer les faux positifs

Si PHPStan émet un faux positif qui ne peut pas être corrigé (ex. une incompatibilité PHPDoc dans une classe vendor), utilisez une ignorance en ligne :

```php
/** @phpstan-ignore-next-line */
$value = $externalLib->getSomething();
```

Ou ajoutez un fichier de baseline :

```bash
phpstan analyse --generate-baseline
```

---

## PHP-CS-Fixer

NENE2 utilise `@PSR12` comme jeu de règles de base, plus `strict_param` et `declare_strict_types`.

### Configuration minimale

Créez `.php-cs-fixer.php` à la racine du projet :

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

### Problèmes de formatage courants

**Expansion du corps de constructeur** : `@PSR12` requiert que les corps de constructeur s'étendent sur plusieurs lignes même quand ils sont vides. CS-Fixer développe automatiquement :

```php
// Avant (écrit à la main)
public function __construct(private Foo $foo) {}

// Après la correction CS-Fixer
public function __construct(private Foo $foo)
{
}
```

Exécutez `composer cs:fix` après avoir écrit de nouvelles classes pour éviter les corrections manuelles.

**Placement de `declare(strict_types=1)`** : La règle `declare_strict_types` ajoute cette déclaration automatiquement. Si vous l'écrivez manuellement, CS-Fixer normalisera les espaces autour d'elle.

### Dry-run vs correction

```bash
composer cs        # dry-run, montre ce qui changerait
composer cs:fix    # applique les corrections
```

---

## Combiner les vérifications

Ajoutez un script composer `check` qui exécute tous les outils en séquence :

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

Exécutez `composer check` avant chaque commit pour détecter les problèmes tôt. La CI devrait exécuter la même commande.
