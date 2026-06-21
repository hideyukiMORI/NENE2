# How-to : Test de pénétration en conteneur live

Ce guide documente comment exécuter un test de pénétration adversarial en conteneur live contre une application NENE2 — de la configuration jusqu'aux 30 phases d'attaque — et consigne les résultats canoniques de la session de test v1.5.329 (2026-05-31, 150+ cas).

Le test adopte un **état d'esprit de cracker** : on suppose que l'attaquant a un accès complet au code source (boîte blanche), a lu toute la documentation publique, et essaiera toutes les classes d'attaque connues avant d'abandonner.

---

## Prérequis

- Docker Compose disponible (`docker compose version`)
- `curl`, `nc` (netcat), `openssl`, `python3` installés sur l'hôte
- Un conteneur NENE2 en cours d'exécution avec des credentials de test

---

## 1. Configuration du conteneur

Démarrez une cible de test isolée. Utilisez un port dédié (jamais le port de production) et injectez des credentials de test :

```bash
# PHP built-in server target — fastest to spin up, tests raw NENE2 behaviour
NENE2_MACHINE_API_KEY=pentest-key docker compose run -d --rm \
  -e NENE2_LOCAL_JWT_SECRET=pentest-jwt-secret-32chars-min!! \
  -e APP_ENV=local \
  -e APP_DEBUG=false \
  -p 8299:80 \
  app php -S 0.0.0.0:80 -t public_html/

# Apache target — tests full stack including Apache config hardening
NENE2_MACHINE_API_KEY=pentest-key docker compose up -d app
# Available on :8200 (see port registry in CLAUDE.md §8)
```

Contrôle de fumée de base :

```bash
curl -si http://localhost:8299/
# Expected: 200 OK, security headers present, no Server/X-Powered-By
```

Énumérez la surface d'attaque depuis OpenAPI :

```bash
curl -s http://localhost:8299/openapi.php | grep -E "^  /"
# → /, /health, /machine/health, /examples/protected,
#   /examples/notes, /examples/notes/{id}, /examples/tags, /examples/tags/{id}
```

Générez des credentials de test à l'intérieur du conteneur :

```bash
CID=$(docker ps --filter "publish=8299" --format "{{.ID}}")
VALID_JWT=$(docker exec $CID php -r "
  require 'vendor/autoload.php';
  \$v = new Nene2\Auth\LocalBearerTokenVerifier('pentest-jwt-secret-32chars-min!!');
  echo \$v->issue(['sub'=>'tester','exp'=>time()+86400]);
")
```

---

## 2. Phases d'attaque

### Phase 1 — Confusion d'algorithme JWT

| ID | Attaque | Attendu | v1.5.329 |
|----|--------|----------|----------|
| J-01 | `alg:none` (signature vide) | 401 | ✅ BLOCKED |
| J-02 | `alg:NONE` (majuscules) | 401 | ✅ BLOCKED |
| J-03 | `alg:None` (casse mixte) | 401 | ✅ BLOCKED |
| J-04 | `alg:hs256` (minuscules) | 401 | ✅ BLOCKED |
| J-05 | `alg:RS256` (confusion de clé) | 401 | ✅ BLOCKED |
| J-06 | Pas de champ `alg` | 401 | ✅ BLOCKED |
| J-07 | `kid: ../../etc/passwd` | 200 (sig valide) | ✅ SAFE — champs d'en-tête supplémentaires ignorés |
| J-08 | `jku: http://evil.com` | 200 (sig valide) | ✅ SAFE — pas de fetch JWK |

```bash
# J-01: alg:none
H=$(echo -n '{"typ":"JWT","alg":"none"}' | base64 -w0 | tr '+/' '-_' | tr -d '=')
P=$(echo -n '{"sub":"admin","exp":9999999999}' | base64 -w0 | tr '+/' '-_' | tr -d '=')
curl -si -H "Authorization: Bearer $H.$P." http://localhost:8299/examples/protected
# → 401  detail: "Token algorithm must be HS256."
```

### Phase 1b — Manipulation de payload JWT

