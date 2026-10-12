.DEFAULT_GOAL := help

DC   = docker compose
EXEC = $(DC) exec -T php

.PHONY: help install up down build sh logs composer console db.migrate db.reset assets watch test lint fix phpstan cs cs.fix

help: ## Liste les commandes disponibles
	@grep -E '^[a-zA-Z_.-]+:.*?## ' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-12s\033[0m %s\n", $$1, $$2}'

install: build up ## Installation complète (build, démarrage, dépendances, base)
	$(EXEC) composer install --no-interaction
	$(MAKE) assets
	$(MAKE) db.migrate

build: ## Construit l'image PHP
	$(DC) build

up: ## Démarre la stack (http://localhost:8000)
	$(DC) up -d --wait

down: ## Arrête la stack
	$(DC) down

sh: ## Ouvre un shell dans le conteneur PHP
	$(DC) exec php bash

logs: ## Suit les logs de la stack
	$(DC) logs -f

composer: ## Lance composer (ex: make composer c="require foo/bar")
	$(EXEC) composer $(c)

console: ## Lance bin/console (ex: make console c="debug:router")
	$(EXEC) bin/console $(c)

db.migrate: ## Crée la base si besoin et joue les migrations
	$(EXEC) bin/console doctrine:database:create --if-not-exists
	$(EXEC) bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration

db.reset: ## Supprime et recrée la base
	$(EXEC) bin/console doctrine:database:drop --force --if-exists
	$(MAKE) db.migrate

assets: ## Compile le CSS (Tailwind)
	$(EXEC) bin/console tailwind:build

watch: ## Recompile le CSS à chaque modification
	$(DC) exec php bin/console tailwind:build --watch

test: ## Lance la suite PHPUnit (base de test recréée)
	$(EXEC) bin/console doctrine:database:drop --force --if-exists --env=test
	$(EXEC) bin/console doctrine:database:create --env=test
	$(EXEC) bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration --env=test
	$(EXEC) bin/phpunit

lint: cs phpstan ## Vérifie le code (style + analyse statique)

fix: cs.fix ## Corrige automatiquement le style

cs: ## PHP-CS-Fixer en mode vérification
	$(EXEC) vendor/bin/php-cs-fixer fix --dry-run --diff

cs.fix: ## PHP-CS-Fixer en mode correction
	$(EXEC) vendor/bin/php-cs-fixer fix

phpstan: ## Analyse statique PHPStan
	$(EXEC) bin/console cache:warmup --env=dev
	$(EXEC) vendor/bin/phpstan analyse --memory-limit=512M
