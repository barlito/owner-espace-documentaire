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
docker compose exec php bin/console doctrine:fixtures:load -n
```

### Comptes de démonstration

Chargés par `make install` (ou `make fixtures`, qui vide la base avant de les recréer). Mot de passe commun : `demo1234`.

| Rôle | Adresse e-mail | Accès |
| --- | --- | --- |
| Administratrice (Sophie Laurent, équipe OWNER) | `admin@owner.test` | `/admin` — supervision de tous les clients |
| Client — Boulangerie Martin | `paul@boulangerie-martin.test` | `/espace` — uniquement son propre dossier |
| Client — Micro-crèche Les Petits Pas | `claire@lespetitspas.test` | `/espace` — uniquement son propre dossier |

En environnement `dev`, ces comptes sont rappelés sous le formulaire de connexion (`/connexion`). Cet encart n'est jamais affiché en production.

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
| `make fixtures` | Recharge les comptes de démonstration (vide la base) |

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
| Stockage | **Flysystem** (`league/flysystem-bundle`) | Les fichiers passent par une abstraction : disque local aujourd'hui (`var/storage/documents`, hors de `public/`), adaptateur mémoire en test, un bucket S3 demain sans toucher au code métier. |
| Qualité | **PHPStan** (niveau 8) + **PHP-CS-Fixer** | Analyse statique et style homogène, vérifiés en CI (GitHub Actions) sur chaque PR. |

## Modèle de données

```
Client 1──n User              (compte client ; un administrateur n'a pas de client)
Client 1──n Document          (un fichier accepté = un document)
Document 1──n AnalysisAttempt (une tentative par analyse ou relance)
Document 1──n DocumentEvent   (timeline, en ajout seulement)
```

| Entité | Rôle |
| --- | --- |
| `Client` | L'entreprise accompagnée. Son **dossier documentaire** est l'ensemble de ses documents : il n'y a pas d'entité « Dossier » distincte. |
| `User` | Compte de connexion (`ROLE_CLIENT` rattaché à un client, ou `ROLE_ADMIN` sans client). |
| `Document` | Pièce transmise : fichier original, nature et période (facultatives), statut d'analyse, tentative courante. |
| `AnalysisAttempt` | Une exécution de l'analyse : numéro, statut, dates, résultat **ou** cause d'échec, administrateur à l'origine d'une relance. |
| `DocumentEvent` | Événement daté de la timeline : type, auteur éventuel, tentative concernée, client. |

**Cycle de vie d'un document**

```
En attente d'analyse ──▶ En cours d'analyse ──▶ Analysée
        ▲                        │
        │ relance (admin)        ▼
        └──────────────── Échec de l'analyse
```

Chaque changement passe par une méthode de `Document` (`startAnalysis`, `completeAnalysis`, `failAnalysis`, `retryAnalysis`) qui met à jour la tentative courante **et** ajoute l'événement correspondant : le statut, les tentatives et l'historique ne peuvent pas diverger. Une transition invalide lève `InvalidDocumentTransition`.

**Choix et hypothèses**

- **Les documents appartiennent au client, pas au compte.** Pour ce MVP, un compte = un client, mais rattacher les pièces à l'entreprise évite de se fermer la porte à plusieurs personnes (dirigeant, comptable, assistante) travaillant sur le même dossier. Faire évoluer la propriété des documents après coup imposerait une migration de toutes les données.
- **Pas d'entité « Envoi ».** Un envoi est un geste d'interface : chaque fichier accepté devient immédiatement un document, et un fichier refusé n'a, par définition, jamais été reçu.
- **Le client est porté directement par le document**, même s'il pourrait être déduit : l'isolation entre clients se résume à un filtre `client_id`, sans jointure à ne pas oublier.
- **Le statut est dénormalisé sur le document** pour lister et filtrer rapidement des dossiers volumineux.
- **Nom de stockage unique** (`storageKey`), indépendant du nom transmis : deux fichiers de même nom ne s'écrasent pas.
- **Nature et période facultatives** : une pièce « non catégorisée » ou de période inconnue est acceptée. La nature suit la liste documentaire OWNER, regroupée en 16 types et 5 familles, dont « Autre ».
- **Identifiants UUID v7** : non devinables dans les URL et ordonnés dans le temps (index efficaces).
- **Une relance crée une nouvelle tentative** sur le même document, sans jamais réécrire la précédente : un échec et sa cause restent visibles après une réussite.
- **Le résultat est stocké sur la tentative** qui l'a produit : il ne peut pas être rattaché à la mauvaise tentative, au mauvais document ou au mauvais client.
- **La timeline stocke des codes, pas des phrases** (`analysis_failed` + `{reason: unreadable_file}`) : le texte est rédigé à l'affichage, adapté au client ou à l'équipe OWNER. Les événements sont en lecture seule pour Doctrine.
- **Garanties en base** : contraintes `CHECK` (cohérence de la période, valeurs de statut, **une tentative échouée ne porte jamais de résultat** et une tentative réussie en porte toujours un), index unique partiel interdisant **plus d'une tentative non terminée par document** (une relance répétée ne peut pas lancer deux traitements), unicité du numéro de tentative, index partiel sur les documents en échec. Elles sont écrites dans les migrations ; la base de test est donc construite par les migrations, jamais par `schema:create`.
- **Pas de suppression de document dans le MVP** : l'historique doit rester fiable et conservé. Une suppression passerait d'abord par une suppression logique (soft delete), avant toute suppression définitive.
- Le code est en anglais ; l'interface et les messages sont en français.

## Dépôt de documents

- **Formats acceptés** : PDF, JPEG, PNG, HEIC (photos d'iPhone, acceptées volontairement : l'aperçu ou l'analyse pourront ne pas les gérer), Word `.docx`, Excel `.xlsx`. **20 Mo par fichier, 20 fichiers par envoi**, fichiers vides refusés. Tout est défini dans `App\Upload\UploadPolicy` (validation, contrôleurs, gabarit et contrôleur Stimulus lisent la même source).
- **Le type MIME est détecté côté serveur, à partir du contenu** (fileinfo), jamais d'après ce que déclare le navigateur : un `.txt` renommé en `.pdf` est refusé. Certains `.docx` / `.xlsx` (générés par des outils tiers) sont détectés comme un simple `application/zip` : dans ce cas seulement, et si l'extension est `.docx` / `.xlsx`, la structure de l'archive est vérifiée (`[Content_Types].xml` + dossier `word/` ou `xl/`) avant de leur attribuer le type OOXML. Un zip quelconque reste refusé.
- **Stockage** : clé `{clientId}/{uuidv7}`, sans extension ni partie contrôlée par l'utilisateur. Le nom d'origine n'existe qu'en base, pour l'affichage (nom de base uniquement, 255 caractères max). Le fichier est écrit en flux et son SHA-256 est calculé en flux : il n'est jamais chargé en mémoire.
- **Atomicité** : le fichier est écrit d'abord, puis le document est enregistré. Si l'écriture en base échoue, le fichier est supprimé (compensation) : il n'existe jamais de document sans fichier. Inversement, un arrêt brutal du processus entre les deux étapes peut laisser un fichier orphelin ; c'est acceptable pour un MVP et nettoyable plus tard par une commande qui compare le stockage à la base.
- **Interface** : un envoi = une requête par fichier (progression par fichier, un refus n'empêche pas les autres), deux envois en parallèle. Sans JavaScript, le formulaire reste fonctionnel (`files[]` en une seule requête, `post_max_size = 100M`), et chaque fichier est traité indépendamment.
- Les limites PHP sont dans `.docker/php/app.ini` (`upload_max_filesize = 20M`, `post_max_size = 100M`, `max_file_uploads = 20`).

## Direction artistique

L'interface reprend la DA du site [ownertechnology.com](https://ownertechnology.com/) : les couleurs, le rayon (`10px`) et les échelles de nuances sont relevés directement dans le CSS du site.

- **Couleurs** : vert `#376B44` (actions principales), bleu nuit `#121b35` (titres), bleu `#203D60` / `#D8E5EF` (aplats). Les états (succès, attention, erreur, information) reprennent leurs teintes, avec des nuances foncées pour le texte des badges afin de garder un contraste lisible.
- **Typographies** : le texte est en **Inter**, comme sur le site. Les titres du site utilisent **Tomato Grotesk**, une police commerciale dont la licence ne permet pas la redistribution dans un dépôt public : elle est remplacée par **Schibsted Grotesk** (SIL Open Font License), retenue après comparaison visuelle avec l'originale comme la plus proche en largeur et en rondeur.
- **Polices auto-hébergées** (`assets/fonts/`) plutôt que chargées depuis Google Fonts : aucune donnée de navigation (adresse IP) n'est transmise à un tiers, ce qui compte pour un service manipulant des pièces fiscales et sociales.
- Le logo et le favicon sont ceux d'OWNER.

Les composants sont visibles sur `/_styleguide` (environnements `dev` et `test` uniquement).

## Sécurité de la connexion

- **Limitation des échecs par IP, tous comptes confondus** : 5 échecs en 5 minutes depuis une même adresse IP bloquent les nouvelles tentatives de cette IP jusqu'à la fin de la fenêtre (`config/packages/rate_limiter.yaml`). Seuls les échecs comptent : passer d'un compte à l'autre ne bloque jamais personne, et le compte visé n'est jamais verrouillé (sinon n'importe qui pourrait bloquer le compte d'un client en ratant volontairement sa connexion).
- Limite assumée : une attaque distribuée sur de nombreuses IP n'est pas couverte ; la réponse adaptée (double authentification, CAPTCHA, alertes) dépasse le MVP. Plusieurs personnes derrière une même IP (bureau) partagent le compteur.
- Formulaires de connexion et de déconnexion protégés par CSRF ; la déconnexion n'est possible qu'en POST.

## Pages d'erreur

Une seule page (`templates/bundles/TwigBundle/Exception/error.html.twig`) adapte titre, message et icône au code : 400, 403, 404, 405, 413, 429, 5xx, et un message générique pour les autres. Elle ne dépend ni de l'utilisateur connecté (un 404 du routeur survient avant le chargement de la sécurité) ni de la base (une erreur 5xx peut venir d'elle). Le 404 reste volontairement vague (« n'existe pas, ou n'est pas accessible depuis votre compte ») pour ne jamais révéler l'existence d'une pièce d'un autre client.

Ces pages ne s'affichent que hors mode debug ; en développement, elles se prévisualisent sur `/_error/404`, `/_error/500`, etc. Les routes `/_styleguide` et `/_error` n'existent pas en production (vérifié par `ProductionRoutesTest`).

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
