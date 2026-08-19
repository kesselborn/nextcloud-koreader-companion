# Makefile for KOReader Companion
#
# Local development (Docker; no host PHP or composer required):
#   make dev                  boot, install deps, provision, seed sample books
#   make up / down / logs / shell / reset
#   make occ ARGS="app:list"
#   make seed-gutenberg       fetch real public-domain EPUBs (Project Gutenberg) and seed them
#                             (make dev does this automatically; GUTENBERG=0 opts out)
#   make test
#
# Release:
#   make release              build and verify an installable tarball
#   make appstore             build the tarball only, without the checks
#   make sign                 sign the working tree using ~/.nextcloud/certificates
#
app_name     = koreader_companion
app_version  = $(shell sed -n 's:.*<version>\(.*\)</version>.*:\1:p' appinfo/info.xml)
build_dir    = $(CURDIR)/build
appstore_dir = $(build_dir)/artifacts/appstore
tarball      = $(appstore_dir)/$(app_name).tar.gz
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
APP_PORT          ?= 8080
APP31_PORT        ?= 8091
APPMYSQL_PORT     ?= 8092
BASE_URL          ?= http://localhost:$(APP_PORT)

.DEFAULT_GOAL := help

.PHONY: help dev up down logs occ cron shell shell-www reset seed provision test \
        gutenberg-fetch seed-gutenberg \
        composer install clean appstore sign release nc31-up nc31-down nc31-provision \
        seed-annotations release-checks release-verify \
        mysql-up mysql-down mysql-provision npm-install frontend watch l10n

help: ## Show this help
	@grep -hE '^[a-zA-Z0-9_-]+:.*?## ' $(MAKEFILE_LIST) \
	  | sort \
	  | awk 'BEGIN{FS=":.*?## "}{printf "  \033[36m%-16s\033[0m %s\n", $$1, $$2}'

# ---------------------------------------------------------------- development

# Real books from Project Gutenberg are part of every boot: fetched before
# provisioning, cached in dev/fixtures/. Opt out with GUTENBERG=0 (offline
# work falls back to the generated fixtures). filter-out rather than $(if):
# GUTENBERG=0 is a non-empty string and would count as "on". CI never runs
# `make dev` -- it calls dev/provision.sh directly -- so gutenberg.org never
# becomes a CI dependency.
GUTENBERG ?= 1
dev: $(if $(filter-out 0,$(GUTENBERG)),gutenberg-fetch) up composer provision ## Boot, install deps, provision and seed (start here)
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
	$(DC) up -d --wait --build

down: ## Stop the stack (keeps volumes)
	$(DC) down

logs: ## Follow the Nextcloud/Apache/PHP log
	$(DC) logs -f $(SERVICE)

occ: ## Run occ in the container, e.g. make occ ARGS="app:list"
	@$(DC) exec -u www-data $(SERVICE) php occ $(ARGS)

cron: ## Drain the background job queue once (metadata extraction, etc.)
	@$(DC) exec -T -u www-data $(SERVICE) php -f cron.php
	@echo "background jobs drained"

shell: ## Root shell in the Nextcloud container
	$(DC) exec -u root $(SERVICE) bash

shell-www: ## www-data shell in the Nextcloud container
	$(DC) exec -u www-data $(SERVICE) bash

provision: ## Configure Nextcloud for dev, enable the app, seed sample books
	./dev/provision.sh

seed: ## Generate sample books and upload them via WebDAV
	./dev/seed.sh

gutenberg-fetch: ## Download public-domain EPUBs from Project Gutenberg into dev/fixtures/
	./dev/fetch-gutenberg.sh

seed-gutenberg: gutenberg-fetch ## Fetch Project Gutenberg EPUBs and upload them
	./dev/seed.sh

# The pointers are generated against the actual EPUB, so they resolve and the
# highlights really draw -- fabricated ones would exercise the badge and the list
# but not the half that can break.
seed-annotations: ## Seed device-shaped highlights for a library EPUB (MATCH=Moby)
	./dev/seed-annotations.sh $(MATCH)
	@$(OCC) koreader:generate-hashes | tail -4

reset: ## Destroy containers AND volumes, and drop generated fixtures
	$(DC) --profile nc31 --profile mysql down -v --remove-orphans
	rm -rf dev/fixtures

test: ## Run the OPDS and KOReader integration test scripts
	./test_scripts/test_opds.sh --base-url $(BASE_URL)
	./test_scripts/test_koreader.sh --base-url $(BASE_URL) --password $(KOREADER_PASSWORD)

# ----------------------------------------------------------- NC 31 comparison

nc31-up: ## Start the extra Nextcloud 31 stack on :$(APP31_PORT)
	$(DC) --profile nc31 up -d --wait

nc31-provision: ## Provision the Nextcloud 31 stack
	SERVICE=app31 BASE_URL=http://localhost:$(APP31_PORT) ./dev/provision.sh

nc31-down: ## Stop the Nextcloud 31 stack
	$(DC) --profile nc31 stop app31 db31

# ------------------------------------------------------- MySQL/MariaDB variant

# Some schema problems only appear on MySQL: InnoDB caps an index key at 3072
# bytes, and backtick quoting is valid there but not on Postgres. Run migrations
# against both before believing them.
mysql-up: ## Start the MariaDB-backed NC 34 stack on :$(APPMYSQL_PORT)
	$(DC) --profile mysql up -d --wait dbmysql appmysql

mysql-provision: ## Provision the MariaDB-backed stack
	SERVICE=appmysql BASE_URL=http://localhost:$(APPMYSQL_PORT) ./dev/provision.sh

mysql-down: ## Stop the MariaDB-backed stack
	$(DC) --profile mysql stop appmysql dbmysql

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

