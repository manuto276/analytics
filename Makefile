# analytics — developer entry points (plan §13.3).
#
# Everything runs in containers: no PHP, Node or MySQL is needed on the host,
# only Docker with compose v2 and buildx. `make help` lists the targets.
#
# PHP suites and tools run in the test stack (compose.base.yml + compose.test.yml,
# project `analytics-test`); tracker and dashboard commands run in its node
# service. Nothing in the test stack publishes a host port, so it never collides
# with the dev stack or with a database you already run locally.

SHELL := /usr/bin/env bash
.SHELLFLAGS := -eu -o pipefail -c
.DEFAULT_GOAL := help

DOCKER_DIR := deploy/docker
DEV_PROJECT ?= analytics-dev
TEST_PROJECT ?= analytics-test

# Containers write into bind-mounted sources as the calling user.
export DOCKER_UID := $(shell id -u)
export DOCKER_GID := $(shell id -g)

COMPOSE ?= docker compose
COMPOSE_DEV := $(COMPOSE) -p $(DEV_PROJECT) -f $(DOCKER_DIR)/compose.base.yml -f $(DOCKER_DIR)/compose.dev.yml
COMPOSE_TEST := $(COMPOSE) -p $(TEST_PROJECT) -f $(DOCKER_DIR)/compose.base.yml -f $(DOCKER_DIR)/compose.test.yml

# `run --rm` for one-off commands; `--no-deps` where MySQL is not needed.
PHP := $(COMPOSE_TEST) run --rm -T php
PHP_NODEPS := $(COMPOSE_TEST) run --rm -T --no-deps php
NODE := $(COMPOSE_TEST) run --rm -T --no-deps node
PLAYWRIGHT := $(COMPOSE_TEST) run --rm -T playwright

COMMA := ,
PHPUNIT := vendor/bin/phpunit
PNPM := corepack pnpm
DIST ?= dist
PACKAGE = $(shell ls -1t $(DIST)/analytics-*.tar.gz 2>/dev/null | head -n1)

.PHONY: help up down logs sh certs install node-init db-reset seed \
        test test-unit test-integration test-functional test-migrations \
        test-tracker test-tracker-browser test-dashboard test-e2e e2e-setup \
        test-deploy test-smoke test-image test-wordpress coverage mutation perf e2e-snapshots e2e-report \
        stan deptrac cs cs-fix rector lint lint-js lint-infra typecheck openapi-types size package ci \
        images build-images

## ---------------------------------------------------------------- stack ----

help: ## List the available targets
	@grep -hE '^[a-zA-Z0-9_-]+:.*?## ' $(MAKEFILE_LIST) \
	  | awk -F':.*?## ' '{ printf "  \033[36m%-22s\033[0m %s\n", $$1, $$2 }'
	@echo
	@echo "  Dev stack:  https://analytics.test:8443 (add analytics.test to /etc/hosts, run 'make certs')"
	@echo "  Test stack: internal only, project '$(TEST_PROJECT)'"

certs: ## Generate the local CA and the analytics.test / *.site.test certificates
	./$(DOCKER_DIR)/certs/gen-certs.sh

up: certs ## Start the development stack (add PROFILES=redis,mail,node for the optional services)
	$(COMPOSE_DEV) $(if $(PROFILES),$(foreach p,$(subst $(COMMA), ,$(PROFILES)),--profile $(p)),) up -d --build
	@echo "Dashboard/API: https://analytics.test:8443  ·  http://127.0.0.1:8080"

down: ## Stop both stacks (test volumes are removed)
	-$(COMPOSE_DEV) down --remove-orphans
	-$(COMPOSE_TEST) down -v --remove-orphans

logs: ## Follow the development stack logs
	$(COMPOSE_DEV) logs -f --tail=100

sh: ## Shell in the development php container
	$(COMPOSE_DEV) exec php bash

# The JavaScript containers keep their own (Linux) node_modules in named
# volumes, which docker creates root-owned: hand them to the calling user once.
node-init:
	@$(COMPOSE_TEST) run --rm -T --no-deps --user root node sh -lc \
	  'chown $(DOCKER_UID):$(DOCKER_GID) /src/services/tracker/node_modules /src/services/dashboard/node_modules /src/services/e2e/node_modules' >/dev/null

install: node-init ## Install PHP and JavaScript dependencies inside the containers
	$(PHP_NODEPS) composer install
	$(NODE) sh -lc 'cd services/tracker && $(PNPM) install --frozen-lockfile'
	$(NODE) sh -lc 'cd services/dashboard && $(PNPM) install --frozen-lockfile'

db-reset: ## Drop, recreate and migrate the test database
	$(PHP) sh -lc 'php bin/analytics migrations:migrate --no-interaction --allow-no-migration'

seed: ## Seed demo data into the test database (bin/analytics dev:seed)
	$(PHP) sh -lc 'php bin/analytics dev:seed --days=$${DAYS:-30} --events=$${EVENTS:-5000}'

