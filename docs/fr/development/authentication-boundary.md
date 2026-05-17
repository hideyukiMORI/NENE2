# Politique de frontière d'authentification

NENE2 traite l'authentification comme une frontière d'application explicite, et non comme de la magie de framework cachée.

Cette politique définit la première direction clé d'API et jeton pour les clients machine, les outils MCP et les futurs middlewares d'authentification.

## Position

L'authentification et l'autorisation sont des frontières de middleware explicites.

La direction par défaut est :

- Les clés API sont pour les clients machine et les outils MCP.
- Les jetons Bearer sont pour l'authentification utilisateur ou service lorsqu'une application les adopte.
- L'authentification par session appartient aux applications qui ont besoin de sessions navigateur côté serveur.
- Les schémas de sécurité OpenAPI ne doivent être ajoutés que lorsque le comportement middleware correspondant existe.
- Les secrets ne doivent jamais être commités, journalisés ou exposés via les métadonnées MCP.

Le premier chemin middleware implémenté est une vérification de clé API pour les endpoints machine-client utilisant :

```text
X-NENE2-API-Key
```

La valeur de la clé est chargée depuis `NENE2_MACHINE_API_KEY` lorsque configuré. Laissez-la non définie pour le développement local public uniquement, et définissez-la en dehors du dépôt lors du test des routes protégées.

## Clés API

Les clés API sont des credentials de longue durée pour les clients non-humains.

Utiliser les clés API pour :

- les outils MCP locaux qui appellent des API HTTP locales
- les outils d'inspection service-à-service
- les clients machine nécessitant un accès stable et délimité

Les clés API doivent avoir :

- un propriétaire
- un environnement
- une liste de portées
- une heure de création
- une heure de dernière utilisation lorsque le stockage existe
- un chemin de rotation ou révocation

Ne pas mettre de clés API brutes dans les exemples OpenAPI, les catalogues d'outils MCP, les logs, les captures d'écran ou la configuration versionnée.

## Jetons Bearer

Les jetons Bearer sont des credentials de requête envoyés dans l'en-tête `Authorization`.

Utiliser les jetons Bearer pour :

- les jetons utilisateur de courte durée
- les jetons de service avec des portées explicites
- les futurs flux OAuth ou jeton first-party

Les jetons Bearer doivent être traités comme des secrets même lorsqu'ils sont de courte durée.

Le framework ne doit pas prescrire un format de jeton avant qu'un adaptateur d'authentification existe.

## Portées

Les portées décrivent les capacités autorisées.

La nomenclature initiale des portées doit rester petite et lisible :

- `read:system`
- `read:health`
- `read:docs`
- `write:*` uniquement après la conception des outils d'écriture
- `admin:*` uniquement après la documentation de la politique admin

Les outils MCP doivent déclarer les portées minimales requises avant l'utilisation en production.

## Développement local

Le développement local peut utiliser des credentials fictifs uniquement lorsqu'ils sont clairement non secrets et documentés comme exemples.

Les outils locaux peuvent :

- appeler des endpoints HTTP locaux publics sans credentials
- utiliser des clés API de test générées en dehors du dépôt
- documenter les noms de variables d'environnement requises sans valeurs

Les outils locaux ne doivent pas :

- lire les valeurs `.env` via des outils MCP
- afficher des credentials dans la sortie de commande
- dépendre du stockage privé des credentials du développeur
- contourner l'authentification d'une manière qui ressemble au comportement de production

## Attentes de production

L'authentification de production nécessite une conception explicite avant l'implémentation.

Avant d'activer les credentials de production, documenter :

- le type de credential
- le propriétaire et le processus de rotation
- les environnements autorisés
- les portées requises
- le backend de stockage
- les champs d'audit
- le comportement en cas d'échec pour les credentials manquants, invalides, expirés ou insuffisants

Les échecs de validation des credentials doivent utiliser des réponses Problem Details et ne doivent pas révéler si une valeur secrète existe.

## Journalisation et observabilité

Les logs peuvent inclure :

- l'identifiant de requête
- le type de credential
- l'identifiant du propriétaire du credential lorsque c'est sûr
- les noms de portées
- le résultat de l'authentification
- la catégorie de raison d'échec

Les logs ne doivent pas inclure :

- les clés API brutes
- les jetons Bearer
- les cookies
- les en-têtes d'autorisation
- les hachages de credentials

## OpenAPI et MCP

Les schémas de sécurité OpenAPI doivent correspondre au middleware implémenté.

Lors de l'ajout, OpenAPI doit décrire :

- l'emplacement du credential
- les portées requises
- les réponses Problem Details `401` et `403`
- des exemples sans secrets réels

Les métadonnées MCP doivent référencer les portées requises, pas les credentials bruts.

Les outils MCP d'écriture, admin et destructifs nécessitent authentification, autorisation, audit, propagation du request id et comportement de confirmation avant implémentation.
