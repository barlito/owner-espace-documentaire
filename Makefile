.DEFAULT_GOAL := help

DC   = docker compose
EXEC = $(DC) exec php

.PHONY: help install up down build sh logs composer console db.migrate db.reset test

help: ## Liste les commandes disponibles
	@grep -E '^[a-zA-Z_.-]+:.*?## ' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-12s\033[0m %s\n", $$1, $$2}'

install: build up ## Installation complète (build, démarrage, dépendances, base)
	$(EXEC) composer install --no-interaction
	$(MAKE) db.migrate

build: ## Construit l'image PHP
	$(DC) build

up: ## Démarre la stack (http://localhost:8000)
	$(DC) up -d --wait

down: ## Arrête la stack
	$(DC) down

sh: ## Ouvre un shell dans le conteneur PHP
	$(EXEC) bash

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

test: ## Lance la suite PHPUnit (base de test recréée)
	$(EXEC) bin/console doctrine:database:drop --force --if-exists --env=test
	$(EXEC) bin/console doctrine:database:create --env=test
	$(EXEC) bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration --env=test
	$(EXEC) bin/phpunit
