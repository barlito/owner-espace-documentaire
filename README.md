# OWNER — Espace documentaire (MVP)

Espace où un client dépose ses pièces comptables, juridiques et sociales, les retrouve et suit leur analyse (simulée), avec un espace administrateur de supervision et de relance.

> Projet réalisé dans le cadre d'un test technique. Le README est enrichi au fil des phases ; la section « Livraison » (hypothèses, limites, usage de l'IA) sera complétée en fin de projet.

## Prérequis

- Docker avec Docker Compose v2
- `make` (optionnel : chaque cible n'est qu'un raccourci vers `docker compose`)

Aucun PHP, Composer ou Node n'est nécessaire sur la machine hôte.

## Lancer le projet

```bash
make install
```

L'application est ensuite disponible sur http://localhost:8000.

Sans `make` :

```bash
docker compose up -d --build --wait
docker compose exec php composer install
docker compose exec php bin/console doctrine:database:create --if-not-exists
docker compose exec php bin/console doctrine:migrations:migrate -n
```

Le port peut être changé avec `HTTP_PORT=8080 make up`. Si votre UID/GID n'est pas `1000`, exportez `USER_ID=$(id -u) GROUP_ID=$(id -g)` avant le build.

## Commandes utiles

| Commande | Rôle |
| --- | --- |
| `make help` | Liste toutes les commandes |
| `make up` / `make down` | Démarre / arrête la stack |
| `make sh` | Shell dans le conteneur PHP |
| `make test` | Recrée la base de test et lance PHPUnit |
| `make lint` | Vérifie le style (PHP-CS-Fixer) et lance l'analyse statique (PHPStan) |
| `make fix` | Corrige automatiquement le style |
| `make console c="…"` | Lance une commande Symfony |
| `make db.reset` | Supprime et recrée la base |

## Stack technique

| Brique | Choix | Pourquoi |
| --- | --- | --- |
| Framework | **Symfony 7.4 (LTS)** | Imposé par le sujet. La LTS est préférée à Symfony 8.0 : support long (bugs jusqu'en 11/2028, sécurité jusqu'en 11/2029) et écosystème de bundles stabilisé, ce qui compte pour un projet destiné à être repris. |
| Langage | **PHP 8.4** | Version stable, supportée par Symfony 7.4 et par l'ensemble des dépendances. |
| Serveur | **FrankenPHP** (Caddy) | Un seul conteneur sert PHP et les fichiers statiques. |
| Base de données | **PostgreSQL 18** | Imposé par le sujet. |
| Environnement | **Docker Compose** | Un `make install` suffit, sans dépendance à une infrastructure externe. |
| Qualité | **PHPStan** (niveau 8) + **PHP-CS-Fixer** | Analyse statique et style homogène, vérifiés en CI (GitHub Actions) sur chaque PR. |

## Structure

```
.docker/        Dockerfile, Caddyfile et configuration PHP
config/         Configuration Symfony
src/            Code applicatif
tests/          Tests PHPUnit
compose.yaml    Stack locale (php + database)
```
