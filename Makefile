.DEFAULT_GOAL := help

# A harness lane writes HARNESS_PORT into .lane.env; without it the app serves on 8000.
-include .lane.env
HARNESS_PORT ?= 8000

CONSOLE := bin/console

.PHONY: help vendor init serve serve-stop db-migrate db-fresh seed test cs-fix cs-check phpstan lint lint-twig lint-yaml lint-container

help: ## List the targets
	@awk 'BEGIN {FS = ":.*## "} /^[a-zA-Z_-]+:.*## / {printf "  \033[36m%-16s\033[0m %s\n", $$1, $$2}' $(MAKEFILE_LIST)

vendor: vendor/autoload.php ## Install Composer dependencies when vendor/ is missing or older than composer.lock

vendor/autoload.php: composer.json composer.lock
	composer install --no-interaction
	@touch $@

init: vendor db-migrate ## Install dependencies and create the dev database

serve: vendor ## Start the local server on HARNESS_PORT (default 8000), in the background
	@if lsof -nP -iTCP:$(HARNESS_PORT) -sTCP:LISTEN >/dev/null 2>&1; then echo "port $(HARNESS_PORT) in use - run make serve-stop"; exit 1; fi
	symfony server:start --port=$(HARNESS_PORT) --no-tls -d

serve-stop: ## Stop the local server of this checkout
	symfony server:stop

db-migrate: vendor ## Run the Doctrine migrations
	$(CONSOLE) doctrine:migrations:migrate --no-interaction --allow-no-migration

db-fresh: vendor ## Delete the dev database and migrate from scratch
	rm -f var/data_dev.db var/data_dev.db-wal var/data_dev.db-shm
	$(MAKE) db-migrate

seed: vendor ## Load the demo data (Foundry story "main") into the dev database
	$(CONSOLE) foundry:load-fixtures main --append --no-interaction

test: vendor ## Run every test suite
	vendor/bin/phpunit

cs-fix: vendor ## Fix the code style
	vendor/bin/php-cs-fixer fix

cs-check: vendor ## Check the code style
	vendor/bin/php-cs-fixer check --diff

phpstan: vendor ## Static analysis (level 8, architecture rules)
	vendor/bin/phpstan analyse --memory-limit=1G --no-progress

lint-twig: vendor ## Lint the templates
	$(CONSOLE) lint:twig templates

lint-yaml: vendor ## Lint the YAML config and translations
	$(CONSOLE) lint:yaml config translations --parse-tags

lint-container: vendor ## Lint the service container
	$(CONSOLE) lint:container

lint: vendor cs-check phpstan lint-twig lint-yaml lint-container ## Every static check (the landing gate)
