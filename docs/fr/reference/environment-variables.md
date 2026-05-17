# Variables d'environnement

Toutes les variables d'environnement reconnues par NENE2.
Définissez-les dans `.env` (chargé par phpdotenv) ou exportez-les avant de démarrer le serveur.

## Application

| Variable | Type | Défaut | Description |
|---|---|---|---|
| `APP_ENV` | string | `local` | Environnement d'exécution. Valeurs acceptées : `local`, `test`, `production`. |
| `APP_DEBUG` | boolean | `false` | Active la sortie de débogage. Utilisez `true` uniquement en développement. |
| `APP_NAME` | string | `NENE2` | Nom de l'application utilisé dans les logs. Ne peut pas être vide. |

## Authentification

| Variable | Type | Défaut | Description |
|---|---|---|---|
| `NENE2_MACHINE_API_KEY` | string | *(vide — désactivé)* | Clé API attendue dans l'en-tête `X-NENE2-API-Key` pour les endpoints machine. Laissez vide pour désactiver. |
| `NENE2_LOCAL_JWT_SECRET` | string | *(vide — désactivé)* | Secret HMAC-HS256 pour protéger les outils d'écriture du serveur MCP local. Laissez vide pour un accès en lecture seule sans authentification. |

## Serveur MCP local

| Variable | Type | Défaut | Description |
|---|---|---|---|
| `NENE2_LOCAL_API_BASE_URL` | string | *(requis)* | URL de base utilisée par le serveur MCP pour proxifier les appels API (ex. `http://app`). Requis avec Docker Compose. |

## Base de données

| Variable | Type | Défaut | Description |
|---|---|---|---|
| `DATABASE_URL` | string | *(vide — utilise `DB_*`)* | URL de connexion complète. Si non vide, remplace toutes les variables `DB_*` individuelles. |
| `DB_ADAPTER` | string | `mysql` | Pilote de base de données. Accepté : `sqlite`, `mysql`. |
| `DB_HOST` | string | `127.0.0.1` | Hôte de la base de données. |
| `DB_PORT` | integer | `3306` | Port de la base de données (1–65535). |
| `DB_NAME` | string | `nene2` | Nom de la base de données. |
| `DB_USER` | string | `nene2` | Nom d'utilisateur de la base de données. |
| `DB_PASSWORD` | string | *(vide)* | Mot de passe de la base de données. |
| `DB_CHARSET` | string | `utf8mb4` | Jeu de caractères de la base de données. |

::: warning Ne jamais committer les secrets
Ne commitez pas les fichiers `.env` contenant des mots de passe, clés API ou secrets JWT.
:::
