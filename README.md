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
docker compose exec php bin/console doctrine:fixtures:load -n --purge-with-truncate --group=demo
```

### Données de démonstration

`make install` (ou `make fixtures`) charge un jeu de démonstration, après avoir **vidé la base et le stockage des documents** (seul le disque `documents.storage` est purgé, via Flysystem) :

- les trois comptes ci-dessous ;
- environ 14 mois d'historique pour les deux clients : ~60 documents pour la Boulangerie Martin, ~36 pour la Micro-crèche, avec de vrais fichiers stockés (PDF générés, quelques images) ;
- tous les statuts : analysés pour la plupart, quelques documents en attente ou en cours d'analyse (déposés dans l'heure), des échecs de causes variées, et des documents relancés par l'administratrice (échec puis succès à la tentative n° 2, ou relance encore en attente).

`make fixtures.volume` charge en plus un jeu de **test de charge** : 40 clients de 3 à 100 documents et un très gros client de 3 000 documents, soit environ 5 200 documents au total (une dizaine de secondes). Ces 40 comptes partagent le mot de passe `demo1234`.

Les jeux sont déterministes (graine fixe) : seules les dates sont relatives au jour du chargement.

#### Comptes

Mot de passe commun : `demo1234`.

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
| `make up` / `make down` | Démarre / arrête la stack (dont le worker) |
| `make worker.restart` / `make worker.logs` | Redémarre le worker (un process long ne recharge pas le code) / suit ses logs |
| `make sh` | Shell dans le conteneur PHP |
| `make test` | Recrée la base de test, valide le schéma Doctrine et lance PHPUnit |
| `make lint` | Vérifie le style (PHP-CS-Fixer) et lance l'analyse statique (PHPStan) |
| `make fix` | Corrige automatiquement le style |
| `make watch` | Recompile le CSS (Tailwind) à chaque modification |
| `make console c="…"` | Lance une commande Symfony |
| `make db.reset` | Supprime et recrée la base |
| `make fixtures` | Recharge le jeu de démonstration (vide la base et le stockage des documents) |
| `make fixtures.volume` | Recharge la démonstration et ajoute ~5 000 documents pour tester la charge |

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
| Horloge | **`symfony/clock`** | Les entités lisent l'heure via `now()` : identique en production, mais les fixtures (et les tests) peuvent figer ou avancer l'horloge pour produire un historique daté de façon cohérente, sans setter ni réflexion. |
| Messagerie | **Symfony Messenger** (transport Doctrine) + **Scheduler** | File d'analyse dans PostgreSQL, sans service supplémentaire. Voir « Analyse simulée ». |
| Temps réel | **Mercure** (hub intégré à FrankenPHP) + **Turbo Streams** | Les statuts changent sous les yeux de l'utilisateur, sans rechargement ni service supplémentaire. Voir « Temps réel ». |
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

## Mon dossier

`/espace` liste **toutes** les pièces du client connecté (25 par page, les plus récentes d'abord), avec recherche et filtres. Le client vient toujours de la session, jamais de la requête.

| Paramètre | Rôle |
| --- | --- |
| `q` | Nom du fichier, insensible à la casse et aux accents (`releve` trouve `Relevé`) ; `%` et `_` sont cherchés littéralement |
| `statut` | `pending`, `processing`, `analyzed` ou `failed` |
| `nature` | Une valeur de `DocumentType`, ou `aucune` (pièces non catégorisées) |
| `periode` | Mois `AAAA-MM` ou année `AAAA` : garde les pièces dont la période **chevauche** celle-ci (un relevé trimestriel apparaît pour chacun de ses mois) ; une pièce sans période n'apparaît jamais |
| `page` | Numéro de page ; au-delà de la dernière, la dernière page est affichée |

Tout est dans l'URL (partageable, bouton Précédent du navigateur). Une valeur invalide est ignorée comme si elle n'avait pas été envoyée : jamais d'erreur 500 ni de filtre fantôme (`App\Dossier\DocumentFilters`). Le formulaire ne recharge que la zone de résultats (Turbo Frame, l'URL avance) et la recherche part après 300 ms de pause ; sans JavaScript, le bouton « Rechercher » recharge la page. Le nombre de résultats est annoncé dans la zone `aria-live` unique de la page (`#live-status`), plutôt que par une seconde zone qui serait recréée à chaque rechargement.

