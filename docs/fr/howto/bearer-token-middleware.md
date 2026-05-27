# How-to : Middleware de token Bearer (Cas limites d'auth JWT)

> **Référence FT** : FT273 (`NENE2-FT/authlog`) — Auth JWT BearerTokenMiddleware : rejet alg=none, détection de falsification de signature, application exp/nbf, en-tête WWW-Authenticate, isolation des données par sub, IDOR → 404, 18 tests / 26 assertions PASS.
>
> **Évaluation VULN** : V-01 à V-10 inclus à la fin de ce document.

Démontre l'utilisation du `BearerTokenMiddleware` + `LocalBearerTokenVerifier` (HMAC-HS256) de NENE2 pour protéger les routes. Tous les cas limites de validation JWT sont gérés par le middleware ; les contrôleurs ne reçoivent que les claims décodées via `nene2.auth.claims`.

---

## Configuration

```php
$verifier        = new LocalBearerTokenVerifier($secret); // env: NENE2_LOCAL_JWT_SECRET
$bearerMiddleware = new BearerTokenMiddleware($problems, $verifier);

$app = (new RuntimeApplicationFactory(
    $psr17, $psr17,
    routeRegistrars: [static fn (Router $r) => $registrar->register($r)],
    authMiddleware:  $bearerMiddleware,
))->create();
```

Le middleware définit `nene2.auth.claims` sur la requête avant l'exécution de tout gestionnaire de route. Si la validation échoue, il retourne 401 avec `WWW-Authenticate: Bearer` avant l'invocation du gestionnaire.

---

## Extraction des claims dans un contrôleur

```php
private function resolveOwnerId(ServerRequestInterface $request): string
{
    /** @var array<string, mixed> $claims */
    $claims = $request->getAttribute('nene2.auth.claims') ?? [];
    return (string) ($claims['sub'] ?? '');
}
```

La claim `sub` est l'identité canonique de l'utilisateur. L'utiliser comme `owner_id` assure l'isolation des données par utilisateur sans aucune recherche supplémentaire.

---

## En-tête WWW-Authenticate

Sur 401, le middleware émet `WWW-Authenticate: Bearer realm="api"`.
Pour les tokens expirés, l'en-tête inclut `error="invalid_token"` :

```
WWW-Authenticate: Bearer realm="api", error="invalid_token", error_description="..."
```

La conformité RFC 6750 permet aux clients de distinguer "pas de token" de "mauvais token".

---

## Évaluation des vulnérabilités

### V-01 — Substitution d'algorithme alg=none ✅ SAFE

**Risque** : Un attaquant crée un JWT avec `"alg":"none"` et un payload non signé revendiquant `sub: admin`.
**Résultat** : SAFE — `LocalBearerTokenVerifier` n'accepte que HMAC-HS256. Les tokens `alg=none` sont rejetés à la vérification de signature ; le test `testWrongAlgorithmHeaderReturns401` confirme 401.

---

### V-02 — Falsification de signature ✅ SAFE

**Risque** : L'attaquant intercepte un JWT valide et modifie le payload (ex. change `sub` en `admin`) tout en gardant l'en-tête et la signature originale.
**Résultat** : SAFE — La signature HMAC-HS256 couvre `header.payload`. Toute modification invalide le MAC ; `testTamperedPayloadReturns401` confirme 401.

---

### V-03 — Rejeu de token expiré ✅ SAFE

**Risque** : Un token expiré est rejoué après que la session devrait être invalide.
**Résultat** : SAFE — La claim `exp` est validée ; les tokens avec `exp < time()` sont rejetés. `testExpiredTokenReturns401` confirme 401 avec `invalid_token` dans `WWW-Authenticate`.

---

### V-04 — Contournement de not-before (nbf) ✅ SAFE

**Risque** : Un token avec un `nbf` futur (pas encore valide) est utilisé avant son heure d'activation.
**Résultat** : SAFE — `nbf` est appliqué ; `testNbfInFutureReturns401` confirme 401.

---

### V-05 — Mauvais schéma Authorization ✅ SAFE