## ----------------------------------------------------------------- tests ----

test: test-unit test-integration test-functional test-migrations test-tracker test-dashboard ## Run the default suites (PHP + tracker + dashboard)

test-unit: ## PHPUnit unit suite
	$(PHP_NODEPS) $(PHPUNIT) --testsuite unit

test-integration: ## PHPUnit integration suite (real MySQL)
	$(PHP) $(PHPUNIT) --testsuite integration

test-functional: ## PHPUnit functional HTTP suite (real MySQL)
	$(PHP) $(PHPUNIT) --testsuite functional

test-migrations: ## PHPUnit migrations suite (fresh migrate, schema diff)
	$(PHP) $(PHPUNIT) --testsuite migrations

test-tracker: node-init ## Tracker unit tests (Vitest)
	$(NODE) sh -lc 'cd services/tracker && $(PNPM) install --frozen-lockfile && $(PNPM) test'

test-tracker-browser: ## Tracker tests in real browsers (Playwright project "tracker")
	$(MAKE) test-e2e E2E_ARGS="--grep @tracker"

test-dashboard: node-init ## Dashboard unit and component tests (Vitest)
	$(NODE) sh -lc 'cd services/dashboard && $(PNPM) install --frozen-lockfile && $(PNPM) test'

e2e-setup: ## Bring the test stack up and create the e2e admin, site and fixture key
	TEST_PROJECT=$(TEST_PROJECT) ./$(DOCKER_DIR)/scripts/e2e-setup.sh

test-e2e: node-init e2e-setup ## End-to-end suite (Playwright in the pinned container)
	$(PLAYWRIGHT) sh -lc 'npm ci --no-audit --no-fund --silent || npm install --no-audit --no-fund --silent; npx playwright test $(E2E_ARGS)'

e2e-snapshots: node-init e2e-setup ## Regenerate the screenshot baselines (after an intended design change)
	$(PLAYWRIGHT) sh -lc 'npm ci --no-audit --no-fund --silent || npm install --no-audit --no-fund --silent; npx playwright test --project=chromium --update-snapshots tests/visual.spec.ts'

e2e-report: ## Open the last Playwright HTML report (serves it on 127.0.0.1:9323)
	$(COMPOSE_TEST) run --rm --service-ports -T playwright npx playwright show-report --host 0.0.0.0

test-wordpress: ## WordPress plugin: unit tests + real WordPress smoke (nightly in CI)
	docker run --rm -v "$(CURDIR):/repo" -w /repo/services/wordpress-plugin/analytics-connector/tests \
	  -u "$(DOCKER_UID):$(DOCKER_GID)" -e COMPOSER_HOME=/tmp/composer -e HOME=/tmp php:8.4-cli \
	  sh -lc 'test -d vendor || (curl -sS https://getcomposer.org/installer | php -- --install-dir=/tmp && php /tmp/composer.phar install --no-interaction); php vendor/bin/phpunit'
	WP_SMOKE_PORT=$${WP_SMOKE_PORT:-8091} ./services/wordpress-plugin/analytics-connector/tests/smoke/smoke.sh

test-deploy: ## Deploy console suite (deploy/manual/tests) in a php:8.4-cli container
	docker run --rm -v "$(CURDIR):/repo" -w /repo/deploy/manual/tests \
	  -u "$(DOCKER_UID):$(DOCKER_GID)" -e COMPOSER_HOME=/tmp/composer -e HOME=/tmp \
	  -e USER="$$(id -un)" php:8.4-cli \
	  sh -lc 'test -d vendor || (curl -sS https://getcomposer.org/installer | php -- --install-dir=/tmp && php /tmp/composer.phar install --no-interaction); php vendor/bin/phpunit'

test-smoke: ## Deploy smoke test (managed-PHP-host container) with the newest package
	@test -n "$(PACKAGE)" || { echo "No package in $(DIST)/ — run 'make package' first" >&2; exit 1; }
	@env_file=$$(mktemp); \
	  { echo "APP_SECRET=$$(openssl rand -base64 32)"; \
	    echo "APP_ENCRYPTION_KEYS=k1:$$(openssl rand -base64 32)"; } > $$env_file; \
	  trap 'rm -f '$$env_file EXIT; \
	  ./deploy/manual/smoke/smoke.sh "$(PACKAGE)" --env-file $$env_file

test-image: ## Build the production images and check compose.prod.yml serves /api/v1/health
	./$(DOCKER_DIR)/scripts/test-image.sh

coverage: ## PHP coverage (pcov) with the plan's gates
	$(PHP) sh -lc 'php -d pcov.enabled=1 $(PHPUNIT) --coverage-text --coverage-clover=coverage/clover.xml'

mutation: ## Infection mutation testing (nightly)
	$(PHP) sh -lc 'test -x vendor/bin/infection || { echo "infection is not installed: composer require --dev infection/infection" >&2; exit 1; }; php -d pcov.enabled=1 vendor/bin/infection --threads=max --min-msi=80'

