# Makefile for KOReader Companion
#
# Local development (Docker; no host PHP or composer required):
#   make dev                  boot, install deps, provision, seed sample books
#   make up / down / logs / shell / reset
#   make occ ARGS="app:list"
#   make test
#
# Release:
#   make appstore             build the app store tarball
#   make sign                 sign the working tree using ~/.nextcloud/certificates
#
app_name     = koreader_companion
build_dir    = $(CURDIR)/build
appstore_dir = $(build_dir)/artifacts/appstore
source_dir   = $(build_dir)/artifacts/source
cert_dir     = $(HOME)/.nextcloud/certificates

DC             = docker compose
SERVICE        = app
APP_PATH       = /var/www/html/custom_apps/$(app_name)
OCC            = $(DC) exec -T -u www-data $(SERVICE) php occ
COMPOSER_IMAGE = composer:2
UID_GID        = $(shell id -u):$(shell id -g)

# Overridable: make test KOREADER_PASSWORD=hunter2
KOREADER_PASSWORD ?= test123
BASE_URL          ?= http://localhost:8080

.DEFAULT_GOAL := help

.PHONY: help dev up down logs occ shell shell-www reset seed provision test \
        composer install clean appstore sign release nc31-up nc31-down nc31-provision

help: ## Show this help
	@grep -hE '^[a-zA-Z0-9_-]+:.*?## ' $(MAKEFILE_LIST) \
	  | sort \
	  | awk 'BEGIN{FS=":.*?## "}{printf "  \033[36m%-16s\033[0m %s\n", $$1, $$2}'

# ---------------------------------------------------------------- development

dev: up composer provision ## Boot, install deps, provision and seed (start here)
	@echo
	@echo "  Nextcloud 34    $(BASE_URL)   (admin / admin)"
	@echo "  App             $(BASE_URL)/apps/$(app_name)/"
	@echo "  OPDS feed       $(BASE_URL)/apps/$(app_name)/opds"
	@echo "  KOReader sync   $(BASE_URL)/apps/$(app_name)/sync   (password: $(KOREADER_PASSWORD))"
	@echo
	@echo "  Edits to lib/, templates/, js/ and css/ are live -- no restart needed."
	@echo "  For js/css keep DevTools 'Disable cache' on: Nextcloud cache-busts by app version."
	@echo

up: ## Start the stack and wait until it is healthy
	$(DC) up -d --wait

down: ## Stop the stack (keeps volumes)
	$(DC) down

logs: ## Follow the Nextcloud/Apache/PHP log
	$(DC) logs -f $(SERVICE)

occ: ## Run occ in the container, e.g. make occ ARGS="app:list"
	@$(DC) exec -u www-data $(SERVICE) php occ $(ARGS)

shell: ## Root shell in the Nextcloud container
	$(DC) exec -u root $(SERVICE) bash

shell-www: ## www-data shell in the Nextcloud container
	$(DC) exec -u www-data $(SERVICE) bash

provision: ## Configure Nextcloud for dev, enable the app, seed sample books
	./dev/provision.sh

seed: ## Generate sample books and upload them via WebDAV
	./dev/seed.sh

reset: ## Destroy containers AND volumes, and drop generated fixtures
	$(DC) --profile nc31 down -v --remove-orphans
	rm -rf dev/fixtures

test: ## Run the OPDS and KOReader integration test scripts
	./test_scripts/test_opds.sh --base-url $(BASE_URL)
	./test_scripts/test_koreader.sh --base-url $(BASE_URL) --password $(KOREADER_PASSWORD)

# ----------------------------------------------------------- NC 31 comparison

nc31-up: ## Start the extra Nextcloud 31 stack on :8081
	$(DC) --profile nc31 up -d --wait

nc31-provision: ## Provision the Nextcloud 31 stack
	SERVICE=app31 BASE_URL=http://localhost:8081 ./dev/provision.sh

nc31-down: ## Stop the Nextcloud 31 stack
	$(DC) --profile nc31 stop app31 db31

# -------------------------------------------------------------- dependencies

# Containerised so no host PHP/composer is needed. COMPOSER_HOME is redirected
# because the mapped-in uid has no writable home in that image.
composer: ## Install PHP dependencies into ./vendor
	docker run --rm \
	  -u $(UID_GID) \
	  -v "$(CURDIR)":/app -w /app \
	  -e COMPOSER_HOME=/tmp/composer \
	  -e COMPOSER_CACHE_DIR=/tmp/composer/cache \
	  $(COMPOSER_IMAGE) install --no-interaction --no-progress

install: composer ## Alias for `composer`

# --------------------------------------------------------------------- build

clean: ## Remove build artifacts
	rm -rf $(build_dir)

appstore: clean ## Build the app store tarball
	mkdir -p "$(appstore_dir)"
	mkdir -p "$(source_dir)/$(app_name)"

	docker run --rm \
	  -u $(UID_GID) \
	  -v "$(CURDIR)":/app -w /app \
	  -e COMPOSER_HOME=/tmp/composer \
	  -e COMPOSER_CACHE_DIR=/tmp/composer/cache \
	  $(COMPOSER_IMAGE) install --no-dev --no-interaction --no-progress --optimize-autoloader

	cp -r appinfo   "$(source_dir)/$(app_name)/"
	cp -r css       "$(source_dir)/$(app_name)/"
	cp -r img       "$(source_dir)/$(app_name)/"
	cp -r js        "$(source_dir)/$(app_name)/"
	cp -r lib       "$(source_dir)/$(app_name)/"
	cp -r templates "$(source_dir)/$(app_name)/"
	cp -r vendor    "$(source_dir)/$(app_name)/"
	test -f CHANGELOG.md && cp CHANGELOG.md "$(source_dir)/$(app_name)/" || true
	test -f LICENSE     && cp LICENSE     "$(source_dir)/$(app_name)/" || true

	cd "$(source_dir)" && tar -czf "$(appstore_dir)/$(app_name).tar.gz" $(app_name)
	rm -rf "$(source_dir)"
	@echo "Tarball created at: $(appstore_dir)/$(app_name).tar.gz"

# This used to invoke a hardcoded /path/to/nextcloud/occ, which never existed.
# Run occ in the dev container instead; the repo is bind-mounted there, so the
# regenerated appinfo/signature.json lands straight in the working tree.
sign: ## Sign the working tree (writes appinfo/signature.json); needs `make up`
	@test -f "$(cert_dir)/$(app_name).key" || { echo "missing $(cert_dir)/$(app_name).key"; exit 1; }
	@test -f "$(cert_dir)/$(app_name).crt" || { echo "missing $(cert_dir)/$(app_name).crt"; exit 1; }
	@$(DC) ps --status running --services | grep -qx $(SERVICE) || { echo "stack not running: make up"; exit 1; }
	$(DC) cp "$(cert_dir)/$(app_name).key" $(SERVICE):/tmp/$(app_name).key
	$(DC) cp "$(cert_dir)/$(app_name).crt" $(SERVICE):/tmp/$(app_name).crt
	$(OCC) integrity:sign-app \
	    --privateKey=/tmp/$(app_name).key \
	    --certificate=/tmp/$(app_name).crt \
	    --path=$(APP_PATH)
	$(DC) exec -T -u root $(SERVICE) rm -f /tmp/$(app_name).key /tmp/$(app_name).crt
	@echo "Signed. appinfo/signature.json updated in the working tree."

release: appstore ## Build for release
