# Stratégie de test de base de données

Les tests d'adaptateurs de base de données de NENE2 sont déterministes par défaut et ne nécessitent pas de serveur de base de données propre au développeur.

## Stratégie par défaut

Les tests d'adaptateurs de base de données gérés par le framework utilisent d'abord une base de données SQLite en mémoire.

Raisons :

- Les tests s'exécutent dans le conteneur PHP existant
- Aucun credential MySQL ou PostgreSQL local n'est requis
- Chaque test peut créer son propre schéma
- Assez rapide pour `composer check`
- Facile à inspecter et comprendre

Commande par défaut pour les vérifications ciblées d'adaptateurs de base de données :

```bash
docker compose run --rm app composer test:database
```

`composer check` continue d'exécuter la suite PHPUnit complète incluant les tests d'adaptateurs de base de données.

## Forme des tests

Les tests d'adaptateurs de base de données doivent :

- créer le schéma dans le test
- utiliser des données petites et déterministes
- éviter les credentials proches de la production
- éviter de dépendre de l'état des migrations (sauf si le test concerne explicitement les migrations)
- préférer des objets de configuration typés aux variables d'environnement brutes
- placer les attentes SQL près de l'adaptateur testé

## Base de données externe

Pour les comportements d'adaptateur que SQLite ne peut pas couvrir, la vérification MySQL via Docker Compose est disponible.

Démarrez le service et exécutez la commande opt-in :

```bash
docker compose up -d mysql
docker compose run --rm app composer test:database:mysql
```

Ce chemin vérifie la création de connexion PDO MySQL, l'exécution de requêtes paramétrées et le rollback de transaction contre un vrai service MySQL.

Les tests de base de données externes restent opt-in jusqu'à ce que des conteneurs de service documentés et des credentials sécurisés existent en CI. Ils ne bloquent pas le chemin `composer check` local par défaut.

Les valeurs par défaut Docker Compose sont des credentials de développement local uniquement. Remplacez avec des variables d'environnement si nécessaire, et ne commitez pas les vrais secrets de base de données.

## Tests de migration

Les tests de migration doivent être séparés des tests de dépôts d'adaptateurs.

Lors de l'introduction de tests de migration, définissez :

- le service de base de données à utiliser en CI
- comment réinitialiser le schéma entre les exécutions
- si les seeds sont autorisés
- la commande Composer à exécuter
- le comportement lorsque les migrations sont intentionnellement irréversibles
