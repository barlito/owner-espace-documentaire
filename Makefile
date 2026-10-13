.DEFAULT_GOAL := help

DC   = docker compose
EXEC = $(DC) exec -T php

.PHONY: help install up down build sh logs worker.restart worker.logs composer console db.migrate db.reset fixtures fixtures.volume assets watch test lint fix phpstan cs cs.fix

help: ## List available commands
	@grep -E '^[a-zA-Z_.-]+:.*?## ' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-16s\033[0m %s\n", $$1, $$2}'

install: build ## Full install (build, start, dependencies, assets, database, demo dataset, worker)
	$(DC) up -d --wait php database
	$(EXEC) composer install --no-interaction
	$(MAKE) assets
	$(MAKE) db.migrate
	$(MAKE) fixtures
	$(MAKE) up

build: ## Build the PHP image
	$(DC) build

up: ## Start the stack with its worker (http://localhost:8000)
	$(DC) up -d --wait php database
	$(DC) up -d worker

down: ## Stop the stack
	$(DC) down

sh: ## Open a shell in the PHP container
	$(DC) exec php bash

logs: ## Follow the stack logs
	$(DC) logs -f

worker.restart: ## Restart the worker (a long-running process does not reload code changes)
	$(DC) restart worker

worker.logs: ## Follow the worker logs
	$(DC) logs -f worker

composer: ## Run composer (e.g. make composer c="require foo/bar")
	$(EXEC) composer $(c)

console: ## Run bin/console (e.g. make console c="debug:router")
	$(EXEC) bin/console $(c)

db.migrate: ## Create the database if needed and run migrations
	$(EXEC) bin/console doctrine:database:create --if-not-exists
	$(EXEC) bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration

db.reset: ## Drop and recreate the database (the worker is stopped meanwhile: it holds a connection)
	$(DC) stop worker
	$(EXEC) bin/console doctrine:database:drop --force --if-exists
	$(MAKE) db.migrate
	$(DC) start worker

fixtures: ## Load the demo dataset (purges the database and the stored files first)
	$(EXEC) bin/console doctrine:fixtures:load --no-interaction --purge-with-truncate --group=demo

fixtures.volume: ## Load the demo dataset plus about 5,000 documents for load testing (purges first)
	$(EXEC) bin/console doctrine:fixtures:load --no-interaction --no-debug --purge-with-truncate --group=demo --group=volume

assets: ## Build the CSS (Tailwind)
	$(EXEC) bin/console tailwind:build

watch: ## Rebuild the CSS on every change
	$(DC) exec php bin/console tailwind:build --watch

test: ## Recreate the test database, validate the schema and run PHPUnit
	@# The non-debug kernel of ErrorPagesTest never checks its cache for freshness: start from a clean one.
	$(EXEC) rm -rf var/cache/test
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
