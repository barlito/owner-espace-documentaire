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
docker compose exec php bin/console tailwind:build
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
| `make test` | Recrée la base de test, valide le schéma Doctrine et lance PHPUnit |
| `make lint` | Vérifie le style (PHP-CS-Fixer) et lance l'analyse statique (PHPStan) |
| `make fix` | Corrige automatiquement le style |
| `make watch` | Recompile le CSS (Tailwind) à chaque modification |
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
| Front | **AssetMapper + Stimulus + Turbo** | Imposés par le sujet (Turbo, Stimulus) ; AssetMapper est le défaut de Symfony et évite toute chaîne Node. C'est aussi la stack du site d'OWNER. |
| CSS | **Tailwind CSS v4** (`symfonycasts/tailwind-bundle`) | Binaire autonome, sans Node. Les tokens de la DA sont déclarés dans `@theme` et la palette par défaut est désactivée : seules les couleurs OWNER existent. |
| Composants | **Twig Components** + **UX Icons** (Lucide) | Briques partagées par les deux espaces (`Button`, `Badge`, `Alert`, `Card`, `EmptyState`). Icônes en SVG inline, versionnées dans `assets/icons/`, sans appel externe. |
| Qualité | **PHPStan** (niveau 8) + **PHP-CS-Fixer** | Analyse statique et style homogène, vérifiés en CI (GitHub Actions) sur chaque PR. |

## Modèle de données

```
Client 1──n User              (compte client ; un administrateur n'a pas de client)
Client 1──n Document          (un fichier accepté = un document)
```

| Entité | Rôle |
| --- | --- |
| `Client` | L'entreprise accompagnée. Son **dossier documentaire** est l'ensemble de ses documents : il n'y a pas d'entité « Dossier » distincte. |
| `User` | Compte de connexion (`ROLE_CLIENT` rattaché à un client, ou `ROLE_ADMIN` sans client). |
| `Document` | Pièce transmise : fichier original, nature et période (facultatives), statut d'analyse. |

**Choix et hypothèses**

- **Les documents appartiennent au client, pas au compte.** Pour ce MVP, un compte = un client, mais rattacher les pièces à l'entreprise évite de se fermer la porte à plusieurs personnes (dirigeant, comptable, assistante) travaillant sur le même dossier. Faire évoluer la propriété des documents après coup imposerait une migration de toutes les données.
- **Pas d'entité « Envoi ».** Un envoi est un geste d'interface : chaque fichier accepté devient immédiatement un document, et un fichier refusé n'a, par définition, jamais été reçu.
- **Le client est porté directement par le document**, même s'il pourrait être déduit : l'isolation entre clients se résume à un filtre `client_id`, sans jointure à ne pas oublier.
- **Le statut est dénormalisé sur le document** pour lister et filtrer rapidement des dossiers volumineux.
- **Nom de stockage unique** (`storageKey`), indépendant du nom transmis : deux fichiers de même nom ne s'écrasent pas.
- **Nature et période facultatives** : une pièce « non catégorisée » ou de période inconnue est acceptée. La nature suit la liste documentaire OWNER, regroupée en 16 types et 5 familles, dont « Autre ».
- **Identifiants UUID v7** : non devinables dans les URL et ordonnés dans le temps (index efficaces).
- **Garanties en base** : contraintes `CHECK` (cohérence de la période, valeurs de statut) et index partiel sur les documents en échec. Elles sont écrites dans les migrations ; la base de test est donc construite par les migrations, jamais par `schema:create`.
- **Pas de suppression de document dans le MVP** : l'historique doit rester fiable et conservé. Une suppression passerait d'abord par une suppression logique (soft delete), avant toute suppression définitive.
- Le code est en anglais ; l'interface et les messages sont en français.

## Direction artistique

L'interface reprend la DA du site [ownertechnology.com](https://ownertechnology.com/) : les couleurs, le rayon (`10px`) et les échelles de nuances sont relevés directement dans le CSS du site.

- **Couleurs** : vert `#376B44` (actions principales), bleu nuit `#121b35` (titres), bleu `#203D60` / `#D8E5EF` (aplats). Les états (succès, attention, erreur, information) reprennent leurs teintes, avec des nuances foncées pour le texte des badges afin de garder un contraste lisible.
- **Typographies** : le texte est en **Inter**, comme sur le site. Les titres du site utilisent **Tomato Grotesk**, une police commerciale dont la licence ne permet pas la redistribution dans un dépôt public : elle est remplacée par **Schibsted Grotesk** (SIL Open Font License), retenue après comparaison visuelle avec l'originale comme la plus proche en largeur et en rondeur.
- **Polices auto-hébergées** (`assets/fonts/`) plutôt que chargées depuis Google Fonts : aucune donnée de navigation (adresse IP) n'est transmise à un tiers, ce qui compte pour un service manipulant des pièces fiscales et sociales.
- Le logo et le favicon sont ceux d'OWNER.

Les composants sont visibles sur `/_styleguide` (environnements `dev` et `test` uniquement).

## Structure

```
.docker/        Dockerfile, Caddyfile et configuration PHP
assets/         CSS (tokens Tailwind), JS (Stimulus), polices, icônes, images
config/         Configuration Symfony
src/            Code applicatif
templates/      Templates Twig (components/ : composants d'interface)
tests/          Tests PHPUnit
compose.yaml    Stack locale (php + database)
```