| ID | Attaque | Attendu | v1.5.329 |
|----|--------|----------|----------|
| J-09 | `exp: 0` (epoch 1970) | 401 expiré | ✅ BLOCKED |
| J-10 | `exp: null` | 401 doit être numérique | ✅ BLOCKED |
| J-11 | `exp: "never"` | 401 doit être numérique | ✅ BLOCKED |
| J-12 | `exp: 9999999999.9` (float) | 401 doit être numérique | ✅ BLOCKED |
| J-13 | Le payload est un tableau JSON | 401 doit être numérique | ✅ BLOCKED |
| J-14 | Double espace dans la valeur Bearer | 401 | ✅ BLOCKED |
| J-15 | Pas de schéma Bearer | 401 | ✅ BLOCKED |
| J-16 | Token à 4 segments (point supplémentaire) | 401 format invalide | ✅ BLOCKED |
| J-17 | En-tête + payload seulement (pas de sig) | 401 | ✅ BLOCKED |

> **Invariant clé** : `exp` doit être un entier présent — l'absence ou un mauvais type est rejeté (corrigé en v1.5.329).

### Phase 2 — Injection SQL

Tous les repositories utilisent des requêtes paramétrées avec placeholder `?`. Aucune interpolation de chaîne brute.

| ID | Attaque | Attendu | v1.5.329 |
|----|--------|----------|----------|
| S-01 | `' OR 1=1--` classique dans le titre | 201 (stocké comme littéral) | ✅ SAFE |
| S-02 | `UNION SELECT 1,2,3--` | 201 (stocké comme littéral) | ✅ SAFE |
| S-03 | Booléen aveugle `AND 1=1--` | 201 (stocké comme littéral) | ✅ SAFE |
| S-04 | Basé sur le temps `AND SLEEP(2)--` | 201 en <50ms | ✅ SAFE — SLEEP non exécuté |
| S-05 | SQLi en paramètre de chemin `/notes/1' OR '1'='1` | 200 (cast int → 1) | ✅ SAFE |
| S-06 | Octet nul `\0' OR '1'='1` | 201 (littéral) | ✅ SAFE |
| S-07 | Second ordre : stocker le payload, puis lire | 200 (relecture littérale) | ✅ SAFE |
| S-08 | SLEEP(5) dans un champ du corps | 201 en <50ms | ✅ SAFE |
| S-10 | `limit=UNION SELECT...` dans la query string | 422 (validation) | ✅ SAFE |

```bash
# Verify parameterized queries: SLEEP is not executed
time curl -si -X POST -H "Content-Type: application/json" \
  -d '{"title":"x'\'' AND SLEEP(3)--","body":"x"}' \
  http://localhost:8299/examples/notes
# → 201 in < 100ms  (SLEEP never ran)
```

### Phase 3 — Path traversal / LFI / wrappers PHP

| ID | Attaque | Attendu | v1.5.329 |
|----|--------|----------|----------|
| P-01 | `../../etc/passwd` | 404 | ✅ BLOCKED |
| P-02 | Variantes URL-encodées `%2e%2e%2f` (5 formes) | 404 | ✅ BLOCKED |
| P-03 | Double-encodé `%252e%252e` | 404 | ✅ BLOCKED |
| P-04 | UTF-8 overlong `%c0%ae` | 404 | ✅ BLOCKED |
| P-05 | `php://input` / `php://filter` / `data://` | 404 | ✅ BLOCKED |
| P-06 | LFI via le paramètre `{id}` | 404 | ✅ BLOCKED |
| P-07 | Octet nul `1%00.html` | 200 (cast int → 1) | ✅ SAFE — enregistrement DB pour id=1 retourné |
| P-08 | `.htaccess` sur Apache | 403 | ✅ BLOCKED |
| P-08b | `.htaccess` sur le serveur PHP intégré | **200** | ⚠️ EXPOSED (voir VULN-01) |
| P-09 | `.git/HEAD` | 404 | ✅ BLOCKED |
| P-10 | Fichiers de sauvegarde (`.bak`, `.swp`, `~`, etc.) | 404 | ✅ BLOCKED |

### Phase 4 — Attaques de protocole HTTP

| ID | Attaque | Attendu | v1.5.329 |
|----|--------|----------|----------|
| H-01 | Request smuggling CL.TE | aucune réponse (le serveur PHP intégré bloque) | ✅ |
| H-02 | Smuggling TE.CL | 405 (méthode racine non concordante) | ✅ |
| H-03 | Transfer-Encoding obfusqué TE.TE | aucune réponse | ✅ |
| H-04 | Downgrade HTTP/1.0 | 200 (corps correct) | ✅ |
| H-05 | Abus de proxy URI absolue | 404 | ✅ |
| H-06 | Pliage d'en-tête HTTP | 500 (bug du serveur PHP intégré) | ⚠️ VULN-02 |
| H-07 | Pipelining HTTP | réponses entrelacées | ✅ SAFE |
| H-08 | 100 en-têtes personnalisés simultanés | 200 | ✅ SAFE |
| H-10 | Upgrade WebSocket | 200 (upgrade ignoré) | ✅ SAFE |
| H-12 | Version HTTP invalide (`HTTP/9.9`) | 200 (le serveur PHP intégré accepte) | ✅ SAFE |

