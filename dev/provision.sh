#!/usr/bin/env bash
#
# dev/provision.sh -- make a freshly booted dev stack usable.
#
# Idempotent: safe to re-run at any time. Deliberately uses only `occ` and
# WebDAV, never the app's own web UI, so it works even while the app's PHP
# fatals on Nextcloud 34 (which it currently does -- see docs/nc34-audit.html).
#
# Env overrides:
#   SERVICE=app31 BASE_URL=http://localhost:8081 ./dev/provision.sh
#
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."

APP_ID="${APP_ID:-koreader_companion}"
SERVICE="${SERVICE:-app}"
BASE_URL="${BASE_URL:-http://localhost:8080}"
NC_USER="${NC_USER:-admin}"
NC_PASS="${NC_PASS:-admin}"
EBOOKS_FOLDER="${EBOOKS_FOLDER:-eBooks}"
KOREADER_PASSWORD="${KOREADER_PASSWORD:-test123}"

say()  { printf '\033[1;34m==>\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33m!!\033[0m  %s\n' "$*" >&2; }
die()  { printf '\033[1;31mxx\033[0m  %s\n' "$*" >&2; exit 1; }

dc()  { docker compose "$@"; }
occ() { docker compose exec -T -u www-data "$SERVICE" php occ "$@"; }

# ---------------------------------------------------------------- 1. wait
# `compose up --wait` only proves status.php answers; it returns 200 with
# installed:false while the installer is still running. So poll occ instead.
say "waiting for the Nextcloud installer to finish (service: $SERVICE)"
installed=""
for _ in $(seq 1 150); do
  if occ status 2>/dev/null | grep -q 'installed: true'; then installed=yes; break; fi
  sleep 2
done
[ -n "$installed" ] || die "Nextcloud never reported installed:true. Try: docker compose logs $SERVICE"
occ status

# ---------------------------------------------------------------- 2. dev config
say "applying dev-only system config"
# Verbose exception pages with stack traces. Needed: this app fatals on NC 34
# and a blank 500 page is useless.
occ config:system:set debug    --value=true --type=boolean
occ config:system:set loglevel --value=0
# Route the Nextcloud log to the Apache error log => visible in `make logs`.
occ config:system:set log_type --value=errorlog
# The repo carries appinfo/signature.json; a live working tree will never match
# it, and the integrity warning is pure noise in dev.
occ config:system:set integrity.check.disabled --value=true --type=boolean
# The OPDS/KOReader test scripts deliberately send bad credentials and trip the
# throttler. The old reset script hand-deleted oc_bruteforce_attempts rows to
# cope; just turn the throttler off instead.
occ config:system:set auth.bruteforce.protection.enabled --value=false --type=boolean
# Don't litter every account with Nextcloud's sample files.
occ config:system:set skeletondirectory --value=""

# ---------------------------------------------------------------- 3. deps
if [ ! -f vendor/autoload.php ]; then
  warn "vendor/autoload.php missing -- running 'make composer' for you"
  make composer
fi

# ---------------------------------------------------------------- 4. enable app
say "checking the app is visible through the custom_apps path"
dc exec -T "$SERVICE" test -f "/var/www/html/custom_apps/$APP_ID/appinfo/info.xml" \
  || die "bind mount is wrong: /var/www/html/custom_apps/$APP_ID/appinfo/info.xml not found"

say "enabling $APP_ID"
if ! occ app:enable "$APP_ID" 2>&1; then
  warn "app:enable refused -- appinfo/info.xml still declares max-version=31."
  warn "Phase 2 bumps that to min=34 max=35. Forcing for now."
  occ app:enable --force "$APP_ID"
fi

# Expect a benign warning here on PostgreSQL: migration Version0005 issues a
# backtick-quoted DROP TABLE, which is MySQL-only syntax. It is wrapped in
# try/catch, so it logs "Could not drop file tracking table" and continues --
# meaning the table is never actually dropped on Postgres. Fixed in Phase 2.

# ---------------------------------------------------------------- 5. user setup
say "configuring user '$NC_USER'"
occ user:setting "$NC_USER" "$APP_ID" folder      "$EBOOKS_FOLDER"
occ user:setting "$NC_USER" "$APP_ID" auto_rename no

# The KOReader sync password is stored as a bare MD5 hash in oc_preferences
# (see PageController::setKoreaderPassword). Hash it with the container's PHP so
# we need neither md5sum (absent on macOS) nor openssl on the host.
md5=$(dc exec -T "$SERVICE" php -r 'echo md5($argv[1]);' -- "$KOREADER_PASSWORD" | tr -d '\r')
[ ${#md5} -eq 32 ] || die "failed to compute MD5 of the KOReader password (got '$md5')"
occ user:setting "$NC_USER" "$APP_ID" koreader_sync_password "$md5"
say "KOReader sync password set to '$KOREADER_PASSWORD' (md5 $md5)"

# ---------------------------------------------------------------- 6. sample data
SERVICE="$SERVICE" BASE_URL="$BASE_URL" NC_USER="$NC_USER" NC_PASS="$NC_PASS" \
  EBOOKS_FOLDER="$EBOOKS_FOLDER" ./dev/seed.sh

say "provisioning complete"