**Temps réel.** Une pièce reçue n'est insérée en direct que sur la première page sans filtre (seule la liste de cette page porte l'identifiant `dashboard-documents`). Une ligne mise à jour en direct reste affichée même si son nouveau statut ne correspond plus au filtre actif, jusqu'au prochain chargement ; les compteurs par statut ne sont, eux, recalculés qu'au chargement des résultats.

**Volume** (`make fixtures.volume`, client de 3 000 pièces, PostgreSQL 18 après `ANALYZE`) : liste et comptage prennent chacun de 0,1 à 3 ms, sans filtre, avec recherche, avec statut, avec période, en page 1 comme en page 100. Aucun index n'a été ajouté : un index `(client_id, period_start)` ne se justifie pas à cette échelle. Ce qui passe à l'échelle : le filtrage par client s'appuie sur les index existants (`client_id, uploaded_at`), la liste ne charge aucune association, le comptage est une requête agrégée. Prochaines étapes si un client dépassait quelques dizaines de milliers de pièces : pagination par curseur (`uploaded_at, id`) à la place de `OFFSET`, et index trigramme (`pg_trgm`) sur le nom de fichier normalisé (la recherche parcourt aujourd'hui toutes les pièces du client).

## Analyse simulée

Chaque document déposé reçoit une analyse **simulée** (aucun vrai traitement), exécutée en asynchrone par un worker (`make up` le démarre ; `make worker.logs` pour le suivre).

### Déroulé

L'analyse passe par l'interface `App\Analysis\Provider\Interface\AnalysisProviderInterface`, calquée sur un fournisseur tiers asynchrone (OCR, IA) : on lui **soumet** le document, il répond par une **référence**, puis on **vient chercher le résultat** avec cette référence. La seule implémentation aujourd'hui est `SimulatedAnalysisProvider`.

