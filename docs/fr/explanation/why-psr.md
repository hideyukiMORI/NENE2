# Pourquoi les standards PSR ?

NENE2 est construit sur PSR-7, PSR-15 et PSR-17 plutôt que sur des abstractions HTTP personnalisées. Cette page explique le raisonnement.

## Ce que ces standards couvrent

| Standard | Ce qu'il définit |
|----------|-----------------|
| PSR-7 | `RequestInterface`, `ResponseInterface`, `StreamInterface` — la forme des messages HTTP |
| PSR-15 | `MiddlewareInterface`, `RequestHandlerInterface` — comment les middlewares et handlers se composent |
| PSR-17 | Interfaces de factory pour créer des objets PSR-7 |

## Pourquoi pas une abstraction personnalisée ?

Une classe `Request` personnalisée est rapide à écrire et facile à contrôler. Le coût apparaît plus tard :

- Chaque nouvelle bibliothèque HTTP nécessite un adaptateur personnalisé.
- Les middlewares écrits pour un projet ne peuvent pas être déplacés vers un autre.
- Les tests nécessitent soit un serveur HTTP en cours d'exécution, soit la classe personnalisée elle-même.

Les objets PSR-7 sont des value objects immuables. Un handler qui accepte `ServerRequestInterface` et retourne `ResponseInterface` ne fait aucune hypothèse sur le framework qui l'appelle.

## Pourquoi des messages immuables ?

Les messages PSR-7 sont immuables : `withHeader()`, `withBody()` et méthodes similaires retournent une nouvelle instance au lieu de muter l'existante. Cela élimine une classe de bugs où un middleware modifie silencieusement une requête qu'un handler ultérieur inspecte.

```php
// Chaque middleware reçoit une copie propre — l'originale est inchangée
$request = $request->withAttribute('request_id', $id);
```

## Pourquoi le middleware PSR-15 ?

PSR-15 définit le contrat middleware avec une seule méthode :

```php
public function process(
    ServerRequestInterface $request,
    RequestHandlerInterface $next
): ResponseInterface
```

Cela signifie :

- Tout middleware PSR-15 peut s'intégrer dans tout pipeline PSR-15.
- L'ordre du pipeline est du code explicite, pas un cycle de vie de framework caché.
- Tester un middleware unitairement nécessite seulement un mock `RequestHandlerInterface`, pas un serveur en cours d'exécution.

## Choix du package concret

NENE2 utilise **Nyholm PSR-7** pour les objets de message et **Relay** pour le dispatcher de middleware (voir ADR 0001). Ce sont des packages légers qui implémentent les standards sans ajouter d'API spécifiques au framework.

## Compromis

| Bénéfice | Coût |
|---------|------|
| Middleware interopérable | Plus verbeux qu'une API fluide personnalisée |
| Messages immuables réduisant les bugs | Création d'objets à chaque appel `with*` |
| Testable sans serveur | Nécessite de comprendre les interfaces PSR |