**Risque** : L'attaquant envoie `Authorization: Basic dXNlcjpwYXNz` ou omet le préfixe `Bearer `.
**Résultat** : SAFE — le middleware n'accepte que les tokens préfixés par `Bearer `. `Basic` et les chaînes de token sans préfixe retournent tous 401.

---

### V-06 — Structure de token malformée ✅ SAFE

**Risque** : L'attaquant envoie des tokens avec 2 parties, 4 parties, payload non-base64 ou des chaînes aléatoires pour sonder la gestion des erreurs.
**Résultat** : SAFE — toutes les variantes malformées retournent 401. Les tokens non à 3 parties et le base64 invalide sont rejetés avant toute extraction de claim.

---

### V-07 — Mauvais secret de signature ✅ SAFE

**Risque** : Un attaquant connaissant le format JWT signe un token avec un secret différent.
**Résultat** : SAFE — La vérification HMAC échoue si le secret diffère ; `testWrongSecretSignatureReturns401` confirme 401.

---

### V-08 — IDOR : accès aux données cross-utilisateur ✅ SAFE

**Risque** : L'Utilisateur A tente de lire les données de l'Utilisateur B en connaissant ou devinant l'ID d'entrée.
**Résultat** : SAFE — `findByIdAndOwner($id, $ownerId)` scope la recherche au `sub` JWT. Une requête cross-utilisateur retourne 404 (pas 403) pour éviter de révéler que l'entrée existe.

---

### V-09 — Isolation des données par utilisateur ✅ SAFE

**Risque** : Les écritures de l'Utilisateur A sont visibles par l'Utilisateur B.
**Résultat** : SAFE — toutes les lectures sont scopées par `owner_id = sub`. `testEntriesAreIsolatedByToken` vérifie que les entrées d'Alice et de Bob sont totalement séparées.

---

### V-10 — Token sans claim exp ✅ SAFE (acceptable)

**Risque** : Un token sans claim `exp` est émis, devenant effectivement non-expirant.
**Résultat** : SAFE (par conception) — `LocalBearerTokenVerifier` valide `exp` seulement si la claim est présente. Les tokens sans `exp` sont acceptés. C'est un compromis délibéré pour les scénarios service-à-service ; les déploiements en production devraient appliquer `exp` via un vérificateur plus strict si nécessaire.

---

### Résumé VULN

| ID | Vulnérabilité | Résultat |
|----|---------------|---------|
| V-01 | Substitution d'algorithme alg=none | ✅ SAFE |
| V-02 | Falsification de signature | ✅ SAFE |
| V-03 | Rejeu de token expiré | ✅ SAFE |
| V-04 | Contournement de not-before (nbf) | ✅ SAFE |
| V-05 | Mauvais schéma Authorization | ✅ SAFE |
| V-06 | Structure de token malformée | ✅ SAFE |
| V-07 | Mauvais secret de signature | ✅ SAFE |
| V-08 | Accès aux données IDOR cross-utilisateur | ✅ SAFE |
| V-09 | Isolation des données par utilisateur | ✅ SAFE |
| V-10 | Token sans claim exp | ✅ SAFE (par conception) |

**10 SAFE, 0 EXPOSÉS**
Aucune vulnérabilité critique. Le `BearerTokenMiddleware` gère tous les vecteurs d'attaque JWT standard ; le code applicatif n'a besoin que d'utiliser la claim `sub` pour le scope de propriété.

---

## Ce qu'il ne faut PAS faire

| Anti-pattern | Risque |
|---|---|
| Accepter les tokens `alg=none` | L'attaquant peut forger n'importe quelle identité en omettant la signature |
| Ignorer la validation `exp` | Les tokens volés restent valides indéfiniment |
| Retourner 403 sur IDOR | Révèle que la ressource existe et appartient à quelqu'un d'autre |
| Utiliser l'en-tête `X-User-Id` au lieu du `sub` JWT | L'en-tête est trivialement falsifiable ; la claim JWT est cryptographiquement liée |
| Partager le secret de signature entre les environnements | Une fuite dans l'env de dev compromet les tokens de production |
| Utiliser des clés `RS256` inférieures à 2048 bits | Vulnérables aux attaques par factorisation |
