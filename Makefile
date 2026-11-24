DC   := docker compose
EXEC := $(DC) exec -T -u www-data app
# Behat boots the kernel itself and needs the test environment, not dev.
EXEC_TEST := $(DC) exec -T -u www-data -e APP_ENV=test app

.PHONY: build up down destroy sh logs db test-db phpunit-integration phpunit-functional install backend frontend init ecs phpstan phpunit behat static ci integration

## Environment

.env: .env.dist
	@sed -e "s/^UID=.*/UID=$$(id -u)/" -e "s/^GID=.*/GID=$$(id -g)/" .env.dist > .env
	@echo "Wrote .env for uid $$(id -u), gid $$(id -g)."

build: .env
	$(DC) build

up: .env
	$(DC) up -d

down:
	$(DC) down

destroy:
	$(DC) down -v

sh: up
	$(DC) exec -u www-data app bash

logs:
	$(DC) logs -f app

db:
	$(DC) exec mysql mysql -uroot -pmysql sylius_nps_$${APP_ENV:-dev}

## Setup

install: up
	$(EXEC) composer install --no-interaction

backend:
	$(EXEC) tests/Application/bin/console sylius:install --no-interaction
	$(EXEC) tests/Application/bin/console sylius:fixtures:load default --no-interaction

frontend:
	$(EXEC) bash -c 'cd tests/Application && yarn install'
	$(EXEC) bash -c 'cd tests/Application && yarn build'

init: build install backend frontend

## Quality

ecs:
	$(EXEC) vendor/bin/ecs check

phpstan:
	$(EXEC) vendor/bin/phpstan analyse

phpunit:
	$(EXEC) vendor/bin/phpunit --testsuite=unit

# Integration tests talk to the database, so they need the test schema in place.
test-db:
	$(EXEC_TEST) tests/Application/bin/console doctrine:database:create --if-not-exists --no-interaction
	$(EXEC_TEST) tests/Application/bin/console doctrine:migrations:migrate --no-interaction

phpunit-integration: test-db
	$(EXEC_TEST) vendor/bin/phpunit --testsuite=integration

.PHONY: phpunit-functional
phpunit-functional: test-db ## Run the functional test suite
	$(EXEC_TEST) vendor/bin/phpunit --testsuite=functional

behat:
	$(EXEC_TEST) vendor/bin/behat --colors --strict --no-interaction -vvv -f progress

static: ecs phpstan

ci: init static phpunit phpunit-integration phpunit-functional behat

integration: init phpunit phpunit-integration phpunit-functional behat
