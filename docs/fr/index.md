---
layout: home

hero:
  name: "NENE2"
  text: "Framework PHP API Minimaliste"
  tagline: Construisez des API JSON rapidement. OpenAPI et MCP intégrés. Prêt pour l'IA dès le premier jour.
  actions:
    - theme: brand
      text: Commencer →
      link: /fr/tutorial/first-api
    - theme: alt
      text: Voir sur GitHub
      link: https://github.com/hideyukiMORI/NENE2
    - theme: alt
      text: Packagist
      link: https://packagist.org/packages/hideyukimori/nene2

features:
  - icon: 🚀
    title: Opérationnel en minutes
    details: Un simple composer require hideyukimori/nene2 et vous avez une API JSON fonctionnelle avec health checks, request IDs et erreurs Problem Details — avant même d'écrire une seule route.

  - icon: 📄
    title: OpenAPI en premier
    details: Chaque endpoint que vous créez est accompagné d'un contrat OpenAPI. Swagger UI est inclus. Le contrat est ce que vous remettez à votre client, pas une réflexion après coup.

  - icon: 🤖
    title: Prêt pour MCP
    details: Un serveur MCP local expose votre API en tant qu'outils que les agents IA (Claude, Cursor) peuvent appeler directement. Aucune intégration spéciale — il lit depuis votre catalogue OpenAPI.

  - icon: 🛡️
    title: Erreurs RFC 9457
    details: Chaque réponse d'erreur est un objet Problem Details — une structure JSON lisible par les machines avec type, title, status et detail. Pas d'exceptions brutes en production.

  - icon: 🧱
    title: Architecture propre
    details: UseCase → RepositoryInterface → adaptateur PDO. Chaque couche est testable isolément. Pas de magie, pas de câblage caché, pas de framework qui s'infiltre dans votre domaine.

  - icon: 🔬
    title: PHPStan niveau 8
    details: Analyse statique au niveau le plus strict. Si ça passe PHPStan, ça ne vous surprendra pas en production. Fonctionne avec PHPUnit et PHP-CS-Fixer dès l'installation.
---
