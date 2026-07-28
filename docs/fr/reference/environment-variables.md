# Variables d'environnement

Toutes les variables d'environnement reconnues par NENE2.
Définissez-les dans `.env` (chargé par phpdotenv) ou exportez-les avant de démarrer le serveur.

## Application

| Variable | Type | Défaut | Description |
|---|---|---|---|
| `APP_ENV` | string | `local` | Environnement d'exécution. Valeurs acceptées : `local`, `test`, `production`. |
| `APP_DEBUG` | boolean | `false` | Active la sortie de débogage. Utilisez `true` uniquement en développement. |
| `APP_NAME` | string | `NENE2` | Nom de l'application utilisé dans les logs. Ne peut pas être vide. |
| `PROBLEM_DETAILS_BASE_URL` | string | `https://nene2.dev/problems/` | URL de base ajoutée aux identifiants `type` de Problem Details. À remplacer pour les types de problèmes personnalisés sur votre propre domaine. |

## Authentification

| Variable | Type | Défaut | Description |
|---|---|---|---|
| `NENE2_MACHINE_API_KEY` | string | *(vide — désactivé)* | Clé API attendue dans l'en-tête `X-NENE2-API-Key` pour les endpoints machine. Laissez vide pour désactiver. |
| `NENE2_LOCAL_JWT_SECRET` | string | *(vide — désactivé)* | Secret HMAC-HS256 utilisé par `LocalBearerTokenVerifier`. Active la validation du JWT Bearer pour `GET /examples/protected` et protège les outils d'écriture du serveur MCP local. Laissez vide pour désactiver l'authentification JWT et n'autoriser que l'accès MCP en lecture seule. Lorsqu'il est résolu via `Nene2\Auth\GuardedJwtSecretResolver`, une valeur vide échoue en fail-closed sauf si l'opt-in de secret de développement ci-dessous est défini (jamais en production). |
| `NENE2_ALLOW_DEV_SECRET` | boolean (strict) | `false` | Opt-in de secret de développement lu par `Nene2\Auth\GuardedJwtSecretResolver` (exposé via `AppConfig::$allowDevSecret`). Accepte **uniquement** `1`, `true` ou `yes` (insensible à la casse, espaces retirés) ; toute autre valeur — y compris une faute de frappe — vaut opt-out. Lorsque `NENE2_LOCAL_JWT_SECRET` n'est pas défini dans un environnement `local`/`test`, ceci autorise le secret de développement injecté par le produit. **Ignoré en production** — la production échoue toujours en fail-closed. Voir [ADR 0013](../adr/0013-guarded-jwt-secret-resolution.md). |

## Serveur MCP local

| Variable | Type | Défaut | Description |
|---|---|---|---|
| `NENE2_LOCAL_API_BASE_URL` | string | *(requis)* | URL de base utilisée par le serveur MCP pour proxifier les appels API (ex. `http://app`). Requis avec Docker Compose. |

## Base de données

| Variable | Type | Défaut | Description |
|---|---|---|---|
| `DATABASE_URL` | string | *(vide — utilise `DB_*`)* | URL de connexion complète. Si non vide, remplace toutes les variables `DB_*` individuelles. |
| `DB_ADAPTER` | string | `mysql` | Pilote de base de données. Accepté : `sqlite`, `mysql`, `pgsql` (expérimental — voir [Utiliser PostgreSQL](../howto/use-postgresql.md)). |
| `DB_HOST` | string | `127.0.0.1` | Hôte de la base de données. **Non utilisé par SQLite.** Dans Docker Compose, `compose.yaml` remplace cette valeur par `mysql` pour le service `app`. |
| `DB_PORT` | integer | `3306` | Port de la base de données (1–65535). **Non validé pour SQLite.** |
| `DB_NAME` | string | `nene2` | Nom de la base de données. Pour SQLite : chemin du fichier (ex. `/tmp/myapp.sqlite`). |
| `DB_USER` | string | `nene2` | Nom d'utilisateur de la base de données. **Non utilisé par SQLite.** |
| `DB_PASSWORD` | string | *(vide)* | Mot de passe de la base de données. |
| `DB_CHARSET` | string | `utf8mb4` | Jeu de caractères de la base de données. **Non utilisé par SQLite.** |
| `DB_ENV` | string | `local` | Nom de l'environnement de migration Phinx (voir `phinx.php`). |


### Adaptateur SQLite

Avec `DB_ADAPTER=sqlite`, seul `DB_NAME` (le chemin du fichier) est requis. `DB_HOST`, `DB_USER` et `DB_CHARSET` ne sont pas validés et n'ont pas besoin d'être définis.

```dotenv
DB_ADAPTER=sqlite
DB_NAME=/tmp/myapp.sqlite
```

Pour SQLite en mémoire (utile dans les tests), utilisez `DB_NAME=:memory:`.

::: warning Ne jamais committer les secrets
Ne commitez pas les fichiers `.env` contenant des mots de passe, clés API ou secrets JWT.
:::