npm-install: ## Install frontend dependencies
	npm install --no-audit --no-fund

frontend: ## Build the Vue bundle into js/ and css/
	npm run build

l10n: ## Refresh l10n/ from the t()/n() calls in the sources
	node dev/l10n-extract.mjs

watch: ## Rebuild the frontend on change
	npm run watch

install: composer npm-install ## Install PHP and frontend dependencies

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

	npm ci --no-audit --no-fund
	npm run build
	# Sourcemaps are ~8 MB and of no use to an installed app.
	rm -f js/*.map css/*.map

	cp -r appinfo   "$(source_dir)/$(app_name)/"
	cp -r css       "$(source_dir)/$(app_name)/"
	cp -r img       "$(source_dir)/$(app_name)/"
	cp -r js        "$(source_dir)/$(app_name)/"
	cp -r l10n      "$(source_dir)/$(app_name)/"
	cp -r lib       "$(source_dir)/$(app_name)/"
	cp -r templates "$(source_dir)/$(app_name)/"
	cp -r vendor    "$(source_dir)/$(app_name)/"
	test -f CHANGELOG.md && cp CHANGELOG.md "$(source_dir)/$(app_name)/" || true
	test -f LICENSE     && cp LICENSE     "$(source_dir)/$(app_name)/" || true

	# macOS stores extended attributes in AppleDouble sidecars, and bsdtar embeds
	# them as ._name entries unless COPYFILE_DISABLE is set. Nextcloud's router
	# reflects over every PHP file it finds, so a stray ._SettingsController.php
	# becomes `Class "OCA\KoreaderCompanion\Controller\._SettingsController" does
	# not exist` and the whole app 500s. Strip any that exist, and stop tar adding
	# more.
	find "$(source_dir)" -name '._*' -delete
	cd "$(source_dir)" && COPYFILE_DISABLE=1 tar -czf "$(tarball)" $(app_name)

	# Fail loudly rather than shipping one: it is invisible until the app is
	# installed, and then it is a 500 with a baffling message.
	@! tar -tzf "$(tarball)" | grep -q '/\._' \
	  || { echo "AppleDouble files leaked into the tarball"; exit 1; }

	rm -rf "$(source_dir)"
	@echo "Tarball created at: $(tarball)"

# This used to invoke a hardcoded /path/to/nextcloud/occ, which never existed.
# Run occ in the dev container instead; the repo is bind-mounted there, so the
# regenerated appinfo/signature.json lands straight in the working tree.
# appinfo/signature.json is deliberately NOT kept in the repo. Nextcloud runs its
# integrity check against whatever signature an app ships, so a stale one -- which
# is what a committed signature becomes on the very next edit -- makes a correct
# install report "Some files have not passed the integrity check". Generate it at
# release time, when the tree is final, and only if publishing to the app store; a
# manual install into custom_apps/ needs no signature at all.
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

# Everything that has to be true before a tarball is worth carrying to a server.
# Each of these has cost real time at least once: an AppleDouble sidecar that
# 500s the router, a committed signature.json that fails the integrity check, a
# stale bundle, an untranslated string, an info.xml the app store rejects.
release: release-checks appstore release-verify ## Build a verified, installable tarball
	@echo
	@echo "  Release $(app_version) ready:"
	@echo "    $(tarball)"
	@echo
	@echo "  Install it on the server (as the web server user):"
	@echo "    tar -xzf $(app_name).tar.gz -C /path/to/nextcloud/custom_apps/"
	@echo "    occ app:enable $(app_name)   # first install only"
	@echo "    occ upgrade                      # after a version bump"
	@echo
	@echo "  Then hard-reload the browser: Nextcloud cache-busts js/ by app version."
	@echo

release-checks:
	@echo "==> Checking the tree"
	@find lib templates -name '*.php' -print0 | xargs -0 -n1 php -l > /dev/null
	@node dev/l10n-extract.mjs --check
	@command -v xmllint > /dev/null \
	  && curl -sSfo /tmp/nc-info.xsd https://apps.nextcloud.com/schema/apps/info.xsd \
	  && xmllint --noout --schema /tmp/nc-info.xsd appinfo/info.xml \
	  || echo "    info.xml schema check skipped (needs xmllint and network)"

# The tarball, not the tree: what ships is what was packed, and every one of
# these has shipped broken before.
release-verify:
	@echo "==> Checking the tarball"
	@test -f "$(tarball)" || { echo "no tarball at $(tarball)"; exit 1; }
	@! tar -tzf "$(tarball)" | grep -q '/\._' \
	  || { echo "    AppleDouble sidecars leaked -- the router will 500"; exit 1; }
	@! tar -tzf "$(tarball)" | grep -q 'signature\.json' \
	  || { echo "    signature.json is in the tarball -- integrity check will fail"; exit 1; }
	@! tar -tzf "$(tarball)" | grep -qE '\.map$$' \
	  || { echo "    sourcemaps leaked"; exit 1; }
	@! tar -tzf "$(tarball)" | grep -qiE 'vendor/(phpunit|vimeo|psalm)' \
	  || { echo "    dev dependencies leaked"; exit 1; }
	@tar -xzOf "$(tarball)" $(app_name)/appinfo/info.xml | grep -q '<version>$(app_version)</version>' \
	  || { echo "    version in the tarball does not match appinfo/info.xml"; exit 1; }
	@echo "    ok: version $(app_version), no sidecars, no signature, no maps, no dev deps"
	@# A release build rewrites js/ and css/ with fresh content hashes, so the
	@# working copy can end up ahead of the last commit. CI compares the two.
	@git diff --quiet -- js css \
	  || echo "    NOTE: js/ and css/ changed -- commit them or CI will flag a stale bundle"
