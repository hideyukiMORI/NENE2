# Guide d'installation locale

Ce guide explique comment configurer NENE2 localement, depuis un clone vierge jusqu'à une API fonctionnelle.

## Prérequis

- [Docker Desktop](https://www.docker.com/products/docker-desktop/) (ou Docker Engine + plugin Compose)
- Git

Aucune installation de PHP, Node.js ou MySQL sur l'hôte n'est requise. Toutes les dépendances d'exécution fonctionnent dans Docker.

## 1. Cloner et configurer

```bash
git clone https://github.com/hideyukiMORI/NENE2.git
cd NENE2
cp .env.example .env
```

Ouvrez `.env` et ajustez les valeurs si nécessaire. Les valeurs par défaut fonctionnent pour le développement local sans modifications.

Variables d'environnement principales :

| Variable | Défaut | Utilité |
|---|---|---|
| `APP_ENV` | `local` | Environnement d'exécution |
| `NENE2_MACHINE_API_KEY` | *(vide)* | Laisser vide pour désactiver l'auth machine-client en développement local |
| `DB_ADAPTER` | `mysql` | `sqlite` ou `mysql` |
| `DB_HOST` | `mysql` | Correspond au nom du service Docker Compose |

## 2. Construire et installer

```bash
docker compose build
docker compose run --rm app composer install
```

## 3. Lancer les vérifications backend

```bash
docker compose run --rm app composer check
```

Cette commande exécute PHPUnit, PHPStan, PHP-CS-Fixer, la validation OpenAPI et la validation du catalogue MCP en séquence. Tout doit passer sur un clone propre.

## 4. Démarrer le serveur web

```bash
docker compose up -d app
```

Vérifier qu'il fonctionne :

```bash
curl -i http://localhost:8080/health
```

Réponse attendue :

```json
{"status":"ok","service":"NENE2"}
```

Autres endpoints locaux utiles :

| URL | Description |
|---|---|
| `http://localhost:8080/` | Informations sur le framework |
| `http://localhost:8080/health` | Vérification de santé |
| `http://localhost:8080/examples/ping` | Exemple ping |
| `http://localhost:8080/examples/notes/{id}` | Note par ID (nécessite une DB) |
| `http://localhost:8080/openapi.php` | JSON OpenAPI brut |
| `http://localhost:8080/docs/` | Interface Swagger |

## 5. Arrêter le serveur

```bash
docker compose down
```

## Optionnel : Configuration de la base de données MySQL

La suite de tests par défaut utilise SQLite en mémoire. Pour vérifier l'adaptateur MySQL ou exécuter des tests de fumée sur les opérations d'écriture :

```bash
docker compose up -d mysql
docker compose run --rm app composer migrations:migrate
docker compose run --rm app composer test:database:mysql
```

## Optionnel : Authentification machine-client

L'endpoint `/machine/health` nécessite une clé API. Pour le tester localement :

1. Définir `NENE2_MACHINE_API_KEY=local-dev-key` dans `.env`.
2. Redémarrer le service app : `docker compose up -d app`
3. Appeler l'endpoint protégé :

```bash
curl -i -H 'X-NENE2-API-Key: local-dev-key' http://localhost:8080/machine/health
```

## Optionnel : Configuration du frontend

```bash
npm install --prefix frontend
npm run dev --prefix frontend
```

## Optionnel : Serveur MCP local

```bash
docker compose run --rm -e NENE2_LOCAL_API_BASE_URL=http://app app php tools/local-mcp-server.php
```

## Optionnel : Vérifier le Request ID dans les logs

Chaque requête génère un `X-Request-Id` retourné dans l'en-tête de réponse et attaché à chaque enregistrement Monolog.

1. Démarrer l'app : `docker compose up -d app`
2. Envoyer une requête :
   ```bash
   curl -i http://localhost:8080/health
   # Chercher X-Request-Id dans les en-têtes de réponse
   ```
3. Observer la sortie de log structurée :
   ```bash
   docker compose logs app
   # Chaque ligne JSON inclut "extra":{"request_id":"<id>"}
   ```

Vous pouvez aussi fournir votre propre ID :
```bash
curl -i -H 'X-Request-Id: my-trace-id' http://localhost:8080/health
```

## Dépannage

**`composer check` échoue sur un clone propre**
Exécutez d'abord `docker compose run --rm app composer install`. Le répertoire `vendor/` n'est pas versionné.

**Le port 8080 est déjà utilisé**
Arrêtez ce qui l'utilise, ou changez la correspondance de port dans `compose.yaml` :
```yaml
ports:
  - "8081:80"   # utiliser 8081 à la place
```

**Connexion MySQL refusée pendant les migrations**
Le conteneur `mysql` prend quelques secondes pour être prêt. Attendez un moment et réessayez.