1. **Dépôt** : le document et le message `StartAnalysis` sont écrits dans **la même transaction** (`AnalysisQueue` est le seul point d'envoi).
2. **Démarrage** (`StartAnalysisHandler`) : le worker verrouille le document (`SELECT … FOR UPDATE`), le soumet au fournisseur (`submit()`), enregistre la référence sur la tentative (`provider_reference`), passe le document « en cours » et programme le message `CheckAnalysisOutcome` après le délai indiqué par le fournisseur. Il n'y a **aucun `sleep()`** : le worker reste libre pour les autres documents.
3. **Résultat** (`CheckAnalysisOutcomeHandler`) : le worker demande où en est l'analyse (`fetchOutcome()`) :
   - **toujours en cours** → le même message est renvoyé avec le délai indiqué (interrogation périodique, toutes les 20 s pour une analyse longue simulée) ;
   - **réussie** → le résultat est enregistré (JSON : type détecté, période, résumé, champs, confiance). Le type n'est renseigné par l'analyse que si le client ne l'a pas choisi ; la période vient toujours de l'analyse ;
   - **échouée** → l'échec et sa raison sont enregistrés.

Les doublons et messages tardifs sont sans effet : chaque message porte l'identifiant de la tentative, et le handler ignore le message si le document n'est plus dans l'état attendu. Un fournisseur qui ne répond jamais est borné par la réconciliation (`ANALYSIS_STALE_AFTER`).

Le simulateur ne garde aucun état : le scénario tiré à la soumission est écrit dans la référence (`sim:<tentative>:<issue>:<prêt à>`), comme un vrai fournisseur le conserverait de son côté.

### Brancher un vrai fournisseur

Il manque une seule classe : `XxxAnalysisProvider implements AnalysisProviderInterface`, avec le client HTTP de Symfony et la conversion de la réponse du fournisseur en `AnalysisResult` / `FailureReason`, puis l'alias de `App\Analysis\Provider\Interface\AnalysisProviderInterface` dans `config/services.yaml`. Points d'attention, déjà prévus par le contrat :

- **Idempotence** : si la soumission réussit mais que la transaction échoue ensuite, Messenger rejoue le message ; l'identifiant de la tentative sert de clé d'idempotence auprès du fournisseur pour éviter une double soumission.
- **Appel HTTP sous verrou** : `submit()` et `fetchOutcome()` s'exécutent dans la transaction du worker, ligne verrouillée : timeout court. Pour un fournisseur lent, isoler la soumission dans son propre message.
- **Erreurs** : une erreur technique (fournisseur indisponible) est une exception → Messenger rejoue le message, puis la tentative passe en échec (voir la réconciliation). Un document que le fournisseur refuse est un `AnalysisOutcome::failed()`.
- **Webhook plutôt que polling** : si le fournisseur notifie la fin d'analyse, un contrôleur retrouve la tentative par `provider_reference` et envoie `CheckAnalysisOutcome` ; l'interrogation périodique reste le filet de sécurité. Ajouter alors un index sur `provider_reference`.
- **Délai maximal** : `ANALYSIS_STALE_AFTER` doit dépasser le temps de traitement garanti par le fournisseur.

### Scénarios

| Cas | Probabilité | Durée simulée |
| --- | --- | --- |
| Succès rapide | 75 % | 3 à 12 s |
| Succès long | 10 % | 60 à 120 s |
| Échec (fichier illisible, format non pris en charge, inconnu) | 15 % | 3 à 12 s |

- **Mots-clés dans le nom du fichier** (pour la démonstration, insensibles à la casse et aux accents) : `illisible` → échec « fichier illisible » ; `echec` → échec inconnu ; `lent` ou `long` → succès après 60 à 120 s. Le nom sert aussi à deviner le type (`bulletin`, `kbis`, `statuts`, `bilan`, `dsn`, `mutuelle`…) et la période (`2026-03`, `mars-2026`, `T1-2026`, ou une année pour un bilan) ; à défaut, le mois précédant le dépôt pour les documents périodiques (bulletin, DSN, situation comptable) et aucune période pour un document « Autre » : une période absente vaut mieux qu'une période inventée.
- **Relance** : une tentative n°2 ou plus réussit toujours, **sauf** si la précédente a échoué sur un fichier illisible (une relance ne répare pas un fichier).
- L'échec « interrompu » n'est jamais tiré au sort : il est réservé à la réconciliation.

### Réconciliation

Un message envoyé ne peut pas se perdre : il est écrit dans la même transaction que le document (voir plus bas). Restent trois cas, chacun traité sans minuterie sur les documents en attente, pour qu'un document qui attend simplement son tour dans une file chargée ne soit jamais renvoyé :

- **un message qui plante à chaque fois** (bug, donnée corrompue) : Messenger le rejoue 3 fois avec un délai croissant, puis l'abandonne. À cet instant, `FailAnalysisOnGivenUpMessageListener` passe la tentative en échec (cause inconnue) : le document apparaît parmi les échecs, un administrateur peut le relancer. Le message reste consultable dans la file `failed` (`messenger:failed:show`) ; le rejouer plus tard est sans effet, la tentative n'étant plus la tentative courante ;
- **un document créé sans message** (fixtures de démonstration) : le Scheduler lance `ReconcileAnalyses` toutes les minutes (aussi disponible à la main : `make console c="app:analysis:reconcile"`), qui met en file les tentatives en attente jamais envoyées (`queued_at` vide) ;
- **un worker mort en plein traitement**, ou un message de fin perdu : la même réconciliation passe en échec « interrompu » une tentative « en cours » depuis plus de `ANALYSIS_STALE_AFTER` secondes.

Au plus `ANALYSIS_RECONCILE_BATCH` documents par type et par passage, les plus anciens d'abord ; un index partiel (`idx_document_unfinished`) évite de parcourir tout l'historique.

| Variable | Défaut | Rôle |
| --- | --- | --- |
| `ANALYSIS_RECONCILE_INTERVAL` | `1 minute` | Fréquence de la réconciliation |
| `ANALYSIS_STALE_AFTER` | `600` | Secondes avant d'interrompre une analyse en cours. Doit dépasser la plus longue analyse du fournisseur (120 s pour le simulateur, déclarée dans `config/services.yaml`) : sinon le worker refuse de démarrer |
| `ANALYSIS_RECONCILE_BATCH` | `100` | Documents traités par type et par passage |

**Évolution possible : la base indisponible au moment de l'abandon.** Si la base tombe pendant que Messenger abandonne un message, le listener ne peut pas écrire l'échec non plus : le document reste « en attente » (ou « en cours », et la réconciliation finira par l'interrompre). Une commande pourrait chercher les documents en attente depuis plusieurs heures pour alerter l'équipe ou les remettre en file automatiquement. Elle n'est pas faite : le cas suppose une panne de la base, et le message reste de toute façon dans la file `failed`.

**À faire évoluer : plusieurs workers.** Le Scheduler tourne dans chaque worker : avec plusieurs workers, chacun lance sa propre réconciliation, et plusieurs passages peuvent viser le même document en même temps. C'est sans conséquence (chaque document est verrouillé puis revérifié avant d'être modifié, le second passage ne fait donc rien), mais c'est du travail en double et de l'attente sur les verrous. Pour passer à plusieurs workers, il faudra n'exécuter la réconciliation qu'une fois par intervalle : un verrou sur le schedule (`Schedule::lock()` avec le composant Lock), ou un worker dédié au seul transport `scheduler_analysis`.

Reprises techniques : les exceptions (base indisponible…) sont rejouées 3 fois avec un délai croissant, puis le message part en file `failed` (`messenger:failed:show`). Ce n'est pas le cas des échecs simulés, qui sont des résultats métier.

### Pourquoi le transport Doctrine

- **Une stack plus simple** : la file est une table PostgreSQL, pas de broker à déployer ni à superviser ; le worker écoute les nouveaux messages via `LISTEN/NOTIFY` (pas de polling).
- **Envoi atomique avec l'écriture en base** : le transport utilise la même connexion que l'ORM, donc le document et son message (ou, dans un handler, la mise à jour et les messages suivants) sont validés ou annulés ensemble, sans pattern outbox.
- Le prix : la file partage la base et ne passe pas à l'échelle d'un vrai broker. Nous restons sur Doctrine pour la simplicité de la stack.

La table `messenger_messages` est créée par une migration (`auto_setup=0`) et ignorée par la validation du schéma (`schema_filter`).

### Passer à RabbitMQ

1. `composer require symfony/amqp-messenger` ; dans le `Dockerfile`, ajouter `amqp` à la liste `install-php-extensions`.
2. Un service dans `compose.yaml` (et `depends_on` sur `rabbitmq` pour `worker`) :

```yaml
rabbitmq:
    image: rabbitmq:4-management-alpine
    ports:
        - "15672:15672" # interface d'administration
    healthcheck:
        test: ["CMD", "rabbitmq-diagnostics", "-q", "ping"]
        interval: 10s
        timeout: 5s
        retries: 10
    volumes:
        - rabbitmq_data:/var/lib/rabbitmq
```

3. `MESSENGER_TRANSPORT_DSN=amqp://guest:guest@rabbitmq:5672/%2f/messages`. Garder `failed` sur Doctrine (`doctrine://default?queue_name=failed&auto_setup=0`) ou l'adapter de même.

(Déclarer aussi le volume `rabbitmq_data`.) Ce qu'on perd : l'envoi n'est **plus atomique** avec l'écriture en base (un crash entre le commit et la publication perd le message ; l'inverse, publier avant un commit qui échoue, est géré par les handlers qui ignorent ce qu'ils ne reconnaissent pas). Il faut soit un pattern outbox, soit accepter la réconciliation comme filet de sécurité : elle remet déjà en file les documents jamais pris en charge, c'est donc suffisant ici.

## Temps réel

Quand une analyse avance, la page ouverte se met à jour toute seule : le tableau de bord (« Dernières pièces reçues ») et la liste des fichiers de la page de dépôt, y compris pour une pièce déposée depuis un autre onglet.

### Fonctionnement

1. **Hub Mercure** : celui de FrankenPHP, déclaré par le bloc `mercure` du `Caddyfile`. Aucun conteneur de plus. Il sert sur l'origine de l'application (`/.well-known/mercure`), ce qui permet au navigateur d'envoyer le cookie d'autorisation sans configuration CORS particulière.
2. **Publication atomique** : `QueueDocumentUpdateListener` (écouteur Doctrine) repère à chaque `flush` les documents insérés ou dont le statut change et met en file un message `PublishDocumentUpdate` par document. Comme le transport Doctrine partage la connexion de l'ORM, ce message est écrit dans la transaction de l'appelant (dépôt, handlers d'analyse) : un changement annulé n'est jamais annoncé. C'est l'**unique** point de publication, personne d'autre n'a à y penser. Il passe par un bus dédié (`messenger.bus.realtime`) sans `doctrine_transaction`, dont le `flush` final ne doit pas être rejoué depuis un `flush`.
3. **Le worker publie** : `PublishDocumentUpdateHandler` relit le document (son état *au moment de la publication*, pas celui du message), le rend en actions Turbo Stream (`templates/realtime/document_streams.stream.html.twig`) et le publie sur le hub.
4. **Le navigateur applique** : `turbo_stream_from()` ajoute un `<turbo-mercure-stream-source>` à la page ; Turbo applique les actions sur les éléments d'identifiant connu (`document-{uuid}`, `upload-status-{uuid}`, `dashboard-documents`). Une cible absente de la page est ignorée : chaque page ne réagit qu'à ce qu'elle affiche.

### Confidentialité

- Un topic **par client** : `/clients/{uuid}/documents` (défini dans `App\Realtime\Topics`, seul endroit où les topics sont nommés). Toute publication est `private`.
- Le hub n'accepte **aucun abonné anonyme**. La page pose un cookie `mercureAuthorization` (JWT, HttpOnly, `SameSite=Strict`) dont le claim `subscribe` ne contient que le topic du client connecté : connaître l'uuid d'un autre client ne suffit pas à écouter ses mises à jour. Le navigateur ne peut jamais publier. Les pages admin ne posent pas ce cookie. Testé dans `tests/Functional/RealtimeTest.php` (le JWT est décodé et son contenu vérifié).
- Une seule clé (`MERCURE_JWT_SECRET`) signe le jeton de publication et les cookies, et le hub la vérifie : seule l'application émet des jetons. Le hub est en mode de compatibilité Mercure 0.x (`protocol_version_compatibility 8`) car `symfony/mercure` n'émet pas encore les jetons RFC 9068 du mode 1.0.

### Accessibilité

Une seule région `aria-live="polite"` par page (`#live-status`, dans le layout) : chaque mise à jour y annonce une phrase courte (« Bulletin-mars.pdf : analyse terminée. »). Les badges ne sont pas des régions vives. Le surlignage de la ligne mise à jour est désactivé avec `prefers-reduced-motion`.

### Sans Mercure

Rien n'en dépend : si le hub est indisponible, la page s'affiche et un rechargement montre l'état courant, le navigateur se reconnecte seul quand le hub revient. Si la publication échoue, le message est rejoué 3 fois puis part en file `failed` ; l'état en base n'est jamais perdu, seul le direct l'est. Le dépôt renvoie aussi le badge de statut dans sa réponse, donc la ligne est correcte même si le direct n'a pas encore démarré.

### Choix et limites

- Les lignes ajoutées en direct s'empilent sur le tableau de bord sans retirer les plus anciennes (la page ne montre « que » les 10 dernières à son chargement) : un rechargement revient à 10 lignes. Éliminer l'excédent demanderait du JavaScript pour un gain nul.
- Les fixtures coupent l'écouteur (`QueueDocumentUpdateListener::mute()`) : des milliers de documents que personne ne regarde n'ont pas à remplir la file.
- Avec RabbitMQ (voir plus haut), le message de publication cesse d'être atomique avec la base ; une mise à jour manquée est sans gravité (rechargement).

### Brancher l'admin (ou la page d'un document) plus tard

1. Nommer le topic dans `Topics` (par exemple `Topics::admin()`) et poser le cookie correspondant sur les seules pages admin.
2. Ajouter ses actions Turbo Stream dans `document_streams.stream.html.twig` (ou rendre un second flux dans `DocumentStreamRenderer`) et publier un second `Update` sur ce topic dans `PublishDocumentUpdateHandler`. Rien ne change côté écouteur : il décide déjà « ce document a changé ».
3. Donner à chaque élément visé un `id` stable, comme `document-{uuid}`.

### Variables d'environnement

| Variable | Rôle | Valeur par défaut (dev) |
| --- | --- | --- |
| `MERCURE_URL` | URL de publication, via le réseau Docker (conteneurs `php` et `worker`) | `http://php/.well-known/mercure` |
| `MERCURE_PUBLIC_URL` | URL du hub pour le navigateur | `http://localhost:${HTTP_PORT}/.well-known/mercure` (posée par `compose.yaml`) |
| `MERCURE_JWT_SECRET` | Clé HS256 : lue par Symfony **et** par Caddy | clé de développement dans `.env` et `compose.yaml` |
| `MERCURE_CORS_ORIGINS` | Origines acceptées par le hub | `http://localhost:${HTTP_PORT}` |

En production, `MERCURE_JWT_SECRET` doit être un secret aléatoire d'au moins 256 bits (`openssl rand -hex 32`) fourni par l'environnement réel, jamais la valeur de développement ; `MERCURE_URL`, `MERCURE_PUBLIC_URL` et `MERCURE_CORS_ORIGINS` suivent le domaine réel.

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
compose.yaml    Stack locale (php, worker, database)
```

### Organisation du code

Une seule règle : **ce que le framework appelle va dans les dossiers standard de Symfony, le code métier qu'ils appellent est rangé par fonctionnalité.**

```
src/
├── Command/  Controller/  EventListener/  Message/  MessageHandler/  Scheduler/   points d'entrée
├── Entity/  Enum/  Exception/  Repository/                                       modèle et persistance
├── Security/  Twig/  DataFixtures/
├── Realtime/    nommage des topics Mercure et rendu des mises à jour en direct
├── Dossier/     métier de « Mon dossier » : filtres de recherche, pagination, libellés de période
├── Doctrine/    extensions DQL (fonction `UNACCENT`)
├── Upload/      métier du dépôt : politique, détection du format, enregistrement
└── Analysis/    métier de l'analyse : mise en file, résultat, réglages de la réconciliation
    ├── Provider/     le contrat d'un fournisseur d'analyse : objets échangés, interface dans Provider/Interface/
    └── Simulation/   l'implémentation simulée du fournisseur (scénario, lecture du nom de fichier)
```

Ce sont les dossiers que créent les générateurs de Symfony (`make:message`, `make:command`…) : un développeur Symfony s'y retrouve sans explication. Une architecture hexagonale (domaine sans dépendance au framework, ports et adapters) a été écartée : pour un MVP avec une seule base, un seul transport et un seul stockage, elle multiplierait les fichiers sans bénéfice, au détriment de la facilité de reprise. Les règles métier restent malgré tout isolées : dans les entités (transitions de `Document`) et dans `Upload/` et `Analysis/`, sans dépendance aux contrôleurs ni aux handlers.
