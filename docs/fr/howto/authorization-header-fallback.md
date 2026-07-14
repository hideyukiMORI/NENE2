# Rétablir l'auth Bearer derrière les proxys qui suppriment `Authorization`

Certains proxys frontaux d'hébergement mutualisé suppriment l'en-tête standard
`Authorization` avant que la requête n'atteigne PHP (observé en production sur un
hébergement de classe HETEML). Les en-têtes personnalisés passent, `Authorization` non —
les astuces de récupération habituelles échouent donc aussi :

- `.htaccess` `RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]` — inutile,
  Apache ne voit jamais l'en-tête ;
- `CGIPassAuth on` — pour la même raison.

Résultat : chaque endpoint protégé par Bearer répond 401 `missing_token` alors que le
navigateur a envoyé un jeton parfaitement valide.

NENE2 fournit un correctif standard en deux volets (voir ADR 0019) :

1. **Frontend** : `@hideyukimori/nene2-client` (≥ 1.1.0) duplique le jeton dans
   `X-Authorization: Bearer <token>` sur chaque requête, en plus de l'en-tête standard.
2. **Backend** : `Nene2\Middleware\AuthorizationHeaderFallbackMiddleware` adopte le miroir
   **uniquement quand `Authorization` est absent ou vide**. Les hôtes qui transmettent
   l'en-tête standard restent inchangés octet pour octet.

---

## L'activer dans le pipeline par défaut

Un seul drapeau opt-in sur `RuntimeApplicationFactory` :

```php
$app = (new RuntimeApplicationFactory(
    $psr17, $psr17,
    routeRegistrars: [/* ... */],
    authMiddleware:  $bearerMiddleware,
    enableAuthorizationHeaderFallback: true, // désactivé par défaut
))->create();
```

Une fois activé, le fallback s'exécute au début de l'étape d'authentification — avant la
vérification de la clé API machine et avant tout middleware d'authentification injecté —
de sorte que chaque middleware lisant des identifiants voit l'en-tête restauré. Il est
indépendant de la méthode et du chemin.

## Ou le câbler manuellement

Dans un pipeline assemblé à la main, placez-le n'importe où avant votre middleware
d'authentification :

```php
$stack = [
    // ... request id, journalisation, en-têtes de sécurité, CORS, gestion d'erreurs ...
    new AuthorizationHeaderFallbackMiddleware(),
    $bearerMiddleware,
];
```

Hors d'un pipeline PSR-15, la transformation est disponible via un helper statique :

```php
$request = AuthorizationHeaderFallbackMiddleware::apply($request);
```

---

## Quand il ne faut PAS l'activer

Activer le fallback rend `X-Authorization` équivalent à `Authorization` en tant
qu'identifiant. C'est exactement ce qu'il faut sur les hôtes qui suppriment l'en-tête
*accidentellement* — et exactement l'inverse quand un amont le supprime *délibérément* :

- une passerelle qui effectue elle-même l'authentification et transmet une identité de
  confiance ;
- un WAF qui filtre les identifiants entrants des clients non fiables.

Dans ces configurations, le miroir deviendrait un contournement contrôlé par le client.
Laissez le drapeau désactivé, ou faites en sorte que l'amont supprime aussi
`X-Authorization`.

Traitez également `X-Authorization` avec la même confidentialité que `Authorization` dans
les journaux d'accès et les proxys intermédiaires.

## Remarques

- Le nom de l'en-tête est **fixe** (`AuthorizationHeaderFallbackMiddleware::FALLBACK_HEADER`,
  `X-Authorization`). C'est un contrat de câblage à l'échelle de la flotte avec le client
  frontend, pas un réglage.
- La valeur du miroir est adoptée telle quelle (`Bearer <token>` inclus). La validation du
  jeton reste entièrement à la charge de votre middleware d'authentification — un miroir
  invalide échoue exactement comme un en-tête standard invalide.
- La précédence est toujours : un `Authorization` non vide gagne ; le miroir n'est
  consulté que si l'en-tête standard est absent ou vide.
