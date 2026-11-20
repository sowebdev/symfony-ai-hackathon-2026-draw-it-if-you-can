# Everything runs in the php container: nothing but Docker and make on the machine.
# The image is built with the machine's user, so that the files it writes belong to them.

.DEFAULT_GOAL := help
.PHONY: help install models-dev encryption-key start up stop shell db check test test-unit test-functional lint schema cs cs-fix phpstan audit ci

export HOST_UID := $(shell id -u)
export HOST_GID := $(shell id -g)
EXEC = docker compose exec -T php
CONSOLE = $(EXEC) php bin/console
PHPUNIT = $(EXEC) vendor/bin/phpunit

help: ## List the commands
	@awk 'BEGIN {FS = ":.*##"} /^[a-zA-Z0-9_-]+:.*##/ {printf "  \033[36m%-16s\033[0m %s\n", $$1, $$2}' $(MAKEFILE_LIST)

install: ## Build the image, install the dependencies and the database; the model is chosen on the page
	@./docker/start.sh
	@echo "Installing the dependencies..."
	@$(EXEC) composer install --no-progress --quiet
	@$(CONSOLE) importmap:install --quiet
	@$(MAKE) --no-print-directory encryption-key
	@$(CONSOLE) app:models-dev
	@$(CONSOLE) doctrine:migrations:migrate -n --quiet
	@echo
	@echo "Ready: open http://127.0.0.1:$$(cat .app-port) and choose your model"

# The key that seals the API key kept in the database: made once, in .env.local, never replaced.
models-dev: ## Download the catalog of models.dev again and show the model recommended for each provider
	@$(CONSOLE) app:models-dev

encryption-key:
	@$(CONSOLE) app:encryption-key

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

check: up ## Try the model of each role on a tiny task (the one chosen on the models screen)
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
