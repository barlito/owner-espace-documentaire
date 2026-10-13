.DEFAULT_GOAL := help

DC   = docker compose
EXEC = $(DC) exec -T php

.PHONY: help install up down build sh logs composer console db.migrate db.reset fixtures fixtures.volume assets watch test lint fix phpstan cs cs.fix

help: ## List available commands
	@grep -E '^[a-zA-Z_.-]+:.*?## ' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-12s\033[0m %s\n", $$1, $$2}'

install: build up ## Full install (build, start, dependencies, assets, database, demo dataset)
	$(EXEC) composer install --no-interaction
	$(MAKE) assets
	$(MAKE) db.migrate
	$(MAKE) fixtures

build: ## Build the PHP image
	$(DC) build

up: ## Start the stack (http://localhost:8000)
	$(DC) up -d --wait

down: ## Stop the stack
	$(DC) down

sh: ## Open a shell in the PHP container
	$(DC) exec php bash

logs: ## Follow the stack logs
	$(DC) logs -f

composer: ## Run composer (e.g. make composer c="require foo/bar")
	$(EXEC) composer $(c)

console: ## Run bin/console (e.g. make console c="debug:router")
	$(EXEC) bin/console $(c)

db.migrate: ## Create the database if needed and run migrations
	$(EXEC) bin/console doctrine:database:create --if-not-exists
	$(EXEC) bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration

db.reset: ## Drop and recreate the database
	$(EXEC) bin/console doctrine:database:drop --force --if-exists
	$(MAKE) db.migrate

fixtures: ## Load the demo dataset (purges the database and the stored files first)
	$(EXEC) bin/console doctrine:fixtures:load --no-interaction --purge-with-truncate --group=demo

fixtures.volume: ## Load the demo dataset plus about 5,000 documents for load testing (purges first)
	$(EXEC) bin/console doctrine:fixtures:load --no-interaction --no-debug --purge-with-truncate --group=demo --group=volume

assets: ## Build the CSS (Tailwind)
	$(EXEC) bin/console tailwind:build

watch: ## Rebuild the CSS on every change
	$(DC) exec php bin/console tailwind:build --watch

test: ## Recreate the test database, validate the schema and run PHPUnit
	$(EXEC) bin/console doctrine:database:drop --force --if-exists --env=test
	$(EXEC) bin/console doctrine:database:create --env=test
	$(EXEC) bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration --env=test
	$(EXEC) bin/console doctrine:schema:validate --env=test
	$(EXEC) bin/phpunit

lint: cs phpstan ## Check the code (style + static analysis)

fix: cs.fix ## Fix code style automatically

cs: ## PHP-CS-Fixer in check mode
	$(EXEC) vendor/bin/php-cs-fixer fix --dry-run --diff

cs.fix: ## PHP-CS-Fixer in fix mode
	$(EXEC) vendor/bin/php-cs-fixer fix

phpstan: ## PHPStan static analysis
	$(EXEC) bin/console cache:warmup --env=dev
	$(EXEC) vendor/bin/phpstan analyse --memory-limit=512M
