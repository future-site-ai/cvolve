# CVolve shortcuts. Run `make` for the list.
DC      = docker compose
DC_DEV  = docker compose -f docker-compose.yml -f docker-compose.dev.yml
CONSOLE = $(DC) exec app php bin/console

.DEFAULT_GOAL := help
.PHONY: help up down dev logs build console migrate seed admin test stan qa templates pack-templates worker local-deps

help: ## Show this help
	@grep -E '^[a-zA-Z_-]+:.*?## ' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-16s\033[0m %s\n", $$1, $$2}'

up: ## Build and start the whole stack (http://localhost:8080)
	$(DC) up -d --build

down: ## Stop the stack
	$(DC) down

dev: ## Start the stack with live code mounted (APP_ENV=dev)
	$(DC_DEV) up -d --build

logs: ## Follow app and worker logs
	$(DC) logs -f app messenger-worker

build: ## Rebuild the application image
	$(DC) build app

console: ## Open a shell in the app container
	$(DC) exec app bash

migrate: ## Run database migrations
	$(CONSOLE) doctrine:migrations:migrate --no-interaction

seed: ## Install presets and create the admin + demo user
	$(CONSOLE) app:seed-demo

admin: ## Create an admin: make admin EMAIL=you@example.com
	$(DC) exec app php bin/console app:create-admin $(EMAIL)

templates: ## (Re)install the bundled preset templates
	$(CONSOLE) app:templates:install

pack-templates: ## Write the presets as .cvolve files into dist/templates
	php bin/console app:templates:install --pack=dist/templates

local-deps: ## Start only PostgreSQL, Gotenberg and Mailpit (for running PHP locally)
	$(DC_DEV) up -d database gotenberg mailer

worker: ## Run a messenger worker locally
	php bin/console messenger:consume async -vv

test: ## Run the PHPUnit suite (SQLite, no services needed)
	php bin/phpunit

stan: ## Static analysis (PHPStan level 5)
	php bin/console cache:warmup --env=dev >/dev/null && vendor/bin/phpstan analyse --memory-limit=1G

qa: test stan ## Tests + static analysis
