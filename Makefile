# Everything runs in the php container: nothing but Docker and make on the machine.
# The image is built with the machine's user, so that the files it writes belong to them.

.DEFAULT_GOAL := help
.PHONY: help install setup start stop shell db check test test-unit test-functional

export HOST_UID := $(shell id -u)
export HOST_GID := $(shell id -g)
EXEC = docker compose exec -T php
# A terminal gets the questions of make setup; a script or the CI skips them (-T, then -n).
NO_TTY := $(shell test -t 0 || echo 1)
CONSOLE = $(EXEC) php bin/console
PHPUNIT = $(EXEC) vendor/bin/phpunit

help: ## List the commands
	@awk 'BEGIN {FS = ":.*##"} /^[a-zA-Z0-9_-]+:.*##/ {printf "  \033[36m%-16s\033[0m %s\n", $$1, $$2}' $(MAKEFILE_LIST)

install: start ## Build the image, install the dependencies and the database, then choose the models
	$(EXEC) composer install
	$(CONSOLE) importmap:install
	$(MAKE) db
	-$(MAKE) setup

setup: start ## Choose the models (an API key or your own server), save them in .env.local and try them
	docker compose exec $(if $(NO_TTY),-T) php php bin/console app:setup $(if $(NO_TTY),-n) $(SETUP_ARGS)
	$(MAKE) check

start: ## Build the image if needed and start the game (another port: APP_PORT=8050 make start)
	@docker compose up -d --build --wait || { echo; echo "The game could not start. If port $${APP_PORT:-8040} is taken, choose another one: APP_PORT=8050 make install"; exit 1; }

stop: ## Stop the container
	docker compose stop

shell: start ## Open a shell in the container
	docker compose exec php bash

db: start ## Create the development database and run the migrations
	$(CONSOLE) doctrine:migrations:migrate -n

check: start ## Try the model of each role on a tiny task (after changing AI_* in .env.local)
	$(CONSOLE) app:ai:check

test: test-unit test-functional ## Run all the tests

test-unit: start ## Run the unit tests (the pre-commit hook runs these)
	$(PHPUNIT) --testsuite unit

test-functional: start ## Run the functional tests, against a fresh test database
	$(EXEC) rm -f var/data_test.db
	$(CONSOLE) doctrine:migrations:migrate --env=test -n -q
	$(PHPUNIT) --testsuite functional
