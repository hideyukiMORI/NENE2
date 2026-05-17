# Pourquoi le câblage explicite des dépendances ?

NENE2 utilise un câblage de dépendances explicite et écrit à la main plutôt que l'autowiring ou la magie de container basée sur des conventions. Cette page explique pourquoi.

## Ce que signifie le câblage explicite

```php
// RuntimeServiceProvider.php — chaque dépendance est écrite explicitement
$container->bind(NoteRepositoryInterface::class, function (ContainerInterface $c) {
    return new PdoNoteRepository($c->get(DatabaseQueryExecutorInterface::class));
});
```

## Les raisons du câblage explicite

### 1. Le câblage est toujours trouvable

Avec l'autowiring, répondre à « comment cette classe est-elle construite ? » nécessite de comprendre les règles de résolution du container. Avec le câblage explicite, `grep -r 'NoteRepository'` dans les fichiers de service provider donne la réponse complète.

### 2. Les erreurs échouent au démarrage, pas à l'exécution

Un binding explicite référençant une classe manquante échoue quand le container est construit. Une erreur d'autowiring peut n'apparaître que lorsqu'un chemin de code spécifique est exercé en production.

### 3. Les agents IA et l'analyse statique peuvent suivre le graphe

Le câblage explicite produit un graphe de dépendances que grep, PHPStan et les agents LLM peuvent parcourir sans exécuter le container.

### 4. Pas de couplage via annotations ou attributs

NENE2 évite les attributs `#[Inject]` ou les annotations docblock `@inject`. Les classes de domaine ne portent aucune annotation de container.

## Compromis

| Câblage explicite | Autowiring |
|-----------------|------------|
| Toujours lisible | Moins de boilerplate |
| Échec rapide au démarrage | Pratique pour le scaffolding rapide |
| Pas de magie | Nécessite d'apprendre les règles du container |
| Verbeux pour beaucoup de classes | S'adapte automatiquement |