perf: ## k6 load baseline (nightly, informative)
	@if [ -f services/e2e/perf/collect.js ]; then \
	  docker run --rm -i --network $(TEST_PROJECT)_default -v "$(CURDIR)/services/e2e/perf:/perf" grafana/k6 run /perf/collect.js; \
	else \
	  echo "No k6 scripts yet (services/e2e/perf/collect.js) — skipping."; \
	fi

## ------------------------------------------------------------ static tools ---

stan: ## PHPStan (source and tests)
	$(PHP_NODEPS) vendor/bin/phpstan analyse --no-progress
	$(PHP_NODEPS) vendor/bin/phpstan analyse -c phpstan-tests.neon.dist --no-progress

deptrac: ## Deptrac module boundaries
	$(PHP_NODEPS) vendor/bin/deptrac analyse --no-progress

cs: ## PHP-CS-Fixer (dry run)
	$(PHP_NODEPS) vendor/bin/php-cs-fixer fix --dry-run --diff

cs-fix: ## PHP-CS-Fixer (write)
	$(PHP_NODEPS) vendor/bin/php-cs-fixer fix

rector: ## Rector (dry run)
	$(PHP_NODEPS) vendor/bin/rector process --dry-run

lint: cs lint-js lint-infra ## Lint everything: PHP CS, ESLint, workflows, Dockerfile, shell scripts

lint-js: node-init ## ESLint for the tracker and the dashboard
	$(NODE) sh -lc 'cd services/tracker && $(PNPM) install --frozen-lockfile && $(PNPM) lint'
	$(NODE) sh -lc 'cd services/dashboard && $(PNPM) install --frozen-lockfile && $(PNPM) lint'

lint-infra: ## actionlint, hadolint and shellcheck on the infrastructure files
	docker run --rm -v "$(CURDIR):/repo" -w /repo rhysd/actionlint:latest -color
	docker run --rm -i hadolint/hadolint hadolint --failure-threshold warning - < $(DOCKER_DIR)/Dockerfile
	docker run --rm -v "$(CURDIR):/repo" -w /repo koalaman/shellcheck:stable \
	  deploy/docker/certs/gen-certs.sh deploy/docker/scripts/fpm-healthcheck.sh \
	  deploy/docker/scripts/test-image.sh deploy/docker/scripts/e2e-setup.sh \
	  deploy/manual/build.sh deploy/manual/publish.sh deploy/manual/smoke/smoke.sh deploy/manual/smoke/repack.sh \
	  services/wordpress-plugin/analytics-connector/tests/smoke/smoke.sh

typecheck: node-init ## TypeScript checks (tracker + dashboard)
	$(NODE) sh -lc 'cd services/tracker && $(PNPM) install --frozen-lockfile && $(PNPM) typecheck'
	$(NODE) sh -lc 'cd services/dashboard && $(PNPM) install --frozen-lockfile && $(PNPM) typecheck'

openapi-types: node-init ## Regenerate the dashboard API types from docs/api/openapi.yaml
	$(NODE) sh -lc 'cd services/dashboard && $(PNPM) install --frozen-lockfile && $(PNPM) openapi-types'

size: node-init ## Tracker size budget (size-limit, ≤ 5.0 KB gzip)
	$(NODE) sh -lc 'cd services/tracker && $(PNPM) install --frozen-lockfile && $(PNPM) build && $(PNPM) size'

## --------------------------------------------------------------- packaging ---

package: ## Build dist/analytics-<TS>.tar.gz (+ .sha256)
	./deploy/manual/build.sh $(BUILD_ARGS)

images: build-images ## Alias of build-images
BUILD_ARGS_IMAGE = --build-arg BUILD_TS=$$(date -u +%Y%m%dT%H%M%SZ) \
  --build-arg COMMIT=$$(git rev-parse HEAD) \
  --build-arg COMMIT_DATE=$$(git show -s --format=%cI HEAD) \
  --build-arg COMMIT_TIME=$$(git show -s --format=%ct HEAD)

build-images: ## Build the production images locally
	docker buildx build -f $(DOCKER_DIR)/Dockerfile --target php-runtime $(BUILD_ARGS_IMAGE) -t ghcr.io/manuto276/analytics-php:local --load .
	docker buildx build -f $(DOCKER_DIR)/Dockerfile --target nginx-runtime $(BUILD_ARGS_IMAGE) -t ghcr.io/manuto276/analytics-web:local --load .

ci: ## Everything CI runs, in the same order (see .github/workflows/ci.yml)
	$(MAKE) lint
	$(MAKE) stan
	$(MAKE) deptrac
	$(MAKE) rector
	$(MAKE) test-unit test-integration test-functional test-migrations
	$(MAKE) test-tracker size typecheck
	$(MAKE) test-dashboard
	$(MAKE) test-deploy
	$(MAKE) test-e2e
	$(MAKE) package
	$(MAKE) test-smoke
	$(MAKE) test-image
