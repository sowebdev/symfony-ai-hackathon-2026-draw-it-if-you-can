# Everything runs in the php container: nothing but Docker and make on the machine.
# The image is built with the machine's user, so that the files it writes belong to them.

.DEFAULT_GOAL := help
.PHONY: help install setup start up stop shell db check test test-unit test-functional lint schema cs cs-fix phpstan audit ci

export HOST_UID := $(shell id -u)
export HOST_GID := $(shell id -g)
EXEC = docker compose exec -T php
# A terminal gets the questions of make setup; a script or the CI skips them (-T, then -n).
NO_TTY := $(shell test -t 0 || echo 1)
CONSOLE = $(EXEC) php bin/console
PHPUNIT = $(EXEC) vendor/bin/phpunit

help: ## List the commands
	@awk 'BEGIN {FS = ":.*##"} /^[a-zA-Z0-9_-]+:.*##/ {printf "  \033[36m%-16s\033[0m %s\n", $$1, $$2}' $(MAKEFILE_LIST)

install: ## Build the image, install the dependencies and the database, then choose the models
	@./docker/start.sh
	@echo "Installing the dependencies..."
	@$(EXEC) composer install --no-progress --quiet
	@$(CONSOLE) importmap:install --quiet
	@$(CONSOLE) doctrine:migrations:migrate -n --quiet
	@docker compose exec $(if $(NO_TTY),-T) php php bin/console app:setup $(if $(NO_TTY),-n) $(SETUP_ARGS)
	@echo "Trying the model..."
	@$(CONSOLE) app:ai:check || { echo; echo "The model cannot play yet: fix what is said above, then make check (or make setup)."; }
	@echo
	@echo "Ready: open http://127.0.0.1:$$(cat .app-port)"

setup: up ## Choose the models (an API key or your own server), save them in .env.local and try them
	@docker compose exec $(if $(NO_TTY),-T) php php bin/console app:setup $(if $(NO_TTY),-n) $(SETUP_ARGS)
	@echo "Trying the model..."
	@$(MAKE) --no-print-directory check

start: ## Start the game, on a free port, and tell its address (a port of your own: APP_PORT=8050 make start)
	@./docker/start.sh --announce

up: ## Start the game without a word (the other commands do it first)
	@./docker/start.sh > /dev/null

stop: ## Stop the container
	docker compose stop

shell: up ## Open a shell in the container
	docker compose exec php bash

db: up ## Create the development database and run the migrations
	$(CONSOLE) doctrine:migrations:migrate -n

check: up ## Try the model of each role on a tiny task (after changing AI_* in .env.local)
	@$(CONSOLE) app:ai:check

test: test-unit test-functional ## Run all the tests

test-unit: up ## Run the unit tests (the pre-commit hook runs these)
	$(PHPUNIT) --testsuite unit

test-functional: up ## Run the functional tests, against a fresh test database
	$(EXEC) rm -f var/data_test.db
	$(CONSOLE) doctrine:migrations:migrate --env=test -n -q
	$(PHPUNIT) --testsuite functional

cs: up ## Check the code style, without changing anything
	$(EXEC) vendor/bin/php-cs-fixer fix --dry-run --diff

cs-fix: up ## Fix the code style
	$(EXEC) vendor/bin/php-cs-fixer fix

phpstan: up ## Run the static analysis (warms the dev cache first: PHPStan reads the container from it)
	$(CONSOLE) cache:warmup --env=dev -q
	$(EXEC) vendor/bin/phpstan analyse --no-progress --memory-limit=1G

audit: up ## Check the dependencies for known vulnerabilities
	$(EXEC) composer audit

lint: up ## Lint composer.json, the container, the Twig templates, the YAML and the translations
	$(EXEC) composer validate --strict --no-check-publish
	$(CONSOLE) lint:container
	$(CONSOLE) lint:twig templates
	$(CONSOLE) lint:yaml config --parse-tags
	$(CONSOLE) lint:xliff translations
	$(CONSOLE) lint:translations --locale=en --locale=fr --locale=de --locale=es --locale=it

schema: up ## Check the mapping against a fresh test database, and that the migrations run both ways
	$(EXEC) rm -f var/data_test.db
	$(CONSOLE) doctrine:migrations:migrate --env=test -n -q
	$(CONSOLE) doctrine:schema:validate --env=test
	$(CONSOLE) doctrine:migrations:migrate first --env=test -n -q
	$(CONSOLE) doctrine:migrations:migrate latest --env=test -n -q
	$(CONSOLE) doctrine:schema:validate --env=test
	@echo "The schema matches the mapping and the migrations run both ways."

ci: ## Run everything a pull request must pass: lint, style, static analysis, audit, schema, then the tests
	@$(MAKE) --no-print-directory lint
	@$(MAKE) --no-print-directory cs
	@$(MAKE) --no-print-directory phpstan
	@$(MAKE) --no-print-directory audit
	@$(MAKE) --no-print-directory schema
	@$(MAKE) --no-print-directory test