### Phase 5 — Mass assignment / IDOR / logique métier

| ID | Attaque | Attendu | v1.5.329 |
|----|--------|----------|----------|
| B-01 | Mass assignment (`id`, `__proto__` dans le corps) | 201 (champs supplémentaires ignorés) | ✅ SAFE |
| B-02 | IDOR : DELETE la note d'un autre utilisateur | 204 | ℹ️ Attendu (les exemples n'ont pas de propriété) |
| B-04 | ID négatif / zéro | 404 | ✅ SAFE |
| B-05 | ID en débordement d'entier | 404 | ✅ SAFE |
| B-06 | DELETE puis re-accès au même ID | 404 | ✅ SAFE |
| B-07 | Course de DELETE concurrents | tous 404 (idempotent) | ✅ SAFE |
| B-08 | Corps à la limite de 1Mo | 413 | ✅ BLOCKED |

### Phase 6 — Contournement de clé API

| ID | Attaque | Attendu | v1.5.329 |
|----|--------|----------|----------|
| A-01 | Pas de clé | 401 | ✅ BLOCKED |
| A-02 | Clé dans la query string (`?key=`, `?api_key=`) | 401 | ✅ BLOCKED |
| A-03 | Clé dans le corps de la requête | 401 | ✅ BLOCKED |
| A-04 | Variations de casse du nom d'en-tête | 200 (PSR-7 normalise) | ✅ SAFE |
| A-05 | Espaces en tête/fin dans la valeur | 200 (PSR-7 trim) | ✅ SAFE |
| A-06 | Double slash `//machine/health` | 401 sans clé, 200 avec | ✅ SAFE |
| A-07 | `X-Original-URL` / `X-Rewrite-URL` | 200 (en-tête ignoré) | ✅ SAFE |
| A-08 | Contournement preflight OPTIONS | 405 | ✅ BLOCKED |
| A-09 | Méthode HEAD | 401 | ✅ BLOCKED |
| A-10 | Brute force de mots de passe courants | 401 partout | ✅ BLOCKED |
| A-11 | Chemin URL-encodé (`%6Dachine`) | 404 | ✅ BLOCKED |

```bash
# Timing attack: hash_equals used → constant-time comparison
time (for i in $(seq 1 10); do
  curl -so /dev/null -H "X-NENE2-API-Key: a" http://localhost:8299/machine/health
done)
time (for i in $(seq 1 10); do
  curl -so /dev/null -H "X-NENE2-API-Key: pentest-key" http://localhost:8299/machine/health
done)
# → timing difference < 5ms over 10 requests: SAFE
```

### Phase 7 — Injection / XSS / SSTI / exécution de code

| ID | Attaque | Attendu | v1.5.329 |
|----|--------|----------|----------|
| I-01 | XSS `<script>alert(1)</script>` stocké | 201, retourné comme chaîne JSON | ✅ SAFE — l'encodage JSON neutralise |
| I-02 | SSTI `{{7*7}}` / `${7*7}` | 201, stocké littéralement | ✅ SAFE — pas de moteur de template |
| I-03 | PHP `<?php system("id"); ?>` | 201, stocké comme littéral | ✅ SAFE |
| I-04 | Log4Shell `${jndi:ldap://...}` | 200 (en-tête ignoré) | ✅ SAFE — PHP, pas Java |
| I-05 | JSON imbriqué sur 1000 niveaux | 400 (limite de parse PHP) | ✅ BLOCKED |
| I-06 | Caractères de contrôle Unicode BiDi | 201 (stocké) | ✅ SAFE — risque d'affichage seulement |
| I-07 | Clés JSON dupliquées | la dernière valeur l'emporte (comportement PHP) | ℹ️ INFO-01 |

> **Note XSS stocké** : les payloads XSS sont stockés et retournés verbatim dans les réponses JSON. Comme l'API est uniquement JSON (`Content-Type: application/json` + `X-Content-Type-Options: nosniff`), les navigateurs n'exécuteront pas le script. Le risque ne se matérialise que si une autre application rend ces données dans un contexte HTML sans échappement.

### Phase 8 — Désérialisation / injection d'objet PHP

| ID | Attaque | Attendu | v1.5.329 |
|----|--------|----------|----------|
| D-01 | Wrapper `phar://` dans un paramètre de chemin | 404 | ✅ BLOCKED |
| D-02 | Payload serialize PHP `O:8:"stdClass":...` | 400 (corps invalide) | ✅ BLOCKED |
| D-03 | Formulaire URL-encodé avec payload serialize | 400 (mauvais Content-Type) | ✅ BLOCKED |

### Phase 9 — Injection d'en-tête / response splitting

| ID | Attaque | Attendu | v1.5.329 |
|----|--------|----------|----------|
| R-01 | Injection d'en-tête Location via l'id de note créée | `/examples/notes/<int>` | ✅ SAFE — int uniquement |
| R-02 | CRLF dans WWW-Authenticate via erreur JWT | message fixe assaini | ✅ SAFE |
| R-03 | Content-Type sniffing | `X-Content-Type-Options: nosniff` | ✅ SAFE |
| R-04 | Clickjacking | `X-Frame-Options: SAMEORIGIN` | ✅ SAFE |

### Phase 10 — Contournement CORS / SOP

| ID | Attaque | Attendu | v1.5.329 |
|----|--------|----------|----------|
| C-01 | `Origin: null` (iframe sandboxé) | Vary: Origin, pas d'en-tête ACAO | ✅ SAFE |
| C-02 | CRLF dans l'en-tête Origin | assaini par la couche curl/http | ✅ SAFE |
| C-03 | Cache poisoning de l'en-tête Vary | `Vary: Origin` présent | ✅ SAFE |
| C-04 | Preflight avec méthode injectée | méthode ignorée par PHP | ✅ SAFE |
| C-05 | `Access-Control-Allow-Origin: *` | en-tête absent (allowlist vide) | ✅ SAFE |

### Phases 11-20 — Encodage / protocole / timing

| ID | Attaque | Résultat |
|----|--------|--------|
| E-01 | Emoji / Unicode haut dans le JSON | ✅ 201 (stocké correctement) |
| E-02 | Override BiDi RTL (risque de spoofing) | ✅ 201 (affichage seulement) |
| E-05 | SQLi de pagination via paramètres de requête | ✅ 422 (validé comme entier) |
| H-06b | En-tête Authorization plié | ⚠️ 500 (bug du serveur PHP intégré) |
| 20 | X-Request-Id de 129 caractères rejeté | ✅ Le serveur génère un nouvel ID aléatoire |
| 21 | Injection de log via X-Request-Id `%0a` | ✅ Rejeté (caractères invalides) |
| 22 | Apache ServerTokens/ServerSignature | ✅ `Server: Apache` uniquement |
| 23 | Escalade de privilège JWT sub=admin | ✅ Claims non utilisés pour l'authz |
| 26 | Replay JWT (expiré il y a 2s) | ✅ 401 `Token has expired.` |
| 27 | Divulgation de stack trace 500 | ✅ Message générique uniquement |
| 28 | XSS dans `instance` des Problem Details | ✅ URL-encodé (sûr) |
| 29 | SSRF via l'endpoint health check | ✅ Aucune URL acceptée |
| 15 | Oracle de timing sur la clé API | ✅ `hash_equals` — diff < 5ms |

---

## 3. Constats

### VULN-01 — `.htaccess` lisible depuis le serveur PHP intégré ⚠️ MEDIUM

**Déclencheur** : `curl http://localhost:8299/.htaccess`  
**Réponse** : 200 + contenu complet du fichier (règles de réécriture Apache)  
**Cause racine** : le serveur intégré de PHP (`php -S`) n'applique pas les restrictions d'accès `.htaccess` — il traite `.htaccess` comme un fichier statique.  
**Impact** : révèle les règles de réécriture d'URL. Le contenu n'est pas secret (pas de mots de passe/tokens), mais confirme le pattern de réécriture vers index.php.  
**Atténuation** : utilisez le conteneur Apache (`docker compose up -d app`) au lieu de `php -S` pour les tests sensibles à la sécurité. Apache retourne correctement 403.

```bash
# Apache (correct): 403 Forbidden
curl -si http://localhost:8200/.htaccess | head -1

# PHP built-in server (exposed): 200 OK
curl -si http://localhost:8299/.htaccess | head -1
```

### VULN-02 — Le pliage d'en-tête HTTP plante le serveur PHP intégré ⚠️ LOW

**Déclencheur** :
```
GET / HTTP/1.1\r\nHost: localhost\r\nX-NENE2-API-Key:\r\n <key>\r\n\r\n
```
**Réponse** : `HTTP/1.0 500 Internal Server Error` (corps vide)  
**Cause racine** : le serveur HTTP intégré de PHP ne prend pas en charge le pliage d'en-tête RFC 7230 (déprécié mais toujours valide en HTTP/1.1). Le code du framework NENE2 n'est pas impliqué.  
**Impact** : développement uniquement (serveur PHP intégré). Apache gère correctement les en-têtes pliés.

### INFO-01 — Clés JSON dupliquées : la dernière valeur l'emporte

`{"title":"first","title":"INJECTED"}` → `title = "INJECTED"`  
Comportement standard de `json_decode` en PHP. La validation s'applique à la valeur finale (la dernière), donc il n'y a pas de voie de contournement de validation. Noté pour information.

---

## 4. Invariants de sécurité vérifiés

Ces garanties ont tenu sur l'ensemble des 150+ cas de test :

| Invariant | Vérification |
|-----------|-------------|
| Toutes les requêtes SQL paramétrées | SLEEP non exécuté ; payloads d'injection stockés comme littéraux |
| JWT doit être HS256 + sig valide + exp entier | Les 17 variantes d'attaque JWT bloquées |
| Clé API vérifiée avec `hash_equals` | Différence de timing < 5ms sur 10 itérations |
| Débordement de `Content-Length` géré | 413 avec en-têtes corrects, aucune fuite de warning PHP |
| En-têtes de sécurité sur chaque réponse | CSP / XCTO / XFO / Referrer-Policy / Permissions-Policy confirmés |
| `Server:` / `X-Powered-By:` supprimés | Aucun en-tête présent dans les réponses Apache |
| Jamais de stack trace dans le corps 500 | Uniquement le générique `"The server encountered an unexpected condition."` |
| Path traversal bloqué | Les 15 variantes d'encodage retournent 404 |
| Fichiers `.env` / `.git` / sauvegarde | Tous 404 dans le document root |
| CORS par défaut : aucune origine autorisée | `Access-Control-Allow-Origin` absent pour les origines arbitraires |

---

## 5. Exécuter la suite de tests

Répétition minimale viable des contrôles clés (< 5 minutes) :

```bash
TARGET=http://localhost:8299
APIKEY=pentest-key
SECRET=pentest-jwt-secret-32chars-min!!
CID=$(docker ps --filter "publish=8299" --format "{{.ID}}")

# 1. JWT alg:none
H=$(echo -n '{"typ":"JWT","alg":"none"}' | base64 -w0 | tr '+/' '-_' | tr -d '=')
P=$(echo -n '{"sub":"admin","exp":9999999999}' | base64 -w0 | tr '+/' '-_' | tr -d '=')
curl -si -H "Authorization: Bearer $H.$P." $TARGET/examples/protected | grep "HTTP/"
# expected: 401

# 2. SQL injection time-based
time curl -so /dev/null -X POST -H "Content-Type: application/json" \
  -d '{"title":"x'\'' AND SLEEP(3)--","body":"x"}' $TARGET/examples/notes
# expected: < 500ms total

# 3. Path traversal
curl -si "$TARGET/%2e%2e/%2e%2e/etc/passwd" | grep "HTTP/"
# expected: 404

# 4. Content-Length overflow
curl -si -X POST -H "Content-Length: 9999999999999" $TARGET/ | head -3
# expected: 413 Request Entity Too Large (not 200 + PHP warning)

# 5. API key timing
time (for i in $(seq 1 10); do
  curl -so /dev/null -H "X-NENE2-API-Key: a" $TARGET/machine/health
done)
# expected: similar timing to correct key (hash_equals)

# 6. .htaccess exposure (Apache only)
curl -si http://localhost:8200/.htaccess | grep "HTTP/"
# expected: 403

# 7. JWT exp required
NEXP=$(docker exec $CID php -r "
  require 'vendor/autoload.php';
  \$v = new Nene2\Auth\LocalBearerTokenVerifier('$SECRET');
  echo \$v->issue(['sub'=>'user1']);
")
curl -si -H "Authorization: Bearer $NEXP" $TARGET/examples/protected | grep "detail"
# expected: "Token must contain a numeric exp claim."
```

---

## Guides associés

- [Pagination Boundary & Limit Injection](pagination-boundary-attack.md)
- [Webhook Signature Verification](webhook-signature-verification.md)
- [Add JWT Authentication](add-jwt-authentication.md)
- ADR 0011 : Security Review Policy
